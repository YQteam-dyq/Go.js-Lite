<?php

function gojs_php_errors_candidates() {
    $paths = array();
    $configured = ini_get('error_log');
    if (is_string($configured) && $configured !== '' && strtolower($configured) !== 'syslog') {
        $paths[] = $configured;
    }
    $paths[] = CONFIG_DIR . '/php_error.log';
    if (defined('PANEL_ROOT')) {
        $root = rtrim(PANEL_ROOT, '/\\');
        $paths[] = $root . '/php_error.log';
        $paths[] = $root . '/error_log';
        $paths[] = dirname($root) . '/error_log';
    }
    $out = array();
    foreach ($paths as $p) {
        if (!is_string($p) || $p === '' || !file_exists($p) || !is_readable($p)) {
            continue;
        }
        $real = realpath($p);
        $out[] = $real !== false ? $real : $p;
    }
    return array_values(array_unique($out));
}

function gojs_php_error_classify($line) {
    $map = array(
        'Recoverable fatal error' => 'fatal',
        'Fatal error' => 'fatal',
        'Parse error' => 'fatal',
        'Maximum execution time' => 'fatal',
        'Out of memory' => 'fatal',
        'Allowed memory size' => 'fatal',
        'Warning' => 'warning',
        'Deprecated' => 'deprecated',
        'Notice' => 'notice',
    );
    foreach ($map as $needle => $severity) {
        if (stripos((string)$line, $needle) !== false) {
            return $severity;
        }
    }
    return null;
}

function gojs_php_error_code($severity) {
    $codes = array(
        'fatal' => 'E_ERROR',
        'warning' => 'E_WARNING',
        'notice' => 'E_NOTICE',
        'deprecated' => 'E_DEPRECATED',
    );
    return isset($codes[$severity]) ? $codes[$severity] : 'E_UNKNOWN';
}

function gojs_php_error_timestamp($line) {
    if (!preg_match('/^\s*\[([^\]]+)\]/', (string)$line, $m)) {
        return null;
    }
    $ts = @strtotime($m[1]);
    return ($ts === false) ? null : $ts;
}

function gojs_php_errors_parse($raw) {
    $entries = array();
    $lines = preg_split('/\r?\n/', (string)$raw);
    foreach ($lines as $line) {
        $severity = gojs_php_error_classify($line);
        if ($severity === null) {
            continue;
        }
        $entries[] = array(
            'severity' => $severity,
            'code' => gojs_php_error_code($severity),
            'ts' => gojs_php_error_timestamp($line),
            'text' => trim($line),
        );
    }
    return $entries;
}

function gojs_php_errors_since_ts($since) {
    if (!is_string($since) || trim($since) === '') {
        return null;
    }
    $since = trim($since);
    if (preg_match('/^(\d+)([smhd])$/', $since, $m)) {
        $n = (int)$m[1];
        $mult = array('s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400);
        return time() - $n * $mult[$m[2]];
    }
    $ts = @strtotime($since);
    return ($ts === false) ? null : $ts;
}

function gojs_php_errors_aggregate($entries, $sinceTs = null, $severities = null) {
    $bySeverity = array('fatal' => 0, 'warning' => 0, 'notice' => 0, 'deprecated' => 0);
    $byCode = array();
    $buckets = array();
    $total = 0;
    $filter = null;
    if (is_array($severities) && !empty($severities)) {
        $filter = array();
        foreach ($severities as $s) {
            $filter[strtolower(trim((string)$s))] = true;
        }
    }
    foreach ($entries as $e) {
        if ($sinceTs !== null && isset($e['ts']) && $e['ts'] !== null && $e['ts'] < $sinceTs) {
            continue;
        }
        if ($filter !== null && !isset($filter[$e['severity']])) {
            continue;
        }
        $total++;
        $bySeverity[$e['severity']]++;
        $code = isset($e['code']) ? $e['code'] : 'E_UNKNOWN';
        $byCode[$code] = isset($byCode[$code]) ? $byCode[$code] + 1 : 1;
        if (isset($e['ts']) && $e['ts'] !== null) {
            $hourTs = (int)($e['ts'] - ($e['ts'] % 3600));
            $hour = gmdate('Y-m-d\TH:00:00\Z', $hourTs);
            if (!isset($buckets[$hour])) {
                $buckets[$hour] = array(
                    'hour' => $hour,
                    'fatal' => 0,
                    'warning' => 0,
                    'notice' => 0,
                    'deprecated' => 0,
                );
            }
            $buckets[$hour][$e['severity']]++;
        }
    }
    ksort($buckets);
    arsort($byCode);
    return array(
        'total' => $total,
        'by_severity' => $bySeverity,
        'by_code' => $byCode,
        'buckets' => array_values($buckets),
    );
}

function gojs_php_errors_top_codes($byCode, $limit = 10) {
    $out = array();
    foreach ($byCode as $code => $count) {
        $out[] = array('code' => $code, 'count' => (int)$count);
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function gojs_php_errors_read_all($paths) {
    $raw = '';
    foreach ($paths as $p) {
        $chunk = @file_get_contents($p);
        if (is_string($chunk) && $chunk !== '') {
            $raw .= $chunk . "\n";
        }
    }
    return $raw;
}

function gojs_api_php_errors() {
    $since = isset($_GET['since']) ? (string)$_GET['since'] : '24h';
    $severityParam = isset($_GET['severity']) ? (string)$_GET['severity'] : '';
    $severities = null;
    if ($severityParam !== '') {
        $severities = array();
        foreach (explode(',', $severityParam) as $s) {
            $s = trim($s);
            if ($s !== '') {
                $severities[] = $s;
            }
        }
    }
    $paths = gojs_php_errors_candidates();
    $entries = gojs_php_errors_parse(gojs_php_errors_read_all($paths));
    $sinceTs = gojs_php_errors_since_ts($since);
    $aggregate = gojs_php_errors_aggregate($entries, $sinceTs, $severities);
    $aggregate['top_codes'] = gojs_php_errors_top_codes($aggregate['by_code'], 10);
    gojs_json_response(array(
        'since' => $since,
        'since_ts' => $sinceTs,
        'severity_filter' => $severities,
        'sources' => $paths,
        'sources_count' => count($paths),
        'parsed_total' => count($entries),
        'aggregate' => $aggregate,
    ));
}
