<?php

function gojs_dirprotect_htpasswd_hash($password) {
    if (function_exists('password_hash') && defined('PASSWORD_BCRYPT')) {
        return password_hash($password, PASSWORD_BCRYPT);
    }
    $salt = bin2hex(random_bytes(8));
    return crypt($password, '$2y$10$' . $salt);
}

function gojs_dirprotect_files_root() {
    global $root_path;
    $ctx_root = gojs_files_root();
    if ($ctx_root !== '') return $ctx_root;
    if (!empty($GLOBALS['files_root'])) return $GLOBALS['files_root'];
    return !empty($root_path) ? $root_path : ROOT;
}

function gojs_dirprotect_htpasswd_path($path) {
    $files_root = gojs_dirprotect_files_root();
    $abs_path = $files_root . '/' . ltrim($path, '/');
    $dir = rtrim($abs_path, '/');
    return $dir . '/.htpasswd';
}

function gojs_dirprotect_htaccess_path($path) {
    $files_root = gojs_dirprotect_files_root();
    $abs_path = $files_root . '/' . ltrim($path, '/');
    $dir = rtrim($abs_path, '/');
    return $dir . '/.htaccess';
}

function gojs_dirprotect_load_users($path) {
    $htpasswd = gojs_dirprotect_htpasswd_path($path);
    $users = array();
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
    $auth_name = gojs_get_param('auth_name', 'Restricted Area');
    $users = gojs_get_param('users', array());

    if (!$path) {
        gojs_json_response(null, array('code' => 'missing_param', 'message' => 'Missing path'), 400);
        return;
    }

    $files_root = gojs_dirprotect_files_root();
    $abs_path = $files_root . '/' . ltrim($path, '/');
    $dir = rtrim($abs_path, '/');

    if (!is_dir($dir)) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'Directory not found'), 404);
        return;
    }

    $htpasswd_file = $dir . '/.htpasswd';
    $htaccess_file = $dir . '/.htaccess';

    $htpasswd_content = '';
    if (is_array($users)) {
        foreach ($users as $user) {
            $username = $user['username'] ?? '';
            $password = $user['password'] ?? '';
            if ($username && $password) {
                $hash = gojs_dirprotect_htpasswd_hash($password);
                $htpasswd_content .= $username . ':' . $hash . "\n";
            }
        }
    }
    file_put_contents($htpasswd_file, $htpasswd_content);

    $htaccess_lines = array();
    $htaccess_lines[] = 'AuthType Basic';
    $htaccess_lines[] = 'AuthName "' . str_replace('"', '', $auth_name) . '"';
    $htaccess_lines[] = 'AuthUserFile ' . $htpasswd_file;
    $htaccess_lines[] = 'Require valid-user';
    file_put_contents($htaccess_file, implode("\n", $htaccess_lines) . "\n");

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
    $username = gojs_get_param('username');
    $password = gojs_get_param('password', '');

    if (!$path || !$action || !$username) {
        gojs_json_response(null, array('code' => 'missing_param', 'message' => 'Missing required params'), 400);
        return;
    }

    $htpasswd = gojs_dirprotect_htpasswd_path($path);
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
        $content .= $u . ':' . $h . "\n";
    }
    file_put_contents($htpasswd, $content);

    gojs_json_response(array('success' => true, 'users' => array_keys($users)));
}