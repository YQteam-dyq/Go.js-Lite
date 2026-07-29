<?php

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = urldecode($uri);



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
    $apiAction = strtok($apiAction, '?');
    $_GET['api'] = $apiAction;
    require __DIR__ . '/api.php';
    return true;
}


if ($uri !== '/') {
    $distFile = __DIR__ . '/dist' . $uri;
    if (file_exists($distFile) && is_file($distFile)) {
        $ext = pathinfo($distFile, PATHINFO_EXTENSION);
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
        readfile($distFile);
        return true;
    }

    
$rootFile = __DIR__ . $uri;
    if (file_exists($rootFile) && is_file($rootFile)) {
        return false;
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
