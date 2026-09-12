<?php

function gojs_ini_try_set($key, $value) {
    $before = ini_get($key);
    $ok = @ini_set($key, (string)$value);
    $after = ini_get($key);
    return array(
        'directive' => $key,
        'target' => (string)$value,
        'before' => ($before === false || $before === null) ? null : (string)$before,
        'after' => ($after === false || $after === null) ? null : (string)$after,
        'applied' => $ok !== false && $after !== false && ((string)$after === (string)$value),
    );
}

function gojs_user_ini_path() {
    if (array_key_exists('gojs_user_ini_override', $GLOBALS)) {
        return $GLOBALS['gojs_user_ini_override'];
    }
    if (!defined('PANEL_ROOT')) {
        return null;
    }
    return rtrim(PANEL_ROOT, '/\\') . '/.user.ini';
}

function gojs_user_ini_read() {
    $path = gojs_user_ini_path();
    if ($path === null || !file_exists($path)) {
        return array();
    }
    $parsed = @parse_ini_file($path, false, INI_SCANNER_RAW);
    return is_array($parsed) ? $parsed : array();
}

function gojs_user_ini_write($map) {
    $path = gojs_user_ini_path();
    if ($path === null) {
        return false;
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $lines = array('; managed by Go.js-Lite', '');
    foreach ($map as $k => $v) {
        $lines[] = $k . '=' . $v;
    }
    return @file_put_contents($path, implode("\n", $lines) . "\n", LOCK_EX) !== false;
}

function gojs_user_ini_merge($patch) {
    $map = gojs_user_ini_read();
    foreach ($patch as $k => $v) {
        if ($v === null) {
            unset($map[$k]);
            continue;
        }
        $map[$k] = (string)$v;
    }
    return gojs_user_ini_write($map);
}

function gojs_user_ini_writable() {
    $path = gojs_user_ini_path();
    if ($path === null) {
        return false;
    }
    if (file_exists($path)) {
        return is_writable($path);
    }
    $dir = dirname($path);
    return is_dir($dir) && is_writable($dir);
}

function gojs_php_ini_baseline_path() {
    if (!defined('PANEL_ROOT')) {
        return null;
    }
    return rtrim(PANEL_ROOT, '/\\') . '/backend/data/php_ini_baseline.ini';
}

function gojs_php_ini_baseline() {
    $path = gojs_php_ini_baseline_path();
    if ($path === null || !file_exists($path)) {
        return array();
    }
    $parsed = @parse_ini_file($path, false, INI_SCANNER_RAW);
    return is_array($parsed) ? $parsed : array();
}

function gojs_php_ini_meta() {
    return array(
        'opcache.enable' => array('severity' => 'danger', 'note' => 'OPcache greatly reduces script compilation cost; enable it in production'),
        'opcache.memory_consumption' => array('severity' => 'warning', 'note' => '256M is usually enough for a medium application; a smaller value evicts entries too often'),
        'opcache.interned_strings_buffer' => array('severity' => 'info', 'note' => 'Interned strings buffer; 16M suits most projects'),
        'opcache.max_accelerated_files' => array('severity' => 'warning', 'note' => 'Must exceed the total number of project files, otherwise the hit rate drops'),
        'opcache.validate_timestamps' => array('severity' => 'warning', 'note' => 'Disable it in production to avoid a stat call per request; reload after each deploy'),
        'opcache.save_comments' => array('severity' => 'info', 'note' => 'Keep comments so annotation and documentation tooling can use them'),
        'opcache.jit' => array('severity' => 'info', 'note' => 'The tracing mode gives the best results on most workloads'),
        'opcache.jit_buffer_size' => array('severity' => 'info', 'note' => 'JIT buffer size; 128M is a common starting point'),
        'session.use_strict_mode' => array('severity' => 'danger', 'note' => 'Strict mode prevents session fixation attacks'),
        'session.cookie_httponly' => array('severity' => 'danger', 'note' => 'Stop JavaScript from reading the session cookie, mitigating XSS theft'),
        'session.cookie_samesite' => array('severity' => 'warning', 'note' => 'Lax mitigates CSRF'),
        'session.gc_maxlifetime' => array('severity' => 'info', 'note' => 'Session lifetime; keep it aligned with your security policy'),
        'expose_php' => array('severity' => 'warning', 'note' => 'Disable it to avoid leaking the PHP version in response headers'),
        'display_errors' => array('severity' => 'danger', 'note' => 'Must be off in production to avoid leaking paths and code'),
        'log_errors' => array('severity' => 'warning', 'note' => 'Enable it so runtime errors are logged'),
        'max_execution_time' => array('severity' => 'info', 'note' => 'Too low aborts long tasks; too high lets slow requests exhaust the workers'),
        'memory_limit' => array('severity' => 'info', 'note' => 'Match it to the size of your data'),
        'upload_max_filesize' => array('severity' => 'info', 'note' => 'Upload limit; must exceed the largest file you handle'),
        'post_max_size' => array('severity' => 'info', 'note' => 'Must be at least upload_max_filesize'),
        'date.timezone' => array('severity' => 'info', 'note' => 'Set the timezone explicitly to avoid warnings and clock drift'),
    );
}

function gojs_php_ini_values_match($current, $recommended) {
    if ($current === false || $current === null) {
        return false;
    }
    $c = strtolower(trim((string)$current));
    $r = strtolower(trim((string)$recommended));
    if ($c === $r) {
        return true;
    }
    $truthy = array('1', 'on', 'yes', 'true');
    $falsy = array('0', 'off', 'no', 'false', '');
    if (in_array($r, $truthy, true) && in_array($c, $truthy, true)) {
        return true;
    }
    if (in_array($r, $falsy, true) && in_array($c, $falsy, true)) {
        return true;
    }
    return false;
}

function gojs_php_ini_diff() {
    $baseline = gojs_php_ini_baseline();
    $meta = gojs_php_ini_meta();
    $rows = array();
    foreach ($baseline as $directive => $recommended) {
        $current = ini_get($directive);
        $m = isset($meta[$directive]) ? $meta[$directive] : array('severity' => 'info', 'note' => '');
        $rows[] = array(
            'directive' => (string)$directive,
            'current' => ($current === false || $current === null) ? null : (string)$current,
            'recommended' => (string)$recommended,
            'severity' => $m['severity'],
            'note' => $m['note'],
            'match' => gojs_php_ini_values_match($current, $recommended),
        );
    }
    return $rows;
}

function gojs_php_size_to_mb($val) {
    if ($val === false || $val === null || $val === '') {
        return null;
    }
    $v = trim((string)$val);
    if ($v === '-1') {
        return null;
    }
    if (!preg_match('/^(-?\d+)\s*([kmg])?b?$/i', $v, $m)) {
        return null;
    }
    $n = (int)$m[1];
    $unit = isset($m[2]) ? strtolower($m[2]) : '';
    if ($unit === 'k') {
        return (float)round($n / 1024, 4);
    }
    if ($unit === 'm') {
        return (float)$n;
    }
    if ($unit === 'g') {
        return (float)($n * 1024);
    }
    return (float)round($n / 1048576, 4);
}

function gojs_php_jit_mode_normalize($raw) {
    $v = is_string($raw) ? strtolower(trim($raw)) : '';
    if ($v === '' || $v === '0' || $v === 'disable' || $v === 'off' || $v === 'none') {
        return 'none';
    }
    if (strpos($v, 'tracing') !== false) {
        return 'tracing';
    }
    if (strpos($v, 'function') !== false) {
        return 'function';
    }
    return 'custom';
}

function gojs_php_jit_get() {
    $mode = ini_get('opcache.jit');
    $buffer = ini_get('opcache.jit_buffer_size');
    return array(
        'raw_mode' => ($mode === false || $mode === null) ? null : (string)$mode,
        'mode' => gojs_php_jit_mode_normalize($mode),
        'buffer_size' => ($buffer === false || $buffer === null) ? null : (string)$buffer,
        'buffer_size_mb' => gojs_php_size_to_mb($buffer),
        'user_ini_path' => gojs_user_ini_path(),
    );
}

function gojs_api_php_ini_diff() {
    $rows = gojs_php_ini_diff();
    $mismatch = 0;
    foreach ($rows as $r) {
        if (!$r['match']) {
            $mismatch++;
        }
    }
    gojs_json_response(array(
        'loaded_file' => php_ini_loaded_file(),
        'scanned_file' => php_ini_scanned_files(),
        'baseline_file' => gojs_php_ini_baseline_path(),
        'total' => count($rows),
        'mismatch' => $mismatch,
        'rows' => $rows,
    ));
}

function gojs_api_php_jit_get() {
    gojs_json_response(gojs_php_jit_get());
}

function gojs_api_php_jit_set() {
    $body = gojs_get_body();
    $mode = isset($body['mode']) ? (string)$body['mode'] : '';
    if (!in_array($mode, array('tracing', 'function', 'none'), true)) {
        gojs_json_response(null, array(
            'code' => 'invalid_mode',
            'message' => 'mode must be tracing, function or none',
        ), 400);
    }
    $bufferMb = isset($body['buffer_size_mb']) ? (int)$body['buffer_size_mb'] : 0;
    if ($bufferMb < 0 || $bufferMb > 4096) {
        gojs_json_response(null, array(
            'code' => 'invalid_buffer',
            'message' => 'buffer_size_mb must be between 0 and 4096',
        ), 400);
    }
    $patch = array(
        'opcache.jit' => ($mode === 'none' ? 'disable' : $mode),
        'opcache.jit_buffer_size' => ($bufferMb > 0 ? ($bufferMb . 'M') : '0'),
    );
    $written = gojs_user_ini_merge($patch);
    $runtime = array();
    $anyApplied = false;
    foreach ($patch as $k => $v) {
        $one = gojs_ini_try_set($k, $v);
        $runtime[] = $one;
        if ($one['applied']) {
            $anyApplied = true;
        }
    }
    gojs_log_operation('php.jit', 'php/jit', true, 'mode=' . $mode . ' buffer=' . $bufferMb . 'M');
    $payload = array(
        'saved_to_ini' => $written,
        'user_ini_path' => gojs_user_ini_path(),
        'runtime' => $runtime,
        'effective' => $anyApplied,
        'current' => gojs_php_jit_get(),
        'reload_required' => !$anyApplied,
    );
    if (!$anyApplied) {
        gojs_json_response($payload, array(
            'code' => 'ini_readonly',
            'message' => 'JIT cannot be changed at runtime in this mode; it was written to .user.ini and takes effect after a PHP-FPM restart',
        ), 501);
    }
    gojs_json_response($payload);
}
