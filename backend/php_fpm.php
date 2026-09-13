<?php

function gojs_tail_lines($path, $limit = 100) {
    if (!is_string($path) || $path === '' || !file_exists($path) || !is_readable($path)) {
        return array();
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return array();
    }
    $lines = preg_split('/\r?\n/', $raw);
    if (end($lines) === '') {
        array_pop($lines);
    }
    return array_slice($lines, -max(1, (int)$limit));
}

function gojs_fpm_sapi_applicable() {
    $sapi = function_exists('php_sapi_name') ? php_sapi_name() : '';
    return in_array($sapi, array('fpm-fcgi', 'cgi-fcgi'), true);
}

function gojs_fpm_config_port() {
    if (isset($GLOBALS['config']['fpm_status_port'])) {
        return (int)$GLOBALS['config']['fpm_status_port'];
    }
    return 9000;
}

function gojs_fpm_status_url($port = null) {
    $port = ($port !== null) ? (int)$port : gojs_fpm_config_port();
    return 'http://127.0.0.1:' . $port . '/fpm-status?json';
}

function gojs_fpm_http_get($url, $timeout = 3) {
    if (!is_string($url) || $url === '') {
        return null;
    }
    if (function_exists('curl_init')) {
        $ch = @curl_init($url);
        if ($ch === false) {
            return null;
        }
        @curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int)$timeout,
            CURLOPT_CONNECTTIMEOUT => (int)$timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));
        $raw = @curl_exec($ch);
        $code = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);
        if ($raw === false || $code >= 400) {
            return null;
        }
        return (string)$raw;
    }
    $ctx = @stream_context_create(array('http' => array('timeout' => (int)$timeout)));
    $raw = @file_get_contents($url, false, $ctx);
    return is_string($raw) ? $raw : null;
}

function gojs_fpm_parse_status($payload) {
    $json = json_decode((string)$payload, true);
    if (is_array($json)) {
        return array(
            'pool' => isset($json['pool']) ? $json['pool'] : null,
            'active' => isset($json['active processes']) ? (int)$json['active processes'] : null,
            'idle' => isset($json['idle processes']) ? (int)$json['idle processes'] : null,
            'total' => isset($json['total processes']) ? (int)$json['total processes'] : null,
            'max_active' => isset($json['max active processes']) ? (int)$json['max active processes'] : null,
            'max_children_reached' => isset($json['max children reached']) ? (int)$json['max children reached'] : null,
            'accepted_conn' => isset($json['accepted conn']) ? (int)$json['accepted conn'] : null,
            'listen_queue' => isset($json['listen queue']) ? (int)$json['listen queue'] : null,
            'requests_per_sec' => null,
            'format' => 'json',
            'raw' => $json,
        );
    }
    $map = array();
    foreach (preg_split('/\r?\n/', (string)$payload) as $line) {
        if (strpos($line, ':') === false) {
            continue;
        }
        $parts = explode(':', $line, 2);
        $map[strtolower(trim($parts[0]))] = trim($parts[1]);
    }
    if (empty($map)) {
        return null;
    }
    $pick = function ($key) use ($map) {
        return isset($map[$key]) ? (int)$map[$key] : null;
    };
    return array(
        'pool' => isset($map['pool']) ? $map['pool'] : null,
        'active' => $pick('active processes'),
        'idle' => $pick('idle processes'),
        'total' => $pick('total processes'),
        'max_active' => $pick('max active processes'),
        'max_children_reached' => $pick('max children reached'),
        'accepted_conn' => $pick('accepted conn'),
        'listen_queue' => $pick('listen queue'),
        'requests_per_sec' => null,
        'format' => 'text',
        'raw' => null,
    );
}

function gojs_fpm_conf_candidates() {
    $out = array();
    if (defined('PANEL_ROOT')) {
        $out[] = rtrim(PANEL_ROOT, '/\\') . '/php-fpm.conf';
    }
    $out[] = '/etc/php-fpm.conf';
    $out[] = '/usr/local/etc/php-fpm.conf';
    foreach (array('/etc/php', '/usr/local/etc/php') as $base) {
        if (!is_dir($base)) {
            continue;
        }
        $globs = @glob($base . '/*/fpm/php-fpm.conf');
        if (is_array($globs)) {
            foreach ($globs as $g) {
                $out[] = $g;
            }
        }
    }
    return array_values(array_unique($out));
}

function gojs_fpm_extract_directive($text, $name) {
    if (!is_string($text) || $text === '') {
        return null;
    }
    if (!preg_match('/^\s*' . preg_quote($name, '/') . '\s*=\s*(\S.*)$/mi', $text, $m)) {
        return null;
    }
    return trim($m[1]);
}

function gojs_fpm_slowlog_path() {
    if (isset($GLOBALS['config']['fpm_slowlog'])) {
        return (string)$GLOBALS['config']['fpm_slowlog'];
    }
    foreach (gojs_fpm_conf_candidates() as $conf) {
        if (!file_exists($conf) || !is_readable($conf)) {
            continue;
        }
        $text = @file_get_contents($conf);
        $found = gojs_fpm_extract_directive($text, 'slowlog');
        if ($found !== null && $found !== '') {
            return $found;
        }
    }
    return null;
}

function gojs_fpm_not_applicable_response() {
    gojs_json_response(null, array(
        'code' => 'fpm_not_applicable',
        'message' => 'FPM monitoring is not available in this runtime mode (SAPI: ' . php_sapi_name() . ')',
        'sapi' => php_sapi_name(),
    ), 501);
}

function gojs_api_php_fpm_status() {
    if (!gojs_fpm_sapi_applicable()) {
        gojs_fpm_not_applicable_response();
    }
    $port = isset($_GET['port']) ? (int)$_GET['port'] : null;
    $url = gojs_fpm_status_url($port);
    $raw = gojs_fpm_http_get($url);
    if ($raw === null) {
        gojs_json_response(null, array(
            'code' => 'fpm_status_unreachable',
            'message' => 'Cannot reach the FPM status endpoint; make sure pm.status_path is enabled',
            'url' => $url,
        ), 502);
    }
    $parsed = gojs_fpm_parse_status($raw);
    if ($parsed === null) {
        gojs_json_response(null, array(
            'code' => 'fpm_status_unparsable',
            'message' => 'Unable to parse the FPM status output',
            'url' => $url,
        ), 502);
    }
    gojs_log_operation('php.fpm_status', 'php/fpm', true, 'active=' . var_export($parsed['active'], true));
    gojs_json_response(array('url' => $url, 'status' => $parsed));
}

function gojs_api_php_fpm_slowlog() {
    if (!gojs_fpm_sapi_applicable()) {
        gojs_fpm_not_applicable_response();
    }
    $path = gojs_fpm_slowlog_path();
    if ($path === null) {
        $path = isset($_GET['path']) ? (string)$_GET['path'] : null;
    }
    if ($path === null || $path === '') {
        gojs_json_response(null, array(
            'code' => 'slowlog_not_configured',
            'message' => 'No slowlog setting found in php-fpm.conf; pass ?path= to specify one',
            'candidates' => gojs_fpm_conf_candidates(),
        ), 404);
    }
    if (!file_exists($path) || !is_readable($path)) {
        gojs_json_response(null, array(
            'code' => 'slowlog_unreadable',
            'message' => 'The slowlog file does not exist or is not readable',
            'path' => $path,
        ), 404);
    }
    $lines = gojs_tail_lines($path, 100);
    gojs_json_response(array(
        'path' => $path,
        'count' => count($lines),
        'lines' => $lines,
    ));
}
