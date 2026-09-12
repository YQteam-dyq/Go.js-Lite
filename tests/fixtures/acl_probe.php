<?php

require_once __DIR__ . '/../bootstrap.php';

$scenario = array();
if (isset($argv[1]) && $argv[1] !== '') {
    $raw = base64_decode($argv[1], true);
    $decoded = ($raw !== false) ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $scenario = $decoded;
    }
}

if (!empty($scenario['actor']) && is_array($scenario['actor'])) {
    if (!is_dir(CONFIG_DIR)) {
        @mkdir(CONFIG_DIR, 0700, true);
    }
    @file_put_contents(
        gojs_users_path(),
        json_encode(array('users' => array($scenario['actor']))),
        LOCK_EX
    );
    if (!empty($scenario['actor']['id'])) {
        $_SESSION['user_id'] = $scenario['actor']['id'];
    }
}

if (!empty($scenario['groups']) && is_array($scenario['groups'])) {
    @file_put_contents(
        CONFIG_DIR . '/groups.json',
        json_encode(array('groups' => $scenario['groups'])),
        LOCK_EX
    );
}

$fn = isset($scenario['fn']) ? $scenario['fn'] : '';

if ($fn === 'role_rank') {
    $role = isset($scenario['role']) ? $scenario['role'] : '';
    echo 'RANK:' . gojs_role_rank($role);
    exit(0);
}

if ($fn === 'require_role') {
    $min = isset($scenario['min']) ? $scenario['min'] : 'admin';
    gojs_require_role($min);
    echo 'ALLOW';
    exit(0);
}

if ($fn === 'require_path') {
    $path = isset($scenario['path']) ? $scenario['path'] : '/';
    gojs_require_path_access($path);
    echo 'ALLOW';
    exit(0);
}

echo 'UNKNOWN_FN';
exit(2);
