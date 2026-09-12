<?php

function gojs_dirprotect_htpasswd_hash($password) {
    if (function_exists('password_hash') && defined('PASSWORD_BCRYPT')) {
        return password_hash($password, PASSWORD_BCRYPT);
    }
    $alphabet = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    $salt = '';
    $bytes = random_bytes(16);
    for ($i = 0; $i < 22; $i++) {
        $salt .= $alphabet[ord($bytes[$i % 16]) % 64];
    }
    return crypt($password, '$2y$10$' . $salt);
}

function gojs_dirprotect_files_root() {
    global $root_path;
    $ctx_root = gojs_files_root();
    if ($ctx_root !== '') return $ctx_root;
    if (!empty($GLOBALS['files_root'])) return $GLOBALS['files_root'];
    return !empty($root_path) ? $root_path : ROOT;
}

function gojs_dirprotect_resolve_dir($path) {
    if (!is_string($path) || $path === '') {
        return false;
    }

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        return false;
    }

    if (!is_dir($safe_path)) {
        return false;
    }

    if (gojs_is_protected_path($safe_path)) {
        return false;
    }

    return rtrim($safe_path, '/');
}

function gojs_dirprotect_sanitize_username($username) {
    if (!is_string($username)) {
        return '';
    }
    $username = trim($username);
    if ($username === '' || strlen($username) > 64) {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9_.\-@]{1,64}$/', $username)) {
        return '';
    }
    return $username;
}

function gojs_dirprotect_sanitize_auth_name($name) {
    if (!is_string($name)) {
        return 'Restricted Area';
    }
    $name = str_replace(array("\r", "\n", "\t", '"', '\\'), '', $name);
    $name = trim($name);
    if ($name === '' || strlen($name) > 128) {
        return 'Restricted Area';
    }
    return $name;
}

function gojs_dirprotect_htpasswd_path($path) {
    $dir = gojs_dirprotect_resolve_dir($path);
    if ($dir === false) {
        return false;
    }
    return $dir . '/.htpasswd';
}

function gojs_dirprotect_htaccess_path($path) {
    $dir = gojs_dirprotect_resolve_dir($path);
    if ($dir === false) {
        return false;
    }
    return $dir . '/.htaccess';
}

function gojs_dirprotect_load_users($path) {
    $htpasswd = gojs_dirprotect_htpasswd_path($path);
    $users = array();
    if ($htpasswd === false) {
        return $users;
    }
    if (file_exists($htpasswd)) {
        $lines = file($htpasswd, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $users[] = array('username' => $parts[0]);
            }
        }
    }
    return $users;
}

function gojs_dirprotect_status() {
    $path = gojs_get_param('path');
    if (!$path) {
        gojs_json_response(null, array('code' => 'missing_param', 'message' => 'Missing path'), 400);
        return;
    }

    $htaccess = gojs_dirprotect_htaccess_path($path);
    if ($htaccess === false) {
        gojs_json_response(null, array('code' => 'forbidden', 'message' => '路径访问被拒绝'), 403);
        return;
    }

    $protected = false;
    $auth_name = '';

    if (file_exists($htaccess)) {
        $content = file_get_contents($htaccess);
        if (strpos($content, 'AuthType Basic') !== false) {
            $protected = true;
            if (preg_match('/AuthName\s+"([^"]+)"/', $content, $m)) {
                $auth_name = $m[1];
            }
        }
    }

    $users = $protected ? gojs_dirprotect_load_users($path) : array();

    gojs_json_response(array(
        'protected' => $protected,
        'auth_name' => $auth_name,
        'users' => $users,
    ));
}

function gojs_dirprotect_enable() {
    $path = gojs_get_param('path');
    $auth_name = gojs_dirprotect_sanitize_auth_name(gojs_get_param('auth_name', 'Restricted Area'));
    $users = gojs_get_param('users', array());

    if (!$path) {
        gojs_json_response(null, array('code' => 'missing_param', 'message' => 'Missing path'), 400);
        return;
    }

    $dir = gojs_dirprotect_resolve_dir($path);
    if ($dir === false) {
        gojs_json_response(null, array('code' => 'forbidden', 'message' => '路径访问被拒绝'), 403);
        return;
    }

    if (!is_writable($dir)) {
        gojs_json_response(null, array('code' => 'not_writable', 'message' => '目录不可写'), 403);
        return;
    }

    $htpasswd_file = $dir . '/.htpasswd';
    $htaccess_file = $dir . '/.htaccess';

    $htpasswd_content = '';
    if (is_array($users)) {
        foreach ($users as $user) {
            if (!is_array($user)) continue;
            $username = gojs_dirprotect_sanitize_username(isset($user['username']) ? $user['username'] : '');
            $password = isset($user['password']) && is_string($user['password']) ? $user['password'] : '';
            if ($username !== '' && $password !== '') {
                $hash = gojs_dirprotect_htpasswd_hash($password);
                $htpasswd_content .= $username . ':' . $hash . "\n";
            }
        }
    }
    file_put_contents($htpasswd_file, $htpasswd_content, LOCK_EX);
    @chmod($htpasswd_file, 0640);

    $htaccess_lines = array();
    $htaccess_lines[] = 'AuthType Basic';
    $htaccess_lines[] = 'AuthName "' . $auth_name . '"';
    $htaccess_lines[] = 'AuthUserFile ' . $htpasswd_file;
    $htaccess_lines[] = 'Require valid-user';
    file_put_contents($htaccess_file, implode("\n", $htaccess_lines) . "\n", LOCK_EX);

    gojs_json_response(array('success' => true, 'protected' => true, 'auth_name' => $auth_name));
}

function gojs_dirprotect_disable() {
    $path = gojs_get_param('path');
    if (!$path) {
        gojs_json_response(null, array('code' => 'missing_param', 'message' => 'Missing path'), 400);
        return;
    }

    $htaccess = gojs_dirprotect_htaccess_path($path);
    $htpasswd = gojs_dirprotect_htpasswd_path($path);

    if ($htaccess === false || $htpasswd === false) {
        gojs_json_response(null, array('code' => 'forbidden', 'message' => '路径访问被拒绝'), 403);
        return;
    }

    if (file_exists($htaccess)) {
        $content = file_get_contents($htaccess);
        $lines = explode("\n", $content);
        $keep = array();
        $in_auth = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === 'AuthType Basic') { $in_auth = true; continue; }
            if ($in_auth) {
                if (strpos($trimmed, 'AuthName') === 0) continue;
                if (strpos($trimmed, 'AuthUserFile') === 0) continue;
                if ($trimmed === 'Require valid-user') { $in_auth = false; continue; }
            }
            $keep[] = $line;
        }
        $new_content = implode("\n", $keep);
        if (trim($new_content) === '') {
            @unlink($htaccess);
        } else {
            file_put_contents($htaccess, $new_content);
        }
    }

    if (file_exists($htpasswd)) {
        @unlink($htpasswd);
    }

    gojs_json_response(array('success' => true, 'protected' => false));
}

function gojs_dirprotect_users() {
    $path = gojs_get_param('path');
    $action = gojs_get_param('action');
    $username = gojs_dirprotect_sanitize_username(gojs_get_param('username'));
    $password = gojs_get_param('password', '');

    if (!$path || !$action || $username === '') {
        gojs_json_response(null, array('code' => 'missing_param', 'message' => 'Missing required params'), 400);
        return;
    }

    $htpasswd = gojs_dirprotect_htpasswd_path($path);
    if ($htpasswd === false) {
        gojs_json_response(null, array('code' => 'forbidden', 'message' => '路径访问被拒绝'), 403);
        return;
    }

    if (!is_string($password) || strpos($password, "\n") !== false || strpos($password, "\r") !== false) {
        gojs_json_response(null, array('code' => 'invalid_password', 'message' => '密码包含非法字符'), 400);
        return;
    }

    $users = array();

    if (file_exists($htpasswd)) {
        $lines = file($htpasswd, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $users[$parts[0]] = $parts[1];
            }
        }
    }

    switch ($action) {
        case 'add':
            if (!$password) {
                gojs_json_response(null, array('code' => 'missing_password', 'message' => 'Password required'), 400);
                return;
            }
            $users[$username] = gojs_dirprotect_htpasswd_hash($password);
            break;
        case 'delete':
            unset($users[$username]);
            break;
        case 'change-password':
            if (!$password) {
                gojs_json_response(null, array('code' => 'missing_password', 'message' => 'Password required'), 400);
                return;
            }
            $users[$username] = gojs_dirprotect_htpasswd_hash($password);
            break;
        default:
            gojs_json_response(null, array('code' => 'invalid_action', 'message' => 'Invalid action'), 400);
            return;
    }

    $content = '';
    foreach ($users as $u => $h) {
        $safe_user = gojs_dirprotect_sanitize_username($u);
        if ($safe_user === '') continue;
        $content .= $safe_user . ':' . $h . "\n";
    }
    file_put_contents($htpasswd, $content, LOCK_EX);
    @chmod($htpasswd, 0640);

    gojs_json_response(array('success' => true, 'users' => array_keys($users)));
}