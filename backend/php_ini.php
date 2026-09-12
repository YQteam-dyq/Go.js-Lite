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
        'opcache.enable' => array('severity' => 'danger', 'note' => 'OPcache 可显著降低脚本编译开销，生产环境建议开启'),
        'opcache.memory_consumption' => array('severity' => 'warning', 'note' => '缓存 256M 通常足够中型应用，过小会导致频繁淘汰'),
        'opcache.interned_strings_buffer' => array('severity' => 'info', 'note' => '驻留字符串缓冲，16M 适合大多数项目'),
        'opcache.max_accelerated_files' => array('severity' => 'warning', 'note' => '需大于项目文件总数，否则缓存命中率下降'),
        'opcache.validate_timestamps' => array('severity' => 'warning', 'note' => '生产环境关闭可避免每次请求 stat 文件；部署后需 reload'),
        'opcache.save_comments' => array('severity' => 'info', 'note' => '保留注释，供注解/文档工具使用'),
        'opcache.jit' => array('severity' => 'info', 'note' => 'tracing 模式在多数负载下收益最好'),
        'opcache.jit_buffer_size' => array('severity' => 'info', 'note' => 'JIT 缓冲区，128M 为常见起点'),
        'session.use_strict_mode' => array('severity' => 'danger', 'note' => '严格模式可防止会话固定攻击'),
        'session.cookie_httponly' => array('severity' => 'danger', 'note' => '禁止 JS 读取会话 Cookie，缓解 XSS 窃取'),
        'session.cookie_samesite' => array('severity' => 'warning', 'note' => 'Lax 可缓解 CSRF'),
        'session.gc_maxlifetime' => array('severity' => 'info', 'note' => '会话有效期，需与业务安全策略一致'),
        'expose_php' => array('severity' => 'warning', 'note' => '关闭可避免响应头泄露 PHP 版本'),
        'display_errors' => array('severity' => 'danger', 'note' => '生产环境必须关闭，避免泄露路径与代码'),
        'log_errors' => array('severity' => 'warning', 'note' => '开启以便记录运行时错误'),
        'max_execution_time' => array('severity' => 'info', 'note' => '过小会导致长任务中断，过大易被慢请求拖垮'),
        'memory_limit' => array('severity' => 'info', 'note' => '与业务数据规模匹配'),
        'upload_max_filesize' => array('severity' => 'info', 'note' => '上传上限，需大于业务最大文件'),
        'post_max_size' => array('severity' => 'info', 'note' => '需不小于 upload_max_filesize'),
        'date.timezone' => array('severity' => 'info', 'note' => '显式设置时区，避免告警与时间偏移'),
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
            'message' => 'mode 必须是 tracing / function / none',
        ), 400);
    }
    $bufferMb = isset($body['buffer_size_mb']) ? (int)$body['buffer_size_mb'] : 0;
    if ($bufferMb < 0 || $bufferMb > 4096) {
        gojs_json_response(null, array(
            'code' => 'invalid_buffer',
            'message' => 'buffer_size_mb 必须在 0-4096 之间',
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
            'message' => '当前运行模式不允许运行时修改 JIT，已写入 .user.ini，需重启 PHP-FPM 后生效',
        ), 501);
    }
    gojs_json_response($payload);
}
