<?php

$raw_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw_uri = urldecode($raw_uri);

$is_panel = false;
$strip_prefix = '';

$panel_dir = basename(__DIR__);
$reserved_dirs = array('public_html', 'htdocs', 'www', 'wwwroot', 'html', 'web', '.');
$panel_base = in_array($panel_dir, $reserved_dirs, true) ? '' : '/' . $panel_dir;

$candidates = array();
if ($panel_base !== '') {
    $candidates[] = $panel_base;
}
$candidates[] = '/gojs'; // Compatibility with the historical hardcoded prefix.
$candidates[] = '';      // Deployed at root.

foreach ($candidates as $cand) {
    if ($cand === '') {
        $is_panel = true;
        $strip_prefix = '';
        break;
    }
    if ($raw_uri === $cand || $raw_uri === $cand . '/' || strpos($raw_uri, $cand . '/') === 0) {
        $is_panel = true;
        $strip_prefix = $cand;
        break;
    }
}

if ($is_panel && strlen($strip_prefix) > 0) {
    $uri = substr($raw_uri, strlen($strip_prefix));
    if ($uri === false || $uri === '') {
        $uri = '/';
    }
} else {
    $uri = $raw_uri;
}

if (!$is_panel) {
    return false;
}

$query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
if ($query) {
    parse_str($query, $queryParams);
    if (!empty($queryParams['token'])) {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        $configFile = __DIR__ . '/.gojs/config.php';
        if (file_exists($configFile)) {
            $cfg = include $configFile;
            if (is_array($cfg) && !empty($cfg['access_token']) && hash_equals($cfg['access_token'], $queryParams['token'])) {
                $_SESSION['access_token_valid'] = true;
            }
        }
    }
}

if (strpos($uri, '/api/') === 0 || $uri === '/api') {
    $apiAction = ($uri === '/api') ? '' : substr($uri, 5);
    $apiAction = ltrim($apiAction, '/');
    $apiAction = strtok($apiAction, '?');
    $_GET['api'] = $apiAction;
    require __DIR__ . '/api.php';
    return true;
}

$staticPath = str_replace('\\', '/', (string)$uri);
$staticPath = str_replace("\0", '', $staticPath);

$distDir = realpath(__DIR__ . '/dist');
if ($distDir !== false) {
    $normalizedSegments = array();
    foreach (explode('/', $staticPath) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($normalizedSegments);
            continue;
        }
        $normalizedSegments[] = $segment;
    }
    $normalizedPath = implode('/', $normalizedSegments);

    if ($normalizedPath !== '') {
        $distFile = $distDir . '/' . $normalizedPath;
        $realFile = realpath($distFile);

        if ($realFile !== false &&
            is_file($realFile) &&
            strpos($realFile, $distDir . DIRECTORY_SEPARATOR) === 0) {
            $ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
            $mimeMap = [
                'js'   => 'application/javascript; charset=utf-8',
                'mjs'  => 'application/javascript; charset=utf-8',
                'css'  => 'text/css; charset=utf-8',
                'html' => 'text/html; charset=utf-8',
                'svg'  => 'image/svg+xml',
                'png'  => 'image/png',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'gif'  => 'image/gif',
                'ico'  => 'image/x-icon',
                'woff' => 'font/woff',
                'woff2'=> 'font/woff2',
                'ttf'  => 'font/ttf',
                'json' => 'application/json; charset=utf-8',
                'map'  => 'application/json; charset=utf-8',
            ];
            $mime = isset($mimeMap[$ext]) ? $mimeMap[$ext] : 'application/octet-stream';
            header("Content-Type: $mime");
            header('Cache-Control: public, max-age=86400');
            readfile($realFile);
            return true;
        }
    }
}

$indexFile = __DIR__ . '/dist/index.html';
if (file_exists($indexFile)) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($indexFile);
    return true;
}

http_response_code(404);
echo 'Frontend not built. Run: npm run build';
return true;
