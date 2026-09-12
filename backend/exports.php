<?php

function gojs_exports_dir() {
    return CONFIG_DIR . '/exports';
}

function gojs_exports_index_path() {
    return CONFIG_DIR . '/exports.json';
}

function gojs_exports_ttl() {
    return 3600;
}

function gojs_exports_retention() {
    return 7 * 86400;
}

function gojs_exports_load() {
    $path = gojs_exports_index_path();
    if (!file_exists($path)) {
        return array('exports' => array());
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return array('exports' => array());
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array('exports' => array());
    }
    if (!isset($decoded['exports']) || !is_array($decoded['exports'])) {
        $decoded['exports'] = array();
    }
    return $decoded;
}

function gojs_exports_save($store) {
    $path = gojs_exports_index_path();
    if (!is_dir(CONFIG_DIR)) {
        @mkdir(CONFIG_DIR, 0700, true);
    }
    $json = json_encode($store, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (@file_put_contents($path, $json, LOCK_EX) === false) {
        return false;
    }
    @chmod($path, 0600);
    return true;
}

function gojs_exports_secret() {
    global $config;
    if (is_array($config)) {
        if (!empty($config['export_secret'])) return (string)$config['export_secret'];
        if (!empty($config['internal_cron_token'])) return (string)$config['internal_cron_token'];
        if (!empty($config['access_token'])) return (string)$config['access_token'];
    }
    $seed = (defined('CONFIG_FILE') ? CONFIG_FILE : 'gojs') . '|exports|' . (defined('VERSION') ? VERSION : '0');
    return hash('sha256', $seed);
}

function gojs_exports_sign($id, $exp) {
    return hash_hmac('sha256', (string)$id . '|' . (int)$exp, gojs_exports_secret());
}

function gojs_exports_signed_query($id, $exp) {
    return 'exp=' . (int)$exp . '&sig=' . gojs_exports_sign($id, $exp);
}

function gojs_exports_zip_stored($entries) {
    $local = '';
    $central = '';
    $offset = 0;
    foreach ($entries as $name => $content) {
        $name = str_replace('\\', '/', (string)$name);
        $content = (string)$content;
        $crc = crc32($content);
        $len = strlen($content);
        $header = "PK\x03\x04"
            . pack('v', 20) . pack('v', 0) . pack('v', 0)
            . pack('v', 0) . pack('v', 0)
            . pack('V', $crc) . pack('V', $len) . pack('V', $len)
            . pack('v', strlen($name)) . pack('v', 0) . $name;
        $local .= $header . $content;
        $central .= "PK\x01\x02"
            . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', 0)
            . pack('v', 0) . pack('v', 0)
            . pack('V', $crc) . pack('V', $len) . pack('V', $len)
            . pack('v', strlen($name)) . pack('v', 0) . pack('v', 0)
            . pack('v', 0) . pack('v', 0) . pack('V', 0) . pack('V', $offset)
            . $name;
        $offset += strlen($header) + $len;
    }
    $count = count($entries);
    $local .= $central;
    $local .= "PK\x05\x06"
        . pack('v', 0) . pack('v', 0) . pack('v', $count) . pack('v', $count)
        . pack('V', strlen($central)) . pack('V', $offset) . pack('v', 0);
    return $local;
}

function gojs_exports_audit_rows($user_id) {
    $log_file = CONFIG_DIR . '/operation_log.json';
    if (!file_exists($log_file)) return array();
    $raw = @file_get_contents($log_file);
    $logs = $raw ? json_decode($raw, true) : null;
    if (!is_array($logs)) return array();
    $out = array();
    $now = time();
    $window = 90 * 86400;
    foreach ($logs as $row) {
        if (!is_array($row)) continue;
        if (!isset($row['user_id']) || $row['user_id'] !== $user_id) continue;
        if ($window > 0 && isset($row['timestamp']) && ((int)$row['timestamp'] < $now - $window)) continue;
        $out[] = $row;
    }
    return $out;
}

function gojs_exports_audit_csv($rows) {
    $fields = array('time', 'timestamp', 'ip', 'user_id', 'action', 'target', 'result', 'detail');
    $lines = array(implode(',', $fields));
    foreach ($rows as $row) {
        $cells = array();
        foreach ($fields as $f) {
            $v = isset($row[$f]) ? $row[$f] : '';
            if (is_bool($v)) $v = $v ? 'true' : 'false';
            $v = str_replace('"', '""', (string)$v);
            $cells[] = '"' . $v . '"';
        }
        $lines[] = implode(',', $cells);
    }
    return implode("\r\n", $lines) . "\r\n";
}

function gojs_exports_sessions_snapshot() {
    $sid = isset($_COOKIE[session_name()]) ? (string)$_COOKIE[session_name()] : '';
    return array(array(
        'sid' => $sid !== '' ? substr(hash('sha256', $sid), 0, 8) : '',
        'ip' => function_exists('gojs_get_client_ip') ? gojs_get_client_ip() : '',
        'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '',
        'login_at' => isset($_SESSION['login_at']) ? (int)$_SESSION['login_at'] : 0,
        'last_activity_at' => isset($_SESSION['last_activity']) ? (int)$_SESSION['last_activity'] : time(),
    ));
}

function gojs_exports_own_tokens($user_id) {
    if (!function_exists('gojs_tokens_list')) return array();
    $rows = array();
    foreach (gojs_tokens_list($user_id) as $t) {
        $rows[] = $t;
    }
    return $rows;
}

function gojs_exports_build_entries($user_id) {
    $user = function_exists('gojs_users_find_by_id') ? gojs_users_find_by_id($user_id) : null;
    $audit = gojs_exports_audit_rows($user_id);
    $prefs = ($user && isset($user['preferences']) && is_array($user['preferences'])) ? $user['preferences'] : array();
    $sessions = gojs_exports_sessions_snapshot();
    $tokens = gojs_exports_own_tokens($user_id);

    return array(
        'audit.' . $user_id . '.json' => json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        'audit.' . $user_id . '.csv' => gojs_exports_audit_csv($audit),
        'preferences.json' => json_encode($prefs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        'sessions.json' => json_encode($sessions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        'tokens.json' => json_encode($tokens, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    );
}

function gojs_exports_prune($now = null) {
    $now = $now === null ? time() : $now;
    $store = gojs_exports_load();
    if (empty($store['exports'])) return 0;
    $keep = array();
    $removed = 0;
    foreach ($store['exports'] as $id => $meta) {
        $created = isset($meta['created_at']) ? (int)$meta['created_at'] : 0;
        if ($created > 0 && $created < $now - gojs_exports_retention()) {
            $file = isset($meta['file']) ? $meta['file'] : '';
            if ($file !== '' && file_exists($file)) @unlink($file);
            $removed++;
            continue;
        }
        $keep[$id] = $meta;
    }
    if ($removed > 0) {
        $store['exports'] = $keep;
        gojs_exports_save($store);
    }
    return $removed;
}

function gojs_exports_create($user_id) {
    if (!$user_id) {
        return array('ok' => false, 'code' => 'unauthorized');
    }
    $dir = gojs_exports_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (!is_dir($dir)) {
        return array('ok' => false, 'code' => 'write_failed');
    }

    gojs_exports_prune();

    $id = 'exp_' . bin2hex(random_bytes(8));
    $file = $dir . '/' . $id . '.zip';
    $zip = gojs_exports_zip_stored(gojs_exports_build_entries($user_id));
    if (@file_put_contents($file, $zip, LOCK_EX) === false) {
        return array('ok' => false, 'code' => 'write_failed');
    }
    @chmod($file, 0600);

    $now = time();
    $exp = $now + gojs_exports_ttl();
    $store = gojs_exports_load();
    $store['exports'][$id] = array(
        'id' => $id,
        'user_id' => $user_id,
        'file' => $file,
        'bytes' => strlen($zip),
        'created_at' => $now,
        'signed_expires_at' => $exp,
        'downloaded_at' => 0,
    );
    if (!gojs_exports_save($store)) {
        @unlink($file);
        return array('ok' => false, 'code' => 'write_failed');
    }

    return array(
        'ok' => true,
        'export_id' => $id,
        'bytes' => strlen($zip),
        'signed_expires_at' => $exp,
        'download_path' => 'profile/export/' . $id . '?' . gojs_exports_signed_query($id, $exp),
    );
}

function gojs_exports_find($id) {
    $store = gojs_exports_load();
    return isset($store['exports'][$id]) ? $store['exports'][$id] : null;
}

function gojs_exports_verify($id, $user_id, $exp, $sig) {
    $meta = gojs_exports_find($id);
    if (!$meta) {
        return array('ok' => false, 'code' => 'export_not_found', 'status' => 404);
    }
    if (isset($meta['user_id']) && $meta['user_id'] !== $user_id) {
        return array('ok' => false, 'code' => 'forbidden', 'status' => 403);
    }
    $exp = (int)$exp;
    if ($exp <= 0 || $exp < time()) {
        return array('ok' => false, 'code' => 'export_link_expired', 'status' => 410);
    }
    $expect = gojs_exports_sign($id, $exp);
    if (!is_string($sig) || $sig === '' || !hash_equals($expect, $sig)) {
        return array('ok' => false, 'code' => 'invalid_signature', 'status' => 403);
    }
    if (empty($meta['file']) || !file_exists($meta['file'])) {
        return array('ok' => false, 'code' => 'export_file_missing', 'status' => 410);
    }
    return array('ok' => true, 'meta' => $meta);
}

function gojs_api_profile_export_create() {
    $uid = function_exists('gojs_current_user_id') ? gojs_current_user_id() : null;
    if (!$uid) {
        gojs_json_response(null, array('code' => 'unauthorized', 'message' => '请先登录'), 401);
    }
    $result = gojs_exports_create($uid);
    if (empty($result['ok'])) {
        gojs_json_response(null, array('code' => $result['code'], 'message' => '导出失败'), 500);
    }
    gojs_log_operation('profile.export', $result['export_id'], true);
    gojs_json_response($result, null, 202);
}

function gojs_api_profile_export_download($id) {
    $uid = function_exists('gojs_current_user_id') ? gojs_current_user_id() : null;
    if (!$uid) {
        gojs_json_response(null, array('code' => 'unauthorized', 'message' => '请先登录'), 401);
    }
    $exp = gojs_get_param('exp', 0);
    $sig = gojs_get_param('sig', '');
    $check = gojs_exports_verify($id, $uid, $exp, is_string($sig) ? $sig : '');
    if (empty($check['ok'])) {
        $status = isset($check['status']) ? (int)$check['status'] : 400;
        gojs_json_response(null, array('code' => $check['code'], 'message' => '导出下载失败'), $status);
    }

    $meta = $check['meta'];
    $store = gojs_exports_load();
    if (isset($store['exports'][$id])) {
        $store['exports'][$id]['downloaded_at'] = time();
        gojs_exports_save($store);
    }

    $filename = 'gojs-export-' . $uid . '-' . date('Ymd_His', (int)$meta['created_at']) . '.zip';
    $bytes = @file_get_contents($meta['file']);
    if ($bytes === false) {
        gojs_json_response(null, array('code' => 'export_file_missing', 'message' => '导出文件已清理'), 410);
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Length: ' . strlen($bytes));
    }
    if (function_exists('gojs_monitor_bump_bandwidth')) {
        gojs_monitor_bump_bandwidth(0, strlen($bytes));
    }
    echo $bytes;
    exit;
}

function gojs_api_profile_export_route($api, $method) {
    if ($api === 'profile/export') {
        if ($method !== 'POST') {
            gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
        }
        gojs_api_profile_export_create();
        return;
    }
    if (preg_match('#^profile/export/([A-Za-z0-9_]+)$#', $api, $m)) {
        if ($method !== 'GET') {
            gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
        }
        gojs_api_profile_export_download($m[1]);
        return;
    }
    gojs_json_response(null, array('code' => 'not_found', 'message' => 'API 不存在'), 404);
}
