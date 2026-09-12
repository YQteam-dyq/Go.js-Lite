<?php

function gojs_api_users_sanitize($u) {
    if (!is_array($u)) return null;
    unset($u['password_hash']);
    unset($u['totp']);
    return $u;
}

function gojs_api_users_list() {
    if (!function_exists('gojs_users_ensure_default')) {
        gojs_json_response(null, array('code' => 'internal_error', 'message' => 'users.php not loaded'), 500);
    }
    $store = gojs_users_ensure_default();
    $out = array();
    foreach ($store['users'] as $u) {
        $out[] = gojs_api_users_sanitize($u);
    }
    gojs_json_response(array('users' => $out, 'total' => count($out)));
}

function gojs_api_users_create() {
    $body = gojs_get_body();
    $username = isset($body['username']) ? trim((string)$body['username']) : '';
    $password = isset($body['password']) ? (string)$body['password'] : '';
    $role = isset($body['role']) ? trim((string)$body['role']) : 'viewer';
    $path_allowlist = isset($body['path_allowlist']) && is_array($body['path_allowlist']) ? $body['path_allowlist'] : array();

    if ($username === '') {
        gojs_json_response(null, array('code' => 'invalid_username', 'message' => 'Username is required'), 400);
    }
    if (!in_array($role, array('admin', 'operator', 'viewer'), true)) {
        gojs_json_response(null, array('code' => 'invalid_role', 'message' => 'Unknown role'), 400);
    }
    $policy = function_exists('gojs_users_password_check_policy') ? gojs_users_password_check_policy($password) : true;
    if ($policy !== true) {
        gojs_json_response(null, array('code' => 'weak_password', 'message' => 'Password is too weak: ' . $policy), 400);
    }

    $id = 'u_' . bin2hex(random_bytes(6));
    $now = time();
    $new_user = array(
        'id' => $id,
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'role' => $role,
        'path_allowlist' => array_values(array_filter($path_allowlist, 'is_string')),
        'permissions_boost' => isset($body['permissions_boost']) && is_array($body['permissions_boost']) ? array_values(array_filter($body['permissions_boost'], 'is_string')) : array(),
        'disabled' => false,
        'created_at' => $now,
        'last_login_at' => 0,
        'password_changed_at' => $now,
        'password_expires_at' => $now + 90 * 86400,
        'failed_attempts' => 0,
        'lockout_until' => 0,
        'avatar_color' => function_exists('gojs_users_avatar_color') ? gojs_users_avatar_color($username) : '#3b82f6',
    );

    $store = gojs_users_load();
    foreach ($store['users'] as $u) {
        if ($u['username'] === $username) {
            gojs_json_response(null, array('code' => 'username_exists', 'message' => 'Username already exists'), 409);
        }
    }
    $store['users'][] = $new_user;
    if (!gojs_users_save($store)) {
        gojs_json_response(null, array('code' => 'write_failed', 'message' => 'Failed to write users.json'), 500);
    }
    gojs_log_operation('user.create', $id, true);
    gojs_json_response(gojs_api_users_sanitize($new_user), null, 201);
}

function gojs_api_users_update($id) {
    $body = gojs_get_body();
    $store = gojs_users_load();
    $found = false;
    foreach ($store['users'] as $i => $u) {
        if ($u['id'] === $id) {
            $found = true;
            $currentRole = $u['role'];

            if (!empty($body['role']) && $body['role'] !== $currentRole && $id === gojs_current_user_id()) {
                gojs_json_response(null, array('code' => 'cannot_change_own_role', 'message' => 'You cannot change your own role'), 409);
            }
            if (array_key_exists('disabled', $body) && !empty($body['disabled']) && $id === gojs_current_user_id()) {
                gojs_json_response(null, array('code' => 'cannot_disable_own', 'message' => 'You cannot disable yourself'), 409);
            }

            if (!empty($body['role']))            $store['users'][$i]['role'] = $body['role'];
            if (!empty($body['username']))        $store['users'][$i]['username'] = $body['username'];
            if (isset($body['path_allowlist']))   $store['users'][$i]['path_allowlist'] = is_array($body['path_allowlist']) ? $body['path_allowlist'] : array();
            if (isset($body['permissions_boost'])) $store['users'][$i]['permissions_boost'] = is_array($body['permissions_boost']) ? array_values(array_filter($body['permissions_boost'], 'is_string')) : array();
            if (array_key_exists('disabled', $body)) $store['users'][$i]['disabled'] = (bool)$body['disabled'];
            if (!empty($body['password'])) {
                $pw = (string)$body['password'];
                $policy = function_exists('gojs_users_password_check_policy') ? gojs_users_password_check_policy($pw) : true;
                if ($policy !== true) {
                    gojs_json_response(null, array('code' => 'weak_password', 'message' => 'Password is too weak: ' . $policy), 400);
                }
                $store['users'][$i]['password_hash'] = password_hash($pw, PASSWORD_BCRYPT);
                $store['users'][$i]['password_changed_at'] = time();
                $store['users'][$i]['password_expires_at'] = time() + 90 * 86400;
                $store['users'][$i]['failed_attempts'] = 0;
                $store['users'][$i]['lockout_until'] = 0;
            }
            break;
        }
    }
    if (!$found) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'User not found'), 404);
    }
    if (!gojs_users_save($store)) {
        gojs_json_response(null, array('code' => 'write_failed', 'message' => 'Write failed'), 500);
    }
    gojs_log_operation('user.update', $id, true);
    $row = null;
    foreach ($store['users'] as $u) if ($u['id'] === $id) { $row = $u; break; }
    gojs_json_response(gojs_api_users_sanitize($row));
}

function gojs_api_users_delete($id) {
    $store = gojs_users_load();
    $target = null;
    foreach ($store['users'] as $u) if ($u['id'] === $id) { $target = $u; break; }
    if (!$target) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'User not found'), 404);
    }
    if (($target['role'] ?? '') === 'admin') {
        $adminCount = 0;
        foreach ($store['users'] as $u) if (($u['role'] ?? '') === 'admin') $adminCount++;
        if ($adminCount <= 1) {
            gojs_json_response(null, array('code' => 'cannot_delete_last_admin', 'message' => 'At least one admin must remain'), 409);
        }
    }
    $out = array();
    foreach ($store['users'] as $u) if ($u['id'] !== $id) $out[] = $u;
    $store['users'] = $out;
    if (!gojs_users_save($store)) {
        gojs_json_response(null, array('code' => 'write_failed', 'message' => 'Write failed'), 500);
    }
    gojs_log_operation('user.delete', $id, true);
    gojs_json_response(array('success' => true));
}

function gojs_sessions_fingerprint($sid) {
    $sid = (string)$sid;
    if ($sid === '') return '';
    return substr(hash('sha256', $sid), 0, 8);
}

function gojs_sessions_store_dir() {
    if (isset($GLOBALS['gojs_sessions_dir_override']) && is_string($GLOBALS['gojs_sessions_dir_override']) && $GLOBALS['gojs_sessions_dir_override'] !== '') {
        return $GLOBALS['gojs_sessions_dir_override'];
    }
    $path = ini_get('session.save_path');
    if (is_string($path) && $path !== '') {
        if (strpos($path, ';') !== false) {
            $parts = explode(';', $path);
            $path = trim($parts[count($parts) - 1]);
        }
        if ($path !== '' && is_dir($path)) return $path;
    }
    if (function_exists('session_save_path')) {
        $p = @session_save_path();
        if (is_string($p) && $p !== '' && is_dir($p)) return $p;
    }
    $tmp = sys_get_temp_dir();
    return is_dir($tmp) ? $tmp : '';
}

function gojs_sessions_parse_session_raw($raw) {
    $keys = array('authenticated', 'access_token_valid', 'user_id', 'username', 'user_role', 'login_at', 'last_activity', 'login_ip', 'login_ua');
    $out = array();
    foreach ($keys as $key) {
        if (!preg_match('/(?:^|;)' . preg_quote($key, '/') . '\|(?:(b:[01];)|(i:-?\d+;)|(s:\d+:"(?:[^"\\\\]|\\\\.)*";))/', $raw, $m)) {
            continue;
        }
        if (!empty($m[1])) {
            $out[$key] = ($m[1] === 'b:1;');
        } elseif (!empty($m[2])) {
            $out[$key] = (int)substr($m[2], 2, -1);
        } elseif (!empty($m[3])) {
            $inner = substr($m[3], 2, -2);
            $colon = strpos($inner, ':');
            $len = $colon === false ? 0 : (int)substr($inner, 0, $colon);
            $out[$key] = $colon === false ? '' : substr($inner, $colon + 2, $len);
        }
    }
    return $out;
}

function gojs_sessions_decode_file($file) {
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') return array();
    if (session_status() === PHP_SESSION_ACTIVE) {
        $backup = $_SESSION;
        $_SESSION = array();
        $ok = @session_decode($raw);
        $data = $_SESSION;
        $_SESSION = is_array($backup) ? $backup : array();
        if ($ok) return $data;
    }
    return gojs_sessions_parse_session_raw($raw);
}

function gojs_sessions_collect() {
    $dir = gojs_sessions_store_dir();
    $out = array();
    if ($dir === '' || !is_dir($dir)) return $out;
    $files = @glob(rtrim($dir, '/\\') . '/sess_*');
    if (!is_array($files)) return $out;
    $now = time();
    $gc = (int)ini_get('session.gc_maxlifetime');
    if ($gc <= 0) $gc = 1440;
    foreach ($files as $file) {
        $sid = substr(basename($file), 5);
        if ($sid === '') continue;
        $mtime = @filemtime($file);
        if ($mtime !== false && ($now - $mtime) > $gc + 3600) continue;
        $payload = gojs_sessions_decode_file($file);
        if (empty($payload['authenticated']) && empty($payload['access_token_valid'])) continue;
        $out[] = array('sid' => $sid, 'mtime' => ($mtime !== false ? $mtime : $now), 'payload' => $payload);
    }
    return $out;
}

function gojs_sessions_row($sid, $payload, $currentFp, $mtime = 0) {
    $fp = gojs_sessions_fingerprint($sid);
    $userId = isset($payload['user_id']) && $payload['user_id'] !== '' ? $payload['user_id'] : 'admin';
    $username = isset($payload['username']) && $payload['username'] !== '' ? $payload['username'] : null;
    $role = isset($payload['user_role']) && $payload['user_role'] !== '' ? $payload['user_role'] : null;
    $user = ($userId !== 'admin' && function_exists('gojs_users_find_by_id')) ? gojs_users_find_by_id($userId) : null;
    if ($user) {
        $username = $user['username'];
        $role = isset($user['role']) ? $user['role'] : $role;
    }
    return array(
        'sid' => $fp,
        'current' => ($currentFp !== '' && $fp === $currentFp),
        'user_id' => $userId,
        'username' => $username ?: 'admin',
        'role' => $role ?: 'admin',
        'login_at' => isset($payload['login_at']) ? (int)$payload['login_at'] : (isset($payload['last_activity']) ? (int)$payload['last_activity'] : ($mtime ?: 0)),
        'last_activity_at' => isset($payload['last_activity']) ? (int)$payload['last_activity'] : ($mtime ?: 0),
        'ip' => isset($payload['login_ip']) ? $payload['login_ip'] : '',
        'ua' => isset($payload['login_ua']) ? $payload['login_ua'] : '',
        'token_session' => !empty($payload['access_token_valid']) && empty($payload['authenticated']),
    );
}

function gojs_api_sessions_list() {
    $currentSid = isset($_COOKIE[session_name()]) ? (string)$_COOKIE[session_name()] : '';
    $currentFp = gojs_sessions_fingerprint($currentSid);

    $sessions = array();
    $seen = array();
    foreach (gojs_sessions_collect() as $row) {
        $fp = gojs_sessions_fingerprint($row['sid']);
        if ($fp === '' || isset($seen[$fp])) continue;
        $seen[$fp] = true;
        $sessions[] = gojs_sessions_row($row['sid'], $row['payload'], $currentFp, $row['mtime']);
    }
    if ($currentFp !== '' && !isset($seen[$currentFp])) {
        $sessions[] = gojs_sessions_row($currentSid, $_SESSION, $currentFp);
    }

    usort($sessions, function ($a, $b) {
        return $b['last_activity_at'] <=> $a['last_activity_at'];
    });
    gojs_json_response(array('sessions' => $sessions, 'total' => count($sessions)));
}

function gojs_sessions_revoke_file() {
    return CONFIG_DIR . '/session_revoked.json';
}

function gojs_sessions_revoke_load() {
    $file = gojs_sessions_revoke_file();
    if (!file_exists($file)) return array();
    $raw = @file_get_contents($file);
    if (!$raw) return array();
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function gojs_sessions_revoke_save($revoked) {
    if (!is_dir(CONFIG_DIR)) {
        @mkdir(CONFIG_DIR, 0700, true);
    }
    @file_put_contents(gojs_sessions_revoke_file(), json_encode($revoked), LOCK_EX);
}

function gojs_api_sessions_kick($sidFp = null) {
    if ($sidFp === null) {
        $body = gojs_get_body();
        $sidFp = isset($body['sid']) ? trim((string)$body['sid']) : '';
    }
    $sidFp = trim((string)$sidFp);
    if ($sidFp === '') {
        gojs_json_response(null, array('code' => 'invalid_sid', 'message' => 'sid is required'), 400);
    }

    $currentSid = isset($_COOKIE[session_name()]) ? (string)$_COOKIE[session_name()] : '';
    $currentFp = gojs_sessions_fingerprint($currentSid);
    if ($currentFp !== '' && $sidFp === $currentFp) {
        gojs_json_response(null, array(
            'code' => 'cannot_kick_self',
            'message' => 'You cannot kick your own session; use the "log out everywhere" action instead',
        ), 409);
    }

    $targetSid = null;
    $targetUserId = null;
    foreach (gojs_sessions_collect() as $row) {
        if ($row['sid'] === $sidFp || gojs_sessions_fingerprint($row['sid']) === $sidFp) {
            $targetSid = $row['sid'];
            $targetUserId = isset($row['payload']['user_id']) ? $row['payload']['user_id'] : null;
            break;
        }
    }
    if ($targetSid === null) {
        gojs_json_response(null, array('code' => 'session_not_found', 'message' => 'Session not found or no longer active'), 404);
    }

    $revoked = gojs_sessions_revoke_load();
    $now = time();
    foreach ($revoked as $k => $v) {
        if (!is_array($v) || (isset($v['exp']) && $v['exp'] < $now)) unset($revoked[$k]);
    }
    $revoked[$targetSid] = array('exp' => $now + 8 * 3600, 'user_id' => $targetUserId);
    gojs_sessions_revoke_save($revoked);

    $dir = gojs_sessions_store_dir();
    if ($dir !== '') {
        @unlink(rtrim($dir, '/\\') . '/sess_' . $targetSid);
    }

    gojs_log_operation('session.kick', $sidFp, true, 'user_id=' . var_export($targetUserId, true));
    gojs_json_response(array('success' => true, 'sid' => $sidFp));
}

function gojs_api_logout_all() {
    if (!empty($_COOKIE[session_name()])) {
        $sid = $_COOKIE[session_name()];
        $revoked_file = CONFIG_DIR . '/session_revoked.json';
        $revoked = array();
        if (file_exists($revoked_file)) {
            $raw = @file_get_contents($revoked_file);
            if ($raw) $revoked = json_decode($raw, true);
            if (!is_array($revoked)) $revoked = array();
        }
        $revoked[$sid] = array('exp' => time() + 8 * 3600, 'user_id' => function_exists('gojs_current_user_id') ? gojs_current_user_id() : null);
        @file_put_contents($revoked_file, json_encode($revoked), LOCK_EX);
    }
    session_unset();
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_destroy();
    }
    gojs_json_response(array('success' => true));
}

function gojs_api_users_route($api, $method) {
    if (preg_match('#^users/([A-Za-z0-9_]+)$#', $api, $m)) {
        $id = $m[1];
        if ($method === 'PATCH')  gojs_api_users_update($id);
        elseif ($method === 'DELETE') gojs_api_users_delete($id);
        else gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => 'Method not allowed'), 405);
        return;
    }
    if ($api === 'users') {
        if ($method === 'GET') gojs_api_users_list();
        elseif ($method === 'POST') gojs_api_users_create();
        else gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => 'Method not allowed'), 405);
        return;
    }
    if ($api === 'sessions') {
        if ($method === 'GET') gojs_api_sessions_list();
        else gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => 'Method not allowed'), 405);
        return;
    }
    if (preg_match('#^sessions/([A-Za-z0-9_\-]+)/kick$#', $api, $m)) {
        if ($method === 'POST') gojs_api_sessions_kick($m[1]);
        else gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => 'Method not allowed'), 405);
        return;
    }
    if ($api === 'sessions/kick') {
        if ($method === 'POST') gojs_api_sessions_kick();
        else gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => 'Method not allowed'), 405);
        return;
    }
    if ($api === 'logout-all') {
        if ($method === 'POST') gojs_api_logout_all();
        else gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => 'Method not allowed'), 405);
        return;
    }
    gojs_json_response(null, array('code' => 'not_found', 'message' => 'Unknown API action'), 404);
}
