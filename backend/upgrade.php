<?php

// Upgrade & deploy: panel self-upgrade, one-click app deployment.
// Split from api.php; keep original function signatures and behavior unchanged.

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

    // fallback: stream context (https)
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
 * Task 8: One-click upgrade wizard
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
            return false; // Considered in-progress within 10 minutes; reject concurrent upgrades.
        }
        @unlink($lock_file); // Expired lock.
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

// Back up the panel's own key files to the upgrade backup directory.
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

// Safely extract the upgrade package to ROOT: skip the top-level gojs/ segment and .gojs/, forbid "..", only regular files/dirs.
function gojs_upgrade_extract_to_root($zip_path) {
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return array('ok' => false, 'error' => '无法打开升级包');
    }
    $count = $zip->numFiles;

    // Guard against wrong packages: the zip must contain api.php.
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
            array_shift($parts); // Drop the top-level gojs/ segment.
        }
        $parts = array_values(array_filter($parts, function ($p) {
            return $p !== '' && $p !== '.';
        }));
        if (count($parts) === 0) continue;

        $rel = implode('/', $parts);
        // Skip the .gojs config directory.
        if ($rel === '.gojs' || strpos($rel, '.gojs/') === 0) continue;
        // Forbid path traversal.
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

        // Atomic write: temp file in same dir + rename (copy fallback on failure).
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

        // Reuse the asset URL from the most recent check within 10 minutes to avoid duplicate requests.
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

        // Back up the current version.
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

        // Extract and overwrite.
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

        // Migration: new code runs automatically on the next request load; no manual trigger needed.
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
 * Task 11: One-click app deployment
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

// Resolve and validate the deployment target directory (relative to files_root).
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
    // When the panel is installed in a subdirectory (files_root/gojs), forbid deploying into the panel directory.
    if ($files_real !== $root_real && ($full === $root_real || strpos($full, $root_real . '/') === 0)) {
        return array('ok' => false, 'error' => '目标目录不能在面板目录内');
    }
    return array('ok' => true, 'full' => $full, 'relative' => $relative);
}

// Extract the app to extract_dir: strip the web-root prefix (shallowest index.php directory segment); extract_dir becomes the web root content.
function gojs_deploy_extract_app($zip_path, $extract_dir) {
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return array('ok' => false, 'error' => '无法打开应用安装包');
    }
    $count = $zip->numFiles;

    // Find the shallowest index.php to determine the web-root prefix.
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
                continue; // Skip files outside the web root (readme, licenses, etc.).
            }
            $parts = array_slice($parts, count($prefix_parts));
        }
        if (count($parts) === 0) continue;
        $rel = implode('/', $parts);
        if (strpos($rel, '..') !== false) continue; // Prevent path traversal.

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

// Write the DB config file; returns whether it was written (skipped if no DB info provided or a config already exists).
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

    // Overwrite=true required when the target already exists and is non-empty.
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

        // DB config (not written when no DB info is provided; the app's own web installer handles it).
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
