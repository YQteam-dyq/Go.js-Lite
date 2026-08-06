<?php

function gojs_app_install_laravel() {
    $target = gojs_get_param('target', getcwd() . '/laravel');
    $steps = array();

    $steps[] = array('action' => 'download', 'status' => 'running', 'message' => 'Downloading Laravel...');
    exec('composer create-project --prefer-dist laravel/laravel ' . escapeshellarg($target) . ' 2>&1', $out, $code);
    $steps[] = array('action' => 'download', 'status' => $code === 0 ? 'done' : 'failed', 'output' => $out);

    if ($code === 0) {
        $envFile = $target . '/.env';
        if (file_exists($envFile)) {
            $appUrl = gojs_get_param('app_url', 'http://localhost');
            $env = file_get_contents($envFile);
            $env = preg_replace('/^APP_URL=.*/m', 'APP_URL=' . $appUrl, $env);
            file_put_contents($envFile, $env);
        }
        $steps[] = array('action' => 'permissions', 'status' => 'done', 'message' => 'Setting storage permissions...');
        @chmod($target . '/storage', 0755);
        @chmod($target . '/bootstrap/cache', 0755);
    }

    return $steps;
}