<?php

function gojs_shell_output($cmd) {
    if (!function_exists('shell_exec')) {
        return null;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled, true)) {
        return null;
    }
    $out = @shell_exec($cmd);
    return is_string($out) ? $out : null;
}

function gojs_process_parse_ps($output) {
    $rows = array();
    $lines = preg_split('/\r?\n/', (string)$output);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^PID\s+USER\b/i', $line)) {
            continue;
        }
        $parts = preg_split('/\s+/', $line, 6);
        if (count($parts) < 6) {
            continue;
        }
        if (!ctype_digit($parts[0])) {
            continue;
        }
        $rows[] = array(
            'pid' => (int)$parts[0],
            'user' => $parts[1],
            'mem_percent' => (float)$parts[2],
            'cpu_percent' => (float)$parts[3],
            'elapsed' => $parts[4],
            'cmdline' => $parts[5],
            'mem_kb' => null,
        );
    }
    return $rows;
}

function gojs_process_parse_tasklist_csv($output) {
    $rows = array();
    $lines = preg_split('/\r?\n/', (string)$output);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || stripos($line, 'INFO:') === 0 || strpos($line, '"') !== 0) {
            continue;
        }
        $cols = str_getcsv($line);
        if (count($cols) < 5 || !ctype_digit((string)$cols[1])) {
            continue;
        }
        $memKb = null;
        if (preg_match('/([\d.,]+)\s*K/i', $cols[4], $m)) {
            $memKb = (int)str_replace(array(',', '.'), '', $m[1]);
        }
        $rows[] = array(
            'pid' => (int)$cols[1],
            'user' => isset($cols[6]) && $cols[6] !== 'N/A' ? $cols[6] : null,
            'mem_percent' => null,
            'cpu_percent' => null,
            'elapsed' => null,
            'cmdline' => $cols[0],
            'mem_kb' => $memKb,
        );
    }
    return $rows;
}

function gojs_process_filter_php($rows) {
    $out = array();
    foreach ($rows as $r) {
        $cmd = isset($r['cmdline']) ? (string)$r['cmdline'] : '';
        if (stripos($cmd, 'php') === false) {
            continue;
        }
        if (stripos($cmd, 'grep') !== false) {
            continue;
        }
        $out[] = $r;
    }
    return $out;
}

function gojs_process_list() {
    if (PHP_OS_FAMILY === 'Windows') {
        $out = gojs_shell_output('tasklist /FI "IMAGENAME eq php.exe" /FO CSV /NH');
        if ($out === null) {
            return array('supported' => false, 'os' => 'Windows', 'processes' => array());
        }
        return array('supported' => true, 'os' => 'Windows', 'processes' => gojs_process_parse_tasklist_csv($out));
    }
    $out = gojs_shell_output('ps -eo pid,user,pmem,pcpu,etime,args');
    if ($out === null) {
        return array('supported' => false, 'os' => PHP_OS_FAMILY, 'processes' => array());
    }
    return array(
        'supported' => true,
        'os' => PHP_OS_FAMILY,
        'processes' => gojs_process_filter_php(gojs_process_parse_ps($out)),
    );
}

function gojs_php_binary() {
    if (defined('PHP_BINARY') && PHP_BINARY !== '') {
        return PHP_BINARY;
    }
    return 'php';
}

function gojs_php_snapshot_path() {
    return CONFIG_DIR . '/php_snapshot.txt';
}

function gojs_api_php_processes() {
    $list = gojs_process_list();
    gojs_json_response(array(
        'supported' => $list['supported'],
        'os' => $list['os'],
        'self_pid' => function_exists('getmypid') ? getmypid() : null,
        'php_binary' => gojs_php_binary(),
        'sapi' => php_sapi_name(),
        'count' => count($list['processes']),
        'processes' => $list['processes'],
    ));
}

function gojs_api_php_processes_snapshot() {
    $php = gojs_php_binary();
    $modules = gojs_shell_output(escapeshellarg($php) . ' -m');
    $info = gojs_shell_output(escapeshellarg($php) . ' -i');
    if ($modules === null && $info === null) {
        gojs_json_response(null, array(
            'code' => 'snapshot_unavailable',
            'message' => 'Unable to run the PHP CLI (shell_exec is disabled or the PHP binary is unavailable)',
            'php_binary' => $php,
        ), 501);
    }
    if (!is_dir(CONFIG_DIR)) {
        @mkdir(CONFIG_DIR, 0700, true);
    }
    $content = "# Go.js-Lite PHP snapshot\n";
    $content .= '# generated_at: ' . gmdate('c') . "\n";
    $content .= '# php_binary: ' . $php . "\n";
    $content .= '# php_version: ' . PHP_VERSION . "\n\n";
    $content .= "## php -m\n" . (string)$modules . "\n\n";
    $content .= "## php -i\n" . (string)$info . "\n";
    $ok = @file_put_contents(gojs_php_snapshot_path(), $content, LOCK_EX) !== false;
    if (!$ok) {
        gojs_json_response(null, array('code' => 'write_failed', 'message' => 'Failed to write php_snapshot.txt'), 500);
    }
    @chmod(gojs_php_snapshot_path(), 0600);
    gojs_log_operation('php.snapshot', 'php/processes', true, 'bytes=' . strlen($content));
    gojs_json_response(array(
        'path' => gojs_php_snapshot_path(),
        'bytes' => strlen($content),
        'modules_lines' => $modules === null ? 0 : count(preg_split('/\r?\n/', (string)$modules)),
        'php_binary' => $php,
    ));
}

function gojs_api_php_processes_snapshot_download() {
    $path = gojs_php_snapshot_path();
    if (!file_exists($path)) {
        gojs_json_response(null, array(
            'code' => 'snapshot_missing',
            'message' => 'No snapshot file yet; run a snapshot first',
        ), 404);
    }
    gojs_json_response(array(
        'path' => $path,
        'bytes' => @filesize($path),
        'content' => @file_get_contents($path),
    ));
}
