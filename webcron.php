<?php

define('GOJS_SKIP_DISPATCH', true);
require_once __DIR__ . '/api.php';

header('Content-Type: application/json; charset=utf-8');

global $config;

$provided_token = isset($_GET['token']) ? (string)$_GET['token'] : '';
$valid_token = isset($config['internal_cron_token']) ? (string)$config['internal_cron_token'] : '';

if ($provided_token === '' || $valid_token === '' || !hash_equals($valid_token, $provided_token)) {
    http_response_code(403);
    echo json_encode(array(
        'ok' => false,
        'code' => 'forbidden',
        'message' => 'Invalid or missing token',
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$stats = gojs_internal_cron_tick();

http_response_code(200);
echo json_encode(array(
    'ok' => true,
    'stats' => $stats,
), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
exit;
