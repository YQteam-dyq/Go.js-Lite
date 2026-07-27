<?php

define('VERSION', '1.0.0');
define('ROOT', dirname(__FILE__));
define('CONFIG_DIR', ROOT . '/.gojs');
define('CONFIG_FILE', CONFIG_DIR . '/config.php');
define('AUTH_LOG', CONFIG_DIR . '/auth.log');
define('DB_CONNECTIONS_FILE', CONFIG_DIR . '/db_connections.json');

$config = array();
$installed = false;
$root_path = ROOT;
$capabilities = null;

gojs_init();

function gojs_init() {
    global $config, $installed, $root_path;

    set_error_handler('gojs_error_handler');
    set_exception_handler('gojs_exception_handler');

    ini_set('display_errors', '0');
    error_reporting(E_ALL);

    if (session_status() == PHP_SESSION_NONE) {
        session_set_cookie_params(array(
            'httponly' => 1,
            'samesite' => 'Lax',
        ));
        session_start();
    }

    if (file_exists(CONFIG_FILE)) {
        $config = include CONFIG_FILE;
        if (is_array($config)) {
            $installed = !empty($config['installed']);
            if (!empty($config['root_path']) && is_dir($config['root_path'])) {
                $root_path = rtrim($config['root_path'], '/');
            }
        }
    }

    gojs_dispatch();
}

function gojs_error_handler($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
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
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status_code);
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
            $api = substr($uri, 5); 
        } elseif ($uri && $uri === '/api') {
            $api = '';
        }
    }

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
        case 'system':
            gojs_api_system();
            break;
        case 'system/processes':
            gojs_api_processes();
            break;
        case 'system/cron':
            gojs_api_cron();
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
    $ip = '';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($ips[0]);
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return $ip ? $ip : 'unknown';
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

    $capabilities = gojs_detect_capabilities();
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

function gojs_is_protected_path($full_path) {
    $real_path = rtrim(str_replace('\\', '/', realpath($full_path) ?: $full_path), '/');
    $gojs_dir = rtrim(str_replace('\\', '/', CONFIG_DIR), '/');
    $index_file = str_replace('\\', '/', ROOT . '/index.php');

    if ($real_path === $gojs_dir || strpos($real_path, $gojs_dir . '/') === 0) {
        return true;
    }

    if ($real_path === $index_file) {
        return true;
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

    $relative_path = ltrim($relative_path, '/');
    $relative_path = str_replace('\\', '/', $relative_path);

    $full_path = $root_path . '/' . $relative_path;

    $real_path = realpath($full_path);

    if (!$real_path) {
        $parent_dir = dirname($full_path);
        $real_parent = realpath($parent_dir);

        if (!$real_parent || strpos($real_parent, rtrim($root_path, '/')) !== 0) {
            return false;
        }

        $basename = basename($full_path);
        if (strpos($basename, '..') !== false) {
            return false;
        }

        return $real_parent . '/' . $basename;
    }

    $root_real = rtrim(realpath($root_path), '/');
    if (strpos(rtrim($real_path, '/'), $root_real) !== 0) {
        return false;
    }

    return $real_path;
}

function gojs_relative_path($abs_path) {
    global $root_path;

    $root_real = rtrim(realpath($root_path), '/');
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
                finfo_close($finfo);
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

    if (strpos($target, '/') !== false || strpos($target, '\\') !== false) {
        $target_path = $target;
    } else {
        $target_path = dirname($safe_path) . '/' . $target;
    }

    $safe_target = gojs_safe_path($target_path);
    if ($safe_target === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_target, '重命名到');

    if (file_exists($safe_target)) {
        gojs_json_response(null, array(
            'code' => 'already_exists',
            'message' => '目标路径已存在',
        ), 400);
    }

    if (!@rename($safe_path, $safe_target)) {
        gojs_json_response(null, array(
            'code' => 'rename_failed',
            'message' => '重命名失败',
        ), 500);
    }

    $rel = gojs_relative_path($safe_target);
    $info = gojs_get_file_info($safe_target, $rel);
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
            finfo_close($finfo);
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

function gojs_api_system() {
    global $root_path;

    $disk_total = @disk_total_space($root_path);
    $disk_free = @disk_free_space($root_path);
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

    $data = array(
        'diskTotal' => $disk_total ? $disk_total : 0,
        'diskFree' => $disk_free ? $disk_free : 0,
        'diskUsed' => $disk_used,
        'loadAverage' => $load_average,
        'uptime' => $uptime,
        'serverAddr' => isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : null,
        'serverName' => isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : null,
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

    $processes = array();

    $handle = @opendir('/proc');
    if (!$handle) {
        gojs_json_response(null, array(
            'code' => 'read_failed',
            'message' => '读取 /proc 失败',
        ), 500);
    }

    while (($entry = readdir($handle)) !== false) {
        if (!preg_match('/^\d+$/', $entry)) {
            continue;
        }

        $pid = (int)$entry;
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

        $processes[] = array(
            'pid' => $pid,
            'name' => $name,
            'cmdline' => $cmdline,
            'cpu' => 0,
            'mem' => $vm_rss,
        );
    }
    closedir($handle);

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
        $mysqli = @new mysqli($host, $username, $password, $database, $port);
        if ($mysqli->connect_error) {
            return array(
                'success' => false,
                'error' => $mysqli->connect_error,
            );
        }
        $mysqli->set_charset('utf8mb4');
        return array(
            'success' => true,
            'type' => 'mysqli',
            'connection' => $mysqli,
        );
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
