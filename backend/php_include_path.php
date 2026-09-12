<?php

function gojs_api_php_include_path_get() {
    $userIni = gojs_user_ini_read();
    gojs_json_response(array(
        'current' => ini_get('include_path'),
        'user_ini_path' => gojs_user_ini_path(),
        'user_ini' => $userIni,
        'user_ini_include_path' => isset($userIni['include_path']) ? $userIni['include_path'] : null,
        'writable' => gojs_user_ini_writable(),
        'separator' => PATH_SEPARATOR,
    ));
}

function gojs_api_php_include_path_set() {
    $body = gojs_get_body();
    $paths = isset($body['paths']) ? $body['paths'] : null;
    if (!is_array($paths)) {
        gojs_json_response(null, array('code' => 'invalid_paths', 'message' => 'paths 必须是数组'), 400);
    }
    $clean = array();
    foreach ($paths as $p) {
        if (!is_string($p)) {
            continue;
        }
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        if (strpos($p, "\0") !== false || strpos($p, "\n") !== false) {
            gojs_json_response(null, array('code' => 'invalid_paths', 'message' => '路径含非法字符'), 400);
        }
        $clean[] = $p;
    }
    $clean = array_values(array_unique($clean));
    $value = '.' . (empty($clean) ? '' : PATH_SEPARATOR . implode(PATH_SEPARATOR, $clean));
    if (!gojs_user_ini_merge(array('include_path' => $value))) {
        gojs_json_response(null, array('code' => 'write_failed', 'message' => '写入 .user.ini 失败，请检查目录权限'), 500);
    }
    $runtime = gojs_ini_try_set('include_path', $value);
    gojs_log_operation('php.include_path', 'php/include-path', true, count($clean) . ' paths');
    gojs_json_response(array(
        'saved' => true,
        'user_ini_path' => gojs_user_ini_path(),
        'include_path' => $value,
        'paths' => $clean,
        'runtime' => $runtime,
        'reload_required' => !$runtime['applied'],
    ));
}
