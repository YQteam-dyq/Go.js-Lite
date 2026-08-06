<?php

// Core framework: init, error/exception handling, JSON responses, request parsing, routing.
// Split from api.php; keep original function signatures and behavior unchanged.

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

/**
 * Initialize the app environment: register error/exception handlers, load config, start session,
 * infer the file-management root, run migrations, then dispatch unless GOJS_SKIP_DISPATCH is set.
 */
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

    // Populate the injectable context, kept in sync with the legacy globals for testing and migration.
    gojs_ctx()
        ->reset()
        ->setConfig(is_array($config) ? $config : array())
        ->setFilesRoot($GLOBALS['files_root'])
        ->setInstalled($installed);

    if (!defined('GOJS_SKIP_DISPATCH') || !GOJS_SKIP_DISPATCH) {
        gojs_dispatch();
    }
}

function gojs_ctx() {
    return GoJS_Context::instance();
}

function gojs_config() {
    return gojs_ctx()->config();
}

function gojs_files_root() {
    return gojs_ctx()->filesRoot();
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

/**
 * Emit a unified JSON response: ok=true with data on success, ok=false with error on failure.
 * Writes JSON Content-Type and security headers, bumps monitoring bandwidth, then terminates.
 */
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

    // monitor: panel traffic proxy metric (outbound bytes = response body, inbound = CONTENT_LENGTH)
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

/**
 * Dispatch entry: parse the action (via ?api= query or /api/<action> path), apply legacy aliases,
 * enforce auth and CSRF on non-public routes plus the API Token scope gate, then dispatch.
 */
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

    // legacy path aliases: map old frontend routes to current endpoints
    $legacy_aliases = array(
        'files/list'            => 'files',
        'settings/get'          => 'settings',
        'htaccess/get'          => 'htaccess',
        'trash/list'            => 'trash',
        'ssl/acme/certificates' => 'ssl/certificates',
        '2fa/status'            => 'auth/totp/status',
    );
    if (isset($legacy_aliases[$api])) {
        $api = $legacy_aliases[$api];
    }

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

    // Scope gate: requests authenticated via an API Token may only access api/* REST endpoints.
    if (!empty($_SESSION['api_token_scopes']) && strpos($api, 'api/') !== 0) {
        gojs_json_response(null, array(
            'code' => 'token_not_allowed',
            'message' => 'API Token 仅允许访问 REST 端点（api/*）',
        ), 403);
    }

    $router = gojs_build_router();
    $router->dispatch($api, $method);
}

function gojs_build_router() {
    $r = new GoJS_Router();
    $any = array('GET', 'POST', 'PUT', 'PATCH', 'DELETE');

    $r->add($any, 'bootstrap', function () { gojs_api_bootstrap(); });
    $r->add('POST', 'install', function () { gojs_api_install(); });
    $r->add('POST', 'login', function () { gojs_api_login(); });
    $r->add('POST', 'logout', function () { gojs_api_logout(); });
    $r->add('POST', 'change-password', function () { gojs_api_change_password(); });
    $r->add(array('GET', 'POST'), 'settings', function ($m) {
        if ($m === 'GET') { gojs_api_get_settings(); }
        elseif ($m === 'POST') { gojs_api_update_settings(); }
    });
    $r->add('POST', 'regenerate-access-token', function () { gojs_api_regenerate_access_token(); });
    $r->add('GET', 'settings/export', function () { gojs_api_settings_export(); });
    $r->add('POST', 'settings/reset', function () { gojs_api_settings_reset(); });

    $r->add($any, 'files', function () { gojs_api_files(); });
    $r->add($any, 'file-content', function () { gojs_api_file_content(); });
    $r->add('POST', 'file-save', function () { gojs_api_file_save(); });
    $r->add('POST', 'file-mkdir', function () { gojs_api_file_mkdir(); });
    $r->add('POST', 'file-touch', function () { gojs_api_file_touch(); });
    $r->add('POST', 'file-delete', function () { gojs_api_file_delete(); });
    $r->add('POST', 'file-rename', function () { gojs_api_file_rename(); });
    $r->add('POST', 'file-copy', function () { gojs_api_file_copy(); });
    $r->add('POST', 'file-chmod', function () { gojs_api_file_chmod(); });
    $r->add($any, 'file-search', function () { gojs_api_file_search(); });
    $r->add('POST', 'file-zip', function () { gojs_api_file_zip(); });
    $r->add('POST', 'file-unzip', function () { gojs_api_file_unzip(); });
    $r->add('POST', 'file-targz', function () { gojs_api_file_targz(); });
    $r->add('POST', 'file-untargz', function () { gojs_api_file_untargz(); });
    $r->add('POST', 'upload', function () { gojs_api_upload(); });
    $r->add('POST', 'upload-chunk', function () { gojs_api_upload_chunk(); });
    $r->add($any, 'download', function () { gojs_api_download(); });

    $r->add($any, 'error-log', function () { gojs_api_error_log(); });
    $r->add('POST', 'error-log/clear', function () { gojs_api_error_log_clear(); });
    $r->add($any, 'operation-log', function () { gojs_api_operation_log(); });
    $r->add('POST', 'operation-log/clear', function () { gojs_api_operation_log_clear(); });
    $r->add('POST', 'operation-log/export', function () { gojs_api_operation_log_export(); });
    $r->add($any, 'alert-rules', function ($m) { gojs_api_alert_rules($m); });
    $r->add($any, 'install/check', function () { gojs_api_install_check(); });

    $r->add($any, 'dashboard', function () { gojs_api_dashboard(); });
    $r->add($any, 'phpinfo', function () { gojs_api_phpinfo(); });
    $r->add($any, 'phpinfo/ini', function () { gojs_api_phpinfo_ini(); });
    $r->add($any, 'health-check', function () { gojs_api_health_check(); });
    $r->add($any, 'env-check', function () { gojs_api_env_check(); });
    $r->add($any, 'system', function () { gojs_api_system(); });
    $r->add($any, 'system/processes', function () { gojs_api_processes(); });
    $r->add($any, 'system/cron', function () { gojs_api_cron(); });
    $r->add($any, 'cron/capabilities', function () { gojs_api_cron_capabilities(); });
    $r->add($any, 'cron/list', function () { gojs_api_cron_list(); });
    $r->add('POST', 'cron/save', function () { gojs_api_cron_save(); });
    $r->add($any, 'disk-analysis', function () { gojs_api_disk_analysis(); });
    $r->add($any, 'disk-analysis/large-files', function () { gojs_api_disk_analysis_large_files(); });

    $r->add($any, 'db/connections', function () { gojs_api_db_connections(); });
    $r->add($any, 'db/databases', function () { gojs_api_db_databases(); });
    $r->add($any, 'db/tables', function () { gojs_api_db_tables(); });
    $r->add($any, 'db/structure', function () { gojs_api_db_structure(); });
    $r->add('POST', 'db/sql', function () { gojs_api_db_sql(); });
    $r->add('POST', 'db/export', function () { gojs_api_db_export(); });
    $r->add('POST', 'db/import', function () { gojs_api_db_import(); });

    $r->add($any, 'htaccess', function () { gojs_api_htaccess(); });
    $r->add('POST', 'htaccess/generate', function () { gojs_api_htaccess_generate(); });
    $r->add('POST', 'htaccess/reset', function () { gojs_api_htaccess_reset(); });

    $r->add('POST', 'backup/create', function () { gojs_api_backup_create(); });
    $r->add($any, 'backup/list', function () { gojs_api_backup_list(); });
    $r->add($any, 'backup/download', function () { gojs_api_backup_download(); });
    $r->add('POST', 'backup/delete', function () { gojs_api_backup_delete(); });
    $r->add('POST', 'backup/restore', function () { gojs_api_backup_restore(); });
    $r->add('GET', 'trash', function () { gojs_api_trash_list(); });
    $r->add('POST', 'trash/restore', function () { gojs_api_trash_restore(); });
    $r->add('POST', 'trash/purge', function () { gojs_api_trash_purge(); });
    $r->add($any, 'trash/config', function () { gojs_api_trash_config(); });

    $r->add(array('GET', 'POST'), 'api-tokens', function ($m) {
        if ($m === 'GET') { gojs_api_tokens_list(); }
        elseif ($m === 'POST') { gojs_api_tokens_create(); }
    });
    $r->add('GET', 'api/status', function () { gojs_api_status(); });
    $r->add('POST', 'api/backup/run', function () { gojs_api_backup_run_rest(); });
    $r->add('GET', 'api/files', function () { gojs_api_files_rest(); });

    $r->add(array('GET', 'POST'), 'backup/destinations', function ($m) {
        if ($m === 'GET') { gojs_api_backup_destinations_list(); }
        elseif ($m === 'POST') { gojs_api_backup_destinations_create(); }
    });
    $r->add('POST', 'backup/destinations/test', function () { gojs_api_backup_destinations_test(); });
    $r->add('POST', 'backup/destinations/browse', function () { gojs_api_backup_destinations_browse(); });
    $r->add('POST', 'backup/destinations/download', function () { gojs_api_backup_destinations_download(); });
    $r->add(array('GET', 'POST'), 'backup/schedules', function ($m) {
        if ($m === 'GET') { gojs_api_backup_schedules_list(); }
        elseif ($m === 'POST') { gojs_api_backup_schedules_create(); }
    });
    $r->add('GET', 'backup/runs', function () { gojs_api_backup_runs_list(); });

    $r->add('POST', 'internal/cron', function () { gojs_api_internal_cron_tick(); });
    $r->add('POST', 'internal/cron/tick', function () { gojs_api_internal_cron_tick(); });
    $r->add('POST', 'internal/cron/regenerate-token', function () { gojs_api_internal_cron_regenerate_token(); });
    $r->add('POST', 'internal/cron/drain-outbox', function () { gojs_api_internal_drain_outbox(); });
    $r->add('GET', 'webcron/status', function () { gojs_api_webcron_status(); });

    $r->add('POST', 'ssl/check', function () { gojs_api_ssl_check(); });
    $r->add($any, 'ssl/list', function () { gojs_api_ssl_list(); });
    $r->add('POST', 'ssl/add-domain', function () { gojs_api_ssl_add_domain(); });
    $r->add('POST', 'ssl/remove-domain', function () { gojs_api_ssl_remove_domain(); });
    $r->add('GET', 'ssl/capabilities-acme', function () { gojs_api_ssl_acme_capabilities(); });
    $r->add('GET', 'ssl/certificates', function () { gojs_api_ssl_acme_certificates_list(); });
    $r->add('POST', 'ssl/issue-cert', function () { gojs_api_ssl_acme_issue_cert(); });

    $r->add('GET', 'auth/totp/status', function () { gojs_api_totp_status(); });
    $r->add('POST', 'auth/totp/enroll', function () { gojs_api_totp_enroll(); });
    $r->add('POST', 'auth/totp/confirm', function () { gojs_api_totp_confirm(); });
    $r->add('POST', 'auth/totp/disable', function () { gojs_api_totp_disable(); });
    $r->add('POST', 'auth/totp/recovery-codes', function () { gojs_api_totp_recovery_codes(); });

    $r->add($any, 'notification/channels', function ($m) { gojs_api_notification_channels($m); });
    $r->add($any, 'notifications', function ($m) { gojs_api_notifications($m); });
    $r->add('GET', 'monitor', function () { gojs_api_monitor(); });
    $r->add('GET', 'notifications/summary', function () { gojs_api_notifications_summary(); });
    $r->add('PATCH', 'notifications/read-all', function () { gojs_api_notifications_read_all(); });
    $r->add('DELETE', 'notifications/clear-read', function () { gojs_api_notifications_clear_read(); });

    $r->add('GET', 'upgrade/check', function () { gojs_api_upgrade_check(); });
    $r->add('GET', 'upgrade/progress', function () { gojs_api_upgrade_progress(); });
    $r->add('POST', 'upgrade/apply', function () { gojs_api_upgrade_apply(); });
    $r->add('GET', 'deploy/apps', function () { gojs_api_deploy_apps(); });
    $r->add('POST', 'deploy/run', function () { gojs_api_deploy_run(); });

    $r->add(array('GET', 'POST'), 'secscan/frontend', function ($m) {
        if ($m === 'GET') { gojs_json_response(gojs_secscan_frontend(false)); }
        else { gojs_json_response(gojs_secscan_frontend(true)); }
    });
    $r->add(array('GET', 'POST'), 'secscan/backend', function ($m) {
        if ($m === 'GET') { gojs_json_response(gojs_secscan_backend(false)); }
        else { gojs_json_response(gojs_secscan_backend(true)); }
    });

    $r->add('GET', 'ftp/capabilities', function () { gojs_api_ftp_capabilities(); });
    $r->add(array('GET', 'POST'), 'ftp/accounts', function ($m) {
        if ($m === 'GET') { gojs_api_ftp_accounts_list(); }
        elseif ($m === 'POST') { gojs_api_ftp_accounts_create(); }
    });
    $r->add('POST', 'ftp/sync', function () { gojs_api_ftp_sync(); });
    $r->add('POST', 'ftp/export', function () { gojs_api_ftp_export(); });

    $r->addPrefix('api-tokens/', function ($path, $method) {
        $id = substr($path, strlen('api-tokens/'));
        if ($method !== 'DELETE') {
            gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            return;
        }
        gojs_api_token_revoke($id);
    });

    $r->addPrefix('ftp/accounts/', function ($path, $method) {
        $rest = substr($path, strlen('ftp/accounts/'));
        $parts = explode('/', $rest);
        $id = $parts[0];
        $sub = isset($parts[1]) ? $parts[1] : '';
        if ($sub === 'test-login') {
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                return;
            }
            gojs_api_ftp_accounts_test_login($id);
        } else {
            if ($method === 'PUT') { gojs_api_ftp_accounts_update($id); }
            elseif ($method === 'DELETE') { gojs_api_ftp_accounts_delete($id); }
            else { gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405); }
        }
    });

    $r->addPrefix('db/connections/', function ($path, $method) {
        $id = substr($path, strlen('db/connections/'));
        gojs_api_db_connection($id, $method);
    });

    $r->addPrefix('notification/channels/', function ($path, $method) {
        $rest = substr($path, strlen('notification/channels/'));
        $parts = explode('/', $rest);
        $id = $parts[0];
        $sub = isset($parts[1]) ? $parts[1] : '';
        if ($sub === 'test') {
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                return;
            }
            gojs_api_notification_channel_test($id);
        } else {
            gojs_api_notification_channel($id, $method);
        }
    });

    $r->addPrefix('notifications/', function ($path, $method) {
        $rest = substr($path, strlen('notifications/'));
        $parts = explode('/', $rest);
        $id = $parts[0];
        $sub = isset($parts[1]) ? $parts[1] : '';
        if ($sub === 'read') {
            if ($method !== 'PATCH') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                return;
            }
            gojs_api_notification_mark_read($id);
        } else {
            if ($method !== 'DELETE') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                return;
            }
            gojs_api_notification_delete($id);
        }
    });

    $r->addPrefix('alert-rules/', function ($path, $method) {
        $rest = substr($path, strlen('alert-rules/'));
        $parts = explode('/', $rest);
        $id = $parts[0];
        $sub = isset($parts[1]) ? $parts[1] : '';
        if ($sub === 'test') {
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                return;
            }
            gojs_api_alert_rule_test($id);
        } else {
            gojs_api_alert_rule($id, $method);
        }
    });

    $r->addPrefix('backup/destinations/', function ($path, $method) {
        $id = substr($path, strlen('backup/destinations/'));
        if ($method === 'PUT') { gojs_api_backup_destinations_update($id); }
        elseif ($method === 'DELETE') { gojs_api_backup_destinations_delete($id); }
        else { gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405); }
    });

    $r->addPrefix('backup/schedules/', function ($path, $method) {
        $rest = substr($path, strlen('backup/schedules/'));
        $parts = explode('/', $rest);
        $id = $parts[0];
        $sub = isset($parts[1]) ? $parts[1] : '';
        if ($sub === 'run-now') {
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                return;
            }
            gojs_api_backup_schedules_run_now($id);
        } else {
            if ($method === 'PUT') { gojs_api_backup_schedules_update($id); }
            elseif ($method === 'DELETE') { gojs_api_backup_schedules_delete($id); }
            else { gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405); }
        }
    });

    $r->addPrefix('backup/runs/', function ($path, $method) {
        $id = substr($path, strlen('backup/runs/'));
        if ($method !== 'GET') {
            gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
            return;
        }
        gojs_api_backup_runs_get($id);
    });

    $r->addPrefix('ssl/certificates/', function ($path, $method) {
        $rest = substr($path, strlen('ssl/certificates/'));
        $parts = explode('/', $rest);
        $id = $parts[0];
        $sub = isset($parts[1]) ? $parts[1] : '';
        if ($sub === 'renew') {
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                return;
            }
            gojs_api_ssl_acme_cert_renew($id);
        } elseif ($sub === 'download-pem') {
            if ($method !== 'POST') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                return;
            }
            gojs_api_ssl_acme_cert_download_pem($id);
        } elseif ($sub === 'auto-renew') {
            if ($method !== 'PATCH') {
                gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
                return;
            }
            gojs_api_ssl_acme_cert_auto_renew($id);
        } else {
            if ($method === 'DELETE') { gojs_api_ssl_acme_cert_delete($id); }
            elseif ($method === 'PATCH') { gojs_api_ssl_acme_cert_auto_renew($id); }
            else { gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405); }
        }
    });

    return $r;
}

