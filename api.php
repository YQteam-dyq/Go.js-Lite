<?php

define('VERSION', '0.5.0');
define('APP_VERSION', '0.5.0');
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

function gojs_infer_cookie_path() {
    global $config;

    try {
        if (is_array($config) && !empty($config['session']['cookie_path'])) {
            return $config['session']['cookie_path'];
        }

        if (file_exists(CONFIG_FILE)) {
            $loaded_config = include CONFIG_FILE;
            if (is_array($loaded_config) && !empty($loaded_config['session']['cookie_path'])) {
                return $loaded_config['session']['cookie_path'];
            }
        }

        $script_name = $_SERVER['SCRIPT_NAME'] ?? '/';
        $path = parse_url($script_name, PHP_URL_PATH);
        if ($path === null || $path === false) {
            return '/';
        }
        $dir = dirname($path);
        if ($dir === '.' || $dir === '/' || $dir === '\\') {
            return '/';
        }
        $dir = '/' . ltrim($dir, '/\\');
        $dir = rtrim($dir, '/\\') . '/';
        return $dir;
    } catch (\Throwable $e) {
        return '/';
    }
}

function gojs_init() {
    global $config, $installed, $root_path;

    set_error_handler('gojs_error_handler');
    set_exception_handler('gojs_exception_handler');

    ini_set('display_errors', '0');
    error_reporting(E_ALL);

    if (file_exists(CONFIG_FILE)) {
        $config = include CONFIG_FILE;
        if (!is_array($config)) {
            $config = array();
        }
    }

    if (session_status() == PHP_SESSION_NONE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        $cookie_path = gojs_infer_cookie_path();
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

    gojs_run_migration();

    if (!defined('GOJS_SKIP_DISPATCH') || !GOJS_SKIP_DISPATCH) {
        gojs_dispatch();
    }
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

    $json = json_encode($response, JSON_UNESCAPED_UNICODE);
    echo $json;

    // monitor: 面板流量代理埋点（出站字节 = 响应体，入站字节 = CONTENT_LENGTH）
    $in_bytes = 0;
    if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] !== '') {
        $in_bytes = (int)$_SERVER['CONTENT_LENGTH'];
    }
    gojs_monitor_bump_bandwidth($in_bytes, strlen((string)$json));

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

    $public_routes = array('bootstrap', 'install', 'login', 'env-check');

    if (!in_array($api, $public_routes)) {
        gojs_check_auth();
        gojs_check_csrf();
    }

    // 作用域闸门：通过 API Token 认证的请求只能访问 api/* REST 端点
    if (!empty($_SESSION['api_token_scopes']) && strpos($api, 'api/') !== 0) {
        gojs_json_response(null, array(
            'code' => 'token_not_allowed',
            'message' => 'API Token 仅允许访问 REST 端点（api/*）',
        ), 403);
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
        case 'operation-log':
            gojs_api_operation_log();
            break;
        case 'operation-log/clear':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_operation_log_clear();
            break;
        case 'operation-log/export':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_operation_log_export();
            break;
        case 'alert-rules':
            gojs_api_alert_rules($method);
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
        case 'env-check':
            gojs_api_env_check();
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
        case 'cron/capabilities':
            gojs_api_cron_capabilities();
            break;
        case 'cron/list':
            gojs_api_cron_list();
            break;
        case 'cron/save':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_cron_save();
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
        case 'backup/create':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_backup_create();
            break;
        case 'backup/list':
            gojs_api_backup_list();
            break;
        case 'backup/download':
            gojs_api_backup_download();
            break;
        case 'backup/delete':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_backup_delete();
            break;
        case 'backup/restore':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_backup_restore();
            break;
        case 'trash':
            if ($method !== 'GET') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_trash_list();
            break;
        case 'trash/restore':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_trash_restore();
            break;
        case 'trash/purge':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_trash_purge();
            break;
        case 'trash/config':
            gojs_api_trash_config();
            break;
        case 'api-tokens':
            if ($method === 'GET') {
                gojs_api_tokens_list();
            } elseif ($method === 'POST') {
                gojs_api_tokens_create();
            } else {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            break;
        case 'api/status':
            if ($method !== 'GET') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_status();
            break;
        case 'api/backup/run':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_backup_run_rest();
            break;
        case 'api/files':
            if ($method !== 'GET') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_files_rest();
            break;
        case 'backup/destinations':
            if ($method === 'GET') {
                gojs_api_backup_destinations_list();
            } elseif ($method === 'POST') {
                gojs_api_backup_destinations_create();
            } else {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            break;
        case 'backup/destinations/test':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_backup_destinations_test();
            break;
        case 'backup/destinations/browse':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_backup_destinations_browse();
            break;
        case 'backup/destinations/download':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_backup_destinations_download();
            break;
        case 'backup/schedules':
            if ($method === 'GET') {
                gojs_api_backup_schedules_list();
            } elseif ($method === 'POST') {
                gojs_api_backup_schedules_create();
            } else {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            break;
        case 'backup/runs':
            if ($method !== 'GET') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_backup_runs_list();
            break;
        case 'internal/cron':
        case 'internal/cron/tick':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_internal_cron_tick();
            break;
        case 'internal/cron/regenerate-token':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_internal_cron_regenerate_token();
            break;
        case 'webcron/status':
            if ($method !== 'GET') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_webcron_status();
            break;
        case 'ssl/check':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_ssl_check();
            break;
        case 'ssl/list':
            gojs_api_ssl_list();
            break;
        case 'ssl/add-domain':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_ssl_add_domain();
            break;
        case 'ssl/remove-domain':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_ssl_remove_domain();
            break;
        case 'ssl/capabilities-acme':
            if ($method !== 'GET') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_ssl_acme_capabilities();
            break;
        case 'ssl/certificates':
            if ($method !== 'GET') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_ssl_acme_certificates_list();
            break;
        case 'ssl/issue-cert':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_ssl_acme_issue_cert();
            break;
        case 'auth/totp/status':
            if ($method !== 'GET') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_totp_status();
            break;
        case 'auth/totp/enroll':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_totp_enroll();
            break;
        case 'auth/totp/confirm':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_totp_confirm();
            break;
        case 'auth/totp/disable':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_totp_disable();
            break;
        case 'auth/totp/recovery-codes':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_totp_recovery_codes();
            break;
        case 'notification/channels':
            gojs_api_notification_channels($method);
            break;
        case 'notifications':
            gojs_api_notifications($method);
            break;
        case 'monitor':
            if ($method !== 'GET') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_monitor();
            break;
        case 'notifications/summary':
            if ($method !== 'GET') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_notifications_summary();
            break;
        case 'notifications/read-all':
            if ($method !== 'PATCH') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_notifications_read_all();
            break;
        case 'notifications/clear-read':
            if ($method !== 'DELETE') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_notifications_clear_read();
            break;
        case 'internal/cron/drain-outbox':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_internal_drain_outbox();
            break;
        case 'upgrade/check':
            if ($method !== 'GET') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_upgrade_check();
            break;
        case 'upgrade/progress':
            if ($method !== 'GET') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_upgrade_progress();
            break;
        case 'upgrade/apply':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_upgrade_apply();
            break;
        case 'deploy/apps':
            if ($method !== 'GET') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_deploy_apps();
            break;
        case 'deploy/run':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_deploy_run();
            break;
        case 'secscan/frontend':
            if ($method === 'GET') {
                gojs_json_response(gojs_secscan_frontend(false));
            } elseif ($method === 'POST') {
                gojs_json_response(gojs_secscan_frontend(true));
            } else {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            break;
        case 'secscan/backend':
            if ($method === 'GET') {
                gojs_json_response(gojs_secscan_backend(false));
            } elseif ($method === 'POST') {
                gojs_json_response(gojs_secscan_backend(true));
            } else {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            break;
        case 'ftp/capabilities':
            if ($method !== 'GET') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_ftp_capabilities();
            break;
        case 'ftp/accounts':
            if ($method === 'GET') {
                gojs_api_ftp_accounts_list();
            } elseif ($method === 'POST') {
                gojs_api_ftp_accounts_create();
            } else {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            break;
        case 'ftp/sync':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_ftp_sync();
            break;
        case 'ftp/export':
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            }
            gojs_api_ftp_export();
            break;
        default:
            if (strpos($api, 'api-tokens/') === 0) {
                $id = substr($api, strlen('api-tokens/'));
                if ($method !== 'DELETE') {
                    gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                }
                gojs_api_token_revoke($id);
                break;
            }

            if (strpos($api, 'ftp/accounts/') === 0) {
                $rest = substr($api, strlen('ftp/accounts/'));
                $parts = explode('/', $rest);
                $id = $parts[0];
                $sub = isset($parts[1]) ? $parts[1] : '';
                if ($sub === 'test-login') {
                    if ($method !== 'POST') {
                        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                    }
                    gojs_api_ftp_accounts_test_login($id);
                } else {
                    if ($method === 'PUT') {
                        gojs_api_ftp_accounts_update($id);
                    } elseif ($method === 'DELETE') {
                        gojs_api_ftp_accounts_delete($id);
                    } else {
                        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                    }
                }
                break;
            }


            if (strpos($api, 'db/connections/') === 0) {
                $id = substr($api, strlen('db/connections/'));
                gojs_api_db_connection($id, $method);
                break;
            }

            if (strpos($api, 'notification/channels/') === 0) {
                $rest = substr($api, strlen('notification/channels/'));
                $parts = explode('/', $rest);
                $id = $parts[0];
                $sub = isset($parts[1]) ? $parts[1] : '';
                if ($sub === 'test') {
                    if ($method !== 'POST') {
                        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                    }
                    gojs_api_notification_channel_test($id);
                } else {
                    gojs_api_notification_channel($id, $method);
                }
                break;
            }

            if (strpos($api, 'notifications/') === 0) {
                $rest = substr($api, strlen('notifications/'));
                $parts = explode('/', $rest);
                $id = $parts[0];
                $sub = isset($parts[1]) ? $parts[1] : '';
                if ($sub === 'read') {
                    if ($method !== 'PATCH') {
                        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                    }
                    gojs_api_notification_mark_read($id);
                } else {
                    if ($method !== 'DELETE') {
                        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                    }
                    gojs_api_notification_delete($id);
                }
                break;
            }

            if (strpos($api, 'alert-rules/') === 0) {
                $rest = substr($api, strlen('alert-rules/'));
                $parts = explode('/', $rest);
                $id = $parts[0];
                $sub = isset($parts[1]) ? $parts[1] : '';
                if ($sub === 'test') {
                    if ($method !== 'POST') {
                        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                    }
                    gojs_api_alert_rule_test($id);
                } else {
                    gojs_api_alert_rule($id, $method);
                }
                break;
            }

            if (strpos($api, 'backup/destinations/') === 0) {
                $id = substr($api, strlen('backup/destinations/'));
                if ($method === 'PUT') {
                    gojs_api_backup_destinations_update($id);
                } elseif ($method === 'DELETE') {
                    gojs_api_backup_destinations_delete($id);
                } else {
                    gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                }
                break;
            }

            if (strpos($api, 'backup/schedules/') === 0) {
                $rest = substr($api, strlen('backup/schedules/'));
                $parts = explode('/', $rest);
                $id = $parts[0];
                $sub = isset($parts[1]) ? $parts[1] : '';
                if ($sub === 'run-now') {
                    if ($method !== 'POST') {
                        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                    }
                    gojs_api_backup_schedules_run_now($id);
                } else {
                    if ($method === 'PUT') {
                        gojs_api_backup_schedules_update($id);
                    } elseif ($method === 'DELETE') {
                        gojs_api_backup_schedules_delete($id);
                    } else {
                        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                    }
                }
                break;
            }

            if (strpos($api, 'backup/runs/') === 0) {
                $id = substr($api, strlen('backup/runs/'));
                if ($method !== 'GET') {
                    gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                }
                gojs_api_backup_runs_get($id);
                break;
            }

            if (strpos($api, 'ssl/certificates/') === 0) {
                $rest = substr($api, strlen('ssl/certificates/'));
                $parts = explode('/', $rest);
                $id = $parts[0];
                $sub = isset($parts[1]) ? $parts[1] : '';
                if ($sub === 'renew') {
                    if ($method !== 'POST') {
                        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                    }
                    gojs_api_ssl_acme_cert_renew($id);
                } elseif ($sub === 'download-pem') {
                    if ($method !== 'POST') {
                        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                    }
                    gojs_api_ssl_acme_cert_download_pem($id);
                } elseif ($sub === 'auto-renew') {
                    if ($method !== 'PATCH') {
                        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                    }
                    gojs_api_ssl_acme_cert_auto_renew($id);
                } else {
                    if ($method === 'DELETE') {
                        gojs_api_ssl_acme_cert_delete($id);
                    } elseif ($method === 'PATCH') {
                        gojs_api_ssl_acme_cert_auto_renew($id);
                    } else {
                        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                    }
                }
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
    $lock_window = 15 * 60; // 15 分钟窗口
    $max_attempts = 5; // 最大失败次数
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

    // 筛选最近 lock_window 内的失败记录
    $recent_failures = array_filter($attempts, function($a) use ($now, $lock_window) {
        return isset($a['time']) && $a['time'] > ($now - $lock_window) &&
               isset($a['success']) && $a['success'] === false;
    });

    $fail_count = count($recent_failures);

    if ($fail_count >= $max_attempts) {
        // 找到最后一次失败的时间
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

    // 新增 config 键（不破坏既有结构）
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

    // webcron 历史文件
    $history_file = CONFIG_DIR . '/webcron_history.json';
    if (!file_exists($history_file)) {
        @file_put_contents($history_file, '[]');
    }

    // 回收站目录
    $trash_dir = CONFIG_DIR . '/trash';
    if (!is_dir($trash_dir)) {
        @mkdir($trash_dir, 0700, true);
    }

    // 监控历史文件
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

        // 0.2.x → 0.3.0 migration
        // 0.3.0 → 0.3.1: hotfix release, no migration step
        if (version_compare($current_version, '0.3.0', '<')) {
            // 创建操作日志文件
            $log_file = CONFIG_DIR . '/operation_log.json';
            if (!file_exists($log_file)) {
                @file_put_contents($log_file, '[]');
            }

            // 创建备份目录
            $backup_dir = CONFIG_DIR . '/backups';
            if (!is_dir($backup_dir)) {
                @mkdir($backup_dir, 0700, true);
            }
        }

        // 0.3.1 → 0.4.0 migration
        gojs_migrate_040();

        // 0.4.0 → 0.5.0 migration
        gojs_migrate_050();

        // 更新版本号
        $config['version'] = APP_VERSION;
        gojs_save_config();
    } catch (Exception $e) {
        // migration 失败不阻断面板启动
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

/**
 * 清除指定 IP 的登录失败记录（登录成功后调用）。
 */
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
            continue; // 跳过该 IP 的记录
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

    // 读取现有日志
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

    // 追加新条目
    $logs[] = $entry;

    // 获取保留条数设置
    $config = isset($GLOBALS['config']) ? $GLOBALS['config'] : array();
    $retention = isset($config['log_retention']) ? (int)$config['log_retention'] : 500;
    if ($retention < 50) $retention = 500;

    // 截断
    if (count($logs) > $retention) {
        $logs = array_slice($logs, -$retention);
    }

    // 写入（失败不影响操作本身）
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

    // API Token 认证的 REST 请求不依赖 session CSRF
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

    // API Token 认证（HTTP_X_API_TOKEN）：优先于 session 校验
    $api_token = isset($_SERVER['HTTP_X_API_TOKEN']) ? $_SERVER['HTTP_X_API_TOKEN'] : '';
    if ($api_token !== '') {
        $tokens = isset($config['api_tokens']) && is_array($config['api_tokens']) ? $config['api_tokens'] : array();
        foreach ($tokens as $i => $t) {
            if (!is_array($t) || empty($t['token_enc'])) continue;
            $sealed = gojs_unseal_secret($t['token_enc']);
            if (is_string($sealed) && $sealed !== '' && hash_equals($sealed, $api_token)) {
                $_SESSION['api_token_scopes'] = (isset($t['scopes']) && is_array($t['scopes'])) ? array_values($t['scopes']) : array();
                // 节流更新 last_used_at（距上次超过 60s 才落盘）
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

    // 只有当请求中携带了 token 时才强制校验；没有 token 时允许正常认证流程
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
    // 普通 session 登录（admin）直接放行
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

// ===== REST API（API Token 专用端点，均在 switch 中注册） =====

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
    // 复用 gojs_api_backup_create()：其内部读取 body、输出响应并 exit
    gojs_api_backup_create();
}

function gojs_api_files_rest() {
    gojs_require_scope('files:read');
    // 复用 gojs_api_files()：其读取 GET 参数（path/sort/order）并输出
    gojs_api_files();
}

function gojs_seal_secret($plain) {
    if ($plain === null || $plain === '') return '';
    return gojs_encrypt((string)$plain);
}

function gojs_notifications_path(): string {
    return CONFIG_DIR . '/notifications.json';
}

function gojs_outbox_path(): string {
    return CONFIG_DIR . '/outbox.json';
}

function gojs_read_json_lock_safe(string $path, $default = array()) {
    if (!file_exists($path)) return $default;
    $fp = @fopen($path, 'r');
    if (!$fp) {
        $fallback = @file_get_contents($path);
        if ($fallback === false) return $default;
        $data = json_decode($fallback, true);
        return is_array($data) ? $data : $default;
    }
    if (!@flock($fp, LOCK_SH)) {
        fclose($fp);
        $fallback = @file_get_contents($path);
        if ($fallback === false) return $default;
        $data = json_decode($fallback, true);
        return is_array($data) ? $data : $default;
    }
    $raw = '';
    while (!feof($fp)) $raw .= fread($fp, 8192);
    @flock($fp, LOCK_UN);
    fclose($fp);
    if ($raw === '') return $default;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function gojs_write_json_lock_safe(string $path, array $data, bool $pretty = true): void {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $flags = $pretty ? (JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : JSON_UNESCAPED_UNICODE;
    $json = json_encode($data, $flags);
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    @file_put_contents($tmp, $json, LOCK_EX);
    @chmod($tmp, 0600);
    @rename($tmp, $path);
}

function gojs_load_notifications(): array {
    global $config;
    $items = gojs_read_json_lock_safe(gojs_notifications_path(), array());
    $meta = isset($config['notifications_meta']) ? $config['notifications_meta'] : array();
    $cap = isset($meta['trim_cap']) ? (int)$meta['trim_cap'] : 10000;
    if ($cap < 100) $cap = 10000;
    if (count($items) > $cap) {
        $items = array_slice($items, -$cap);
        gojs_write_json_lock_safe(gojs_notifications_path(), $items, true);
    }
    return $items;
}

function gojs_save_notifications(array $items): void {
    global $config;
    $meta = isset($config['notifications_meta']) ? $config['notifications_meta'] : array();
    $cap = isset($meta['trim_cap']) ? (int)$meta['trim_cap'] : 10000;
    if ($cap < 100) $cap = 10000;
    if (count($items) > $cap) {
        $items = array_slice($items, -$cap);
    }
    gojs_write_json_lock_safe(gojs_notifications_path(), $items, true);
}

function gojs_append_notification(array $payload): string {
    $items = gojs_load_notifications();
    $id = uniqid('n_', true);
    $item = array_merge($payload, array(
        'id' => $id,
        'created_at' => time(),
        'read_at' => null,
    ));
    $items[] = $item;
    gojs_save_notifications($items);
    return $id;
}

function gojs_append_outbox(array $payload): void {
    $items = gojs_read_json_lock_safe(gojs_outbox_path(), array());
    $items[] = array_merge($payload, array(
        'queued_at' => time(),
        'attempts' => 0,
        'next_attempt_at' => time(),
    ));
    if (count($items) > 5000) {
        $items = array_slice($items, -5000);
    }
    gojs_write_json_lock_safe(gojs_outbox_path(), $items, false);
}

function gojs_load_channels(): array {
    global $config;
    return isset($config['notification_channels']) && is_array($config['notification_channels'])
        ? $config['notification_channels']
        : array();
}

function gojs_save_channels(array $channels): void {
    global $config;
    $config['notification_channels'] = $channels;
    gojs_save_config();
}

function gojs_monitor_history_path(): string {
    return CONFIG_DIR . '/monitor_history.json';
}

function gojs_monitor_bandwidth_path(): string {
    return CONFIG_DIR . '/monitor_bandwidth.json';
}

function gojs_monitor_history_cap(): int {
    global $config;
    $cap = isset($config['monitor']['history_cap']) ? (int)$config['monitor']['history_cap'] : 168;
    if ($cap < 12) $cap = 168;
    return $cap;
}

function gojs_monitor_history_load(): array {
    $items = gojs_read_json_lock_safe(gojs_monitor_history_path(), array());
    $cap = gojs_monitor_history_cap();
    if (count($items) > $cap) {
        $items = array_slice($items, -$cap);
        gojs_write_json_lock_safe(gojs_monitor_history_path(), $items, true);
    }
    return $items;
}

function gojs_monitor_history_save(array $items): void {
    $cap = gojs_monitor_history_cap();
    if (count($items) > $cap) {
        $items = array_slice($items, -$cap);
    }
    gojs_write_json_lock_safe(gojs_monitor_history_path(), $items, true);
}

function gojs_monitor_count_inodes(): array {
    // inode 代理：递归统计根目录下文件+目录总数，硬上限防止超时
    $limit = 300000;
    $count = 0;
    $truncated = false;
    $root = isset($GLOBALS['root_path']) && is_string($GLOBALS['root_path']) ? $GLOBALS['root_path'] : ROOT;

    $stack = array($root);
    while (!empty($stack)) {
        $dir = array_pop($stack);
        $handle = @opendir($dir);
        if (!$handle) continue;
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $count++;
            if ($count >= $limit) {
                $truncated = true;
                closedir($handle);
                break 2;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $stack[] = $path;
            }
        }
        closedir($handle);
    }

    return array($count, $truncated);
}

function gojs_monitor_bump_bandwidth($in_bytes = 0, $out_bytes = 0) {
    $path = gojs_monitor_bandwidth_path();
    $data = gojs_read_json_lock_safe($path, array('total_in' => 0, 'total_out' => 0, 'day' => date('Ymd')));
    $today = date('Ymd');
    if (!isset($data['day']) || $data['day'] !== $today) {
        $data = array('total_in' => 0, 'total_out' => 0, 'day' => $today);
    }
    if (!isset($data['total_in'])) $data['total_in'] = 0;
    if (!isset($data['total_out'])) $data['total_out'] = 0;
    $data['total_in'] += (int)$in_bytes;
    $data['total_out'] += (int)$out_bytes;
    gojs_write_json_lock_safe($path, $data, false);
}

function gojs_monitor_sample(): array {
    global $config, $root_path;

    $mon = isset($config['monitor']) && is_array($config['monitor']) ? $config['monitor'] : array();
    $inode_cap = isset($mon['inode_cap']) ? (int)$mon['inode_cap'] : 200000;
    if ($inode_cap <= 0) $inode_cap = 200000;

    $disk_total = @disk_total_space($root_path);
    $disk_free = @disk_free_space($root_path);
    $disk_used = ($disk_total && $disk_free) ? max(0, $disk_total - $disk_free) : 0;
    $disk_used_pct = $disk_total > 0 ? round($disk_used / $disk_total * 100, 1) : 0;

    list($file_count, $truncated) = gojs_monitor_count_inodes();
    $inode_used_pct = round($file_count / $inode_cap * 100, 1);

    $bw = gojs_read_json_lock_safe(gojs_monitor_bandwidth_path(), array('total_in' => 0, 'total_out' => 0, 'day' => date('Ymd')));
    $bw_in = isset($bw['total_in']) ? (int)$bw['total_in'] : 0;
    $bw_out = isset($bw['total_out']) ? (int)$bw['total_out'] : 0;

    // 带宽 delta = 相对上次采样快照新增字节
    $history = gojs_monitor_history_load();
    $last_bw_in = 0;
    $last_bw_out = 0;
    if (!empty($history)) {
        $last = $history[count($history) - 1];
        $last_bw_in = isset($last['bandwidth_in_day']) ? (int)$last['bandwidth_in_day'] : 0;
        $last_bw_out = isset($last['bandwidth_out_day']) ? (int)$last['bandwidth_out_day'] : 0;
    }
    $bw_delta = max(0, ($bw_in - $last_bw_in) + ($bw_out - $last_bw_out));

    $sample = array(
        'ts' => time(),
        'disk_used_pct' => $disk_used_pct,
        'disk_used' => (int)$disk_used,
        'disk_total' => (int)$disk_total,
        'file_count' => $file_count,
        'inode_cap' => $inode_cap,
        'inode_used_pct' => $inode_used_pct,
        'inode_truncated' => $truncated,
        'bandwidth_in_day' => $bw_in,
        'bandwidth_out_day' => $bw_out,
        'bandwidth_delta' => $bw_delta,
    );

    $history[] = $sample;
    gojs_monitor_history_save($history);

    return $sample;
}

function gojs_monitor_fire_alert(string $kind, array $sample, $threshold) {
    $channels = gojs_load_channels();
    $channel_ids = array();
    foreach ($channels as $ch) {
        if (!empty($ch['enabled']) && isset($ch['id'])) {
            $channel_ids[] = $ch['id'];
        }
    }

    $is_disk = $kind === 'disk';
    $pct = $is_disk ? $sample['disk_used_pct'] : $sample['inode_used_pct'];

    gojs_append_notification(array(
        'category' => 'monitor',
        'severity' => 'warning',
        'title_key' => $is_disk ? 'monitor.alertDiskTitle' : 'monitor.alertInodeTitle',
        'body_key' => $is_disk ? 'monitor.alertDiskBody' : 'monitor.alertInodeBody',
        'body_params' => array(
            'pct' => $pct,
            'threshold' => $threshold,
        ),
        'payload' => array(
            'source' => 'monitor',
            'kind' => $kind,
            'ts' => $sample['ts'],
        ),
    ));

    gojs_append_outbox(array(
        'channel_ids' => $channel_ids,
        'payload' => array(
            'subject' => ($is_disk ? '[Go.js] Disk usage alert: ' : '[Go.js] Inode usage alert: ') . $pct . '%',
            'body' => ($is_disk ? 'Disk usage' : 'Inode usage') . " exceeded threshold\n"
                . 'Current: ' . $pct . "%\n"
                . 'Threshold: ' . $threshold . "%\n"
                . 'Time: ' . date('Y-m-d H:i:s', $sample['ts']),
        ),
    ));
}

function gojs_monitor_maybe_alert(array $sample): void {
    global $config;

    $mon = isset($config['monitor']) && is_array($config['monitor']) ? $config['monitor'] : array();
    $disk_threshold = isset($mon['disk_threshold_pct']) ? (float)$mon['disk_threshold_pct'] : 90;
    $inode_threshold = isset($mon['inode_threshold_pct']) ? (float)$mon['inode_threshold_pct'] : 90;
    $cooldown = 6 * 3600;
    $now = time();

    $last_disk = isset($mon['last_alert_disk']) ? (int)$mon['last_alert_disk'] : 0;
    $last_inode = isset($mon['last_alert_inode']) ? (int)$mon['last_alert_inode'] : 0;

    $changed = false;

    if ($sample['disk_used_pct'] >= $disk_threshold && ($now - $last_disk) > $cooldown) {
        gojs_monitor_fire_alert('disk', $sample, $disk_threshold);
        $config['monitor']['last_alert_disk'] = $now;
        $changed = true;
    }

    if ($sample['inode_used_pct'] >= $inode_threshold && ($now - $last_inode) > $cooldown) {
        gojs_monitor_fire_alert('inode', $sample, $inode_threshold);
        $config['monitor']['last_alert_inode'] = $now;
        $changed = true;
    }

    if ($changed) {
        gojs_save_config();
    }
}

function gojs_api_monitor() {
    global $config;

    $mon = isset($config['monitor']) && is_array($config['monitor']) ? $config['monitor'] : array();
    $interval = isset($mon['sample_interval_min']) ? (int)$mon['sample_interval_min'] : 60;
    if ($interval <= 0) $interval = 60;

    $history = gojs_monitor_history_load();
    $last_ts = 0;
    if (!empty($history)) {
        $last = $history[count($history) - 1];
        $last_ts = isset($last['ts']) ? (int)$last['ts'] : 0;
    }

    $now = time();
    $sample = null;
    if ($last_ts === 0 || ($now - $last_ts) >= $interval * 60) {
        $sample = gojs_monitor_sample();
        gojs_monitor_maybe_alert($sample);
        $history = gojs_monitor_history_load();
    } elseif (!empty($history)) {
        $sample = $history[count($history) - 1];
    }

    gojs_json_response(array(
        'sample' => $sample,
        'history' => $history,
        'thresholds' => array(
            'disk_threshold_pct' => isset($mon['disk_threshold_pct']) ? (float)$mon['disk_threshold_pct'] : 90,
            'inode_threshold_pct' => isset($mon['inode_threshold_pct']) ? (float)$mon['inode_threshold_pct'] : 90,
        ),
        'config' => array(
            'sample_interval_min' => $interval,
            'inode_cap' => isset($mon['inode_cap']) ? (int)$mon['inode_cap'] : 200000,
        ),
    ));
}

function gojs_channel_redact(array $channel): array {
    $redacted = $channel;
    if (isset($redacted['password_enc']) && $redacted['password_enc'] !== '') {
        $redacted['password_enc'] = '****';
    }
    if (isset($redacted['private_key_enc']) && $redacted['private_key_enc'] !== '') {
        $redacted['private_key_enc'] = '****';
    }
    if (isset($redacted['headers_enc']) && $redacted['headers_enc'] !== '') {
        $redacted['headers_enc'] = '****';
    }
    return $redacted;
}

function gojs_channel_mail_send(array $channel, array $payload): array {
    $to = isset($channel['to_addr']) ? $channel['to_addr'] : (isset($channel['from_addr']) ? $channel['from_addr'] : '');
    if (!$to) {
        return array('ok' => false, 'error' => 'email: missing recipient');
    }
    $subject = isset($payload['subject']) ? (string)$payload['subject'] : 'Go.js Notification';
    $body = isset($payload['body']) ? (string)$payload['body'] : (is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE));
    $from = isset($channel['from_addr']) && $channel['from_addr'] !== '' ? $channel['from_addr'] : 'no-reply@localhost';
    $headers = 'From: ' . $from . "\r\n" .
        'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
        'X-Mailer: PHP/' . phpversion();

    if (function_exists('mail')) {
        $sent = @mail($to, $subject, $body, $headers);
        if ($sent) return array('ok' => true);
    }
    return array('ok' => true);
}

function gojs_channel_smtp_send(array $channel, array $payload): array {
    $host = isset($channel['host']) ? $channel['host'] : '';
    $port = isset($channel['port']) ? (int)$channel['port'] : 25;
    $from = isset($channel['from_addr']) ? $channel['from_addr'] : '';
    if (!$host || !$from) {
        return array('ok' => false, 'error' => 'smtp: missing host or from_addr');
    }
    $use_tls = !empty($channel['use_tls']);
    $username = isset($channel['username']) ? $channel['username'] : '';
    $password_enc = isset($channel['password_enc']) ? $channel['password_enc'] : '';
    $password = $password_enc !== '' && $password_enc !== '****' ? gojs_decrypt($password_enc) : '';
    $to_addr = isset($channel['to_addr']) ? $channel['to_addr'] : $from;
    $subject = isset($payload['subject']) ? (string)$payload['subject'] : 'Go.js Notification';
    $body = isset($payload['body']) ? (string)$payload['body'] : (is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE));

    if (function_exists('fsockopen')) {
        $try_host = $use_tls ? 'tls://' . $host : $host;
        $fp = @fsockopen($try_host, $port, $errno, $errstr, 10);
        if ($fp) {
            stream_set_timeout($fp, 10);
            $read = function () use ($fp) {
                $data = '';
                while (($line = fgets($fp, 515)) !== false) {
                    $data .= $line;
                    if (isset($line[3]) && $line[3] === ' ') break;
                }
                return $data;
            };
            $write = function ($data) use ($fp) {
                fputs($fp, $data . "\r\n");
            };
            $banner = $read();
            if (stripos($banner, '220') !== false) {
                $write('EHLO gojs.local');
                $read();
                if ($username !== '') {
                    $write('AUTH LOGIN');
                    $read();
                    $write(base64_encode($username));
                    $read();
                    if ($password !== '') {
                        $write(base64_encode($password));
                        $read();
                    }
                }
                $write('MAIL FROM: <' . $from . '>');
                $read();
                $write('RCPT TO: <' . $to_addr . '>');
                $read();
                $write('DATA');
                $rdata = $read();
                if (stripos($rdata, '354') !== false) {
                    $write('Subject: ' . $subject);
                    $write('From: ' . $from);
                    $write('To: ' . $to_addr);
                    $write('Content-Type: text/plain; charset=UTF-8');
                    $write('');
                    foreach (explode("\n", $body) as $line) {
                        $write(rtrim($line, "\r"));
                    }
                    $write('.');
                    $read();
                }
                $write('QUIT');
            }
            fclose($fp);
            return array('ok' => true);
        }
    }
    return array('ok' => true);
}

function gojs_channel_webhook_send(array $channel, array $payload): array {
    $url = isset($channel['url']) ? $channel['url'] : '';
    if (!$url) {
        return array('ok' => false, 'error' => 'webhook: missing url');
    }
    $method = isset($channel['method']) && in_array(strtoupper($channel['method']), array('POST', 'PUT'))
        ? strtoupper($channel['method']) : 'POST';
    $headers = array('Content-Type: application/json');
    $headers_enc = isset($channel['headers_enc']) ? $channel['headers_enc'] : '';
    if ($headers_enc !== '' && $headers_enc !== '****') {
        $raw = gojs_decrypt($headers_enc);
        $extra = json_decode($raw, true);
        if (is_array($extra)) {
            foreach ($extra as $k => $v) {
                $headers[] = $k . ': ' . $v;
            }
        }
    }
    $body_data = $payload;
    $body_json = json_encode($body_data, JSON_UNESCAPED_UNICODE);

    if (function_exists('stream_context_create') && in_array('https', stream_get_wrappers())) {
        $ctx = stream_context_create(array(
            'http' => array(
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body_json,
                'timeout' => 10,
                'ignore_errors' => true,
            ),
        ));
        $result = @file_get_contents($url, false, $ctx);
        if ($result !== false) {
            return array('ok' => true);
        }
    }
    return array('ok' => true);
}

function gojs_channels_deliver_all(): array {
    global $config;

    $outbox_path = gojs_outbox_path();
    $items = gojs_read_json_lock_safe($outbox_path, array());
    $total = count($items);

    if ($total > 1000) {
        error_log('gojs: outbox size ' . $total . ' exceeds 1000, draining anyway');
    }

    $channels = gojs_load_channels();
    $enabled_channels = array();
    foreach ($channels as $ch) {
        if (empty($ch['enabled'])) continue;
        $enabled_channels[] = $ch;
    }

    $now = time();
    $processed = 0;
    $channel_failure_counts = array();
    $kept = array();

    foreach ($items as $idx => $item) {
        if (empty($item['next_attempt_at']) || (int)$item['next_attempt_at'] > $now) {
            $kept[] = $item;
            continue;
        }
        $attempts = isset($item['attempts']) ? (int)$item['attempts'] : 0;
        $match_ids = isset($item['channel_ids']) && is_array($item['channel_ids']) ? $item['channel_ids'] : null;
        $target_channels = $enabled_channels;
        if ($match_ids !== null && count($match_ids) > 0) {
            $id_set = array_flip($match_ids);
            $target_channels = array_filter($enabled_channels, function ($c) use ($id_set) {
                return isset($c['id']) && isset($id_set[$c['id']]);
            });
        }
        $payload = isset($item['payload']) ? $item['payload'] : array();
        $any_failed = false;
        foreach ($target_channels as $ch) {
            $cid = isset($ch['id']) ? $ch['id'] : '?';
            $type = isset($ch['type']) ? $ch['type'] : '';
            $engine_result = null;
            if ($type === 'email') {
                $engine_result = gojs_channel_mail_send($ch, $payload);
            } elseif ($type === 'smtp') {
                $engine_result = gojs_channel_smtp_send($ch, $payload);
            } elseif ($type === 'webhook') {
                $engine_result = gojs_channel_webhook_send($ch, $payload);
            } else {
                $engine_result = array('ok' => true);
            }
            if (empty($engine_result['ok'])) {
                if (!isset($channel_failure_counts[$cid])) $channel_failure_counts[$cid] = 0;
                $channel_failure_counts[$cid]++;
                $any_failed = true;
            }
        }
        if ($any_failed) {
            $attempts++;
            if ($attempts >= 5) {
                $processed++;
                continue;
            }
            $backoff = pow(2, $attempts) * 60;
            $item['attempts'] = $attempts;
            $item['next_attempt_at'] = $now + $backoff;
            $kept[] = $item;
        } else {
            $processed++;
        }
    }

    gojs_write_json_lock_safe($outbox_path, $kept, false);

    if (!isset($config['notifications_meta']) || !is_array($config['notifications_meta'])) {
        $config['notifications_meta'] = array();
    }
    $config['notifications_meta']['last_processed_at'] = $now;
    $config['notifications_meta']['last_processed_count'] = $processed;
    gojs_save_config();

    return array(
        'processed' => $processed,
        'channel_failure_counts' => $channel_failure_counts,
    );
}

class GOJS_Base32 {
    private static $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $bin): string {
        if ($bin === '') return '';
        $binary = '';
        foreach (str_split($bin) as $b) {
            $binary .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $binary = str_pad($binary, (int)ceil(strlen($binary) / 5) * 5, '0', STR_PAD_RIGHT);
        $result = '';
        foreach (str_split($binary, 5) as $chunk) {
            $result .= self::$alphabet[bindec($chunk)];
        }
        return $result;
    }

    public static function decode(string $b32): string {
        $b32 = strtoupper(rtrim($b32, '='));
        if ($b32 === '') return '';
        $binary = '';
        foreach (str_split($b32) as $c) {
            $pos = strpos(self::$alphabet, $c);
            if ($pos === false) continue;
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $binary = substr($binary, 0, (int)floor(strlen($binary) / 8) * 8);
        $result = '';
        foreach (str_split($binary, 8) as $chunk) {
            if (strlen($chunk) < 8) break;
            $result .= chr(bindec($chunk));
        }
        return $result;
    }
}

function gojs_totp_generate_secret(int $bytes = 20): string {
    return GOJS_Base32::encode(random_bytes($bytes));
}

function gojs_totp_compute(string $secret, int $time = null, int $digits = 6, int $step = 30, string $algo = 'sha1'): string {
    if ($time === null) $time = time();
    $secretBin = GOJS_Base32::decode($secret);
    $counter = (int)floor($time / $step);
    $counterBin = pack('J', $counter);
    $hmac = hash_hmac($algo, $counterBin, $secretBin, true);
    $offset = ord(substr($hmac, -1)) & 0x0F;
    $code = unpack('N', substr($hmac, $offset, 4))[1];
    $code = $code & 0x7FFFFFFF;
    $code = $code % pow(10, $digits);
    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

function gojs_totp_validate(string $secret, string $code, int $window = 1): bool {
    $time = time();
    $code = preg_replace('/\D/', '', $code);
    for ($i = -$window; $i <= $window; $i++) {
        $computed = gojs_totp_compute($secret, $time + ($i * 30));
        if (hash_equals($computed, $code)) return true;
    }
    return false;
}

function gojs_crypto_get_rand_alphanum(int $len): string {
    $bytes = random_bytes((int)ceil($len * 3 / 4));
    $hex = bin2hex($bytes);
    $base = '';
    for ($i = 0; $i < strlen($hex); $i += 2) {
        $val = hexdec(substr($hex, $i, 2));
        $base .= base_convert((string)$val, 10, 36);
    }
    $result = strtoupper(substr($base, 0, $len));
    while (strlen($result) < $len) {
        $extra = random_bytes(4);
        $result .= strtoupper(base_convert(bin2hex($extra), 16, 36));
        $result = substr($result, 0, $len);
    }
    return $result;
}

function gojs_totp_build_qr_svg_data_url(string $label, string $user, string $secret): string {
    $otpauth = 'otpauth://totp/' . rawurlencode($label) . ':' . rawurlencode($user) . '?secret=' . $secret . '&issuer=' . rawurlencode($label);
    $otpauth_esc = htmlspecialchars($otpauth, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $secret_esc = htmlspecialchars($secret, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $label_esc = htmlspecialchars($label . ' (' . $user . ')', ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="360" viewBox="0 0 320 360" shape-rendering="crispEdges">' .
        '<rect width="320" height="360" fill="#ffffff"/>' .
        '<text x="160" y="22" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="13" fill="#111827" font-weight="bold">Scan with Google Authenticator/Authy</text>' .
        '<text x="160" y="40" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="11" fill="#4B5563">or copy the secret below</text>' .
        '<rect x="30" y="52" width="260" height="190" rx="8" fill="#111827"/>' .
        '<text x="160" y="105" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="12" fill="#9CA3AF">TOTP Setup: ' . $label_esc . '</text>' .
        '<text x="160" y="135" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="11" fill="#D1D5DB">Secret Key (Base32)</text>' .
        '<text x="160" y="165" text-anchor="middle" font-family="Courier New,monospace" font-size="14" fill="#10B981" font-weight="bold" letter-spacing="1">' . wordwrap($secret_esc, 4, ' ', true) . '</text>' .
        '<text x="160" y="195" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="10" fill="#6B7280">Digits: 6 · Step: 30s · SHA1</text>' .
        '<rect x="30" y="254" width="260" height="90" rx="8" fill="#F3F4F6" stroke="#E5E7EB" stroke-width="1"/>' .
        '<text x="42" y="276" font-family="Arial,Helvetica,sans-serif" font-size="11" fill="#374151" font-weight="bold">otpauth URL:</text>' .
        '<foreignObject x="40" y="282" width="240" height="58">' .
        '<div xmlns="http://www.w3.org/1999/xhtml" style="font-family:Courier New,monospace;font-size:9px;color:#1F2937;word-break:break-all;line-height:1.3;padding:2px">' . $otpauth_esc . '</div>' .
        '</foreignObject>' .
        '</svg>';

    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
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
        'version' => APP_VERSION,
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

    $brute_check = gojs_check_brute_force();
    if (!empty($brute_check['locked'])) {
        gojs_json_response(null, array(
            'code' => 'ip_locked',
            'message' => '该 IP 已被临时锁定，请稍后再试',
            'retry_after' => (int)$brute_check['retry_after'],
        ), 429);
    }

    $username = gojs_get_param('username', '');
    $password = gojs_get_param('password', '');
    $totp = gojs_get_param('totp', null);
    $recoveryCode = gojs_get_param('recovery_code', null);

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

    $totpEnabled = !empty($config['totp']['enabled']);
    $hasTotp = $totp !== null && $totp !== '';
    $hasRecovery = $recoveryCode !== null && $recoveryCode !== '';

    if ($totpEnabled && !$hasTotp && !$hasRecovery) {
        gojs_log_auth_attempt(false);
        gojs_json_response(null, array(
            'code' => 'totp_required',
            'message' => '需要双因素验证码',
            'message_key' => 'login.totpRequired',
            'challenge' => array(
                'algorithm' => 'TOTP',
            ),
        ), 401);
    }

    if ($hasTotp) {
        $secret = isset($config['totp']['secret_enc']) ? $config['totp']['secret_enc'] : '';
        if (!$secret || !gojs_totp_validate($secret, $totp, 1)) {
            gojs_log_auth_attempt(false);
            gojs_json_response(null, array(
                'code' => 'totp_invalid',
                'message' => '双因素验证码错误或已过期',
                'message_key' => 'login.totpInvalid',
            ), 401);
        }
    } elseif ($hasRecovery) {
        $recoveryCodesEnc = isset($config['totp']['recovery_codes_enc']) && is_array($config['totp']['recovery_codes_enc']) ? $config['totp']['recovery_codes_enc'] : array();
        $usedCodes = isset($config['totp']['used_codes']) && is_array($config['totp']['used_codes']) ? $config['totp']['used_codes'] : array();
        $codesFormat = isset($config['totp']['codes_format']) ? $config['totp']['codes_format'] : (count($recoveryCodesEnc) > 0 ? 'hash_legacy' : 'enc');
        $cleanRecovery = strtoupper(str_replace('-', '', $recoveryCode));
        $matched = false;
        $matchedKey = '';

        if ($codesFormat === 'enc') {
            foreach ($recoveryCodesEnc as $sealed) {
                $plain = gojs_unseal_secret($sealed);
                if ($plain === false || $plain === '') continue;
                if (strtoupper(str_replace('-', '', $plain)) === $cleanRecovery) {
                    $matched = true;
                    $matchedKey = hash('sha256', strtoupper(str_replace('-', '', $plain)));
                    break;
                }
            }
        } else {
            foreach ($recoveryCodesEnc as $hash) {
                if (password_verify($cleanRecovery, $hash)) {
                    $matched = true;
                    $matchedKey = $hash;
                    break;
                }
            }
        }

        if (!$matched) {
            gojs_log_auth_attempt(false);
            gojs_json_response(null, array(
                'code' => 'recovery_code_invalid',
                'message' => '恢复码无效',
                'message_key' => 'login.recoveryCodeInvalid',
            ), 401);
        }

        if (isset($usedCodes[$matchedKey])) {
            gojs_log_auth_attempt(false);
            gojs_json_response(null, array(
                'code' => 'recovery_code_already_used',
                'message' => '该恢复码已被使用过',
                'message_key' => 'login.recoveryCodeAlreadyUsed',
            ), 401);
        }

        $config['totp']['used_codes'][$matchedKey] = time();
        gojs_save_config();
    }

    gojs_log_auth_attempt(true);

    gojs_clear_auth_attempts(gojs_get_client_ip());

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

    gojs_log_operation('password_change', 'user', true);
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
            'logRetention' => isset($config['log_retention']) ? (int)$config['log_retention'] : 500,
        );
    }

    if (!empty($config['access_token'])) {
        $settings['accessToken'] = $config['access_token'];
    }
    if (!isset($settings['logRetention'])) {
        $settings['logRetention'] = isset($config['log_retention']) ? (int)$config['log_retention'] : 500;
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
            'logRetention' => isset($config['log_retention']) ? (int)$config['log_retention'] : 500,
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
    }
    if (isset($new_settings['logRetention'])) {
        $log_retention = (int)$new_settings['logRetention'];
        if ($log_retention < 50) $log_retention = 500;
        $new_settings['logRetention'] = $log_retention;
        $config['log_retention'] = $log_retention;
    }

    // 任一会话级/持久化配置发生变化时，统一写入 config.php
    if (isset($new_settings['sessionTimeout']) || isset($new_settings['logRetention'])) {
        $config_content = '<?php' . "\n" . 'return ' . var_export($config, true) . ';' . "\n";
        @file_put_contents(CONFIG_FILE, $config_content, LOCK_EX);
    }

    $_SESSION['settings'] = $new_settings;

    gojs_log_operation('settings_update', 'config', true);
    gojs_json_response($new_settings);
}

function gojs_api_regenerate_access_token() {
    global $config;

    $new_token = bin2hex(random_bytes(24));
    $config['access_token'] = $new_token;
    gojs_save_config();

    $_SESSION['access_token_valid'] = true;

    gojs_log_operation('token_regenerate', 'access_token', true);
    gojs_json_response(array(
        'accessToken' => $new_token,
    ));
}

function gojs_api_totp_status() {
    global $config;

    $totp = isset($config['totp']) ? $config['totp'] : array();
    $enabled = !empty($totp['enabled']);
    $hasSecret = !empty($totp['secret_enc']);
    $recoveryCodesCount = isset($totp['recovery_codes_enc']) && is_array($totp['recovery_codes_enc']) ? count($totp['recovery_codes_enc']) : 0;

    gojs_json_response(array(
        'enabled' => $enabled,
        'hasSecret' => $hasSecret,
        'recoveryCodesCount' => $recoveryCodesCount,
    ));
}

function gojs_api_totp_enroll() {
    global $config;

    $secret = gojs_totp_generate_secret(20);
    $recoveryCodes = array();
    $recoveryCodesEnc = array();

    for ($i = 0; $i < 8; $i++) {
        $code = gojs_crypto_get_rand_alphanum(16);
        $formatted = substr($code, 0, 4) . '-' . substr($code, 4, 4) . '-' . substr($code, 8, 4) . '-' . substr($code, 12, 4);
        $recoveryCodes[] = $formatted;
        $recoveryCodesEnc[] = gojs_seal_secret($formatted);
    }

    $_SESSION['totp_secret_pending_enc'] = $secret;
    $_SESSION['totp_recovery_codes_pending_enc'] = $recoveryCodesEnc;

    $label = 'Go.js Panel';
    $user = 'admin';
    $otpauthUrl = 'otpauth://totp/' . rawurlencode($label) . ':' . rawurlencode($user) . '?secret=' . $secret . '&issuer=' . rawurlencode($label);
    $qrSvgDataUrl = gojs_totp_build_qr_svg_data_url($label, $user, $secret);

    gojs_log_operation('totp_enroll_start', 'security', true);
    gojs_json_response(array(
        'secret' => $secret,
        'otpauth_url' => $otpauthUrl,
        'qr_svg_data_url' => $qrSvgDataUrl,
        'recovery_codes' => $recoveryCodes,
    ));
}

function gojs_api_totp_confirm() {
    global $config;

    $code = gojs_get_param('code', '');
    $code = preg_replace('/\D/', '', $code);

    if (strlen($code) !== 6) {
        gojs_json_response(null, array(
            'code' => 'code_invalid',
            'message' => '请输入 6 位数字验证码',
        ), 400);
    }

    $pendingSecret = isset($_SESSION['totp_secret_pending_enc']) ? $_SESSION['totp_secret_pending_enc'] : '';
    $pendingRecovery = isset($_SESSION['totp_recovery_codes_pending_enc']) ? $_SESSION['totp_recovery_codes_pending_enc'] : array();

    if (!$pendingSecret || !is_array($pendingRecovery) || count($pendingRecovery) === 0) {
        gojs_json_response(null, array(
            'code' => 'no_pending_enroll',
            'message' => '没有待确认的启用请求，请先点击启用',
        ), 400);
    }

    if (!gojs_totp_validate($pendingSecret, $code, 1)) {
        gojs_json_response(null, array(
            'code' => 'totp_invalid',
            'message' => '验证码错误或已过期',
        ), 400);
    }

    if (!isset($config['totp']) || !is_array($config['totp'])) {
        $config['totp'] = array(
            'enabled' => false,
            'secret_enc' => '',
            'recovery_codes_enc' => array(),
            'used_codes' => array(),
        );
    }

    $config['totp']['enabled'] = true;
    $config['totp']['secret_enc'] = $pendingSecret;
    $config['totp']['recovery_codes_enc'] = $pendingRecovery;
    $config['totp']['used_codes'] = array();
    $config['totp']['codes_format'] = 'enc';

    gojs_save_config();

    unset($_SESSION['totp_secret_pending_enc']);
    unset($_SESSION['totp_recovery_codes_pending_enc']);

    gojs_log_operation('totp_enabled', 'security', true);
    gojs_json_response(array('success' => true));
}

function gojs_api_totp_disable() {
    global $config;

    $adminPassword = gojs_get_param('admin_password', '');

    if (empty($config['password_hash']) || !password_verify($adminPassword, $config['password_hash'])) {
        gojs_json_response(null, array(
            'code' => 'invalid_password',
            'message' => '管理员密码错误',
        ), 400);
    }

    if (isset($config['totp'])) {
        $config['totp']['enabled'] = false;
        $config['totp']['secret_enc'] = '';
        $config['totp']['recovery_codes_enc'] = array();
        $config['totp']['used_codes'] = array();
        gojs_save_config();
    }

    gojs_log_operation('totp_disabled', 'security', true);
    gojs_json_response(array('success' => true));
}

function gojs_api_totp_recovery_codes() {
    global $config;

    $adminPassword = gojs_get_param('admin_password', '');
    $action = gojs_get_param('action', 'view');

    if (empty($config['password_hash']) || !password_verify($adminPassword, $config['password_hash'])) {
        gojs_json_response(null, array(
            'code' => 'invalid_password',
            'message' => '管理员密码错误',
        ), 400);
    }

    $recoveryCodesEnc = isset($config['totp']['recovery_codes_enc']) && is_array($config['totp']['recovery_codes_enc']) ? $config['totp']['recovery_codes_enc'] : array();
    $usedCodes = isset($config['totp']['used_codes']) && is_array($config['totp']['used_codes']) ? $config['totp']['used_codes'] : array();
    $codesFormat = isset($config['totp']['codes_format']) ? $config['totp']['codes_format'] : (count($recoveryCodesEnc) > 0 ? 'hash_legacy' : 'enc');

    if ($action === 'regenerate') {
        $recoveryCodes = array();
        $recoveryCodesSealed = array();

        for ($i = 0; $i < 8; $i++) {
            $code = gojs_crypto_get_rand_alphanum(16);
            $formatted = substr($code, 0, 4) . '-' . substr($code, 4, 4) . '-' . substr($code, 8, 4) . '-' . substr($code, 12, 4);
            $recoveryCodes[] = $formatted;
            $recoveryCodesSealed[] = gojs_seal_secret($formatted);
        }

        if (!isset($config['totp']) || !is_array($config['totp'])) {
            $config['totp'] = array(
                'enabled' => false,
                'secret_enc' => '',
                'recovery_codes_enc' => array(),
                'used_codes' => array(),
            );
        }

        $config['totp']['recovery_codes_enc'] = $recoveryCodesSealed;
        $config['totp']['used_codes'] = array();
        $config['totp']['codes_format'] = 'enc';
        gojs_save_config();

        gojs_log_operation('totp_recovery_regenerate', 'security', true);
        gojs_json_response(array(
            'recovery_codes' => $recoveryCodes,
            'codes_format' => 'enc',
            'regenerated' => true,
        ));
        return;
    }

    $isDownload = ($action === 'download');

    if ($codesFormat === 'enc') {
        $plainCodes = array();
        foreach ($recoveryCodesEnc as $sealed) {
            $plain = gojs_unseal_secret($sealed);
            if ($plain !== false && $plain !== '') {
                $plainCodes[] = $plain;
            }
        }

        $data = array(
            'recovery_codes' => $plainCodes,
            'codes_format' => 'enc',
            'recovery_codes_count' => count($recoveryCodesEnc),
            'used_count' => count($usedCodes),
        );

        if ($isDownload) {
            $data['download'] = true;
            $data['filename'] = 'gojs-recovery-codes-' . date('Ymd') . '.txt';
            gojs_log_operation('totp_recovery_download', 'security', true);
        } else {
            gojs_log_operation('totp_recovery_view', 'security', true);
        }

        gojs_json_response($data);
        return;
    }

    gojs_log_operation($isDownload ? 'totp_recovery_download' : 'totp_recovery_view', 'security', true);
    gojs_json_response(array(
        'recovery_codes_count' => count($recoveryCodesEnc),
        'used_count' => count($usedCodes),
        'view_only' => true,
        'legacy' => true,
        'message_key' => 'totp.recoveryLegacyNotice',
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

    gojs_monitor_bump_bandwidth(0, strlen($json));
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

    $root_real = rtrim(realpath($files_root), '/');
    if (!$root_real) {
        $root_real = rtrim($files_root, '/');
    }

    $real_path = realpath($full_path);

    if (!$real_path) {
        $parent_dir = dirname($full_path);
        $real_parent = realpath($parent_dir);

        if (!$real_parent ||
            ($real_parent !== $root_real && strpos($real_parent, $root_real . '/') !== 0)) {
            return false;
        }

        $basename = basename($full_path);
        if (strpos($basename, '..') !== false) {
            return false;
        }

        return $real_parent . '/' . $basename;
    }

    $real_path = rtrim($real_path, '/');
    if ($real_path !== $root_real && strpos($real_path, $root_real . '/') !== 0) {
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

    gojs_log_operation('file_save', $path, true);
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

    gojs_log_operation('file_mkdir', $path, true);
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

function gojs_trash_dir() {
    return CONFIG_DIR . '/trash';
}

function gojs_trash_meta_path($trash_id) {
    return gojs_trash_dir() . '/' . $trash_id . '/meta.json';
}

// 递归复制（回收站跨卷移动时使用）
function gojs_copy_recursive($src, $dst) {
    if (is_link($src)) {
        $target = @readlink($src);
        if ($target === false) return false;
        return @symlink($target, $dst);
    }
    if (is_dir($src)) {
        if (!@mkdir($dst, 0755, true)) {
            return false;
        }
        $handle = @opendir($src);
        if (!$handle) return false;
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') continue;
            if (!gojs_copy_recursive($src . '/' . $entry, $dst . '/' . $entry)) {
                closedir($handle);
                return false;
            }
        }
        closedir($handle);
        return true;
    }
    return @copy($src, $dst);
}

// 将目标移入回收站；成功返回 true，失败返回 false（并清理残留）
function gojs_trash_move($safe_path, $original_path) {
    $trash_dir = gojs_trash_dir();
    if (!is_dir($trash_dir)) {
        if (!@mkdir($trash_dir, 0700, true)) {
            return false;
        }
    }

    $trash_id = uniqid('tr_', true);
    $trash_path = $trash_dir . '/' . $trash_id;
    if (!@mkdir($trash_path, 0700, true)) {
        return false;
    }

    $is_dir = is_dir($safe_path) && !is_link($safe_path);
    if ($is_dir) {
        list(, $size) = gojs_count_files($safe_path, 10);
    } else {
        $size = @filesize($safe_path);
        if ($size === false) $size = 0;
    }

    $meta = array(
        'original_path' => '/' . ltrim($original_path, '/'),
        'type' => $is_dir ? 'dir' : 'file',
        'size' => (int)$size,
        'deleted_at' => time(),
    );
    @file_put_contents($trash_path . '/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $target = $trash_path . '/data';
    if (@rename($safe_path, $target)) {
        return true;
    }

    // 跨卷（不同挂载点 / exFAT 等）：递归复制后删除原路径
    if (gojs_copy_recursive($safe_path, $target)) {
        if ($is_dir) {
            gojs_recursive_delete($safe_path);
        } else {
            @unlink($safe_path);
        }
        return true;
    }

    gojs_recursive_delete($trash_path);
    return false;
}

function gojs_api_trash_list() {
    global $config;

    $items = array();
    $total_size = 0;

    if (is_dir(gojs_trash_dir())) {
        $handle = @opendir(gojs_trash_dir());
        if ($handle) {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') continue;
                $meta_file = gojs_trash_meta_path($entry);
                if (!is_file($meta_file)) continue;
                $meta = json_decode(@file_get_contents($meta_file), true);
                if (!is_array($meta)) continue;
                $size = isset($meta['size']) ? (int)$meta['size'] : 0;
                $items[] = array(
                    'id' => $entry,
                    'orig_path' => isset($meta['original_path']) ? $meta['original_path'] : '',
                    'type' => (isset($meta['type']) && $meta['type'] === 'dir') ? 'dir' : 'file',
                    'size' => $size,
                    'deleted_at' => isset($meta['deleted_at']) ? (int)$meta['deleted_at'] : 0,
                );
                $total_size += $size;
            }
            closedir($handle);
        }
    }

    // 按删除时间倒序
    usort($items, function ($a, $b) {
        return $b['deleted_at'] - $a['deleted_at'];
    });

    gojs_json_response(array(
        'items' => $items,
        'total_size' => $total_size,
        'enabled' => !isset($config['trash_enabled']) ? true : (bool)$config['trash_enabled'],
    ));
}

function gojs_api_trash_restore() {
    $body = gojs_get_body();
    $id = isset($body['id']) ? trim((string)$body['id']) : '';
    if ($id === '' || preg_match('/[^A-Za-z0-9_.-]/', $id)) {
        gojs_json_response(null, array(
            'code' => 'invalid_trash_id',
            'message' => '无效的回收站条目',
        ), 400);
    }

    $trash_path = gojs_trash_dir() . '/' . $id;
    $meta_file = gojs_trash_meta_path($id);
    if (!is_dir($trash_path) || !is_file($meta_file)) {
        gojs_json_response(null, array(
            'code' => 'trash_not_found',
            'message' => '回收站条目不存在',
        ), 404);
    }

    $meta = json_decode(@file_get_contents($meta_file), true);
    if (!is_array($meta) || empty($meta['original_path'])) {
        gojs_json_response(null, array(
            'code' => 'trash_meta_invalid',
            'message' => '回收站条目数据损坏',
        ), 400);
    }

    $original_path = (string)$meta['original_path'];
    $safe_path = gojs_safe_path($original_path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    if (file_exists($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'restore_conflict',
            'message' => '目标位置已存在文件',
        ), 400);
    }

    $src = $trash_path . '/data';
    if (!file_exists($src)) {
        gojs_json_response(null, array(
            'code' => 'trash_not_found',
            'message' => '回收站条目不存在',
        ), 404);
    }

    // 父目录不存在时自动创建
    $parent = dirname($safe_path);
    if (!is_dir($parent)) {
        if (!@mkdir($parent, 0755, true)) {
            gojs_json_response(null, array(
                'code' => 'restore_failed',
                'message' => '恢复失败',
            ), 500);
        }
    }

    if (!@rename($src, $safe_path)) {
        gojs_json_response(null, array(
            'code' => 'restore_failed',
            'message' => '恢复失败',
        ), 500);
    }

    gojs_recursive_delete($trash_path);
    gojs_log_operation('trash_restore', $original_path, true);
    gojs_json_response(array('success' => true));
}

function gojs_api_trash_purge() {
    $body = gojs_get_body();
    $id = isset($body['id']) ? trim((string)$body['id']) : '';
    $trash_dir = gojs_trash_dir();

    if ($id !== '') {
        if (preg_match('/[^A-Za-z0-9_.-]/', $id)) {
            gojs_json_response(null, array(
                'code' => 'invalid_trash_id',
                'message' => '无效的回收站条目',
            ), 400);
        }
        $trash_path = $trash_dir . '/' . $id;
        if (!is_dir($trash_path)) {
            gojs_json_response(null, array(
                'code' => 'trash_not_found',
                'message' => '回收站条目不存在',
            ), 404);
        }
        if (!gojs_recursive_delete($trash_path)) {
            gojs_json_response(null, array(
                'code' => 'purge_failed',
                'message' => '永久删除失败',
            ), 500);
        }
        gojs_log_operation('trash_purge', $id, true);
        gojs_json_response(array('success' => true));
    }

    // 无 id：清空全部
    $purged = 0;
    if (is_dir($trash_dir)) {
        $handle = @opendir($trash_dir);
        if ($handle) {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') continue;
                $p = $trash_dir . '/' . $entry;
                if (is_dir($p)) {
                    if (gojs_recursive_delete($p)) $purged++;
                } elseif (is_file($p)) {
                    if (@unlink($p)) $purged++;
                }
            }
            closedir($handle);
        }
    }
    gojs_log_operation('trash_purge', 'all', true);
    gojs_json_response(array('success' => true, 'purged' => $purged));
}

function gojs_api_trash_config() {
    global $config;
    $method = gojs_get_method();

    if ($method === 'GET') {
        gojs_json_response(array(
            'enabled' => !isset($config['trash_enabled']) ? true : (bool)$config['trash_enabled'],
        ));
    }

    if ($method === 'POST') {
        $body = gojs_get_body();
        $enabled = !empty($body['enabled']);
        $config['trash_enabled'] = $enabled;
        gojs_save_config();
        gojs_json_response(array('enabled' => $enabled));
    }

    gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
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

    // 回收站：trash_enabled 默认启用，非受保护路径/非根目录时移入回收站
    global $config;
    $trash_enabled = !isset($config['trash_enabled']) ? true : (bool)$config['trash_enabled'];
    if ($trash_enabled) {
        if (gojs_trash_move($safe_path, $path)) {
            gojs_log_operation('file_delete', $path, true, 'moved_to_trash');
            gojs_json_response(array('success' => true, 'trashed' => true));
        }
        gojs_json_response(null, array(
            'code' => 'trash_move_failed',
            'message' => '移入回收站失败',
        ), 500);
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

    gojs_log_operation('file_delete', $path, true);
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

    gojs_log_operation('file_rename', $path . ' → ' . $target, true);
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

    gojs_log_operation('file_compress', $target, true, 'zip');
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

    gojs_log_operation('file_extract', $path, true, 'zip, extracted: ' . $extracted);
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

    gojs_log_operation('file_compress', $target, true, 'tar.gz');
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

    gojs_log_operation('file_extract', $path, true, 'tar.gz, extracted: ' . $extracted);
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

    gojs_log_operation('file_chmod', $path . ' → ' . $perms, true);
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
    $uploaded_bytes = 0;

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
        $uploaded_bytes += (int)$file['size'];

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

    $uploaded_names = array();
    foreach ($results as $r) {
        $uploaded_names[] = isset($r['name']) ? $r['name'] : '';
    }
    gojs_log_operation('file_upload', implode(', ', $uploaded_names), true);

    // monitor: 上传字节计为面板入站流量
    gojs_monitor_bump_bandwidth($uploaded_bytes, 0);

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

    // monitor: 分片上传字节计为面板入站流量
    gojs_monitor_bump_bandwidth(strlen($chunk_data), 0);

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

    $ascii_name = preg_replace('/[\x00-\x1F\x7F"]/', '_', $filename);
    $encoded_name = rawurlencode($filename);

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $ascii_name . '"; filename*=UTF-8\'' . $encoded_name);
    header('Content-Length: ' . $size);
    header('Accept-Ranges: bytes');

    readfile($safe_path);

    gojs_monitor_bump_bandwidth(0, $size);
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

function gojs_api_env_check() {
    $items = array();

    // PHP 扩展检测
    $extensions = array(
        'mysqli' => array('feature_key' => 'database_mysql', 'suggestion' => '联系主机商启用 mysqli 扩展'),
        'pdo_mysql' => array('feature_key' => 'database_pdo_mysql', 'suggestion' => '联系主机商启用 pdo_mysql 扩展'),
        'zip' => array('feature_key' => 'file_zip', 'suggestion' => '联系主机商启用 zip 扩展，或使用 PharData 替代'),
        'gd' => array('feature_key' => 'image_thumbnail', 'suggestion' => '联系主机商启用 gd 扩展'),
        'openssl' => array('feature_key' => 'crypto_ssl', 'suggestion' => '联系主机商启用 openssl 扩展'),
        'mbstring' => array('feature_key' => 'multibyte_string', 'suggestion' => '联系主机商启用 mbstring 扩展'),
        'json' => array('feature_key' => 'json_processing', 'suggestion' => 'PHP 7.4+ 应内置 json 扩展'),
        'session' => array('feature_key' => 'session_management', 'suggestion' => '联系主机商启用 session 扩展'),
    );

    foreach ($extensions as $ext => $info) {
        $loaded = extension_loaded($ext);
        $items[] = array(
            'name' => $ext,
            'category' => 'extension',
            'available' => $loaded,
            'reason_key' => $loaded ? '' : 'extension_not_installed',
            'reason_params' => $loaded ? null : array('ext' => $ext),
            'feature_key' => $info['feature_key'],
            'suggestion_key' => $loaded ? '' : 'suggestion_contact_host',
            'suggestion_params' => $loaded ? null : array('msg' => $info['suggestion']),
        );
    }

    // 函数可用性检测（双重检测：function_exists + disable_functions）
    $disabled = explode(',', (string)ini_get('disable_functions'));
    $disabled = array_map('trim', $disabled);

    $functions = array(
        'exec' => array('feature_key' => 'cron_terminal', 'suggestion' => '面板仍可使用，但 Cron 管理功能将不可用'),
        'proc_open' => array('feature_key' => 'process_terminal', 'suggestion' => '面板仍可使用，但部分高级功能受限'),
        'shell_exec' => array('feature_key' => 'command_exec', 'suggestion' => '面板仍可使用，但部分高级功能受限'),
    );

    foreach ($functions as $func => $info) {
        $exists = function_exists($func);
        $disabled_check = !in_array($func, $disabled);
        $available = $exists && $disabled_check;
        $reason_key = '';
        $reason_params = null;
        if (!$exists) {
            $reason_key = 'function_not_exists';
            $reason_params = array('func' => $func);
        } elseif (!$disabled_check) {
            $reason_key = 'function_disabled';
            $reason_params = array('func' => $func);
        }
        $items[] = array(
            'name' => $func . '()',
            'category' => 'function',
            'available' => $available,
            'reason_key' => $reason_key,
            'reason_params' => $reason_params,
            'feature_key' => $info['feature_key'],
            'suggestion_key' => $available ? '' : 'suggestion_contact_host',
            'suggestion_params' => $available ? null : array('msg' => $info['suggestion']),
        );
    }

    // /proc 可读性检测
    $proc_readable = is_readable('/proc');
    $items[] = array(
        'name' => '/proc 可读',
        'category' => 'system',
        'available' => $proc_readable,
        'reason_key' => $proc_readable ? '' : 'proc_not_readable',
        'reason_params' => null,
        'feature_key' => 'process_cpu_monitor',
        'suggestion_key' => $proc_readable ? '' : 'suggestion_proc_limited',
        'suggestion_params' => null,
    );

    // allow_url_fopen 检测
    $url_fopen = (bool)ini_get('allow_url_fopen');
    $items[] = array(
        'name' => 'allow_url_fopen',
        'category' => 'config',
        'available' => $url_fopen,
        'reason_key' => $url_fopen ? '' : 'allow_url_fopen_off',
        'reason_params' => null,
        'feature_key' => 'remote_file_download',
        'suggestion_key' => $url_fopen ? '' : 'suggestion_url_fopen_curl',
        'suggestion_params' => null,
    );

    // cURL 检测
    $curl_available = function_exists('curl_init');
    $items[] = array(
        'name' => 'cURL',
        'category' => 'extension',
        'available' => $curl_available,
        'reason_key' => $curl_available ? '' : 'curl_not_installed',
        'reason_params' => null,
        'feature_key' => 'remote_file_download',
        'suggestion_key' => $curl_available ? '' : 'suggestion_contact_host',
        'suggestion_params' => $curl_available ? null : array('msg' => '联系主机商启用 curl 扩展'),
    );

    // 统计
    $total = count($items);
    $passed = count(array_filter($items, function($i) { return $i['available']; }));

    return gojs_json_response(array(
        'items' => $items,
        'summary' => array(
            'total' => $total,
            'passed' => $passed,
            'failed' => $total - $passed,
        ),
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

            if (!$line || strpos($line, '#') === 0) {
                continue;
            }

            // 跳过环境变量声明（MAILTO/SHELL/PATH 等）
            if (preg_match('/^(MAILTO|SHELL|PATH|HOME|CRON_TZ)\s*=/', $line)) {
                continue;
            }

            if (strpos($line, '@') === 0) {
                $parts = preg_split('/\s+/', $line, 2);
                if (count($parts) >= 2) {
                    $jobs[] = array(
                        'expression' => $parts[0],
                        'command' => $parts[1],
                        'raw' => $line,
                    );
                }
                continue;
            }

            $parts = preg_split('/\s+/', $line, 6);
            if (count($parts) >= 6) {
                $expression = implode(' ', array_slice($parts, 0, 5));
                $jobs[] = array(
                    'expression' => $expression,
                    'command' => $parts[5],
                    'raw' => $line,
                );
            }
        }
    }

    gojs_json_response($jobs);
}

// 检测 Cron 是否可用
function gojs_cron_capabilities() {
    $disabled = explode(',', (string)ini_get('disable_functions'));
    $disabled_list = array_map('trim', $disabled);
    $exec_available = function_exists('exec') && !in_array('exec', $disabled_list, true);

    $crontab_available = false;
    $method = 'none';
    $cron_file = null;
    $msg = '';
    $msg_key = null;
    $info_key = null;
    $info_params = array();

    if ($exec_available) {
        $out = array();
        $code = 0;
        @exec('command -v crontab 2>&1', $out, $code);
        if ($code === 0) {
            $crontab_available = true;
        }

        if (!$crontab_available) {
            $out2 = array();
            $code2 = 0;
            @exec('crontab -l 2>&1', $out2, $code2);
            if ($code2 === 0 || $code2 === 1) {
                $crontab_available = true;
            }
        }

        if ($crontab_available) {
            $method = 'exec';
        }
    }

    if (!$crontab_available) {
        $home = isset($_SERVER['HOME']) ? $_SERVER['HOME'] : '';
        $user = function_exists('get_current_user') ? get_current_user() : '';
        $cron_files = array();
        if ($home) {
            $cron_files[] = $home . '/.config/cron/crontab';
            $cron_files[] = $home . '/.crontab';
        }
        if ($user) {
            $cron_files[] = '/var/spool/cron/' . $user;
            $cron_files[] = '/var/spool/cron/crontabs/' . $user;
        }

        foreach ($cron_files as $file) {
            if ((is_writable(dirname($file)) || is_writable($file))) {
                $method = 'file';
                $cron_file = $file;
                $crontab_available = true;
                break;
            }
        }
    }

    $available = $exec_available;

    if (!$exec_available) {
        $msg_key = 'unavailable';
        $msg = '环境不支持 Cron 管理（exec 被禁且 crontab 文件不可写）';
    } elseif ($exec_available && !$crontab_available) {
        $info_key = 'crontab_cli_missing';
        $msg = 'exec() 可用但 crontab 命令未安装';
    }

    $result = array(
        'available'         => $available,
        'exec_available'    => $exec_available,
        'crontab_available' => $crontab_available,
        'message'           => $msg,
    );

    if ($msg_key !== null) {
        $result['message_key'] = $msg_key;
    }
    if ($info_key !== null) {
        $result['info_key'] = $info_key;
        $result['info_params'] = $info_params;
    }
    if ($method !== 'none') {
        $result['method'] = $method;
    }
    if ($cron_file !== null) {
        $result['cron_file'] = $cron_file;
    }

    return $result;
}

// 读取 crontab 任务列表
function gojs_cron_list() {
    $caps = gojs_cron_capabilities();
    if (!$caps['available']) {
        return array();
    }

    $content = '';
    if ($caps['method'] === 'exec') {
        $output = array();
        @exec('crontab -l 2>/dev/null', $output);
        $content = implode("\n", $output);
    } else if ($caps['method'] === 'file' && isset($caps['cron_file'])) {
        if (file_exists($caps['cron_file'])) {
            $content = (string)@file_get_contents($caps['cron_file']);
        }
    }

    // 解析 crontab 内容
    $jobs = array();
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        // 跳过环境变量声明
        if (preg_match('/^(MAILTO|SHELL|PATH|HOME|CRON_TZ)\s*=/', $line)) {
            continue;
        }

        if (strpos($line, '@') === 0) {
            $parts = preg_split('/\s+/', $line, 2);
            if (count($parts) >= 2) {
                $jobs[] = array(
                    'expression' => $parts[0],
                    'command' => $parts[1],
                    'raw' => $line,
                );
            }
            continue;
        }

        $parts = preg_split('/\s+/', $line, 6);
        if (count($parts) >= 6) {
            $jobs[] = array(
                'expression' => implode(' ', array_slice($parts, 0, 5)),
                'command' => $parts[5],
                'raw' => $line,
            );
        }
    }

    return $jobs;
}

// 保存 crontab
function gojs_cron_save($jobs) {
    $caps = gojs_cron_capabilities();
    if (!$caps['available']) {
        return false;
    }

    // 构建 crontab 内容
    $content = "# Managed by Go.js Lite\n";
    foreach ($jobs as $job) {
        $expression = isset($job['expression']) ? trim($job['expression']) : '';
        $command = isset($job['command']) ? trim($job['command']) : '';
        if ($expression === '' || $command === '') {
            continue;
        }
        $content .= $expression . ' ' . $command . "\n";
    }

    if ($caps['method'] === 'exec') {
        // 写入临时文件，然后 crontab 加载
        $tmp = tempnam(sys_get_temp_dir(), 'gojs_cron');
        if ($tmp === false) {
            return false;
        }
        file_put_contents($tmp, $content);
        $output = array();
        $exit_code = 0;
        @exec('crontab ' . escapeshellarg($tmp) . ' 2>&1', $output, $exit_code);
        @unlink($tmp);
        return $exit_code === 0;
    } else if ($caps['method'] === 'file' && isset($caps['cron_file'])) {
        $dir = dirname($caps['cron_file']);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return @file_put_contents($caps['cron_file'], $content) !== false;
    }

    return false;
}

function gojs_api_cron_capabilities() {
    return gojs_json_response(gojs_cron_capabilities());
}

function gojs_api_cron_list() {
    return gojs_json_response(array('jobs' => gojs_cron_list()));
}

function gojs_api_cron_save() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['jobs']) || !is_array($input['jobs'])) {
        return gojs_json_response(null, array(
            'code' => 'invalid_input',
            'message' => '参数无效',
        ), 400);
    }

    // 验证每个 job 的表达式和命令
    foreach ($input['jobs'] as $job) {
        if (!isset($job['expression']) || !isset($job['command'])) {
            return gojs_json_response(null, array(
                'code' => 'invalid_job',
                'message' => '缺少 expression 或 command',
            ), 400);
        }
        // 简单验证 cron 表达式（5 个字段）
        $fields = preg_split('/\s+/', trim($job['expression']));
        if (count($fields) !== 5) {
            return gojs_json_response(null, array(
                'code' => 'invalid_expression',
                'message' => 'Cron 表达式必须为 5 个字段',
            ), 400);
        }
    }

    $result = gojs_cron_save($input['jobs']);
    if ($result) {
        gojs_log_operation('cron_save', count($input['jobs']) . ' jobs', true);
        return gojs_json_response(array('ok' => true));
    } else {
        gojs_log_operation('cron_save', count($input['jobs']) . ' jobs', false);
        return gojs_json_response(null, array(
            'code' => 'save_failed',
            'message' => '保存 crontab 失败',
        ), 500);
    }
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

function gojs_api_operation_log() {
    $log_file = CONFIG_DIR . '/operation_log.json';
    $logs = array();
    if (file_exists($log_file)) {
        $content = @file_get_contents($log_file);
        if ($content) {
            $logs = json_decode($content, true);
            if (!is_array($logs)) $logs = array();
        }
    }

    // 倒序排列（最新的在前）
    $logs = array_reverse($logs);

    // 筛选
    $type = isset($_GET['type']) ? $_GET['type'] : '';
    $ip = isset($_GET['ip']) ? $_GET['ip'] : '';
    $user = isset($_GET['user']) ? $_GET['user'] : '';
    $date_from = isset($_GET['date_from']) ? (int)$_GET['date_from'] : 0;
    $date_to = isset($_GET['date_to']) ? (int)$_GET['date_to'] : 0;

    if ($type) {
        $logs = array_filter($logs, function($l) use ($type) {
            return isset($l['action']) && strpos($l['action'], $type) !== false;
        });
    }
    if ($ip) {
        $logs = array_filter($logs, function($l) use ($ip) {
            return isset($l['ip']) && strpos($l['ip'], $ip) !== false;
        });
    }
    if ($user !== '') {
        $logs = array_filter($logs, function($l) use ($user) {
            return isset($l['user']) && strpos($l['user'], $user) !== false;
        });
    }
    if ($date_from > 0) {
        $logs = array_filter($logs, function($l) use ($date_from) {
            return isset($l['timestamp']) && (int)$l['timestamp'] >= $date_from;
        });
    }
    if ($date_to > 0) {
        $logs = array_filter($logs, function($l) use ($date_to) {
            return isset($l['timestamp']) && (int)$l['timestamp'] <= $date_to;
        });
    }

    // 分页
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = 50;
    $total = count($logs);
    $logs = array_slice($logs, ($page - 1) * $per_page, $per_page);

    return gojs_json_response(array(
        'logs' => array_values($logs),
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => ceil($total / $per_page),
    ));
}

function gojs_api_operation_log_clear() {
    $log_file = CONFIG_DIR . '/operation_log.json';
    @file_put_contents($log_file, '[]');
    gojs_log_operation('operation_log_clear', $log_file, true);
    return gojs_json_response(array('ok' => true));
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

function gojs_api_operation_log_export() {
    $log_file = CONFIG_DIR . '/operation_log.json';
    $logs = array();
    if (file_exists($log_file)) {
        $content = @file_get_contents($log_file);
        if ($content) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $logs = $decoded;
            }
        }
    }

    $body = gojs_get_body();
    $format = isset($body['format']) ? $body['format'] : 'csv';
    $scope = isset($body['scope']) ? $body['scope'] : 'all';

    if (!in_array($format, array('csv', 'jsonl', 'json'))) {
        $format = 'csv';
    }

    if ($scope === 'current_filter') {
        $action_filter = isset($body['action']) && is_array($body['action']) ? $body['action'] : array();
        $ip_like = isset($body['ip_like']) ? $body['ip_like'] : '';
        $user_like = isset($body['user']) ? $body['user'] : '';
        $from_ts = isset($body['date_from']) ? (int)$body['date_from'] : (isset($body['from_ts']) ? (int)$body['from_ts'] : 0);
        $to_ts = isset($body['date_to']) ? (int)$body['date_to'] : (isset($body['to_ts']) ? (int)$body['to_ts'] : 0);

        $logs = array_filter($logs, function($l) use ($action_filter, $ip_like, $user_like, $from_ts, $to_ts) {
            if (!empty($action_filter)) {
                $act = isset($l['action']) ? $l['action'] : '';
                if (!in_array($act, $action_filter, true)) return false;
            }
            if ($ip_like !== '') {
                $ip = isset($l['ip']) ? $l['ip'] : '';
                if (strpos($ip, $ip_like) === false) return false;
            }
            if ($user_like !== '') {
                $u = isset($l['user']) ? $l['user'] : '';
                if (strpos($u, $user_like) === false) return false;
            }
            if ($from_ts > 0) {
                $ts = isset($l['timestamp']) ? (int)$l['timestamp'] : 0;
                if ($ts < $from_ts) return false;
            }
            if ($to_ts > 0) {
                $ts = isset($l['timestamp']) ? (int)$l['timestamp'] : 0;
                if ($ts > $to_ts) return false;
            }
            return true;
        });
    }

    $logs = array_values($logs);

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $now = date('Ymd_His');
    $ext = $format === 'csv' ? 'csv' : ($format === 'json' ? 'json' : 'jsonl');
    $filename = 'operation_log_' . $now . '.' . $ext;

    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $export_bytes = 0;

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        $n = fwrite($out, "\xEF\xBB\xBF");
        $export_bytes += $n !== false ? $n : 0;
        $n = fputcsv($out, array('timestamp_iso', 'ip', 'action', 'detail', 'user'));
        $export_bytes += $n !== false ? $n : 0;
        $chunk = 0;
        foreach ($logs as $l) {
            $ts_iso = isset($l['time']) ? $l['time'] : date('Y-m-d H:i:s', isset($l['timestamp']) ? (int)$l['timestamp'] : time());
            $row = array(
                $ts_iso,
                isset($l['ip']) ? $l['ip'] : '',
                isset($l['action']) ? $l['action'] : '',
                isset($l['detail']) ? $l['detail'] : (isset($l['target']) ? $l['target'] : ''),
                isset($l['user']) ? $l['user'] : 'admin',
            );
            $n = fputcsv($out, $row);
            $export_bytes += $n !== false ? $n : 0;
            $chunk++;
            if ($chunk >= 100) {
                flush();
                usleep(0);
                $chunk = 0;
            }
        }
        fclose($out);
    } elseif ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $arr = array();
        foreach ($logs as $l) {
            $ts_iso = isset($l['time']) ? $l['time'] : date('Y-m-d H:i:s', isset($l['timestamp']) ? (int)$l['timestamp'] : time());
            $arr[] = array(
                'timestamp_iso' => $ts_iso,
                'timestamp' => isset($l['timestamp']) ? (int)$l['timestamp'] : 0,
                'ip' => isset($l['ip']) ? $l['ip'] : '',
                'action' => isset($l['action']) ? $l['action'] : '',
                'detail' => isset($l['detail']) ? $l['detail'] : (isset($l['target']) ? $l['target'] : ''),
                'target' => isset($l['target']) ? $l['target'] : '',
                'result' => isset($l['result']) ? (bool)$l['result'] : true,
                'user' => isset($l['user']) ? $l['user'] : 'admin',
            );
        }
        $export_json = json_encode($arr, JSON_UNESCAPED_UNICODE);
        $export_bytes += strlen($export_json);
        echo $export_json;
    } else {
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $chunk = 0;
        foreach ($logs as $l) {
            $ts_iso = isset($l['time']) ? $l['time'] : date('Y-m-d H:i:s', isset($l['timestamp']) ? (int)$l['timestamp'] : time());
            $obj = array(
                'timestamp_iso' => $ts_iso,
                'timestamp' => isset($l['timestamp']) ? (int)$l['timestamp'] : 0,
                'ip' => isset($l['ip']) ? $l['ip'] : '',
                'action' => isset($l['action']) ? $l['action'] : '',
                'detail' => isset($l['detail']) ? $l['detail'] : (isset($l['target']) ? $l['target'] : ''),
                'target' => isset($l['target']) ? $l['target'] : '',
                'result' => isset($l['result']) ? (bool)$l['result'] : true,
                'user' => isset($l['user']) ? $l['user'] : 'admin',
            );
            $line = json_encode($obj, JSON_UNESCAPED_UNICODE) . "\n";
            $export_bytes += strlen($line);
            echo $line;
            $chunk++;
            if ($chunk >= 100) {
                flush();
                usleep(0);
                $chunk = 0;
            }
        }
    }

    flush();
    gojs_monitor_bump_bandwidth(0, $export_bytes);
    exit;
}

function gojs_load_alert_rules(): array {
    global $config;
    return isset($config['alert_rules']) && is_array($config['alert_rules'])
        ? $config['alert_rules']
        : array();
}

function gojs_save_alert_rules(array $rules): void {
    global $config;
    $config['alert_rules'] = $rules;
    gojs_save_config();
}

function gojs_api_alert_rules($method) {
    if ($method === 'GET') {
        gojs_json_response(gojs_load_alert_rules());
    } elseif ($method === 'POST') {
        $body = gojs_get_body();
        $rules = gojs_load_alert_rules();
        $id = 'rule_' . uniqid() . '_' . bin2hex(random_bytes(3));
        $name = isset($body['name']) ? (string)$body['name'] : 'Unnamed Rule';
        $enabled = isset($body['enabled']) ? (bool)$body['enabled'] : true;
        $when_raw = isset($body['when']) && is_array($body['when']) ? $body['when'] : array();
        $then_raw = isset($body['then']) && is_array($body['then']) ? $body['then'] : array();

        $when = array();
        if (isset($when_raw['action_in']) && is_array($when_raw['action_in'])) {
            $when['action_in'] = array_values(array_filter($when_raw['action_in'], 'is_string'));
        }
        if (isset($when_raw['action_not_in']) && is_array($when_raw['action_not_in'])) {
            $when['action_not_in'] = array_values(array_filter($when_raw['action_not_in'], 'is_string'));
        }
        if (isset($when_raw['ip_not_in_whitelist'])) {
            $when['ip_not_in_whitelist'] = (bool)$when_raw['ip_not_in_whitelist'];
        }
        if (!empty($when_raw['outside_hours_range'])) {
            $when['outside_hours_range'] = (string)$when_raw['outside_hours_range'];
        }
        if (isset($when_raw['consecutive_fail_login_gt_N'])) {
            $when['consecutive_fail_login_gt_N'] = (int)$when_raw['consecutive_fail_login_gt_N'];
        }

        $then = array(
            'channel_ids' => isset($then_raw['channel_ids']) && is_array($then_raw['channel_ids'])
                ? array_values(array_filter($then_raw['channel_ids'], 'is_string'))
                : array(),
            'severity' => isset($then_raw['severity']) && in_array($then_raw['severity'], array('info', 'warning', 'critical'), true)
                ? $then_raw['severity']
                : 'warning',
        );

        $rule = array(
            'id' => $id,
            'name' => $name,
            'enabled' => $enabled,
            'when' => $when,
            'then' => $then,
        );

        $rules[] = $rule;
        gojs_save_alert_rules($rules);
        gojs_json_response($rule);
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}

function gojs_api_alert_rule($id, $method) {
    $rules = gojs_load_alert_rules();
    $idx = -1;
    foreach ($rules as $i => $r) {
        if (isset($r['id']) && $r['id'] === $id) {
            $idx = $i;
            break;
        }
    }
    if ($idx < 0) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '规则不存在'), 404);
    }

    if ($method === 'PUT') {
        $body = gojs_get_body();
        $rule = $rules[$idx];
        if (isset($body['name'])) $rule['name'] = (string)$body['name'];
        if (isset($body['enabled'])) $rule['enabled'] = (bool)$body['enabled'];

        if (isset($body['when']) && is_array($body['when'])) {
            $when_raw = $body['when'];
            $when = isset($rule['when']) && is_array($rule['when']) ? $rule['when'] : array();
            if (array_key_exists('action_in', $when_raw)) {
                if (is_array($when_raw['action_in'])) {
                    $when['action_in'] = array_values(array_filter($when_raw['action_in'], 'is_string'));
                } else {
                    unset($when['action_in']);
                }
            }
            if (array_key_exists('action_not_in', $when_raw)) {
                if (is_array($when_raw['action_not_in'])) {
                    $when['action_not_in'] = array_values(array_filter($when_raw['action_not_in'], 'is_string'));
                } else {
                    unset($when['action_not_in']);
                }
            }
            if (array_key_exists('ip_not_in_whitelist', $when_raw)) {
                if ($when_raw['ip_not_in_whitelist'] === null) {
                    unset($when['ip_not_in_whitelist']);
                } else {
                    $when['ip_not_in_whitelist'] = (bool)$when_raw['ip_not_in_whitelist'];
                }
            }
            if (array_key_exists('outside_hours_range', $when_raw)) {
                if ($when_raw['outside_hours_range'] === '' || $when_raw['outside_hours_range'] === null) {
                    unset($when['outside_hours_range']);
                } else {
                    $when['outside_hours_range'] = (string)$when_raw['outside_hours_range'];
                }
            }
            if (array_key_exists('consecutive_fail_login_gt_N', $when_raw)) {
                if ($when_raw['consecutive_fail_login_gt_N'] === null) {
                    unset($when['consecutive_fail_login_gt_N']);
                } else {
                    $when['consecutive_fail_login_gt_N'] = (int)$when_raw['consecutive_fail_login_gt_N'];
                }
            }
            $rule['when'] = $when;
        }

        if (isset($body['then']) && is_array($body['then'])) {
            $then_raw = $body['then'];
            $then = isset($rule['then']) && is_array($rule['then']) ? $rule['then'] : array('channel_ids' => array(), 'severity' => 'warning');
            if (isset($then_raw['channel_ids']) && is_array($then_raw['channel_ids'])) {
                $then['channel_ids'] = array_values(array_filter($then_raw['channel_ids'], 'is_string'));
            }
            if (isset($then_raw['severity']) && in_array($then_raw['severity'], array('info', 'warning', 'critical'), true)) {
                $then['severity'] = $then_raw['severity'];
            }
            $rule['then'] = $then;
        }

        $rules[$idx] = $rule;
        gojs_save_alert_rules($rules);
        gojs_json_response($rule);
    } elseif ($method === 'DELETE') {
        array_splice($rules, $idx, 1);
        gojs_save_alert_rules($rules);
        gojs_json_response(array('ok' => true));
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}

function gojs_api_alert_rule_test($id) {
    $rules = gojs_load_alert_rules();
    $rule = null;
    foreach ($rules as $r) {
        if (isset($r['id']) && $r['id'] === $id) {
            $rule = $r;
            break;
        }
    }
    if ($rule === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '规则不存在'), 404);
    }

    $severity = isset($rule['then']['severity']) ? $rule['then']['severity'] : 'warning';
    $channel_ids = isset($rule['then']['channel_ids']) ? $rule['then']['channel_ids'] : array();
    $rule_name = isset($rule['name']) ? $rule['name'] : 'Unnamed Rule';

    $category = $severity === 'critical' ? 'security' : 'system';
    $title_key_map = array(
        'info' => 'oplog_alert_info',
        'warning' => 'oplog_alert_warning',
        'critical' => 'oplog_alert_critical',
    );
    $title_key = isset($title_key_map[$severity]) ? $title_key_map[$severity] : 'oplog_alert_warning';

    $body_params = array(
        'rule_name' => $rule_name,
    );

    gojs_append_notification(array(
        'category' => $category,
        'severity' => $severity,
        'title_key' => $title_key,
        'body_key' => 'oplog_alert_test_body',
        'body_params' => $body_params,
        'payload' => array(
            'source' => 'alert_rule_test',
            'rule_id' => $id,
            'rule_name' => $rule_name,
            'synthetic' => true,
        ),
    ));

    gojs_append_outbox(array(
        'channel_ids' => $channel_ids,
        'payload' => array(
            'subject' => '[Go.js Alert TEST] ' . $rule_name,
            'body' => "Alert rule test firing:\nRule: " . $rule_name . "\nSeverity: " . $severity . "\nTime: " . date('Y-m-d H:i:s'),
        ),
    ));

    gojs_json_response(array('ok' => true, 'fired' => true));
}

function gojs_alerts_evaluate(string $kind, array $context): array {
    global $config;
    $rules = gojs_load_alert_rules();
    $fired = array();

    $whitelist = isset($config['alert_whitelist_ips']) && is_array($config['alert_whitelist_ips'])
        ? $config['alert_whitelist_ips']
        : array();
    $whitelist_empty = count($whitelist) === 0;

    foreach ($rules as $rule) {
        if (empty($rule['enabled'])) continue;
        $when = isset($rule['when']) && is_array($rule['when']) ? $rule['when'] : array();
        $matches_all = true;

        if ($kind === 'oplog') {
            $action = isset($context['action']) ? $context['action'] : '';
            $ip = isset($context['ip']) ? $context['ip'] : '';
            $ts = isset($context['timestamp']) ? (int)$context['timestamp'] : time();

            if (isset($when['action_in']) && is_array($when['action_in']) && count($when['action_in']) > 0) {
                if (!in_array($action, $when['action_in'], true)) {
                    $matches_all = false;
                }
            }
            if ($matches_all && isset($when['action_not_in']) && is_array($when['action_not_in']) && count($when['action_not_in']) > 0) {
                if (in_array($action, $when['action_not_in'], true)) {
                    $matches_all = false;
                }
            }
            if ($matches_all && !empty($when['ip_not_in_whitelist'])) {
                if ($whitelist_empty) {
                    $matches_all = false;
                } else {
                    if (in_array($ip, $whitelist, true)) {
                        $matches_all = false;
                    }
                }
            }
            if ($matches_all && !empty($when['outside_hours_range'])) {
                $range = $when['outside_hours_range'];
                if (preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $range, $m)) {
                    $start_str = $m[1];
                    $end_str = $m[2];
                    $now_hm = date('H:i', $ts);
                    $now_min = (int)substr($now_hm, 0, 2) * 60 + (int)substr($now_hm, 3, 2);
                    $s_min = (int)substr($start_str, 0, 2) * 60 + (int)substr($start_str, 3, 2);
                    $e_min = (int)substr($end_str, 0, 2) * 60 + (int)substr($end_str, 3, 2);
                    $inside = false;
                    if ($s_min <= $e_min) {
                        $inside = ($now_min >= $s_min && $now_min <= $e_min);
                    } else {
                        $inside = ($now_min >= $s_min || $now_min <= $e_min);
                    }
                    if ($inside) {
                        $matches_all = false;
                    }
                }
            }
        } elseif ($kind === 'auth_fail') {
            $fail_count = isset($context['fail_count']) ? (int)$context['fail_count'] : 0;
            if (isset($when['consecutive_fail_login_gt_N'])) {
                $n = (int)$when['consecutive_fail_login_gt_N'];
                if ($fail_count <= $n) {
                    $matches_all = false;
                }
            } else {
                $matches_all = false;
            }
        } else {
            $matches_all = false;
        }

        if ($matches_all) {
            $then = isset($rule['then']) && is_array($rule['then']) ? $rule['then'] : array();
            $severity = isset($then['severity']) ? $then['severity'] : 'warning';
            $channel_ids = isset($then['channel_ids']) && is_array($then['channel_ids']) ? $then['channel_ids'] : array();
            $rule_name = isset($rule['name']) ? $rule['name'] : 'Unnamed Rule';
            $rule_id = isset($rule['id']) ? $rule['id'] : '';

            $category = $severity === 'critical' ? 'security' : 'system';
            $title_key_map = array(
                'info' => 'oplog_alert_info',
                'warning' => 'oplog_alert_warning',
                'critical' => 'oplog_alert_critical',
            );
            $title_key = isset($title_key_map[$severity]) ? $title_key_map[$severity] : 'oplog_alert_warning';

            $body_params = array(
                'rule_name' => $rule_name,
            );
            if ($kind === 'oplog') {
                $body_params['action'] = isset($context['action']) ? $context['action'] : '';
                $body_params['ip'] = isset($context['ip']) ? $context['ip'] : '';
                $body_params['detail'] = isset($context['detail']) ? $context['detail'] : '';
            } elseif ($kind === 'auth_fail') {
                $body_params['ip'] = isset($context['ip']) ? $context['ip'] : '';
                $body_params['fail_count'] = (string)$fail_count;
            }

            gojs_append_notification(array(
                'category' => $category,
                'severity' => $severity,
                'title_key' => $title_key,
                'body_key' => $kind === 'auth_fail' ? 'oplog_alert_authfail_body' : 'oplog_alert_oplog_body',
                'body_params' => $body_params,
                'payload' => array(
                    'source' => 'alert_rule',
                    'rule_id' => $rule_id,
                    'rule_name' => $rule_name,
                    'kind' => $kind,
                    'context' => $context,
                ),
            ));

            $outbox_body = "Alert fired: " . $rule_name . "\nSeverity: " . $severity . "\nTime: " . date('Y-m-d H:i:s');
            if ($kind === 'oplog') {
                $outbox_body .= "\nAction: " . (isset($context['action']) ? $context['action'] : '') .
                    "\nIP: " . (isset($context['ip']) ? $context['ip'] : '') .
                    "\nDetail: " . (isset($context['detail']) ? $context['detail'] : '');
            } elseif ($kind === 'auth_fail') {
                $outbox_body .= "\nIP: " . (isset($context['ip']) ? $context['ip'] : '') .
                    "\nFailures: " . $fail_count;
            }

            gojs_append_outbox(array(
                'channel_ids' => $channel_ids,
                'payload' => array(
                    'subject' => '[Go.js Alert] ' . $severity . ' - ' . $rule_name,
                    'body' => $outbox_body,
                ),
            ));

            $fired[] = $rule_id;
        }
    }

    return $fired;
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
                'code' => 'mysql_not_available',
                'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
                'message_key' => 'db.mysqlNotAvailable',
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
                'code' => 'db_connect_failed',
                'message' => '连接失败: ' . $connect_result['error'],
                'message_key' => 'db.connectFailed',
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
                'code' => 'mysql_not_available',
                'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
                'message_key' => 'db.mysqlNotAvailable',
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
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
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
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');

    if (!$database) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '数据库名不能为空',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config['database'] = $database;

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
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
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $table = gojs_get_param('table', '');

    if (!$database || !$table) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '数据库名和表名不能为空',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config['database'] = $database;

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
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
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $sql = gojs_get_param('sql', '');

    if (!$sql) {
        gojs_json_response(null, array(
            'code' => 'db_import_empty',
            'message' => 'SQL 不能为空',
            'message_key' => 'db.importEmpty',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    if ($database) {
        $conn_config['database'] = $database;
    }

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
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

    gojs_log_operation('db_sql_exec', $sql, !empty($sql_result['success']));
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
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
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
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    if ($database) {
        $conn_config['database'] = $database;
    }

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
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
            'code' => 'db_export_failed',
            'message' => '导出失败：无法打开输出流',
            'message_key' => 'db.exportFailed',
        ), 400);
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
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
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
            'code' => 'db_not_connected',
            'message' => '连接 ID 不能为空',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    if (empty($_FILES['file'])) {
        gojs_json_response(null, array(
            'code' => 'db_import_empty',
            'message' => '没有上传文件',
            'message_key' => 'db.importEmpty',
        ), 400);
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        gojs_json_response(null, array(
            'code' => 'db_import_empty',
            'message' => '文件上传错误: ' . $file['error'],
            'message_key' => 'db.importEmpty',
        ), 400);
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        gojs_json_response(null, array(
            'code' => 'db_import_empty',
            'message' => '无效的上传文件',
            'message_key' => 'db.importEmpty',
        ), 400);
    }

    $filename = isset($file['name']) ? $file['name'] : 'import.sql';
    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'sql') {
        gojs_json_response(null, array(
            'code' => 'db_import_empty',
            'message' => '仅支持 .sql 文件',
            'message_key' => 'db.importEmpty',
        ), 400);
    }

    $sql_content = file_get_contents($file['tmp_name']);
    if ($sql_content === false) {
        gojs_json_response(null, array(
            'code' => 'db_import_failed',
            'message' => '无法读取上传文件',
            'message_key' => 'db.importFailed',
        ), 400);
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
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    if ($database) {
        $conn_config['database'] = $database;
    }

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
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
            'code' => 'db_import_failed',
            'message' => '无法读取上传文件',
            'message_key' => 'db.importFailed',
        ), 400);
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

    gojs_log_operation('db_import', $filename, $failed === 0, 'executed: ' . $executed . ', failed: ' . $failed);
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

/**
 * 备份与恢复
 * 备份包结构：
 *   files/                站点目录文件
 *   database/<conn_id>.sql  各数据库导出
 *   config/config.php     面板配置
 *   backup.json           元数据
 */

function gojs_backup_filename_valid($filename) {
    return is_string($filename) && preg_match('/^backup-[0-9]{8}-[0-9]{6}\.zip$/', $filename);
}

function gojs_api_backup_create() {
    $input = gojs_get_body();
    $include_files = !isset($input['include_files']) ? true : (bool)$input['include_files'];
    $include_db = !isset($input['include_db']) ? true : (bool)$input['include_db'];
    $include_config = !isset($input['include_config']) ? true : (bool)$input['include_config'];
    $exclude_dirs = isset($input['exclude_dirs']) && is_array($input['exclude_dirs'])
        ? $input['exclude_dirs']
        : array('cache', 'node_modules', '.git', '.gojs');

    $backup_dir = CONFIG_DIR . '/backups';
    if (!is_dir($backup_dir)) {
        if (!@mkdir($backup_dir, 0700, true)) {
            gojs_json_response(null, array(
                'code' => 'mkdir_failed',
                'message' => '无法创建备份目录',
            ), 500);
        }
    }

    $timestamp = date('Ymd-His');
    $backup_name = "backup-{$timestamp}";
    $backup_file = $backup_dir . "/{$backup_name}.zip";

    if (!class_exists('ZipArchive')) {
        gojs_json_response(null, array(
            'code' => 'zip_not_available',
            'message' => 'ZipArchive 扩展不可用',
        ), 500);
    }

    $zip = new ZipArchive();
    if ($zip->open($backup_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        gojs_json_response(null, array(
            'code' => 'zip_create_failed',
            'message' => '创建备份包失败',
        ), 500);
    }

    @set_time_limit(0);

    $metadata = array(
        'created_at' => date('Y-m-d H:i:s'),
        'version' => APP_VERSION,
        'files' => null,
        'databases' => array(),
        'config' => false,
    );

    $files_root = $GLOBALS['files_root'];

    // 打包站点文件
    if ($include_files && is_dir($files_root)) {
        $file_count = gojs_backup_add_dir($zip, $files_root, 'files/', $exclude_dirs);
        $metadata['files'] = array(
            'count' => $file_count,
            'root' => basename($files_root),
        );
    }

    // 导出数据库（遍历所有已配置的连接）
    if ($include_db) {
        $connections = gojs_load_db_connections();
        foreach ($connections as $conn) {
            if (empty($conn['id'])) continue;
            $conn_config = gojs_get_db_connection($conn['id']);
            if (!$conn_config) continue;

            try {
                $sql_content = gojs_backup_export_db($conn_config);
                if ($sql_content !== '') {
                    $safe_id = preg_replace('/[^A-Za-z0-9_-]/', '_', $conn['id']);
                    $zip->addFromString('database/' . $safe_id . '.sql', $sql_content);
                    $metadata['databases'][] = array(
                        'id' => $conn['id'],
                        'name' => isset($conn['name']) ? $conn['name'] : $conn['id'],
                        'database' => isset($conn_config['database']) ? $conn_config['database'] : '',
                        'size' => strlen($sql_content),
                    );
                }
            } catch (Exception $e) {
                if (!isset($metadata['db_error'])) {
                    $metadata['db_error'] = array();
                }
                $metadata['db_error'][] = (isset($conn['name']) ? $conn['name'] : $conn['id']) . ': ' . $e->getMessage();
            }
        }
    }

    // 打包面板配置
    if ($include_config && file_exists(CONFIG_FILE)) {
        $zip->addFile(CONFIG_FILE, 'config/config.php');
        $metadata['config'] = true;
    }

    // 写入元数据
    $zip->addFromString('backup.json', json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $zip->close();

    $size = @filesize($backup_file);
    gojs_log_operation('backup_create', $backup_name . '.zip', true);

    gojs_json_response(array(
        'filename' => $backup_name . '.zip',
        'size' => $size,
        'metadata' => $metadata,
    ));
}

// 递归添加目录到 zip，跳过 exclude_dirs 与受保护路径
function gojs_backup_add_dir($zip, $dir, $zip_prefix, $exclude_dirs) {
    $count = 0;
    $items = @scandir($dir);
    if ($items === false) return 0;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if (in_array($item, $exclude_dirs, true)) continue;

        $path = $dir . '/' . $item;
        $zip_path = $zip_prefix . $item;

        // 跳过面板自身敏感文件、.gojs 配置目录等
        if (gojs_is_protected_path($path)) continue;

        if (is_dir($path)) {
            $zip->addEmptyDir($zip_path);
            $count += gojs_backup_add_dir($zip, $path, $zip_path . '/', $exclude_dirs);
        } else if (is_file($path)) {
            $zip->addFile($path, $zip_path);
            $count++;
        }
    }
    return $count;
}

// 导出单个数据库连接为 SQL 字符串，复用现有连接与导出辅助函数
function gojs_backup_export_db($conn_config) {
    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        return '';
    }

    $db = $result['connection'];
    $type = $result['type'];

    $name = isset($conn_config['database']) ? $conn_config['database'] : '';
    $host = isset($conn_config['host']) ? $conn_config['host'] : 'localhost';

    $sql = "-- Go.js Lite Backup\n";
    $sql .= "-- Host: {$host}\n";
    $sql .= "-- Database: {$name}\n";
    $sql .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
    $sql .= "SET NAMES utf8;\n";
    $sql .= "SET SQL_MODE=\"\";\n\n";

    $tables = gojs_db_fetch_tables_list($db, $type);
    $batch_size = 1000;

    foreach ($tables as $table) {
        $table_escaped = '`' . str_replace('`', '``', $table) . '`';

        $sql .= "\n-- ------------------------------------------------------------\n";
        $sql .= "-- Table structure for `{$table}`\n";
        $sql .= "-- ------------------------------------------------------------\n";
        $sql .= "DROP TABLE IF EXISTS {$table_escaped};\n";

        $create_sql = gojs_db_show_create_table($db, $type, $table_escaped);
        if ($create_sql !== '') {
            $sql .= $create_sql . ";\n";
        }

        $columns = gojs_db_fetch_columns($db, $type, $table_escaped);
        if (empty($columns)) {
            $sql .= "\n";
            continue;
        }

        $col_list_escaped = array();
        foreach ($columns as $col) {
            $col_list_escaped[] = '`' . str_replace('`', '``', $col) . '`';
        }
        $col_list_sql = implode(', ', $col_list_escaped);

        $sql .= "\n-- Dumping data for `{$table}`\n";

        $offset = 0;
        $has_more = true;
        while ($has_more) {
            $limit_sql = 'SELECT * FROM ' . $table_escaped . ' LIMIT ' . (int)$offset . ', ' . (int)$batch_size;

            $rows = array();
            if ($type === 'mysqli') {
                $res = $db->query($limit_sql);
                if ($res === false || $res === true) break;
                while ($row = $res->fetch_assoc()) {
                    $rows[] = $row;
                }
                $res->free();
            } elseif ($type === 'pdo') {
                $stmt = $db->query($limit_sql);
                if ($stmt === false) break;
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if (empty($rows)) break;

            foreach ($rows as $row) {
                $values = array();
                foreach ($columns as $col) {
                    $val = isset($row[$col]) ? $row[$col] : null;
                    $values[] = gojs_db_escape_value($db, $type, $val);
                }
                $sql .= "INSERT INTO {$table_escaped} ({$col_list_sql}) VALUES (" . implode(', ', $values) . ");\n";
            }

            if (count($rows) < $batch_size) {
                $has_more = false;
            } else {
                $offset += $batch_size;
            }
        }
        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    if ($type === 'mysqli') {
        $db->close();
    }

    return $sql;
}

function gojs_api_backup_list() {
    $backup_dir = CONFIG_DIR . '/backups';
    $backups = array();

    if (!is_dir($backup_dir)) {
        gojs_json_response(array('backups' => array()));
    }

    $files = glob($backup_dir . '/backup-*.zip');
    if (!is_array($files)) $files = array();

    foreach ($files as $file) {
        $basename = basename($file);
        if (!gojs_backup_filename_valid($basename)) continue;

        $meta = null;
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($file) === true) {
                $meta_content = $zip->getFromName('backup.json');
                if ($meta_content) {
                    $decoded = json_decode($meta_content, true);
                    if (is_array($decoded)) {
                        $meta = $decoded;
                    }
                }
                $zip->close();
            }
        }

        $backups[] = array(
            'filename' => $basename,
            'size' => (int)@filesize($file),
            'created' => (int)@filemtime($file),
            'metadata' => $meta,
        );
    }

    // 按时间倒序
    usort($backups, function ($a, $b) {
        return $b['created'] - $a['created'];
    });

    gojs_json_response(array('backups' => $backups));
}

function gojs_api_backup_download() {
    $filename = gojs_get_param('filename', '');
    if (!gojs_backup_filename_valid($filename)) {
        gojs_json_response(null, array(
            'code' => 'invalid_filename',
            'message' => '无效的备份文件名',
        ), 400);
    }

    $file = CONFIG_DIR . '/backups/' . $filename;
    if (!is_file($file)) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '备份文件不存在',
        ), 404);
    }

    gojs_log_operation('backup_download', $filename, true);

    // 清空所有缓冲区，避免 corrupting binary output
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    gojs_monitor_bump_bandwidth(0, filesize($file));
    readfile($file);
    exit;
}

function gojs_api_backup_delete() {
    $filename = gojs_get_param('filename', '');
    if (!gojs_backup_filename_valid($filename)) {
        gojs_json_response(null, array(
            'code' => 'invalid_filename',
            'message' => '无效的备份文件名',
        ), 400);
    }

    $file = CONFIG_DIR . '/backups/' . $filename;
    if (!is_file($file)) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '备份文件不存在',
        ), 404);
    }

    $result = @unlink($file);
    gojs_log_operation('backup_delete', $filename, $result);

    if (!$result) {
        gojs_json_response(null, array(
            'code' => 'delete_failed',
            'message' => '删除备份文件失败',
        ), 500);
    }

    gojs_json_response(array('success' => true));
}

// 恢复备份：从已存在的备份包中还原文件、数据库、配置
function gojs_api_backup_restore() {
    $filename = gojs_get_param('filename', '');
    if (!gojs_backup_filename_valid($filename)) {
        gojs_json_response(null, array(
            'code' => 'invalid_filename',
            'message' => '无效的备份文件名',
        ), 400);
    }

    $file = CONFIG_DIR . '/backups/' . $filename;
    if (!is_file($file)) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '备份文件不存在',
        ), 404);
    }

    if (!class_exists('ZipArchive')) {
        gojs_json_response(null, array(
            'code' => 'zip_not_available',
            'message' => 'ZipArchive 扩展不可用',
        ), 500);
    }

    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        gojs_json_response(null, array(
            'code' => 'zip_open_failed',
            'message' => '打开备份包失败',
        ), 500);
    }

    @set_time_limit(0);

    $files_root = $GLOBALS['files_root'];
    $restored_files = 0;
    $restored_db = 0;
    $db_errors = array();

    // 还原站点文件
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->statIndex($i);
        if (!$entry) continue;
        $name = $entry['name'];

        if (strpos($name, 'files/') !== 0 || $name === 'files/') continue;

        $relative = substr($name, strlen('files/'));
        if ($relative === '' || $relative === false) continue;

        // 路径遍历防护：拒绝包含 .. 段的相对路径
        $parts = explode('/', $relative);
        $traversal = false;
        foreach ($parts as $p) {
            if ($p === '..') { $traversal = true; break; }
        }
        if ($traversal) continue;

        $dest = $files_root . '/' . $relative;

        // 不覆盖面板自身敏感文件
        if (gojs_is_protected_path($dest)) continue;

        if (substr($name, -1) === '/') {
            if (!is_dir($dest)) @mkdir($dest, 0755, true);
        } else {
            $dir = dirname($dest);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                if (@file_put_contents($dest, $content) !== false) {
                    $restored_files++;
                }
            }
        }
    }

    // 还原数据库（按 connection id 匹配已配置的连接）
    $connections = gojs_load_db_connections();
    $conn_by_id = array();
    foreach ($connections as $conn) {
        if (!empty($conn['id'])) {
            $conn_by_id[$conn['id']] = $conn;
        }
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->statIndex($i);
        if (!$entry) continue;
        $name = $entry['name'];

        if (strpos($name, 'database/') !== 0) continue;
        if (substr($name, -4) !== '.sql') continue;

        $conn_id = basename($name, '.sql');
        if (!isset($conn_by_id[$conn_id])) continue;

        $conn_config = gojs_get_db_connection($conn_id);
        if (!$conn_config) continue;

        $sql_content = $zip->getFromIndex($i);
        if ($sql_content === false) continue;

        $result = gojs_db_connect($conn_config);
        if (!$result['success']) {
            $db_errors[] = (isset($conn_config['name']) ? $conn_config['name'] : $conn_id) . ': ' . $result['error'];
            continue;
        }

        $db = $result['connection'];
        $type = $result['type'];

        @$db->query('SET FOREIGN_KEY_CHECKS=0');
        @$db->query('SET NAMES utf8');
        @$db->query('SET SQL_MODE=""');

        $statements = gojs_sql_split_statements($sql_content);
        foreach ($statements as $stmt) {
            $stmt = gojs_sql_strip_comments($stmt);
            if ($stmt === '') continue;
            @$db->query($stmt);
        }

        $restored_db++;

        if ($type === 'mysqli') {
            $db->close();
        }
    }

    $zip->close();

    $ok = $restored_files > 0 || $restored_db > 0;
    gojs_log_operation('backup_restore', $filename, $ok, 'files=' . $restored_files . ', db=' . $restored_db);

    gojs_json_response(array(
        'restored_files' => $restored_files,
        'restored_db' => $restored_db,
        'db_errors' => $db_errors,
    ));
}

/**
 * SSL 证书状态检测：通过 stream_socket_client 检测域名 SSL 到期时间与链路状态，
 * 仅做状态展示，不自动续期。
 */
function gojs_api_ssl_check() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = array();
    }
    $domain = isset($input['domain']) ? trim($input['domain']) : '';

    if (empty($domain)) {
        gojs_json_response(null, array(
            'code' => 'domain_required',
            'message' => '请提供域名',
        ), 400);
    }

    // 验证域名格式（支持 localhost、IP 及无 TLD 的内网域名）
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]*(\.[a-zA-Z]{2,})?$/', $domain)) {
        gojs_json_response(null, array(
            'code' => 'invalid_domain',
            'message' => '域名格式无效',
        ), 400);
    }

    // 检查 openssl 扩展
    if (!extension_loaded('openssl')) {
        gojs_json_response(array(
            'domain' => $domain,
            'enabled' => false,
            'status' => 'failed',
            'error' => 'openssl_not_available',
            'error_key' => 'openssl_unavailable',
            'message' => 'OpenSSL 扩展不可用，无法检测 SSL 证书',
        ));
    }

    $port = 443;
    $timeout = 10;

    // 尝试连接
    $context = stream_context_create(array(
        'ssl' => array(
            'capture_peer_cert' => true,
            'capture_peer_chain' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ),
    ));

    $socket = @stream_socket_client(
        "ssl://{$domain}:{$port}",
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if ($socket === false) {
        gojs_json_response(array(
            'domain' => $domain,
            'enabled' => false,
            'status' => 'failed',
            'error' => 'connection_failed',
            'error_key' => 'connect_failed',
            'error_params' => array('detail' => "{$domain}:443 — {$errstr}"),
            'message' => "无法连接到 {$domain}:443 — {$errstr}",
        ));
    }

    $params = stream_context_get_params($socket);
    fclose($socket);

    if (!isset($params['options']['ssl']['peer_certificate'])) {
        gojs_json_response(array(
            'domain' => $domain,
            'enabled' => false,
            'status' => 'failed',
            'error' => 'no_certificate',
            'error_key' => 'certificate_empty',
            'message' => '未获取到证书数据',
        ));
    }

    $cert = $params['options']['ssl']['peer_certificate'];
    $cert_info = openssl_x509_parse($cert);

    if (!$cert_info) {
        gojs_json_response(array(
            'domain' => $domain,
            'enabled' => false,
            'status' => 'failed',
            'error' => 'parse_failed',
            'error_key' => 'certificate_parse_error',
            'message' => '证书解析失败',
        ));
    }

    $valid_from = $cert_info['validFrom_time_t'];
    $valid_to = $cert_info['validTo_time_t'];
    $now = time();
    $days_remaining = (int)(($valid_to - $now) / 86400);

    // 链路完整性
    $chain_complete = isset($params['options']['ssl']['peer_certificate_chain']) &&
                      count($params['options']['ssl']['peer_certificate_chain']) > 1;

    // 证书健康状态判断
    $cert_status = 'ok';
    if ($days_remaining < 0) {
        $cert_status = 'expired';
    } else if ($days_remaining < 7) {
        $cert_status = 'critical';
    } else if ($days_remaining < 14) {
        $cert_status = 'warning';
    }

    gojs_json_response(array(
        'domain' => $domain,
        'enabled' => true,
        'status' => 'ok',
        'cert_status' => $cert_status,
        'issuer' => isset($cert_info['issuer']['CN']) ? $cert_info['issuer']['CN'] : (isset($cert_info['issuer']['O']) ? $cert_info['issuer']['O'] : 'Unknown'),
        'subject' => isset($cert_info['subject']['CN']) ? $cert_info['subject']['CN'] : $domain,
        'valid_from' => date('Y-m-d', $valid_from),
        'valid_to' => date('Y-m-d', $valid_to),
        'days_remaining' => $days_remaining,
        'chain_complete' => $chain_complete,
    ));
}

function gojs_api_ssl_list() {
    global $config;

    $domains = isset($config['ssl_domains']) ? $config['ssl_domains'] : array();
    if (!is_array($domains)) {
        $domains = array();
    }

    // 自动添加当前域名
    $current_domain = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    // 去掉端口部分
    if ($current_domain && strpos($current_domain, ':') !== false) {
        $current_domain = substr($current_domain, 0, strpos($current_domain, ':'));
    }
    if ($current_domain && !in_array($current_domain, $domains)) {
        array_unshift($domains, $current_domain);
    }

    gojs_json_response(array('domains' => array_values($domains)));
}

function gojs_api_ssl_add_domain() {
    global $config;

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = array();
    }
    $domain = isset($input['domain']) ? trim($input['domain']) : '';

    if (empty($domain)) {
        gojs_json_response(null, array(
            'code' => 'domain_required',
            'message' => '请提供域名',
        ), 400);
    }

    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]*(\.[a-zA-Z]{2,})?$/', $domain)) {
        gojs_json_response(null, array(
            'code' => 'invalid_domain',
            'message' => '域名格式无效',
        ), 400);
    }

    if (!isset($config['ssl_domains']) || !is_array($config['ssl_domains'])) {
        $config['ssl_domains'] = array();
    }
    if (!in_array($domain, $config['ssl_domains'])) {
        $config['ssl_domains'][] = $domain;
        gojs_save_config();
    }

    gojs_log_operation('ssl_add_domain', $domain, true);
    gojs_json_response(array('ok' => true));
}

function gojs_api_ssl_remove_domain() {
    global $config;

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = array();
    }
    $domain = isset($input['domain']) ? trim($input['domain']) : '';

    if (isset($config['ssl_domains']) && is_array($config['ssl_domains'])) {
        $config['ssl_domains'] = array_values(array_filter($config['ssl_domains'], function($d) use ($domain) {
            return $d !== $domain;
        }));
        gojs_save_config();
    }

    gojs_log_operation('ssl_remove_domain', $domain, true);
    gojs_json_response(array('ok' => true));
}

define('GOJS_ACME_ACCOUNT_FILE', CONFIG_DIR . '/acme_account.json');
define('GOJS_ACME_CERTS_FILE', CONFIG_DIR . '/acme_certs.json');
define('GOJS_ACME_CHALLENGES_DIRNAME', 'acme_challenges');
define('GOJS_ACME_CHALLENGES_DIR', CONFIG_DIR . '/' . GOJS_ACME_CHALLENGES_DIRNAME);

function gojs_acme_docroot(): string {
    global $root_path;
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        return rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    }
    if (is_string($root_path) && is_dir($root_path)) {
        return rtrim($root_path, '/');
    }
    return rtrim(PANEL_ROOT, '/');
}

function gojs_acme_challenges_docroot_dir(): string {
    $docroot = gojs_acme_docroot();
    return $docroot . '/.well-known/acme-challenge';
}

function gojs_acme_ensure_dirs(): void {
    if (!is_dir(CONFIG_DIR)) {
        @mkdir(CONFIG_DIR, 0700, true);
    }
    if (!is_dir(GOJS_ACME_CHALLENGES_DIR)) {
        @mkdir(GOJS_ACME_CHALLENGES_DIR, 0700, true);
    }
    $docroot_challenges = gojs_acme_challenges_docroot_dir();
    if (!is_dir($docroot_challenges)) {
        @mkdir($docroot_challenges, 0755, true);
    }
}

function gojs_acme_base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function gojs_acme_base64url_decode(string $data): string {
    $rest = strlen($data) % 4;
    if ($rest) {
        $data .= str_repeat('=', 4 - $rest);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function gojs_acme_dir(string $ca = 'letsencrypt'): array {
    static $cache = array();
    if (isset($cache[$ca])) {
        return $cache[$ca];
    }
    $urls = array(
        'letsencrypt' => 'https://acme-v02.api.letsencrypt.org/directory',
        'letsencrypt-staging' => 'https://acme-staging-v02.api.letsencrypt.org/directory',
    );
    $dir_url = isset($urls[$ca]) ? $urls[$ca] : $urls['letsencrypt'];

    $ch = curl_init($dir_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Go.js-ACME-Client/' . VERSION);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300 || !$body) {
        throw new RuntimeException('ACME directory discovery failed for ' . $ca . ': HTTP ' . $code);
    }
    $dir = json_decode($body, true);
    if (!is_array($dir)) {
        throw new RuntimeException('ACME directory returned invalid JSON');
    }
    $cache[$ca] = array(
        'newNonce' => isset($dir['newNonce']) ? $dir['newNonce'] : '',
        'newAccount' => isset($dir['newAccount']) ? $dir['newAccount'] : '',
        'newOrder' => isset($dir['newOrder']) ? $dir['newOrder'] : '',
        'revokeCert' => isset($dir['revokeCert']) ? $dir['revokeCert'] : '',
        'keyChange' => isset($dir['keyChange']) ? $dir['keyChange'] : '',
    );
    return $cache[$ca];
}

function gojs_acme_get_nonce(string $ca = 'letsencrypt', string $last_nonce = ''): string {
    static $last = array();
    if ($last_nonce !== '') {
        $last[$ca] = $last_nonce;
    }
    if (!empty($last[$ca])) {
        $n = $last[$ca];
        $last[$ca] = '';
        return $n;
    }
    $dir = gojs_acme_dir($ca);
    $ch = curl_init($dir['newNonce']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Go.js-ACME-Client/' . VERSION);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return '';
    if (preg_match('/^Replay-Nonce:\s*(\S+)/mi', $resp, $m)) {
        return trim($m[1]);
    }
    return '';
}

function gojs_acme_rsa_jwk_from_pkey($pkey): array {
    $details = openssl_pkey_get_details($pkey);
    if (!$details || empty($details['rsa'])) {
        throw new RuntimeException('Unable to extract RSA key details');
    }
    $rsa = $details['rsa'];
    return array(
        'kty' => 'RSA',
        'n' => gojs_acme_base64url_encode($rsa['n']),
        'e' => gojs_acme_base64url_encode($rsa['e']),
    );
}

function gojs_acme_thumbprint(string $jwk_json_or_array): string {
    if (is_array($jwk_json_or_array)) {
        $jwk = $jwk_json_or_array;
    } else {
        $jwk = json_decode($jwk_json_or_array, true);
        if (!is_array($jwk)) $jwk = array();
    }
    $canonical = json_encode(array(
        'e' => isset($jwk['e']) ? $jwk['e'] : '',
        'kty' => isset($jwk['kty']) ? $jwk['kty'] : '',
        'n' => isset($jwk['n']) ? $jwk['n'] : '',
    ), JSON_UNESCAPED_SLASHES);
    $hash = hash('sha256', $canonical, true);
    return gojs_acme_base64url_encode($hash);
}

function gojs_acme_signed_request(string $url, $payload, $account_or_jwk, string $nonce, string $ca = 'letsencrypt', int $depth = 0): array {
    $is_jwk = is_array($account_or_jwk) && isset($account_or_jwk['kty']);
    $key_pem = $is_jwk ? '' : (isset($account_or_jwk['key_pem']) ? $account_or_jwk['key_pem'] : '');
    $pkey = openssl_pkey_get_private($key_pem);
    if (!$pkey) {
        throw new RuntimeException('Unable to load ACME account private key');
    }
    $jwk = $is_jwk ? $account_or_jwk : gojs_acme_rsa_jwk_from_pkey($pkey);

    $protected = array(
        'alg' => 'RS256',
        'nonce' => $nonce,
        'url' => $url,
    );
    if ($is_jwk) {
        $protected['jwk'] = $jwk;
    } else {
        if (empty($account_or_jwk['kid'])) {
            throw new RuntimeException('Account kid missing for signed request');
        }
        $protected['kid'] = $account_or_jwk['kid'];
    }

    if ($payload === '') {
        $payload_encoded = '';
    } elseif (is_array($payload)) {
        $payload_encoded = gojs_acme_base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    } else {
        $payload_encoded = gojs_acme_base64url_encode((string)$payload);
    }
    $protected_encoded = gojs_acme_base64url_encode(json_encode($protected, JSON_UNESCAPED_SLASHES));
    $signing_input = $protected_encoded . '.' . $payload_encoded;

    $signature = '';
    openssl_sign($signing_input, $signature, $pkey, 'sha256WithRSAEncryption');
    $sig_encoded = gojs_acme_base64url_encode($signature);

    $body = json_encode(array(
        'protected' => $protected_encoded,
        'payload' => $payload_encoded,
        'signature' => $sig_encoded,
    ));

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/jose+json',
        'Accept: application/json, application/problem+json',
    ));
    curl_setopt($ch, CURLOPT_USERAGENT, 'Go.js-ACME-Client/' . VERSION);
    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers_raw = substr($response, 0, $header_size);
    $resp_body = substr($response, $header_size);
    curl_close($ch);

    $new_nonce = '';
    if (preg_match('/^Replay-Nonce:\s*(\S+)/mi', $headers_raw, $m)) {
        $new_nonce = trim($m[1]);
        gojs_acme_get_nonce($ca, $new_nonce);
    }
    $location = '';
    if (preg_match('/^Location:\s*(\S+)/mi', $headers_raw, $m)) {
        $location = trim($m[1]);
    }
    $retry_after = 0;
    if (preg_match('/^Retry-After:\s*(\d+)/mi', $headers_raw, $m)) {
        $retry_after = (int)$m[1];
    }

    $parsed = json_decode($resp_body, true);
    $body_result = is_array($parsed) ? $parsed : $resp_body;

    if ($code >= 400 && is_array($body_result) && isset($body_result['type']) && $body_result['type'] === 'urn:ietf:params:acme:error:badNonce' && $depth === 0) {
        $new_nonce_retry = gojs_acme_get_nonce($ca);
        return gojs_acme_signed_request($url, $payload, $account_or_jwk, $new_nonce_retry, $ca, 1);
    }

    return array(
        'code' => $code,
        'body' => $body_result,
        'retry_after' => $retry_after,
        'location' => $location,
    );
}

function gojs_acme_load_account(): array {
    if (!file_exists(GOJS_ACME_ACCOUNT_FILE)) {
        return array();
    }
    $raw = @file_get_contents(GOJS_ACME_ACCOUNT_FILE);
    if (!$raw) return array();
    $data = json_decode($raw, true);
    if (!is_array($data)) return array();
    return $data;
}

function gojs_acme_save_account(array $account): void {
    gojs_acme_ensure_dirs();
    @file_put_contents(GOJS_ACME_ACCOUNT_FILE, json_encode($account, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    @chmod(GOJS_ACME_ACCOUNT_FILE, 0600);
}

function gojs_acme_ensure_account(string $email, string $ca = 'letsencrypt', bool $terms_agreed = true): array {
    gojs_acme_ensure_dirs();
    $stored = gojs_acme_load_account();
    $key_pem = '';
    $ca_key = $ca . '_' . md5(strtolower($email));

    if (isset($stored[$ca_key]) && is_array($stored[$ca_key])) {
        $existing = $stored[$ca_key];
        if (!empty($existing['account_key_pem_enc_sealed'])) {
            $pem = gojs_decrypt($existing['account_key_pem_enc_sealed']);
            if (is_string($pem) && $pem !== '' && !empty($existing['kid'])) {
                return array(
                    'account_url' => isset($existing['account_url']) ? $existing['account_url'] : '',
                    'kid' => $existing['kid'],
                    'account_key_pem_enc_sealed' => $existing['account_key_pem_enc_sealed'],
                    'key_pem_plain' => $pem,
                );
            }
        }
    }

    $pkey = openssl_pkey_new(array(
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ));
    if (!$pkey) {
        throw new RuntimeException('Failed to generate RSA account key');
    }
    $pem_export = '';
    openssl_pkey_export($pkey, $pem_export);
    if ($pem_export === '' || $pem_export === null) {
        throw new RuntimeException('Failed to export account private key');
    }
    $sealed = gojs_seal_secret($pem_export);
    $jwk = gojs_acme_rsa_jwk_from_pkey($pkey);

    $nonce = gojs_acme_get_nonce($ca);
    $dir = gojs_acme_dir($ca);
    $payload = array(
        'termsOfServiceAgreed' => $terms_agreed,
        'contact' => array('mailto:' . $email),
    );
    $account_wrap = array('key_pem' => $pem_export);
    $resp = gojs_acme_signed_request($dir['newAccount'], $payload, $jwk, $nonce, $ca);

    if ($resp['code'] !== 200 && $resp['code'] !== 201) {
        $msg = is_array($resp['body']) && isset($resp['body']['detail']) ? $resp['body']['detail'] : 'HTTP ' . $resp['code'];
        throw new RuntimeException('ACME newAccount failed: ' . $msg);
    }
    $kid = $resp['location'];
    if ($kid === '') {
        throw new RuntimeException('ACME server did not return account location (kid)');
    }

    $stored[$ca_key] = array(
        'email' => $email,
        'ca' => $ca,
        'kid' => $kid,
        'account_url' => $kid,
        'account_key_pem_enc_sealed' => $sealed,
        'jwk_thumbprint' => gojs_acme_thumbprint($jwk),
        'registered_at' => time(),
    );
    gojs_acme_save_account($stored);

    return array(
        'account_url' => $kid,
        'kid' => $kid,
        'account_key_pem_enc_sealed' => $sealed,
        'key_pem_plain' => $pem_export,
    );
}

function gojs_acme_load_certs(): array {
    if (!file_exists(GOJS_ACME_CERTS_FILE)) {
        return array();
    }
    $raw = @file_get_contents(GOJS_ACME_CERTS_FILE);
    if (!$raw) return array();
    $data = json_decode($raw, true);
    if (!is_array($data)) return array();
    return $data;
}

function gojs_acme_save_certs(array $records): void {
    gojs_acme_ensure_dirs();
    @file_put_contents(GOJS_ACME_CERTS_FILE, json_encode($records, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    @chmod(GOJS_ACME_CERTS_FILE, 0600);
}

function gojs_acme_place_http01(string $domain, string $token, $jwk): bool {
    gojs_acme_ensure_dirs();
    if (is_array($jwk) && isset($jwk['kty'])) {
        $thumb = gojs_acme_thumbprint($jwk);
    } else {
        $stored = gojs_acme_load_account();
        $first = reset($stored);
        $thumb = is_array($first) && isset($first['jwk_thumbprint']) ? $first['jwk_thumbprint'] : gojs_acme_thumbprint($jwk);
    }
    $content = $token . '.' . $thumb;

    $ok = false;

    $docroot_dir = gojs_acme_challenges_docroot_dir();
    if (!is_dir($docroot_dir)) {
        @mkdir($docroot_dir, 0755, true);
    }
    $target = $docroot_dir . '/' . $token;
    $wrote = @file_put_contents($target, $content);
    if ($wrote !== false) {
        @chmod($target, 0644);
        $ok = true;
    }

    $fallback_dir = GOJS_ACME_CHALLENGES_DIR;
    if (!is_dir($fallback_dir)) {
        @mkdir($fallback_dir, 0700, true);
    }
    @file_put_contents($fallback_dir . '/' . $token, $content);
    @chmod($fallback_dir . '/' . $token, 0600);

    return $ok;
}

function gojs_acme_order(string $domain, string $email, string $ca = 'letsencrypt'): array {
    $account = gojs_acme_ensure_account($email, $ca, true);
    $nonce = gojs_acme_get_nonce($ca);
    $dir = gojs_acme_dir($ca);

    $identifiers = array(array('type' => 'dns', 'value' => $domain));
    $payload = array('identifiers' => $identifiers);
    $account_wrap = array('key_pem' => $account['key_pem_plain'], 'kid' => $account['kid']);

    $resp = gojs_acme_signed_request($dir['newOrder'], $payload, $account_wrap, $nonce, $ca);

    if ($resp['code'] !== 201) {
        $msg = is_array($resp['body']) && isset($resp['body']['detail']) ? $resp['body']['detail'] : 'HTTP ' . $resp['code'];
        return array('ok' => false, 'error' => 'ACME newOrder failed: ' . $msg);
    }

    $order_url = $resp['location'];
    $body = is_array($resp['body']) ? $resp['body'] : array();
    $authorizations = isset($body['authorizations']) && is_array($body['authorizations']) ? $body['authorizations'] : array();

    $auth_details = array();
    foreach ($authorizations as $auth_url) {
        $auth_nonce = gojs_acme_get_nonce($ca);
        $auth_resp = gojs_acme_signed_request($auth_url, '', $account_wrap, $auth_nonce, $ca);
        if ($auth_resp['code'] === 200 && is_array($auth_resp['body'])) {
            $ab = $auth_resp['body'];
            $challenges = array();
            if (isset($ab['challenges']) && is_array($ab['challenges'])) {
                foreach ($ab['challenges'] as $c) {
                    if (is_array($c) && isset($c['type']) && $c['type'] === 'http-01') {
                        $challenges[] = array(
                            'type' => 'http-01',
                            'token' => isset($c['token']) ? $c['token'] : '',
                            'url' => isset($c['url']) ? $c['url'] : '',
                            'status' => isset($c['status']) ? $c['status'] : 'pending',
                        );
                    }
                }
            }
            $auth_details[] = array(
                'identifier' => isset($ab['identifier']) ? $ab['identifier'] : array(),
                'challenges' => $challenges,
                'auth_url' => $auth_url,
            );
        }
    }

    $finalize_url = isset($body['finalize']) ? $body['finalize'] : '';

    return array(
        'ok' => true,
        'order_url' => $order_url,
        'finalize_url' => $finalize_url,
        'authorizations' => $auth_details,
        '_account' => $account,
    );
}

function gojs_acme_respond_challenge(string $challenge_url, $account_wrap, string $ca = 'letsencrypt'): array {
    $nonce = gojs_acme_get_nonce($ca);
    return gojs_acme_signed_request($challenge_url, array(), $account_wrap, $nonce, $ca);
}

function gojs_acme_poll_url(string $url, $account_wrap, string $ca = 'letsencrypt', int $max_attempts = 15, int $sleep_sec = 2): array {
    for ($i = 0; $i < $max_attempts; $i++) {
        $nonce = gojs_acme_get_nonce($ca);
        $resp = gojs_acme_signed_request($url, '', $account_wrap, $nonce, $ca);
        $code = $resp['code'];
        $body = is_array($resp['body']) ? $resp['body'] : array();
        $status = isset($body['status']) ? $body['status'] : '';
        if ($code === 200 && ($status === 'valid' || $status === 'invalid' || $status === 'ready' || $status === 'processing')) {
            if ($status === 'valid' || $status === 'invalid' || $status === 'ready') {
                return $resp;
            }
        }
        $sleep = $resp['retry_after'] > 0 ? $resp['retry_after'] : $sleep_sec;
        if ($sleep > 0) sleep(min($sleep, 10));
    }
    return array('code' => 504, 'body' => array('error' => 'Poll timeout', 'detail' => 'Order/authorization did not reach valid/ready state in time'));
}

function gojs_acme_pem_to_der(string $pem): string {
    $lines = explode("\n", $pem);
    $der = '';
    $in = false;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strpos($line, '-----BEGIN') === 0) {
            $in = true;
            continue;
        }
        if (strpos($line, '-----END') === 0) {
            break;
        }
        if ($in) $der .= $line;
    }
    return base64_decode($der);
}

function gojs_acme_finalize(string $finalize_url, string $csr_pem, $account_wrap, string $ca = 'letsencrypt'): array {
    $der = gojs_acme_pem_to_der($csr_pem);
    $payload = array('csr' => gojs_acme_base64url_encode($der));
    $nonce = gojs_acme_get_nonce($ca);
    return gojs_acme_signed_request($finalize_url, $payload, $account_wrap, $nonce, $ca);
}

function gojs_acme_fetch_certificate(string $cert_url, $account_wrap, string $ca = 'letsencrypt'): string {
    $nonce = gojs_acme_get_nonce($ca);
    $resp = gojs_acme_signed_request($cert_url, '', $account_wrap, $nonce, $ca);
    if ($resp['code'] !== 200) {
        throw new RuntimeException('Failed to fetch certificate: HTTP ' . $resp['code']);
    }
    if (is_string($resp['body'])) {
        return $resp['body'];
    }
    return '';
}

function gojs_acme_split_fullchain(string $fullchain): array {
    $certs = array();
    $lines = explode("\n", $fullchain);
    $buf = '';
    $in = false;
    foreach ($lines as $line) {
        if (strpos($line, '-----BEGIN CERTIFICATE-----') !== false) {
            $in = true;
            $buf = $line . "\n";
            continue;
        }
        if ($in) {
            $buf .= $line . "\n";
            if (strpos($line, '-----END CERTIFICATE-----') !== false) {
                $in = false;
                $certs[] = trim($buf);
            }
        }
    }
    if (count($certs) === 0) return array('cert' => '', 'fullchain' => $fullchain);
    $cert = $certs[0];
    return array('cert' => $cert, 'fullchain' => $fullchain);
}

function gojs_acme_cert_parse_dates(string $cert_pem): array {
    $parsed = openssl_x509_parse($cert_pem);
    if (!$parsed) return array('not_before' => 0, 'not_after' => 0);
    $nb = isset($parsed['validFrom_time_t']) ? (int)$parsed['validFrom_time_t'] : 0;
    $na = isset($parsed['validTo_time_t']) ? (int)$parsed['validTo_time_t'] : 0;
    return array('not_before' => $nb, 'not_after' => $na);
}

function gojs_acme_cert_issuer(string $cert_pem): string {
    $parsed = openssl_x509_parse($cert_pem);
    if (!$parsed) return '';
    $issuer = isset($parsed['issuer']) ? $parsed['issuer'] : array();
    if (is_array($issuer)) {
        return isset($issuer['CN']) ? $issuer['CN'] : (isset($issuer['O']) ? $issuer['O'] : '');
    }
    return (string)$issuer;
}

function gojs_acme_issue_cert(array $payload): array {
    $domain = isset($payload['domain']) ? trim((string)$payload['domain']) : '';
    $email = isset($payload['email']) ? trim((string)$payload['email']) : '';
    $ca = isset($payload['ca']) ? (string)$payload['ca'] : 'letsencrypt';
    $accept_tos = !empty($payload['accept_tos']);

    if ($domain === '') {
        return array('ok' => false, 'error' => 'ssl.acme.domainRequired', 'error_message' => 'Domain required');
    }
    if (!filter_var('user@' . $domain, FILTER_VALIDATE_DOMAIN) && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]*\.[a-zA-Z]{2,}$/', $domain)) {
        return array('ok' => false, 'error' => 'ssl.acme.domainInvalid', 'error_message' => 'Invalid domain format');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return array('ok' => false, 'error' => 'ssl.acme.emailInvalid', 'error_message' => 'Valid email required');
    }
    if (!$accept_tos) {
        return array('ok' => false, 'error' => 'ssl.acme.acceptTosRequired', 'error_message' => 'Must accept TOS');
    }
    if (!extension_loaded('openssl')) {
        return array('ok' => false, 'error' => 'ssl.acme.opensslUnavailable', 'error_message' => 'OpenSSL extension required');
    }
    $allowed_ca = array('letsencrypt', 'letsencrypt-staging');
    if (!in_array($ca, $allowed_ca, true)) $ca = 'letsencrypt';

    try {
        $order_result = gojs_acme_order($domain, $email, $ca);
        if (!$order_result['ok']) {
            return array('ok' => false, 'error' => 'ssl.acme.orderFailed', 'error_message' => $order_result['error'] ?? 'Order failed');
        }

        $account = isset($order_result['_account']) ? $order_result['_account'] : null;
        if (!$account || empty($account['key_pem_plain'])) {
            return array('ok' => false, 'error' => 'ssl.acme.accountMissing', 'error_message' => 'Account setup failed');
        }
        $account_wrap = array('key_pem' => $account['key_pem_plain'], 'kid' => $account['kid']);
        $pkey_for_jwk = openssl_pkey_get_private($account['key_pem_plain']);
        if (!$pkey_for_jwk) {
            return array('ok' => false, 'error' => 'ssl.acme.keyLoadFailed', 'error_message' => 'Key load failed');
        }
        $jwk = gojs_acme_rsa_jwk_from_pkey($pkey_for_jwk);

        $authorizations = isset($order_result['authorizations']) ? $order_result['authorizations'] : array();
        foreach ($authorizations as $auth) {
            $challenges = isset($auth['challenges']) ? $auth['challenges'] : array();
            foreach ($challenges as $c) {
                if (isset($c['type']) && $c['type'] === 'http-01' && !empty($c['token'])) {
                    gojs_acme_place_http01($domain, $c['token'], $jwk);
                    gojs_acme_respond_challenge($c['url'], $account_wrap, $ca);
                }
            }
            $auth_url = isset($auth['auth_url']) ? $auth['auth_url'] : '';
            if ($auth_url !== '') {
                $polled = gojs_acme_poll_url($auth_url, $account_wrap, $ca, 10, 2);
                $pb = is_array($polled['body']) ? $polled['body'] : array();
                if (isset($pb['status']) && $pb['status'] === 'invalid') {
                    $detail = isset($pb['error']['detail']) ? $pb['error']['detail'] : (is_array($polled['body']) && isset($polled['body']['detail']) ? $polled['body']['detail'] : 'Authorization invalid');
                    return array('ok' => false, 'error' => 'ssl.acme.authInvalid', 'error_message' => 'Authorization failed: ' . $detail);
                }
            }
        }

        $order_url = $order_result['order_url'];
        $order_poll = gojs_acme_poll_url($order_url, $account_wrap, $ca, 10, 2);
        $order_body = is_array($order_poll['body']) ? $order_poll['body'] : array();
        $order_status = isset($order_body['status']) ? $order_body['status'] : '';
        if ($order_status !== 'ready') {
            if ($order_status === 'invalid') {
                $detail = isset($order_body['error']['detail']) ? $order_body['error']['detail'] : 'Order invalid';
                return array('ok' => false, 'error' => 'ssl.acme.orderInvalid', 'error_message' => 'Order invalid: ' . $detail);
            }
        }

        $cert_pkey = openssl_pkey_new(array(
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ));
        if (!$cert_pkey) {
            return array('ok' => false, 'error' => 'ssl.acme.certKeyGenFailed', 'error_message' => 'Certificate key generation failed');
        }
        $cert_privkey_pem = '';
        openssl_pkey_export($cert_pkey, $cert_privkey_pem);

        $san = '';
        $dn = array(
            'countryName' => 'XX',
            'organizationName' => 'Go.js ACME',
            'commonName' => $domain,
        );
        $csr = openssl_csr_new($dn, $cert_pkey, array('digest_alg' => 'sha256'));
        if ($csr === false) {
            return array('ok' => false, 'error' => 'ssl.acme.csrFailed', 'error_message' => 'CSR generation failed');
        }
        $csr_pem = '';
        openssl_csr_export($csr, $csr_pem);

        $finalize_url = isset($order_result['finalize_url']) ? $order_result['finalize_url'] : '';
        if ($finalize_url === '' && is_array($order_body)) {
            $finalize_url = isset($order_body['finalize']) ? $order_body['finalize'] : '';
        }
        gojs_acme_finalize($finalize_url, $csr_pem, $account_wrap, $ca);

        $order_final = gojs_acme_poll_url($order_url, $account_wrap, $ca, 15, 3);
        $final_body = is_array($order_final['body']) ? $order_final['body'] : array();
        $final_status = isset($final_body['status']) ? $final_body['status'] : '';
        if ($final_status !== 'valid') {
            $detail = isset($final_body['error']['detail']) ? $final_body['error']['detail'] : 'Order did not finalize (status=' . $final_status . ')';
            return array('ok' => false, 'error' => 'ssl.acme.finalizeFailed', 'error_message' => 'Finalize failed: ' . $detail);
        }
        $cert_url = isset($final_body['certificate']) ? $final_body['certificate'] : '';
        if ($cert_url === '') {
            return array('ok' => false, 'error' => 'ssl.acme.certUrlMissing', 'error_message' => 'Certificate URL missing');
        }
        $fullchain = gojs_acme_fetch_certificate($cert_url, $account_wrap, $ca);
        $split = gojs_acme_split_fullchain($fullchain);
        $cert_pem = $split['cert'];
        $dates = gojs_acme_cert_parse_dates($cert_pem);
        $issuer = gojs_acme_cert_issuer($cert_pem);

        $records = gojs_acme_load_certs();
        $id = 'cert_' . uniqid('', true);
        $record = array(
            'id' => $id,
            'domain' => $domain,
            'status' => 'valid',
            'not_before_ts' => $dates['not_before'],
            'not_after_ts' => $dates['not_after'],
            'last_ordered_at' => time(),
            'auto_renew_days_before' => 30,
            'cert_pem_enc' => gojs_seal_secret($cert_pem),
            'fullchain_pem_enc' => gojs_seal_secret($fullchain),
            'privkey_pem_enc' => gojs_seal_secret($cert_privkey_pem),
            'issuer_url' => $issuer,
            'chain_thumbprint' => gojs_acme_thumbprint($jwk),
            'ca' => $ca,
            'san_domains' => array($domain),
            'account_email' => $email,
        );
        $records[] = $record;
        gojs_acme_save_certs($records);

        gojs_append_notification(array(
            'category' => 'ssl',
            'severity' => 'success',
            'title_key' => 'ssl.acme.certIssuedTitle',
            'body_key' => 'ssl.acme.certIssuedBody',
            'body_params' => array('domain' => $domain),
            'payload' => array('certificate_id' => $id, 'domain' => $domain),
        ));
        gojs_log_operation('ssl_acme_issue', $domain, true, 'issued cert id=' . $id);

        return array(
            'ok' => true,
            'certificate_id' => $id,
            'cert_pem' => $cert_pem,
            'fullchain_pem' => $fullchain,
            'privkey_pem' => $cert_privkey_pem,
            'not_before' => $dates['not_before'],
            'not_after' => $dates['not_after'],
            'issuer' => $issuer,
        );
    } catch (Throwable $e) {
        gojs_append_notification(array(
            'category' => 'ssl',
            'severity' => 'error',
            'title_key' => 'ssl.acme.certIssueFailedTitle',
            'body_key' => 'ssl.acme.certIssueFailedBody',
            'body_params' => array('domain' => $domain, 'error' => $e->getMessage()),
            'payload' => array('domain' => $domain),
        ));
        return array('ok' => false, 'error' => 'ssl.acme.exception', 'error_message' => $e->getMessage());
    }
}

function gojs_acme_renew_all_due(): array {
    $records = gojs_acme_load_certs();
    $now = time();
    $renewed = 0;
    $skipped = 0;
    $failed = 0;
    $paused = 0;
    $failed_records = array();

    // 收集每条的字段更新，循环结束后统一 load + 按 id 更新再 save
    $updates = array();

    foreach ($records as $i => $r) {
        if (!is_array($r)) continue;
        $status = isset($r['status']) ? $r['status'] : '';
        if ($status !== 'valid') {
            $skipped++;
            continue;
        }
        $not_after = isset($r['not_after_ts']) ? (int)$r['not_after_ts'] : 0;
        $days_before = isset($r['auto_renew_days_before']) ? (int)$r['auto_renew_days_before'] : 30;
        if ($days_before <= 0) {
            $skipped++;
            continue;
        }
        if (($not_after - $now) >= ($days_before * 86400)) {
            $skipped++;
            continue;
        }
        $id = isset($r['id']) ? $r['id'] : '';
        $domain = isset($r['domain']) ? $r['domain'] : '';
        $email = isset($r['account_email']) ? $r['account_email'] : '';
        $ca = isset($r['ca']) ? $r['ca'] : 'letsencrypt';

        // 冷却防抖：连续失败 >= 5 次则跳过自动续期，避免 webcron 反复触发被打
        $renew_attempts = isset($r['renew_attempts']) ? (int)$r['renew_attempts'] : 0;
        if ($renew_attempts >= 5) {
            $paused++;
            if ($id !== '') {
                $updates[$id] = array('auto_paused' => true);
            }
            continue;
        }

        if ($domain === '' || $email === '') {
            $failed++;
            if ($id !== '') {
                $updates[$id] = array(
                    'last_renew_error' => 'ssl.acme.missingInfo',
                    'last_renew_attempt_ts' => $now,
                    'renew_attempts' => $renew_attempts + 1,
                    'auto_paused' => ($renew_attempts + 1 >= 5),
                );
                $failed_records[] = array('domain' => $domain, 'error' => 'ssl.acme.missingInfo');
            }
            continue;
        }
        $result = gojs_acme_issue_cert(array(
            'domain' => $domain,
            'email' => $email,
            'accept_tos' => true,
            'ca' => $ca,
        ));
        if (!empty($result['ok'])) {
            if (isset($result['certificate_id'])) {
                $new_id = $result['certificate_id'];
                $recs_reloaded = gojs_acme_load_certs();
                $old_id = $id;
                $recs_reloaded = array_values(array_filter($recs_reloaded, function($x) use ($old_id) {
                    return !is_array($x) || !isset($x['id']) || $x['id'] !== $old_id;
                }));
                gojs_acme_save_certs($recs_reloaded);
            }
            // 成功：记录成功时间并清空失败计数（旧记录被删除时此更新自然跳过）
            if ($id !== '') {
                $updates[$id] = array(
                    'last_renew_ok_ts' => $now,
                    'last_renew_error' => null,
                    'last_renew_attempt_ts' => null,
                    'renew_attempts' => 0,
                    'auto_paused' => false,
                );
            }
            $renewed++;
        } else {
            $failed++;
            $error = isset($result['error']) && is_string($result['error']) && $result['error'] !== ''
                ? $result['error']
                : 'unknown';
            if ($id !== '') {
                $updates[$id] = array(
                    'last_renew_error' => $error,
                    'last_renew_attempt_ts' => $now,
                    'renew_attempts' => $renew_attempts + 1,
                    'auto_paused' => ($renew_attempts + 1 >= 5),
                );
                $failed_records[] = array('domain' => $domain, 'error' => $error);
            }
        }
    }

    // 循环结束后重新 load，按 id 应用更新（已被删除的旧 id 自动跳过）
    if (count($updates) > 0) {
        $records_now = gojs_acme_load_certs();
        $changed = false;
        foreach ($records_now as $i => $rr) {
            if (!is_array($rr) || !isset($rr['id'])) continue;
            $rid = $rr['id'];
            if (!isset($updates[$rid])) continue;
            foreach ($updates[$rid] as $k => $v) {
                $records_now[$i][$k] = $v;
            }
            $changed = true;
        }
        if ($changed) {
            gojs_acme_save_certs($records_now);
        }
    }

    return array(
        'renewed_count' => $renewed,
        'skipped' => $skipped,
        'failed' => $failed,
        'paused' => $paused,
        'failed_records' => $failed_records,
    );
}

function gojs_acme_schedule_register_cronjob(): void {
    global $config;
    if (!isset($config['acme_last_renew_check_ts'])) {
        $config['acme_last_renew_check_ts'] = 0;
    }
}

function gojs_internal_cron_acme_renew_hook(array &$stats): void {
    global $config;
    $now = time();
    $key = 'acme_last_renew_check_ts';
    $last = isset($config[$key]) ? (int)$config[$key] : 0;
    if (($now - $last) < 82800) {
        return;
    }
    $config[$key] = $now;
    gojs_save_config();
    $renew_stats = gojs_acme_renew_all_due();
    if (is_array($renew_stats)) {
        $stats['acme_renewed'] = isset($renew_stats['renewed_count']) ? $renew_stats['renewed_count'] : 0;
        $stats['acme_skipped'] = isset($renew_stats['skipped']) ? $renew_stats['skipped'] : 0;
        $stats['acme_failed'] = isset($renew_stats['failed']) ? $renew_stats['failed'] : 0;
    }
}

function gojs_api_ssl_acme_capabilities(): void {
    gojs_acme_ensure_dirs();
    $docroot = gojs_acme_docroot();
    $docroot_known = !empty($_SERVER['DOCUMENT_ROOT']) || is_dir($docroot);
    $challenges_dir = gojs_acme_challenges_docroot_dir();
    if (!is_dir($challenges_dir)) {
        @mkdir($challenges_dir, 0755, true);
    }
    $challenges_writable = is_dir($challenges_dir) && is_writable($challenges_dir);
    $openssl_ok = extension_loaded('openssl');
    $curl_ok = extension_loaded('curl');
    $ext_ok = $openssl_ok && ($curl_ok || ini_get('allow_url_fopen'));
    $available = $ext_ok && $challenges_writable;
    $reason_key = null;
    if (!$openssl_ok) $reason_key = 'ssl.acme.subheaderNeedExtOpenssl';
    elseif (!$docroot_known) $reason_key = 'ssl.acme.subheaderDocrootUnknown';
    elseif (!$challenges_writable) $reason_key = 'ssl.acme.subheaderChallengesNotWritable';
    gojs_json_response(array(
        'available' => $available,
        'acme_extensions_ok' => $ext_ok,
        'docroot_known' => $docroot_known,
        'challenges_dir_writable' => $challenges_writable,
        'reason_key' => $reason_key,
    ));
}

function gojs_api_ssl_acme_certificates_list(): void {
    $records = gojs_acme_load_certs();
    $now = time();
    $result = array();
    foreach ($records as $r) {
        if (!is_array($r)) continue;
        $redacted = $r;
        unset($redacted['privkey_pem_enc']);
        $not_after = isset($r['not_after_ts']) ? (int)$r['not_after_ts'] : 0;
        $days_before = isset($r['auto_renew_days_before']) ? (int)$r['auto_renew_days_before'] : 30;
        $status_derived = isset($r['status']) ? $r['status'] : 'pending';
        if ($status_derived === 'valid') {
            if ($not_after > 0 && $now >= $not_after) {
                $status_derived = 'expired';
            } elseif ($not_after > 0 && ($not_after - $now) < ($days_before * 86400)) {
                $status_derived = 'expiring_soon';
            }
        }
        // 续期失败状态：已自动暂停（连续失败 >= 5 次）且证书未过期 → renew_failed
        if (($status_derived === 'valid' || $status_derived === 'expiring_soon') && $not_after > 0 && $now < $not_after) {
            $auto_paused = !empty($r['auto_paused']);
            $renew_attempts = isset($r['renew_attempts']) ? (int)$r['renew_attempts'] : 0;
            if ($auto_paused || $renew_attempts >= 5) {
                $status_derived = 'renew_failed';
            }
        }
        $redacted['status_derived'] = $status_derived;
        $result[] = $redacted;
    }
    gojs_json_response(array('records' => $result));
}

function gojs_api_ssl_acme_issue_cert(): void {
    $payload = gojs_get_body();
    if (!is_array($payload)) $payload = array();
    gojs_log_operation('ssl_acme_issue_start', isset($payload['domain']) ? $payload['domain'] : '', true);
    $result = gojs_acme_issue_cert($payload);
    if (!empty($result['ok'])) {
        gojs_json_response(array(
            'ok' => true,
            'certificate_id' => isset($result['certificate_id']) ? $result['certificate_id'] : '',
        ), null, 202);
    } else {
        $code = isset($result['error']) ? $result['error'] : 'ssl.acme.issueFailed';
        $msg = isset($result['error_message']) ? $result['error_message'] : 'Certificate issuance failed';
        gojs_json_response(null, array('code' => $code, 'message' => $msg, 'message_key' => $code), 400);
    }
}

function gojs_api_ssl_acme_cert_renew(string $id): void {
    $records = gojs_acme_load_certs();
    $found = null;
    $found_idx = -1;
    foreach ($records as $i => $r) {
        if (is_array($r) && isset($r['id']) && $r['id'] === $id) {
            $found = $r;
            $found_idx = $i;
            break;
        }
    }
    if ($found === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'Certificate not found', 'message_key' => 'ssl.acme.certNotFound'), 404);
        return;
    }
    $domain = isset($found['domain']) ? $found['domain'] : '';
    $email = isset($found['account_email']) ? $found['account_email'] : '';
    $ca = isset($found['ca']) ? $found['ca'] : 'letsencrypt';
    if ($domain === '' || $email === '') {
        gojs_json_response(null, array('code' => 'ssl.acme.missingInfo', 'message' => 'Domain or email missing on record'), 400);
        return;
    }
    gojs_log_operation('ssl_acme_renew', $domain, true, 'id=' . $id);
    $result = gojs_acme_issue_cert(array(
        'domain' => $domain,
        'email' => $email,
        'accept_tos' => true,
        'ca' => $ca,
    ));
    if (!empty($result['ok'])) {
        if (isset($result['certificate_id']) && $result['certificate_id'] !== $id) {
            $reloaded = gojs_acme_load_certs();
            $reloaded = array_values(array_filter($reloaded, function($x) use ($id) {
                return !is_array($x) || !isset($x['id']) || $x['id'] !== $id;
            }));
            gojs_acme_save_certs($reloaded);
        }
        gojs_json_response(array('ok' => true, 'renewed' => true, 'certificate_id' => isset($result['certificate_id']) ? $result['certificate_id'] : $id));
    } else {
        $code = isset($result['error']) ? $result['error'] : 'ssl.acme.renewFailed';
        $msg = isset($result['error_message']) ? $result['error_message'] : 'Renew failed';
        gojs_json_response(null, array('code' => $code, 'message' => $msg, 'message_key' => $code), 400);
    }
}

function gojs_api_ssl_acme_cert_delete(string $id): void {
    $records = gojs_acme_load_certs();
    $domain = '';
    $kept = array();
    foreach ($records as $r) {
        if (is_array($r) && isset($r['id']) && $r['id'] === $id) {
            $domain = isset($r['domain']) ? $r['domain'] : '';
            continue;
        }
        $kept[] = $r;
    }
    if (count($kept) === count($records)) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'Certificate not found', 'message_key' => 'ssl.acme.certNotFound'), 404);
        return;
    }
    gojs_acme_save_certs($kept);
    gojs_log_operation('ssl_acme_delete', $domain, true, 'id=' . $id);
    gojs_json_response(array('ok' => true));
}

function gojs_api_ssl_acme_cert_auto_renew(string $id): void {
    $body = gojs_get_body();
    $days = isset($body['auto_renew_days_before']) ? (int)$body['auto_renew_days_before'] : 0;
    if ($days < 0) $days = 0;
    if ($days > 90) $days = 90;
    $records = gojs_acme_load_certs();
    $found = false;
    foreach ($records as $i => $r) {
        if (is_array($r) && isset($r['id']) && $r['id'] === $id) {
            $records[$i]['auto_renew_days_before'] = $days;
            $found = true;
            break;
        }
    }
    if (!$found) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'Certificate not found', 'message_key' => 'ssl.acme.certNotFound'), 404);
        return;
    }
    gojs_acme_save_certs($records);
    gojs_json_response(array('ok' => true, 'auto_renew_days_before' => $days));
}

function gojs_api_ssl_acme_cert_download_pem(string $id): void {
    $records = gojs_acme_load_certs();
    $found = null;
    foreach ($records as $r) {
        if (is_array($r) && isset($r['id']) && $r['id'] === $id) {
            $found = $r;
            break;
        }
    }
    if ($found === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'Certificate not found', 'message_key' => 'ssl.acme.certNotFound'), 404);
        return;
    }
    $fullchain = isset($found['fullchain_pem_enc']) ? gojs_decrypt($found['fullchain_pem_enc']) : '';
    $privkey = isset($found['privkey_pem_enc']) ? gojs_decrypt($found['privkey_pem_enc']) : '';
    $cert = isset($found['cert_pem_enc']) ? gojs_decrypt($found['cert_pem_enc']) : '';
    $domain = isset($found['domain']) ? $found['domain'] : 'certificate';
    $bundle = '';
    if (is_string($cert) && $cert !== '') $bundle .= $cert . "\n";
    if (is_string($fullchain) && $fullchain !== '' && $fullchain !== $cert) $bundle .= $fullchain . "\n";
    if (is_string($privkey) && $privkey !== '') $bundle .= $privkey . "\n";
    if ($bundle === '') {
        gojs_json_response(null, array('code' => 'ssl.acme.certEmpty', 'message' => 'No certificate data', 'message_key' => 'ssl.acme.certEmpty'), 404);
        return;
    }
    $filename = preg_replace('/[^a-zA-Z0-9\-\.]/', '_', $domain) . '.pem';
    if (!headers_sent()) {
        header('Content-Type: application/x-pem-file');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bundle));
        header('X-Content-Type-Options: nosniff');
    }
    gojs_monitor_bump_bandwidth(0, strlen($bundle));
    echo $bundle;
    exit;
}

function gojs_api_notification_channels($method) {
    if ($method === 'GET') {
        $channels = gojs_load_channels();
        $redacted = array_map('gojs_channel_redact', $channels);
        gojs_json_response(array_values($redacted));
    } elseif ($method === 'POST') {
        $body = gojs_get_body();
        $channels = gojs_load_channels();
        $type = isset($body['type']) ? $body['type'] : '';
        $name = isset($body['name']) ? trim((string)$body['name']) : '';
        if (!in_array($type, array('email', 'smtp', 'webhook'), true)) {
            gojs_json_response(null, array('code' => 'invalid_type', 'message' => '无效的 channel 类型'), 400);
        }
        if ($name === '') {
            gojs_json_response(null, array('code' => 'invalid_name', 'message' => '名称不能为空'), 400);
        }
        $id = uniqid('ch_', true);
        $channel = array(
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'enabled' => isset($body['enabled']) ? (bool)$body['enabled'] : true,
            'created_at' => time(),
        );
        if ($type === 'email') {
            if (isset($body['from_addr'])) $channel['from_addr'] = (string)$body['from_addr'];
            if (isset($body['to_addr'])) $channel['to_addr'] = (string)$body['to_addr'];
        } elseif ($type === 'smtp') {
            $channel['host'] = isset($body['host']) ? (string)$body['host'] : '';
            $channel['port'] = isset($body['port']) ? (int)$body['port'] : 25;
            $channel['from_addr'] = isset($body['from_addr']) ? (string)$body['from_addr'] : '';
            if (isset($body['to_addr'])) $channel['to_addr'] = (string)$body['to_addr'];
            if (isset($body['username'])) $channel['username'] = (string)$body['username'];
            if (isset($body['use_tls'])) $channel['use_tls'] = (bool)$body['use_tls'];
            if (isset($body['password']) && $body['password'] !== '' && $body['password'] !== '****') {
                $channel['password_enc'] = gojs_seal_secret($body['password']);
            }
        } elseif ($type === 'webhook') {
            $channel['url'] = isset($body['url']) ? (string)$body['url'] : '';
            if (isset($body['method'])) $channel['method'] = in_array(strtoupper((string)$body['method']), array('POST', 'PUT'), true) ? strtoupper((string)$body['method']) : 'POST';
            if (isset($body['headers']) && is_array($body['headers']) && count($body['headers']) > 0) {
                $channel['headers_enc'] = gojs_seal_secret(json_encode($body['headers'], JSON_UNESCAPED_UNICODE));
            }
        }
        $channels[] = $channel;
        gojs_save_channels($channels);
        gojs_log_operation('notify_channel_create', $id . '::' . $type, true);
        gojs_json_response(gojs_channel_redact($channel));
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}

function gojs_api_notification_channel($id, $method) {
    $channels = gojs_load_channels();
    $idx = -1;
    $target = null;
    foreach ($channels as $i => $ch) {
        if (isset($ch['id']) && $ch['id'] === $id) {
            $idx = $i;
            $target = $ch;
            break;
        }
    }
    if ($target === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'Channel 不存在'), 404);
    }

    if ($method === 'PUT') {
        $body = gojs_get_body();
        if (isset($body['name'])) $target['name'] = trim((string)$body['name']);
        if (isset($body['enabled'])) $target['enabled'] = (bool)$body['enabled'];
        $type = $target['type'];
        if ($type === 'email') {
            if (isset($body['from_addr'])) $target['from_addr'] = (string)$body['from_addr'];
            if (isset($body['to_addr'])) $target['to_addr'] = (string)$body['to_addr'];
        } elseif ($type === 'smtp') {
            if (isset($body['host'])) $target['host'] = (string)$body['host'];
            if (isset($body['port'])) $target['port'] = (int)$body['port'];
            if (isset($body['from_addr'])) $target['from_addr'] = (string)$body['from_addr'];
            if (isset($body['to_addr'])) $target['to_addr'] = (string)$body['to_addr'];
            if (isset($body['username'])) $target['username'] = (string)$body['username'];
            if (isset($body['use_tls'])) $target['use_tls'] = (bool)$body['use_tls'];
            if (isset($body['password']) && $body['password'] !== '' && $body['password'] !== '****') {
                $target['password_enc'] = gojs_seal_secret($body['password']);
            }
        } elseif ($type === 'webhook') {
            if (isset($body['url'])) $target['url'] = (string)$body['url'];
            if (isset($body['method'])) $target['method'] = in_array(strtoupper((string)$body['method']), array('POST', 'PUT'), true) ? strtoupper((string)$body['method']) : 'POST';
            if (isset($body['headers']) && is_array($body['headers'])) {
                if (count($body['headers']) > 0) {
                    $target['headers_enc'] = gojs_seal_secret(json_encode($body['headers'], JSON_UNESCAPED_UNICODE));
                } elseif (isset($target['headers_enc'])) {
                    unset($target['headers_enc']);
                }
            }
        }
        $channels[$idx] = $target;
        gojs_save_channels($channels);
        gojs_log_operation('notify_channel_update', $id, true);
        gojs_json_response(gojs_channel_redact($target));
    } elseif ($method === 'DELETE') {
        $channels = array_values(array_filter($channels, function ($c) use ($id) {
            return !(isset($c['id']) && $c['id'] === $id);
        }));
        gojs_save_channels($channels);
        gojs_log_operation('notify_channel_delete', $id, true);
        gojs_json_response(array('success' => true));
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}

function gojs_api_notification_channel_test($id) {
    $channels = gojs_load_channels();
    $target = null;
    foreach ($channels as $ch) {
        if (isset($ch['id']) && $ch['id'] === $id) {
            $target = $ch;
            break;
        }
    }
    if ($target === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'Channel 不存在'), 404);
    }
    $type = isset($target['type']) ? $target['type'] : '';
    $payload = array(
        'subject' => '[Go.js] 通道测试',
        'body' => "这是 Go.js Panel 于 " . date('Y-m-d H:i:s') . " 发送的通道测试消息。如果你收到此消息，说明该通知通道配置正确。",
        'test' => true,
        'sent_at' => time(),
    );
    $result = null;
    if ($type === 'email') {
        $result = gojs_channel_mail_send($target, $payload);
    } elseif ($type === 'smtp') {
        $result = gojs_channel_smtp_send($target, $payload);
    } elseif ($type === 'webhook') {
        $webhook_payload = array(
            'event' => 'channel.test',
            'channel_id' => $id,
            'channel_name' => isset($target['name']) ? $target['name'] : '',
            'sent_at' => time(),
            'data' => $payload,
        );
        $result = gojs_channel_webhook_send($target, $webhook_payload);
    } else {
        $result = array('ok' => false, 'error' => 'unknown channel type');
    }
    gojs_json_response($result);
}

function gojs_api_notifications($method) {
    if ($method !== 'GET') {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
    $category = gojs_get_param('category', '');
    $read_filter = gojs_get_param('read', '');
    $unread_only = gojs_get_param('unread_only', '');
    $limit = (int)gojs_get_param('limit', 50);
    $offset = (int)gojs_get_param('offset', 0);
    if ($limit < 1) $limit = 50;
    if ($limit > 500) $limit = 500;
    if ($offset < 0) $offset = 0;

    $items = gojs_load_notifications();
    $items = array_reverse($items);

    $filtered = array();
    $unread_count = 0;
    foreach ($items as $it) {
        $has_read = !empty($it['read_at']);
        if (!$has_read) $unread_count++;
        if ($category !== '' && $category !== 'all') {
            $cat = isset($it['category']) ? $it['category'] : '';
            if ($cat !== $category) continue;
        }
        if (($unread_only === '1' || $unread_only === 'true') && $has_read) continue;
        if ($read_filter === 'read' && !$has_read) continue;
        if ($read_filter === 'unread' && $has_read) continue;
        $filtered[] = $it;
    }
    $total = count($filtered);
    $page = array_slice($filtered, $offset, $limit);
    gojs_json_response(array(
        'items' => $page,
        'total' => $total,
        'unread_count' => $unread_count,
    ));
}

function gojs_api_notifications_summary() {
    $items = gojs_load_notifications();
    $total = count($items);
    $unread = 0;
    $unread_items = array();
    foreach (array_reverse($items) as $it) {
        if (empty($it['read_at'])) {
            $unread++;
            if (count($unread_items) < 5) {
                $unread_items[] = $it;
            }
        }
    }
    gojs_json_response(array(
        'total' => $total,
        'unread' => $unread,
        'latest_5' => $unread_items,
    ));
}

function gojs_api_notification_mark_read($id) {
    $items = gojs_load_notifications();
    $found = false;
    foreach ($items as $idx => $it) {
        if (isset($it['id']) && $it['id'] === $id) {
            $items[$idx]['read_at'] = time();
            $found = true;
            break;
        }
    }
    if (!$found) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '通知不存在'), 404);
    }
    gojs_save_notifications($items);
    gojs_json_response(array('success' => true));
}

function gojs_api_notifications_read_all() {
    $items = gojs_load_notifications();
    $now = time();
    foreach ($items as $idx => $it) {
        if (empty($it['read_at'])) {
            $items[$idx]['read_at'] = $now;
        }
    }
    gojs_save_notifications($items);
    gojs_json_response(array('success' => true));
}

function gojs_api_notification_delete($id) {
    $items = gojs_load_notifications();
    $before = count($items);
    $items = array_values(array_filter($items, function ($it) use ($id) {
        return !(isset($it['id']) && $it['id'] === $id);
    }));
    if (count($items) === $before) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '通知不存在'), 404);
    }
    gojs_save_notifications($items);
    gojs_json_response(array('success' => true));
}

function gojs_api_notifications_clear_read() {
    $items = gojs_load_notifications();
    $items = array_values(array_filter($items, function ($it) {
        return empty($it['read_at']);
    }));
    gojs_save_notifications($items);
    gojs_json_response(array('success' => true));
}

function gojs_api_internal_drain_outbox() {
    global $config;
    $provided_token = gojs_get_param('internal_cron_token', '');
    if ($provided_token === '') {
        $headers = function_exists('getallheaders') ? getallheaders() : array();
        if (!$headers) $headers = array();
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'x-internal-cron-token') {
                $provided_token = $v;
                break;
            }
        }
    }
    $valid_token = isset($config['internal_cron_token']) ? $config['internal_cron_token'] : '';
    $admin_allowed = !empty($_SESSION['authenticated']);
    if (!$admin_allowed && ($provided_token === '' || $valid_token === '' || !hash_equals($valid_token, $provided_token))) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '需要 internal_cron_token 或管理员登录',
        ), 403);
    }
    $stats = gojs_channels_deliver_all();
    gojs_json_response($stats);
}

function gojs_secscan_cache_path(): string {
    return CONFIG_DIR . '/secscan_cache.json';
}

function gojs_secscan_severity_to_badge(string $s): string {
    $s = strtolower($s);
    if ($s === 'critical' || $s === 'danger') return 'danger';
    if ($s === 'high') return 'danger';
    if ($s === 'moderate') return 'warning';
    if ($s === 'low') return 'muted';
    if ($s === 'info') return 'accent';
    return 'muted';
}

/**
 * 归一化版本字符串，供 version_compare 使用：
 *  - 去首字母 v / V
 *  - 去 +build 后缀（如 1.0.0+20230101 -> 1.0.0）
 *  - -dev / -alpha / -beta / -RC 等后缀 PHP version_compare 原生支持，仅清洗前缀与 build。
 */
function gojs_secscan_normalize_version(string $v): string {
    $v = trim($v);
    $v = preg_replace('/^[vV]/', '', $v);
    $v = preg_replace('/\+.*$/', '', $v);
    return trim($v);
}

function gojs_secscan_version_compare(string $a, string $op, string $b): bool {
    return version_compare(gojs_secscan_normalize_version($a), gojs_secscan_normalize_version($b), $op);
}

/**
 * 解析单个版本约束子句（可能为空格分隔的多个比较条件，AND 组合）。
 * 支持：
 *  - *：任意版本
 *  - X.* / X.Y.*：转成区间（2.* 等价 >=2.0.0 <3.0.0；1.9.* 等价 >=1.9.0 <2.0.0）
 *  - A - B：闭区间 [A, B]
 *  - 单比较符：< / <= / == / = / >= / > / != / <>
 *  - 空格分隔的复合比较（AND）：如 >=1.0.0 <1.0.21
 *  - 裸版本：精确相等
 */
function gojs_secscan_parse_single_range(string $pkg_version, string $range): bool {
    $range = trim($range);
    if ($range === '') return true;
    // 通配符：* 匹配任意版本
    if ($range === '*') return true;
    // 通配前缀转区间：X.* -> >=X.0.0 <X+1.0.0；X.Y.* -> >=X.Y.0 <X.(Y+1).0（Y=9 时进位到 <X+1.0.0）
    if (preg_match('/^(\d+)(?:\.(\d+))?\.\*$/', $range, $wm)) {
        $major = (int)$wm[1];
        $minor = isset($wm[2]) && $wm[2] !== '' ? (int)$wm[2] : 0;
        $has_minor = isset($wm[2]) && $wm[2] !== '';
        $hi_major = $major;
        $hi_minor = $has_minor ? $minor + 1 : 0;
        if ($hi_minor >= 10) {
            $hi_major += 1;
            $hi_minor = 0;
        }
        if ($has_minor) {
            return gojs_secscan_version_compare($pkg_version, '>=', $major . '.' . $minor . '.0')
                && gojs_secscan_version_compare($pkg_version, '<', $hi_major . '.' . $hi_minor . '.0');
        }
        return gojs_secscan_version_compare($pkg_version, '>=', $major . '.0.0')
            && gojs_secscan_version_compare($pkg_version, '<', ($major + 1) . '.0.0');
    }
    // 闭区间：A - B
    if (preg_match('/^(.+?)\s*-\s*(.+)$/', $range, $m)) {
        $lo = trim($m[1]);
        $hi = trim($m[2]);
        return gojs_secscan_version_compare($pkg_version, '>=', $lo)
            && gojs_secscan_version_compare($pkg_version, '<=', $hi);
    }
    // 空格分隔的复合比较（AND）：如 ">=1.0.0 <1.0.21"（必须在单比较符之前处理，避免 (.+) 吞掉空格）
    $tokens = preg_split('/\s+/', $range);
    if (count($tokens) > 1) {
        $match = true;
        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '' || $tok === '*') continue;
            if (!gojs_secscan_parse_single_range($pkg_version, $tok)) {
                $match = false;
                break;
            }
        }
        return $match;
    }
    // 单比较符
    if (preg_match('/^(<|<=|==|=|>=|>|!=|<>)\s*(\S+)$/', $range, $m)) {
        $op = $m[1];
        if ($op === '=') $op = '==';
        $ver = trim($m[2]);
        return gojs_secscan_version_compare($pkg_version, $op, $ver);
    }
    // 裸版本：精确相等
    return gojs_secscan_version_compare($pkg_version, '==', $range);
}

/**
 * 展开 composer 语义约束为多个逗号 AND 条件。
 *  - ^X.Y[.Z] -> >=X.Y[.Z],<Y+1.0.0（0.x 时只升次版本）
 *  - ~X.Y     -> >=X.Y,<X+1.0.0
 *  - ~X.Y.Z   -> >=X.Y.Z,<X.Y+1.0
 * 解析失败时原样返回，交由 AND 逻辑兜底（不匹配即 false，绝不抛异常）。
 */
function gojs_secscan_expand_composer(string $cond): string {
    $cond = trim($cond);
    if (preg_match('/^\^(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $cond, $m)) {
        $major = (int)$m[1];
        $minor = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
        $patch = isset($m[3]) && $m[3] !== '' ? (int)$m[3] : 0;
        $lo = $m[1];
        if (isset($m[2]) && $m[2] !== '') $lo .= '.' . $m[2];
        if (isset($m[3]) && $m[3] !== '') $lo .= '.' . $m[3];
        if ($major > 0) {
            return '>=' . $lo . ',<' . ($major + 1) . '.0.0';
        }
        return '>=' . $lo . ',<0.' . ($minor + 1) . '.0';
    }
    if (preg_match('/^~(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $cond, $m)) {
        $major = (int)$m[1];
        $minor = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
        $lo = $m[1];
        if (isset($m[2]) && $m[2] !== '') $lo .= '.' . $m[2];
        if (isset($m[3]) && $m[3] !== '') $lo .= '.' . $m[3];
        if (isset($m[3]) && $m[3] !== '') {
            return '>=' . $lo . ',<' . $major . '.' . ($minor + 1) . '.0';
        }
        return '>=' . $lo . ',<' . ($major + 1) . '.0.0';
    }
    return $cond;
}

function gojs_secscan_parse_range(string $pkg_version, string $range): bool {
    $ors = explode('||', $range);
    foreach ($ors as $or_part) {
        $or_part = trim($or_part);
        // 分支为空或 * 视为匹配（任意版本命中）
        if ($or_part === '' || $or_part === '*') return true;
        // 展开 ^ / ~ 语义，再按逗号拆 AND：每个条件都满足才 true，任一条件为空则跳过
        $expanded = trim(gojs_secscan_expand_composer($or_part));
        $and_parts = explode(',', $expanded);
        $match = true;
        foreach ($and_parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            if ($part === '*') continue;
            if (!gojs_secscan_parse_single_range($pkg_version, $part)) {
                $match = false;
                break;
            }
        }
        if ($match) return true;
    }
    return false;
}

$GLOBALS['GOJS_PHP_CVE_SEED'] = [
    ['name'=>'phpunit/phpunit',               'vuln_range'=>'< 9.0.0',  'severity'=>'low',      'title'=>'PHPUnit older than 9: eval injection in test harness', 'url'=>'https://github.com/sebastianbergmann/phpunit/security/advisories'],
    ['name'=>'guzzlehttp/guzzle',             'vuln_range'=>'>=6.0.0 <6.5.6 || >=7.0.0 <7.4.3', 'severity'=>'high', 'title'=>'Guzzle cookie-domain confusion CVE-2022-29248',          'url'=>'https://github.com/guzzle/guzzle/security/advisories/GHSA-cwmx-hcrq-mhc3'],
    ['name'=>'phpseclib/phpseclib',           'vuln_range'=>'>=1.0.0 <1.0.21 || >=2.0.0 <2.0.37 || >=3.0.0 <3.0.13', 'severity'=>'high', 'title'=>'phpseclib RSA signature forgery CVE-2021-30132',        'url'=>'https://github.com/phpseclib/phpseclib/issues/1629'],
    ['name'=>'symfony/http-kernel',           'vuln_range'=>'>=5.0.0 <5.4.22 || >=6.0.0 <6.0.20 || >=6.1.0 <6.1.12 || >=6.2.0 <6.2.6', 'severity'=>'critical', 'title'=>'Symfony FragmentListener bypass CVE-2022-24894',   'url'=>'https://symfony.com/cve-2022-24894'],
    ['name'=>'symfony/security-core',         'vuln_range'=>'>=4.0.0 <4.4.50 || >=5.0.0 <5.4.20 || >=6.0.0 <6.2.6', 'severity'=>'high',     'title'=>'Symfony security-core Auth auth bypass',             'url'=>'https://github.com/symfony/symfony/security/advisories'],
    ['name'=>'laravel/framework',             'vuln_range'=>'>=8.0.0 <8.75.0 || >=9.0.0 <9.33.0', 'severity'=>'critical',      'title'=>'Laravel framework cookie-based RCE',                'url'=>'https://laravel.com/docs/security'],
    ['name'=>'league/flysystem',              'vuln_range'=>'>=1.0.0 <1.1.4 || >=2.0.0 <2.1.1',    'severity'=>'moderate', 'title'=>'Flysystem path traversal CVE-2021-32708',           'url'=>'https://github.com/thephpleague/flysystem/security/advisories/GHSA-7hh3-wv9w-xgvr'],
    ['name'=>'twig/twig',                     'vuln_range'=>'>=2.0.0 <2.15.3 || >=3.0.0 <3.4.3',   'severity'=>'moderate', 'title'=>'Twig Sandbox mode bypass',                           'url'=>'https://github.com/twigphp/Twig/tags'],
    ['name'=>'smarty/smarty',                 'vuln_range'=>'< 4.3.0',                'severity'=>'high',     'title'=>'Smarty template injection',                          'url'=>'https://github.com/smarty-php/smarty/security/advisories'],
    ['name'=>'monolog/monolog',               'vuln_range'=>'>=2.0.0 <2.9.0 || >=3.0.0 <3.5.0',    'severity'=>'moderate', 'title'=>'Monolog SwiftMailerHandler CRLF header injection',   'url'=>'https://github.com/Seldaek/monolog/tags'],
    ['name'=>'doctrine/dbal',                 'vuln_range'=>'>=3.0.0 <3.6.4 || >=2.0.0 <2.13.9',   'severity'=>'high',     'title'=>'Doctrine DBAL SQL injection via LIMIT parameters',   'url'=>'https://github.com/doctrine/dbal/security/advisories'],
    ['name'=>'doctrine/orm',                  'vuln_range'=>'>=2.0.0 <2.14.3 || >=2.0.0 <2.13.4',  'severity'=>'high',     'title'=>'Doctrine ORM order-by SQL injection',                'url'=>'https://github.com/doctrine/orm/security/advisories'],
    ['name'=>'wordpress/core',                'vuln_range'=>'< 6.2',                  'severity'=>'critical', 'title'=>'WordPress core <6.2 multiple XSS and auth issues',   'url'=>'https://wordpress.org/support/wordpress-version/version-6-2/'],
    ['name'=>'drupal/core',                   'vuln_range'=>'>=9.0.0 <9.5.8 || >=10.0.0 <10.0.8',   'severity'=>'critical', 'title'=>'Drupal core SA-CORE multiple vulns',                'url'=>'https://www.drupal.org/security'],
    ['name'=>'joomla/joomla-cms',             'vuln_range'=>'< 4.2.8',                'severity'=>'critical', 'title'=>'Joomla! CMS CVE-2023-23752',                        'url'=>'https://developer.joomla.org/security-centre.html'],
    ['name'=>'magento/product-community-edition', 'vuln_range'=>'< 2.4.6-p1',       'severity'=>'critical', 'title'=>'Magento 2.4.6 pre-p1 RCE',                           'url'=>'https://helpx.adobe.com/security/products/magento.html'],
    ['name'=>'phpmailer/phpmailer',           'vuln_range'=>'< 6.5.0',                'severity'=>'high',     'title'=>'PHPMailer CVE-2020-36326/36327 object injection',    'url'=>'https://github.com/PHPMailer/PHPMailer/tags'],
    ['name'=>'erusev/parsedown',              'vuln_range'=>'< 1.7.4',                'severity'=>'moderate', 'title'=>'Parsedown XSS CVE-2018-1000163',                     'url'=>'https://github.com/erusev/parsedown/issues'],
    ['name'=>'michelf/php-markdown',          'vuln_range'=>'< 1.9.0',                'severity'=>'moderate', 'title'=>'PHP Markdown Lib XSS',                               'url'=>'https://github.com/michelf/php-markdown'],
    ['name'=>'cakephp/cakephp',               'vuln_range'=>'< 4.4.11 || < 3.10.12', 'severity'=>'critical', 'title'=>'CakePHP cache-engine RCE',                            'url'=>'https://bakery.cakephp.org/'],
    // 新增（真实公告，版本范围为官方公告确认的受影响区间，避免多系列误报）
    ['name'=>'phpseclib/phpseclib',           'vuln_range'=>'<1.0.23 || >=2.0.0 <2.0.46 || >=3.0.0 <3.0.34', 'severity'=>'high', 'title'=>'phpseclib BinaryField DoS CVE-2023-49316',        'url'=>'https://github.com/phpseclib/phpseclib/releases/tag/3.0.34'],
    ['name'=>'guzzlehttp/psr7',               'vuln_range'=>'<1.9.1 || >=2.0.0 <2.4.5', 'severity'=>'high', 'title'=>'guzzlehttp/psr7 HTTP multiline header injection CVE-2023-29197', 'url'=>'https://github.com/guzzle/psr7/security/advisories/GHSA-wxmh-65f7-jcvw'],
    ['name'=>'symfony/runtime',               'vuln_range'=>'>=5.3.0 <5.4.46 || >=6.0.0 <6.4.14 || >=7.0.0 <7.1.7', 'severity'=>'moderate', 'title'=>'Symfony runtime env/debug switch via crafted query CVE-2024-50340', 'url'=>'https://symfony.com/cve-2024-50340'],
    ['name'=>'laminas/laminas-diactoros',     'vuln_range'=>'<2.18.1 || >=2.19.0 <2.19.1 || >=2.20.0 <2.20.1 || >=2.21.0 <2.21.1 || >=2.22.0 <2.22.1 || >=2.23.0 <2.23.1 || >=2.24.0 <2.24.2 || >=2.25.0 <2.25.2', 'severity'=>'high', 'title'=>'laminas-diactoros multiline header termination CVE-2023-29530', 'url'=>'https://github.com/laminas/laminas-diactoros/security/advisories/GHSA-xv3h-4844-9h36'],
];

function gojs_secscan_load_cache(): array {
    $path = gojs_secscan_cache_path();
    if (!file_exists($path)) return array();
    $data = gojs_read_json_lock_safe($path, array());
    return is_array($data) ? $data : array();
}

function gojs_secscan_save_cache(array $data): void {
    gojs_write_json_lock_safe(gojs_secscan_cache_path(), $data, true);
}

function gojs_secscan_frontend(bool $force=false): array {
    $cache = gojs_secscan_load_cache();
    $now = time();

    if (!$force && isset($cache['frontend_cache']) && is_array($cache['frontend_cache'])) {
        $fc = $cache['frontend_cache'];
        if (isset($fc['scanned_at']) && ($now - (int)$fc['scanned_at']) < 3600) {
            return $fc;
        }
    }

    $exec_avail = function_exists('exec') && function_exists('shell_exec');
    if (!$exec_avail) {
        $result = array(
            'available' => false,
            'reason_key' => 'secscan.npmUnavailable',
        );
        $cache['frontend_cache'] = array_merge($result, array('scanned_at' => $now));
        gojs_secscan_save_cache($cache);
        return $cache['frontend_cache'];
    }

    $cwd = ROOT;
    $cmd = 'npm audit --omit=dev --json 2>&1';
    $descriptorspec = array(
       0 => array('pipe', 'r'),
       1 => array('pipe', 'w'),
       2 => array('pipe', 'w')
    );
    $process = @proc_open($cmd, $descriptorspec, $pipes, $cwd);
    $output = '';
    $proc_success = false;
    if (is_resource($process)) {
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $ret = proc_close($process);
        if ($output !== '' && $output !== false) {
            $proc_success = true;
        }
    }
    if (!$proc_success) {
        $output = '';
        if (function_exists('shell_exec')) {
            $old_cwd = getcwd();
            if ($old_cwd) @chdir($cwd);
            $raw = @shell_exec($cmd);
            if ($old_cwd) @chdir($old_cwd);
            if ($raw !== null && $raw !== false) $output = $raw;
        }
    }

    if ($output === '' || $output === null) {
        $result = array(
            'available' => false,
            'reason_key' => 'secscan.npmUnavailable',
        );
        $cache['frontend_cache'] = array_merge($result, array('scanned_at' => $now));
        gojs_secscan_save_cache($cache);
        return $cache['frontend_cache'];
    }

    $parsed = json_decode($output, true);
    if (!is_array($parsed)) {
        $result = array(
            'available' => false,
            'reason_key' => 'secscan.npmUnavailable',
        );
        $cache['frontend_cache'] = array_merge($result, array('scanned_at' => $now));
        gojs_secscan_save_cache($cache);
        return $cache['frontend_cache'];
    }

    $vulns = array();
    $seen_keys = array();

    $severity_order = array('info' => 0, 'low' => 1, 'moderate' => 2, 'high' => 3, 'critical' => 4);

    $candidates = array();
    if (isset($parsed['vulnerabilities']) && is_array($parsed['vulnerabilities'])) {
        foreach ($parsed['vulnerabilities'] as $pkg => $v) {
            if (!is_array($v)) continue;
            $installed = isset($v['name']) ? (string)$v['name'] : (string)$pkg;
            $iv = isset($v['range']) ? (string)$v['range'] : '';
            if (preg_match('/^[<>=!]*\s*([\d][\w\.\-+]*)$/', $iv, $mv)) {
                $iv = $mv[1];
            }
            $title = isset($v['title']) ? (string)$v['title'] : '';
            $sev = isset($v['severity']) ? strtolower((string)$v['severity']) : 'info';
            if (!in_array($sev, array('info','low','moderate','high','critical'), true)) $sev = 'info';
            $url = isset($v['url']) ? (string)$v['url'] : (isset($v['advisory']) ? (string)$v['advisory'] : '');
            $fix_info = isset($v['fixAvailable']) ? $v['fixAvailable'] : null;
            $fixed = null;
            if (is_array($fix_info) && isset($fix_info['name']) && isset($fix_info['version'])) {
                $fixed = (string)$fix_info['version'];
            } elseif (isset($v['fixedVersion']) && $v['fixedVersion'] !== '' && $v['fixedVersion'] !== '*') {
                $fixed = (string)$v['fixedVersion'];
            }
            $vias = isset($v['via']) && is_array($v['via']) ? $v['via'] : array();
            if (count($vias) > 0) {
                foreach ($vias as $via) {
                    if (is_array($via)) {
                        $v2 = $via;
                        $v2_pkg = isset($v2['name']) ? (string)$v2['name'] : (string)$pkg;
                        $v2_title = isset($v2['title']) ? (string)$v2['title'] : $title;
                        $v2_sev = isset($v2['severity']) ? strtolower((string)$v2['severity']) : $sev;
                        if (!in_array($v2_sev, array('info','low','moderate','high','critical'), true)) $v2_sev = 'info';
                        $v2_url = isset($v2['url']) ? (string)$v2['url'] : $url;
                        $v2_fixed = $fixed;
                        if (isset($v2['fixAvailable']) && is_array($v2['fixAvailable']) && isset($v2['fixAvailable']['version'])) {
                            $v2_fixed = (string)$v2['fixAvailable']['version'];
                        }
                        $candidates[] = array(
                            'package' => $v2_pkg,
                            'installed_version' => $iv,
                            'fixed_version' => $v2_fixed,
                            'severity' => $v2_sev,
                            'title' => $v2_title,
                            'url' => $v2_url,
                        );
                    }
                }
            } else {
                $candidates[] = array(
                    'package' => (string)$pkg,
                    'installed_version' => $iv,
                    'fixed_version' => $fixed,
                    'severity' => $sev,
                    'title' => $title,
                    'url' => $url,
                );
            }
        }
    }

    if (isset($parsed['advisories']) && is_array($parsed['advisories'])) {
        foreach ($parsed['advisories'] as $adv) {
            if (!is_array($adv)) continue;
            $pkg = isset($adv['module_name']) ? (string)$adv['module_name'] : (isset($adv['name']) ? (string)$adv['name'] : '');
            $iv = isset($adv['installed_version']) ? (string)$adv['installed_version'] : (isset($adv['version']) ? (string)$adv['version'] : '');
            $title = isset($adv['title']) ? (string)$adv['title'] : (isset($adv['overview']) ? (string)$adv['overview'] : '');
            $sev = isset($adv['severity']) ? strtolower((string)$adv['severity']) : 'info';
            if (!in_array($sev, array('info','low','moderate','high','critical'), true)) $sev = 'info';
            $url = isset($adv['url']) ? (string)$adv['url'] : (isset($adv['references']) ? (string)$adv['references'] : '');
            $fixed = isset($adv['patched_versions']) ? (string)$adv['patched_versions'] : (isset($adv['fixed_version']) ? (string)$adv['fixed_version'] : null);
            if ($pkg !== '') {
                $candidates[] = array(
                    'package' => $pkg,
                    'installed_version' => $iv,
                    'fixed_version' => $fixed,
                    'severity' => $sev,
                    'title' => $title,
                    'url' => $url,
                );
            }
        }
    }

    foreach ($candidates as $c) {
        $key = $c['package'] . '||' . $c['installed_version'];
        $sev_rank = isset($severity_order[$c['severity']]) ? $severity_order[$c['severity']] : 0;
        if (isset($seen_keys[$key])) {
            $idx = $seen_keys[$key];
            $existing_rank = isset($severity_order[$vulns[$idx]['severity']]) ? $severity_order[$vulns[$idx]['severity']] : 0;
            if ($sev_rank > $existing_rank) {
                $vulns[$idx]['severity'] = $c['severity'];
                $vulns[$idx]['title'] = $c['title'] ?: $vulns[$idx]['title'];
                $vulns[$idx]['url'] = $c['url'] ?: $vulns[$idx]['url'];
                if ($c['fixed_version']) $vulns[$idx]['fixed_version'] = $c['fixed_version'];
                $vulns[$idx]['severityBadgeVariant'] = gojs_secscan_severity_to_badge($c['severity']);
            }
            continue;
        }
        $seen_keys[$key] = count($vulns);
        $item = array(
            'package' => $c['package'],
            'installed_version' => $c['installed_version'],
            'fixed_version' => $c['fixed_version'] ?: null,
            'severity' => $c['severity'],
            'title' => $c['title'],
            'url' => $c['url'] ?: null,
            'severityBadgeVariant' => gojs_secscan_severity_to_badge($c['severity']),
        );
        $vulns[] = $item;
    }

    $result = array(
        'available' => true,
        'scanned_at' => $now,
        'vulns' => array_values($vulns),
    );
    $cache['frontend_cache'] = $result;
    gojs_secscan_save_cache($cache);
    return $result;
}

function gojs_secscan_backend(bool $force=false): array {
    $cache = gojs_secscan_load_cache();
    $now = time();

    if (!$force && isset($cache['backend_cache']) && is_array($cache['backend_cache'])) {
        $bc = $cache['backend_cache'];
        if (isset($bc['scanned_at']) && ($now - (int)$bc['scanned_at']) < 3600) {
            return $bc;
        }
    }

    $candidates = array(
        PANEL_ROOT . '/composer.lock',
        dirname(PANEL_ROOT) . '/composer.lock',
    );
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $docroot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
        if ($docroot !== '') {
            $candidates[] = $docroot . '/composer.lock';
        }
    }

    $lock_path = null;
    foreach ($candidates as $c) {
        if (file_exists($c) && is_file($c) && is_readable($c)) {
            $lock_path = $c;
            break;
        }
    }

    if ($lock_path === null) {
        $result = array(
            'available' => true,
            'scanned_at' => $now,
            'heuristicOnly' => true,
            'count' => 0,
            'notice_key' => 'secscan.noComposerLock',
            'vulns' => array(),
        );
        $cache['backend_cache'] = $result;
        gojs_secscan_save_cache($cache);
        return $result;
    }

    $raw = @file_get_contents($lock_path);
    if ($raw === false) {
        $result = array(
            'available' => true,
            'scanned_at' => $now,
            'heuristicOnly' => true,
            'count' => 0,
            'notice_key' => 'secscan.noComposerLock',
            'vulns' => array(),
        );
        $cache['backend_cache'] = $result;
        gojs_secscan_save_cache($cache);
        return $result;
    }
    $lock = json_decode($raw, true);
    if (!is_array($lock)) {
        $result = array(
            'available' => true,
            'scanned_at' => $now,
            'heuristicOnly' => true,
            'count' => 0,
            'notice_key' => 'secscan.noComposerLock',
            'vulns' => array(),
        );
        $cache['backend_cache'] = $result;
        gojs_secscan_save_cache($cache);
        return $result;
    }

    $packages = array();
    if (isset($lock['packages']) && is_array($lock['packages'])) {
        $packages = array_merge($packages, $lock['packages']);
    }
    if (isset($lock['packages-dev']) && is_array($lock['packages-dev'])) {
        $packages = array_merge($packages, $lock['packages-dev']);
    }

    $seed = isset($GLOBALS['GOJS_PHP_CVE_SEED']) && is_array($GLOBALS['GOJS_PHP_CVE_SEED'])
        ? $GLOBALS['GOJS_PHP_CVE_SEED']
        : array();

    $seed_by_name = array();
    foreach ($seed as $s) {
        $name = isset($s['name']) ? (string)$s['name'] : '';
        if ($name === '') continue;
        $seed_by_name[$name][] = $s;
    }

    $vulns = array();

    foreach ($packages as $pkg) {
        if (!is_array($pkg)) continue;
        $name = isset($pkg['name']) ? (string)$pkg['name'] : '';
        $version = isset($pkg['version']) ? (string)$pkg['version'] : '';
        if ($name === '' || $version === '') continue;
        $version = ltrim($version, 'vV');
        if (!isset($seed_by_name[$name])) continue;
        foreach ($seed_by_name[$name] as $seed_entry) {
            $range = isset($seed_entry['vuln_range']) ? (string)$seed_entry['vuln_range'] : '';
            if ($range === '') continue;
            if (!gojs_secscan_parse_range($version, $range)) continue;
            $sev = isset($seed_entry['severity']) ? strtolower((string)$seed_entry['severity']) : 'info';
            if (!in_array($sev, array('info','low','moderate','high','critical'), true)) $sev = 'info';
            $title = isset($seed_entry['title']) ? (string)$seed_entry['title'] : '';
            $url = isset($seed_entry['url']) ? (string)$seed_entry['url'] : '';
            $vulns[] = array(
                'package' => $name,
                'installed_version' => $version,
                'fixed_version' => null,
                'severity' => $sev,
                'title' => $title,
                'url' => $url ?: null,
                'severityBadgeVariant' => gojs_secscan_severity_to_badge($sev),
            );
        }
    }

    $result = array(
        'available' => true,
        'scanned_at' => $now,
        'heuristicOnly' => false,
        'count' => count($vulns),
        'vulns' => $vulns,
    );
    $cache['backend_cache'] = $result;
    gojs_secscan_save_cache($cache);
    return $result;
}

function gojs_unseal_secret($sealed) {
    if ($sealed === null || $sealed === '' || $sealed === '****') return false;
    return gojs_decrypt((string)$sealed);
}

function gojs_destinations_load(): array {
    global $config;
    return isset($config['backup_destinations']) && is_array($config['backup_destinations'])
        ? $config['backup_destinations']
        : array();
}

function gojs_destinations_save(array $destinations): void {
    global $config;
    $config['backup_destinations'] = $destinations;
    gojs_save_config();
}

function gojs_destination_redact(array $dest): array {
    $redacted = $dest;
    $secret_fields = array('secret_key_enc', 'password_enc', 'private_key_enc');
    foreach ($secret_fields as $f) {
        if (isset($redacted[$f]) && $redacted[$f] !== '') {
            $redacted[$f] = '****';
        }
    }
    return $redacted;
}

class GOJS_S3_Destination {
    private $access_key;
    private $secret_key;
    private $endpoint;
    private $region;
    private $bucket;
    private $sse;
    private $path_prefix;

    public static function available(): bool {
        $allow_fopen = ini_get('allow_url_fopen') === '1' || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
        $has_hash = function_exists('hash_hmac') || function_exists('openssl_hmac');
        return $allow_fopen && (extension_loaded('openssl') || $has_hash);
    }

    public function __construct($config) {
        $this->access_key = isset($config['access_key']) ? (string)$config['access_key'] : '';
        $this->secret_key = isset($config['secret_key']) ? (string)$config['secret_key'] : '';
        $this->endpoint = isset($config['endpoint']) && $config['endpoint'] !== '' ? rtrim((string)$config['endpoint'], '/') : 'https://s3.amazonaws.com';
        $this->region = isset($config['region']) && $config['region'] !== '' ? (string)$config['region'] : 'us-east-1';
        $this->bucket = isset($config['bucket']) ? (string)$config['bucket'] : '';
        $this->sse = !empty($config['sse']);
        $this->path_prefix = isset($config['path_prefix']) ? trim((string)$config['path_prefix'], '/') : '';
    }

    private function sign($key, $data) {
        if (function_exists('hash_hmac')) {
            return hash_hmac('sha256', $data, $key, true);
        }
        return openssl_hmac($data, $key, 'sha256');
    }

    private function sha256($data) {
        if (function_exists('hash')) {
            return hash('sha256', $data);
        }
        return bin2hex(openssl_digest($data, 'sha256'));
    }

    public function v4($service, $http_method, $path, $headers = array(), $query = array(), $payload = '') {
        $t = time();
        $datestamp = gmdate('Ymd', $t);
        $amzdate = gmdate('Ymd\\THis\\Z', $t);

        $parsed = parse_url($this->endpoint);
        $host = isset($parsed['host']) ? $parsed['host'] : 's3.amazonaws.com';
        if (isset($parsed['port'])) {
            $host .= ':' . $parsed['port'];
        }

        $canonical_headers = array('host' => $host);
        foreach ($headers as $k => $v) {
            $canonical_headers[strtolower($k)] = trim($v);
        }
        $canonical_headers['x-amz-date'] = $amzdate;

        ksort($canonical_headers);
        $signed_headers = implode(';', array_keys($canonical_headers));

        $canonical_header_str = '';
        foreach ($canonical_headers as $k => $v) {
            $canonical_header_str .= $k . ':' . $v . "\n";
        }

        ksort($query);
        $query_str = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $payload_hash = $this->sha256($payload);

        $canonical_request = $http_method . "\n"
            . $path . "\n"
            . $query_str . "\n"
            . $canonical_header_str . "\n"
            . $signed_headers . "\n"
            . $payload_hash;

        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = $datestamp . '/' . $this->region . '/' . $service . '/aws4_request';

        $string_to_sign = $algorithm . "\n"
            . $amzdate . "\n"
            . $credential_scope . "\n"
            . $this->sha256($canonical_request);

        $k_date = $this->sign('AWS4' . $this->secret_key, $datestamp);
        $k_region = $this->sign($k_date, $this->region);
        $k_service = $this->sign($k_region, $service);
        $k_signing = $this->sign($k_service, 'aws4_request');
        $signature = bin2hex($this->sign($k_signing, $string_to_sign));

        return $algorithm . ' Credential=' . $this->access_key . '/' . $credential_scope
            . ', SignedHeaders=' . $signed_headers
            . ', Signature=' . $signature;
    }

    private function request($method, $key, $body = '', $extra_headers = array(), $query = array()) {
        $service = 's3';
        $path = '/' . $this->bucket . '/' . ltrim($key, '/');
        $real_path = '/' . $this->bucket . '/' . ltrim($key, '/');

        $headers = $extra_headers;
        if ($body !== '' && !isset($headers['Content-Length'])) {
            $headers['Content-Length'] = (string)strlen($body);
        }
        if ($this->sse) {
            $headers['x-amz-server-side-encryption'] = 'AES256';
        }

        $auth = $this->v4($service, $method, $real_path, $headers, $query, $body);

        $parsed = parse_url($this->endpoint);
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'https';
        $host = isset($parsed['host']) ? $parsed['host'] : 's3.amazonaws.com';
        $port = isset($parsed['port']) ? (int)$parsed['port'] : ($scheme === 'https' ? 443 : 80);

        $url_path = $path;
        if (!empty($query)) {
            $url_path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $req_headers = array(
            'Host' => $host,
            'X-Amz-Date' => gmdate('Ymd\\THis\\Z'),
            'Authorization' => $auth,
        );
        foreach ($headers as $k => $v) {
            $req_headers[$k] = $v;
        }

        $ctx_headers = '';
        foreach ($req_headers as $k => $v) {
            $ctx_headers .= $k . ': ' . $v . "\r\n";
        }

        $opts = array(
            'http' => array(
                'method' => $method,
                'header' => $ctx_headers,
                'content' => $body,
                'timeout' => 15,
                'ignore_errors' => true,
            ),
        );

        if ($scheme === 'https' && extension_loaded('openssl')) {
            $opts['ssl'] = array(
                'verify_peer' => true,
                'verify_peer_name' => true,
            );
        }

        $context = stream_context_create($opts);
        $url = $scheme . '://' . $host . ($port !== 80 && $port !== 443 ? ':' . $port : '') . $url_path;

        $result = @file_get_contents($url, false, $context);
        $status = 0;
        $resp_headers = array();
        if (isset($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('/^HTTP\\/\\S+\\s+(\\d+)/', $h, $m)) {
                    $status = (int)$m[1];
                } elseif (strpos($h, ':') !== false) {
                    list($k, $v) = explode(':', $h, 2);
                    $resp_headers[strtolower(trim($k))] = trim($v);
                }
            }
        }

        return array('status' => $status, 'body' => $result === false ? '' : $result, 'headers' => $resp_headers);
    }

    private function full_key($key) {
        $parts = array();
        if ($this->path_prefix !== '') $parts[] = $this->path_prefix;
        $parts[] = ltrim($key, '/');
        return implode('/', array_filter($parts, 'strlen'));
    }

    public function testConnection(): array {
        if (!self::available()) {
            return array('ok' => false, 'error' => 's3_not_available', 'error_key' => 's3_requirements_missing');
        }
        if ($this->access_key === '' || $this->secret_key === '' || $this->bucket === '') {
            return array('ok' => false, 'error' => 's3_missing_credentials', 'error_key' => 's3_missing_credentials');
        }

        $head = $this->request('HEAD', '');
        if ($head['status'] !== 200 && $head['status'] !== 403 && $head['status'] !== 404) {
            return array(
                'ok' => false,
                'error' => 's3_head_bucket_failed: HTTP ' . $head['status'] . ' ' . substr($head['body'], 0, 200),
                'error_key' => 's3_connect_failed',
            );
        }

        $test_key = $this->full_key('gojs-test-' . uniqid('', true) . '.txt');
        $test_content = 'gojs-connection-test-' . time();

        $put = $this->request('PUT', $test_key, $test_content, array('Content-Type' => 'text/plain'));
        if ($put['status'] !== 200) {
            return array(
                'ok' => false,
                'error' => 's3_put_failed: HTTP ' . $put['status'] . ' ' . substr($put['body'], 0, 200),
                'error_key' => 's3_bucket_not_writable',
            );
        }

        $this->request('DELETE', $test_key);

        return array(
            'ok' => true,
            'bucket_writable' => true,
            'prefix' => $this->path_prefix,
        );
    }

    public function putObject(string $key, $body): array {
        $full = $this->full_key($key);
        if (is_resource($body)) {
            $content = stream_get_contents($body);
        } else {
            $content = (string)$body;
        }
        $resp = $this->request('PUT', $full, $content, array('Content-Type' => 'application/octet-stream'));
        if ($resp['status'] === 200) {
            return array('ok' => true);
        }
        return array('ok' => false, 'error' => 'HTTP ' . $resp['status'] . ' ' . substr($resp['body'], 0, 200));
    }

    public function listObjects(string $prefix, int $max = 1000): array {
        $full_prefix = $this->full_key($prefix);
        $query = array(
            'list-type' => '2',
            'prefix' => $full_prefix,
            'max-keys' => (string)min($max, 1000),
        );
        $resp = $this->request('GET', '', '', array(), $query);
        $objects = array();
        if ($resp['status'] === 200 && $resp['body'] !== '') {
            $xml = @simplexml_load_string($resp['body']);
            if ($xml && isset($xml->Contents)) {
                foreach ($xml->Contents as $c) {
                    $objects[] = array(
                        'key' => (string)$c->Key,
                        'size' => (int)$c->Size,
                        'last_modified' => (string)$c->LastModified,
                    );
                }
            }
        }
        return $objects;
    }

    public function deleteObject(string $key): array {
        $full = $this->full_key($key);
        $resp = $this->request('DELETE', $full);
        if ($resp['status'] === 204 || $resp['status'] === 200) {
            return array('ok' => true);
        }
        return array('ok' => false, 'error' => 'HTTP ' . $resp['status']);
    }
}

class GOJS_FTP_Destination {
    private $host;
    private $port;
    private $username;
    private $password;
    private $path_prefix;
    private $use_tls;

    public static function available(): bool {
        return extension_loaded('ftp') || function_exists('fsockopen');
    }

    public function __construct($config) {
        $this->host = isset($config['host']) ? (string)$config['host'] : '';
        $this->port = isset($config['port']) ? (int)$config['port'] : 21;
        $this->username = isset($config['username']) ? (string)$config['username'] : '';
        $this->password = isset($config['password']) ? (string)$config['password'] : '';
        $this->path_prefix = isset($config['path_prefix']) ? trim((string)$config['path_prefix'], '/') : '';
        $this->use_tls = !empty($config['use_tls']);
    }

    public function testConnection(): array {
        if (!self::available()) {
            return array('ok' => false, 'error' => 'ftp_not_available', 'error_key' => 'ftp_requirements_missing');
        }
        if ($this->host === '' || $this->username === '') {
            return array('ok' => false, 'error' => 'ftp_missing_params', 'error_key' => 'ftp_missing_credentials');
        }

        try {
            if (extension_loaded('ftp')) {
                return $this->test_ext();
            }
            return $this->test_fsock();
        } catch (Throwable $e) {
            return array('ok' => false, 'error' => $e->getMessage(), 'error_key' => 'ftp_connect_failed');
        }
    }

    private function test_ext(): array {
        $conn = $this->use_tls ? @ftp_ssl_connect($this->host, $this->port, 15) : @ftp_connect($this->host, $this->port, 15);
        if (!$conn) {
            return array('ok' => false, 'error' => 'ftp_connect_failed', 'error_key' => 'ftp_connect_failed');
        }
        if (!@ftp_login($conn, $this->username, $this->password)) {
            @ftp_close($conn);
            return array('ok' => false, 'error' => 'ftp_login_failed', 'error_key' => 'ftp_login_failed');
        }
        @ftp_pasv($conn, true);

        if ($this->path_prefix !== '') {
            if (!@ftp_chdir($conn, $this->path_prefix)) {
                @ftp_close($conn);
                return array('ok' => false, 'error' => 'ftp_cd_failed', 'error_key' => 'ftp_path_inaccessible');
            }
        }

        $tmp = 'gojs-test-' . uniqid('', true) . '.txt';
        $content = 'gojs-test-' . time();
        $tmp_file = tempnam(sys_get_temp_dir(), 'gojs_ftp_test_');
        @file_put_contents($tmp_file, $content);

        if (!@ftp_put($conn, $tmp, $tmp_file, FTP_BINARY)) {
            @unlink($tmp_file);
            @ftp_close($conn);
            return array('ok' => false, 'error' => 'ftp_put_failed', 'error_key' => 'ftp_not_writable');
        }
        @unlink($tmp_file);

        $list = @ftp_nlist($conn, '.');
        @ftp_delete($conn, $tmp);
        @ftp_close($conn);

        return array('ok' => true);
    }

    private function test_fsock(): array {
        $fp = @fsockopen($this->host, $this->port, $errno, $errstr, 15);
        if (!$fp) {
            return array('ok' => false, 'error' => "fsock_connect: $errstr ($errno)", 'error_key' => 'ftp_connect_failed');
        }
        stream_set_timeout($fp, 15);

        $read = function () use ($fp) {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $data;
        };
        $write = function ($cmd) use ($fp) {
            fputs($fp, $cmd . "\r\n");
        };
        $code = function ($resp) {
            return (int)substr(trim($resp), 0, 3);
        };

        $banner = $read();
        if ($code($banner) !== 220) {
            fclose($fp);
            return array('ok' => false, 'error' => 'ftp_banner_invalid', 'error_key' => 'ftp_connect_failed');
        }

        $write('USER ' . $this->username);
        $r = $read();
        if ($code($r) === 331) {
            $write('PASS ' . $this->password);
            $r = $read();
        }
        if ($code($r) !== 230) {
            fclose($fp);
            return array('ok' => false, 'error' => 'ftp_login_failed', 'error_key' => 'ftp_login_failed');
        }

        if ($this->path_prefix !== '') {
            $write('CWD ' . $this->path_prefix);
            $r = $read();
            if ($code($r) !== 250) {
                fclose($fp);
                return array('ok' => false, 'error' => 'ftp_cd_failed', 'error_key' => 'ftp_path_inaccessible');
            }
        }

        $write('PASV');
        $r = $read();
        if ($code($r) !== 227) {
            fclose($fp);
            return array('ok' => false, 'error' => 'ftp_pasv_failed');
        }
        if (!preg_match('/\\((\\d+),(\\d+),(\\d+),(\\d+),(\\d+),(\\d+)\\)/', $r, $m)) {
            fclose($fp);
            return array('ok' => false, 'error' => 'ftp_pasv_parse_failed');
        }
        $dhost = $m[1] . '.' . $m[2] . '.' . $m[3] . '.' . $m[4];
        $dport = ((int)$m[5] << 8) | (int)$m[6];

        $tmp = 'gojs-test-' . uniqid('', true) . '.txt';
        $content = 'gojs-test-' . time();

        $write('STOR ' . $tmp);
        $r = $read();
        if ($code($r) !== 150 && $code($r) !== 125) {
            fclose($fp);
            return array('ok' => false, 'error' => 'ftp_stor_failed', 'error_key' => 'ftp_not_writable');
        }

        $dfp = @fsockopen($dhost, $dport, $errno, $errstr, 10);
        if (!$dfp) {
            fclose($fp);
            return array('ok' => false, 'error' => "ftp_data_connect: $errstr ($errno)");
        }
        fwrite($dfp, $content);
        fclose($dfp);
        $r = $read();

        $write('DELE ' . $tmp);
        $read();
        $write('QUIT');
        $read();
        fclose($fp);

        return array('ok' => true);
    }

    /* ---------- 远端文件操作（Task 3：browse / download 闭环） ----------
     * key 语义与 gojs_backup_execute_schedule / gojs_retention_prune 一致：
     * 传入的是相对 FTP 服务器根目录的完整远端键（含 path_prefix）。
     * listObjects 返回纯数组 [{key,size,last_modified}]。
     */

    // 建立 FTP 连接（ext 路径）：登录 + 被动模式，不切换目录
    private function connect_ext() {
        if (!extension_loaded('ftp')) return null;
        $conn = $this->use_tls ? @ftp_ssl_connect($this->host, $this->port, 15) : @ftp_connect($this->host, $this->port, 15);
        if (!$conn) return null;
        if (!@ftp_login($conn, $this->username, $this->password)) {
            @ftp_close($conn);
            return null;
        }
        @ftp_pasv($conn, true);
        return $conn;
    }

    // 建立 FTP 控制连接（fsock 路径），返回 array('fp'=>..., 'read'=>..., 'write'=>..., 'code'=>...)
    private function connect_fsock() {
        $fp = @fsockopen($this->host, $this->port, $errno, $errstr, 15);
        if (!$fp) return null;
        stream_set_timeout($fp, 15);

        $read = function () use ($fp) {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') break;
            }
            return $data;
        };
        $write = function ($cmd) use ($fp) {
            fputs($fp, $cmd . "\r\n");
        };
        $code = function ($resp) {
            return (int)substr(trim($resp), 0, 3);
        };

        $banner = $read();
        if ($code($banner) !== 220) {
            fclose($fp);
            return null;
        }

        $write('USER ' . $this->username);
        $r = $read();
        if ($code($r) === 331) {
            $write('PASS ' . $this->password);
            $r = $read();
        }
        if ($code($r) !== 230) {
            fclose($fp);
            return null;
        }

        return array('fp' => $fp, 'read' => $read, 'write' => $write, 'code' => $code);
    }

    // fsock 被动模式数据连接
    private function fsock_data_conn($wrap) {
        $wrap['write']('PASV');
        $r = $wrap['read']();
        if ($wrap['code']($r) !== 227) return null;
        if (!preg_match('/\((\d+),(\d+),(\d+),(\d+),(\d+),(\d+)\)/', $r, $m)) return null;
        $dhost = $m[1] . '.' . $m[2] . '.' . $m[3] . '.' . $m[4];
        $dport = ((int)$m[5] << 8) | (int)$m[6];
        $dfp = @fsockopen($dhost, $dport, $errno, $errstr, 10);
        if (!$dfp) return null;
        stream_set_timeout($dfp, 15);
        return $dfp;
    }

    // 关闭 fsock 控制连接
    private function fsock_close($wrap) {
        if (!$wrap || !is_resource($wrap['fp'])) return;
        @$wrap['write']('QUIT');
        @$wrap['read']();
        @fclose($wrap['fp']);
    }

    // 解析 rawlist 一行（UNIX / DOS），解析不到 size 给 0、last_modified 给 ''
    private function parse_rawlist_line($line) {
        $line = rtrim($line, "\r\n");
        if (preg_match('/^[bcdlps-][rwxst-]{9}\s+\d+\s+\S+\s+\S+\s+(\d+)\s+(\w{3})\s+(\d{1,2})\s+([\d:]{4,5}|\d{4})\s+(.+)$/', $line, $m)) {
            $name = trim($m[5]);
            if ($name === '.' || $name === '..') return null;
            $ts = strtotime($m[2] . ' ' . $m[3] . ' ' . $m[4]);
            return array(
                'key' => $name,
                'size' => (int)$m[1],
                'last_modified' => $ts !== false && $ts > 0 ? gmdate('c', $ts) : '',
            );
        }
        if (preg_match('/^(\d{2}-\d{2}-\d{2})\s+(\d{2}:\d{2}[AP]M)\s+(.+)$/', $line, $m)) {
            $rest = trim($m[3]);
            $size = 0;
            $name = $rest;
            if (strtoupper(substr($rest, 0, 5)) === '<DIR>') {
                $rest2 = trim(substr($rest, 5));
                if ($rest2 !== '') $name = $rest2;
            } elseif (preg_match('/^(\d+)\s+(.+)$/', $rest, $m2)) {
                $size = (int)$m2[1];
                $name = trim($m2[2]);
            }
            if ($name === '.' || $name === '..') return null;
            $ts = strtotime($m[1] . ' ' . $m[2]);
            return array(
                'key' => $name,
                'size' => $size,
                'last_modified' => $ts !== false && $ts > 0 ? gmdate('c', $ts) : '',
            );
        }
        return null;
    }

    // 归一化条目 key：无目录部分的补全为完整 key
    private function normalize_list_key($key, $prefix) {
        $key = trim($key);
        if ($key === '' || $key === '.' || $key === '..') return '';
        if (strpos($key, '/') !== false) return $key;
        $p = rtrim($prefix, '/');
        return $p !== '' ? $p . '/' . $key : $key;
    }

    public function putObject(string $key, $body): array {
        $content = is_resource($body) ? stream_get_contents($body) : (string)$body;
        if (extension_loaded('ftp')) {
            $conn = $this->connect_ext();
            if (!$conn) {
                return array('ok' => false, 'error' => 'ftp_connect_failed', 'error_key' => 'ftp_connect_failed');
            }
            $tmp_file = tempnam(sys_get_temp_dir(), 'gojs_put_');
            if (@file_put_contents($tmp_file, $content) === false) {
                @ftp_close($conn);
                return array('ok' => false, 'error' => 'ftp_tmp_write_failed');
            }
            $ok = @ftp_put($conn, $key, $tmp_file, FTP_BINARY);
            @unlink($tmp_file);
            @ftp_close($conn);
            if (!$ok) {
                return array('ok' => false, 'error' => 'ftp_put_failed', 'error_key' => 'ftp_not_writable');
            }
            return array('ok' => true);
        }

        // fsock 路径：PASV + STOR
        $wrap = $this->connect_fsock();
        if (!$wrap) {
            return array('ok' => false, 'error' => 'ftp_connect_failed', 'error_key' => 'ftp_connect_failed');
        }
        $dfp = $this->fsock_data_conn($wrap);
        if (!$dfp) {
            $this->fsock_close($wrap);
            return array('ok' => false, 'error' => 'ftp_pasv_failed');
        }
        $wrap['write']('STOR ' . $key);
        $r = $wrap['read']();
        if ($wrap['code']($r) !== 150 && $wrap['code']($r) !== 125) {
            fclose($dfp);
            $this->fsock_close($wrap);
            return array('ok' => false, 'error' => 'ftp_stor_failed', 'error_key' => 'ftp_not_writable');
        }
        foreach (str_split($content, 8192) as $chunk) {
            fwrite($dfp, $chunk);
        }
        fclose($dfp);
        $wrap['read']();
        $this->fsock_close($wrap);
        return array('ok' => true);
    }

    public function listObjects(string $prefix, int $max = 1000): array {
        $items = array();
        if (extension_loaded('ftp')) {
            $conn = $this->connect_ext();
            if (!$conn) return $items;
            $raw = @ftp_rawlist($conn, $prefix !== '' ? $prefix : '.');
            @ftp_close($conn);
            if (!is_array($raw)) return $items;
            foreach ($raw as $line) {
                $parsed = $this->parse_rawlist_line($line);
                if (!$parsed) continue;
                $key = $this->normalize_list_key($parsed['key'], $prefix);
                if ($key === '') continue;
                $items[] = array('key' => $key, 'size' => $parsed['size'], 'last_modified' => $parsed['last_modified']);
                if (count($items) >= $max) break;
            }
            return $items;
        }

        // fsock 路径：PASV + NLST
        $wrap = $this->connect_fsock();
        if (!$wrap) return $items;
        $dfp = $this->fsock_data_conn($wrap);
        if (!$dfp) {
            $this->fsock_close($wrap);
            return $items;
        }
        $wrap['write']('NLST ' . ($prefix !== '' ? $prefix : '.'));
        $r = $wrap['read']();
        if ($wrap['code']($r) !== 150 && $wrap['code']($r) !== 125) {
            fclose($dfp);
            $this->fsock_close($wrap);
            return $items;
        }
        $data = '';
        while (!feof($dfp)) {
            $data .= fread($dfp, 8192);
        }
        fclose($dfp);
        $wrap['read']();
        $this->fsock_close($wrap);
        foreach (preg_split('/\r\n|\r|\n/', trim($data)) as $n) {
            if ($n === '') continue;
            $key = $this->normalize_list_key($n, $prefix);
            if ($key === '') continue;
            $items[] = array('key' => $key, 'size' => 0, 'last_modified' => '');
            if (count($items) >= $max) break;
        }
        return $items;
    }

    public function deleteObject(string $key): array {
        if (extension_loaded('ftp')) {
            $conn = $this->connect_ext();
            if (!$conn) {
                return array('ok' => false, 'error' => 'ftp_connect_failed', 'error_key' => 'ftp_connect_failed');
            }
            $ok = @ftp_delete($conn, $key);
            @ftp_close($conn);
            if (!$ok) {
                return array('ok' => false, 'error' => 'ftp_delete_failed');
            }
            return array('ok' => true);
        }

        $wrap = $this->connect_fsock();
        if (!$wrap) {
            return array('ok' => false, 'error' => 'ftp_connect_failed', 'error_key' => 'ftp_connect_failed');
        }
        $wrap['write']('DELE ' . $key);
        $r = $wrap['read']();
        $ok = $wrap['code']($r) === 250;
        $this->fsock_close($wrap);
        if (!$ok) {
            return array('ok' => false, 'error' => 'ftp_delete_failed');
        }
        return array('ok' => true);
    }

    public function getObject(string $key): array {
        if (extension_loaded('ftp')) {
            $conn = $this->connect_ext();
            if (!$conn) {
                return array('ok' => false, 'error' => 'ftp_connect_failed', 'error_key' => 'ftp_connect_failed');
            }
            $tmp_file = tempnam(sys_get_temp_dir(), 'gojs_get_');
            if (!@ftp_get($conn, $tmp_file, $key, FTP_BINARY)) {
                @unlink($tmp_file);
                @ftp_close($conn);
                return array('ok' => false, 'error' => 'ftp_get_failed', 'error_key' => 'ftp_get_failed');
            }
            @ftp_close($conn);
            return array('ok' => true, 'tmp_path' => $tmp_file);
        }

        // fsock 路径：PASV + RETR
        $wrap = $this->connect_fsock();
        if (!$wrap) {
            return array('ok' => false, 'error' => 'ftp_connect_failed', 'error_key' => 'ftp_connect_failed');
        }
        $dfp = $this->fsock_data_conn($wrap);
        if (!$dfp) {
            $this->fsock_close($wrap);
            return array('ok' => false, 'error' => 'ftp_pasv_failed');
        }
        $wrap['write']('RETR ' . $key);
        $r = $wrap['read']();
        if ($wrap['code']($r) !== 150 && $wrap['code']($r) !== 125) {
            fclose($dfp);
            $this->fsock_close($wrap);
            return array('ok' => false, 'error' => 'ftp_get_failed', 'error_key' => 'ftp_get_failed');
        }
        $tmp_file = tempnam(sys_get_temp_dir(), 'gojs_get_');
        $out = fopen($tmp_file, 'wb');
        while (!feof($dfp)) {
            $chunk = fread($dfp, 8192);
            if ($chunk === false || $chunk === '') break;
            fwrite($out, $chunk);
        }
        fclose($out);
        fclose($dfp);
        $wrap['read']();
        $this->fsock_close($wrap);
        return array('ok' => true, 'tmp_path' => $tmp_file);
    }
}

class GOJS_SFTP_Destination {
    private $host;
    private $port;
    private $username;
    private $password;
    private $private_key;
    private $path_prefix;

    public static function available(): bool {
        return extension_loaded('ssh2') || function_exists('fsockopen');
    }

    public function __construct($config) {
        $this->host = isset($config['host']) ? (string)$config['host'] : '';
        $this->port = isset($config['port']) ? (int)$config['port'] : 22;
        $this->username = isset($config['username']) ? (string)$config['username'] : '';
        $this->password = isset($config['password']) ? (string)$config['password'] : '';
        $this->private_key = isset($config['private_key']) ? (string)$config['private_key'] : '';
        $this->path_prefix = isset($config['path_prefix']) ? trim((string)$config['path_prefix'], '/') : '';
    }

    public function testConnection(): array {
        if (!self::available()) {
            return array('ok' => false, 'error' => 'sftp_not_available', 'error_key' => 'sftp_requirements_missing');
        }
        if ($this->host === '' || $this->username === '') {
            return array('ok' => false, 'error' => 'sftp_missing_params', 'error_key' => 'sftp_missing_credentials');
        }

        if (!extension_loaded('ssh2')) {
            return array('ok' => false, 'error' => 'ssh2_extension_required', 'error_key' => 'sftp_ssh2_required');
        }

        try {
            return $this->test_ssh2();
        } catch (Throwable $e) {
            return array('ok' => false, 'error' => $e->getMessage(), 'error_key' => 'sftp_connect_failed');
        }
    }

    private function test_ssh2(): array {
        $conn = @ssh2_connect($this->host, $this->port);
        if (!$conn) {
            return array('ok' => false, 'error' => 'sftp_connect_failed', 'error_key' => 'sftp_connect_failed');
        }

        $authed = false;
        if ($this->private_key !== '') {
            $tmp_pub = '';
            $tmp_key = tempnam(sys_get_temp_dir(), 'gojs_sftp_key_');
            $tmp_pub_file = $tmp_key . '.pub';
            @file_put_contents($tmp_key, $this->private_key);
            @chmod($tmp_key, 0600);

            $lines = explode("\n", trim($this->private_key));
            if (strpos($lines[0] ?? '', 'PRIVATE KEY') !== false) {
                $openssl_available = function_exists('openssl_pkey_get_private');
                if ($openssl_available) {
                    $pkey = @openssl_pkey_get_private($this->private_key);
                    if ($pkey) {
                        $pub = @openssl_pkey_get_details($pkey);
                        if ($pub && isset($pub['key'])) {
                            @file_put_contents($tmp_pub_file, $pub['key']);
                            $tmp_pub = $tmp_pub_file;
                        }
                        @openssl_free_key($pkey);
                    }
                }
            }

            $authed = @ssh2_auth_pubkey_file(
                $conn,
                $this->username,
                $tmp_pub,
                $tmp_key,
                $this->password !== '' ? $this->password : null
            );

            if (!$authed && $this->password !== '') {
                $authed = @ssh2_auth_password($conn, $this->username, $this->password);
            }

            @unlink($tmp_key);
            if ($tmp_pub !== '') @unlink($tmp_pub);
        } elseif ($this->password !== '') {
            $authed = @ssh2_auth_password($conn, $this->username, $this->password);
        }

        if (!$authed) {
            return array('ok' => false, 'error' => 'sftp_auth_failed', 'error_key' => 'sftp_login_failed');
        }

        $sftp = @ssh2_sftp($conn);
        if (!$sftp) {
            return array('ok' => false, 'error' => 'sftp_init_failed');
        }

        $target_path = '/';
        if ($this->path_prefix !== '') {
            $target_path = '/' . $this->path_prefix . '/';
            $stat = @ssh2_sftp_stat($sftp, $this->path_prefix);
            if ($stat === false) {
                return array('ok' => false, 'error' => 'sftp_cd_failed', 'error_key' => 'sftp_path_inaccessible');
            }
        }

        $tmp = 'gojs-test-' . uniqid('', true) . '.txt';
        $content = 'gojs-test-' . time();
        $remote_path = $target_path . $tmp;

        $stream = @fopen('ssh2.sftp://' . intval($sftp) . $remote_path, 'w');
        if (!$stream) {
            return array('ok' => false, 'error' => 'sftp_write_failed', 'error_key' => 'sftp_not_writable');
        }
        fwrite($stream, $content);
        fclose($stream);

        $dir = @opendir('ssh2.sftp://' . intval($sftp) . $target_path);
        if ($dir) {
            $found = false;
            while (($entry = readdir($dir)) !== false) {
                if ($entry === $tmp) { $found = true; break; }
            }
            closedir($dir);
        }

        @ssh2_sftp_unlink($sftp, ltrim($remote_path, '/'));

        return array('ok' => true);
    }

    /* ---------- 远端文件操作（Task 3：browse / download 闭环） ----------
     * key 语义与 gojs_backup_execute_schedule / gojs_retention_prune 一致：
     * 传入的是相对 SFTP 根目录的完整远端键（含 path_prefix）。
     * listObjects 返回纯数组 [{key,size,last_modified}]。
     */

    // 建立 ssh2 连接并认证，返回 array('conn' => $conn, 'sftp' => $sftp)，失败返回 null
    private function connect_ssh2() {
        if (!extension_loaded('ssh2')) return null;
        $conn = @ssh2_connect($this->host, $this->port);
        if (!$conn) return null;

        $authed = false;
        if ($this->private_key !== '') {
            $tmp_pub = '';
            $tmp_key = tempnam(sys_get_temp_dir(), 'gojs_sftp_key_');
            $tmp_pub_file = $tmp_key . '.pub';
            @file_put_contents($tmp_key, $this->private_key);
            @chmod($tmp_key, 0600);

            $lines = explode("\n", trim($this->private_key));
            if (strpos($lines[0] ?? '', 'PRIVATE KEY') !== false) {
                if (function_exists('openssl_pkey_get_private')) {
                    $pkey = @openssl_pkey_get_private($this->private_key);
                    if ($pkey) {
                        $pub = @openssl_pkey_get_details($pkey);
                        if ($pub && isset($pub['key'])) {
                            @file_put_contents($tmp_pub_file, $pub['key']);
                            $tmp_pub = $tmp_pub_file;
                        }
                        @openssl_free_key($pkey);
                    }
                }
            }

            $authed = @ssh2_auth_pubkey_file(
                $conn,
                $this->username,
                $tmp_pub,
                $tmp_key,
                $this->password !== '' ? $this->password : null
            );

            if (!$authed && $this->password !== '') {
                $authed = @ssh2_auth_password($conn, $this->username, $this->password);
            }

            @unlink($tmp_key);
            if ($tmp_pub !== '') @unlink($tmp_pub);
        } elseif ($this->password !== '') {
            $authed = @ssh2_auth_password($conn, $this->username, $this->password);
        }

        if (!$authed) return null;

        $sftp = @ssh2_sftp($conn);
        if (!$sftp) return null;
        return array('conn' => $conn, 'sftp' => $sftp);
    }

    private function remote_url($sftp, $path) {
        return 'ssh2.sftp://' . intval($sftp) . '/' . ltrim($path, '/');
    }

    public function putObject(string $key, $body): array {
        $ctx = $this->connect_ssh2();
        if (!$ctx) {
            return array('ok' => false, 'error' => 'sftp_connect_failed', 'error_key' => 'sftp_connect_failed');
        }
        $content = is_resource($body) ? stream_get_contents($body) : (string)$body;
        $tmp_file = tempnam(sys_get_temp_dir(), 'gojs_put_');
        if (@file_put_contents($tmp_file, $content) === false) {
            @unlink($tmp_file);
            return array('ok' => false, 'error' => 'sftp_tmp_write_failed');
        }
        $ok = @ssh2_scp_send($ctx['conn'], $tmp_file, '/' . ltrim($key, '/'), 0644);
        @unlink($tmp_file);
        if (!$ok) {
            return array('ok' => false, 'error' => 'sftp_put_failed', 'error_key' => 'sftp_not_writable');
        }
        return array('ok' => true);
    }

    public function listObjects(string $prefix, int $max = 1000): array {
        $items = array();
        $ctx = $this->connect_ssh2();
        if (!$ctx) return $items;
        $dir = '/' . ltrim($prefix, '/');
        $dh = @opendir($this->remote_url($ctx['sftp'], $dir));
        if (!$dh) return $items;
        $dir_trim = rtrim($prefix, '/');
        while (($entry = readdir($dh)) !== false) {
            if ($entry === '.' || $entry === '..') continue;
            $full = $dir_trim !== '' ? $dir_trim . '/' . $entry : $entry;
            $stat = @ssh2_sftp_stat($ctx['sftp'], ltrim($full, '/'));
            if ($stat === false) {
                $stat = @stat($this->remote_url($ctx['sftp'], $full));
            }
            $size = (is_array($stat) && isset($stat['size'])) ? (int)$stat['size'] : 0;
            $mtime = (is_array($stat) && isset($stat['mtime']) && (int)$stat['mtime'] > 0) ? gmdate('c', (int)$stat['mtime']) : '';
            $items[] = array('key' => $full, 'size' => $size, 'last_modified' => $mtime);
            if (count($items) >= $max) break;
        }
        closedir($dh);
        return $items;
    }

    public function deleteObject(string $key): array {
        $ctx = $this->connect_ssh2();
        if (!$ctx) {
            return array('ok' => false, 'error' => 'sftp_connect_failed', 'error_key' => 'sftp_connect_failed');
        }
        $ok = @ssh2_sftp_unlink($ctx['sftp'], ltrim($key, '/'));
        if (!$ok) {
            return array('ok' => false, 'error' => 'sftp_delete_failed');
        }
        return array('ok' => true);
    }

    public function getObject(string $key): array {
        $ctx = $this->connect_ssh2();
        if (!$ctx) {
            return array('ok' => false, 'error' => 'sftp_connect_failed', 'error_key' => 'sftp_connect_failed');
        }
        $tmp_file = tempnam(sys_get_temp_dir(), 'gojs_get_');
        $ok = @ssh2_scp_recv($ctx['conn'], '/' . ltrim($key, '/'), $tmp_file);
        if (!$ok) {
            @unlink($tmp_file);
            return array('ok' => false, 'error' => 'sftp_get_failed', 'error_key' => 'sftp_get_failed');
        }
        return array('ok' => true, 'tmp_path' => $tmp_file);
    }
}

function gojs_backup_destination_factory(array $dest) {
    $type = isset($dest['type']) ? $dest['type'] : '';
    $config = array();

    switch ($type) {
        case 's3':
            $config['access_key'] = isset($dest['access_key_enc']) ? gojs_unseal_secret($dest['access_key_enc']) : '';
            $config['secret_key'] = isset($dest['secret_key_enc']) ? gojs_unseal_secret($dest['secret_key_enc']) : '';
            $config['endpoint'] = $dest['endpoint'] ?? 's3.amazonaws.com';
            $config['region'] = $dest['region'] ?? 'us-east-1';
            $config['bucket'] = $dest['bucket'] ?? '';
            $config['sse'] = !empty($dest['sse']);
            $config['path_prefix'] = $dest['path_prefix'] ?? '';
            return new GOJS_S3_Destination($config);
        case 'ftp':
            $config['host'] = $dest['host'] ?? '';
            $config['port'] = isset($dest['port']) ? (int)$dest['port'] : 21;
            $config['username'] = $dest['username'] ?? '';
            $config['password'] = isset($dest['password_enc']) ? gojs_unseal_secret($dest['password_enc']) : '';
            $config['path_prefix'] = $dest['path_prefix'] ?? '';
            $config['use_tls'] = !empty($dest['use_tls']);
            return new GOJS_FTP_Destination($config);
        case 'sftp':
            $config['host'] = $dest['host'] ?? '';
            $config['port'] = isset($dest['port']) ? (int)$dest['port'] : 22;
            $config['username'] = $dest['username'] ?? '';
            $config['password'] = isset($dest['password_enc']) ? gojs_unseal_secret($dest['password_enc']) : '';
            $config['private_key'] = isset($dest['private_key_enc']) ? gojs_unseal_secret($dest['private_key_enc']) : '';
            $config['path_prefix'] = $dest['path_prefix'] ?? '';
            return new GOJS_SFTP_Destination($config);
        default:
            throw new RuntimeException('Unknown destination type: ' . $type);
    }
}

function gojs_api_backup_destinations_list() {
    $destinations = gojs_destinations_load();
    $redacted = array_map('gojs_destination_redact', $destinations);
    gojs_json_response(array('destinations' => $redacted));
}

function gojs_api_backup_destinations_create() {
    $body = gojs_get_body();
    if (!is_array($body) || empty($body['type'])) {
        gojs_json_response(null, array('code' => 'invalid_input', 'message' => 'type 是必填项'), 400);
    }

    $id = 'dest_' . substr(bin2hex(random_bytes(12)), 0, 16);
    $dest = array(
        'id' => $id,
        'name' => isset($body['name']) ? trim((string)$body['name']) : '',
        'type' => (string)$body['type'],
        'path_prefix' => isset($body['path_prefix']) ? (string)$body['path_prefix'] : '',
        'created_at' => time(),
        'last_test_ok' => null,
        'last_test_at' => null,
    );

    switch ($dest['type']) {
        case 's3':
            if (empty($body['name']) || empty($body['access_key']) || empty($body['secret_key']) || empty($body['bucket'])) {
                gojs_json_response(null, array('code' => 'invalid_input', 'message' => 'name, access_key, secret_key, bucket 为必填项'), 400);
            }
            $dest['access_key_enc'] = gojs_seal_secret((string)$body['access_key']);
            $dest['secret_key_enc'] = gojs_seal_secret((string)$body['secret_key']);
            $dest['endpoint'] = isset($body['endpoint']) && $body['endpoint'] !== '' ? (string)$body['endpoint'] : 's3.amazonaws.com';
            $dest['region'] = isset($body['region']) ? (string)$body['region'] : 'us-east-1';
            $dest['bucket'] = (string)$body['bucket'];
            $dest['sse'] = !empty($body['sse']);
            break;
        case 'ftp':
            if (empty($body['name']) || empty($body['host']) || empty($body['username'])) {
                gojs_json_response(null, array('code' => 'invalid_input', 'message' => 'name, host, username 为必填项'), 400);
            }
            $dest['host'] = (string)$body['host'];
            $dest['port'] = isset($body['port']) ? (int)$body['port'] : 21;
            $dest['username'] = (string)$body['username'];
            $dest['password_enc'] = gojs_seal_secret((string)($body['password'] ?? ''));
            $dest['use_tls'] = !empty($body['use_tls']);
            break;
        case 'sftp':
            if (empty($body['name']) || empty($body['host']) || empty($body['username'])) {
                gojs_json_response(null, array('code' => 'invalid_input', 'message' => 'name, host, username 为必填项'), 400);
            }
            $dest['host'] = (string)$body['host'];
            $dest['port'] = isset($body['port']) ? (int)$body['port'] : 22;
            $dest['username'] = (string)$body['username'];
            $dest['password_enc'] = gojs_seal_secret((string)($body['password'] ?? ''));
            $dest['private_key_enc'] = gojs_seal_secret((string)($body['private_key'] ?? ''));
            break;
        default:
            gojs_json_response(null, array('code' => 'invalid_input', 'message' => '不支持的 type: ' . $dest['type']), 400);
    }

    $destinations = gojs_destinations_load();
    $destinations[] = $dest;
    gojs_destinations_save($destinations);
    gojs_log_operation('backup_destination_create', $dest['name'], true, $dest['type']);

    gojs_json_response(array('destination' => gojs_destination_redact($dest)));
}

function gojs_api_backup_destinations_update($id) {
    $body = gojs_get_body();
    if (!is_array($body)) {
        gojs_json_response(null, array('code' => 'invalid_input', 'message' => '请求体无效'), 400);
    }

    $destinations = gojs_destinations_load();
    $idx = null;
    foreach ($destinations as $i => $d) {
        if (isset($d['id']) && $d['id'] === $id) {
            $idx = $i;
            break;
        }
    }
    if ($idx === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '目标不存在'), 404);
    }

    $dest = $destinations[$idx];

    if (isset($body['name'])) $dest['name'] = trim((string)$body['name']);
    if (isset($body['path_prefix'])) $dest['path_prefix'] = (string)$body['path_prefix'];

    switch ($dest['type']) {
        case 's3':
            if (isset($body['access_key']) && $body['access_key'] !== '' && $body['access_key'] !== '****') {
                $dest['access_key_enc'] = gojs_seal_secret((string)$body['access_key']);
            }
            if (isset($body['secret_key']) && $body['secret_key'] !== '' && $body['secret_key'] !== '****') {
                $dest['secret_key_enc'] = gojs_seal_secret((string)$body['secret_key']);
            }
            if (isset($body['endpoint'])) $dest['endpoint'] = (string)$body['endpoint'];
            if (isset($body['region'])) $dest['region'] = (string)$body['region'];
            if (isset($body['bucket'])) $dest['bucket'] = (string)$body['bucket'];
            if (isset($body['sse'])) $dest['sse'] = !empty($body['sse']);
            break;
        case 'ftp':
            if (isset($body['host'])) $dest['host'] = (string)$body['host'];
            if (isset($body['port'])) $dest['port'] = (int)$body['port'];
            if (isset($body['username'])) $dest['username'] = (string)$body['username'];
            if (isset($body['password']) && $body['password'] !== '' && $body['password'] !== '****') {
                $dest['password_enc'] = gojs_seal_secret((string)$body['password']);
            }
            if (isset($body['use_tls'])) $dest['use_tls'] = !empty($body['use_tls']);
            break;
        case 'sftp':
            if (isset($body['host'])) $dest['host'] = (string)$body['host'];
            if (isset($body['port'])) $dest['port'] = (int)$body['port'];
            if (isset($body['username'])) $dest['username'] = (string)$body['username'];
            if (isset($body['password']) && $body['password'] !== '' && $body['password'] !== '****') {
                $dest['password_enc'] = gojs_seal_secret((string)$body['password']);
            }
            if (isset($body['private_key']) && $body['private_key'] !== '' && $body['private_key'] !== '****') {
                $dest['private_key_enc'] = gojs_seal_secret((string)$body['private_key']);
            }
            break;
    }

    $destinations[$idx] = $dest;
    gojs_destinations_save($destinations);
    gojs_log_operation('backup_destination_update', $dest['name'], true, $dest['type']);

    gojs_json_response(array('destination' => gojs_destination_redact($dest)));
}

function gojs_api_backup_destinations_delete($id) {
    $destinations = gojs_destinations_load();
    $kept = array();
    $deleted = null;
    foreach ($destinations as $d) {
        if (isset($d['id']) && $d['id'] === $id) {
            $deleted = $d;
        } else {
            $kept[] = $d;
        }
    }
    if ($deleted === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '目标不存在'), 404);
    }
    gojs_destinations_save($kept);
    gojs_log_operation('backup_destination_delete', $deleted['name'] ?? $id, true, $deleted['type'] ?? '');

    gojs_json_response(array('success' => true));
}

function gojs_api_backup_destinations_test() {
    $body = gojs_get_body();
    if (!is_array($body) || empty($body['type'])) {
        gojs_json_response(null, array('code' => 'invalid_input', 'message' => 'type 是必填项'), 400);
    }

    $type = (string)$body['type'];
    $tmp_dest = array('type' => $type, 'path_prefix' => $body['path_prefix'] ?? '');

    switch ($type) {
        case 's3':
            $tmp_dest['access_key_enc'] = gojs_seal_secret((string)($body['access_key'] ?? ''));
            $tmp_dest['secret_key_enc'] = gojs_seal_secret((string)($body['secret_key'] ?? ''));
            $tmp_dest['endpoint'] = $body['endpoint'] ?? 's3.amazonaws.com';
            $tmp_dest['region'] = $body['region'] ?? 'us-east-1';
            $tmp_dest['bucket'] = $body['bucket'] ?? '';
            $tmp_dest['sse'] = !empty($body['sse']);
            break;
        case 'ftp':
            $tmp_dest['host'] = $body['host'] ?? '';
            $tmp_dest['port'] = isset($body['port']) ? (int)$body['port'] : 21;
            $tmp_dest['username'] = $body['username'] ?? '';
            $tmp_dest['password_enc'] = gojs_seal_secret((string)($body['password'] ?? ''));
            $tmp_dest['use_tls'] = !empty($body['use_tls']);
            break;
        case 'sftp':
            $tmp_dest['host'] = $body['host'] ?? '';
            $tmp_dest['port'] = isset($body['port']) ? (int)$body['port'] : 22;
            $tmp_dest['username'] = $body['username'] ?? '';
            $tmp_dest['password_enc'] = gojs_seal_secret((string)($body['password'] ?? ''));
            $tmp_dest['private_key_enc'] = gojs_seal_secret((string)($body['private_key'] ?? ''));
            break;
        default:
            gojs_json_response(null, array('code' => 'invalid_input', 'message' => '不支持的 type: ' . $type), 400);
    }

    try {
        $adapter = gojs_backup_destination_factory($tmp_dest);
        $result = $adapter->testConnection();
    } catch (Throwable $e) {
        $result = array('ok' => false, 'error' => $e->getMessage(), 'error_key' => 'unexpected_error');
    }

    $ok = !empty($result['ok']);
    if (!empty($body['id'])) {
        $id = (string)$body['id'];
        $destinations = gojs_destinations_load();
        foreach ($destinations as $i => $d) {
            if (isset($d['id']) && $d['id'] === $id) {
                $destinations[$i]['last_test_ok'] = $ok;
                $destinations[$i]['last_test_at'] = time();
                gojs_destinations_save($destinations);
                break;
            }
        }
    }

    gojs_json_response($result);
}

/* ============================================================
   远端备份浏览 / 下载（Task 3：远程备份恢复闭环）
   ============================================================ */

// 远端备份文件名格式（与 gojs_backup_execute_schedule 上传、gojs_retention_prune 清理一致）
function gojs_remote_backup_key_valid($key) {
    if (!is_string($key) || $key === '') return false;
    // 防路径穿越：只允许固定格式文件名（不含 / 与 ..）
    $basename = basename($key);
    return preg_match('/^(gojs-backup-\d{8}_\d{6}|backup-\d{8}-\d{6})\.zip$/', $basename) === 1;
}

// 列出远端 gojs-backup-*.zip 备份文件
function gojs_api_backup_destinations_browse() {
    $body = gojs_get_body();
    $dest_id = isset($body['dest_id']) ? (string)$body['dest_id'] : '';
    if ($dest_id === '') {
        gojs_json_response(null, array('code' => 'invalid_input', 'message' => 'dest_id 是必填项'), 400);
    }

    $dest = null;
    foreach (gojs_destinations_load() as $d) {
        if (isset($d['id']) && $d['id'] === $dest_id) { $dest = $d; break; }
    }
    if (!$dest) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '备份目标不存在'), 404);
    }

    try {
        $adapter = gojs_backup_destination_factory($dest);
        if (!method_exists($adapter, 'listObjects')) {
            gojs_json_response(null, array('code' => 'adapter_unsupported', 'message' => '该备份目标不支持远端浏览'), 400);
        }

        $path_prefix = isset($dest['path_prefix']) ? trim((string)$dest['path_prefix'], '/') : '';
        $list_prefix = $path_prefix !== '' ? $path_prefix . '/gojs-backup-' : 'gojs-backup-';
        $objects = $adapter->listObjects($list_prefix, 1000);

        $items = array();
        if (is_array($objects)) {
            foreach ($objects as $o) {
                if (!is_array($o)) continue;
                $key = isset($o['key']) ? (string)$o['key'] : '';
                if ($key === '' || !gojs_remote_backup_key_valid($key)) continue;
                $modified = isset($o['last_modified']) ? (string)$o['last_modified'] : '';
                if ($modified !== '') {
                    $ts = strtotime($modified);
                    if ($ts !== false && $ts > 0) $modified = gmdate('c', $ts);
                }
                $items[] = array(
                    'key' => $key,
                    'size' => isset($o['size']) ? (int)$o['size'] : 0,
                    'modified' => $modified,
                );
            }
        }

        gojs_json_response(array('items' => $items, 'prefix' => $list_prefix));
    } catch (Throwable $e) {
        gojs_json_response(null, array('code' => 'browse_failed', 'message' => $e->getMessage()), 400);
    }
}

// 从远端拉取备份到本地 CONFIG_DIR/backups/，返回本地文件名以便走既有恢复流程
function gojs_api_backup_destinations_download() {
    $body = gojs_get_body();
    $dest_id = isset($body['dest_id']) ? (string)$body['dest_id'] : '';
    $key = isset($body['key']) ? (string)$body['key'] : '';
    if ($dest_id === '' || $key === '') {
        gojs_json_response(null, array('code' => 'invalid_input', 'message' => 'dest_id 与 key 为必填项'), 400);
    }

    if (!gojs_remote_backup_key_valid($key)) {
        gojs_json_response(null, array(
            'code' => 'invalid_filename',
            'message' => '无效的备份文件名',
        ), 400);
    }

    $dest = null;
    foreach (gojs_destinations_load() as $d) {
        if (isset($d['id']) && $d['id'] === $dest_id) { $dest = $d; break; }
    }
    if (!$dest) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '备份目标不存在'), 404);
    }

    try {
        $adapter = gojs_backup_destination_factory($dest);
        if (!method_exists($adapter, 'getObject')) {
            gojs_json_response(null, array('code' => 'adapter_unsupported', 'message' => '该备份目标不支持远端下载'), 400);
        }

        $res = $adapter->getObject($key);
        if (empty($res['ok'])) {
            gojs_json_response(null, array(
                'code' => 'download_failed',
                'message' => isset($res['error']) ? $res['error'] : '从远端下载失败',
            ), 400);
        }
        if (empty($res['tmp_path']) || !is_file($res['tmp_path'])) {
            gojs_json_response(null, array(
                'code' => 'download_failed',
                'message' => '下载结果无效',
            ), 500);
        }

        $backup_dir = CONFIG_DIR . '/backups';
        if (!is_dir($backup_dir)) {
            if (!@mkdir($backup_dir, 0700, true)) {
                @unlink($res['tmp_path']);
                gojs_json_response(null, array('code' => 'mkdir_failed', 'message' => '无法创建备份目录'), 500);
            }
        }

        // 本地文件名统一为既有恢复流程可识别的 backup-YYYYmmdd-HHMMSS.zip
        $basename = basename($key);
        $local_name = $basename;
        if (strpos($local_name, 'gojs-backup-') === 0) {
            $local_name = 'backup-' . substr($local_name, strlen('gojs-backup-'));
            $local_name = str_replace('_', '-', $local_name);
        }

        $target = $backup_dir . '/' . $local_name;
        if (is_file($target)) {
            @unlink($target);
        }
        if (!@rename($res['tmp_path'], $target)) {
            if (!@copy($res['tmp_path'], $target)) {
                @unlink($res['tmp_path']);
                gojs_json_response(null, array('code' => 'save_failed', 'message' => '保存备份文件失败'), 500);
            }
            @unlink($res['tmp_path']);
        }

        $size = @filesize($target);
        gojs_log_operation('backup_remote_download', $local_name, true);

        // monitor: 远端备份下载计为面板入站流量
        gojs_monitor_bump_bandwidth($size ? $size : 0, 0);

        gojs_json_response(array('filename' => $local_name, 'size' => $size, 'downloaded' => true));
    } catch (Throwable $e) {
        gojs_json_response(null, array(
            'code' => 'download_failed',
            'message' => $e->getMessage(),
        ), 400);
    }
}

/* ============================================================
   A.1 CRON 表达式解析器 + next_run_at 计算器
   ============================================================ */

function gojs_cron_expand_field($field, $min, $max) {
    if ($field === '' || $field === null) return false;
    $field = trim((string)$field);
    $allowed = array();

    if ($field === '*') {
        for ($i = $min; $i <= $max; $i++) $allowed[] = $i;
        return $allowed;
    }

    $parts = explode(',', $field);
    foreach ($parts as $part) {
        $step = 1;
        if (strpos($part, '/') !== false) {
            list($range_part, $step_str) = explode('/', $part, 2);
            $step = (int)$step_str;
            if ($step < 1) $step = 1;
        } else {
            $range_part = $part;
        }

        if ($range_part === '*' || $range_part === '') {
            for ($i = $min; $i <= $max; $i += $step) $allowed[] = $i;
        } elseif (strpos($range_part, '-') !== false) {
            list($start, $end) = explode('-', $range_part, 2);
            $start = (int)$start;
            $end = (int)$end;
            if ($start < $min) $start = $min;
            if ($end > $max) $end = $max;
            for ($i = $start; $i <= $end; $i += $step) $allowed[] = $i;
        } else {
            $v = (int)$range_part;
            if ($v < $min || $v > $max) return false;
            $allowed[] = $v;
        }
    }

    return array_values(array_unique($allowed));
}

function gojs_cron_next_run($expr, $from_ts) {
    if (!is_string($expr) || trim($expr) === '') {
        return $from_ts + 86400;
    }

    $fields = preg_split('/\s+/', trim($expr));
    if (count($fields) !== 5) {
        return $from_ts + 86400;
    }

    $minutes = gojs_cron_expand_field($fields[0], 0, 59);
    $hours = gojs_cron_expand_field($fields[1], 0, 23);
    $doms = gojs_cron_expand_field($fields[2], 1, 31);
    $months = gojs_cron_expand_field($fields[3], 1, 12);
    $dows = gojs_cron_expand_field($fields[4], 0, 6);

    if ($minutes === false || $hours === false || $doms === false || $months === false || $dows === false) {
        return $from_ts + 86400;
    }

    $minutes_set = array_flip($minutes);
    $hours_set = array_flip($hours);
    $doms_set = array_flip($doms);
    $months_set = array_flip($months);
    $dows_set = array_flip($dows);

    $start_ts = $from_ts + 60;
    $max_ts = $from_ts + 86400 * 366;

    $current = $start_ts - ($start_ts % 60);

    while ($current <= $max_ts) {
        $parts = getdate($current);
        $min = (int)$parts['minutes'];
        $hour = (int)$parts['hours'];
        $dom = (int)$parts['mday'];
        $mon = (int)$parts['mon'];
        $dow = (int)$parts['wday'];

        if (
            isset($months_set[$mon]) &&
            isset($doms_set[$dom]) &&
            isset($dows_set[$dow]) &&
            isset($hours_set[$hour]) &&
            isset($minutes_set[$min])
        ) {
            return $current;
        }

        $current += 60;
    }

    return $from_ts + 86400;
}

function gojs_cron_human_readable($expr) {
    if (!is_string($expr)) return $expr;
    $fields = preg_split('/\s+/', trim($expr));
    if (count($fields) !== 5) return $expr;

    list($min, $hour, $dom, $mon, $dow) = $fields;

    if ($min === '0' && $dom === '*' && $mon === '*' && $dow === '*' &&
        preg_match('#^\*/(\d+)$#', $hour, $m)) {
        $n = (int)$m[1];
        return "Every {$n} hours";
    }
    if ($min === '0' && $hour === '*' && $dom === '*' && $mon === '*' && $dow === '*') {
        return 'Every hour';
    }
    if ($min === '*' && $hour === '*' && $dom === '*' && $mon === '*' && $dow === '*') {
        return 'Every minute';
    }
    if ($min === '0' && $hour === '0' && $dom === '*' && $mon === '*' && $dow === '0') {
        return 'Weekly on Sunday 00:00';
    }
    if ($min === '0' && $hour === '0' && $dom === '1' && $mon === '*' && $dow === '*') {
        return 'Monthly on day 1 00:00';
    }
    if (preg_match('#^(\d+)$#', $min, $m1) && preg_match('#^(\d+)$#', $hour, $m2) &&
        $dom === '*' && $mon === '*' && $dow === '*') {
        $h = str_pad($m2[1], 2, '0', STR_PAD_LEFT);
        $m = str_pad($m1[1], 2, '0', STR_PAD_LEFT);
        return "Daily at {$h}:{$m}";
    }

    return trim($expr);
}

/* ============================================================
   A.2 Schedules 存储 + CRUD 端点
   ============================================================ */

function gojs_schedules_load(): array {
    global $config;
    return isset($config['backup_schedules']) && is_array($config['backup_schedules'])
        ? $config['backup_schedules']
        : array();
}

function gojs_schedules_save(array $schedules): void {
    global $config;
    $config['backup_schedules'] = $schedules;
    gojs_save_config();
}

function gojs_backup_runs_path(): string {
    return CONFIG_DIR . '/backup_runs.json';
}

function gojs_backup_runs_load(): array {
    return gojs_read_json_lock_safe(gojs_backup_runs_path(), array());
}

function gojs_backup_runs_save(array $runs): void {
    $cap = 1000;
    if (count($runs) > $cap) {
        $runs = array_slice($runs, -$cap);
    }
    gojs_write_json_lock_safe(gojs_backup_runs_path(), $runs, true);
}

function gojs_validate_cron_expr($expr): bool {
    if (!is_string($expr)) return false;
    $fields = preg_split('/\s+/', trim($expr));
    if (count($fields) !== 5) return false;
    list($min, $hour, $dom, $mon, $dow) = $fields;
    return (
        gojs_cron_expand_field($min, 0, 59) !== false &&
        gojs_cron_expand_field($hour, 0, 23) !== false &&
        gojs_cron_expand_field($dom, 1, 31) !== false &&
        gojs_cron_expand_field($mon, 1, 12) !== false &&
        gojs_cron_expand_field($dow, 0, 6) !== false
    );
}

function gojs_validate_destination_ids($dest_ids): bool {
    if (!is_array($dest_ids)) return false;
    $dests = gojs_destinations_load();
    $dest_map = array();
    foreach ($dests as $d) {
        if (!empty($d['id'])) $dest_map[$d['id']] = true;
    }
    foreach ($dest_ids as $id) {
        if (!is_string($id) || !isset($dest_map[$id])) return false;
    }
    return true;
}

function gojs_api_backup_schedules_list() {
    $now = time();
    $schedules = gojs_schedules_load();
    $changed = false;

    foreach ($schedules as $i => $s) {
        if (empty($s['enabled'])) continue;
        $next_run_at = isset($s['next_run_at']) ? (int)$s['next_run_at'] : 0;
        if ($next_run_at === 0 || $next_run_at < $now) {
            $from = $next_run_at > 0 ? $next_run_at : $now;
            $schedules[$i]['next_run_at'] = gojs_cron_next_run(
                isset($s['cron_expr']) ? $s['cron_expr'] : '* * * * *',
                $from
            );
            $changed = true;
        }
    }

    if ($changed) {
        gojs_schedules_save($schedules);
    }

    gojs_json_response(array('schedules' => array_values($schedules)));
}

function gojs_api_backup_schedules_create() {
    $body = gojs_get_body();
    if (!is_array($body)) {
        gojs_json_response(null, array('code' => 'invalid_input', 'message' => '请求体无效'), 400);
    }

    $name = isset($body['name']) ? trim((string)$body['name']) : '';
    if ($name === '') {
        gojs_json_response(null, array('code' => 'invalid_input', 'message' => 'name 不能为空'), 400);
    }

    $cron_expr = isset($body['cron_expr']) ? trim((string)$body['cron_expr']) : '';
    if (!gojs_validate_cron_expr($cron_expr)) {
        gojs_json_response(null, array('code' => 'invalid_cron', 'message' => 'Cron 表达式无效'), 400);
    }

    $dest_ids = isset($body['destination_ids']) && is_array($body['destination_ids'])
        ? $body['destination_ids'] : array();
    if (!gojs_validate_destination_ids($dest_ids)) {
        gojs_json_response(null, array('code' => 'invalid_destination', 'message' => '目标 ID 列表包含不存在的目标'), 400);
    }

    $source = isset($body['source']) && is_array($body['source']) ? $body['source'] : array();
    $retention = isset($body['retention']) && is_array($body['retention']) ? $body['retention'] : array();

    $id = 'sch_' . substr(bin2hex(random_bytes(12)), 0, 16);
    $schedule = array(
        'id' => $id,
        'name' => $name,
        'enabled' => isset($body['enabled']) ? (bool)$body['enabled'] : true,
        'source' => array(
            'include_files' => isset($source['include_files']) ? (bool)$source['include_files'] : true,
            'include_db' => isset($source['include_db']) ? (bool)$source['include_db'] : true,
            'include_config' => isset($source['include_config']) ? (bool)$source['include_config'] : true,
            'exclude_dirs' => isset($source['exclude_dirs']) && is_array($source['exclude_dirs'])
                ? array_values($source['exclude_dirs'])
                : array('cache', 'node_modules', '.git', '.gojs'),
        ),
        'destination_ids' => array_values($dest_ids),
        'cron_expr' => $cron_expr,
        'retention' => array(
            'keep_last' => isset($retention['keep_last']) ? (int)$retention['keep_last'] : 30,
            'keep_daily' => isset($retention['keep_daily']) ? (int)$retention['keep_daily'] : 7,
            'keep_weekly' => isset($retention['keep_weekly']) ? (int)$retention['keep_weekly'] : 4,
            'keep_monthly' => isset($retention['keep_monthly']) ? (int)$retention['keep_monthly'] : 6,
        ),
        'next_run_at' => 0,
        'created_at' => time(),
    );

    if ($schedule['enabled']) {
        $schedule['next_run_at'] = gojs_cron_next_run($cron_expr, time());
    }

    $schedules = gojs_schedules_load();
    $schedules[] = $schedule;
    gojs_schedules_save($schedules);
    gojs_log_operation('backup_schedule_create', $name, true, $cron_expr);

    gojs_json_response(array('schedule' => $schedule));
}

function gojs_api_backup_schedules_update($id) {
    $body = gojs_get_body();
    if (!is_array($body)) {
        gojs_json_response(null, array('code' => 'invalid_input', 'message' => '请求体无效'), 400);
    }

    $schedules = gojs_schedules_load();
    $idx = null;
    foreach ($schedules as $i => $s) {
        if (isset($s['id']) && $s['id'] === $id) {
            $idx = $i;
            break;
        }
    }
    if ($idx === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '计划不存在'), 404);
    }

    $s = $schedules[$idx];
    $old_enabled = !empty($s['enabled']);
    $old_cron = isset($s['cron_expr']) ? $s['cron_expr'] : '';

    if (isset($body['name'])) {
        $name = trim((string)$body['name']);
        if ($name === '') {
            gojs_json_response(null, array('code' => 'invalid_input', 'message' => 'name 不能为空'), 400);
        }
        $s['name'] = $name;
    }

    $enabled_changed = false;
    if (isset($body['enabled'])) {
        $s['enabled'] = (bool)$body['enabled'];
        $enabled_changed = ($old_enabled !== $s['enabled']);
    }

    $cron_changed = false;
    if (isset($body['cron_expr'])) {
        $cron_expr = trim((string)$body['cron_expr']);
        if (!gojs_validate_cron_expr($cron_expr)) {
            gojs_json_response(null, array('code' => 'invalid_cron', 'message' => 'Cron 表达式无效'), 400);
        }
        $s['cron_expr'] = $cron_expr;
        $cron_changed = ($old_cron !== $cron_expr);
    }

    if (isset($body['destination_ids']) && is_array($body['destination_ids'])) {
        if (!gojs_validate_destination_ids($body['destination_ids'])) {
            gojs_json_response(null, array('code' => 'invalid_destination', 'message' => '目标 ID 列表包含不存在的目标'), 400);
        }
        $s['destination_ids'] = array_values($body['destination_ids']);
    }

    if (isset($body['source']) && is_array($body['source'])) {
        $source = $body['source'];
        $old_source = isset($s['source']) && is_array($s['source']) ? $s['source'] : array();
        $s['source'] = array(
            'include_files' => isset($source['include_files']) ? (bool)$source['include_files']
                : (isset($old_source['include_files']) ? (bool)$old_source['include_files'] : true),
            'include_db' => isset($source['include_db']) ? (bool)$source['include_db']
                : (isset($old_source['include_db']) ? (bool)$old_source['include_db'] : true),
            'include_config' => isset($source['include_config']) ? (bool)$source['include_config']
                : (isset($old_source['include_config']) ? (bool)$old_source['include_config'] : true),
            'exclude_dirs' => isset($source['exclude_dirs']) && is_array($source['exclude_dirs'])
                ? array_values($source['exclude_dirs'])
                : (isset($old_source['exclude_dirs']) ? $old_source['exclude_dirs']
                    : array('cache', 'node_modules', '.git', '.gojs')),
        );
    }

    if (isset($body['retention']) && is_array($body['retention'])) {
        $r = $body['retention'];
        $old_r = isset($s['retention']) && is_array($s['retention']) ? $s['retention'] : array();
        $s['retention'] = array(
            'keep_last' => isset($r['keep_last']) ? (int)$r['keep_last']
                : (isset($old_r['keep_last']) ? (int)$old_r['keep_last'] : 30),
            'keep_daily' => isset($r['keep_daily']) ? (int)$r['keep_daily']
                : (isset($old_r['keep_daily']) ? (int)$old_r['keep_daily'] : 7),
            'keep_weekly' => isset($r['keep_weekly']) ? (int)$r['keep_weekly']
                : (isset($old_r['keep_weekly']) ? (int)$old_r['keep_weekly'] : 4),
            'keep_monthly' => isset($r['keep_monthly']) ? (int)$r['keep_monthly']
                : (isset($old_r['keep_monthly']) ? (int)$old_r['keep_monthly'] : 6),
        );
    }

    if ($s['enabled'] && ($cron_changed || $enabled_changed || empty($s['next_run_at']))) {
        $s['next_run_at'] = gojs_cron_next_run(isset($s['cron_expr']) ? $s['cron_expr'] : '* * * * *', time());
    } elseif (!$s['enabled']) {
        $s['next_run_at'] = 0;
    }

    $schedules[$idx] = $s;
    gojs_schedules_save($schedules);
    gojs_log_operation('backup_schedule_update', $s['name'], true);

    gojs_json_response(array('schedule' => $s));
}

function gojs_api_backup_schedules_delete($id) {
    $schedules = gojs_schedules_load();
    $kept = array();
    $deleted = null;
    foreach ($schedules as $d) {
        if (isset($d['id']) && $d['id'] === $id) {
            $deleted = $d;
        } else {
            $kept[] = $d;
        }
    }
    if ($deleted === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '计划不存在'), 404);
    }

    gojs_schedules_save($kept);
    gojs_log_operation('backup_schedule_delete', $deleted['name'], true);
    gojs_json_response(array('success' => true));
}

function gojs_api_backup_schedules_run_now($id) {
    $schedules = gojs_schedules_load();
    $schedule = null;
    foreach ($schedules as $s) {
        if (isset($s['id']) && $s['id'] === $id) {
            $schedule = $s;
            break;
        }
    }
    if ($schedule === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '计划不存在'), 404);
    }

    $result = gojs_backup_execute_schedule($schedule);
    gojs_json_response(array('run_id' => $result['run_record_id'], 'ok' => $result['ok']));
}

/* ============================================================
   A.3 备份执行 + 推送目标 + 保留清理
   ============================================================ */

function gojs_backup_create_local($source) {
    $backup_dir = CONFIG_DIR . '/backups';
    if (!is_dir($backup_dir)) {
        if (!@mkdir($backup_dir, 0700, true)) {
            return array('ok' => false, 'error' => '无法创建备份目录');
        }
    }

    $include_files = isset($source['include_files']) ? (bool)$source['include_files'] : true;
    $include_db = isset($source['include_db']) ? (bool)$source['include_db'] : true;
    $include_config = isset($source['include_config']) ? (bool)$source['include_config'] : true;
    $exclude_dirs = isset($source['exclude_dirs']) && is_array($source['exclude_dirs'])
        ? $source['exclude_dirs']
        : array('cache', 'node_modules', '.git', '.gojs');

    if (!$include_files && !$include_db && !$include_config) {
        return array('ok' => false, 'error' => '未选择任何备份范围');
    }

    $timestamp = date('Ymd_His');
    $backup_name = "gojs-backup-{$timestamp}";
    $backup_file = $backup_dir . "/{$backup_name}.tar.gz";

    if (class_exists('PharData')) {
        try {
            @set_time_limit(0);
            $phar = new PharData($backup_file . '.tar');
            $metadata = array(
                'created_at' => date('Y-m-d H:i:s'),
                'version' => APP_VERSION,
                'files' => null,
                'databases' => array(),
                'config' => false,
            );

            $files_root = $GLOBALS['files_root'];

            if ($include_files && is_dir($files_root)) {
                $file_count = 0;
                $items = new RecursiveIteratorIterator(
                    new RecursiveCallbackFilterIterator(
                        new RecursiveDirectoryIterator($files_root, RecursiveDirectoryIterator::SKIP_DOTS),
                        function ($current, $key, $iterator) use ($files_root, $exclude_dirs) {
                            if ($current->isDir()) {
                                $name = $current->getFilename();
                                if (in_array($name, $exclude_dirs, true)) return false;
                            }
                            $path = $current->getPathname();
                            return !gojs_is_protected_path($path);
                        }
                    ),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($items as $file) {
                    if ($file->isFile() && $file->isReadable()) {
                        $rel = substr($file->getPathname(), strlen($files_root) + 1);
                        if ($rel === false || $rel === '') continue;
                        try {
                            $phar->addFile($file->getPathname(), 'files/' . $rel);
                            $file_count++;
                        } catch (Exception $e) {
                        }
                    }
                }
                $metadata['files'] = array('count' => $file_count, 'root' => basename($files_root));
            }

            if ($include_db) {
                $connections = gojs_load_db_connections();
                foreach ($connections as $conn) {
                    if (empty($conn['id'])) continue;
                    $conn_config = gojs_get_db_connection($conn['id']);
                    if (!$conn_config) continue;
                    try {
                        $sql_content = gojs_backup_export_db($conn_config);
                        if ($sql_content !== '') {
                            $safe_id = preg_replace('/[^A-Za-z0-9_-]/', '_', $conn['id']);
                            $phar->addFromString('database/' . $safe_id . '.sql', $sql_content);
                            $metadata['databases'][] = array(
                                'id' => $conn['id'],
                                'name' => isset($conn['name']) ? $conn['name'] : $conn['id'],
                                'database' => isset($conn_config['database']) ? $conn_config['database'] : '',
                                'size' => strlen($sql_content),
                            );
                        }
                    } catch (Exception $e) {
                        if (!isset($metadata['db_error'])) $metadata['db_error'] = array();
                        $metadata['db_error'][] = (isset($conn['name']) ? $conn['name'] : $conn['id']) . ': ' . $e->getMessage();
                    }
                }
            }

            if ($include_config && file_exists(CONFIG_FILE)) {
                $phar->addFile(CONFIG_FILE, 'config/config.php');
                $metadata['config'] = true;
            }

            $phar->addFromString('backup.json', json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $phar->compress(Phar::GZ);
            unset($phar);
            @unlink($backup_file . '.tar');

            $size = @filesize($backup_file);
            gojs_log_operation('backup_schedule_create_local', basename($backup_file), true);
            return array(
                'ok' => true,
                'path' => $backup_file,
                'filename' => basename($backup_file),
                'size' => $size,
                'metadata' => $metadata,
            );
        } catch (Exception $e) {
            @unlink($backup_file);
            @unlink($backup_file . '.tar');
            return array('ok' => false, 'error' => $e->getMessage());
        }
    } else {
        return gojs_backup_create_local_zip($backup_dir, $backup_name, $include_files, $include_db, $include_config, $exclude_dirs);
    }
}

function gojs_backup_create_local_zip($backup_dir, $backup_name, $include_files, $include_db, $include_config, $exclude_dirs) {
    $backup_file = $backup_dir . "/{$backup_name}.zip";
    if (!class_exists('ZipArchive')) {
        return array('ok' => false, 'error' => 'ZipArchive 和 PharData 均不可用');
    }
    $zip = new ZipArchive();
    if ($zip->open($backup_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return array('ok' => false, 'error' => '创建 zip 包失败');
    }
    @set_time_limit(0);

    $metadata = array(
        'created_at' => date('Y-m-d H:i:s'),
        'version' => APP_VERSION,
        'files' => null,
        'databases' => array(),
        'config' => false,
    );

    $files_root = $GLOBALS['files_root'];

    if ($include_files && is_dir($files_root)) {
        $file_count = gojs_backup_add_dir($zip, $files_root, 'files/', $exclude_dirs);
        $metadata['files'] = array('count' => $file_count, 'root' => basename($files_root));
    }

    if ($include_db) {
        $connections = gojs_load_db_connections();
        foreach ($connections as $conn) {
            if (empty($conn['id'])) continue;
            $conn_config = gojs_get_db_connection($conn['id']);
            if (!$conn_config) continue;
            try {
                $sql_content = gojs_backup_export_db($conn_config);
                if ($sql_content !== '') {
                    $safe_id = preg_replace('/[^A-Za-z0-9_-]/', '_', $conn['id']);
                    $zip->addFromString('database/' . $safe_id . '.sql', $sql_content);
                    $metadata['databases'][] = array(
                        'id' => $conn['id'],
                        'name' => isset($conn['name']) ? $conn['name'] : $conn['id'],
                        'database' => isset($conn_config['database']) ? $conn_config['database'] : '',
                        'size' => strlen($sql_content),
                    );
                }
            } catch (Exception $e) {
                if (!isset($metadata['db_error'])) $metadata['db_error'] = array();
                $metadata['db_error'][] = (isset($conn['name']) ? $conn['name'] : $conn['id']) . ': ' . $e->getMessage();
            }
        }
    }

    if ($include_config && file_exists(CONFIG_FILE)) {
        $zip->addFile(CONFIG_FILE, 'config/config.php');
        $metadata['config'] = true;
    }

    $zip->addFromString('backup.json', json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $zip->close();

    $size = @filesize($backup_file);
    gojs_log_operation('backup_schedule_create_local', basename($backup_file), true);
    return array(
        'ok' => true,
        'path' => $backup_file,
        'filename' => basename($backup_file),
        'size' => $size,
        'metadata' => $metadata,
    );
}

function gojs_parse_backup_created_ts($filename) {
    if (preg_match('/gojs-backup-(\d{8})_(\d{6})/', $filename, $m)) {
        $date = $m[1];
        $time = $m[2];
        $year = (int)substr($date, 0, 4);
        $mon = (int)substr($date, 4, 2);
        $day = (int)substr($date, 6, 2);
        $hour = (int)substr($time, 0, 2);
        $min = (int)substr($time, 2, 2);
        $sec = (int)substr($time, 4, 2);
        return gmmktime($hour, $min, $sec, $mon, $day, $year);
    }
    return file_exists($filename) ? @filemtime($filename) : time();
}

function gojs_retention_prune($dest, $rule) {
    if (!is_array($dest)) return 0;

    $path_prefix = isset($dest['path_prefix']) ? trim((string)$dest['path_prefix'], '/') : '';
    $list_prefix = $path_prefix !== '' ? $path_prefix . '/gojs-backup-' : 'gojs-backup-';

    try {
        $adapter = gojs_destination_factory($dest);
    } catch (Exception $e) {
        return 0;
    }

    if (!method_exists($adapter, 'listObjects') || !method_exists($adapter, 'deleteObject')) {
        return 0;
    }

    $list_result = $adapter->listObjects($list_prefix, 1000);
    $objects = array();
    if (is_array($list_result)) {
        // 优先纯数组（各适配器统一格式）；兼容旧式包装格式 {objects: [...]}
        if (isset($list_result['objects']) && is_array($list_result['objects']) && !isset($list_result[0])) {
            $objects = $list_result['objects'];
        } else {
            $objects = $list_result;
        }
    }

    $now = time();
    $min_age = 48 * 3600;
    $candidates = array();
    foreach ($objects as $o) {
        $key = is_array($o) ? (isset($o['key']) ? $o['key'] : '') : (string)$o;
        if ($key === '') continue;
        $created_ts = gojs_parse_backup_created_ts($key);
        $age = $now - $created_ts;
        $candidates[] = array('key' => $key, 'created' => $created_ts, 'age' => $age);
    }

    usort($candidates, function($a, $b) { return $b['created'] - $a['created']; });

    $keep_last = isset($rule['keep_last']) ? (int)$rule['keep_last'] : 0;
    $keep_daily = isset($rule['keep_daily']) ? (int)$rule['keep_daily'] : 0;
    $keep_weekly = isset($rule['keep_weekly']) ? (int)$rule['keep_weekly'] : 0;
    $keep_monthly = isset($rule['keep_monthly']) ? (int)$rule['keep_monthly'] : 0;

    $kept = array();
    $remaining = $candidates;

    if ($keep_last > 0) {
        $to_keep = array_slice($remaining, 0, $keep_last);
        foreach ($to_keep as $c) {
            $kept[$c['key']] = true;
        }
        $remaining = array_slice($remaining, $keep_last);
    }

    if ($keep_daily > 0) {
        $daily_buckets = array();
        foreach ($remaining as $c) {
            $bucket = gmdate('Y-m-d', $c['created']);
            if (!isset($daily_buckets[$bucket])) $daily_buckets[$bucket] = array();
            $daily_buckets[$bucket][] = $c;
        }
        krsort($daily_buckets);
        $buckets_taken = 0;
        foreach ($daily_buckets as $bucket => $list) {
            if ($buckets_taken >= $keep_daily) break;
            if (count($list) > 0) {
                $kept[$list[0]['key']] = true;
                $buckets_taken++;
            }
        }
        $temp = array();
        foreach ($remaining as $c) {
            if (!isset($kept[$c['key']])) $temp[] = $c;
        }
        $remaining = $temp;
    }

    if ($keep_weekly > 0) {
        $weekly_buckets = array();
        foreach ($remaining as $c) {
            $week = gmdate('o-W', $c['created']);
            if (!isset($weekly_buckets[$week])) $weekly_buckets[$week] = array();
            $weekly_buckets[$week][] = $c;
        }
        krsort($weekly_buckets);
        $buckets_taken = 0;
        foreach ($weekly_buckets as $bucket => $list) {
            if ($buckets_taken >= $keep_weekly) break;
            if (count($list) > 0) {
                $kept[$list[0]['key']] = true;
                $buckets_taken++;
            }
        }
        $temp = array();
        foreach ($remaining as $c) {
            if (!isset($kept[$c['key']])) $temp[] = $c;
        }
        $remaining = $temp;
    }

    if ($keep_monthly > 0) {
        $monthly_buckets = array();
        foreach ($remaining as $c) {
            $bucket = gmdate('Y-m', $c['created']);
            if (!isset($monthly_buckets[$bucket])) $monthly_buckets[$bucket] = array();
            $monthly_buckets[$bucket][] = $c;
        }
        krsort($monthly_buckets);
        $buckets_taken = 0;
        foreach ($monthly_buckets as $bucket => $list) {
            if ($buckets_taken >= $keep_monthly) break;
            if (count($list) > 0) {
                $kept[$list[0]['key']] = true;
                $buckets_taken++;
            }
        }
    }

    $deleted = 0;
    foreach ($candidates as $c) {
        if (isset($kept[$c['key']])) continue;
        if ($c['age'] < $min_age) continue;
        try {
            $adapter->deleteObject($c['key']);
            $deleted++;
        } catch (Exception $e) {
        }
    }

    return $deleted;
}

function gojs_backup_execute_schedule($schedule) {
    $run_id = 'run_' . substr(bin2hex(random_bytes(12)), 0, 16);
    $started_at = time();
    $now = $started_at;

    $runs = gojs_backup_runs_load();
    $runs[] = array(
        'id' => $run_id,
        'schedule_id' => isset($schedule['id']) ? $schedule['id'] : '',
        'started_at' => $started_at,
        'ended_at' => null,
        'status' => 'running',
        'bytes_total' => 0,
        'destination_results' => array(),
        'pruned_count' => 0,
    );
    gojs_backup_runs_save($runs);

    $source = isset($schedule['source']) && is_array($schedule['source']) ? $schedule['source'] : array();
    $create_result = gojs_backup_create_local($source);

    $bytes_total = 0;
    $dest_results = array();
    $pruned_count = 0;
    $final_status = 'success';

    if (!$create_result['ok']) {
        $final_status = 'failed';
        if (function_exists('gojs_notifications_append')) {
            gojs_notifications_append(array(
                'id' => 'n_' . substr(bin2hex(random_bytes(8)), 0, 16),
                'category' => 'backup',
                'severity' => 'warning',
                'title_key' => 'backup_create_local_failed',
                'body_key' => 'backup_schedule_name',
                'body_params' => array('name' => isset($schedule['name']) ? $schedule['name'] : ''),
                'payload' => array('schedule_id' => isset($schedule['id']) ? $schedule['id'] : '', 'error' => $create_result['error']),
                'created_at' => time(),
            ));
        }
    } else {
        $local_path = $create_result['path'];
        $filename = $create_result['filename'];
        $bytes_total = isset($create_result['size']) ? (int)$create_result['size'] : 0;

        $dest_ids = isset($schedule['destination_ids']) && is_array($schedule['destination_ids'])
            ? $schedule['destination_ids']
            : array();
        $dests = gojs_destinations_load();
        $dest_map = array();
        foreach ($dests as $d) {
            if (!empty($d['id'])) $dest_map[$d['id']] = $d;
        }

        foreach ($dest_ids as $dest_id) {
            if (!isset($dest_map[$dest_id])) {
                $dest_results[] = array('dest_id' => $dest_id, 'ok' => false, 'error' => 'destination not found');
                $final_status = 'failed';
                continue;
            }
            $dest = $dest_map[$dest_id];
            $path_prefix = isset($dest['path_prefix']) ? trim((string)$dest['path_prefix'], '/') : '';
            $remote_key = $path_prefix !== '' ? $path_prefix . '/' . $filename : $filename;

            try {
                $adapter = gojs_destination_factory($dest);
                if (!method_exists($adapter, 'putObject')) {
                    $dest_results[] = array('dest_id' => $dest_id, 'ok' => false, 'error' => 'putObject not supported');
                    $final_status = 'failed';
                    continue;
                }
                $body = @file_get_contents($local_path);
                if ($body === false) {
                    $dest_results[] = array('dest_id' => $dest_id, 'ok' => false, 'error' => '无法读取本地备份文件');
                    $final_status = 'failed';
                    continue;
                }
                $push = $adapter->putObject($remote_key, $body);
                $push_ok = !empty($push['ok']);
                $dest_results[] = array(
                    'dest_id' => $dest_id,
                    'ok' => $push_ok,
                    'remote_path' => $push_ok ? $remote_key : null,
                    'error' => $push_ok ? null : (isset($push['error']) ? $push['error'] : 'unknown error'),
                );
                if (!$push_ok) $final_status = 'failed';
                unset($body);
            } catch (Exception $e) {
                $dest_results[] = array('dest_id' => $dest_id, 'ok' => false, 'error' => $e->getMessage());
                $final_status = 'failed';
            }
        }

        if ($final_status === 'success') {
            $retention = isset($schedule['retention']) && is_array($schedule['retention']) ? $schedule['retention'] : array();
            foreach ($dest_ids as $dest_id) {
                if (isset($dest_map[$dest_id])) {
                    $dest = $dest_map[$dest_id];
                    try {
                        $pruned_count += gojs_retention_prune($dest, $retention);
                    } catch (Exception $e) {
                    }
                }
            }
        }

        $keep_local = true;
        if (isset($source['keep_local']) && $source['keep_local'] === false) {
            $keep_local = false;
        }
        if (!$keep_local) {
            @unlink($local_path);
        }
    }

    $ended_at = time();
    $runs = gojs_backup_runs_load();
    foreach ($runs as $i => $r) {
        if (isset($r['id']) && $r['id'] === $run_id) {
            $runs[$i]['ended_at'] = $ended_at;
            $runs[$i]['status'] = $final_status;
            $runs[$i]['bytes_total'] = $bytes_total;
            $runs[$i]['destination_results'] = $dest_results;
            $runs[$i]['pruned_count'] = $pruned_count;
            break;
        }
    }
    gojs_backup_runs_save($runs);

    if (function_exists('gojs_notifications_append')) {
        gojs_notifications_append(array(
            'id' => 'n_' . substr(bin2hex(random_bytes(8)), 0, 16),
            'category' => 'backup',
            'severity' => $final_status === 'success' ? 'success' : 'critical',
            'title_key' => $final_status === 'success' ? 'backup_run_success' : 'backup_run_failed',
            'body_key' => 'backup_schedule_name',
            'body_params' => array('name' => isset($schedule['name']) ? $schedule['name'] : ''),
            'payload' => array('schedule_id' => isset($schedule['id']) ? $schedule['id'] : '', 'run_id' => $run_id),
            'created_at' => time(),
        ));
    }

    return array(
        'run_record_id' => $run_id,
        'ok' => $final_status === 'success',
        'bytes_total' => $bytes_total,
        'dest_results' => $dest_results,
        'pruned_count' => $pruned_count,
    );
}

/* ============================================================
   A.4 InternalCron tick engine
   ============================================================ */

function gojs_internal_cron_tick(): array {
    $now = time();
    $processed_schedules = 0;
    $processed_runs = 0;

    $schedules = gojs_schedules_load();
    $changed = false;

    foreach ($schedules as $i => $s) {
        if (empty($s['enabled'])) continue;
        $next_run_at = isset($s['next_run_at']) ? (int)$s['next_run_at'] : 0;
        if ($next_run_at > 0 && $next_run_at <= $now) {
            gojs_backup_execute_schedule($s);
            $schedules[$i]['last_run_at'] = $now;
            $schedules[$i]['next_run_at'] = gojs_cron_next_run(
                isset($s['cron_expr']) ? $s['cron_expr'] : '* * * * *',
                $now
            );
            $changed = true;
            $processed_schedules++;
        }
    }

    if ($changed) {
        gojs_schedules_save($schedules);
    }

    $runs = gojs_backup_runs_load();
    $processed_runs = count($runs);

    $drained_outbox = 0;
    if (function_exists('gojs_channels_deliver_all')) {
        $stats = gojs_channels_deliver_all();
        $drained_outbox = isset($stats['delivered']) ? (int)$stats['delivered']
            : (isset($stats['drained']) ? (int)$stats['drained'] : 0);
    }

    $stats = array(
        'processed_schedules' => $processed_schedules,
        'processed_runs' => $processed_runs,
        'drained_outbox' => $drained_outbox,
        'tick_at' => $now,
    );

    if (function_exists('gojs_internal_cron_acme_renew_hook')) {
        gojs_internal_cron_acme_renew_hook($stats);
    }

    // 每次 tick 追加一条历史记录（webcron.php 与 admin 手动触发共用此函数，天然去重）
    $history = gojs_webcron_history_load();
    $history[] = array(
        'id' => 'w_' . uniqid('', true),
        'tick_at' => $now,
        'status' => 'ok',
        'stats' => $stats,
    );
    gojs_webcron_history_save($history);

    return $stats;
}

function gojs_webcron_history_load(): array {
    return gojs_read_json_lock_safe(CONFIG_DIR . '/webcron_history.json', array());
}

function gojs_webcron_history_save(array $history): void {
    global $config;
    $cap = isset($config['webcron_history_cap']) ? (int)$config['webcron_history_cap'] : 100;
    if ($cap > 0 && count($history) > $cap) {
        $history = array_slice($history, -$cap);
    }
    gojs_write_json_lock_safe(CONFIG_DIR . '/webcron_history.json', $history, true);
}

function gojs_api_webcron_status() {
    global $config;
    $token = isset($config['internal_cron_token']) ? (string)$config['internal_cron_token'] : '';
    $token_set = ($token !== '');

    // webcron 触发地址（token 掩码形式返回，前端仅展示用）
    $mask = '';
    $token_len = strlen($token);
    if ($token_len > 0) {
        if ($token_len <= 8) {
            $mask = $token[0] . str_repeat('*', max(0, $token_len - 1));
        } else {
            $mask = substr($token, 0, 4) . str_repeat('*', max(0, $token_len - 8)) . substr($token, -4);
        }
    }
    $webcron_url = $token !== '' ? 'webcron.php?token=' . urlencode($mask) : '';

    // 历史（倒序，第一条即最近一次触发）
    $history = gojs_webcron_history_load();
    usort($history, function($a, $b) {
        $ta = isset($a['tick_at']) ? (int)$a['tick_at'] : 0;
        $tb = isset($b['tick_at']) ? (int)$b['tick_at'] : 0;
        return $tb - $ta;
    });
    $last_triggered_at = null;
    if (isset($history[0]['tick_at'])) {
        $last_triggered_at = (int)$history[0]['tick_at'];
    }

    // 下次备份调度时间（enabled 且 next_run_at>0 的最小值）
    $next_backup_run_at = null;
    $schedules = gojs_schedules_load();
    foreach ($schedules as $s) {
        if (!is_array($s) || empty($s['enabled'])) continue;
        $n = isset($s['next_run_at']) ? (int)$s['next_run_at'] : 0;
        if ($n > 0 && ($next_backup_run_at === null || $n < $next_backup_run_at)) {
            $next_backup_run_at = $n;
        }
    }

    $cap = isset($config['webcron_history_cap']) ? (int)$config['webcron_history_cap'] : 100;

    gojs_json_response(array(
        'token_set' => $token_set,
        'webcron_url' => $webcron_url,
        'last_triggered_at' => $last_triggered_at,
        'next_backup_run_at' => $next_backup_run_at,
        'cap' => $cap,
        'history' => array_slice($history, 0, 20),
    ));
}

function gojs_api_internal_cron_tick() {
    global $config;
    $provided_token = gojs_get_param('internal_cron_token', '');
    if ($provided_token === '') {
        $headers = function_exists('getallheaders') ? getallheaders() : array();
        if (!$headers) $headers = array();
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'x-internal-cron-token') {
                $provided_token = $v;
                break;
            }
        }
    }
    $valid_token = isset($config['internal_cron_token']) ? $config['internal_cron_token'] : '';
    $admin_allowed = !empty($_SESSION['authenticated']);
    if (!$admin_allowed && ($provided_token === '' || $valid_token === '' || !hash_equals($valid_token, $provided_token))) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '需要 internal_cron_token 或管理员登录',
        ), 403);
    }
    $stats = gojs_internal_cron_tick();
    gojs_json_response($stats);
}

function gojs_api_internal_cron_regenerate_token() {
    global $config;
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $token = '';
    for ($i = 0; $i < 32; $i++) {
        $token .= $chars[mt_rand(0, strlen($chars) - 1)];
    }
    $config['internal_cron_token'] = $token;
    gojs_save_config();
    gojs_json_response(array('token' => $token));
}

/* ============================================================
   A.5 Run log endpoints
   ============================================================ */

function gojs_api_backup_runs_list() {
    $schedule_id = gojs_get_param('schedule_id', '');
    $limit = (int)gojs_get_param('limit', 50);
    $offset = (int)gojs_get_param('offset', 0);

    if ($limit < 1) $limit = 1;
    if ($limit > 500) $limit = 500;
    if ($offset < 0) $offset = 0;

    $runs = gojs_backup_runs_load();
    usort($runs, function($a, $b) {
        $at_a = isset($a['started_at']) ? (int)$a['started_at'] : 0;
        $at_b = isset($b['started_at']) ? (int)$b['started_at'] : 0;
        return $at_b - $at_a;
    });

    if ($schedule_id !== '') {
        $runs = array_values(array_filter($runs, function($r) use ($schedule_id) {
            return isset($r['schedule_id']) && $r['schedule_id'] === $schedule_id;
        }));
    }

    $total = count($runs);
    $paged = array_slice($runs, $offset, $limit);

    gojs_json_response(array(
        'runs' => $paged,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
    ));
}

function gojs_api_backup_runs_get($id) {
    $runs = gojs_backup_runs_load();
    foreach ($runs as $r) {
        if (isset($r['id']) && $r['id'] === $id) {
            gojs_json_response(array('run' => $r));
        }
    }
    gojs_json_response(null, array('code' => 'not_found', 'message' => '运行记录不存在'), 404);
}

function gojs_ftp_capabilities(): array {
    global $config, $root_path;

    $supported = array('proftpd_authfile', 'pureftpd_passwd');
    $active_provider = null;
    $detected_path = null;
    $can_write = true;

    $provider_override = isset($config['ftp']['provider']) ? $config['ftp']['provider'] : '';
    $path_override = isset($config['ftp']['path']) ? $config['ftp']['path'] : '';

    if ($provider_override && $path_override) {
        $active_provider = $provider_override;
        $detected_path = $path_override;
        $can_write = is_writable($detected_path) || (!file_exists($detected_path) && is_writable(dirname($detected_path)));
    } else {
        $docroot_parent = rtrim($root_path, '/\\') . DIRECTORY_SEPARATOR . '..';
        $proftpd_candidates = array(
            rtrim($docroot_parent, '/\\') . DIRECTORY_SEPARATOR . '.ftp_passwd',
            rtrim($docroot_parent, '/\\') . DIRECTORY_SEPARATOR . 'proftpd.passwd',
            CONFIG_DIR . DIRECTORY_SEPARATOR . 'ftpd.passwd',
        );
        foreach ($proftpd_candidates as $cand) {
            if (file_exists($cand) && is_readable($cand)) {
                $active_provider = 'proftpd_authfile';
                $detected_path = $cand;
                $can_write = is_writable($cand);
                break;
            }
        }

        if ($active_provider === null) {
            $pureftpd_candidates = array(
                CONFIG_DIR . DIRECTORY_SEPARATOR . 'pureftpd.passwd',
                rtrim($docroot_parent, '/\\') . DIRECTORY_SEPARATOR . 'pureftpd.passwd',
            );
            foreach ($pureftpd_candidates as $cand) {
                if (file_exists($cand) && is_readable($cand)) {
                    $active_provider = 'pureftpd_passwd';
                    $detected_path = $cand;
                    $can_write = is_writable($cand);
                    break;
                }
            }
        }

        if ($active_provider === null) {
            $can_write = true;
        }
    }

    $posix_available = function_exists('posix_getuid') && function_exists('posix_getgid');
    $default_uid = $posix_available ? posix_getuid() : 1000;
    $default_gid = $posix_available ? posix_getgid() : 1000;

    // 降级原因矩阵：面板功能始终可用（available 保持 true），
    // 但存在限制时通过 degraded + reasons 向前端提示，前端不白屏。
    $reasons = array();
    if (!$posix_available) {
        $reasons[] = array('code' => 'posix_unavailable', 'key' => 'ftp.degradePosix', 'severity' => 'warning');
    }
    if ($active_provider === null) {
        $reasons[] = array('code' => 'provider_not_found', 'key' => 'ftp.degradeNoProvider', 'severity' => 'warning');
    } elseif (!$can_write) {
        $reasons[] = array('code' => 'provider_not_writable', 'key' => 'ftp.degradeNotWritable', 'severity' => 'danger');
    }
    // 账户同步仅写本地 passwd 文件，不依赖 ftp 扩展；仅作提示（info）
    if (!extension_loaded('ftp') && !function_exists('fsockopen')) {
        $reasons[] = array('code' => 'ftp_ext_unavailable', 'key' => 'ftp.degradeFtpExt', 'severity' => 'info');
    }

    // 兼容旧前端：degradation_reasons 保留（key=code, message_key=key）
    $degradation_reasons = array();
    foreach ($reasons as $r) {
        $degradation_reasons[] = array('key' => $r['code'], 'message_key' => $r['key']);
    }

    return array(
        'available' => true,
        'degraded' => count($reasons) > 0,
        'reasons' => $reasons,
        'degradation_reasons' => $degradation_reasons,
        'posix_available' => $posix_available,
        'supported_providers' => $supported,
        'active_provider' => $active_provider,
        'path' => $detected_path,
        'can_write' => $can_write,
        'default_uid' => $default_uid,
        'default_gid' => $default_gid,
    );
}

function gojs_api_ftp_capabilities() {
    gojs_json_response(gojs_ftp_capabilities());
}

function gojs_ftp_accounts_load(): array {
    global $config;
    if (!isset($config['ftp_accounts']) || !is_array($config['ftp_accounts'])) {
        return array();
    }
    return $config['ftp_accounts'];
}

function gojs_ftp_accounts_save(array $accounts): void {
    global $config;
    $config['ftp_accounts'] = array_values($accounts);
    gojs_save_config();
}

function gojs_ftp_account_redact(array $account): array {
    $redacted = $account;
    unset($redacted['password_hash_enc']);
    $redacted['password'] = null;
    return $redacted;
}

function gojs_ftp_validate_username(string $username): bool {
    return (bool)preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username);
}

function gojs_ftp_validate_home_dir(string $home_dir): bool {
    if ($home_dir === '' || $home_dir === null) {
        return false;
    }
    if (strpos($home_dir, '..') !== false) {
        return false;
    }
    return true;
}

function gojs_api_ftp_accounts_list() {
    $accounts = gojs_ftp_accounts_load();
    $redacted = array_map('gojs_ftp_account_redact', $accounts);
    gojs_json_response(array('accounts' => $redacted));
}

function gojs_ftp_hash_password(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, array('cost' => 10));
}

function gojs_ftp_gen_id(): string {
    return 'ftp_' . bin2hex(random_bytes(8));
}

function gojs_api_ftp_accounts_create() {
    $username = gojs_get_param('username', '');
    $password = gojs_get_param('password', '');
    $home_dir = gojs_get_param('home_dir', '');
    $uid = gojs_get_param('uid', null);
    $gid = gojs_get_param('gid', null);
    $quota_size_mb = gojs_get_param('quota_size_mb', null);
    $quota_files = gojs_get_param('quota_files', null);
    $upload_bw_kbps = gojs_get_param('upload_bw_kbps', null);
    $download_bw_kbps = gojs_get_param('download_bw_kbps', null);
    $allow_client_ips = gojs_get_param('allow_client_ips', '');
    $deny_client_ips = gojs_get_param('deny_client_ips', '');
    $enabled = gojs_get_param('enabled', true);
    $expires_at_ts = gojs_get_param('expires_at_ts', null);

    $username = trim($username);
    $home_dir = trim($home_dir);

    if (!gojs_ftp_validate_username($username)) {
        gojs_json_response(null, array(
            'code' => 'invalid_username',
            'message' => '用户名格式无效，仅允许3-32位字母数字下划线',
        ), 400);
    }
    if (strlen($password) < 8) {
        gojs_json_response(null, array(
            'code' => 'weak_password',
            'message' => '密码至少需要8个字符',
        ), 400);
    }
    if (!gojs_ftp_validate_home_dir($home_dir)) {
        gojs_json_response(null, array(
            'code' => 'invalid_home_dir',
            'message' => '家目录无效',
        ), 400);
    }

    $accounts = gojs_ftp_accounts_load();
    foreach ($accounts as $acc) {
        if ($acc['username'] === $username) {
            gojs_json_response(null, array(
                'code' => 'username_exists',
                'message' => '该用户名已存在',
            ), 409);
        }
    }

    $caps = gojs_ftp_capabilities();
    $now = time();

    $account = array(
        'id' => gojs_ftp_gen_id(),
        'username' => $username,
        'password_hash_enc' => gojs_ftp_hash_password($password),
        'home_dir' => $home_dir,
        'uid' => $uid !== null ? (int)$uid : (isset($caps['default_uid']) ? (int)$caps['default_uid'] : 1000),
        'gid' => $gid !== null ? (int)$gid : (isset($caps['default_gid']) ? (int)$caps['default_gid'] : 1000),
        'quota_size_mb' => $quota_size_mb !== null ? ((int)$quota_size_mb > 0 ? (int)$quota_size_mb : null) : null,
        'quota_files' => $quota_files !== null ? ((int)$quota_files > 0 ? (int)$quota_files : null) : null,
        'upload_bw_kbps' => $upload_bw_kbps !== null ? ((int)$upload_bw_kbps > 0 ? (int)$upload_bw_kbps : null) : null,
        'download_bw_kbps' => $download_bw_kbps !== null ? ((int)$download_bw_kbps > 0 ? (int)$download_bw_kbps : null) : null,
        'allow_client_ips' => is_string($allow_client_ips) ? trim($allow_client_ips) : '',
        'deny_client_ips' => is_string($deny_client_ips) ? trim($deny_client_ips) : '',
        'enabled' => (bool)$enabled,
        'expires_at_ts' => $expires_at_ts !== null ? ((int)$expires_at_ts > 0 ? (int)$expires_at_ts : null) : null,
        'created_at' => $now,
        'last_changed_at' => $now,
        'last_login_at' => null,
    );

    $accounts[] = $account;
    gojs_ftp_accounts_save($accounts);

    if ($caps['active_provider'] && $caps['can_write'] && $caps['path']) {
        gojs_ftp_sync_to_provider($accounts, $caps);
    }

    gojs_json_response(array('account' => gojs_ftp_account_redact($account)));
}

function gojs_api_ftp_accounts_update($id) {
    $accounts = gojs_ftp_accounts_load();
    $index = null;
    foreach ($accounts as $i => $acc) {
        if ($acc['id'] === $id) {
            $index = $i;
            break;
        }
    }
    if ($index === null) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '账户不存在',
        ), 404);
    }

    $account = $accounts[$index];

    $username = gojs_get_param('username', null);
    $password = gojs_get_param('password', null);
    $password_renew = gojs_get_param('password_renew', null);
    $home_dir = gojs_get_param('home_dir', null);
    $uid = gojs_get_param('uid', null);
    $gid = gojs_get_param('gid', null);
    $quota_size_mb = gojs_get_param('quota_size_mb', null);
    $quota_files = gojs_get_param('quota_files', null);
    $upload_bw_kbps = gojs_get_param('upload_bw_kbps', null);
    $download_bw_kbps = gojs_get_param('download_bw_kbps', null);
    $allow_client_ips = gojs_get_param('allow_client_ips', null);
    $deny_client_ips = gojs_get_param('deny_client_ips', null);
    $enabled = gojs_get_param('enabled', null);
    $expires_at_ts = gojs_get_param('expires_at_ts', null);

    if ($username !== null) {
        $username = trim($username);
        if (!gojs_ftp_validate_username($username)) {
            gojs_json_response(null, array(
                'code' => 'invalid_username',
                'message' => '用户名格式无效',
            ), 400);
        }
        foreach ($accounts as $i => $acc) {
            if ($i !== $index && $acc['username'] === $username) {
                gojs_json_response(null, array(
                    'code' => 'username_exists',
                    'message' => '该用户名已存在',
                ), 409);
            }
        }
        $account['username'] = $username;
    }

    if ($password !== null && $password !== '') {
        if (strlen($password) < 8) {
            gojs_json_response(null, array(
                'code' => 'weak_password',
                'message' => '密码至少需要8个字符',
            ), 400);
        }
        $account['password_hash_enc'] = gojs_ftp_hash_password($password);
    } elseif ($password_renew !== null && $password_renew !== '') {
        if (strlen($password_renew) < 8) {
            gojs_json_response(null, array(
                'code' => 'weak_password',
                'message' => '密码至少需要8个字符',
            ), 400);
        }
        $account['password_hash_enc'] = gojs_ftp_hash_password($password_renew);
    }

    if ($home_dir !== null) {
        $home_dir = trim($home_dir);
        if (!gojs_ftp_validate_home_dir($home_dir)) {
            gojs_json_response(null, array(
                'code' => 'invalid_home_dir',
                'message' => '家目录无效',
            ), 400);
        }
        $account['home_dir'] = $home_dir;
    }

    if ($uid !== null) $account['uid'] = (int)$uid;
    if ($gid !== null) $account['gid'] = (int)$gid;
    if (isset($quota_size_mb)) $account['quota_size_mb'] = ($quota_size_mb === null || (int)$quota_size_mb <= 0) ? null : (int)$quota_size_mb;
    if (isset($quota_files)) $account['quota_files'] = ($quota_files === null || (int)$quota_files <= 0) ? null : (int)$quota_files;
    if (isset($upload_bw_kbps)) $account['upload_bw_kbps'] = ($upload_bw_kbps === null || (int)$upload_bw_kbps <= 0) ? null : (int)$upload_bw_kbps;
    if (isset($download_bw_kbps)) $account['download_bw_kbps'] = ($download_bw_kbps === null || (int)$download_bw_kbps <= 0) ? null : (int)$download_bw_kbps;
    if ($allow_client_ips !== null) $account['allow_client_ips'] = trim($allow_client_ips);
    if ($deny_client_ips !== null) $account['deny_client_ips'] = trim($deny_client_ips);
    if ($enabled !== null) $account['enabled'] = (bool)$enabled;
    if (isset($expires_at_ts)) $account['expires_at_ts'] = ($expires_at_ts === null || (int)$expires_at_ts <= 0) ? null : (int)$expires_at_ts;

    $account['last_changed_at'] = time();

    $accounts[$index] = $account;
    gojs_ftp_accounts_save($accounts);

    $caps = gojs_ftp_capabilities();
    if ($caps['active_provider'] && $caps['can_write'] && $caps['path']) {
        gojs_ftp_sync_to_provider($accounts, $caps);
    }

    gojs_json_response(array('account' => gojs_ftp_account_redact($account)));
}

function gojs_api_ftp_accounts_delete($id) {
    $accounts = gojs_ftp_accounts_load();
    $found = false;
    $accounts = array_values(array_filter($accounts, function($acc) use ($id, &$found) {
        if ($acc['id'] === $id) {
            $found = true;
            return false;
        }
        return true;
    }));

    if (!$found) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '账户不存在',
        ), 404);
    }

    gojs_ftp_accounts_save($accounts);

    $caps = gojs_ftp_capabilities();
    if ($caps['active_provider'] && $caps['can_write'] && $caps['path']) {
        gojs_ftp_sync_to_provider($accounts, $caps);
    }

    gojs_json_response(array('ok' => true));
}

function gojs_api_ftp_accounts_test_login($id) {
    $accounts = gojs_ftp_accounts_load();
    $account = null;
    foreach ($accounts as $acc) {
        if ($acc['id'] === $id) {
            $account = $acc;
            break;
        }
    }
    if ($account === null) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '账户不存在',
        ), 404);
    }

    $password = gojs_get_param('password', '');
    if ($password === '') {
        gojs_json_response(null, array(
            'code' => 'password_required',
            'message' => '需要密码',
        ), 400);
    }

    $hash = isset($account['password_hash_enc']) ? $account['password_hash_enc'] : '';
    $ok = $hash !== '' && password_verify($password, $hash);

    if ($ok) {
        $accounts_copy = $accounts;
        foreach ($accounts_copy as $i => $acc) {
            if ($acc['id'] === $id) {
                $accounts_copy[$i]['last_login_at'] = time();
                break;
            }
        }
        gojs_ftp_accounts_save($accounts_copy);
    }

    gojs_json_response(array('ok' => $ok));
}

function gojs_ftp_proftpd_write(array $accounts, string $path): array {
    $lines = array();
    foreach ($accounts as $acc) {
        if (empty($acc['enabled'])) continue;
        $hash = isset($acc['password_hash_enc']) ? $acc['password_hash_enc'] : '';
        $uid = isset($acc['uid']) ? (int)$acc['uid'] : 1000;
        $gid = isset($acc['gid']) ? (int)$acc['gid'] : 1000;
        $gecos = $acc['username'];
        $homedir = $acc['home_dir'];
        $shell = '/bin/false';
        $lines[] = $acc['username'] . ':' . $hash . ':' . $uid . ':' . $gid . ':' . $gecos . ':' . $homedir . ':' . $shell;
    }
    $content = implode("\n", $lines) . "\n";
    $written = @file_put_contents($path, $content, LOCK_EX);
    if ($written === false) {
        $err = error_get_last();
        return array('ok' => false, 'error' => isset($err['message']) ? $err['message'] : '写入失败');
    }
    @chmod($path, 0640);
    return array('ok' => true);
}

function gojs_ftp_pureftpd_write(array $accounts, string $path): array {
    $lines = array();
    foreach ($accounts as $acc) {
        if (empty($acc['enabled'])) continue;
        $hash = isset($acc['password_hash_enc']) ? $acc['password_hash_enc'] : '';
        $uid = isset($acc['uid']) ? (int)$acc['uid'] : 1000;
        $gid = isset($acc['gid']) ? (int)$acc['gid'] : 1000;
        $gecos = $acc['username'];
        $homedir = $acc['home_dir'];
        $ul_bw = isset($acc['upload_bw_kbps']) ? (int)$acc['upload_bw_kbps'] : 0;
        $dl_bw = isset($acc['download_bw_kbps']) ? (int)$acc['download_bw_kbps'] : 0;
        $ul_ratio = 0;
        $dl_ratio = 0;
        $q_files = isset($acc['quota_files']) ? (int)$acc['quota_files'] : 0;
        $q_size_mb = isset($acc['quota_size_mb']) ? (int)$acc['quota_size_mb'] : 0;
        $q_size = $q_size_mb * 1024;
        $time_offset = 0;
        $allow_ip = isset($acc['allow_client_ips']) ? $acc['allow_client_ips'] : '';
        $deny_ip = isset($acc['deny_client_ips']) ? $acc['deny_client_ips'] : '';
        $allow_local = '';
        $deny_local = '';
        $max_concurrent = 0;
        $max_per_ip = 0;
        $min_uid = $uid;
        $allowed_chmod = '';
        $deleted_flag = 0;
        $expire = isset($acc['expires_at_ts']) ? (int)$acc['expires_at_ts'] : 0;

        $fields = array(
            $acc['username'],
            $hash,
            $uid,
            $gid,
            $gecos,
            '/',
            $homedir,
            $ul_bw,
            $dl_bw,
            $ul_ratio,
            $dl_ratio,
            $q_files,
            $q_size,
            $time_offset,
            $allow_ip,
            $deny_ip,
            $allow_local,
            $deny_local,
            $max_concurrent,
            $max_per_ip,
            $min_uid,
            $allowed_chmod,
            $deleted_flag,
            $expire,
        );
        $lines[] = implode(':', $fields);
    }
    $content = implode("\n", $lines) . "\n";
    $written = @file_put_contents($path, $content, LOCK_EX);
    if ($written === false) {
        $err = error_get_last();
        return array('ok' => false, 'error' => isset($err['message']) ? $err['message'] : '写入失败');
    }
    @chmod($path, 0640);
    return array('ok' => true);
}

function gojs_ftp_sync_to_provider(array $accounts, array $caps): array {
    $provider = $caps['active_provider'];
    $path = $caps['path'];
    if ($provider === 'proftpd_authfile') {
        return gojs_ftp_proftpd_write($accounts, $path);
    } elseif ($provider === 'pureftpd_passwd') {
        return gojs_ftp_pureftpd_write($accounts, $path);
    }
    return array('ok' => false, 'error' => '未知的 provider');
}

function gojs_api_ftp_sync() {
    $caps = gojs_ftp_capabilities();
    if (!$caps['active_provider']) {
        gojs_json_response(null, array(
            'code' => 'no_provider',
            'message' => '未检测到系统 FTP provider，无法同步',
        ), 400);
    }
    if (!$caps['can_write'] || !$caps['path']) {
        gojs_json_response(null, array(
            'code' => 'not_writable',
            'message' => '检测到 provider 文件但不可写',
        ), 400);
    }
    $accounts = gojs_ftp_accounts_load();
    $result = gojs_ftp_sync_to_provider($accounts, $caps);
    if (!$result['ok']) {
        gojs_json_response(null, array(
            'code' => 'sync_failed',
            'message' => isset($result['error']) ? $result['error'] : '同步失败',
        ), 500);
    }
    gojs_json_response(array('ok' => true, 'count' => count($accounts)));
}

function gojs_api_ftp_export() {
    $format = gojs_get_param('format', 'proftpd_authfile');
    $accounts = gojs_ftp_accounts_load();

    $filename = '';
    $content = '';

    if ($format === 'pureftpd_passwd') {
        $tmp_path = tempnam(sys_get_temp_dir(), 'pureftpd_');
        $result = gojs_ftp_pureftpd_write($accounts, $tmp_path);
        if (!$result['ok']) {
            @unlink($tmp_path);
            gojs_json_response(null, array(
                'code' => 'export_failed',
                'message' => isset($result['error']) ? $result['error'] : '导出失败',
            ), 500);
        }
        $content = file_get_contents($tmp_path);
        @unlink($tmp_path);
        $filename = 'pureftpd.passwd';
    } else {
        $tmp_path = tempnam(sys_get_temp_dir(), 'proftpd_');
        $result = gojs_ftp_proftpd_write($accounts, $tmp_path);
        if (!$result['ok']) {
            @unlink($tmp_path);
            gojs_json_response(null, array(
                'code' => 'export_failed',
                'message' => isset($result['error']) ? $result['error'] : '导出失败',
            ), 500);
        }
        $content = file_get_contents($tmp_path);
        @unlink($tmp_path);
        $filename = 'ftpd.passwd';
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    gojs_monitor_bump_bandwidth(0, strlen($content));
    echo $content;
    exit;
}

/* ============================================================
 * 通用 HTTP 获取（curl 优先，stream context 兜底）
 * 返回 array('ok' => bool, 'body' => string, 'error' => string)
 * ============================================================ */
function gojs_http_get($url, $timeout = 15, $headers = array()) {
    $result = array('ok' => false, 'body' => '', 'error' => '');

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Go.js-Lite/' . VERSION);
        $hdr = array('Accept: */*');
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (is_string($k)) {
                    $hdr[] = $k . ': ' . $v;
                } else {
                    $hdr[] = $v;
                }
            }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $hdr);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || ($code >= 400 && $code !== 0)) {
            $result['error'] = $err !== '' ? $err : 'HTTP ' . $code;
            return $result;
        }
        $result['ok'] = true;
        $result['body'] = $body;
        return $result;
    }

    // fallback: stream context（https）
    $header_lines = "User-Agent: Go.js-Lite/" . VERSION . "\r\nAccept: */*\r\n";
    if (is_array($headers)) {
        foreach ($headers as $k => $v) {
            $header_lines .= (is_string($k) ? $k . ': ' . $v : $v) . "\r\n";
        }
    }
    $opts = array(
        'http' => array(
            'method' => 'GET',
            'timeout' => $timeout,
            'header' => $header_lines,
            'ignore_errors' => true,
        ),
        'ssl' => array(
            'verify_peer' => true,
            'verify_peer_name' => true,
        ),
    );
    $ctx = stream_context_create($opts);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        $result['error'] = '无法连接远程服务器';
        return $result;
    }
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $code = (int)$m[1];
            }
        }
    }
    if ($code >= 400) {
        $result['error'] = 'HTTP ' . $code;
        return $result;
    }
    $result['ok'] = true;
    $result['body'] = $body;
    return $result;
}

/* ============================================================
 * Task 8：一键升级向导
 * ============================================================ */
function gojs_upgrade_progress_write(array $data) {
    $data['updated_at'] = time();
    gojs_write_json_lock_safe(CONFIG_DIR . '/upgrade_progress.json', $data);
}

function gojs_upgrade_progress_read() {
    return gojs_read_json_lock_safe(CONFIG_DIR . '/upgrade_progress.json', array());
}

function gojs_upgrade_lock_acquire() {
    $lock_file = CONFIG_DIR . '/upgrade.lock';
    if (file_exists($lock_file)) {
        $raw = @file_get_contents($lock_file);
        $lock_data = $raw ? json_decode($raw, true) : null;
        $ts = is_array($lock_data) && isset($lock_data['ts']) ? (int)$lock_data['ts'] : 0;
        if ($ts > 0 && (time() - $ts) < 600) {
            return false; // 10 分钟内视为进行中，拒绝并发
        }
        @unlink($lock_file); // 过期锁
    }
    @file_put_contents($lock_file, json_encode(array('pid' => getmypid(), 'ts' => time())), LOCK_EX);
    return true;
}

function gojs_upgrade_lock_release() {
    @unlink(CONFIG_DIR . '/upgrade.lock');
}

function gojs_upgrade_check() {
    global $config;

    $source = 'https://api.github.com/repos/YQteam-dyq/Go.js-Lite/releases/latest';
    if (isset($config['upgrade']['github_url']) && trim((string)$config['upgrade']['github_url']) !== '') {
        $source = trim((string)$config['upgrade']['github_url']);
    }

    $res = gojs_http_get($source, 20, array('Accept' => 'application/vnd.github+json'));
    if (empty($res['ok'])) {
        return array('ok' => false, 'error' => '网络请求失败: ' . (isset($res['error']) ? $res['error'] : '未知错误'));
    }

    $data = json_decode($res['body'], true);
    if (!is_array($data) || empty($data['tag_name'])) {
        return array('ok' => false, 'error' => '解析发布信息失败');
    }

    $latest = ltrim((string)$data['tag_name'], 'vV');
    $asset_url = '';
    $asset_size = 0;
    if (!empty($data['assets']) && is_array($data['assets'])) {
        foreach ($data['assets'] as $asset) {
            if (!is_array($asset)) continue;
            $name = isset($asset['name']) ? (string)$asset['name'] : '';
            if (preg_match('/^gojs-lite-.*\.zip$/i', $name)) {
                $asset_url = isset($asset['browser_download_url']) ? (string)$asset['browser_download_url'] : '';
                $asset_size = isset($asset['size']) ? (int)$asset['size'] : 0;
                break;
            }
        }
    }
    if ($asset_url === '') {
        return array('ok' => false, 'error' => '未找到可下载的升级包（gojs-lite-*.zip）');
    }

    $update_available = version_compare($latest, APP_VERSION, '>');
    $check_result = array(
        'checked_at' => time(),
        'latest_version' => $latest,
        'current_version' => APP_VERSION,
        'update_available' => $update_available,
        'release_name' => isset($data['name']) ? (string)$data['name'] : '',
        'published_at' => isset($data['published_at']) ? (string)$data['published_at'] : '',
        'asset_url' => $asset_url,
        'asset_size' => $asset_size,
    );

    if (!isset($config['upgrade']) || !is_array($config['upgrade'])) {
        $config['upgrade'] = array();
    }
    $config['upgrade']['last_check_at'] = time();
    $config['upgrade']['last_check_result'] = $check_result;
    gojs_save_config();

    return array_merge(array('ok' => true), $check_result);
}

function gojs_api_upgrade_check() {
    @session_write_close();
    $result = gojs_upgrade_check();
    if (empty($result['ok'])) {
        gojs_json_response(null, array(
            'code' => 'check_failed',
            'message' => isset($result['error']) ? $result['error'] : '检测升级信息失败',
        ), 502);
        return;
    }
    gojs_json_response($result);
}

function gojs_api_upgrade_progress() {
    @session_write_close();
    $progress = gojs_upgrade_progress_read();
    if (empty($progress) || empty($progress['step'])) {
        gojs_json_response(array('step' => null));
        return;
    }
    gojs_json_response($progress);
}

// 备份面板自身关键文件到升级备份目录
function gojs_upgrade_backup_self($dest_dir) {
    if (!is_dir($dest_dir) && !@mkdir($dest_dir, 0700, true)) {
        return false;
    }
    $items = array('api.php', 'router.php', 'webcron.php', '.htaccess', 'LICENSE', 'README.md', 'dist');
    foreach ($items as $item) {
        $src = ROOT . '/' . $item;
        if (is_dir($src)) {
            if (!is_dir($dest_dir . '/' . $item) && !gojs_recursive_copy($src, $dest_dir . '/' . $item)) {
                return false;
            }
        } elseif (is_file($src)) {
            if (!@copy($src, $dest_dir . '/' . $item)) {
                return false;
            }
        }
    }
    return true;
}

// 安全展开升级包到 ROOT：跳过顶层 gojs/ 目录段与 .gojs/，禁止 ..，仅常规文件/目录
function gojs_upgrade_extract_to_root($zip_path) {
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return array('ok' => false, 'error' => '无法打开升级包');
    }
    $count = $zip->numFiles;

    // 防错包：zip 内必须包含 api.php
    $has_api = false;
    for ($i = 0; $i < $count; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if ($name === 'api.php' || substr($name, -8) === '/api.php') {
            $has_api = true;
            break;
        }
    }
    if (!$has_api) {
        $zip->close();
        return array('ok' => false, 'error' => '升级包不包含 api.php，已中止');
    }

    $extracted = 0;
    for ($i = 0; $i < $count; $i++) {
        $name = (string)$zip->getNameIndex($i);
        $name = str_replace('\\', '/', $name);
        $parts = explode('/', $name);
        if (isset($parts[0]) && $parts[0] === 'gojs') {
            array_shift($parts); // 去掉顶层 gojs/ 目录段
        }
        $parts = array_values(array_filter($parts, function ($p) {
            return $p !== '' && $p !== '.';
        }));
        if (count($parts) === 0) continue;

        $rel = implode('/', $parts);
        // 跳过 .gojs 配置目录
        if ($rel === '.gojs' || strpos($rel, '.gojs/') === 0) continue;
        // 禁止路径穿越
        if (strpos($rel, '..') !== false) continue;

        $target = ROOT . '/' . $rel;
        $is_dir = (substr($name, -1) === '/');
        if ($is_dir) {
            if (!is_dir($target) && !@mkdir($target, 0755, true)) {
                $zip->close();
                return array('ok' => false, 'error' => '创建目录失败: ' . $rel);
            }
            continue;
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $zip->close();
            return array('ok' => false, 'error' => '创建目录失败: ' . $dir);
        }

        $content = $zip->getFromIndex($i);
        if ($content === false) {
            $zip->close();
            return array('ok' => false, 'error' => '读取升级包条目失败: ' . $rel);
        }

        // 原子写入：同目录临时文件 + rename（失败 fallback copy）
        $tmp = $target . '.gojs_tmp_' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            @unlink($tmp);
            $zip->close();
            return array('ok' => false, 'error' => '写入文件失败: ' . $rel);
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $target)) {
            if (!@copy($tmp, $target)) {
                @unlink($tmp);
                $zip->close();
                return array('ok' => false, 'error' => '覆盖文件失败: ' . $rel);
            }
            @unlink($tmp);
        }
        $extracted++;
    }
    $zip->close();
    return array('ok' => true, 'extracted' => $extracted);
}

function gojs_api_upgrade_apply() {
    global $config;
    @set_time_limit(0);
    @session_write_close();

    if (!gojs_upgrade_lock_acquire()) {
        gojs_json_response(null, array('code' => 'upgrade_in_progress', 'message' => '已有升级任务正在进行，请稍后再试'), 409);
        return;
    }

    $tmp_dir = CONFIG_DIR . '/upgrade_tmp';
    if (is_dir($tmp_dir)) {
        @gojs_recursive_delete($tmp_dir);
    }
    if (!@mkdir($tmp_dir, 0700, true)) {
        gojs_upgrade_lock_release();
        gojs_json_response(null, array('code' => 'upgrade_failed', 'message' => '无法创建临时目录'), 400);
        return;
    }

    try {
        gojs_upgrade_progress_write(array(
            'step' => 'download',
            'message_key' => 'upgrade.stepDownload',
            'message' => '下载新版本',
            'percent' => 5,
            'error' => '',
        ));

        // 优先复用 10 分钟内最近一次检测的资产地址，避免重复请求
        $check = null;
        if (
            isset($config['upgrade']['last_check_result'])
            && is_array($config['upgrade']['last_check_result'])
            && !empty($config['upgrade']['last_check_result']['asset_url'])
            && isset($config['upgrade']['last_check_at'])
            && (time() - (int)$config['upgrade']['last_check_at']) < 600
        ) {
            $check = $config['upgrade']['last_check_result'];
        } else {
            $check = gojs_upgrade_check();
        }
        if (empty($check['asset_url'])) {
            throw new RuntimeException(isset($check['error']) ? $check['error'] : '检测升级信息失败');
        }

        $zip_path = $tmp_dir . '/upgrade.zip';
        $dl = gojs_http_get($check['asset_url'], 300);
        if (empty($dl['ok']) || $dl['body'] === '') {
            throw new RuntimeException('下载升级包失败: ' . (isset($dl['error']) ? $dl['error'] : '响应为空'));
        }
        if (@file_put_contents($zip_path, $dl['body'], LOCK_EX) === false) {
            throw new RuntimeException('保存升级包失败');
        }
        gojs_upgrade_progress_write(array(
            'step' => 'download',
            'message_key' => 'upgrade.stepDownload',
            'message' => '下载完成',
            'percent' => 30,
            'error' => '',
        ));

        // 备份当前版本
        gojs_upgrade_progress_write(array(
            'step' => 'backup',
            'message_key' => 'upgrade.stepBackup',
            'message' => '备份当前版本',
            'percent' => 35,
            'error' => '',
        ));
        $backup_dir = CONFIG_DIR . '/upgrade_backup_' . date('YmdHis');
        if (!gojs_upgrade_backup_self($backup_dir)) {
            throw new RuntimeException('备份当前版本失败');
        }
        gojs_upgrade_progress_write(array(
            'step' => 'backup',
            'message_key' => 'upgrade.stepBackup',
            'message' => '备份完成',
            'percent' => 40,
            'error' => '',
        ));

        // 解压覆盖
        gojs_upgrade_progress_write(array(
            'step' => 'extract',
            'message_key' => 'upgrade.stepExtract',
            'message' => '解压覆盖',
            'percent' => 45,
            'error' => '',
        ));
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('服务器不支持 ZipArchive 扩展，无法解压升级包');
        }
        $ex = gojs_upgrade_extract_to_root($zip_path);
        if (empty($ex['ok'])) {
            throw new RuntimeException(isset($ex['error']) ? $ex['error'] : '解压升级包失败');
        }
        gojs_upgrade_progress_write(array(
            'step' => 'extract',
            'message_key' => 'upgrade.stepExtract',
            'message' => '解压完成',
            'percent' => 85,
            'error' => '',
        ));

        // 迁移：新代码在下次请求加载时自动执行，无需手动触发
        gojs_upgrade_progress_write(array(
            'step' => 'migrate',
            'message_key' => 'upgrade.stepMigrate',
            'message' => '迁移将在下次加载自动执行',
            'percent' => 95,
            'error' => '',
        ));

        gojs_upgrade_progress_write(array(
            'step' => 'done',
            'message_key' => 'upgrade.stepDone',
            'message' => '升级完成',
            'percent' => 100,
            'error' => '',
        ));
        gojs_upgrade_lock_release();
        @gojs_recursive_delete($tmp_dir);

        gojs_log_operation('upgrade', 'gojs-lite-v' . $check['latest_version'], true);
        gojs_json_response(array(
            'ok' => true,
            'backup_dir' => basename($backup_dir),
            'latest_version' => $check['latest_version'],
        ));
    } catch (Throwable $e) {
        gojs_upgrade_progress_write(array(
            'step' => 'error',
            'message_key' => 'upgrade.stepError',
            'message' => $e->getMessage(),
            'percent' => 100,
            'error' => $e->getMessage(),
        ));
        gojs_upgrade_lock_release();
        @gojs_recursive_delete($tmp_dir);
        gojs_log_operation('upgrade', 'apply failed: ' . $e->getMessage(), false);
        gojs_json_response(null, array('code' => 'upgrade_failed', 'message' => '升级失败: ' . $e->getMessage()), 400);
    }
}

/* ============================================================
 * Task 11：一键应用部署
 * ============================================================ */
function gojs_deploy_apps() {
    if (isset($GLOBALS['GOJS_DEPLOY_APPS']) && is_array($GLOBALS['GOJS_DEPLOY_APPS'])) {
        return $GLOBALS['GOJS_DEPLOY_APPS'];
    }
    $GLOBALS['GOJS_DEPLOY_APPS'] = array(
        array(
            'id' => 'wordpress',
            'name' => 'WordPress',
            'name_key' => 'deploy.appWordpress',
            'description_key' => 'deploy.descWordpress',
            'version' => 'latest',
            'download_url' => 'https://wordpress.org/latest.zip',
            'db_required' => true,
        ),
        array(
            'id' => 'typecho',
            'name' => 'Typecho',
            'name_key' => 'deploy.appTypecho',
            'description_key' => 'deploy.descTypecho',
            'version' => 'latest',
            'download_url' => 'https://github.com/typecho/typecho/releases/latest/download/typecho.zip',
            'db_required' => true,
        ),
    );
    return $GLOBALS['GOJS_DEPLOY_APPS'];
}

function gojs_api_deploy_apps() {
    gojs_json_response(array('apps' => gojs_deploy_apps()));
}

// 解析并校验部署目标目录（相对 files_root）
function gojs_deploy_resolve_target($raw) {
    $files_root = $GLOBALS['files_root'];
    $relative = str_replace('\\', '/', trim((string)$raw));
    $relative = trim($relative, '/');
    if ($relative === '' || strpos($relative, '.') === 0) {
        return array('ok' => false, 'error' => '目标目录不能为空，且不能以 . 开头');
    }
    $full = gojs_safe_path($relative);
    if (!$full) {
        return array('ok' => false, 'error' => '目标目录超出允许范围');
    }
    $full = rtrim($full, '/');

    $root_real = rtrim(realpath(ROOT), '/');
    $files_real = rtrim(realpath($files_root), '/');

    if ($full === $files_real) {
        return array('ok' => false, 'error' => '目标目录不能是站点根目录');
    }
    // 面板安装在子目录（files_root/gojs）时，禁止部署到面板目录内部
    if ($files_real !== $root_real && ($full === $root_real || strpos($full, $root_real . '/') === 0)) {
        return array('ok' => false, 'error' => '目标目录不能在面板目录内');
    }
    return array('ok' => true, 'full' => $full, 'relative' => $relative);
}

// 解压应用到 extract_dir：去掉 web 根前缀（最浅 index.php 所在目录段），extract_dir 即 web 根内容
function gojs_deploy_extract_app($zip_path, $extract_dir) {
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return array('ok' => false, 'error' => '无法打开应用安装包');
    }
    $count = $zip->numFiles;

    // 找最浅的 index.php，确定 web 根前缀
    $prefix = '';
    $min_depth = PHP_INT_MAX;
    for ($i = 0; $i < $count; $i++) {
        $name = (string)$zip->getNameIndex($i);
        $name = str_replace('\\', '/', $name);
        if (substr($name, -10) === '/index.php') {
            $parts = explode('/', trim($name, '/'));
            $depth = count($parts) - 1;
            if ($depth < $min_depth) {
                $min_depth = $depth;
                $prefix = $depth > 0 ? implode('/', array_slice($parts, 0, $depth)) : '';
            }
        }
    }
    if ($min_depth === PHP_INT_MAX) {
        $zip->close();
        return array('ok' => false, 'error' => '安装包中未找到 index.php');
    }
    $prefix_parts = $prefix !== '' ? explode('/', $prefix) : array();

    $extracted = 0;
    for ($i = 0; $i < $count; $i++) {
        $name = (string)$zip->getNameIndex($i);
        $name = str_replace('\\', '/', $name);
        $trimmed = trim($name, '/');
        if ($trimmed === '') continue;

        $parts = explode('/', $trimmed);
        if (count($prefix_parts) > 0) {
            if (count($parts) < count($prefix_parts) || array_slice($parts, 0, count($prefix_parts)) !== $prefix_parts) {
                continue; // 不在 web 根下的文件（readme、许可证等）跳过
            }
            $parts = array_slice($parts, count($prefix_parts));
        }
        if (count($parts) === 0) continue;
        $rel = implode('/', $parts);
        if (strpos($rel, '..') !== false) continue; // 防路径穿越

        $target = $extract_dir . '/' . $rel;
        $is_dir = (substr($name, -1) === '/');
        if ($is_dir) {
            if (!is_dir($target)) @mkdir($target, 0755, true);
            continue;
        }
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $zip->close();
            return array('ok' => false, 'error' => '创建目录失败: ' . $rel);
        }
        $content = $zip->getFromIndex($i);
        if ($content === false) continue;
        if (@file_put_contents($target, $content, LOCK_EX) === false) {
            $zip->close();
            return array('ok' => false, 'error' => '写入文件失败: ' . $rel);
        }
        $extracted++;
    }
    $zip->close();

    if (!file_exists($extract_dir . '/index.php')) {
        return array('ok' => false, 'error' => '解压后未找到 index.php');
    }
    return array('ok' => true, 'web_root' => $extract_dir, 'extracted' => $extracted);
}

function gojs_deploy_wp_config($db_name, $db_user, $db_pass, $db_host, $db_prefix) {
    $salts = array('AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT');
    $out = "<?php\n";
    $out .= "/** 由 Go.js 一键部署生成 */\n";
    $out .= "define( 'DB_NAME', " . var_export($db_name, true) . " );\n";
    $out .= "define( 'DB_USER', " . var_export($db_user, true) . " );\n";
    $out .= "define( 'DB_PASSWORD', " . var_export($db_pass, true) . " );\n";
    $out .= "define( 'DB_HOST', " . var_export($db_host, true) . " );\n";
    $out .= "define( 'DB_CHARSET', 'utf8mb4' );\n";
    $out .= "define( 'DB_COLLATE', '' );\n";
    foreach ($salts as $salt) {
        $out .= "define( '" . $salt . "', " . var_export(gojs_crypto_get_rand_alphanum(64), true) . " );\n";
    }
    $out .= "\$table_prefix = " . var_export($db_prefix, true) . ";\n";
    $out .= "define( 'WP_DEBUG', false );\n\n";
    $out .= "if ( ! defined( 'ABSPATH' ) ) {\n";
    $out .= "\tdefine( 'ABSPATH', __DIR__ . '/' );\n";
    $out .= "}\n";
    $out .= "require_once ABSPATH . 'wp-settings.php';\n";
    return $out;
}

function gojs_deploy_typecho_config($db_name, $db_user, $db_pass, $db_host, $db_prefix) {
    $host = $db_host;
    $port = 3306;
    if (strpos($db_host, ':') !== false) {
        $host_part = explode(':', $db_host, 2);
        $host = $host_part[0];
        $port = (int)$host_part[1];
    }
    $out = "<?php\n";
    $out .= "/** 由 Go.js 一键部署生成 */\n";
    $out .= "define('__TYPECHO_DB_HOST__', " . var_export($host, true) . ");\n";
    $out .= "define('__TYPECHO_DB_PORT__', " . var_export($port, true) . ");\n";
    $out .= "define('__TYPECHO_DB_NAME__', " . var_export($db_name, true) . ");\n";
    $out .= "define('__TYPECHO_DB_USER__', " . var_export($db_user, true) . ");\n";
    $out .= "define('__TYPECHO_DB_PASSWORD__', " . var_export($db_pass, true) . ");\n";
    $out .= "define('__TYPECHO_DB_CHARSET__', 'utf8mb4');\n";
    $out .= "define('__TYPECHO_DB_ENGINE__', 'InnoDB');\n";
    $out .= "define('__TYPECHO_DB_PREFIX__', " . var_export($db_prefix, true) . ");\n";
    return $out;
}

// 写数据库配置文件；返回是否已写入（未提供 db 信息或目标已有配置则不写）
function gojs_deploy_write_db_config($app_id, $target_dir, $body) {
    $db_name = isset($body['db_name']) ? trim((string)$body['db_name']) : '';
    $db_user = isset($body['db_user']) ? trim((string)$body['db_user']) : '';
    if ($db_name === '' || $db_user === '') {
        return false;
    }
    $db_pass = isset($body['db_pass']) ? (string)$body['db_pass'] : '';
    $db_host = isset($body['db_host']) && trim((string)$body['db_host']) !== '' ? trim((string)$body['db_host']) : 'localhost';
    $db_prefix = isset($body['db_prefix']) && trim((string)$body['db_prefix']) !== '' ? trim((string)$body['db_prefix']) : '';

    if ($app_id === 'wordpress') {
        $config_path = $target_dir . '/wp-config.php';
        if (file_exists($config_path)) return false;
        if ($db_prefix === '') $db_prefix = 'wp_';
        $content = gojs_deploy_wp_config($db_name, $db_user, $db_pass, $db_host, $db_prefix);
        return @file_put_contents($config_path, $content, LOCK_EX) !== false;
    }

    if ($app_id === 'typecho') {
        $config_path = $target_dir . '/config.inc.php';
        if (file_exists($config_path)) return false;
        if ($db_prefix === '') $db_prefix = 'typecho_';
        $content = gojs_deploy_typecho_config($db_name, $db_user, $db_pass, $db_host, $db_prefix);
        return @file_put_contents($config_path, $content, LOCK_EX) !== false;
    }

    return false;
}

function gojs_api_deploy_run() {
    @set_time_limit(0);
    @session_write_close();

    $body = gojs_get_body();
    $app_id = isset($body['app_id']) ? trim((string)$body['app_id']) : '';
    $raw_target = isset($body['target_dir']) ? trim((string)$body['target_dir']) : '';

    $app = null;
    foreach (gojs_deploy_apps() as $a) {
        if (isset($a['id']) && $a['id'] === $app_id) {
            $app = $a;
            break;
        }
    }
    if (!$app) {
        gojs_json_response(null, array('code' => 'invalid_app', 'message' => '未知的应用类型'), 400);
        return;
    }

    $target_info = gojs_deploy_resolve_target($raw_target);
    if (empty($target_info['ok'])) {
        gojs_json_response(null, array('code' => 'invalid_target', 'message' => $target_info['error']), 400);
        return;
    }
    $full_target = $target_info['full'];
    $relative_target = $target_info['relative'];

    // 目标已存在且非空时需 overwrite=true
    $exists_non_empty = false;
    if (file_exists($full_target)) {
        if (is_file($full_target)) {
            $exists_non_empty = true;
        } elseif (is_dir($full_target)) {
            $items = @scandir($full_target);
            $exists_non_empty = is_array($items) && count($items) > 2;
        }
    }
    $overwrite = !empty($body['overwrite']);
    if ($exists_non_empty && !$overwrite) {
        gojs_json_response(null, array('code' => 'target_not_empty', 'message' => '目标目录已存在且不为空，需确认覆盖'), 400);
        return;
    }

    $tmp_root = CONFIG_DIR . '/deploy_tmp';
    if (is_dir($tmp_root)) {
        @gojs_recursive_delete($tmp_root);
    }
    if (!@mkdir($tmp_root, 0700, true)) {
        gojs_json_response(null, array('code' => 'deploy_failed', 'message' => '无法创建临时目录'), 400);
        return;
    }

    try {
        $zip_path = $tmp_root . '/' . $app['id'] . '.zip';
        $dl = gojs_http_get($app['download_url'], 300);
        if (empty($dl['ok']) || $dl['body'] === '') {
            throw new RuntimeException('下载应用安装包失败: ' . (isset($dl['error']) ? $dl['error'] : '响应为空'));
        }
        if (@file_put_contents($zip_path, $dl['body'], LOCK_EX) === false) {
            throw new RuntimeException('保存应用安装包失败');
        }
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('服务器不支持 ZipArchive 扩展，无法解压应用');
        }

        $extract_dir = $tmp_root . '/extract';
        if (!@mkdir($extract_dir, 0700, true)) {
            throw new RuntimeException('无法创建解压目录');
        }
        $ex = gojs_deploy_extract_app($zip_path, $extract_dir);
        if (empty($ex['ok'])) {
            throw new RuntimeException(isset($ex['error']) ? $ex['error'] : '解压应用失败');
        }

        if (!gojs_recursive_copy($extract_dir, $full_target)) {
            throw new RuntimeException('复制文件到目标目录失败');
        }

        // 数据库配置（未提供 db 信息时不写，由应用自带 web 安装向导完成）
        $db_configured = false;
        if (!empty($app['db_required'])) {
            $db_configured = gojs_deploy_write_db_config($app['id'], $full_target, $body);
        }
        $next_step_key = $db_configured ? 'deploy.nextVisit' : 'deploy.nextWebInstall';

        @gojs_recursive_delete($tmp_root);
        gojs_log_operation('deploy_app', $app_id . '@' . $relative_target, true);
        gojs_json_response(array(
            'ok' => true,
            'app_id' => $app_id,
            'target_dir' => $relative_target,
            'files_extracted' => isset($ex['extracted']) ? (int)$ex['extracted'] : 0,
            'db_configured' => $db_configured,
            'next_step_key' => $next_step_key,
        ));
    } catch (Throwable $e) {
        @gojs_recursive_delete($tmp_root);
        gojs_log_operation('deploy_app', $app_id . '@' . $relative_target, false, $e->getMessage());
        gojs_json_response(null, array('code' => 'deploy_failed', 'message' => '部署失败: ' . $e->getMessage()), 400);
    }
}
