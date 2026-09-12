<?php

function gojs_invitations_path() {
    return CONFIG_DIR . '/invitations.json';
}

function gojs_invitations_ttl() {
    return 24 * 3600;
}

function gojs_invitations_load() {
    $path = gojs_invitations_path();
    if (!file_exists($path)) {
        return array('invitations' => array());
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return array('invitations' => array());
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array('invitations' => array());
    }
    if (!isset($decoded['invitations']) || !is_array($decoded['invitations'])) {
        $decoded['invitations'] = array();
    }
    return $decoded;
}

function gojs_invitations_save($store) {
    $path = gojs_invitations_path();
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

function gojs_invitations_hash($plain) {
    return hash('sha256', (string)$plain);
}

function gojs_invitations_effective_status($inv, $now = null) {
    if (!is_array($inv)) return 'expired';
    $now = $now === null ? time() : $now;
    $status = isset($inv['status']) ? $inv['status'] : 'pending';
    if ($status === 'accepted' || $status === 'revoked') return $status;
    if (!empty($inv['expires_at']) && (int)$inv['expires_at'] < $now) return 'expired';
    return 'pending';
}

function gojs_invitations_prune(&$store) {
    $now = time();
    $changed = false;
    foreach ($store['invitations'] as $i => $inv) {
        if (gojs_invitations_effective_status($inv, $now) === 'expired'
            && (isset($inv['status']) ? $inv['status'] : '') === 'pending') {
            $store['invitations'][$i]['status'] = 'expired';
            $changed = true;
        }
    }
    if ($changed) {
        gojs_invitations_save($store);
    }
    return $store;
}

function gojs_invitations_sanitize($inv) {
    if (!is_array($inv)) return null;
    unset($inv['token_hash']);
    $inv['status'] = gojs_invitations_effective_status($inv);
    return $inv;
}

function gojs_invitations_find($id) {
    $store = gojs_invitations_load();
    foreach ($store['invitations'] as $inv) {
        if (isset($inv['id']) && $inv['id'] === $id) {
            return $inv;
        }
    }
    return null;
}

function gojs_invitations_find_by_plain($plain) {
    if (!is_string($plain) || $plain === '') return null;
    $hash = gojs_invitations_hash($plain);
    $store = gojs_invitations_load();
    foreach ($store['invitations'] as $inv) {
        if (empty($inv['token_hash'])) continue;
        if (hash_equals($inv['token_hash'], $hash)) {
            return $inv;
        }
    }
    return null;
}

function gojs_invitations_normalize_paths($paths) {
    if (!is_array($paths)) return array();
    $out = array();
    foreach ($paths as $p) {
        if (!is_string($p)) continue;
        $p = trim($p);
        if ($p === '') continue;
        $out[] = $p;
    }
    return array_values(array_unique($out));
}

function gojs_invitations_normalize_groups($ids) {
    if (!is_array($ids)) return array();
    $out = array();
    foreach ($ids as $i) {
        if (!is_string($i) || $i === '') continue;
        $out[] = $i;
    }
    return array_values(array_unique($out));
}

function gojs_invitations_create($email, $role, $path_allowlist = array(), $groups = array(), $message = '', $created_by = null) {
    $email = trim((string)$email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return array('ok' => false, 'code' => 'invalid_email');
    }
    if (!in_array($role, array('admin', 'operator', 'viewer'), true)) {
        return array('ok' => false, 'code' => 'invalid_role');
    }

    $store = gojs_invitations_load();
    gojs_invitations_prune($store);
    foreach ($store['invitations'] as $inv) {
        if (gojs_invitations_effective_status($inv) === 'pending'
            && isset($inv['email']) && strcasecmp($inv['email'], $email) === 0) {
            return array('ok' => false, 'code' => 'already_pending');
        }
    }

    $plain = bin2hex(random_bytes(24));
    $now = time();
    $inv = array(
        'id' => 'inv_' . bin2hex(random_bytes(6)),
        'email' => $email,
        'role' => $role,
        'path_allowlist' => gojs_invitations_normalize_paths($path_allowlist),
        'groups' => gojs_invitations_normalize_groups($groups),
        'message' => trim((string)$message),
        'token_hash' => gojs_invitations_hash($plain),
        'token_prefix' => substr($plain, 0, 10),
        'expires_at' => $now + gojs_invitations_ttl(),
        'status' => 'pending',
        'created_at' => $now,
        'created_by' => $created_by,
        'accepted_at' => 0,
        'accepted_user_id' => null,
    );
    $store['invitations'][] = $inv;
    if (!gojs_invitations_save($store)) {
        return array('ok' => false, 'code' => 'write_failed');
    }
    $inv['token'] = $plain;
    $inv['invite_url'] = gojs_invitations_public_url($plain);
    return array('ok' => true, 'invitation' => $inv);
}

function gojs_invitations_public_url($plain) {
    $base = '/gojs/invite/';
    if (defined('PANEL_ROOT') && is_string(PANEL_ROOT)) {
        $root = rtrim(PANEL_ROOT, '/');
        if ($root !== '' && substr($root, 0, 1) === '/' && strpos($root, ':') === false) {
            $base = $root . '/invite/';
        }
    }
    return $base . rawurlencode($plain);
}

function gojs_invitations_list() {
    $store = gojs_invitations_load();
    gojs_invitations_prune($store);
    $out = array();
    foreach ($store['invitations'] as $inv) {
        $out[] = gojs_invitations_sanitize($inv);
    }
    usort($out, function ($a, $b) {
        return (int)$b['created_at'] - (int)$a['created_at'];
    });
    return $out;
}

function gojs_invitations_revoke($id) {
    $store = gojs_invitations_load();
    foreach ($store['invitations'] as $i => $inv) {
        if (!isset($inv['id']) || $inv['id'] !== $id) continue;
        if (gojs_invitations_effective_status($inv) !== 'pending') {
            return array('ok' => false, 'code' => 'not_pending');
        }
        $store['invitations'][$i]['status'] = 'revoked';
        $store['invitations'][$i]['revoked_at'] = time();
        if (!gojs_invitations_save($store)) {
            return array('ok' => false, 'code' => 'write_failed');
        }
        return array('ok' => true);
    }
    return array('ok' => false, 'code' => 'not_found');
}

function gojs_invitations_username_from_email($email) {
    $local = strstr((string)$email, '@', true);
    if ($local === false || $local === '') $local = (string)$email;
    $local = preg_replace('/[^A-Za-z0-9_.-]/', '', $local);
    $local = trim($local, '._-');
    return $local === '' ? 'invitee' : $local;
}

function gojs_invitations_accept($plain, $username, $password) {
    $inv = gojs_invitations_find_by_plain($plain);
    if (!$inv) {
        return array('ok' => false, 'code' => 'invite_not_found', 'status' => 404);
    }
    $status = gojs_invitations_effective_status($inv);
    if ($status === 'expired') {
        return array('ok' => false, 'code' => 'invite_expired', 'status' => 410);
    }
    if ($status === 'revoked') {
        return array('ok' => false, 'code' => 'invite_revoked', 'status' => 410);
    }
    if ($status !== 'pending') {
        return array('ok' => false, 'code' => 'invite_used', 'status' => 409);
    }

    $username = trim((string)$username);
    if ($username === '') {
        $username = gojs_invitations_username_from_email($inv['email']);
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
        return array('ok' => false, 'code' => 'invalid_username', 'status' => 400);
    }
    if (function_exists('gojs_users_find') && gojs_users_find($username)) {
        return array('ok' => false, 'code' => 'username_exists', 'status' => 409);
    }
    $policy = function_exists('gojs_users_password_check_policy') ? gojs_users_password_check_policy($password) : true;
    if ($policy !== true) {
        return array('ok' => false, 'code' => 'weak_password', 'status' => 400);
    }

    $now = time();
    $id = 'u_' . bin2hex(random_bytes(6));
    $new_user = array(
        'id' => $id,
        'username' => $username,
        'password_hash' => password_hash((string)$password, PASSWORD_BCRYPT),
        'role' => $inv['role'],
        'path_allowlist' => isset($inv['path_allowlist']) && is_array($inv['path_allowlist']) ? $inv['path_allowlist'] : array(),
        'permissions_boost' => array(),
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
    $store['users'][] = $new_user;
    if (!gojs_users_save($store)) {
        return array('ok' => false, 'code' => 'write_failed', 'status' => 500);
    }

    if (!empty($inv['groups']) && function_exists('gojs_groups_set_members')) {
        foreach ($inv['groups'] as $gid) {
            gojs_groups_set_members($gid, array($id), array());
        }
    }

    $istore = gojs_invitations_load();
    foreach ($istore['invitations'] as $i => $row) {
        if (isset($row['id']) && $row['id'] === $inv['id']) {
            $istore['invitations'][$i]['status'] = 'accepted';
            $istore['invitations'][$i]['accepted_at'] = $now;
            $istore['invitations'][$i]['accepted_user_id'] = $id;
            break;
        }
    }
    gojs_invitations_save($istore);

    if (function_exists('gojs_log_operation')) {
        gojs_log_operation('user.invited', $id, true, $inv['email'], $id);
    }

    return array('ok' => true, 'user_id' => $id, 'username' => $username, 'role' => $inv['role']);
}

function gojs_api_invitations_list() {
    $rows = gojs_invitations_list();
    gojs_json_response(array('invitations' => $rows, 'total' => count($rows)));
}

function gojs_api_invitations_create() {
    $body = gojs_get_body();
    $result = gojs_invitations_create(
        isset($body['email']) ? $body['email'] : '',
        isset($body['role']) ? $body['role'] : 'viewer',
        isset($body['path_allowlist']) ? $body['path_allowlist'] : array(),
        isset($body['groups']) ? $body['groups'] : array(),
        isset($body['message']) ? $body['message'] : '',
        function_exists('gojs_current_user_id') ? gojs_current_user_id() : null
    );
    if (empty($result['ok'])) {
        $status = $result['code'] === 'already_pending' ? 409 : 400;
        gojs_json_response(null, array('code' => $result['code'], 'message' => 'Failed to create the invitation'), $status);
    }
    gojs_log_operation('invitation.create', $result['invitation']['id'], true);
    gojs_json_response($result['invitation'], null, 201);
}

function gojs_api_invitations_revoke($id) {
    $result = gojs_invitations_revoke($id);
    if (empty($result['ok'])) {
        $status = $result['code'] === 'not_found' ? 404 : 409;
        gojs_json_response(null, array('code' => $result['code'], 'message' => 'Failed to revoke the invitation'), $status);
    }
    gojs_log_operation('invitation.revoke', $id, true);
    gojs_json_response(array('success' => true));
}

function gojs_api_invitations_preview() {
    $token = gojs_get_param('token', '');
    $inv = gojs_invitations_find_by_plain(is_string($token) ? $token : '');
    if (!$inv) {
        gojs_json_response(null, array('code' => 'invite_not_found', 'message' => 'Invalid invitation link'), 404);
    }
    $status = gojs_invitations_effective_status($inv);
    $email = isset($inv['email']) ? $inv['email'] : '';
    $masked = $email;
    if (strpos($email, '@') !== false) {
        list($local, $domain) = explode('@', $email, 2);
        $head = substr($local, 0, 1);
        $masked = $head . str_repeat('*', max(1, strlen($local) - 1)) . '@' . $domain;
    }
    gojs_json_response(array(
        'status' => $status,
        'role' => isset($inv['role']) ? $inv['role'] : 'viewer',
        'email_masked' => $masked,
        'expires_at' => isset($inv['expires_at']) ? (int)$inv['expires_at'] : 0,
        'suggested_username' => gojs_invitations_username_from_email($email),
    ));
}

function gojs_api_invitations_accept() {
    $body = gojs_get_body();
    $token = isset($body['token']) ? (string)$body['token'] : '';
    $username = isset($body['username']) ? (string)$body['username'] : '';
    $password = isset($body['password']) ? (string)$body['password'] : '';
    if ($token === '') {
        gojs_json_response(null, array('code' => 'invalid_token', 'message' => 'Invalid invitation link'), 400);
    }
    if ($password === '') {
        gojs_json_response(null, array('code' => 'invalid_password', 'message' => 'Please provide a password'), 400);
    }
    $result = gojs_invitations_accept($token, $username, $password);
    if (empty($result['ok'])) {
        $status = isset($result['status']) ? (int)$result['status'] : 400;
        gojs_json_response(null, array('code' => $result['code'], 'message' => 'Activation failed'), $status);
    }
    gojs_json_response(array(
        'success' => true,
        'username' => $result['username'],
        'role' => $result['role'],
    ), null, 201);
}

function gojs_api_invitations_route($api, $method) {
    if ($api === 'invitations/preview') {
        if ($method !== 'GET') {
            gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => 'Method not allowed'), 405);
        }
        gojs_api_invitations_preview();
        return;
    }
    if ($api === 'invitations/accept') {
        if ($method !== 'POST') {
            gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => 'Method not allowed'), 405);
        }
        gojs_api_invitations_accept();
        return;
    }
    if (preg_match('#^invitations/([A-Za-z0-9_]+)$#', $api, $m)) {
        if ($method !== 'DELETE') {
            gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => 'Method not allowed'), 405);
        }
        gojs_api_invitations_revoke($m[1]);
        return;
    }
    if ($api === 'invitations') {
        if ($method === 'GET') gojs_api_invitations_list();
        elseif ($method === 'POST') gojs_api_invitations_create();
        else gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => 'Method not allowed'), 405);
        return;
    }
    gojs_json_response(null, array('code' => 'not_found', 'message' => 'Unknown API action'), 404);
}
