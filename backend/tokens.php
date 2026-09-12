<?php

function gojs_tokens_path() {
    return CONFIG_DIR . '/api_tokens.json';
}

function gojs_tokens_load() {
    $path = gojs_tokens_path();
    if (!file_exists($path)) {
        return array('tokens' => array());
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return array('tokens' => array());
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array('tokens' => array());
    }
    if (!isset($decoded['tokens']) || !is_array($decoded['tokens'])) {
        $decoded['tokens'] = array();
    }
    return $decoded;
}

function gojs_tokens_save($store) {
    $path = gojs_tokens_path();
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

function gojs_tokens_hash($plain) {
    return hash('sha256', (string)$plain);
}

function gojs_tokens_sanitize($t) {
    if (!is_array($t)) return null;
    unset($t['token_hash']);
    return $t;
}

function gojs_tokens_scopes_from_role($role) {
    if ($role === 'admin') {
        return array('admin');
    }
    if ($role === 'operator') {
        return array('readonly', 'user-self');
    }
    return array('readonly');
}

function gojs_tokens_create($name, $scopes, $path_prefix, $rate_limit, $expires_at, $created_by, $created_by_role) {
    $name = trim((string)$name);
    if ($name === '') {
        return array('ok' => false, 'code' => 'invalid_name');
    }
    if (!is_array($scopes) || empty($scopes)) {
        return array('ok' => false, 'code' => 'invalid_scopes');
    }
    $scopes = array_values(array_filter($scopes, 'is_string'));
    if (empty($scopes)) {
        return array('ok' => false, 'code' => 'invalid_scopes');
    }
    if ($created_by_role !== 'admin') {
        $allowed = gojs_tokens_scopes_from_role($created_by_role);
        foreach ($scopes as $s) {
            if (!in_array($s, $allowed, true)) {
                return array('ok' => false, 'code' => 'scope_not_allowed');
            }
        }
    }

    $plain = 'gojs_' . bin2hex(random_bytes(24));
    $now = time();
    $token = array(
        'id' => 'tok_' . bin2hex(random_bytes(6)),
        'name' => $name,
        'token_hash' => gojs_tokens_hash($plain),
        'token_prefix' => substr($plain, 0, 12),
        'scopes' => $scopes,
        'path_prefix' => is_string($path_prefix) ? trim($path_prefix) : '',
        'rate_limit_per_min' => (int)$rate_limit > 0 ? (int)$rate_limit : 60,
        'expires_at' => (int)$expires_at > 0 ? (int)$expires_at : 0,
        'created_by' => $created_by,
        'created_at' => $now,
        'last_used_at' => 0,
        'revoked' => false,
    );

    $store = gojs_tokens_load();
    $store['tokens'][] = $token;
    if (!gojs_tokens_save($store)) {
        return array('ok' => false, 'code' => 'write_failed');
    }
    $token['token_plain_once'] = $plain;
    return array('ok' => true, 'token' => $token);
}

function gojs_tokens_list($created_by = null) {
    $store = gojs_tokens_load();
    $out = array();
    foreach ($store['tokens'] as $t) {
        if ($created_by !== null && (!isset($t['created_by']) || $t['created_by'] !== $created_by)) {
            continue;
        }
        $out[] = gojs_tokens_sanitize($t);
    }
    return $out;
}

function gojs_tokens_find($id) {
    $store = gojs_tokens_load();
    foreach ($store['tokens'] as $t) {
        if (isset($t['id']) && $t['id'] === $id) {
            return $t;
        }
    }
    return null;
}

function gojs_tokens_find_by_plain($plain) {
    if (!is_string($plain) || $plain === '') return null;
    $hash = gojs_tokens_hash($plain);
    $store = gojs_tokens_load();
    foreach ($store['tokens'] as $t) {
        if (empty($t['token_hash'])) continue;
        if (hash_equals($t['token_hash'], $hash)) {
            return $t;
        }
    }
    return null;
}

function gojs_tokens_revoke($id) {
    $store = gojs_tokens_load();
    foreach ($store['tokens'] as $i => $t) {
        if (!isset($t['id']) || $t['id'] !== $id) continue;
        $store['tokens'][$i]['revoked'] = true;
        $store['tokens'][$i]['revoked_at'] = time();
        if (!gojs_tokens_save($store)) {
            return array('ok' => false, 'code' => 'write_failed');
        }
        return array('ok' => true);
    }
    return array('ok' => false, 'code' => 'not_found');
}

function gojs_request_bearer_token() {
    $header = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    } elseif (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strtolower($k) === 'authorization') { $header = $v; break; }
            }
        }
    }
    if (!is_string($header) || $header === '') return null;
    if (stripos($header, 'Bearer ') !== 0) return null;
    $token = trim(substr($header, 7));
    return $token === '' ? null : $token;
}

function gojs_token_authenticate($plain) {
    $token = gojs_tokens_find_by_plain($plain);
    if (!$token) {
        gojs_json_response(null, array('code' => 'invalid_token', 'message' => 'Invalid API token'), 401);
    }
    if (!empty($token['revoked'])) {
        gojs_json_response(null, array('code' => 'token_revoked', 'message' => 'The API token has been revoked'), 401);
    }
    if (!empty($token['expires_at']) && (int)$token['expires_at'] < time()) {
        gojs_json_response(null, array('code' => 'token_expired', 'message' => 'The API token has expired'), 401);
    }

    $user = null;
    if (function_exists('gojs_users_find_by_id')) {
        $user = gojs_users_find_by_id($token['created_by']);
    }
    if ($user && !empty($user['disabled'])) {
        gojs_json_response(null, array('code' => 'token_user_disabled', 'message' => 'The user that owns this token is disabled'), 401);
    }

    $_SESSION['authenticated'] = true;
    $_SESSION['user_id'] = $token['created_by'];
    $_SESSION['api_token_active'] = true;
    $_SESSION['api_token_id'] = $token['id'];
    $_SESSION['api_token_scopes'] = isset($token['scopes']) && is_array($token['scopes']) ? $token['scopes'] : array();
    $_SESSION['api_token_prefix'] = isset($token['path_prefix']) ? $token['path_prefix'] : '';
    $_SESSION['last_activity'] = time();

    $store = gojs_tokens_load();
    foreach ($store['tokens'] as $i => $t) {
        if (isset($t['id']) && $t['id'] === $token['id']) {
            $store['tokens'][$i]['last_used_at'] = time();
            gojs_tokens_save($store);
            break;
        }
    }
}

function gojs_token_scope_allows($scopes, $api, $method) {
    if (!is_array($scopes)) return false;
    if (in_array('admin', $scopes, true)) return true;

    $is_read = in_array(strtoupper($method), array('GET', 'HEAD'), true);

    if (in_array('readonly', $scopes, true) && $is_read) return true;
    if (in_array('read', $scopes, true) && $is_read) return true;
    if (in_array('user-self', $scopes, true) && strpos($api, 'profile') === 0) return true;

    foreach ($scopes as $s) {
        if (!preg_match('#^([a-z0-9_/-]+)\.(read|write)$#', $s, $m)) continue;
        $target = $m[1];
        $matches = ($api === $target || strpos($api, $target . '/') === 0);
        if (!$matches) continue;
        if ($m[2] === 'read' && $is_read) return true;
        if ($m[2] === 'write' && !$is_read) return true;
    }
    return false;
}

function gojs_token_rate_limit_ok($token) {
    $limit = isset($token['rate_limit_per_min']) ? (int)$token['rate_limit_per_min'] : 60;
    if ($limit <= 0) return true;

    $dir = CONFIG_DIR . '/token_rl';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $bucket = (int)floor(time() / 60);
    $file = $dir . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $token['id']) . '_' . $bucket . '.json';

    $count = 0;
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        $d = $raw ? json_decode($raw, true) : null;
        if (is_array($d) && isset($d['count'])) $count = (int)$d['count'];
    }
    if ($count >= $limit) {
        return false;
    }
    @file_put_contents($file, json_encode(array('count' => $count + 1)), LOCK_EX);

    foreach ((array)glob($dir . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $token['id']) . '_*.json') as $f) {
        if (preg_match('/_(\d+)\.json$/', $f, $mm) && (int)$mm[1] < $bucket - 1) {
            @unlink($f);
        }
    }
    return true;
}

function gojs_api_tokens_v2_list() {
    $role = function_exists('gojs_current_role') ? gojs_current_role() : null;
    $uid = gojs_current_user_id();
    $created_by = ($role === 'admin') ? null : $uid;
    $rows = gojs_tokens_list($created_by);
    gojs_json_response(array('tokens' => $rows, 'total' => count($rows)));
}

function gojs_api_tokens_v2_create() {
    $body = gojs_get_body();
    $role = function_exists('gojs_current_role') ? gojs_current_role() : 'admin';
    $uid = gojs_current_user_id();
    $expires_at = 0;
    if (!empty($body['expires_at'])) {
        $expires_at = is_numeric($body['expires_at']) ? (int)$body['expires_at'] : strtotime((string)$body['expires_at']);
        if (!$expires_at || $expires_at < time()) {
            gojs_json_response(null, array('code' => 'invalid_expires_at', 'message' => 'Invalid expiration time'), 400);
        }
    }
    $result = gojs_tokens_create(
        isset($body['name']) ? $body['name'] : '',
        isset($body['scopes']) ? $body['scopes'] : array(),
        isset($body['path_prefix']) ? $body['path_prefix'] : '',
        isset($body['rate_limit_per_min']) ? $body['rate_limit_per_min'] : 60,
        $expires_at,
        $uid,
        $role
    );
    if (empty($result['ok'])) {
        $status = $result['code'] === 'scope_not_allowed' ? 403 : 400;
        gojs_json_response(null, array('code' => $result['code'], 'message' => 'Failed to create the token'), $status);
    }
    gojs_log_operation('token.create', $result['token']['id'], true);
    gojs_json_response($result['token'], null, 201);
}

function gojs_api_tokens_v2_revoke($id) {
    $token = gojs_tokens_find($id);
    if (!$token) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'Token not found'), 404);
    }
    $role = function_exists('gojs_current_role') ? gojs_current_role() : null;
    if ($role !== 'admin' && $token['created_by'] !== gojs_current_user_id()) {
        gojs_json_response(null, array('code' => 'insufficient_role', 'message' => 'Not allowed to revoke this token'), 403);
    }
    $result = gojs_tokens_revoke($id);
    if (empty($result['ok'])) {
        gojs_json_response(null, array('code' => $result['code'], 'message' => 'Revocation failed'), 404);
    }
    gojs_log_operation('token.revoke', $id, true);
    gojs_json_response(array('success' => true));
}

function gojs_api_tokens_v2_route($api, $method) {
    if (preg_match('#^tokens/([A-Za-z0-9_]+)$#', $api, $m)) {
        if ($method !== 'DELETE') {
            gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => 'Method not allowed'), 405);
        }
        gojs_api_tokens_v2_revoke($m[1]);
        return;
    }
    if ($api === 'tokens') {
        if ($method === 'GET') gojs_api_tokens_v2_list();
        elseif ($method === 'POST') gojs_api_tokens_v2_create();
        else gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => 'Method not allowed'), 405);
        return;
    }
    gojs_json_response(null, array('code' => 'not_found', 'message' => 'Unknown API action'), 404);
}
