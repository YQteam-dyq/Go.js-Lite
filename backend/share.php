<?php

function gojs_share_data_dir() {
    $dir = ROOT . '/.gojs/shares';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function gojs_share_load() {
    $file = gojs_share_data_dir() . '/shares.json';
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : array();
    }
    return array();
}

function gojs_share_save($shares) {
    $file = gojs_share_data_dir() . '/shares.json';
    file_put_contents($file, json_encode($shares, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function gojs_share_cleanup() {
    $shares = gojs_share_load();
    $changed = false;
    $now = time();
    foreach ($shares as $id => $share) {
        if ($share['expires_at'] > 0 && $now >= $share['expires_at']) {
            unset($shares[$id]);
            $changed = true;
        }
    }
    if ($changed) {
        gojs_share_save($shares);
    }
    return $shares;
}

function gojs_share_files_root() {
    global $root_path;
    $ctx_root = gojs_files_root();
    if ($ctx_root !== '') return $ctx_root;
    if (!empty($GLOBALS['files_root'])) return $GLOBALS['files_root'];
    return !empty($root_path) ? $root_path : ROOT;
}

function gojs_share_create() {
    $path = gojs_get_param('path');
    $expires_in = (int)gojs_get_param('expires_in', 24);
    $password = gojs_get_param('password', '');
    $max_downloads = (int)gojs_get_param('max_downloads', 0);

    if (!$path) {
        gojs_json_response(null, array('code' => 'missing_param', 'message' => 'Missing path'), 400);
        return;
    }

    $files_root = gojs_share_files_root();
    $abs_path = $files_root . '/' . ltrim($path, '/');
    if (!file_exists($abs_path)) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'File not found'), 404);
        return;
    }

    $token = bin2hex(random_bytes(16));
    $expires_at = $expires_in > 0 ? time() + ($expires_in * 3600) : 0;

    $shares = gojs_share_load();
    $shares[$token] = array(
        'path' => $path,
        'created_at' => time(),
        'expires_at' => $expires_at,
        'password' => $password ? password_hash($password, PASSWORD_BCRYPT) : '',
        'max_downloads' => $max_downloads,
        'download_count' => 0,
        'is_dir' => is_dir($abs_path),
    );
    gojs_share_save($shares);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $share_url = $scheme . '://' . $host . '/gojs/share/' . $token;

    gojs_json_response(array(
        'share_url' => $share_url,
        'token' => $token,
        'expires_at' => $expires_at,
        'expires_in' => $expires_in,
    ));
}

function gojs_share_list() {
    $shares = gojs_share_cleanup();
    $result = array();
    foreach ($shares as $token => $share) {
        $remaining = $share['expires_at'] > 0 ? max(0, $share['expires_at'] - time()) : -1;
        $result[] = array(
            'token' => $token,
            'path' => $share['path'],
            'created_at' => $share['created_at'],
            'expires_at' => $share['expires_at'],
            'remaining_seconds' => $remaining,
            'max_downloads' => $share['max_downloads'],
            'download_count' => $share['download_count'],
            'has_password' => $share['password'] !== '',
            'is_dir' => $share['is_dir'],
        );
    }
    gojs_json_response(array('shares' => $result));
}

function gojs_share_revoke() {
    $token = gojs_get_param('token');
    if (!$token) {
        gojs_json_response(null, array('code' => 'missing_param', 'message' => 'Missing token'), 400);
        return;
    }

    $shares = gojs_share_load();
    if (isset($shares[$token])) {
        unset($shares[$token]);
        gojs_share_save($shares);
    }

    gojs_json_response(array('success' => true));
}

function gojs_share_access() {
    $token = $_GET['token'] ?? '';
    $input_password = $_GET['password'] ?? '';

    if (!$token) {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => array('code' => 'missing_token', 'message' => 'Missing token')));
        exit;
    }

    $shares = gojs_share_cleanup();

    if (!isset($shares[$token])) {
        http_response_code(410);
        echo json_encode(array('ok' => false, 'error' => array('code' => 'expired', 'message' => 'Link expired or invalid')));
        exit;
    }

    $share = $shares[$token];

    if ($share['password'] !== '') {
        if (!$input_password || !password_verify($input_password, $share['password'])) {
            http_response_code(403);
            echo json_encode(array('ok' => false, 'error' => array('code' => 'invalid_password', 'message' => 'Invalid password')));
            exit;
        }
    }

    if ($share['max_downloads'] > 0 && $share['download_count'] >= $share['max_downloads']) {
        unset($shares[$token]);
        gojs_share_save($shares);
        http_response_code(410);
        echo json_encode(array('ok' => false, 'error' => array('code' => 'limit_reached', 'message' => 'Download limit reached')));
        exit;
    }

    $files_root = gojs_share_files_root();
    $abs_path = $files_root . '/' . ltrim($share['path'], '/');

    if (!file_exists($abs_path)) {
        unset($shares[$token]);
        gojs_share_save($shares);
        http_response_code(404);
        echo json_encode(array('ok' => false, 'error' => array('code' => 'not_found', 'message' => 'File not found')));
        exit;
    }

    $share['download_count']++;
    $shares[$token] = $share;
    gojs_share_save($shares);

    if (is_dir($abs_path)) {
        $files = array();
        $dh = opendir($abs_path);
        while (($f = readdir($dh)) !== false) {
            if ($f === '.' || $f === '..') continue;
            $fp = $abs_path . '/' . $f;
            $files[] = array(
                'name' => $f,
                'size' => is_file($fp) ? filesize($fp) : 0,
                'type' => is_dir($fp) ? 'dir' : 'file',
            );
        }
        closedir($dh);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => true, 'data' => array('files' => $files, 'path' => $share['path'])));
        exit;
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($abs_path) . '"');
    header('Content-Length: ' . filesize($abs_path));
    readfile($abs_path);
    exit;
}