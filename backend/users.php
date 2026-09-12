<?php

const GOJS_ROLE_RANK = array(
    'viewer'   => 1,
    'operator' => 2,
    'admin'    => 3,
);

function gojs_users_path() {
    return CONFIG_DIR . '/users.json';
}

function gojs_users_load() {
    $path = gojs_users_path();
    if (!file_exists($path)) {
        return array('users' => array());
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return array('users' => array());
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array('users' => array());
    }
    if (!isset($decoded['users']) || !is_array($decoded['users'])) {
        $decoded['users'] = array();
    }
    return $decoded;
}

function gojs_users_save($store) {
    $path = gojs_users_path();
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

function gojs_users_ensure_default() {
    global $config, $installed;

    $store = gojs_users_load();

    $has_admin = false;
    foreach ($store['users'] as $u) {
        if (isset($u['role']) && $u['role'] === 'admin') { $has_admin = true; break; }
    }
    if ($has_admin) return $store;

    if (!empty($config['password_hash'])) {
        $now = time();
        $username = 'admin';
        $store['users'][] = array(
            'id'             => 'u_' . bin2hex(random_bytes(6)),
            'username'       => $username,
            'password_hash'  => $config['password_hash'],
            'role'           => 'admin',
            'path_allowlist' => array(),
            'permissions_boost' => array(),
            'disabled'       => false,
            'created_at'     => $now,
            'last_login_at'  => 0,
            'password_changed_at' => $now,
            'password_expires_at' => $now + 90 * 86400,
            'failed_attempts' => 0,
            'lockout_until'  => 0,
            'avatar_color'   => function_exists('gojs_users_avatar_color') ? gojs_users_avatar_color($username) : '#3b82f6',
            'preferences'    => function_exists('gojs_default_preferences') ? gojs_default_preferences($config, 'admin') : array(),
        );
        gojs_users_save($store);
    }

    return $store;
}

function gojs_users_find($username) {
    $store = gojs_users_ensure_default();
    foreach ($store['users'] as $u) {
        if (isset($u['username']) && $u['username'] === $username) {
            return $u;
        }
    }
    return null;
}

function gojs_users_find_by_id($id) {
    $store = gojs_users_ensure_default();
    foreach ($store['users'] as $u) {
        if (isset($u['id']) && $u['id'] === $id) {
            return $u;
        }
    }
    return null;
}

function gojs_users_upsert($user) {
    $store = gojs_users_load();
    $found = false;
    foreach ($store['users'] as $i => $u) {
        if ($u['id'] === $user['id']) {
            $store['users'][$i] = array_merge($u, $user);
            $found = true;
            break;
        }
    }
    if (!$found) {
        $store['users'][] = $user;
    }
    return gojs_users_save($store);
}

function gojs_users_delete($id) {
    $store = gojs_users_load();
    $out = array();
    foreach ($store['users'] as $u) {
        if ($u['id'] !== $id) $out[] = $u;
    }
    $store['users'] = $out;
    return gojs_users_save($store);
}

function gojs_current_user() {
    if (empty($_SESSION['user_id'])) return null;
    return gojs_users_find_by_id($_SESSION['user_id']);
}

function gojs_current_role() {
    $u = gojs_current_user();
    return $u ? $u['role'] : null;
}

function gojs_current_user_id() {
    if (!empty($_SESSION['user_id'])) return $_SESSION['user_id'];
    if (!empty($_SESSION['authenticated'])) return 'admin';
    return null;
}

function gojs_current_role_rank() {
    static $rank = array('viewer' => 1, 'operator' => 2, 'admin' => 3);
    $role = gojs_current_role();
    return isset($rank[$role]) ? $rank[$role] : 0;
}

function gojs_users_avatar_palette() {
    return array('#ef4444','#f59e0b','#10b981','#3b82f6','#8b5cf6','#ec4899','#14b8a6','#f97316');
}

function gojs_users_avatar_color($username) {
    $palette = gojs_users_avatar_palette();
    $idx = abs(crc32((string)$username)) % count($palette);
    return $palette[$idx];
}

function gojs_users_password_check_policy($pwd) {
    if (!is_string($pwd) || strlen($pwd) < 8) return 'weak_too_short';
    if (!preg_match('/[A-Za-z]/', $pwd))      return 'weak_no_letter';
    if (!preg_match('/[0-9]/', $pwd))         return 'weak_no_digit';
    static $common = array(
        'password','password1','12345678','qwerty','qwerty123','letmein',
        'admin','admin123','root','root123','iloveyou','monkey','dragon',
        '111111','123123','123456','1234567','123456789','000000',
    );
    if (in_array(strtolower($pwd), $common, true)) return 'weak_common_password';
    return true;
}

function gojs_users_lockout_check($user) {
    $now = time();
    $locked = false;
    $retry_after = 0;
    if (!is_array($user)) return array('locked' => false, 'retry_after' => 0);
    if (!empty($user['lockout_until']) && (int)$user['lockout_until'] > $now) {
        $locked = true;
        $retry_after = (int)$user['lockout_until'] - $now;
    }
    return array('locked' => $locked, 'retry_after' => $retry_after);
}

function gojs_user_totp_get($user = null) {
    global $config;
    if ($user === null) {
        $user = function_exists('gojs_current_user') ? gojs_current_user() : null;
    }
    if (is_array($user) && isset($user['id'])) {
        if (isset($user['totp']) && is_array($user['totp'])) {
            return $user['totp'];
        }
        if (!empty($config['totp']['secret_enc']) && function_exists('gojs_users_upsert')) {
            $migrated = $config['totp'];
            $user['totp'] = $migrated;
            gojs_users_upsert($user);
            return $migrated;
        }
    }
    return isset($config['totp']) ? $config['totp'] : array();
}

function gojs_user_totp_set($data, $user = null) {
    global $config;
    if ($user === null) {
        $user = function_exists('gojs_current_user') ? gojs_current_user() : null;
    }
    if (is_array($user) && isset($user['id']) && $user['id'] !== 'admin' && function_exists('gojs_users_upsert')) {
        $user['totp'] = $data;
        gojs_users_upsert($user);
        return;
    }
    $config['totp'] = $data;
    if (function_exists('gojs_save_config')) gojs_save_config();
}
