<?php

require_once __DIR__ . '/../bootstrap.php';

error_reporting(E_ERROR | E_PARSE);

$scenario = array();
if (isset($argv[1]) && $argv[1] !== '') {
    $raw = base64_decode($argv[1], true);
    $decoded = ($raw !== false) ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $scenario = $decoded;
    }
}

$_COOKIE = array();
if (!empty($scenario['sid'])) {
    $_COOKIE[session_name()] = (string)$scenario['sid'];
}

$_SESSION = array();
if (!empty($scenario['session']) && is_array($scenario['session'])) {
    $_SESSION = $scenario['session'];
}

$sessionDir = CONFIG_DIR . '/sessstore';
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0700, true);
}
$GLOBALS['gojs_sessions_dir_override'] = $sessionDir;
if (!empty($scenario['session_files']) && is_array($scenario['session_files'])) {
    foreach ($scenario['session_files'] as $sid => $payloadRaw) {
        @file_put_contents($sessionDir . '/sess_' . $sid, (string)$payloadRaw);
    }
}

$revokedFile = CONFIG_DIR . '/session_revoked.json';
if (array_key_exists('revoked', $scenario)) {
    if (is_array($scenario['revoked']) && count($scenario['revoked']) > 0) {
        if (!is_dir(CONFIG_DIR)) {
            @mkdir(CONFIG_DIR, 0700, true);
        }
        @file_put_contents($revokedFile, json_encode($scenario['revoked']), LOCK_EX);
    } else {
        @unlink($revokedFile);
    }
}

register_shutdown_function(function () use ($revokedFile) {
    $raw = @file_get_contents($revokedFile);
    echo "\n###STATE###\n" . (($raw !== false) ? base64_encode($raw) : '');
});

$fn = isset($scenario['fn']) ? $scenario['fn'] : '';

if ($fn === 'logout_all') {
    gojs_api_logout_all();
    echo 'UNREACHABLE';
    exit(0);
}

if ($fn === 'sessions_list') {
    gojs_api_sessions_list();
    echo 'UNREACHABLE';
    exit(0);
}

if ($fn === 'sessions_kick') {

    gojs_api_sessions_kick();
    echo 'UNREACHABLE';
    exit(0);
}

if ($fn === 'revoked_check') {
    gojs_session_revoked_check();
    echo 'ALLOW';
    exit(0);
}

echo 'UNKNOWN_FN';
exit(2);
