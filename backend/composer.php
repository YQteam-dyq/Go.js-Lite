<?php

function gojs_composer_find() {
    if (array_key_exists('gojs_composer_override', $GLOBALS)) {
        return $GLOBALS['gojs_composer_override'];
    }
    if (!function_exists('shell_exec')) {
        return null;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled, true)) {
        return null;
    }
    $which = (PHP_OS_FAMILY === 'Windows') ? 'where composer' : 'command -v composer';
    $out = @shell_exec($which);
    if (!is_string($out) || trim($out) === '') {
        return null;
    }
    $first = strtok(trim($out), "\r\n");
    if ($first === false || trim($first) === '') {
        return null;
    }
    return trim($first);
}

function gojs_composer_available() {
    return gojs_composer_find() !== null;
}

function gojs_composer_base_cmd() {
    $exe = gojs_composer_find();
    if ($exe === null) {
        return null;
    }
    if (substr(strtolower($exe), -5) === '.phar') {
        $php = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
        return escapeshellarg($php) . ' ' . escapeshellarg($exe);
    }
    return escapeshellarg($exe);
}

function gojs_composer_run(array $args, $timeout = 300) {
    $base = gojs_composer_base_cmd();
    if ($base === null) {
        return array('ok' => false, 'code' => null, 'output' => 'composer 不可用');
    }
    if (!empty($GLOBALS['gojs_composer_run_disabled'])) {
        return array('ok' => false, 'code' => null, 'output' => 'disabled');
    }
    if (!function_exists('proc_open')) {
        return array('ok' => false, 'code' => null, 'output' => 'proc_open 不可用');
    }
    $cmd = $base;
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg((string)$a);
    }
    $descriptors = array(
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );
    $cwd = (defined('PANEL_ROOT') && is_dir(PANEL_ROOT)) ? PANEL_ROOT : null;
    $proc = @proc_open($cmd, $descriptors, $pipes, $cwd);
    if (!is_resource($proc)) {
        return array('ok' => false, 'code' => null, 'output' => 'proc_open 启动失败');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + max(1, (int)$timeout);
    while (true) {
        $stdout .= (string)stream_get_contents($pipes[1]);
        $stderr .= (string)stream_get_contents($pipes[2]);
        $info = proc_get_status($proc);
        if (!$info['running']) {
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            $stderr .= "\n[timeout]";
            break;
        }
        usleep(20000);
    }
    $stdout .= (string)stream_get_contents($pipes[1]);
    $stderr .= (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    $output = trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''));
    if (strlen($output) > 20000) {
        $output = substr($output, 0, 20000) . "\n...[truncated]";
    }
    return array('ok' => ((int)$code === 0), 'code' => (int)$code, 'output' => $output);
}

function gojs_composer_log_path() {
    return CONFIG_DIR . '/composer_install.log';
}

function gojs_composer_log_append($action, $result) {
    if (!is_dir(CONFIG_DIR)) {
        @mkdir(CONFIG_DIR, 0700, true);
    }
    $block = '[' . gmdate('c') . '] ' . $action
        . ' exit=' . (isset($result['code']) ? var_export($result['code'], true) : 'null') . "\n"
        . (isset($result['output']) ? (string)$result['output'] : '') . "\n\n";
    @file_put_contents(gojs_composer_log_path(), $block, FILE_APPEND | LOCK_EX);
}

function gojs_composer_log_tail($lines = 60) {
    $path = gojs_composer_log_path();
    if (!file_exists($path)) {
        return '';
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return '';
    }
    $all = preg_split('/\r?\n/', $raw);
    if (end($all) === '') {
        array_pop($all);
    }
    return implode("\n", array_slice($all, -max(1, (int)$lines)));
}

function gojs_composer_project_file($name) {
    if (!defined('PANEL_ROOT')) {
        return null;
    }
    $candidates = array(
        rtrim(PANEL_ROOT, '/\\') . '/' . $name,
        rtrim(dirname(PANEL_ROOT), '/\\') . '/' . $name,
    );
    foreach ($candidates as $c) {
        if (is_file($c)) {
            return $c;
        }
    }
    return null;
}

function gojs_composer_read_json_file($name) {
    $path = gojs_composer_project_file($name);
    if ($path === null) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function gojs_composer_dependency_depth($name, $requires, &$memo, $stack) {
    if (isset($memo[$name])) {
        return $memo[$name];
    }
    if (isset($stack[$name])) {
        return 0;
    }
    if (!isset($requires[$name])) {
        $memo[$name] = 1;
        return 1;
    }
    $stack[$name] = true;
    $best = 1;
    foreach ($requires[$name] as $dep) {
        if (!isset($requires[$dep])) {
            continue;
        }
        $child = gojs_composer_dependency_depth($dep, $requires, $memo, $stack) + 1;
        if ($child > $best) {
            $best = $child;
        }
    }
    $memo[$name] = $best;
    return $best;
}

function gojs_composer_tree_depth($lock) {
    if (!is_array($lock) || empty($lock['packages']) || !is_array($lock['packages'])) {
        return 0;
    }
    $requires = array();
    foreach ($lock['packages'] as $pkg) {
        if (!isset($pkg['name'])) {
            continue;
        }
        $name = (string)$pkg['name'];
        $deps = array();
        if (isset($pkg['require']) && is_array($pkg['require'])) {
            foreach ($pkg['require'] as $dep => $ver) {
                if (strpos((string)$dep, '/') === false) {
                    continue;
                }
                $deps[] = (string)$dep;
            }
        }
        $requires[$name] = $deps;
    }
    $memo = array();
    $max = 0;
    foreach (array_keys($requires) as $n) {
        $d = gojs_composer_dependency_depth($n, $requires, $memo, array());
        if ($d > $max) {
            $max = $d;
        }
    }
    return $max;
}

function gojs_composer_lock_stats($lock) {
    $stats = array(
        'packages' => 0,
        'dev_packages' => 0,
        'depth' => 0,
        'content_hash' => null,
        'plugin_api_version' => null,
        'platform' => array(),
    );
    if (!is_array($lock)) {
        return $stats;
    }
    if (isset($lock['packages']) && is_array($lock['packages'])) {
        $stats['packages'] = count($lock['packages']);
    }
    if (isset($lock['packages-dev']) && is_array($lock['packages-dev'])) {
        $stats['dev_packages'] = count($lock['packages-dev']);
    }
    if (isset($lock['content-hash'])) {
        $stats['content_hash'] = (string)$lock['content-hash'];
    }
    if (isset($lock['plugin-api-version'])) {
        $stats['plugin_api_version'] = (string)$lock['plugin-api-version'];
    }
    if (isset($lock['platform']) && is_array($lock['platform'])) {
        $stats['platform'] = $lock['platform'];
    }
    $stats['depth'] = gojs_composer_tree_depth($lock);
    return $stats;
}

function gojs_composer_require_available() {
    if (gojs_composer_available()) {
        return;
    }
    gojs_json_response(null, array(
        'code' => 'composer_unavailable',
        'message' => '未检测到 composer 可执行文件，请先安装 composer 并加入 PATH',
        'install_guide' => array(
            'url' => 'https://getcomposer.org/download/',
            'steps' => array(
                'curl -sS https://getcomposer.org/installer | php',
                'mv composer.phar /usr/local/bin/composer',
                '或使用系统包管理器：apt install composer / brew install composer',
            ),
        ),
    ), 501);
}

function gojs_composer_validate_package($package) {
    if (!is_string($package)) {
        return false;
    }
    return (bool)preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]?|-{0,2})[a-z0-9]+)*$#', $package);
}

function gojs_api_composer_status() {
    $json = gojs_composer_read_json_file('composer.json');
    $lock = gojs_composer_read_json_file('composer.lock');
    $exe = gojs_composer_find();
    $autoload = gojs_composer_project_file('vendor/autoload.php');
    $phpReq = null;
    if (is_array($json) && isset($json['require']['php'])) {
        $phpReq = (string)$json['require']['php'];
    }
    gojs_json_response(array(
        'available' => $exe !== null,
        'executable' => $exe,
        'php_version' => PHP_VERSION,
        'php_requirement' => $phpReq,
        'composer_json' => $json !== null,
        'composer_lock' => $lock !== null,
        'composer_json_path' => gojs_composer_project_file('composer.json'),
        'composer_lock_path' => gojs_composer_project_file('composer.lock'),
        'vendor_autoload' => $autoload,
        'vendor_present' => $autoload !== null,
        'lock' => gojs_composer_lock_stats($lock),
        'install_guide' => $exe === null ? array(
            'url' => 'https://getcomposer.org/download/',
            'steps' => array(
                'curl -sS https://getcomposer.org/installer | php',
                'mv composer.phar /usr/local/bin/composer',
            ),
        ) : null,
    ));
}

function gojs_api_composer_install() {
    gojs_composer_require_available();
    $res = gojs_composer_run(array('install', '--no-dev', '--no-interaction'));
    gojs_composer_log_append('install', $res);
    gojs_log_operation('composer.install', 'composer', $res['ok'], 'exit=' . var_export($res['code'], true));
    if (!$res['ok']) {
        gojs_json_response(array('log' => gojs_composer_log_tail(80)), array(
            'code' => 'composer_failed',
            'message' => 'composer install 执行失败',
            'detail' => $res['output'],
        ), 500);
    }
    gojs_json_response(array('ok' => true, 'log' => gojs_composer_log_tail(80)));
}

function gojs_api_composer_require() {
    gojs_composer_require_available();
    $body = gojs_get_body();
    $package = isset($body['package']) ? (string)$body['package'] : '';
    if (!gojs_composer_validate_package($package)) {
        gojs_json_response(null, array(
            'code' => 'invalid_package',
            'message' => '包名不合法，应为 vendor/name 形式',
        ), 400);
    }
    $args = array('require', $package, '--no-interaction');
    $version = isset($body['version']) ? trim((string)$body['version']) : '';
    if ($version !== '') {
        $args[] = $package . ':' . $version;
    }
    $res = gojs_composer_run($args);
    gojs_composer_log_append('require ' . $package, $res);
    gojs_log_operation('composer.require', $package, $res['ok'], 'exit=' . var_export($res['code'], true));
    if (!$res['ok']) {
        gojs_json_response(array('log' => gojs_composer_log_tail(80)), array(
            'code' => 'composer_failed',
            'message' => 'composer require 执行失败',
            'detail' => $res['output'],
        ), 500);
    }
    gojs_json_response(array('ok' => true, 'package' => $package, 'log' => gojs_composer_log_tail(80)));
}

function gojs_api_composer_update() {
    gojs_composer_require_available();
    $res = gojs_composer_run(array('update', '--no-interaction'));
    gojs_composer_log_append('update', $res);
    gojs_log_operation('composer.update', 'composer', $res['ok'], 'exit=' . var_export($res['code'], true));
    if (!$res['ok']) {
        gojs_json_response(array('log' => gojs_composer_log_tail(80)), array(
            'code' => 'composer_failed',
            'message' => 'composer update 执行失败',
            'detail' => $res['output'],
        ), 500);
    }
    gojs_json_response(array('ok' => true, 'log' => gojs_composer_log_tail(80)));
}

function gojs_api_composer_json() {
    gojs_json_response(array(
        'json' => gojs_composer_read_json_file('composer.json'),
        'lock' => gojs_composer_read_json_file('composer.lock'),
        'json_path' => gojs_composer_project_file('composer.json'),
        'lock_path' => gojs_composer_project_file('composer.lock'),
    ));
}
