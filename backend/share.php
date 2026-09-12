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
    file_put_contents($file, json_encode($shares, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

// Rewrite the shares file through an already-held exclusive handle. The caller
// must own the flock() on $fp; this helper only replaces the file contents so
// the read-check-write sequence stays inside a single lock section.
function gojs_share_write_locked($fp, array $shares) {
    $json = json_encode($shares, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return;
    }
    @ftruncate($fp, 0);
    @rewind($fp);
    @fwrite($fp, $json);
    @fflush($fp);
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

function gojs_share_sanitize_host($host) {
    if (!is_string($host) || $host === '') {
        return 'localhost';
    }
    if (!preg_match('/^[A-Za-z0-9.\-]+(?::\d{1,5})?$/', $host)) {
        return 'localhost';
    }
    return $host;
}

function gojs_share_create() {
    $path = gojs_get_param('path');
    $expires_in = gojs_get_param('expires_in', 24);
    $password = gojs_get_param('password', '');
    $max_downloads = gojs_get_param('max_downloads', 0);

    if (!$path || !is_string($path)) {
        gojs_json_response(null, array('code' => 'missing_param', 'message' => 'Missing path'), 400);
        return;
    }

    // The password must be a real string before it reaches password_hash().
    // A request such as password[]=x turns the value into an array, which would
    // raise a TypeError on PHP 8 and return a 500 response instead of a clean
    // validation error.
    if (!is_string($password)) {
        gojs_json_response(null, array('code' => 'invalid_param', 'message' => 'Invalid password parameter'), 400);
        return;
    }

    // Validate the numeric parameters as scalars (integer or integer string)
    // before casting, so non-scalar input cannot slip through.
    if (!is_int($expires_in) && !(is_string($expires_in) && preg_match('/^-?\d+$/', trim($expires_in)))) {
        gojs_json_response(null, array('code' => 'invalid_param', 'message' => 'Invalid expires_in parameter'), 400);
        return;
    }
    if (!is_int($max_downloads) && !(is_string($max_downloads) && preg_match('/^-?\d+$/', trim($max_downloads)))) {
        gojs_json_response(null, array('code' => 'invalid_param', 'message' => 'Invalid max_downloads parameter'), 400);
        return;
    }
    $expires_in = (int)$expires_in;
    $max_downloads = (int)$max_downloads;

    if ($expires_in < 0 || $expires_in > 8760) {
        $expires_in = 24;
    }
    if ($max_downloads < 0) {
        $max_downloads = 0;
    }

    $abs_path = gojs_safe_path($path);
    if ($abs_path === false) {
        gojs_json_response(null, array('code' => 'forbidden', 'message' => 'Path access denied'), 403);
        return;
    }

    gojs_ensure_not_protected($abs_path, 'share');

    if (!file_exists($abs_path)) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'File not found'), 404);
        return;
    }

    $relative_path = gojs_relative_path($abs_path);
    if (!is_string($relative_path) || $relative_path === '') {
        gojs_json_response(null, array('code' => 'forbidden', 'message' => 'Path access denied'), 403);
        return;
    }

    $token = bin2hex(random_bytes(16));
    $expires_at = $expires_in > 0 ? time() + ($expires_in * 3600) : 0;

    $shares = gojs_share_load();
    $shares[$token] = array(
        'path' => $relative_path,
        'created_at' => time(),
        'expires_at' => $expires_at,
        'password' => $password ? password_hash($password, PASSWORD_BCRYPT) : '',
        'max_downloads' => $max_downloads,
        'download_count' => 0,
        'is_dir' => is_dir($abs_path),
    );
    gojs_share_save($shares);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = gojs_share_sanitize_host(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost');
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
    if (!$token || !is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
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

    if (!$token || !is_string($token) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => array('code' => 'missing_token', 'message' => 'Missing token')));
        exit;
    }

    // password[]=x would make this an array and password_verify() would fail
    // with a TypeError; reject anything that is not a string up front.
    if (!is_string($input_password)) {
        http_response_code(400);
        echo json_encode(array('ok' => false, 'error' => array('code' => 'invalid_password', 'message' => 'Invalid password')));
        exit;
    }

    // Hold one exclusive lock for the whole read-check-increment-write cycle so
    // concurrent downloads cannot all pass the limit check and push
    // download_count past max_downloads.
    $shares_file = gojs_share_data_dir() . '/shares.json';
    $shares_fp = @fopen($shares_file, 'c+');
    if ($shares_fp === false) {
        http_response_code(500);
        echo json_encode(array('ok' => false, 'error' => array('code' => 'storage_error', 'message' => 'Share storage unavailable')));
        exit;
    }
    if (!@flock($shares_fp, LOCK_EX)) {
        @fclose($shares_fp);
        http_response_code(500);
        echo json_encode(array('ok' => false, 'error' => array('code' => 'storage_error', 'message' => 'Share storage unavailable')));
        exit;
    }

    @rewind($shares_fp);
    $raw = stream_get_contents($shares_fp);
    $shares = json_decode((string)$raw, true);
    if (!is_array($shares)) {
        $shares = array();
    }

    // Expired shares are dropped inside the lock so the view stays consistent.
    $now = time();
    $pruned = false;
    foreach ($shares as $share_key => $share_item) {
        if (isset($share_item['expires_at']) && (int)$share_item['expires_at'] > 0 && $now >= (int)$share_item['expires_at']) {
            unset($shares[$share_key]);
            $pruned = true;
        }
    }

    if (!isset($shares[$token])) {
        if ($pruned) {
            gojs_share_write_locked($shares_fp, $shares);
        }
        @flock($shares_fp, LOCK_UN);
        @fclose($shares_fp);
        http_response_code(410);
        echo json_encode(array('ok' => false, 'error' => array('code' => 'expired', 'message' => 'Link expired or invalid')));
        exit;
    }

    $share = $shares[$token];

    if (isset($share['password']) && $share['password'] !== '') {
        if (!$input_password || !password_verify($input_password, $share['password'])) {
            @flock($shares_fp, LOCK_UN);
            @fclose($shares_fp);
            http_response_code(403);
            echo json_encode(array('ok' => false, 'error' => array('code' => 'invalid_password', 'message' => 'Invalid password')));
            exit;
        }
    }

    // The cap is enforced and the counter is bumped under the same lock. Every
    // rejection keeps the previous response code, message and HTTP status.
    if ((int)$share['max_downloads'] > 0 && (int)$share['download_count'] >= (int)$share['max_downloads']) {
        unset($shares[$token]);
        gojs_share_write_locked($shares_fp, $shares);
        @flock($shares_fp, LOCK_UN);
        @fclose($shares_fp);
        http_response_code(410);
        echo json_encode(array('ok' => false, 'error' => array('code' => 'limit_reached', 'message' => 'Download limit reached')));
        exit;
    }

    $abs_path = gojs_safe_path($share['path']);
    if ($abs_path === false || gojs_is_protected_path($abs_path)) {
        unset($shares[$token]);
        gojs_share_write_locked($shares_fp, $shares);
        @flock($shares_fp, LOCK_UN);
        @fclose($shares_fp);
        http_response_code(403);
        echo json_encode(array('ok' => false, 'error' => array('code' => 'forbidden', 'message' => 'Path not allowed')));
        exit;
    }

    if (!file_exists($abs_path)) {
        unset($shares[$token]);
        gojs_share_write_locked($shares_fp, $shares);
        @flock($shares_fp, LOCK_UN);
        @fclose($shares_fp);
        http_response_code(404);
        echo json_encode(array('ok' => false, 'error' => array('code' => 'not_found', 'message' => 'File not found')));
        exit;
    }

    $share['download_count'] = (int)$share['download_count'] + 1;
    $shares[$token] = $share;
    gojs_share_write_locked($shares_fp, $shares);
    @flock($shares_fp, LOCK_UN);
    @fclose($shares_fp);

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