<?php

define('VERSION', '0.2.1');
define('ROOT', dirname(__FILE__));
define('PANEL_ROOT', ROOT);
define('CONFIG_DIR', ROOT . '/.gojs');
define('CONFIG_FILE', CONFIG_DIR . '/config.php');
define('AUTH_LOG', CONFIG_DIR . '/auth.log');
define('DB_CONNECTIONS_FILE', CONFIG_DIR . '/db_connections.json');

$config = array();
$installed = false;
$root_path = ROOT;
$GLOBALS['files_root'] = ROOT;
$capabilities = null;

gojs_init();

function gojs_init() {
    global $config, $installed, $root_path;

    set_error_handler('gojs_error_handler');
    set_exception_handler('gojs_exception_handler');

    ini_set('display_errors', '0');
    error_reporting(E_ALL);

    if (session_status() == PHP_SESSION_NONE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        $cookie_path = '/gojs/';
        $dir_name = basename(ROOT);
        if ($dir_name !== 'gojs') {
            $cookie_path = '/' . $dir_name . '/';
        }
        if (PHP_SAPI === 'cli-server') {
            $cookie_path = '/gojs/';
        }
        session_set_cookie_params(array(
            'lifetime' => 86400,
            'path' => $cookie_path,
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ));
        session_start();
    }

    if (file_exists(CONFIG_FILE)) {
        $config = include CONFIG_FILE;
        if (is_array($config)) {
            $installed = !empty($config['installed']);
            if (!empty($config['root_path']) && is_dir($config['root_path'])) {
                $root_path = rtrim($config['root_path'], '/');
                $GLOBALS['files_root'] = $root_path;
            } else {
                $dir_name = basename(ROOT);
                if ($dir_name === 'gojs') {
                    $parent = @realpath(ROOT . '/..');
                    if ($parent && $parent !== ROOT) {
                        $GLOBALS['files_root'] = rtrim($parent, '/');
                        $root_path = $GLOBALS['files_root'];
                    } else {
                        $GLOBALS['files_root'] = ROOT;
                    }
                } else {
                    $GLOBALS['files_root'] = ROOT;
                }
            }
        }
    } else {
        $dir_name = basename(ROOT);
        if ($dir_name === 'gojs') {
            $parent = @realpath(ROOT . '/..');
            if ($parent && $parent !== ROOT) {
                $GLOBALS['files_root'] = rtrim($parent, '/');
                $root_path = $GLOBALS['files_root'];
            }
        }
    }

    gojs_dispatch();
}

function gojs_error_handler($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }

    
$non_fatal = array(E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, E_STRICT);
    if (in_array($errno, $non_fatal, true)) {
        return false;
    }

    $error_types = array(
        E_ERROR => 'E_ERROR',
        E_WARNING => 'E_WARNING',
        E_PARSE => 'E_PARSE',
        E_NOTICE => 'E_NOTICE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_CORE_WARNING => 'E_CORE_WARNING',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING',
        E_USER_ERROR => 'E_USER_ERROR',
        E_USER_WARNING => 'E_USER_WARNING',
        E_USER_NOTICE => 'E_USER_NOTICE',
        E_STRICT => 'E_STRICT',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED',
    );

    $type = isset($error_types[$errno]) ? $error_types[$errno] : 'UNKNOWN';

    gojs_json_response(null, array(
        'code' => 'server_error',
        'message' => $type . ': ' . $errstr,
    ), 500);

    exit(1);
}

function gojs_exception_handler($exception) {
    gojs_json_response(null, array(
        'code' => 'server_error',
        'message' => '服务器内部错误',
    ), 500);

    exit(1);
}

function gojs_json_response($data = null, $error = null, $status_code = 200) {
    if (!headers_sent()) {
        $sent_headers = array_map(function ($h) {
            $parts = explode(':', $h, 2);
            return strtolower(trim($parts[0]));
        }, headers_list());

        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status_code);

        if (!in_array('x-content-type-options', $sent_headers)) {
            header('X-Content-Type-Options: nosniff');
        }
        if (!in_array('x-frame-options', $sent_headers)) {
            header('X-Frame-Options: DENY');
        }
        if (!in_array('referrer-policy', $sent_headers)) {
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
    }

    $response = array('ok' => $error === null);

    if ($data !== null) {
        $response['data'] = $data;
    }

    if ($error !== null) {
        $response['error'] = $error;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function gojs_get_method() {
    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    return strtoupper($method);
}

function gojs_get_body() {
    static $body = null;
    if ($body !== null) return $body;

    $raw = file_get_contents('php://input');
    if (!$raw) {
        $body = array();
        return $body;
    }

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        $body = array();
    }

    return $body;
}

function gojs_get_param($key, $default = null) {
    if (isset($_GET[$key])) {
        return $_GET[$key];
    }
    $body = gojs_get_body();
    if (isset($body[$key])) {
        return $body[$key];
    }
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }
    return $default;
}

function gojs_dispatch() {
    $api = isset($_GET['api']) ? $_GET['api'] : '';
    if (!$api && isset($_REQUEST['api'])) {
        $api = $_REQUEST['api'];
    }

    if (!$api && isset($_SERVER['REQUEST_URI'])) {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if ($uri && strpos($uri, '/api/') === 0) {
            $api = ltrim(substr($uri, 5), '/');
        } elseif ($uri && $uri === '/api') {
            $api = '';
        }
    }

    $api = ltrim($api, '/');

    if (!$api) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '接口不存在',
        ), 404);
        return;
    }

    $method = gojs_get_method();

    $public_routes = array('bootstrap', 'install', 'login');

    if (!in_array($api, $public_routes)) {
        gojs_check_auth();
        gojs_check_csrf();
    }

    switch ($api) {
        case 'bootstrap':
            gojs_api_bootstrap();
            break;
        case 'install':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_install();
            break;
        case 'login':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_login();
            break;
        case 'logout':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_logout();
            break;
        case 'change-password':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_change_password();
            break;
        case 'settings':
            if ($method === 'GET') {
                gojs_api_get_settings();
            } elseif ($method === 'POST') {
                gojs_api_update_settings();
            } else {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            break;
        case 'regenerate-access-token':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_regenerate_access_token();
            break;
        case 'settings/export':
            if ($method !== 'GET') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_settings_export();
            break;
        case 'settings/reset':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_settings_reset();
            break;
        case 'files':
            gojs_api_files();
            break;
        case 'file-content':
            gojs_api_file_content();
            break;
        case 'file-save':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_file_save();
            break;
        case 'file-mkdir':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_file_mkdir();
            break;
        case 'file-touch':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_file_touch();
            break;
        case 'file-delete':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_file_delete();
            break;
        case 'file-rename':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_file_rename();
            break;
        case 'file-copy':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_file_copy();
            break;
        case 'file-chmod':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_file_chmod();
            break;
        case 'file-search':
            gojs_api_file_search();
            break;
        case 'file-zip':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_file_zip();
            break;
        case 'file-unzip':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_file_unzip();
            break;
        case 'file-targz':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_file_targz();
            break;
        case 'file-untargz':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_file_untargz();
            break;
        case 'upload':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_upload();
            break;
        case 'upload-chunk':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_upload_chunk();
            break;
        case 'error-log':
            gojs_api_error_log();
            break;
        case 'error-log/clear':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_error_log_clear();
            break;
        case 'install/check':
            gojs_api_install_check();
            break;
        case 'download':
            gojs_api_download();
            break;
        case 'dashboard':
            gojs_api_dashboard();
            break;
        case 'phpinfo':
            gojs_api_phpinfo();
            break;
        case 'phpinfo/ini':
            gojs_api_phpinfo_ini();
            break;
        case 'health-check':
            gojs_api_health_check();
            break;
        case 'system':
            gojs_api_system();
            break;
        case 'system/processes':
            gojs_api_processes();
            break;
        case 'system/cron':
            gojs_api_cron();
            break;
        case 'disk-analysis':
            gojs_api_disk_analysis();
            break;
        case 'disk-analysis/large-files':
            gojs_api_disk_analysis_large_files();
            break;
        case 'db/connections':
            gojs_api_db_connections();
            break;
        case 'db/databases':
            gojs_api_db_databases();
            break;
        case 'db/tables':
            gojs_api_db_tables();
            break;
        case 'db/structure':
            gojs_api_db_structure();
            break;
        case 'db/sql':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_db_sql();
            break;
        case 'db/export':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_db_export();
            break;
        case 'db/import':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_db_import();
            break;
        case 'htaccess':
            gojs_api_htaccess();
            break;
        case 'htaccess/generate':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_htaccess_generate();
            break;
        case 'htaccess/reset':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_htaccess_reset();
            break;
        default:

            if (strpos($api, 'db/connections/') === 0) {
                $id = substr($api, strlen('db/connections/'));
                gojs_api_db_connection($id, $method);
                break;
            }

            gojs_json_response(null, array(
                'code' => 'not_found',
                'message' => '接口不存在: ' . $api,
            ), 404);
            break;
    }
}

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
    $max_attempts = 5;
    $lockout_time = 900; 

    if (!file_exists(AUTH_LOG)) {
        return true;
    }

    $lines = @file(AUTH_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return true;
    }

    $now = time();
    $recent_failures = 0;

    foreach ($lines as $line) {
        $entry = json_decode($line, true);
        if (!$entry || empty($entry['ip']) || $entry['ip'] !== $ip) {
            continue;
        }

        if (empty($entry['time']) || ($now - $entry['time']) > $lockout_time) {
            continue;
        }

        if (empty($entry['success'])) {
            $recent_failures++;
        }
    }

    return $recent_failures < $max_attempts;
}

function gojs_log_auth_attempt($success) {
    if (!is_dir(CONFIG_DIR)) {
        @mkdir(CONFIG_DIR, 0700, true);
    }

    $entry = array(
        'ip' => gojs_get_client_ip(),
        'time' => time(),
        'success' => $success,
    );

    $line = json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents(AUTH_LOG, $line, FILE_APPEND);
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

    if ($token && hash_equals($config['access_token'], $token)) {
        $_SESSION['access_token_valid'] = true;
        return;
    }

    gojs_json_response(null, array(
        'code' => 'not_found',
        'message' => 'Not Found',
    ), 404);
}

function gojs_save_config() {
    global $config;
    $config_content = '<?php' . "\n" . 'return ' . var_export($config, true) . ';' . "\n";
    @file_put_contents(CONFIG_FILE, $config_content, LOCK_EX);
    @chmod(CONFIG_FILE, 0600);
}

function gojs_api_bootstrap() {
    global $config, $installed;

    gojs_check_access_token();

    $capabilities = gojs_get_capabilities();
    $csrf_token = gojs_generate_csrf_token();

    $authenticated = !empty($_SESSION['authenticated']);

    $data = array(
        'authenticated' => $authenticated,
        'installed' => $installed,
        'csrfToken' => $csrf_token,
        'capabilities' => $capabilities,
        'backendVersion' => VERSION,
        'frontendVersion' => VERSION,
    );

    if ($authenticated) {
        $data['user'] = array(
            'username' => 'admin',
        );

        $settings = isset($_SESSION['settings']) ? $_SESSION['settings'] : array();
        if (!$settings) {
            $settings = array(
                'theme' => 'system',
                'language' => 'zh',
                'sessionTimeout' => isset($config['session_timeout']) ? (int)$config['session_timeout'] : 1800,
            );
        }
        $data['settings'] = $settings;
    }

    gojs_json_response($data);
}

function gojs_api_install() {
    global $config, $installed, $root_path;

    if ($installed) {
        gojs_json_response(null, array(
            'code' => 'already_installed',
            'message' => '系统已安装',
        ), 400);
    }

    $password = gojs_get_param('password', '');
    $root_path_param = gojs_get_param('rootPath', '');

    if (strlen($password) < 8) {
        gojs_json_response(null, array(
            'code' => 'invalid_password',
            'message' => '密码长度至少为8位',
        ), 400);
    }

    $new_root_path = ROOT;
    if ($root_path_param) {
        $real_path = realpath($root_path_param);
        if (!$real_path || !is_dir($real_path)) {
            gojs_json_response(null, array(
                'code' => 'invalid_root_path',
                'message' => '根目录路径无效',
            ), 400);
        }
        $new_root_path = $real_path;
    }

    if (!is_dir(CONFIG_DIR)) {
        if (!@mkdir(CONFIG_DIR, 0700, true)) {
            gojs_json_response(null, array(
                'code' => 'create_config_dir_failed',
                'message' => '创建配置目录失败',
            ), 500);
        }
    }

    $encryption_key = bin2hex(random_bytes(16));

    $access_token = bin2hex(random_bytes(24));

    $config_data = array(
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'root_path' => $new_root_path,
        'installed' => true,
        'install_time' => time(),
        'session_timeout' => 1800,
        'encryption_key' => $encryption_key,
        'access_token' => $access_token,
    );

    $config_content = '<?php' . "\n" . 'return ' . var_export($config_data, true) . ';' . "\n";

    if (@file_put_contents(CONFIG_FILE, $config_content, LOCK_EX) === false) {
        gojs_json_response(null, array(
            'code' => 'write_config_failed',
            'message' => '写入配置文件失败',
        ), 500);
    }

    @chmod(CONFIG_FILE, 0600);

    $config = $config_data;
    $installed = true;
    $root_path = $new_root_path;

    $_SESSION['access_token_valid'] = true;
    $_SESSION['authenticated'] = true;
    $_SESSION['username'] = 'admin';
    $_SESSION['last_activity'] = time();
    $csrf_token = gojs_generate_csrf_token();

    $capabilities = gojs_get_capabilities();
    gojs_json_response(array(
        'authenticated' => true,
        'installed' => true,
        'csrfToken' => $csrf_token,
        'capabilities' => $capabilities,
        'user' => array('username' => 'admin'),
        'backendVersion' => VERSION,
        'frontendVersion' => VERSION,
        'accessToken' => $access_token,
    ));
}

function gojs_api_login() {
    global $config, $installed;

    gojs_check_access_token();

    if (!$installed) {
        gojs_json_response(null, array(
            'code' => 'not_installed',
            'message' => '系统未安装',
        ), 400);
    }

    if (!gojs_check_brute_force()) {
        gojs_log_auth_attempt(false);
        gojs_json_response(null, array(
            'code' => 'rate_limited',
            'message' => '登录失败次数过多，请15分钟后再试',
        ), 429);
    }

    $username = gojs_get_param('username', '');
    $password = gojs_get_param('password', '');

    if ($username !== 'admin') {
        gojs_log_auth_attempt(false);
        gojs_json_response(null, array(
            'code' => 'invalid_credentials',
            'message' => '用户名或密码错误',
        ), 401);
    }

    if (empty($config['password_hash']) || !password_verify($password, $config['password_hash'])) {
        gojs_log_auth_attempt(false);
        gojs_json_response(null, array(
            'code' => 'invalid_credentials',
            'message' => '用户名或密码错误',
        ), 401);
    }

    gojs_log_auth_attempt(true);

    session_regenerate_id(true);

    $_SESSION['authenticated'] = true;
    $_SESSION['username'] = 'admin';
    $_SESSION['last_activity'] = time();
    $csrf_token = gojs_generate_csrf_token();

    $capabilities = gojs_get_capabilities();

    $data = array(
        'authenticated' => true,
        'installed' => true,
        'csrfToken' => $csrf_token,
        'capabilities' => $capabilities,
        'backendVersion' => VERSION,
        'frontendVersion' => VERSION,
        'user' => array(
            'username' => 'admin',
        ),
    );

    $settings = isset($_SESSION['settings']) ? $_SESSION['settings'] : array();
    if (!$settings) {
        $settings = array(
            'theme' => 'system',
            'language' => 'zh',
            'sessionTimeout' => isset($config['session_timeout']) ? (int)$config['session_timeout'] : 1800,
        );
    }
    $data['settings'] = $settings;

    gojs_json_response($data);
}

function gojs_api_logout() {
    session_unset();
    session_destroy();

    gojs_json_response(array('success' => true));
}

function gojs_api_change_password() {
    global $config;

    $old_password = gojs_get_param('oldPassword', '');
    $new_password = gojs_get_param('newPassword', '');

    if (empty($config['password_hash']) || !password_verify($old_password, $config['password_hash'])) {
        gojs_json_response(null, array(
            'code' => 'invalid_password',
            'message' => '旧密码错误',
        ), 400);
    }

    if (strlen($new_password) < 8) {
        gojs_json_response(null, array(
            'code' => 'invalid_password',
            'message' => '新密码长度至少为8位',
        ), 400);
    }

    $config['password_hash'] = password_hash($new_password, PASSWORD_BCRYPT);

    $config_content = '<?php' . "\n" . 'return ' . var_export($config, true) . ';' . "\n";

    if (@file_put_contents(CONFIG_FILE, $config_content, LOCK_EX) === false) {
        gojs_json_response(null, array(
            'code' => 'write_config_failed',
            'message' => '写入配置文件失败',
        ), 500);
    }

    gojs_json_response(array('success' => true));
}

function gojs_api_get_settings() {
    global $config;

    $settings = isset($_SESSION['settings']) ? $_SESSION['settings'] : array();
    if (!$settings) {
        $settings = array(
            'theme' => 'system',
            'language' => 'zh',
            'sessionTimeout' => isset($config['session_timeout']) ? (int)$config['session_timeout'] : 1800,
        );
    }

    if (!empty($config['access_token'])) {
        $settings['accessToken'] = $config['access_token'];
    }

    gojs_json_response($settings);
}

function gojs_api_update_settings() {
    global $config;

    $body = gojs_get_body();

    $current_settings = isset($_SESSION['settings']) ? $_SESSION['settings'] : array();
    if (!$current_settings) {
        $current_settings = array(
            'theme' => 'system',
            'language' => 'zh',
            'sessionTimeout' => isset($config['session_timeout']) ? (int)$config['session_timeout'] : 1800,
        );
    }

    $new_settings = array_merge($current_settings, $body);

    if (isset($new_settings['theme']) && !in_array($new_settings['theme'], array('light', 'dark', 'system'))) {
        $new_settings['theme'] = 'system';
    }
    if (isset($new_settings['language']) && !in_array($new_settings['language'], array('zh', 'en'))) {
        $new_settings['language'] = 'zh';
    }
    if (isset($new_settings['sessionTimeout'])) {
        $new_settings['sessionTimeout'] = max(300, min(86400, (int)$new_settings['sessionTimeout']));

        $config['session_timeout'] = $new_settings['sessionTimeout'];
        $config_content = '<?php' . "\n" . 'return ' . var_export($config, true) . ';' . "\n";
        @file_put_contents(CONFIG_FILE, $config_content, LOCK_EX);
    }

    $_SESSION['settings'] = $new_settings;

    gojs_json_response($new_settings);
}

function gojs_api_regenerate_access_token() {
    global $config;

    $new_token = bin2hex(random_bytes(24));
    $config['access_token'] = $new_token;
    gojs_save_config();

    $_SESSION['access_token_valid'] = true;

    gojs_json_response(array(
        'accessToken' => $new_token,
    ));
}

function gojs_api_settings_export() {
    global $config;

    $settings = isset($_SESSION['settings']) ? $_SESSION['settings'] : array();
    if (!$settings) {
        $settings = array(
            'theme' => 'system',
            'language' => 'zh',
            'sessionTimeout' => isset($config['session_timeout']) ? (int)$config['session_timeout'] : 1800,
        );
    }

    $export = array(
        'theme' => isset($settings['theme']) ? $settings['theme'] : 'system',
        'language' => isset($settings['language']) ? $settings['language'] : 'zh',
        'sessionTimeout' => isset($config['session_timeout']) ? (int)$config['session_timeout'] : (isset($settings['sessionTimeout']) ? (int)$settings['sessionTimeout'] : 1800),
        'rootPath' => isset($config['root_path']) ? $config['root_path'] : ROOT,
        'exportedAt' => time(),
        'version' => VERSION,
    );

    $json = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $filename = 'gojs-config-' . date('Ymd_His') . '.json';

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Length: ' . strlen($json));

    echo $json;
    exit;
}

function gojs_api_settings_reset() {
    global $config, $installed;

    session_unset();
    if (session_status() !== PHP_SESSION_NONE) {
        @session_destroy();
    }

    if (file_exists(CONFIG_FILE)) {
        @unlink(CONFIG_FILE);
    }

    $config = array();
    $installed = false;

    gojs_json_response(array('success' => true));
}

function gojs_is_protected_path($full_path) {
    $real_path = rtrim(str_replace('\\', '/', realpath($full_path) ?: $full_path), '/');
    $gojs_dir = rtrim(str_replace('\\', '/', CONFIG_DIR), '/');
    $index_file = str_replace('\\', '/', ROOT . '/api.php');
    $panel_root = rtrim(str_replace('\\', '/', realpath(ROOT) ?: ROOT), '/');

    if ($real_path === $gojs_dir || strpos($real_path, $gojs_dir . '/') === 0) {
        return true;
    }

    if ($real_path === $index_file) {
        return true;
    }

    if ($real_path === $panel_root || strpos($real_path, $panel_root . '/.htaccess') !== false) {
        return true;
    }
    if ($real_path === $panel_root . '/index.html' || $real_path === $panel_root . '/favicon.svg') {
        return false;
    }
    if (strpos($real_path, $panel_root . '/assets/') === 0) {
        return false;
    }
    if ($real_path !== $panel_root && strpos($real_path, $panel_root . '/') === 0) {
        $relative = substr($real_path, strlen($panel_root) + 1);
        if (strpos($relative, '.') === 0) {
            return true;
        }
        $protected_suffix = array('api.php', '.htaccess', '.user.ini', 'config.php');
        foreach ($protected_suffix as $sfx) {
            if ($relative === $sfx) {
                return true;
            }
        }
    }

    return false;
}

function gojs_ensure_not_protected($full_path, $action = '操作') {
    if (gojs_is_protected_path($full_path)) {
        gojs_json_response(null, array(
            'code' => 'protected_path',
            'message' => '该文件为 GOJS 系统文件，禁止' . $action,
        ), 403);
    }
}

function gojs_safe_path($relative_path) {
    global $root_path;

    $files_root = !empty($GLOBALS['files_root']) ? $GLOBALS['files_root'] : $root_path;

    $relative_path = ltrim($relative_path, '/');
    $relative_path = str_replace('\\', '/', $relative_path);

    $full_path = $files_root . '/' . $relative_path;

    $real_path = realpath($full_path);

    if (!$real_path) {
        $parent_dir = dirname($full_path);
        $real_parent = realpath($parent_dir);

        if (!$real_parent || strpos($real_parent, rtrim($files_root, '/')) !== 0) {
            return false;
        }

        $basename = basename($full_path);
        if (strpos($basename, '..') !== false) {
            return false;
        }

        return $real_parent . '/' . $basename;
    }

    $root_real = rtrim(realpath($files_root), '/');
    if (strpos(rtrim($real_path, '/'), $root_real) !== 0) {
        return false;
    }

    return $real_path;
}

function gojs_relative_path($abs_path) {
    global $root_path;

    $files_root = !empty($GLOBALS['files_root']) ? $GLOBALS['files_root'] : $root_path;

    $root_real = rtrim(realpath($files_root), '/');
    $abs_real = rtrim($abs_path, '/');

    if ($abs_real === $root_real) {
        return '/';
    }

    if (strpos($abs_real, $root_real) === 0) {
        return substr($abs_real, strlen($root_real));
    }

    return $abs_path;
}

function gojs_get_perms($file_path) {
    $perms = @fileperms($file_path);
    if ($perms === false) {
        return '0000';
    }
    return substr(sprintf('%o', $perms), -4);
}

function gojs_get_file_type($file_path) {
    if (is_link($file_path)) {
        return 'link';
    }
    if (is_dir($file_path)) {
        return 'dir';
    }
    return 'file';
}

function gojs_get_file_info($file_path, $relative_path) {
    $stat = @stat($file_path);

    return array(
        'name' => basename($file_path),
        'path' => $relative_path,
        'type' => gojs_get_file_type($file_path),
        'size' => $stat ? $stat['size'] : 0,
        'mtime' => $stat ? $stat['mtime'] : 0,
        'perms' => gojs_get_perms($file_path),
        'readable' => is_readable($file_path),
        'writable' => is_writable($file_path),
    );
}

function gojs_api_files() {
    global $root_path;

    $method = gojs_get_method();

    if ($method === 'GET') {
        $path = gojs_get_param('path', '/');
        $sort = gojs_get_param('sort', 'name');
        $order = gojs_get_param('order', 'asc');

        $safe_path = gojs_safe_path($path);
        if ($safe_path === false) {
            gojs_json_response(null, array(
                'code' => 'forbidden',
                'message' => '路径访问被拒绝',
            ), 403);
        }

        if (!is_dir($safe_path)) {
            gojs_json_response(null, array(
                'code' => 'not_directory',
                'message' => '路径不是目录',
            ), 400);
        }

        $entries = array();
        $dir_handle = @opendir($safe_path);
        if (!$dir_handle) {
            gojs_json_response(null, array(
                'code' => 'read_dir_failed',
                'message' => '读取目录失败',
            ), 500);
        }

        while (($entry = readdir($dir_handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full_path = $safe_path . '/' . $entry;

            if (gojs_is_protected_path($full_path)) {
                continue;
            }

            $rel = gojs_relative_path($full_path);
            $entries[] = gojs_get_file_info($full_path, $rel);
        }
        closedir($dir_handle);

        usort($entries, function($a, $b) use ($sort, $order) {

            $dir_compare = 0;
            if ($a['type'] === 'dir' && $b['type'] !== 'dir') {
                $dir_compare = -1;
            } elseif ($a['type'] !== 'dir' && $b['type'] === 'dir') {
                $dir_compare = 1;
            }

            if ($dir_compare !== 0) {
                return $dir_compare;
            }

            $cmp = 0;
            switch ($sort) {
                case 'size':
                    $cmp = $a['size'] - $b['size'];
                    break;
                case 'mtime':
                    $cmp = $a['mtime'] - $b['mtime'];
                    break;
                case 'name':
                default:
                    $cmp = strcasecmp($a['name'], $b['name']);
                    break;
            }

            return $order === 'desc' ? -$cmp : $cmp;
        });

        gojs_json_response(array(
            'files' => $entries,
            'path' => $path === '' ? '/' : $path,
        ));
    } elseif ($method === 'POST') {
        $action = gojs_get_param('action', '');

        switch ($action) {
            case 'create_file':
                gojs_api_file_touch();
                break;
            case 'create_dir':
                gojs_api_file_mkdir();
                break;
            case 'delete':
                gojs_api_file_delete();
                break;
            case 'rename':
                gojs_api_file_rename();
                break;
            case 'copy':
                gojs_api_file_copy();
                break;
            case 'chmod':
                gojs_api_file_chmod();
                break;
            default:
                gojs_json_response(null, array(
                    'code' => 'invalid_action',
                    'message' => '无效的操作',
                ), 400);
                break;
        }
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}

function gojs_api_file_content() {
    $method = gojs_get_method();

    if ($method === 'GET') {
        $path = gojs_get_param('path', '');

        $safe_path = gojs_safe_path($path);
        if ($safe_path === false) {
            gojs_json_response(null, array(
                'code' => 'forbidden',
                'message' => '路径访问被拒绝',
            ), 403);
        }

        gojs_ensure_not_protected($safe_path, '读取');

        if (!is_file($safe_path)) {
            gojs_json_response(null, array(
                'code' => 'not_file',
                'message' => '路径不是文件',
            ), 400);
        }

        if (!is_readable($safe_path)) {
            gojs_json_response(null, array(
                'code' => 'not_readable',
                'message' => '文件不可读',
            ), 403);
        }

        $size = filesize($safe_path);
        $max_size = 1024 * 1024; 
        $truncated = false;
        $read_size = $size;

        if ($size > $max_size) {
            $read_size = $max_size;
            $truncated = true;
        }

        $content = @file_get_contents($safe_path, false, null, 0, $read_size);
        if ($content === false) {
            gojs_json_response(null, array(
                'code' => 'read_failed',
                'message' => '读取文件失败',
            ), 500);
        }

        $mime = 'application/octet-stream';
        $type = 'binary';

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $safe_path);
            }
        }

        $is_text = false;
        $is_image = false;

        if ($mime) {
            if (strpos($mime, 'text/') === 0 || $mime === 'application/json' || $mime === 'application/javascript' || $mime === 'application/xml' || $mime === 'application/x-httpd-php') {
                $is_text = true;
            }
            if (strpos($mime, 'image/') === 0) {
                $is_image = true;
            }
        }

        $ext = strtolower(pathinfo($safe_path, PATHINFO_EXTENSION));
        $text_exts = array('txt', 'md', 'html', 'htm', 'css', 'js', 'json', 'xml', 'php', 'py', 'rb', 'java', 'c', 'cpp', 'h', 'sh', 'yml', 'yaml', 'ini', 'conf', 'log', 'csv', 'sql', 'ts', 'tsx', 'jsx', 'vue', 'less', 'scss', 'sass');
        $image_exts = array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico');

        if (!$is_text && in_array($ext, $text_exts)) {
            $is_text = true;
            $mime = 'text/plain';
        }
        if (!$is_image && in_array($ext, $image_exts)) {
            $is_image = true;
        }

        if ($is_text) {
            $type = 'text';
            $encoding = mb_detect_encoding($content, mb_detect_order(), true);
            $lines = $truncated ? null : substr_count($content, "\n") + 1;

            gojs_json_response(array(
                'type' => 'text',
                'content' => $content,
                'size' => $size,
                'mime' => $mime,
                'encoding' => $encoding,
                'lines' => $lines,
                'truncated' => $truncated,
            ));
        } elseif ($is_image) {
            $type = 'image';
            gojs_json_response(array(
                'type' => 'image',
                'content' => base64_encode($content),
                'size' => $size,
                'mime' => $mime,
                'encoding' => 'base64',
                'truncated' => $truncated,
            ));
        } else {
            gojs_json_response(array(
                'type' => 'binary',
                'content' => base64_encode($content),
                'size' => $size,
                'mime' => $mime,
                'encoding' => 'base64',
                'truncated' => $truncated,
            ));
        }
    } elseif ($method === 'PUT') {
        gojs_api_file_save();
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}

function gojs_api_file_save() {
    $path = gojs_get_param('path', '');
    $content = gojs_get_param('content', '');

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '修改');

    if (file_exists($safe_path) && !is_file($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_file',
            'message' => '路径不是文件',
        ), 400);
    }

    if (file_exists($safe_path) && !is_writable($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_writable',
            'message' => '文件不可写',
        ), 403);
    }

    $result = @file_put_contents($safe_path, $content, LOCK_EX);
    if ($result === false) {
        gojs_json_response(null, array(
            'code' => 'write_failed',
            'message' => '写入文件失败',
        ), 500);
    }

    gojs_json_response(array('success' => true));
}

function gojs_api_file_mkdir() {
    $path = gojs_get_param('path', '');

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '创建');

    if (file_exists($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'already_exists',
            'message' => '路径已存在',
        ), 400);
    }

    if (!@mkdir($safe_path, 0755, true)) {
        gojs_json_response(null, array(
            'code' => 'mkdir_failed',
            'message' => '创建目录失败',
        ), 500);
    }

    $rel = gojs_relative_path($safe_path);
    $info = gojs_get_file_info($safe_path, $rel);
    gojs_json_response($info);
}

function gojs_api_file_touch() {
    $path = gojs_get_param('path', '');

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '创建');

    if (file_exists($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'already_exists',
            'message' => '文件已存在',
        ), 400);
    }

    if (@file_put_contents($safe_path, '') === false) {
        gojs_json_response(null, array(
            'code' => 'create_failed',
            'message' => '创建文件失败',
        ), 500);
    }

    $rel = gojs_relative_path($safe_path);
    $info = gojs_get_file_info($safe_path, $rel);
    gojs_json_response($info);
}

function gojs_recursive_delete($dir) {
    if (!is_dir($dir)) {
        return @unlink($dir);
    }

    $handle = @opendir($dir);
    if (!$handle) {
        return false;
    }

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            if (!gojs_recursive_delete($path)) {
                closedir($handle);
                return false;
            }
        } else {
            if (!@unlink($path)) {
                closedir($handle);
                return false;
            }
        }
    }
    closedir($handle);

    return @rmdir($dir);
}

function gojs_api_file_delete() {
    $path = gojs_get_param('path', '');
    $recursive = gojs_get_param('recursive', false);

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '删除');

    if (!file_exists($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '路径不存在',
        ), 404);
    }

    global $root_path;
    $root_real = rtrim(realpath($root_path), '/');
    if (rtrim($safe_path, '/') === $root_real) {
        gojs_json_response(null, array(
            'code' => 'cannot_delete_root',
            'message' => '不能删除根目录',
        ), 400);
    }

    if (is_dir($safe_path) && !is_link($safe_path)) {
        if (!$recursive) {

            $handle = @opendir($safe_path);
            $empty = true;
            if ($handle) {
                while (($entry = readdir($handle)) !== false) {
                    if ($entry !== '.' && $entry !== '..') {
                        $empty = false;
                        break;
                    }
                }
                closedir($handle);
            }
            if (!$empty) {
                gojs_json_response(null, array(
                    'code' => 'dir_not_empty',
                    'message' => '目录不为空，请使用递归删除',
                ), 400);
            }
        }

        if (!gojs_recursive_delete($safe_path)) {
            gojs_json_response(null, array(
                'code' => 'delete_failed',
                'message' => '删除失败',
            ), 500);
        }
    } else {
        if (!@unlink($safe_path)) {
            gojs_json_response(null, array(
                'code' => 'delete_failed',
                'message' => '删除失败',
            ), 500);
        }
    }

    gojs_json_response(array('success' => true));
}

function gojs_api_file_rename() {
    global $root_path;

    $path = gojs_get_param('path', '');
    $target = gojs_get_param('target', '');

    if (!$target) {
        gojs_json_response(null, array(
            'code' => 'invalid_target',
            'message' => '目标名称不能为空',
        ), 400);
    }

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '重命名');

    $target_has_sep = (strpos($target, '/') !== false || strpos($target, '\\') !== false);
    if ($target_has_sep) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '暂不支持跨目录重命名，请使用同目录内名称',
        ), 403);
    }

    if (strpos($target, '..') !== false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标名称包含非法字符',
        ), 403);
    }

    $files_root = !empty($GLOBALS['files_root']) ? $GLOBALS['files_root'] : $root_path;
    $root_real = rtrim(realpath($files_root) ?: $files_root, '/');

    $parent_dir = dirname($safe_path);
    $safe_target = $parent_dir . '/' . basename($target);

    $real_parent = realpath($parent_dir);
    if (!$real_parent || strpos($real_parent, $root_real) !== 0) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标路径访问被拒绝',
        ), 403);
    }

    $target_base = basename($safe_target);
    $safe_target_final = $real_parent . '/' . $target_base;

    gojs_ensure_not_protected($safe_target_final, '重命名到');

    if (file_exists($safe_target_final)) {
        gojs_json_response(null, array(
            'code' => 'already_exists',
            'message' => '目标路径已存在',
        ), 400);
    }

    if (!@rename($safe_path, $safe_target_final)) {
        gojs_json_response(null, array(
            'code' => 'rename_failed',
            'message' => '重命名失败',
        ), 500);
    }

    $rel = gojs_relative_path($safe_target_final);
    $info = gojs_get_file_info($safe_target_final, $rel);
    gojs_json_response($info);
}

function gojs_recursive_copy($src, $dst) {
    if (is_file($src)) {
        return @copy($src, $dst);
    }

    if (!is_dir($dst)) {
        if (!@mkdir($dst, 0755, true)) {
            return false;
        }
    }

    $handle = @opendir($src);
    if (!$handle) {
        return false;
    }

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $src_path = $src . '/' . $entry;
        $dst_path = $dst . '/' . $entry;

        if (is_dir($src_path) && !is_link($src_path)) {
            if (!gojs_recursive_copy($src_path, $dst_path)) {
                closedir($handle);
                return false;
            }
        } else {
            if (!@copy($src_path, $dst_path)) {
                closedir($handle);
                return false;
            }
        }
    }
    closedir($handle);

    return true;
}

function gojs_api_file_copy() {
    $path = gojs_get_param('path', '');
    $target = gojs_get_param('target', '');

    if (!$target) {
        gojs_json_response(null, array(
            'code' => 'invalid_target',
            'message' => '目标路径不能为空',
        ), 400);
    }

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '复制');

    $safe_target = gojs_safe_path($target);
    if ($safe_target === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_target, '复制到');

    if (file_exists($safe_target)) {
        gojs_json_response(null, array(
            'code' => 'already_exists',
            'message' => '目标路径已存在',
        ), 400);
    }

    if (!gojs_recursive_copy($safe_path, $safe_target)) {
        gojs_json_response(null, array(
            'code' => 'copy_failed',
            'message' => '复制失败',
        ), 500);
    }

    gojs_json_response(array('success' => true));
}

function gojs_api_file_zip() {
    if (!class_exists('ZipArchive')) {
        gojs_json_response(null, array(
            'code' => 'not_supported',
            'message' => '服务器不支持 Zip 扩展',
        ), 400);
    }

    $paths = gojs_get_param('paths', array());
    $target = gojs_get_param('target', '');

    if (empty($paths)) {
        gojs_json_response(null, array(
            'code' => 'invalid_paths',
            'message' => '请选择要压缩的文件',
        ), 400);
    }

    $safe_target = gojs_safe_path($target);
    if ($safe_target === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_target, '压缩到');

    if (file_exists($safe_target)) {
        gojs_json_response(null, array(
            'code' => 'already_exists',
            'message' => '目标文件已存在',
        ), 400);
    }

    $zip = new ZipArchive();
    if ($zip->open($safe_target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        gojs_json_response(null, array(
            'code' => 'zip_create_failed',
            'message' => '创建压缩包失败',
        ), 500);
    }

    foreach ($paths as $path) {
        $safe_path = gojs_safe_path($path);
        if ($safe_path === false) {
            continue;
        }
        if (gojs_is_protected_path($safe_path)) {
            continue;
        }
        if (!file_exists($safe_path)) {
            continue;
        }
        $base_name = basename($safe_path);
        if (is_dir($safe_path)) {
            gojs_add_dir_to_zip($zip, $safe_path, $base_name);
        } else {
            $zip->addFile($safe_path, $base_name);
        }
    }

    $zip->close();

    gojs_json_response(array('success' => true, 'target' => $target));
}

function gojs_add_dir_to_zip($zip, $dir, $zip_path) {
    $dir = rtrim($dir, '/') . '/';
    $zip_path = rtrim($zip_path, '/') . '/';
    $handle = opendir($dir);
    if (!$handle) return;
    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $dir . $entry;
        $zpath = $zip_path . $entry;
        if (gojs_is_protected_path($full)) continue;
        if (is_dir($full)) {
            gojs_add_dir_to_zip($zip, $full, $zpath);
        } else {
            $zip->addFile($full, $zpath);
        }
    }
    closedir($handle);
}

function gojs_api_file_unzip() {
    if (!class_exists('ZipArchive')) {
        gojs_json_response(null, array(
            'code' => 'not_supported',
            'message' => '服务器不支持 Zip 扩展',
        ), 400);
    }

    $path = gojs_get_param('path', '');
    $target = gojs_get_param('target', '');

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '解压');

    if (!is_file($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_file',
            'message' => '不是文件',
        ), 400);
    }

    $safe_target = gojs_safe_path($target);
    if ($safe_target === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_target, '解压到');

    if (!is_dir($safe_target)) {
        if (!@mkdir($safe_target, 0755, true)) {
            gojs_json_response(null, array(
                'code' => 'create_dir_failed',
                'message' => '创建目标目录失败',
            ), 500);
        }
    }

    $zip = new ZipArchive();
    if ($zip->open($safe_path) !== true) {
        gojs_json_response(null, array(
            'code' => 'zip_open_failed',
            'message' => '打开压缩包失败',
        ), 500);
    }

    $count = $zip->numFiles;
    $extracted = 0;

    for ($i = 0; $i < $count; $i++) {
        $name = $zip->getNameIndex($i);
        $full_path = $safe_target . '/' . $name;
        $real_target = realpath($safe_target);
        $real_full = realpath(dirname($full_path));
        if ($real_target === false || $real_full === false) continue;
        if (strpos($real_full, rtrim($real_target, '/')) !== 0) continue;
        if (gojs_is_protected_path($full_path)) continue;
        if ($zip->extractTo($safe_target, array($name))) {
            $extracted++;
        }
    }

    $zip->close();

    gojs_json_response(array('success' => true, 'extracted' => $extracted));
}

function gojs_api_file_targz() {
    if (!class_exists('PharData')) {
        gojs_json_response(null, array(
            'code' => 'not_supported',
            'message' => '服务器不支持 PharData 扩展',
        ), 404);
    }

    
if (ini_get('phar.readonly')) {
        gojs_json_response(null, array(
            'code' => 'phar_readonly',
            'message' => '服务器 phar.readonly 已启用，无法创建 tar.gz 压缩包。请在 php.ini 或 .htaccess 中设置 phar.readonly=0',
        ), 500);
    }

    $paths = gojs_get_param('paths', array());
    $target = gojs_get_param('target', '');

    if (empty($paths)) {
        gojs_json_response(null, array(
            'code' => 'invalid_paths',
            'message' => '请选择要压缩的文件',
        ), 400);
    }

    $safe_target = gojs_safe_path($target);
    if ($safe_target === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_target, '压缩到');

    if (file_exists($safe_target)) {
        gojs_json_response(null, array(
            'code' => 'already_exists',
            'message' => '目标文件已存在',
        ), 400);
    }

    

if (preg_match('/\.tar\.gz$/i', $safe_target)) {
        $tar_target = substr($safe_target, 0, -7) . '.tar';
    } elseif (preg_match('/\.tgz$/i', $safe_target)) {
        $tar_target = substr($safe_target, 0, -4) . '.tar';
    } else {
        $tar_target = $safe_target . '.tar';
    }

    if (file_exists($tar_target)) {
        gojs_json_response(null, array(
            'code' => 'already_exists',
            'message' => '临时 tar 文件已存在',
        ), 400);
    }

    try {
        $phar = new PharData($tar_target);
    } catch (Exception $e) {
        gojs_json_response(null, array(
            'code' => 'targz_create_failed',
            'message' => '创建 tar 失败: ' . $e->getMessage(),
        ), 500);
    }

    foreach ($paths as $path) {
        $safe_path = gojs_safe_path($path);
        if ($safe_path === false) {
            continue;
        }
        if (gojs_is_protected_path($safe_path)) {
            continue;
        }
        if (!file_exists($safe_path)) {
            continue;
        }
        $base_name = basename($safe_path);
        if (is_dir($safe_path)) {
            gojs_add_dir_to_tar($phar, $safe_path, $base_name);
        } else {
            $phar->addFile($safe_path, $base_name);
        }
    }

    
try {
        $phar->compress(Phar::GZ);
    } catch (Exception $e) {
        unset($phar);
        if (file_exists($tar_target)) {
            @unlink($tar_target);
        }
        gojs_json_response(null, array(
            'code' => 'targz_compress_failed',
            'message' => '压缩为 tar.gz 失败: ' . $e->getMessage(),
        ), 500);
    }

    

unset($phar);
    if (file_exists($tar_target)) {
        @unlink($tar_target);
    }

    gojs_json_response(array('success' => true, 'target' => $target));
}

function gojs_add_dir_to_tar($phar, $dir, $tar_path) {
    $dir = rtrim($dir, '/') . '/';
    $tar_path = rtrim($tar_path, '/') . '/';
    $handle = opendir($dir);
    if (!$handle) return;
    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $dir . $entry;
        $tpath = $tar_path . $entry;
        if (gojs_is_protected_path($full)) continue;
        if (is_dir($full)) {
            $phar->addEmptyDir($tpath);
            gojs_add_dir_to_tar($phar, $full, $tpath);
        } else {
            $phar->addFile($full, $tpath);
        }
    }
    closedir($handle);
}

function gojs_api_file_untargz() {
    if (!class_exists('PharData')) {
        gojs_json_response(null, array(
            'code' => 'not_supported',
            'message' => '服务器不支持 PharData 扩展',
        ), 404);
    }

    $path = gojs_get_param('path', '');
    $target = gojs_get_param('target', '');

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '解压');

    if (!is_file($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_file',
            'message' => '不是文件',
        ), 400);
    }

    $safe_target = gojs_safe_path($target);
    if ($safe_target === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_target, '解压到');

    if (!is_dir($safe_target)) {
        if (!@mkdir($safe_target, 0755, true)) {
            gojs_json_response(null, array(
                'code' => 'create_dir_failed',
                'message' => '创建目标目录失败',
            ), 500);
        }
    }

    $count = 0;
    $extracted = 0;
    try {
        $phar = new PharData($safe_path);
        $count = $phar->count();

        $base_phar_path = str_replace('\\', '/', realpath($safe_path));
        $prefix = 'phar://' . $base_phar_path . '/';

        foreach (new RecursiveIteratorIterator($phar) as $file) {
            $full_name = str_replace('\\', '/', $file->getPathname());
            if (strpos($full_name, $prefix) !== 0) {
                continue;
            }
            $name = substr($full_name, strlen($prefix));
            if ($name === '' || substr($name, -1) === '/') {
                continue;
            }

            $full_path = $safe_target . '/' . $name;
            $real_target = realpath($safe_target);
            $real_full = realpath(dirname($full_path));
            if ($real_target === false || $real_full === false) continue;
            if (strpos($real_full, rtrim($real_target, '/')) !== 0) continue;
            if (gojs_is_protected_path($full_path)) continue;

            if ($phar->extractTo($safe_target, $name, true)) {
                $extracted++;
            }
        }
    } catch (Exception $e) {
        gojs_json_response(null, array(
            'code' => 'untargz_failed',
            'message' => '解压 tar.gz 失败: ' . $e->getMessage(),
        ), 500);
    }

    gojs_json_response(array('success' => true, 'extracted' => $extracted));
}

function gojs_api_file_chmod() {
    $path = gojs_get_param('path', '');
    $perms = gojs_get_param('perms', '');

    if (!$perms) {
        gojs_json_response(null, array(
            'code' => 'invalid_perms',
            'message' => '权限值不能为空',
        ), 400);
    }

    $mode = octdec($perms);
    if ($mode <= 0 || $mode > 07777) {
        gojs_json_response(null, array(
            'code' => 'invalid_perms',
            'message' => '权限值无效',
        ), 400);
    }

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '修改权限');

    if (!file_exists($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '路径不存在',
        ), 404);
    }

    if (!@chmod($safe_path, $mode)) {
        gojs_json_response(null, array(
            'code' => 'chmod_failed',
            'message' => '修改权限失败',
        ), 500);
    }

    gojs_json_response(array('success' => true));
}

function gojs_upload_error_message($error_code) {
    $messages = array(
        UPLOAD_ERR_INI_SIZE   => '文件过大（超过 php.ini 的 upload_max_filesize 限制）',
        UPLOAD_ERR_FORM_SIZE  => '文件过大（超过表单限制）',
        UPLOAD_ERR_PARTIAL    => '文件仅部分上传',
        UPLOAD_ERR_NO_FILE    => '没有文件被上传',
        UPLOAD_ERR_NO_TMP_DIR => '缺少临时目录',
        UPLOAD_ERR_CANT_WRITE => '写入磁盘失败',
        UPLOAD_ERR_EXTENSION  => 'PHP 扩展阻止了上传',
    );
    return isset($messages[$error_code]) ? $messages[$error_code] : '上传失败（未知错误）';
}

function gojs_normalize_files_array($files) {
    $result = array();
    if (!isset($files['name'])) {
        return $result;
    }

    if (is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($files['name'][$i] === '') {
                continue;
            }
            $result[] = array(
                'name'     => $files['name'][$i],
                'type'     => isset($files['type'][$i]) ? $files['type'][$i] : '',
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            );
        }
    } else {
        if ($files['name'] === '') {
            return $result;
        }
        $result[] = array(
            'name'     => $files['name'],
            'type'     => isset($files['type']) ? $files['type'] : '',
            'tmp_name' => $files['tmp_name'],
            'error'    => $files['error'],
            'size'     => $files['size'],
        );
    }

    return $result;
}

function gojs_unique_path($path) {
    if (!file_exists($path)) {
        return $path;
    }

    $dir = dirname($path);
    $basename = basename($path);
    $dot = strrpos($basename, '.');
    if ($dot === false) {
        $name = $basename;
        $ext = '';
    } else {
        $name = substr($basename, 0, $dot);
        $ext = substr($basename, $dot);
    }

    $counter = 1;
    while (file_exists($dir . '/' . $name . ' (' . $counter . ')' . $ext)) {
        $counter++;
    }

    return $dir . '/' . $name . ' (' . $counter . ')' . $ext;
}

function gojs_is_dangerous_filename($name) {
    $blacklist = array('php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'php7', 'phps', 'php-s', 'phpt', 'inc');
    $parts = explode('.', $name);
    foreach ($parts as $part) {
        $ext = strtolower($part);
        if (in_array($ext, $blacklist, true)) {
            return true;
        }
    }
    return false;
}

function gojs_validate_upload_filename($name) {
    if ($name === '' || $name === '.' || $name === '..') {
        return false;
    }
    if (strpos($name, '/') !== false || strpos($name, '\\') !== false) {
        return false;
    }
    $len = strlen($name);
    for ($i = 0; $i < $len; $i++) {
        $ascii = ord($name[$i]);
        if ($ascii <= 31) {
            return false;
        }
    }
    if (strpos($name, ':') !== false || strpos($name, '<') !== false || strpos($name, '>') !== false) {
        return false;
    }
    if (strpos($name, '|') !== false || strpos($name, '?') !== false || strpos($name, '*') !== false) {
        return false;
    }
    if (strpos($name, '"') !== false || strpos($name, "'") !== false || strpos($name, '`') !== false) {
        return false;
    }
    if (gojs_is_dangerous_filename($name)) {
        return false;
    }
    return true;
}

function gojs_detect_php_magic($file_path, $filename) {
    $safe_exts = array('txt', 'sql', 'md', 'html');
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, $safe_exts, true)) {
        return false;
    }
    $header = @file_get_contents($file_path, false, null, 0, 4096);
    if ($header === false || $header === '') {
        return false;
    }
    $patterns = array(
        '/^\s*<\?php/i',
        '/^\s*<%/i',
        '/^\s*<script\s/i',
        '/^\s*<script>/i',
    );
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $header)) {
            return true;
        }
    }
    return false;
}

function gojs_api_upload() {
    $target = isset($_POST['target']) ? $_POST['target'] : '/';
    if ($target === '') {
        $target = '/';
    }

    $safe_target = gojs_safe_path($target);
    if ($safe_target === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标路径访问被拒绝',
        ), 403);
    }

    if (!is_dir($safe_target)) {
        gojs_json_response(null, array(
            'code' => 'not_directory',
            'message' => '目标路径不是目录',
        ), 400);
    }

    gojs_ensure_not_protected($safe_target, '上传到');

    if (!is_writable($safe_target)) {
        gojs_json_response(null, array(
            'code' => 'not_writable',
            'message' => '目标目录不可写',
        ), 403);
    }

    $files = array();
    if (isset($_FILES['files'])) {
        $files = gojs_normalize_files_array($_FILES['files']);
    } elseif (isset($_FILES['file'])) {
        $files = gojs_normalize_files_array($_FILES['file']);
    }

    if (empty($files)) {
        gojs_json_response(null, array(
            'code' => 'no_files',
            'message' => '没有上传文件',
        ), 400);
    }

    $capabilities = gojs_get_capabilities();
    $max_upload = $capabilities['maxUpload'];
    $disk_free = @disk_free_space($safe_target);

    $results = array();
    $errors = array();

    foreach ($files as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = array(
                'name' => $file['name'],
                'error' => gojs_upload_error_message($file['error']),
            );
            continue;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = array(
                'name' => $file['name'],
                'error' => '无效的上传文件',
            );
            continue;
        }

        $basename = basename($file['name']);
        if (!gojs_validate_upload_filename($basename)) {
            $errors[] = array(
                'name' => $file['name'],
                'error' => '文件名无效',
            );
            continue;
        }

        if ($max_upload > 0 && $file['size'] > $max_upload) {
            $errors[] = array(
                'name' => $file['name'],
                'error' => '文件过大（超过 upload_max_filesize 限制）',
            );
            continue;
        }

        if ($disk_free !== false && $file['size'] > $disk_free) {
            $errors[] = array(
                'name' => $file['name'],
                'error' => '磁盘空间不足',
            );
            continue;
        }

        $final_path = $safe_target . '/' . $basename;

        gojs_ensure_not_protected($final_path, '上传到');

        if (file_exists($final_path)) {
            $final_path = gojs_unique_path($final_path);
        }

        if (!move_uploaded_file($file['tmp_name'], $final_path)) {
            $errors[] = array(
                'name' => $file['name'],
                'error' => '移动文件失败',
            );
            continue;
        }

        if (gojs_detect_php_magic($final_path, $basename)) {
            @unlink($final_path);
            $errors[] = array(
                'name' => $file['name'],
                'error' => '文件内容疑似脚本伪装，已拒绝',
            );
            continue;
        }

        @chmod($final_path, 0644);

        $results[] = array(
            'name' => basename($final_path),
            'size' => @filesize($final_path),
        );
    }

    if (empty($results) && !empty($errors)) {
        gojs_json_response(null, array(
            'code' => 'upload_failed',
            'message' => $errors[0]['error'],
            'errors' => $errors,
        ), 400);
    }

    gojs_json_response(array(
        'success' => true,
        'files' => $results,
        'errors' => $errors,
    ));
}

function gojs_api_upload_chunk() {
    $body = gojs_get_body();

    $chunk = isset($body['chunk']) ? $body['chunk'] : '';
    $chunk_index = isset($body['chunkIndex']) ? (int)$body['chunkIndex'] : -1;
    $total_chunks = isset($body['totalChunks']) ? (int)$body['totalChunks'] : 0;
    $file_name = isset($body['fileName']) ? (string)$body['fileName'] : '';
    $target = isset($body['target']) ? (string)$body['target'] : '/';
    $upload_id = isset($body['uploadId']) ? (string)$body['uploadId'] : '';

    if ($target === '') {
        $target = '/';
    }

    if ($chunk === '' || $chunk_index < 0 || $total_chunks <= 0 || $file_name === '' || $upload_id === '') {
        gojs_json_response(null, array(
            'code' => 'invalid_params',
            'message' => '上传参数无效',
        ), 400);
    }

    if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $upload_id)) {
        gojs_json_response(null, array(
            'code' => 'invalid_upload_id',
            'message' => '上传 ID 无效',
        ), 400);
    }

    if (!gojs_validate_upload_filename($file_name)) {
        gojs_json_response(null, array(
            'code' => 'invalid_filename',
            'message' => '文件名无效',
        ), 400);
    }

    if ($chunk_index >= $total_chunks) {
        gojs_json_response(null, array(
            'code' => 'invalid_chunk_index',
            'message' => '分片索引越界',
        ), 400);
    }

    $chunk_data = base64_decode($chunk, true);
    if ($chunk_data === false) {
        gojs_json_response(null, array(
            'code' => 'invalid_chunk',
            'message' => '分片数据解码失败',
        ), 400);
    }

    $safe_target = gojs_safe_path($target);
    if ($safe_target === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标路径访问被拒绝',
        ), 403);
    }

    if (!is_dir($safe_target)) {
        gojs_json_response(null, array(
            'code' => 'not_directory',
            'message' => '目标路径不是目录',
        ), 400);
    }

    gojs_ensure_not_protected($safe_target, '上传到');

    if (!is_writable($safe_target)) {
        gojs_json_response(null, array(
            'code' => 'not_writable',
            'message' => '目标目录不可写',
        ), 403);
    }

    $tmp_base = CONFIG_DIR . '/tmp';
    if (!is_dir($tmp_base)) {
        if (!@mkdir($tmp_base, 0700, true)) {
            gojs_json_response(null, array(
                'code' => 'create_tmp_dir_failed',
                'message' => '创建临时目录失败',
            ), 500);
        }
    }

    $tmp_dir = $tmp_base . '/' . $upload_id;
    if (!is_dir($tmp_dir)) {
        if (!@mkdir($tmp_dir, 0700, true)) {
            gojs_json_response(null, array(
                'code' => 'create_tmp_dir_failed',
                'message' => '创建临时目录失败',
            ), 500);
        }
    }

    $chunk_file = $tmp_dir . '/chunk_' . sprintf('%08d', $chunk_index);
    if (@file_put_contents($chunk_file, $chunk_data, LOCK_EX) === false) {
        gojs_json_response(null, array(
            'code' => 'write_chunk_failed',
            'message' => '写入分片失败',
        ), 500);
    }

    $received = 0;
    for ($i = 0; $i < $total_chunks; $i++) {
        if (is_file($tmp_dir . '/chunk_' . sprintf('%08d', $i))) {
            $received++;
        }
    }

    $merged = false;
    $final_name = basename($file_name);

    if ($received === $total_chunks) {
        $final_path = $safe_target . '/' . $final_name;
        gojs_ensure_not_protected($final_path, '上传到');

        if (file_exists($final_path)) {
            $final_path = gojs_unique_path($final_path);
        }

        $total_size = 0;
        for ($i = 0; $i < $total_chunks; $i++) {
            $fsize = @filesize($tmp_dir . '/chunk_' . sprintf('%08d', $i));
            $total_size += $fsize ? $fsize : 0;
        }

        $disk_free = @disk_free_space($safe_target);
        if ($disk_free !== false && $total_size > $disk_free) {
            gojs_recursive_delete($tmp_dir);
            gojs_json_response(null, array(
                'code' => 'disk_full',
                'message' => '磁盘空间不足',
            ), 500);
        }

        $out = @fopen($final_path, 'wb');
        if (!$out) {
            gojs_recursive_delete($tmp_dir);
            gojs_json_response(null, array(
                'code' => 'merge_failed',
                'message' => '合并文件失败',
            ), 500);
        }

        $merge_ok = true;
        for ($i = 0; $i < $total_chunks; $i++) {
            $in = @fopen($tmp_dir . '/chunk_' . sprintf('%08d', $i), 'rb');
            if (!$in) {
                $merge_ok = false;
                break;
            }
            while (!feof($in)) {
                $buf = fread($in, 65536);
                if ($buf === false) {
                    break;
                }
                if (fwrite($out, $buf) === false) {
                    $merge_ok = false;
                    break;
                }
            }
            fclose($in);
            if (!$merge_ok) {
                break;
            }
        }
        fclose($out);

        if (!$merge_ok) {
            @unlink($final_path);
            gojs_recursive_delete($tmp_dir);
            gojs_json_response(null, array(
                'code' => 'merge_failed',
                'message' => '合并文件失败',
            ), 500);
        }

        if (gojs_detect_php_magic($final_path, $final_name)) {
            @unlink($final_path);
            gojs_recursive_delete($tmp_dir);
            gojs_json_response(null, array(
                'code' => 'php_magic_detected',
                'message' => '文件内容疑似脚本伪装，已拒绝',
            ), 400);
        }

        @chmod($final_path, 0644);
        gojs_recursive_delete($tmp_dir);

        $merged = true;
    }

    gojs_json_response(array(
        'success' => true,
        'merged' => $merged,
        'progress' => $received . '/' . $total_chunks,
        'received' => $received,
        'totalChunks' => $total_chunks,
    ));
}

function gojs_api_file_search() {
    $path = gojs_get_param('path', '/');
    $q = gojs_get_param('q', '');

    if (!$q) {
        gojs_json_response(array(
            'files' => array(),
            'total' => 0,
        ));
        return;
    }

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    if (!is_dir($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_directory',
            'message' => '路径不是目录',
        ), 400);
    }

    $results = array();
    $max_results = 100;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($safe_path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if (count($results) >= $max_results) {
            break;
        }

        $name = $file->getFilename();
        if (stripos($name, $q) !== false) {
            $full_path = $file->getPathname();

            if (gojs_is_protected_path($full_path)) {
                continue;
            }

            $rel = gojs_relative_path($full_path);
            $results[] = gojs_get_file_info($full_path, $rel);
        }
    }

    gojs_json_response(array(
        'files' => $results,
        'total' => count($results),
    ));
}

function gojs_api_download() {
    $path = gojs_get_param('path', '');

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '下载');

    if (!is_file($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_file',
            'message' => '路径不是文件',
        ), 400);
    }

    if (!is_readable($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_readable',
            'message' => '文件不可读',
        ), 403);
    }

    $filename = basename($safe_path);
    $size = filesize($safe_path);

    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $safe_path);
        }
    }

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $size);
    header('Accept-Ranges: bytes');

    readfile($safe_path);
    exit;
}

function gojs_count_files($dir, $max_depth = 5, $depth = 0) {
    $count = 0;
    $size = 0;

    if ($depth >= $max_depth) {
        return array($count, $size);
    }

    $handle = @opendir($dir);
    if (!$handle) {
        return array($count, $size);
    }

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;

        if (is_dir($path) && !is_link($path)) {
            list($sub_count, $sub_size) = gojs_count_files($path, $max_depth, $depth + 1);
            $count += $sub_count;
            $size += $sub_size;
        } else {
            $count++;
            $size += @filesize($path);
        }
    }
    closedir($handle);

    return array($count, $size);
}

function gojs_find_recent_files($dir, $limit = 5, $max_depth = 5, $depth = 0) {
    $files = array();

    if ($depth >= $max_depth) {
        return $files;
    }

    $handle = @opendir($dir);
    if (!$handle) {
        return $files;
    }

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;

        if (is_dir($path) && !is_link($path)) {
            $sub_files = gojs_find_recent_files($path, $limit, $max_depth, $depth + 1);
            $files = array_merge($files, $sub_files);
        } else {
            $mtime = @filemtime($path);
            $files[] = array(
                'path' => $path,
                'mtime' => $mtime,
            );
        }
    }
    closedir($handle);

    usort($files, function($a, $b) {
        return $b['mtime'] - $a['mtime'];
    });

    return array_slice($files, 0, $limit);
}

function gojs_api_dashboard() {
    global $root_path;

    $capabilities = gojs_get_capabilities();

    $disk_total = @disk_total_space($root_path);
    $disk_free = @disk_free_space($root_path);
    $disk_used = ($disk_total && $disk_free) ? ($disk_total - $disk_free) : 0;

    list($file_count, $total_size) = gojs_count_files($root_path, 5);

    $recent_raw = gojs_find_recent_files($root_path, 5, 5);
    $recent_files = array();
    foreach ($recent_raw as $item) {
        $rel = gojs_relative_path($item['path']);
        $recent_files[] = gojs_get_file_info($item['path'], $rel);
    }

    $hostname = function_exists('gethostname') ? @gethostname() : 'unknown';

    $data = array(
        'phpVersion' => $capabilities['phpVersion'],
        'sapi' => $capabilities['sapi'],
        'webServer' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown',
        'hostname' => $hostname ? $hostname : 'unknown',
        'timezone' => date_default_timezone_get(),
        'now' => time(),
        'diskTotal' => $disk_total ? $disk_total : 0,
        'diskFree' => $disk_free ? $disk_free : 0,
        'diskUsed' => $disk_used,
        'rootPath' => '/',
        'fileCount' => $file_count,
        'totalSize' => $total_size,
        'maxUpload' => $capabilities['maxUpload'],
        'maxPost' => $capabilities['maxPost'],
        'memoryLimit' => $capabilities['memoryLimit'],
        'recentFiles' => $recent_files,
    );

    gojs_json_response($data);
}

function gojs_api_phpinfo() {
    $core_ini_keys = array(
        'memory_limit',
        'upload_max_filesize',
        'post_max_size',
        'max_execution_time',
        'display_errors',
        'error_reporting',
        'date.timezone',
        'file_uploads',
        'max_file_uploads',
        'open_basedir',
        'allow_url_fopen',
        'session.gc_maxlifetime',
        'session.cookie_httponly',
        'session.cookie_secure',
    );

    $core_ini = array();
    foreach ($core_ini_keys as $key) {
        $core_ini[$key] = (string)ini_get($key);
    }

    $env_keys = array('PATH', 'HOME', 'USER', 'LANG');
    $env = array();
    foreach ($env_keys as $key) {
        if (isset($_ENV[$key])) {
            $env[$key] = $_ENV[$key];
        }
    }

    $server_keys = array(
        'SERVER_SOFTWARE',
        'SERVER_NAME',
        'SERVER_ADDR',
        'SERVER_PORT',
        'DOCUMENT_ROOT',
        'HTTP_HOST',
        'REQUEST_URI',
        'REMOTE_ADDR',
        'REMOTE_PORT',
        'SCRIPT_NAME',
        'PHP_SELF',
    );
    $server = array();
    foreach ($server_keys as $key) {
        if (isset($_SERVER[$key])) {
            $server[$key] = $_SERVER[$key];
        }
    }

    $data = array(
        'version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'iniFile' => php_ini_loaded_file(),
        'loadedExtensions' => get_loaded_extensions(),
        'coreIni' => $core_ini,
        'env' => $env,
        'server' => $server,
    );

    gojs_json_response($data);
}

function gojs_api_phpinfo_ini() {
    $search = gojs_get_param('search', '');

    $ini = ini_get_all(null, false);

    if ($search) {
        $result = array();
        foreach ($ini as $key => $value) {
            if (stripos($key, $search) !== false) {
                $result[$key] = (string)$value;
            }
        }
        gojs_json_response($result);
    } else {
        $result = array();
        foreach ($ini as $key => $value) {
            $result[$key] = (string)$value;
        }
        gojs_json_response($result);
    }
}

function gojs_ini_bool($key) {
    $val = strtolower((string)ini_get($key));
    return $val === '1' || $val === 'on' || $val === 'true';
}

function gojs_ini_display($key, $off_label = 'Off') {
    $val = (string)ini_get($key);
    return $val === '' ? $off_label : $val;
}

function gojs_api_health_check() {
    $security = array();
    $performance = array();
    $compatibility = array();

    $security[] = array(
        'name' => 'display_errors',
        'currentValue' => gojs_ini_display('display_errors'),
        'recommendedValue' => 'Off',
        'status' => gojs_ini_bool('display_errors') ? 'danger' : 'pass',
        'description' => '生产环境应关闭错误显示，避免向用户泄露敏感的路径与配置信息',
    );

    $security[] = array(
        'name' => 'expose_php',
        'currentValue' => gojs_ini_display('expose_php'),
        'recommendedValue' => 'Off',
        'status' => gojs_ini_bool('expose_php') ? 'warning' : 'pass',
        'description' => '关闭后可隐藏响应头中的 PHP 版本信息，避免攻击者利用已知版本漏洞',
    );

    $security[] = array(
        'name' => 'allow_url_include',
        'currentValue' => gojs_ini_display('allow_url_include'),
        'recommendedValue' => 'Off',
        'status' => gojs_ini_bool('allow_url_include') ? 'danger' : 'pass',
        'description' => '禁止远程文件包含，可有效防止远程文件包含（RFI）攻击',
    );

    $security[] = array(
        'name' => 'allow_url_fopen',
        'currentValue' => gojs_ini_display('allow_url_fopen'),
        'recommendedValue' => 'Off',
        'status' => gojs_ini_bool('allow_url_fopen') ? 'warning' : 'pass',
        'description' => '关闭远程文件访问可提升安全性，但可能影响部分依赖远程请求的功能',
    );

    $ob_val = (string)ini_get('open_basedir');
    $security[] = array(
        'name' => 'open_basedir',
        'currentValue' => $ob_val === '' ? '未设置' : $ob_val,
        'recommendedValue' => '设置目录限制',
        'status' => $ob_val === '' ? 'warning' : 'pass',
        'description' => '限制 PHP 可访问的目录范围，防止跨目录越权访问',
    );

    $df_val = (string)ini_get('disable_functions');
    $dangerous_funcs = array('exec', 'system', 'shell_exec', 'passthru', 'popen', 'proc_open');
    $disabled_funcs = $df_val ? array_map('trim', explode(',', $df_val)) : array();
    $not_disabled = array();
    foreach ($dangerous_funcs as $f) {
        if (!in_array($f, $disabled_funcs, true)) {
            $not_disabled[] = $f;
        }
    }
    if (count($not_disabled) >= 3) {
        $df_status = 'danger';
    } elseif (count($not_disabled) > 0) {
        $df_status = 'warning';
    } else {
        $df_status = 'pass';
    }
    $security[] = array(
        'name' => 'disable_functions',
        'currentValue' => $df_val === '' ? '未禁用' : $df_val,
        'recommendedValue' => '禁用 exec/system/shell_exec 等危险函数',
        'status' => $df_status,
        'description' => '禁用危险函数可显著降低命令执行类漏洞的风险',
    );

    $security[] = array(
        'name' => 'session.cookie_httponly',
        'currentValue' => gojs_ini_display('session.cookie_httponly'),
        'recommendedValue' => 'On',
        'status' => gojs_ini_bool('session.cookie_httponly') ? 'pass' : 'danger',
        'description' => '开启后 Cookie 无法被 JavaScript 读取，可缓解 XSS 窃取会话的风险',
    );

    $security[] = array(
        'name' => 'session.cookie_secure',
        'currentValue' => gojs_ini_display('session.cookie_secure'),
        'recommendedValue' => 'On（HTTPS 环境）',
        'status' => gojs_ini_bool('session.cookie_secure') ? 'pass' : 'warning',
        'description' => '仅在 HTTPS 连接下传输 Cookie，HTTPS 站点应开启',
    );

    $ss_val = (string)ini_get('session.cookie_samesite');
    $ss_lower = strtolower($ss_val);
    $security[] = array(
        'name' => 'session.cookie_samesite',
        'currentValue' => $ss_val === '' ? '未设置' : $ss_val,
        'recommendedValue' => 'Strict 或 Lax',
        'status' => ($ss_lower === 'strict' || $ss_lower === 'lax') ? 'pass' : 'warning',
        'description' => '设置 SameSite 属性可缓解跨站请求伪造（CSRF）攻击',
    );

    $performance[] = array(
        'name' => 'opcache.enable',
        'currentValue' => gojs_ini_display('opcache.enable'),
        'recommendedValue' => 'On',
        'status' => gojs_ini_bool('opcache.enable') ? 'pass' : 'warning',
        'description' => '启用 OPcache 可缓存字节码，显著提升 PHP 性能',
    );

    $rcs_val = (string)ini_get('realpath_cache_size');
    $rcs_bytes = gojs_return_bytes($rcs_val);
    $performance[] = array(
        'name' => 'realpath_cache_size',
        'currentValue' => $rcs_val === '' ? '未设置' : $rcs_val,
        'recommendedValue' => '>= 4096K',
        'status' => $rcs_bytes >= 4096 * 1024 ? 'pass' : 'warning',
        'description' => '增大路径缓存可减少文件系统 stat 调用，提升包含大量文件时的性能',
    );

    $ml_val = (string)ini_get('memory_limit');
    $ml_bytes = gojs_return_bytes($ml_val);
    $performance[] = array(
        'name' => 'memory_limit',
        'currentValue' => $ml_val === '' ? '未设置' : $ml_val,
        'recommendedValue' => '>= 128M',
        'status' => $ml_bytes >= 128 * 1024 * 1024 ? 'pass' : 'warning',
        'description' => '单个脚本可使用的内存上限，过小可能导致复杂任务内存不足',
    );

    $met_val = (string)ini_get('max_execution_time');
    $met_num = (int)$met_val;
    $performance[] = array(
        'name' => 'max_execution_time',
        'currentValue' => $met_val === '' ? '0' : $met_val,
        'recommendedValue' => '>= 30',
        'status' => $met_num >= 30 ? 'pass' : 'warning',
        'description' => '脚本最大执行时间（秒），过小可能导致长任务超时',
    );

    $umf_val = (string)ini_get('upload_max_filesize');
    $umf_bytes = gojs_return_bytes($umf_val);
    $performance[] = array(
        'name' => 'upload_max_filesize',
        'currentValue' => $umf_val === '' ? '未设置' : $umf_val,
        'recommendedValue' => '>= 10M',
        'status' => $umf_bytes >= 10 * 1024 * 1024 ? 'pass' : 'warning',
        'description' => '单文件上传大小上限，过小会影响大文件上传',
    );

    $pms_val = (string)ini_get('post_max_size');
    $pms_bytes = gojs_return_bytes($pms_val);
    $performance[] = array(
        'name' => 'post_max_size',
        'currentValue' => $pms_val === '' ? '未设置' : $pms_val,
        'recommendedValue' => '>= 10M',
        'status' => $pms_bytes >= 10 * 1024 * 1024 ? 'pass' : 'warning',
        'description' => 'POST 请求体大小上限，需大于 upload_max_filesize',
    );

    $compatibility[] = gojs_build_compat(
        'WordPress',
        version_compare(PHP_VERSION, '7.4.0', '>='),
        array('PHP 7.4+（推荐 8.0+）', 'MySQL 5.6+ / MariaDB 10.1+'),
        array('mysqli', 'json')
    );

    $compatibility[] = gojs_build_compat(
        'Typecho',
        version_compare(PHP_VERSION, '7.2.0', '>='),
        array('PHP 7.2+'),
        array('mbstring', 'json')
    );

    $compatibility[] = gojs_build_compat(
        'Laravel 11',
        version_compare(PHP_VERSION, '8.2.0', '>='),
        array('PHP 8.2+'),
        array('mbstring', 'openssl', 'pdo', 'tokenizer', 'xml')
    );

    $compatibility[] = gojs_build_compat(
        'ThinkPHP 8',
        version_compare(PHP_VERSION, '8.0.0', '>='),
        array('PHP 8.0+'),
        array('mbstring', 'json', 'pdo')
    );

    $summary = array('pass' => 0, 'warning' => 0, 'danger' => 0, 'total' => 0);
    foreach (array_merge($security, $performance) as $item) {
        $summary['total']++;
        if ($item['status'] === 'pass') {
            $summary['pass']++;
        } elseif ($item['status'] === 'warning') {
            $summary['warning']++;
        } elseif ($item['status'] === 'danger') {
            $summary['danger']++;
        }
    }
    foreach ($compatibility as $item) {
        $summary['total']++;
        if ($item['pass']) {
            $summary['pass']++;
        } else {
            $summary['danger']++;
        }
    }

    gojs_json_response(array(
        'security' => $security,
        'performance' => $performance,
        'compatibility' => $compatibility,
        'summary' => $summary,
    ));
}

function gojs_build_compat($name, $php_ok, $php_req_lines, $exts) {
    $requirements = $php_req_lines;
    $missing = array();
    if (!$php_ok) {
        $missing[] = 'PHP 版本不满足';
    }
    foreach ($exts as $ext) {
        $requirements[] = '扩展: ' . $ext;
        if (!extension_loaded($ext)) {
            $missing[] = $ext;
        }
    }
    return array(
        'name' => $name,
        'pass' => $php_ok && empty($missing),
        'requirements' => $requirements,
        'missing' => $missing,
    );
}

function gojs_api_system() {
    global $root_path;

    $files_root = !empty($GLOBALS['files_root']) ? $GLOBALS['files_root'] : $root_path;

    $disk_total = @disk_total_space($files_root);
    $disk_free = @disk_free_space($files_root);
    $disk_used = ($disk_total && $disk_free) ? ($disk_total - $disk_free) : 0;

    $load_average = null;
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        if ($load) {
            $load_average = array_values($load);
        }
    }

    $uptime = null;
    if (is_readable('/proc/uptime')) {
        $content = @file_get_contents('/proc/uptime');
        if ($content) {
            $parts = preg_split('/\s+/', trim($content));
            if (!empty($parts[0])) {
                $uptime = (float)$parts[0];
            }
        }
    }

    $mem_total = null;
    $mem_available = null;
    $mem_used = null;
    $mem_percent = null;
    if (is_readable('/proc/meminfo')) {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo) {
            $lines = explode("\n", $meminfo);
            $mem_kv = array();
            foreach ($lines as $line) {
                if (preg_match('/^([A-Za-z_]+):\s*(\d+)/', $line, $m)) {
                    $mem_kv[$m[1]] = (int)$m[2];
                }
            }
            if (isset($mem_kv['MemTotal'])) {
                $mem_total = $mem_kv['MemTotal'];
            }
            if (isset($mem_kv['MemAvailable'])) {
                $mem_available = $mem_kv['MemAvailable'];
            } elseif (isset($mem_kv['MemFree'])) {
                $mem_available = $mem_kv['MemFree'];
                if (isset($mem_kv['Buffers'])) {
                    $mem_available += $mem_kv['Buffers'];
                }
                if (isset($mem_kv['Cached'])) {
                    $mem_available += $mem_kv['Cached'];
                }
            }
            if ($mem_total !== null && $mem_total > 0) {
                if ($mem_available !== null) {
                    $mem_used = $mem_total - $mem_available;
                    if ($mem_used < 0) {
                        $mem_used = 0;
                    }
                    $mem_percent = round(($mem_used / $mem_total) * 100, 1);
                }
            }
        }
    }

    $data = array(
        'diskTotal' => $disk_total ? $disk_total : 0,
        'diskFree' => $disk_free ? $disk_free : 0,
        'diskUsed' => $disk_used,
        'loadAverage' => $load_average,
        'uptime' => $uptime,
        'serverAddr' => isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : null,
        'serverName' => isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : null,
        'webServer' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : null,
        'memTotal' => $mem_total,
        'memAvailable' => $mem_available,
        'memUsed' => $mem_used,
        'memPercent' => $mem_percent,
    );

    gojs_json_response($data);
}

function gojs_api_processes() {
    if (!is_readable('/proc')) {
        gojs_json_response(null, array(
            'code' => 'not_supported',
            'message' => '系统不支持进程查看',
        ), 400);
    }

    $pids = array();
    $handle = @opendir('/proc');
    if (!$handle) {
        gojs_json_response(null, array(
            'code' => 'read_failed',
            'message' => '读取 /proc 失败',
        ), 500);
    }
    while (($entry = readdir($handle)) !== false) {
        if (preg_match('/^\d+$/', $entry)) {
            $pids[] = (int)$entry;
        }
    }
    closedir($handle);

    $total_mem = 0;
    $meminfo = @file_get_contents('/proc/meminfo');
    if ($meminfo) {
        if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $m)) {
            $total_mem = (int)$m[1];
        }
    }

    $sample_pids = array_slice($pids, 0, 50);

    function gojs_read_stat_fields($pid) {
        $stat_path = '/proc/' . $pid . '/stat';
        if (!is_readable($stat_path)) {
            return null;
        }
        $content = @file_get_contents($stat_path);
        if (!$content) {
            return null;
        }
        $open = strpos($content, '(');
        $close = strrpos($content, ')');
        if ($open === false || $close === false || $close <= $open) {
            return null;
        }
        $prefix = substr($content, 0, $open);
        $suffix = substr($content, $close + 1);
        $rest = $prefix . 'COMM' . $suffix;
        $fields = preg_split('/\s+/', trim($rest));
        if (count($fields) < 22) {
            return null;
        }
        return $fields;
    }

    $stat_t1 = array();
    $jiffies_total_t1 = 0;
    $stat_content = @file_get_contents('/proc/stat');
    if ($stat_content) {
        $lines = explode("\n", $stat_content);
        foreach ($lines as $line) {
            if (strpos($line, 'cpu ') === 0) {
                $parts = preg_split('/\s+/', trim($line));
                for ($i = 1; $i < count($parts) && $i <= 8; $i++) {
                    $jiffies_total_t1 += (int)$parts[$i];
                }
                break;
            }
        }
    }
    foreach ($sample_pids as $pid) {
        $f = gojs_read_stat_fields($pid);
        if ($f !== null) {
            $utime = isset($f[13]) ? (int)$f[13] : 0;
            $stime = isset($f[14]) ? (int)$f[14] : 0;
            $stat_t1[$pid] = $utime + $stime;
        }
    }

    usleep(200000);

    $stat_t2 = array();
    $jiffies_total_t2 = 0;
    $stat_content2 = @file_get_contents('/proc/stat');
    if ($stat_content2) {
        $lines = explode("\n", $stat_content2);
        foreach ($lines as $line) {
            if (strpos($line, 'cpu ') === 0) {
                $parts = preg_split('/\s+/', trim($line));
                for ($i = 1; $i < count($parts) && $i <= 8; $i++) {
                    $jiffies_total_t2 += (int)$parts[$i];
                }
                break;
            }
        }
    }
    foreach ($sample_pids as $pid) {
        $f = gojs_read_stat_fields($pid);
        if ($f !== null) {
            $utime = isset($f[13]) ? (int)$f[13] : 0;
            $stime = isset($f[14]) ? (int)$f[14] : 0;
            $stat_t2[$pid] = $utime + $stime;
        }
    }

    $delta_total = $jiffies_total_t2 - $jiffies_total_t1;

    $processes = array();
    foreach ($pids as $pid) {
        $status_file = '/proc/' . $pid . '/status';
        $cmdline_file = '/proc/' . $pid . '/cmdline';

        $name = '';
        $vm_rss = 0;

        if (is_readable($status_file)) {
            $status_content = @file_get_contents($status_file);
            if ($status_content) {
                $lines = explode("\n", $status_content);
                foreach ($lines as $line) {
                    if (strpos($line, 'Name:') === 0) {
                        $name = trim(substr($line, 5));
                    } elseif (strpos($line, 'VmRSS:') === 0) {
                        preg_match('/\d+/', $line, $matches);
                        if ($matches) {
                            $vm_rss = (int)$matches[0];
                        }
                    }
                }
            }
        }

        $cmdline = '';
        if (is_readable($cmdline_file)) {
            $cmdline_content = @file_get_contents($cmdline_file);
            if ($cmdline_content) {
                $cmdline = str_replace("\0", ' ', $cmdline_content);
                $cmdline = trim($cmdline);
            }
        }

        $mem_percent = 0;
        if ($total_mem > 0 && $vm_rss > 0) {
            $mem_percent = round(($vm_rss / $total_mem) * 100, 1);
        }

        $cpu = null;
        if (isset($stat_t1[$pid]) && isset($stat_t2[$pid]) && $delta_total > 0) {
            $delta_proc = $stat_t2[$pid] - $stat_t1[$pid];
            if ($delta_proc < 0) {
                $delta_proc = 0;
            }
            $cpu = round(($delta_proc / $delta_total) * 100, 1);
            if ($cpu < 0) {
                $cpu = 0;
            }
            if ($cpu > 100) {
                $cpu = 100.0;
            }
        }

        $processes[] = array(
            'pid' => $pid,
            'name' => $name,
            'cmdline' => $cmdline,
            'cpu' => $cpu,
            'mem' => $mem_percent,
        );
    }

    gojs_json_response($processes);
}

function gojs_api_cron() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['cron']) {
        gojs_json_response(null, array(
            'code' => 'not_supported',
            'message' => '系统不支持 crontab',
        ), 400);
    }

    $output = array();
    $return_var = 0;

    exec('crontab -l 2>&1', $output, $return_var);

    $jobs = array();

    if ($return_var === 0 && !empty($output)) {
        foreach ($output as $line) {
            $line = trim($line);

            if (!$line || strpos($line, '#') === 0 || strpos($line, '@') !== 0 && strpos($line, 'MAILTO') === 0 || strpos($line, 'SHELL') === 0 || strpos($line, 'PATH') === 0) {

                if (strpos($line, '@') === 0) {
                    $parts = preg_split('/\s+/', $line, 2);
                    if (count($parts) >= 2) {
                        $jobs[] = array(
                            'minute' => $parts[0],
                            'hour' => '',
                            'day' => '',
                            'month' => '',
                            'weekday' => '',
                            'command' => $parts[1],
                            'raw' => $line,
                        );
                    }
                }
                continue;
            }

            $parts = preg_split('/\s+/', $line, 6);
            if (count($parts) >= 6) {
                $jobs[] = array(
                    'minute' => $parts[0],
                    'hour' => $parts[1],
                    'day' => $parts[2],
                    'month' => $parts[3],
                    'weekday' => $parts[4],
                    'command' => $parts[5],
                    'raw' => $line,
                );
            }
        }
    }

    gojs_json_response($jobs);
}

function gojs_scan_dir_size($dir, $max_depth = 6, $depth = 0) {
    $size = 0;
    $count = 0;

    if ($depth >= $max_depth) {
        return array($size, $count);
    }

    $handle = @opendir($dir);
    if (!$handle) {
        return array($size, $count);
    }

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;

        if (is_dir($path) && !is_link($path)) {
            list($sub_size, $sub_count) = gojs_scan_dir_size($path, $max_depth, $depth + 1);
            $size += $sub_size;
            $count += $sub_count;
        } else {
            $fsize = @filesize($path);
            if ($fsize !== false) {
                $size += $fsize;
            }
            $count++;
        }
    }
    closedir($handle);

    return array($size, $count);
}

function gojs_find_large_files($dir, &$files, $threshold, $max_files, $max_depth = 8, $depth = 0) {
    if ($depth >= $max_depth || count($files) >= $max_files) {
        return;
    }

    $handle = @opendir($dir);
    if (!$handle) {
        return;
    }

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        if (count($files) >= $max_files) {
            break;
        }

        $path = $dir . '/' . $entry;

        if (is_dir($path) && !is_link($path)) {
            gojs_find_large_files($path, $files, $threshold, $max_files, $max_depth, $depth + 1);
        } else {
            $fsize = @filesize($path);
            if ($fsize !== false && $fsize >= $threshold) {
                $mtime = @filemtime($path);
                $rel = gojs_relative_path($path);
                $files[] = array(
                    'name' => $entry,
                    'path' => $rel,
                    'size' => $fsize,
                    'modified' => $mtime ? date('c', $mtime) : '',
                );
            }
        }
    }
    closedir($handle);
}

function gojs_api_disk_analysis() {
    global $root_path;

    $path = gojs_get_param('path', '/');
    if ($path === '') {
        $path = '/';
    }

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    if (!is_dir($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_directory',
            'message' => '路径不是目录',
        ), 400);
    }

    $disk_total = @disk_total_space($safe_path);
    $disk_free = @disk_free_space($safe_path);

    $directories = array();
    $total_size = 0;
    $max_dirs = 100;

    $handle = @opendir($safe_path);
    if (!$handle) {
        gojs_json_response(null, array(
            'code' => 'read_dir_failed',
            'message' => '读取目录失败',
        ), 500);
    }

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        if (count($directories) >= $max_dirs) {
            break;
        }

        $full_path = $safe_path . '/' . $entry;

        if (!is_dir($full_path) || is_link($full_path)) {
            continue;
        }

        list($size, $file_count) = gojs_scan_dir_size($full_path, 6);
        $total_size += $size;
        $rel = gojs_relative_path($full_path);
        $directories[] = array(
            'name' => $entry,
            'path' => $rel,
            'size' => $size,
            'fileCount' => $file_count,
            'percent' => 0,
        );
    }
    closedir($handle);

    foreach ($directories as &$d) {
        $d['percent'] = $total_size > 0 ? round(($d['size'] / $total_size) * 100, 2) : 0;
    }
    unset($d);

    usort($directories, function($a, $b) {
        return $b['size'] - $a['size'];
    });

    gojs_json_response(array(
        'directories' => $directories,
        'totalSize' => $total_size,
        'diskTotal' => $disk_total ? $disk_total : 0,
        'diskFree' => $disk_free ? $disk_free : 0,
    ));
}

function gojs_api_disk_analysis_large_files() {
    $path = gojs_get_param('path', '/');
    if ($path === '') {
        $path = '/';
    }

    $threshold = (int)gojs_get_param('threshold', 10485760);
    if ($threshold < 0) {
        $threshold = 10485760;
    }

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    if (!is_dir($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_directory',
            'message' => '路径不是目录',
        ), 400);
    }

    $files = array();
    $max_files = 100;

    gojs_find_large_files($safe_path, $files, $threshold, $max_files, 8);

    usort($files, function($a, $b) {
        return $b['size'] - $a['size'];
    });

    if (count($files) > $max_files) {
        $files = array_slice($files, 0, $max_files);
    }

    gojs_json_response(array(
        'files' => $files,
        'total' => count($files),
    ));
}

function gojs_find_error_log() {
    global $root_path;
    $candidates = array();
    $candidates[] = ini_get('error_log');
    $candidates[] = $root_path . '/.gojs/php_errors.log';
    $candidates[] = $root_path . '/error_log';
    $candidates[] = $root_path . '/logs/error.log';
    $candidates[] = $root_path . '/php_errorlog';
    $candidates[] = dirname($root_path) . '/logs/error.log';
    $candidates[] = dirname($root_path) . '/error_log';

    foreach ($candidates as $path) {
        if (!$path) continue;
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }
    return false;
}

function gojs_api_error_log() {
    $log_path = gojs_find_error_log();

    if (!$log_path) {
        gojs_json_response(array(
            'found' => false,
            'entries' => array(),
            'path' => null,
        ));
    }

    $limit = (int)gojs_get_param('limit', 50);
    if ($limit <= 0) $limit = 50;
    if ($limit > 500) $limit = 500;

    $entries = array();
    $file = @fopen($log_path, 'r');
    if (!$file) {
        gojs_json_response(array(
            'found' => true,
            'path' => $log_path,
            'entries' => array(),
            'size' => filesize($log_path),
        ));
    }

    $lines = array();
    while (($line = fgets($file)) !== false) {
        $lines[] = $line;
        if (count($lines) > $limit * 2) {
            array_splice($lines, 0, count($lines) - $limit);
        }
    }
    fclose($file);

    $lines = array_slice($lines, -$limit);
    $lines = array_reverse($lines);

    foreach ($lines as $line) {
        $line = trim($line);
        if (!$line) continue;
        $type = 'info';
        if (stripos($line, 'Fatal error') !== false || stripos($line, 'PHP Fatal') !== false) {
            $type = 'fatal';
        } elseif (stripos($line, 'Warning') !== false || stripos($line, 'PHP Warning') !== false) {
            $type = 'warning';
        } elseif (stripos($line, 'Notice') !== false || stripos($line, 'PHP Notice') !== false) {
            $type = 'notice';
        } elseif (stripos($line, 'Deprecated') !== false || stripos($line, 'PHP Deprecated') !== false) {
            $type = 'deprecated';
        }
        $entries[] = array(
            'message' => $line,
            'type' => $type,
        );
    }

    gojs_json_response(array(
        'found' => true,
        'path' => $log_path,
        'entries' => $entries,
        'size' => filesize($log_path),
    ));
}

function gojs_api_error_log_clear() {
    $log_path = gojs_find_error_log();
    if (!$log_path) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '未找到错误日志',
        ), 404);
    }

    gojs_ensure_not_protected($log_path, '清空日志');

    if (@file_put_contents($log_path, '') === false) {
        gojs_json_response(null, array(
            'code' => 'clear_failed',
            'message' => '清空日志失败',
        ), 500);
    }

    gojs_json_response(array('success' => true));
}

function gojs_api_install_check() {
    global $root_path;

    $checks = array();

    $checks[] = array(
        'name' => 'PHP 版本',
        'pass' => version_compare(PHP_VERSION, '7.4.0', '>='),
        'value' => PHP_VERSION,
        'required' => '>= 7.4.0',
    );

    $required_extensions = array('json', 'mbstring', 'fileinfo');
    $optional_extensions = array('zip' => 'Zip 压缩', 'mysqli' => 'MySQL 数据库', 'gd' => 'GD 图像处理');

    foreach ($required_extensions as $ext) {
        $checks[] = array(
            'name' => '扩展: ' . $ext,
            'pass' => extension_loaded($ext),
            'value' => extension_loaded($ext) ? '已安装' : '未安装',
            'required' => '必需',
        );
    }

    foreach ($optional_extensions as $ext => $label) {
        $checks[] = array(
            'name' => '扩展: ' . $label,
            'pass' => extension_loaded($ext),
            'value' => extension_loaded($ext) ? '已安装' : '未安装',
            'required' => '可选',
        );
    }

    $checks[] = array(
        'name' => '根目录可写',
        'pass' => is_writable($root_path),
        'value' => is_writable($root_path) ? '可写' : '不可写',
        'required' => '必需',
    );

    $config_dir = $root_path . '/.gojs';
    $config_writable = true;
    if (is_dir($config_dir)) {
        $config_writable = is_writable($config_dir);
    } else {
        $config_writable = is_writable($root_path);
    }
    $checks[] = array(
        'name' => '配置目录可写',
        'pass' => $config_writable,
        'value' => $config_writable ? '可写' : '不可写',
        'required' => '必需',
    );

    $disabled = explode(',', ini_get('disable_functions'));
    $disabled = array_map('trim', $disabled);
    $important = array('exec', 'shell_exec', 'system', 'passthru');
    $missing_funcs = array();
    foreach ($important as $f) {
        if (in_array($f, $disabled)) {
            $missing_funcs[] = $f;
        }
    }
    $checks[] = array(
        'name' => '系统函数可用',
        'pass' => empty($missing_funcs),
        'value' => empty($missing_funcs) ? '正常' : '禁用: ' . implode(', ', $missing_funcs),
        'required' => '可选',
    );

    $all_pass = true;
    foreach ($checks as $c) {
        if ($c['required'] === '必需' && !$c['pass']) {
            $all_pass = false;
            break;
        }
    }

    gojs_json_response(array(
        'pass' => $all_pass,
        'checks' => $checks,
        'disabledFunctions' => $disabled,
    ));
}

function gojs_get_encryption_key() {
    global $config;

    if (!empty($config['encryption_key'])) {
        $key = $config['encryption_key'];

        return substr(hash('sha256', $key, true), 0, 32);
    }

    if (!empty($config['password_hash'])) {
        return substr(hash('sha256', $config['password_hash'], true), 0, 32);
    }

    return str_repeat("\0", 32);
}

function gojs_encrypt($data) {
    if (!function_exists('openssl_encrypt')) {
        return base64_encode($data);
    }

    $key = gojs_get_encryption_key();
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    return base64_encode($iv . $encrypted);
}

function gojs_decrypt($data) {
    if (!function_exists('openssl_decrypt')) {
        return base64_decode($data);
    }

    $key = gojs_get_encryption_key();
    $raw = base64_decode($data);

    if (strlen($raw) < 16) {
        return false;
    }

    $iv = substr($raw, 0, 16);
    $encrypted = substr($raw, 16);

    return openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
}

function gojs_load_db_connections() {
    if (!file_exists(DB_CONNECTIONS_FILE)) {
        return array();
    }

    $content = @file_get_contents(DB_CONNECTIONS_FILE);
    if (!$content) {
        return array();
    }

    $data = json_decode($content, true);
    if (!is_array($data)) {
        return array();
    }

    return $data;
}

function gojs_save_db_connections($connections) {
    if (!is_dir(CONFIG_DIR)) {
        @mkdir(CONFIG_DIR, 0700, true);
    }

    $content = json_encode($connections, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $result = @file_put_contents(DB_CONNECTIONS_FILE, $content, LOCK_EX);

    if ($result !== false) {
        @chmod(DB_CONNECTIONS_FILE, 0600);
    }

    return $result !== false;
}

function gojs_get_db_connection($id) {
    $connections = gojs_load_db_connections();

    foreach ($connections as $conn) {
        if (!empty($conn['id']) && $conn['id'] === $id) {

            if (!empty($conn['password'])) {
                $conn['password'] = gojs_decrypt($conn['password']);
            }
            return $conn;
        }
    }

    return null;
}

function gojs_db_connect($conn) {
    $capabilities = gojs_get_capabilities();

    $host = !empty($conn['host']) ? $conn['host'] : 'localhost';
    $port = !empty($conn['port']) ? (int)$conn['port'] : 3306;
    $username = !empty($conn['username']) ? $conn['username'] : '';
    $password = !empty($conn['password']) ? $conn['password'] : '';
    $database = !empty($conn['database']) ? $conn['database'] : '';

    if (extension_loaded('mysqli')) {
        $old_report = null;
        try {
            $old_report = mysqli_report(MYSQLI_REPORT_OFF);
            $mysqli = @new mysqli($host, $username, $password, $database, $port);
            if ($mysqli->connect_error || $mysqli->connect_errno) {
                $err = $mysqli->connect_error ? $mysqli->connect_error : ('Connect error #' . $mysqli->connect_errno);
                return array(
                    'success' => false,
                    'error' => $err,
                );
            }
            $mysqli->set_charset('utf8mb4');
            return array(
                'success' => true,
                'type' => 'mysqli',
                'connection' => $mysqli,
            );
        } catch (Throwable $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        } finally {
            if ($old_report !== null) {
                @mysqli_report($old_report);
            }
        }
    }

    if (extension_loaded('pdo_mysql')) {
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return array(
                'success' => true,
                'type' => 'pdo',
                'connection' => $pdo,
            );
        } catch (PDOException $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }

    return array(
        'success' => false,
        'error' => '没有可用的 MySQL 扩展',
    );
}

function gojs_api_db_connections() {
    $method = gojs_get_method();

    if ($method === 'GET') {
        $connections = gojs_load_db_connections();

        $result = array();
        foreach ($connections as $conn) {
            $item = $conn;
            unset($item['password']);
            $result[] = $item;
        }

        gojs_json_response($result);
    } elseif ($method === 'POST') {
        $capabilities = gojs_get_capabilities();

        if (!$capabilities['mysql']) {
            gojs_json_response(null, array(
                'code' => 'not_supported',
                'message' => '系统不支持 MySQL',
            ), 400);
        }

        $name = gojs_get_param('name', '');
        $host = gojs_get_param('host', 'localhost');
        $port = gojs_get_param('port', 3306);
        $username = gojs_get_param('username', '');
        $password = gojs_get_param('password', '');
        $database = gojs_get_param('database', '');

        if (!$name) {
            gojs_json_response(null, array(
                'code' => 'invalid_name',
                'message' => '连接名称不能为空',
            ), 400);
        }

        $test_conn = array(
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'database' => $database,
        );

        $connect_result = gojs_db_connect($test_conn);
        if (!$connect_result['success']) {
            gojs_json_response(null, array(
                'code' => 'connect_failed',
                'message' => '连接失败: ' . $connect_result['error'],
            ), 400);
        }

        $id = 'conn_' . substr(bin2hex(random_bytes(8)), 0, 12);

        $connections = gojs_load_db_connections();

        $new_conn = array(
            'id' => $id,
            'name' => $name,
            'host' => $host,
            'port' => (int)$port,
            'username' => $username,
            'password' => gojs_encrypt($password),
            'database' => $database,
            'created_at' => time(),
        );

        $connections[] = $new_conn;

        if (!gojs_save_db_connections($connections)) {
            gojs_json_response(null, array(
                'code' => 'save_failed',
                'message' => '保存连接失败',
            ), 500);
        }

        $result = $new_conn;
        unset($result['password']);

        gojs_json_response($result);
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}

function gojs_api_db_connection($id, $method) {
    if ($method === 'PUT') {
        $capabilities = gojs_get_capabilities();

        if (!$capabilities['mysql']) {
            gojs_json_response(null, array(
                'code' => 'not_supported',
                'message' => '系统不支持 MySQL',
            ), 400);
        }

        $connections = gojs_load_db_connections();
        $found = false;
        $updated_conn = null;

        foreach ($connections as &$conn) {
            if (!empty($conn['id']) && $conn['id'] === $id) {
                $name = gojs_get_param('name');
                $host = gojs_get_param('host');
                $port = gojs_get_param('port');
                $username = gojs_get_param('username');
                $password = gojs_get_param('password');
                $database = gojs_get_param('database');

                if ($name !== null) $conn['name'] = $name;
                if ($host !== null) $conn['host'] = $host;
                if ($port !== null) $conn['port'] = (int)$port;
                if ($username !== null) $conn['username'] = $username;
                if ($database !== null) $conn['database'] = $database;
                if ($password !== null && $password !== '') {
                    $conn['password'] = gojs_encrypt($password);
                }

                $found = true;
                $updated_conn = $conn;
                break;
            }
        }
        unset($conn);

        if (!$found) {
            gojs_json_response(null, array(
                'code' => 'not_found',
                'message' => '连接不存在',
            ), 404);
        }

        if (!gojs_save_db_connections($connections)) {
            gojs_json_response(null, array(
                'code' => 'save_failed',
                'message' => '保存连接失败',
            ), 500);
        }

        $result = $updated_conn;
        unset($result['password']);

        gojs_json_response($result);
    } elseif ($method === 'DELETE') {
        $connections = gojs_load_db_connections();
        $new_connections = array();
        $found = false;

        foreach ($connections as $conn) {
            if (!empty($conn['id']) && $conn['id'] === $id) {
                $found = true;
                continue;
            }
            $new_connections[] = $conn;
        }

        if (!$found) {
            gojs_json_response(null, array(
                'code' => 'not_found',
                'message' => '连接不存在',
            ), 404);
        }

        if (!gojs_save_db_connections($new_connections)) {
            gojs_json_response(null, array(
                'code' => 'save_failed',
                'message' => '保存连接失败',
            ), 500);
        }

        gojs_json_response(array('success' => true));
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}

function gojs_api_db_databases() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'not_supported',
            'message' => '系统不支持 MySQL',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '连接不存在',
        ), 404);
    }

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'connect_failed',
            'message' => '连接失败: ' . $result['error'],
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];
    $databases = array();

    if ($type === 'mysqli') {
        $res = $db->query('SHOW DATABASES');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $databases[] = $row['Database'];
            }
            $res->free();
        }
    } elseif ($type === 'pdo') {
        $stmt = $db->query('SHOW DATABASES');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $databases[] = $row['Database'];
            }
        }
    }

    gojs_json_response($databases);
}

function gojs_api_db_tables() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'not_supported',
            'message' => '系统不支持 MySQL',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');

    if (!$database) {
        gojs_json_response(null, array(
            'code' => 'invalid_database',
            'message' => '数据库名不能为空',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '连接不存在',
        ), 404);
    }

    $conn_config['database'] = $database;

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'connect_failed',
            'message' => '连接失败: ' . $result['error'],
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];
    $tables = array();

    if ($type === 'mysqli') {
        $res = $db->query('SHOW TABLE STATUS');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $tables[] = array(
                    'name' => $row['Name'],
                    'engine' => $row['Engine'],
                    'rows' => (int)$row['Rows'],
                    'size' => (int)$row['Data_length'] + (int)$row['Index_length'],
                    'collation' => $row['Collation'],
                    'comment' => $row['Comment'],
                );
            }
            $res->free();
        }
    } elseif ($type === 'pdo') {
        $stmt = $db->query('SHOW TABLE STATUS');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $tables[] = array(
                    'name' => $row['Name'],
                    'engine' => $row['Engine'],
                    'rows' => (int)$row['Rows'],
                    'size' => (int)$row['Data_length'] + (int)$row['Index_length'],
                    'collation' => $row['Collation'],
                    'comment' => $row['Comment'],
                );
            }
        }
    }

    gojs_json_response($tables);
}

function gojs_api_db_structure() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'not_supported',
            'message' => '系统不支持 MySQL',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $table = gojs_get_param('table', '');

    if (!$database || !$table) {
        gojs_json_response(null, array(
            'code' => 'invalid_params',
            'message' => '数据库名和表名不能为空',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '连接不存在',
        ), 404);
    }

    $conn_config['database'] = $database;

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'connect_failed',
            'message' => '连接失败: ' . $result['error'],
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];
    $columns = array();

    $table_escaped = '`' . str_replace('`', '``', $table) . '`';

    if ($type === 'mysqli') {
        $res = $db->query('DESCRIBE ' . $table_escaped);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $columns[] = array(
                    'name' => $row['Field'],
                    'type' => $row['Type'],
                    'nullable' => $row['Null'] === 'YES',
                    'key' => $row['Key'],
                    'default' => $row['Default'],
                    'extra' => $row['Extra'],
                );
            }
            $res->free();
        }
    } elseif ($type === 'pdo') {
        $stmt = $db->query('DESCRIBE ' . $table_escaped);
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = array(
                    'name' => $row['Field'],
                    'type' => $row['Type'],
                    'nullable' => $row['Null'] === 'YES',
                    'key' => $row['Key'],
                    'default' => $row['Default'],
                    'extra' => $row['Extra'],
                );
            }
        }
    }

    gojs_json_response($columns);
}

function gojs_api_db_sql() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'not_supported',
            'message' => '系统不支持 MySQL',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $sql = gojs_get_param('sql', '');

    if (!$sql) {
        gojs_json_response(null, array(
            'code' => 'invalid_sql',
            'message' => 'SQL 不能为空',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '连接不存在',
        ), 404);
    }

    if ($database) {
        $conn_config['database'] = $database;
    }

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'connect_failed',
            'message' => '连接失败: ' . $result['error'],
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];

    $start_time = microtime(true);
    $results = array();

    $statement = trim($sql);

    $sql_result = array(
        'success' => true,
        'statement' => $statement,
    );

    try {
        if ($type === 'mysqli') {
            $res = $db->query($statement);

            if ($res === false) {
                $sql_result['success'] = false;
                $sql_result['error'] = $db->error;
            } elseif ($res === true) {
                $sql_result['affectedRows'] = $db->affected_rows;
            } else {
                $rows = array();
                while ($row = $res->fetch_assoc()) {
                    $rows[] = $row;
                }
                $res->free();
                $sql_result['rows'] = $rows;
            }
        } elseif ($type === 'pdo') {
            $stmt = $db->query($statement);

            if ($stmt === false) {
                $sql_result['success'] = false;
                $error_info = $db->errorInfo();
                $sql_result['error'] = $error_info[2];
            } else {
                if ($stmt->columnCount() > 0) {

                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $sql_result['rows'] = $rows;
                } else {

                    $sql_result['affectedRows'] = $stmt->rowCount();
                }
            }
        }
    } catch (Exception $e) {
        $sql_result['success'] = false;
        $sql_result['error'] = $e->getMessage();
    }

    $results[] = $sql_result;

    $execution_time = round((microtime(true) - $start_time) * 1000, 2);

    gojs_json_response(array(
        'results' => $results,
        'executionTime' => $execution_time,
    ));
}

function gojs_db_escape_value($db, $type, $value) {
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    if (is_string($value)) {
        if ($type === 'mysqli') {
            return "'" . $db->real_escape_string($value) . "'";
        }
        if ($type === 'pdo') {
            $quoted = $db->quote($value);
            if ($quoted !== false) {
                return $quoted;
            }
            return "'" . addslashes($value) . "'";
        }
    }

    return "'" . addslashes((string)$value) . "'";
}

function gojs_db_show_create_table($db, $type, $table_escaped) {
    if ($type === 'mysqli') {
        $res = $db->query('SHOW CREATE TABLE ' . $table_escaped);
        if ($res) {
            $row = $res->fetch_assoc();
            $res->free();
            return isset($row['Create Table']) ? $row['Create Table'] : '';
        }
        return '';
    }
    if ($type === 'pdo') {
        $stmt = $db->query('SHOW CREATE TABLE ' . $table_escaped);
        if ($stmt) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return isset($row['Create Table']) ? $row['Create Table'] : '';
        }
        return '';
    }
    return '';
}

function gojs_db_fetch_tables_list($db, $type) {
    $tables = array();
    if ($type === 'mysqli') {
        $res = $db->query('SHOW TABLES');
        if ($res) {
            while ($row = $res->fetch_row()) {
                if (isset($row[0])) {
                    $tables[] = $row[0];
                }
            }
            $res->free();
        }
    } elseif ($type === 'pdo') {
        $stmt = $db->query('SHOW TABLES');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                if (isset($row[0])) {
                    $tables[] = $row[0];
                }
            }
        }
    }
    return $tables;
}

function gojs_db_fetch_columns($db, $type, $table_escaped) {
    $columns = array();
    if ($type === 'mysqli') {
        $res = $db->query('SELECT * FROM ' . $table_escaped . ' LIMIT 1');
        if ($res) {
            $finfo = $res->fetch_fields();
            foreach ($finfo as $col) {
                $columns[] = $col->name;
            }
            $res->free();
        }
    } elseif ($type === 'pdo') {
        $stmt = $db->query('SELECT * FROM ' . $table_escaped . ' LIMIT 1');
        if ($stmt) {
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $meta = $stmt->getColumnMeta($i);
                if ($meta && isset($meta['name'])) {
                    $columns[] = $meta['name'];
                }
            }
        }
    }
    return $columns;
}

function gojs_api_db_export() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'not_supported',
            'message' => '系统不支持 MySQL',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $tables_param = gojs_get_param('tables', null);
    $mode = gojs_get_param('mode', 'structure_data');

    if (!in_array($mode, array('structure_only', 'structure_data'), true)) {
        $mode = 'structure_data';
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '连接不存在',
        ), 404);
    }

    if ($database) {
        $conn_config['database'] = $database;
    }

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'connect_failed',
            'message' => '连接失败: ' . $result['error'],
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];

    $tables = array();
    if (is_array($tables_param)) {
        foreach ($tables_param as $t) {
            if (is_string($t) && $t !== '') {
                $tables[] = $t;
            }
        }
    }

    if (empty($tables)) {
        $tables = gojs_db_fetch_tables_list($db, $type);
    }

    @set_time_limit(0);
    if (function_exists('ini_set')) {
        @ini_set('memory_limit', '512M');
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $filename = 'backup_' . date('Ymd_His') . '.sql';

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    if (!$out) {
        gojs_json_response(null, array(
            'code' => 'stream_failed',
            'message' => '无法打开输出流',
        ), 500);
    }

    fwrite($out, "-- Go.js SQL Dump\n");
    fwrite($out, "-- Host: " . (isset($conn_config['host']) ? $conn_config['host'] : 'localhost') . "\n");
    fwrite($out, "-- Generation Time: " . date('Y-m-d H:i:s') . "\n");
    fwrite($out, "-- Database: " . (isset($conn_config['database']) ? $conn_config['database'] : '') . "\n");
    fwrite($out, "\n");
    fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($out, "SET NAMES utf8;\n");
    fwrite($out, "SET SQL_MODE=\"\";\n");
    fwrite($out, "\n");

    $batch_size = 1000;

    foreach ($tables as $table) {
        $table_escaped = '`' . str_replace('`', '``', $table) . '`';

        fwrite($out, "\n-- ------------------------------------------------------------\n");
        fwrite($out, "-- Table structure for `" . $table . "`\n");
        fwrite($out, "-- ------------------------------------------------------------\n");
        fwrite($out, "DROP TABLE IF EXISTS " . $table_escaped . ";\n");

        $create_sql = gojs_db_show_create_table($db, $type, $table_escaped);
        if ($create_sql !== '') {
            fwrite($out, $create_sql . ";\n");
        }

        if ($mode !== 'structure_data') {
            continue;
        }

        $columns = gojs_db_fetch_columns($db, $type, $table_escaped);
        if (empty($columns)) {
            continue;
        }

        $col_list_escaped = array();
        foreach ($columns as $col) {
            $col_list_escaped[] = '`' . str_replace('`', '``', $col) . '`';
        }
        $col_list_sql = implode(', ', $col_list_escaped);

        fwrite($out, "\n-- Dumping data for `" . $table . "`\n");

        $offset = 0;
        $has_more = true;

        while ($has_more) {
            $limit_sql = 'SELECT * FROM ' . $table_escaped . ' LIMIT ' . (int)$offset . ', ' . (int)$batch_size;

            $rows = array();
            if ($type === 'mysqli') {
                $res = $db->query($limit_sql);
                if ($res === false) {
                    fwrite($out, "-- ERROR fetching data: " . $db->error . "\n");
                    break;
                }
                if ($res === true) {
                    break;
                }
                while ($row = $res->fetch_assoc()) {
                    $rows[] = $row;
                }
                $res->free();
            } elseif ($type === 'pdo') {
                $stmt = $db->query($limit_sql);
                if ($stmt === false) {
                    break;
                }
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $values = array();
                foreach ($columns as $col) {
                    $val = isset($row[$col]) ? $row[$col] : null;
                    $values[] = gojs_db_escape_value($db, $type, $val);
                }
                fwrite($out, "INSERT INTO " . $table_escaped . " (" . $col_list_sql . ") VALUES (" . implode(', ', $values) . ");\n");
            }

            if (count($rows) < $batch_size) {
                $has_more = false;
            } else {
                $offset += $batch_size;
            }
        }
    }

    fwrite($out, "\nSET FOREIGN_KEY_CHECKS=1;\n");

    fclose($out);
    exit;
}

function gojs_sql_split_statements($content) {
    $statements = array();
    $len = strlen($content);

    $buffer = '';
    $in_single = false;
    $in_double = false;
    $in_backtick = false;
    $in_line_comment = false;
    $in_block_comment = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $content[$i];
        $next = ($i + 1 < $len) ? $content[$i + 1] : '';
        $prev = ($i > 0) ? $content[$i - 1] : '';

        if ($in_line_comment) {
            $buffer .= $ch;
            if ($ch === "\n") {
                $in_line_comment = false;
            }
            continue;
        }

        if ($in_block_comment) {
            $buffer .= $ch;
            if ($ch === '*' && $next === '/') {
                $buffer .= '/';
                $i++;
                $in_block_comment = false;
            }
            continue;
        }

        if ($in_single) {
            $buffer .= $ch;
            if ($ch === '\\' && $next !== '') {
                $buffer .= $next;
                $i++;
                continue;
            }
            if ($ch === "'") {
                $in_single = false;
            }
            continue;
        }

        if ($in_double) {
            $buffer .= $ch;
            if ($ch === '\\' && $next !== '') {
                $buffer .= $next;
                $i++;
                continue;
            }
            if ($ch === '"') {
                $in_double = false;
            }
            continue;
        }

        if ($in_backtick) {
            $buffer .= $ch;
            if ($ch === '`') {
                $in_backtick = false;
            }
            continue;
        }

        if ($ch === '-' && $next === '-' && ($prev === '' || $prev === "\n" || $prev === "\r" || $prev === ' ' || $prev === "\t")) {
            $in_line_comment = true;
            $buffer .= $ch;
            continue;
        }

        if ($ch === '#') {
            $in_line_comment = true;
            $buffer .= $ch;
            continue;
        }

        if ($ch === '/' && $next === '*') {
            $in_block_comment = true;
            $buffer .= $ch;
            continue;
        }

        if ($ch === "'") {
            $in_single = true;
            $buffer .= $ch;
            continue;
        }

        if ($ch === '"') {
            $in_double = true;
            $buffer .= $ch;
            continue;
        }

        if ($ch === '`') {
            $in_backtick = true;
            $buffer .= $ch;
            continue;
        }

        if ($ch === ';') {
            $stmt = trim($buffer);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $ch;
    }

    $stmt = trim($buffer);
    if ($stmt !== '') {
        $statements[] = $stmt;
    }

    return $statements;
}

function gojs_sql_strip_comments($statement) {
    $lines = explode("\n", $statement);
    $cleaned = array();
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '') {
            continue;
        }
        if (strpos($trimmed, '--') === 0) {
            continue;
        }
        if (strpos($trimmed, '#') === 0) {
            continue;
        }
        $cleaned[] = $line;
    }
    return trim(implode("\n", $cleaned));
}

function gojs_sql_detect_dangerous_statements($sql_content) {
    $dangerous = array();

    if (preg_match_all('/^\s*DROP\s+DATABASE\b/im', $sql_content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $dangerous[] = array('type' => 'DROP_DATABASE', 'statement' => substr($sql_content, $match[1], 100));
        }
    }

    if (preg_match_all('/^\s*DROP\s+TABLE\b/im', $sql_content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $dangerous[] = array('type' => 'DROP_TABLE', 'statement' => substr($sql_content, $match[1], 100));
        }
    }

    if (preg_match_all('/^\s*TRUNCATE\s+TABLE\b/im', $sql_content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $dangerous[] = array('type' => 'TRUNCATE_TABLE', 'statement' => substr($sql_content, $match[1], 100));
        }
    }

    if (preg_match_all('/^\s*DELETE\s+FROM\s+\S+\s*$/im', $sql_content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $dangerous[] = array('type' => 'DELETE_NO_WHERE', 'statement' => substr($sql_content, $match[1], 100));
        }
    }

    if (preg_match_all('/^\s*DELETE\s+FROM\s+\S+\s+ORDER\s+BY/im', $sql_content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $dangerous[] = array('type' => 'DELETE_NO_WHERE', 'statement' => substr($sql_content, $match[1], 100));
        }
    }

    return $dangerous;
}

function gojs_api_db_import() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'not_supported',
            'message' => '系统不支持 MySQL',
        ), 400);
    }

    $conn_id = isset($_POST['connId']) ? $_POST['connId'] : '';
    if (!$conn_id) {
        $conn_id = gojs_get_param('connId', '');
    }
    $database = isset($_POST['database']) ? $_POST['database'] : '';
    if (!$database) {
        $database = gojs_get_param('database', '');
    }

    if (!$conn_id) {
        gojs_json_response(null, array(
            'code' => 'invalid_params',
            'message' => '连接 ID 不能为空',
        ), 400);
    }

    if (empty($_FILES['file'])) {
        gojs_json_response(null, array(
            'code' => 'no_file',
            'message' => '没有上传文件',
        ), 400);
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        gojs_json_response(null, array(
            'code' => 'upload_error',
            'message' => '文件上传错误: ' . $file['error'],
        ), 400);
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        gojs_json_response(null, array(
            'code' => 'invalid_file',
            'message' => '无效的上传文件',
        ), 400);
    }

    $filename = isset($file['name']) ? $file['name'] : 'import.sql';
    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'sql') {
        gojs_json_response(null, array(
            'code' => 'invalid_extension',
            'message' => '仅支持 .sql 文件',
        ), 400);
    }

    $sql_content = file_get_contents($file['tmp_name']);
    if ($sql_content === false) {
        gojs_json_response(null, array(
            'code' => 'read_failed',
            'message' => '无法读取上传文件',
        ), 500);
    }
    $cleaned_sql = gojs_sql_strip_comments($sql_content);
    $dangerous_statements = gojs_sql_detect_dangerous_statements($cleaned_sql);
    $allow_dangerous = gojs_get_param('allowDangerous', false);
    if (!empty($dangerous_statements) && !$allow_dangerous) {
        gojs_json_response(null, array(
            'code' => 'dangerous_statements',
            'message' => 'SQL 文件中检测到危险语句，请确认后继续',
            'data' => array('dangerous' => $dangerous_statements),
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '连接不存在',
        ), 404);
    }

    if ($database) {
        $conn_config['database'] = $database;
    }

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'connect_failed',
            'message' => '连接失败: ' . $result['error'],
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];

    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    if ($type === 'mysqli') {
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        $db->query('SET NAMES utf8');
        $db->query('SET SQL_MODE=""');
        $db->autocommit(true);
    } else {
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        $db->query('SET NAMES utf8');
        $db->query('SET SQL_MODE=""');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
    }

    $handle = @fopen($file['tmp_name'], 'rb');
    if (!$handle) {
        gojs_json_response(null, array(
            'code' => 'read_failed',
            'message' => '无法读取上传文件',
        ), 500);
    }

    $executed = 0;
    $failed = 0;
    $errors = array();
    $max_errors = 50;

    $buffer = '';
    $chunk_size = 65536;

    $in_single = false;
    $in_double = false;
    $in_backtick = false;
    $in_line_comment = false;
    $in_block_comment = false;

    while (!feof($handle)) {
        $chunk = fread($handle, $chunk_size);
        if ($chunk === false) {
            break;
        }

        $len = strlen($chunk);
        for ($i = 0; $i < $len; $i++) {
            $ch = $chunk[$i];
            $next = ($i + 1 < $len) ? $chunk[$i + 1] : '';
            $prev = ($i > 0) ? $chunk[$i - 1] : (($buffer !== '') ? $buffer[strlen($buffer) - 1] : '');

            if ($in_line_comment) {
                $buffer .= $ch;
                if ($ch === "\n") {
                    $in_line_comment = false;
                }
                continue;
            }

            if ($in_block_comment) {
                $buffer .= $ch;
                if ($ch === '*' && $next === '/') {
                    $buffer .= '/';
                    $i++;
                    $in_block_comment = false;
                }
                continue;
            }

            if ($in_single) {
                $buffer .= $ch;
                if ($ch === '\\' && $next !== '') {
                    $buffer .= $next;
                    $i++;
                    continue;
                }
                if ($ch === "'") {
                    $in_single = false;
                }
                continue;
            }

            if ($in_double) {
                $buffer .= $ch;
                if ($ch === '\\' && $next !== '') {
                    $buffer .= $next;
                    $i++;
                    continue;
                }
                if ($ch === '"') {
                    $in_double = false;
                }
                continue;
            }

            if ($in_backtick) {
                $buffer .= $ch;
                if ($ch === '`') {
                    $in_backtick = false;
                }
                continue;
            }

            if ($ch === '-' && $next === '-' && ($prev === '' || $prev === "\n" || $prev === "\r" || $prev === ' ' || $prev === "\t")) {
                $in_line_comment = true;
                $buffer .= $ch;
                continue;
            }

            if ($ch === '#') {
                $in_line_comment = true;
                $buffer .= $ch;
                continue;
            }

            if ($ch === '/' && $next === '*') {
                $in_block_comment = true;
                $buffer .= $ch;
                continue;
            }

            if ($ch === "'") {
                $in_single = true;
                $buffer .= $ch;
                continue;
            }

            if ($ch === '"') {
                $in_double = true;
                $buffer .= $ch;
                continue;
            }

            if ($ch === '`') {
                $in_backtick = true;
                $buffer .= $ch;
                continue;
            }

            if ($ch === ';') {
                $stmt = gojs_sql_strip_comments($buffer);
                $buffer = '';

                if ($stmt === '') {
                    continue;
                }

                $err = null;
                if ($type === 'mysqli') {
                    $res = @$db->query($stmt);
                    if ($res === false) {
                        $err = $db->error;
                    }
                } else {
                    $affected = $db->exec($stmt);
                    if ($affected === false) {
                        $info = $db->errorInfo();
                        $err = isset($info[2]) ? $info[2] : 'PDO error';
                    }
                }

                if ($err !== null) {
                    $failed++;
                    if (count($errors) < $max_errors) {
                        $errors[] = $err;
                    }
                } else {
                    $executed++;
                }
                continue;
            }

            $buffer .= $ch;
        }
    }

    fclose($handle);

    $stmt = gojs_sql_strip_comments($buffer);
    if ($stmt !== '') {
        $err = null;
        if ($type === 'mysqli') {
            $res = @$db->query($stmt);
            if ($res === false) {
                $err = $db->error;
            }
        } else {
            $affected = $db->exec($stmt);
            if ($affected === false) {
                $info = $db->errorInfo();
                $err = isset($info[2]) ? $info[2] : 'PDO error';
            }
        }

        if ($err !== null) {
            $failed++;
            if (count($errors) < $max_errors) {
                $errors[] = $err;
            }
        } else {
            $executed++;
        }
    }

    $db->query('SET FOREIGN_KEY_CHECKS=1');

    gojs_json_response(array(
        'success' => true,
        'executed' => $executed,
        'failed' => $failed,
        'errors' => $errors,
    ));
}

function gojs_htaccess_default_content() {
    return <<<'HTACCESS'
# Go.js - 轻量级 PHP 服务器管理面板
# Apache 配置文件

# 禁止访问 .gojs 配置目录
<DirectoryMatch "^.*/\.gojs/">
    Require all denied
</DirectoryMatch>

<FilesMatch "^\.gojs">
    Require all denied
</FilesMatch>

# 禁止直接访问 PHP 文件（除了 api.php）
<FilesMatch "\.php$">
    SetEnvIf Request_URI "^/api\.php$" allow_php=1
    Require env allow_php
</FilesMatch>

# 禁止访问配置文件
<Files "config.php">
    Require all denied
</Files>

# 禁止访问 .htaccess
<Files ".htaccess">
    Require all denied
</Files>

# 禁止访问 .log 文件
<FilesMatch "\.log$">
    Require all denied
</FilesMatch>

# 禁止访问 .json 配置文件
<FilesMatch "db_connections\.json$">
    Require all denied
</FilesMatch>

# 禁止访问 .md 文件
<FilesMatch "\.md$">
    Require all denied
</FilesMatch>

# 默认入口：前端 SPA 优先
DirectoryIndex index.html

# 开启 URL 重写
RewriteEngine On

# 禁止直接访问 api.php（仅允许内部重写访问）
RewriteRule ^api\.php$ - [R=404,L]

# 如果请求的是真实存在的文件或目录，直接访问
RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^ - [L]

RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

# API 路由重写：/api/xxx -> api.php?api=xxx
RewriteRule ^api/(.*)$ api.php?api=$1 [QSA,L]

# 前端页面路由：所有非文件请求指向 index.html（SPA）
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.html [L]

# 安全头部
<IfModule mod_headers.c>
    # 阻止点击劫持
    Header always set X-Frame-Options "SAMEORIGIN"

    # MIME 类型嗅探保护
    Header always set X-Content-Type-Options "nosniff"

    # XSS 保护
    Header always set X-XSS-Protection "1; mode=block"

    # Referrer Policy
    Header always set Referrer-Policy "strict-origin-when-cross-origin"

    # 禁止浏览器缓存敏感页面
    <FilesMatch "\.(php)$">
        Header set Cache-Control "no-cache, no-store, must-revalidate"
        Header set Pragma "no-cache"
        Header set Expires "0"
    </FilesMatch>
</IfModule>

# PHP 设置
<IfModule mod_php.c>
    php_flag display_errors Off
    php_flag log_errors On
    php_value error_log .gojs/php_errors.log
    php_flag expose_php Off
    php_flag allow_url_include Off
</IfModule>
HTACCESS;
}

function gojs_htaccess_rule_template($rule, $from = '', $to = '') {
    switch ($rule) {
        case 'force_https':
            return <<<'HTACCESS'
# 强制 HTTPS
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</IfModule>
HTACCESS;
        case 'block_sensitive':
            return <<<'HTACCESS'
# 禁止访问敏感文件
<FilesMatch "^\.">
    Require all denied
</FilesMatch>
<FilesMatch "\.(log|sql|bak|backup|ini|conf|config|sh|bash)$">
    Require all denied
</FilesMatch>
<Files "config.php">
    Require all denied
</Files>
<DirectoryMatch "^.*/\.gojs/">
    Require all denied
</DirectoryMatch>
HTACCESS;
        case 'prevent_hotlink':
            return <<<'HTACCESS'
# 防盗链
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP_REFERER} !^$
    RewriteCond %{HTTP_REFERER} !^https?://(www\.)?%{HTTP_HOST}/ [NC]
    RewriteRule \.(jpg|jpeg|png|gif|bmp|webp|svg|css|js)$ - [F,NC]
</IfModule>
HTACCESS;
        case 'redirect_301':
            $from_clean = ltrim($from, '/');
            $to_clean = $to;
            return "# 301 重定向\nRedirect 301 /" . $from_clean . " " . $to_clean;
        case 'gzip_compress':
            return <<<'HTACCESS'
# Gzip 压缩
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/css text/xml application/xml application/rss+xml application/javascript application/x-javascript application/json image/svg+xml
</IfModule>
HTACCESS;
        case 'browser_cache':
            return <<<'HTACCESS'
# 浏览器缓存
<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 month"
    ExpiresByType image/jpeg "access plus 1 month"
    ExpiresByType image/png "access plus 1 month"
    ExpiresByType image/gif "access plus 1 month"
    ExpiresByType image/webp "access plus 1 month"
    ExpiresByType image/svg+xml "access plus 1 month"
    ExpiresByType text/css "access plus 1 week"
    ExpiresByType application/javascript "access plus 1 week"
    ExpiresByType text/javascript "access plus 1 week"
    ExpiresByType application/font-woff "access plus 1 year"
    ExpiresByType application/font-woff2 "access plus 1 year"
    ExpiresByType application/x-font-ttf "access plus 1 year"
    ExpiresByType font/opentype "access plus 1 year"
</IfModule>
<IfModule mod_headers.c>
    <FilesMatch "\.(css|js|woff|woff2|ttf|eot|otf|jpg|jpeg|png|gif|webp|svg)$">
        Header set Cache-Control "public, max-age=604800"
    </FilesMatch>
</IfModule>
HTACCESS;
        case 'block_dir_browsing':
            return <<<'HTACCESS'
# 禁止目录浏览
Options -Indexes
HTACCESS;
        default:
            return '';
    }
}

function gojs_api_htaccess() {
    $method = gojs_get_method();

    $safe_path = gojs_safe_path('.htaccess');
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    if ($method === 'GET') {
        $exists = is_file($safe_path);
        $content = '';
        if ($exists) {
            $content = @file_get_contents($safe_path);
            if ($content === false) {
                $content = '';
            }
        } else {
            $content = gojs_htaccess_default_content();
        }

        $writable = $exists ? is_writable($safe_path) : is_writable(dirname($safe_path));

        gojs_json_response(array(
            'content' => $content,
            'path' => '.htaccess',
            'writable' => $writable,
            'exists' => $exists,
        ));
    } elseif ($method === 'PUT') {
        $content = gojs_get_param('content', '');
        if (!is_string($content)) {
            $content = '';
        }

        gojs_ensure_not_protected($safe_path, '修改');

        if (file_exists($safe_path) && !is_writable($safe_path)) {
            gojs_json_response(null, array(
                'code' => 'not_writable',
                'message' => '文件不可写',
            ), 403);
        }

        if (!file_exists($safe_path) && !is_writable(dirname($safe_path))) {
            gojs_json_response(null, array(
                'code' => 'not_writable',
                'message' => '目录不可写',
            ), 403);
        }

        $result = @file_put_contents($safe_path, $content, LOCK_EX);
        if ($result === false) {
            gojs_json_response(null, array(
                'code' => 'write_failed',
                'message' => '写入文件失败',
            ), 500);
        }

        gojs_json_response(array('success' => true));
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}

function gojs_api_htaccess_generate() {
    $rules = gojs_get_param('rules', array());
    if (!is_array($rules)) {
        $rules = array();
    }

    $body = gojs_get_body();
    $redirect_from = isset($body['from']) ? (string)$body['from'] : '';
    $redirect_to = isset($body['to']) ? (string)$body['to'] : '';

    $supported_rules = array(
        'force_https',
        'block_sensitive',
        'prevent_hotlink',
        'redirect_301',
        'gzip_compress',
        'browser_cache',
        'block_dir_browsing',
    );

    $valid_rules = array();
    foreach ($rules as $rule) {
        if (in_array($rule, $supported_rules, true)) {
            $valid_rules[] = $rule;
        }
    }

    $sections = array();
    foreach ($valid_rules as $rule) {
        $section = gojs_htaccess_rule_template($rule, $redirect_from, $redirect_to);
        if ($section !== '') {
            $sections[] = $section;
        }
    }

    $content = '';
    if (!empty($sections)) {
        $content = "# Go.js .htaccess 规则生成\n# 生成时间: " . date('Y-m-d H:i:s') . "\n\n";
        $content .= implode("\n\n", $sections);
        $content .= "\n";
    }

    gojs_json_response(array(
        'content' => $content,
        'rules' => $valid_rules,
    ));
}

function gojs_api_htaccess_reset() {
    $safe_path = gojs_safe_path('.htaccess');
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '重置');

    if (file_exists($safe_path) && !is_writable($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_writable',
            'message' => '文件不可写',
        ), 403);
    }

    if (!file_exists($safe_path) && !is_writable(dirname($safe_path))) {
        gojs_json_response(null, array(
            'code' => 'not_writable',
            'message' => '目录不可写',
        ), 403);
    }

    $default_content = gojs_htaccess_default_content();

    $result = @file_put_contents($safe_path, $default_content, LOCK_EX);
    if ($result === false) {
        gojs_json_response(null, array(
            'code' => 'write_failed',
            'message' => '写入文件失败',
        ), 500);
    }

    gojs_json_response(array(
        'success' => true,
        'content' => $default_content,
    ));
}
