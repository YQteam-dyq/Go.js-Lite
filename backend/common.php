<?php




function gojs_return_bytes($val) {
    $val = trim($val);
    if (!$val) return 0;

    $last = strtolower($val[strlen($val) - 1]);
    $num = (int)$val;

    switch ($last) {
        case 'g':
            $num *= 1024;
        case 'm':
            $num *= 1024;
        case 'k':
            $num *= 1024;
    }

    return $num;
}

function gojs_get_capabilities() {
    global $capabilities;

    if ($capabilities !== null) {
        return $capabilities;
    }

    $disabled = ini_get('disable_functions');
    $disabled_functions = $disabled ? array_map('trim', explode(',', $disabled)) : array();

    $capabilities = array(
        'disk' => true,
        'mysql' => extension_loaded('mysqli') || extension_loaded('pdo_mysql'),
        'terminal' => function_exists('proc_open') && function_exists('exec'),
        'processes' => is_readable('/proc'),
        'cron' => function_exists('exec'),
        'zip' => class_exists('ZipArchive'),
        'targz' => class_exists('PharData'),
        'gd' => extension_loaded('gd'),
        'openBasedir' => ini_get('open_basedir') ?: false,
        'disabledFunctions' => $disabled_functions,
        'phpVersion' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'maxUpload' => gojs_return_bytes(ini_get('upload_max_filesize')),
        'maxPost' => gojs_return_bytes(ini_get('post_max_size')),
        'memoryLimit' => gojs_return_bytes(ini_get('memory_limit')),
    );

    return $capabilities;
}

function gojs_get_client_ip() {
    global $config;

    $remote_addr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    if (!$remote_addr) {
        return 'unknown';
    }

    if (empty($config['trustedProxies']) || !is_array($config['trustedProxies'])) {
        return $remote_addr;
    }

    if (!in_array($remote_addr, $config['trustedProxies'], true)) {
        return $remote_addr;
    }

    if (empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $remote_addr;
    }

    $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    $ips = array_map('trim', $ips);
    $ips = array_filter($ips);

    foreach ($ips as $ip) {
        if (!in_array($ip, $config['trustedProxies'], true)) {
            return $ip;
        }
    }

    return $remote_addr;
}

function gojs_check_brute_force() {
    $ip = gojs_get_client_ip();
    $lock_window = 15 * 60;
    $max_attempts = 5;
    $now = time();

    $attempts = array();
    if (file_exists(AUTH_LOG)) {
        $content = @file_get_contents(AUTH_LOG);
        if ($content) {
            $lines = array_filter(explode("\n", $content));
            foreach ($lines as $line) {
                $entry = json_decode($line, true);
                if (is_array($entry) && isset($entry['ip']) && $entry['ip'] === $ip) {
                    $attempts[] = $entry;
                }
            }
        }
    }

    $recent_failures = array_filter($attempts, function($a) use ($now, $lock_window) {
        return isset($a['time']) && $a['time'] > ($now - $lock_window) &&
               isset($a['success']) && $a['success'] === false;
    });

    $fail_count = count($recent_failures);

    if ($fail_count >= $max_attempts) {
        $last_failure_time = 0;
        foreach ($recent_failures as $f) {
            if ($f['time'] > $last_failure_time) {
                $last_failure_time = $f['time'];
            }
        }
        $unlock_time = $last_failure_time + $lock_window;
        $remaining = $unlock_time - $now;

        return array(
            'locked' => true,
            'retry_after' => max(0, $remaining),
            'fail_count' => $fail_count,
        );
    }

    return array(
        'locked' => false,
        'retry_after' => 0,
        'fail_count' => $fail_count,
    );
}

function gojs_migrate_040() {
    global $config;

    if (version_compare(APP_VERSION, '0.4.0', '<')) {
        return;
    }

    $current_version = isset($config['version']) ? $config['version'] : '0.0.0';
    if (version_compare($current_version, '0.4.0', '>=')) {
        return;
    }

    if (!isset($config['totp'])) {
        $config['totp'] = array(
            'enabled' => false,
            'secret_enc' => '',
            'recovery_codes_enc' => array(),
            'used_codes' => array(),
        );
    }
    if (!isset($config['backup_destinations'])) {
        $config['backup_destinations'] = array();
    }
    if (!isset($config['backup_schedules'])) {
        $config['backup_schedules'] = array();
    }
    if (!isset($config['alert_rules'])) {
        $config['alert_rules'] = array();
    }
    if (!isset($config['notification_channels'])) {
        $config['notification_channels'] = array();
    }
    if (!isset($config['ftp_accounts'])) {
        $config['ftp_accounts'] = array();
    }
    if (!isset($config['ssl_acme'])) {
        $config['ssl_acme'] = array(
            'account_email' => '',
            'staging' => false,
            'per_domain' => array(),
        );
    }
    if (!isset($config['notifications_meta'])) {
        $config['notifications_meta'] = array(
            'trim_cap' => 10000,
        );
    }
    if (empty($config['internal_cron_token'])) {
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $token = '';
        for ($i = 0; $i < 24; $i++) {
            $token .= $chars[mt_rand(0, strlen($chars) - 1)];
        }
        $config['internal_cron_token'] = $token;
    }

    gojs_save_config();

    $notifications_file = CONFIG_DIR . '/notifications.json';
    if (!file_exists($notifications_file)) {
        @file_put_contents($notifications_file, '[]');
    }

    $outbox_file = CONFIG_DIR . '/outbox.json';
    if (!file_exists($outbox_file)) {
        @file_put_contents($outbox_file, '[]');
    }

    $acme_dir = CONFIG_DIR . '/acme';
    if (!is_dir($acme_dir)) {
        @mkdir($acme_dir, 0700, true);
    }

    if (function_exists('gojs_acme_schedule_register_cronjob')) {
        gojs_acme_schedule_register_cronjob();
    }
}

function gojs_migrate_050() {
    global $config;

    $current_version = isset($config['version']) ? $config['version'] : '0.0.0';
    if (version_compare($current_version, '0.5.0', '>=')) {
        return;
    }

    
    if (!isset($config['totp']) || !is_array($config['totp'])) {
        $config['totp'] = array();
    }
    if (!isset($config['totp']['codes_format'])) {
        $legacy = isset($config['totp']['recovery_codes_enc']) && is_array($config['totp']['recovery_codes_enc']) && count($config['totp']['recovery_codes_enc']) > 0;
        $config['totp']['codes_format'] = $legacy ? 'hash_legacy' : 'enc';
    }
    if (!isset($config['api_tokens']) || !is_array($config['api_tokens'])) {
        $config['api_tokens'] = array();
    }
    if (!isset($config['trash_enabled'])) {
        $config['trash_enabled'] = true;
    }
    if (!isset($config['monitor']) || !is_array($config['monitor'])) {
        $config['monitor'] = array(
            'disk_threshold_pct' => 90,
            'inode_threshold_pct' => 90,
            'sample_interval_min' => 60,
        );
    }
    if (!isset($config['upgrade']) || !is_array($config['upgrade'])) {
        $config['upgrade'] = array(
            'last_check_at' => 0,
            'last_check_result' => null,
        );
    }
    if (!isset($config['webcron_history_cap'])) {
        $config['webcron_history_cap'] = 100;
    }

    gojs_save_config();

    
    $history_file = CONFIG_DIR . '/webcron_history.json';
    if (!file_exists($history_file)) {
        @file_put_contents($history_file, '[]');
    }

    
    $trash_dir = CONFIG_DIR . '/trash';
    if (!is_dir($trash_dir)) {
        @mkdir($trash_dir, 0700, true);
    }

    
    $monitor_file = CONFIG_DIR . '/monitor_history.json';
    if (!file_exists($monitor_file)) {
        @file_put_contents($monitor_file, '[]');
    }
}

function gojs_run_migration() {
    global $config;

    if (!file_exists(CONFIG_FILE)) {
        return;
    }

    try {
        $current_version = isset($config['version']) ? $config['version'] : '0.0.0';

        if (version_compare($current_version, APP_VERSION, '>=')) {
            return;
        }

        
        
        if (version_compare($current_version, '0.3.0', '<')) {
            
            $log_file = CONFIG_DIR . '/operation_log.json';
            if (!file_exists($log_file)) {
                @file_put_contents($log_file, '[]');
            }

            
            $backup_dir = CONFIG_DIR . '/backups';
            if (!is_dir($backup_dir)) {
                @mkdir($backup_dir, 0700, true);
            }
        }

        
        gojs_migrate_040();

        
        gojs_migrate_050();

        
        $config['version'] = APP_VERSION;
        gojs_save_config();
    } catch (Exception $e) {
        
    }
}

function gojs_log_auth_attempt($success) {
    if (!is_dir(CONFIG_DIR)) {
        @mkdir(CONFIG_DIR, 0700, true);
    }

    $ip = gojs_get_client_ip();
    $entry = array(
        'ip' => $ip,
        'time' => time(),
        'success' => $success,
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
    );

    $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents(AUTH_LOG, $line, FILE_APPEND | LOCK_EX);

    if ($success === false) {
        $counts_file = CONFIG_DIR . '/auth_fail_counts.json';
        $counts = array();
        if (file_exists($counts_file)) {
            $raw = @file_get_contents($counts_file);
            if ($raw) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) $counts = $decoded;
            }
        }
        $now = time();
        $current = isset($counts[$ip]) && is_array($counts[$ip]) ? $counts[$ip] : array('count' => 0, 'last_ts' => 0);
        $window = 15 * 60;
        if (($now - (int)$current['last_ts']) > $window) {
            $current['count'] = 0;
        }
        $current['count'] = (int)$current['count'] + 1;
        $current['last_ts'] = $now;
        $counts[$ip] = $current;
        @file_put_contents($counts_file, json_encode($counts, JSON_UNESCAPED_UNICODE), LOCK_EX);

        gojs_alerts_evaluate('auth_fail', array(
            'ip' => $ip,
            'fail_count' => (int)$current['count'],
        ));
    } else {
        $counts_file = CONFIG_DIR . '/auth_fail_counts.json';
        if (file_exists($counts_file)) {
            $raw = @file_get_contents($counts_file);
            if ($raw) {
                $counts = json_decode($raw, true);
                if (is_array($counts) && isset($counts[$ip])) {
                    unset($counts[$ip]);
                    @file_put_contents($counts_file, json_encode($counts, JSON_UNESCAPED_UNICODE), LOCK_EX);
                }
            }
        }
    }
}


function gojs_clear_auth_attempts($ip) {
    if (!file_exists(AUTH_LOG)) {
        return;
    }

    $content = @file_get_contents(AUTH_LOG);
    if ($content === false || $content === '') {
        return;
    }

    $lines = array_filter(explode("\n", $content), 'strlen');
    $kept = array();
    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        if (is_array($entry) && isset($entry['ip']) && $entry['ip'] === $ip) {
            continue;
        }
        $kept[] = $line;
    }
    @file_put_contents(AUTH_LOG, implode("\n", $kept) . "\n", LOCK_EX);
}

function gojs_log_operation($action, $target, $result = true, $detail = '') {
    $log_file = CONFIG_DIR . '/operation_log.json';

    $entry = array(
        'time' => date('Y-m-d H:i:s'),
        'timestamp' => time(),
        'ip' => gojs_get_client_ip(),
        'action' => $action,
        'target' => $target,
        'result' => $result,
        'detail' => $detail,
        'user' => 'admin',
    );

    $logs = array();
    if (file_exists($log_file)) {
        $content = @file_get_contents($log_file);
        if ($content) {
            $logs = json_decode($content, true);
            if (!is_array($logs)) {
                $logs = array();
            }
        }
    }

    $logs[] = $entry;

    $config = isset($GLOBALS['config']) ? $GLOBALS['config'] : array();
    $retention = isset($config['log_retention']) ? (int)$config['log_retention'] : 500;
    if ($retention < 50) $retention = 500;

    if (count($logs) > $retention) {
        $logs = array_slice($logs, -$retention);
    }

    
    @file_put_contents($log_file, json_encode($logs, JSON_UNESCAPED_UNICODE));

    $ctx = array(
        'ip' => $entry['ip'],
        'action' => $entry['action'],
        'detail' => $entry['detail'] !== '' ? $entry['detail'] : $entry['target'],
        'user' => 'admin',
        'timestamp' => $entry['timestamp'],
    );
    gojs_alerts_evaluate('oplog', $ctx);
}

function gojs_generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function gojs_check_csrf() {
    $method = gojs_get_method();

    if ($method === 'GET') {
        return;
    }

    
    if (!empty($_SESSION['api_token_scopes'])) {
        return;
    }

    $token = '';
    $headers = function_exists('getallheaders') ? getallheaders() : array();
    if (!$headers) {
        $headers = array();
    }

    foreach ($headers as $key => $val) {
        if (strtolower($key) === 'x-csrf-token') {
            $token = $val;
            break;
        }
    }

    if (!$token && isset($_POST['csrf_token'])) {
        $token = $_POST['csrf_token'];
    }

    if (!$token) {
        $body = gojs_get_body();
        if (isset($body['csrfToken'])) {
            $token = $body['csrfToken'];
        }
    }

    if (!$token || empty($_SESSION['csrf_token'])) {
        gojs_json_response(null, array(
            'code' => 'csrf_invalid',
            'message' => 'CSRF Token 无效',
        ), 403);
    }

    if (!function_exists('hash_equals')) {
        if (strlen($token) !== strlen($_SESSION['csrf_token'])) {
            gojs_json_response(null, array(
                'code' => 'csrf_invalid',
                'message' => 'CSRF Token 无效',
            ), 403);
        }
        $result = 0;
        for ($i = 0; $i < strlen($token); $i++) {
            $result |= ord($token[$i]) ^ ord($_SESSION['csrf_token'][$i]);
        }
        if ($result !== 0) {
            gojs_json_response(null, array(
                'code' => 'csrf_invalid',
                'message' => 'CSRF Token 无效',
            ), 403);
        }
    } else {
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            gojs_json_response(null, array(
                'code' => 'csrf_invalid',
                'message' => 'CSRF Token 无效',
            ), 403);
        }
    }
}

function gojs_check_auth() {
    global $config;

    if (empty($config['installed'])) {
        return;
    }

    gojs_check_access_token();

    
    $api_token = isset($_SERVER['HTTP_X_API_TOKEN']) ? $_SERVER['HTTP_X_API_TOKEN'] : '';
    if ($api_token !== '') {
        $tokens = isset($config['api_tokens']) && is_array($config['api_tokens']) ? $config['api_tokens'] : array();
        foreach ($tokens as $i => $t) {
            if (!is_array($t) || empty($t['token_enc'])) continue;
            $sealed = gojs_unseal_secret($t['token_enc']);
            if (is_string($sealed) && $sealed !== '' && hash_equals($sealed, $api_token)) {
                $_SESSION['api_token_scopes'] = (isset($t['scopes']) && is_array($t['scopes'])) ? array_values($t['scopes']) : array();
                
                $now = time();
                $last = isset($t['last_used_at']) ? (int)$t['last_used_at'] : 0;
                if ($now - $last > 60) {
                    $config['api_tokens'][$i]['last_used_at'] = $now;
                    gojs_save_config();
                }
                return;
            }
        }
        gojs_json_response(null, array(
            'code' => 'invalid_api_token',
            'message' => 'API Token 无效',
        ), 401);
    }

    if (!empty($_SESSION['access_token_valid'])) {
        $_SESSION['last_activity'] = time();
        return;
    }

    $timeout = isset($config['session_timeout']) ? (int)$config['session_timeout'] : 1800;

    if (empty($_SESSION['authenticated'])) {
        gojs_json_response(null, array(
            'code' => 'unauthorized',
            'message' => '请先登录',
        ), 401);
    }

    if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        session_unset();
        session_destroy();
        gojs_json_response(null, array(
            'code' => 'unauthorized',
            'message' => '登录已过期',
        ), 401);
    }

    $_SESSION['last_activity'] = time();
}

function gojs_check_access_token() {
    global $config;

    if (empty($config['installed'])) {
        return;
    }

    if (empty($config['access_token'])) {
        return;
    }

    if (!empty($_SESSION['access_token_valid']) && $_SESSION['access_token_valid'] === true) {
        return;
    }

    $token = isset($_GET['token']) ? $_GET['token'] : '';
    if (!$token && isset($_REQUEST['token'])) {
        $token = $_REQUEST['token'];
    }

    if (!$token && isset($_SERVER['HTTP_X_ACCESS_TOKEN'])) {
        $token = $_SERVER['HTTP_X_ACCESS_TOKEN'];
    }

    
    if (!$token) {
        return;
    }

    if (hash_equals($config['access_token'], $token)) {
        $_SESSION['access_token_valid'] = true;
        return;
    }

    gojs_json_response(null, array(
        'code' => 'invalid_token',
        'message' => 'Access token 无效',
    ), 401);
}

function gojs_save_config() {
    global $config;
    $config_content = '<?php' . "\n" . 'return ' . var_export($config, true) . ';' . "\n";
    @file_put_contents(CONFIG_FILE, $config_content, LOCK_EX);
    @chmod(CONFIG_FILE, 0600);
}

function gojs_require_scope($scope) {
    
    if (empty($_SESSION['api_token_scopes'])) {
        if (!empty($_SESSION['authenticated']) || !empty($_SESSION['access_token_valid'])) {
            return;
        }
        gojs_json_response(null, array(
            'code' => 'unauthorized',
            'message' => '请先登录',
        ), 401);
    }

    $scopes = $_SESSION['api_token_scopes'];
    if (!is_array($scopes) || !in_array($scope, $scopes, true)) {
        gojs_json_response(null, array(
            'code' => 'insufficient_scope',
            'message' => 'API Token 权限不足：' . $scope,
        ), 403);
    }
}

function gojs_api_tokens_create() {
    global $config;

    $body = gojs_get_body();
    $name = isset($body['name']) ? trim((string)$body['name']) : '';
    if ($name === '' || strlen($name) > 64) {
        gojs_json_response(null, array(
            'code' => 'invalid_name',
            'message' => 'Token 名称不能为空且不超过 64 个字符',
        ), 400);
    }

    $allowed = array('backup:run', 'status:read', 'files:read');
    $scopes = isset($body['scopes']) && is_array($body['scopes']) ? $body['scopes'] : array();
    $scopes = array_values(array_unique(array_filter($scopes, function ($s) use ($allowed) {
        return is_string($s) && in_array($s, $allowed, true);
    })));
    if (empty($scopes)) {
        $scopes = array('status:read');
    }

    $plain = 'gojs_' . bin2hex(random_bytes(20));
    $token = array(
        'id' => uniqid('tok_', true),
        'name' => $name,
        'scopes' => $scopes,
        'token_enc' => gojs_seal_secret($plain),
        'created_at' => time(),
        'last_used_at' => null,
    );

    if (!isset($config['api_tokens']) || !is_array($config['api_tokens'])) {
        $config['api_tokens'] = array();
    }
    $config['api_tokens'][] = $token;
    gojs_save_config();

    gojs_log_operation('api_token_create', $name, true);

    gojs_json_response(array(
        'token' => array(
            'id' => $token['id'],
            'name' => $token['name'],
            'scopes' => $token['scopes'],
            'created_at' => $token['created_at'],
            'last_used_at' => $token['last_used_at'],
        ),
        'plain_token' => $plain,
    ));
}

function gojs_api_tokens_list() {
    global $config;

    $tokens = isset($config['api_tokens']) && is_array($config['api_tokens']) ? $config['api_tokens'] : array();
    $items = array();
    foreach ($tokens as $t) {
        if (!is_array($t) || empty($t['id'])) continue;
        $items[] = array(
            'id' => $t['id'],
            'name' => isset($t['name']) ? $t['name'] : '',
            'scopes' => isset($t['scopes']) && is_array($t['scopes']) ? array_values($t['scopes']) : array(),
            'created_at' => isset($t['created_at']) ? (int)$t['created_at'] : 0,
            'last_used_at' => isset($t['last_used_at']) ? (int)$t['last_used_at'] : null,
        );
    }

    usort($items, function ($a, $b) {
        return $b['created_at'] - $a['created_at'];
    });

    gojs_json_response(array('tokens' => $items));
}

function gojs_api_token_revoke($id) {
    global $config;

    $tokens = isset($config['api_tokens']) && is_array($config['api_tokens']) ? $config['api_tokens'] : array();
    $found = false;
    foreach ($tokens as $i => $t) {
        if (is_array($t) && isset($t['id']) && $t['id'] === $id) {
            array_splice($tokens, $i, 1);
            $found = true;
            break;
        }
    }
    if (!$found) {
        gojs_json_response(null, array(
            'code' => 'token_not_found',
            'message' => 'Token 不存在',
        ), 404);
    }

    $config['api_tokens'] = array_values($tokens);
    gojs_save_config();
    gojs_log_operation('api_token_revoke', $id, true);
    gojs_json_response(array('success' => true));
}

function gojs_api_status() {
    global $root_path;

    gojs_require_scope('status:read');

    $caps = gojs_get_capabilities();
    $disk_total = @disk_total_space($root_path);
    $disk_free = @disk_free_space($root_path);
    $disk_used = ($disk_total && $disk_free) ? ($disk_total - $disk_free) : 0;
    list($file_count, $total_size) = gojs_count_files($root_path, 3);

    $backup_dir = CONFIG_DIR . '/backups';
    $backups_count = 0;
    if (is_dir($backup_dir)) {
        $files = glob($backup_dir . '/backup-*.zip');
        if (is_array($files)) $backups_count = count($files);
    }

    gojs_json_response(array(
        'ok' => true,
        'phpVersion' => isset($caps['phpVersion']) ? $caps['phpVersion'] : PHP_VERSION,
        'sapi' => isset($caps['sapi']) ? $caps['sapi'] : PHP_SAPI,
        'diskTotal' => $disk_total,
        'diskFree' => $disk_free,
        'diskUsed' => $disk_used,
        'fileCount' => $file_count,
        'totalSize' => $total_size,
        'backups_count' => $backups_count,
    ));
}

function gojs_api_backup_run_rest() {
    gojs_require_scope('backup:run');
    
    gojs_api_backup_create();
}

function gojs_api_files_rest() {
    gojs_require_scope('files:read');
    
    gojs_api_files();
}

function gojs_seal_secret($plain) {
    if ($plain === null || $plain === '') return '';
    return gojs_encrypt((string)$plain);
}
