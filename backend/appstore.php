<?php

function gojs_appstore_list() {
    $apps_dir = ROOT . '/apps';
    $apps = array();
    if (is_dir($apps_dir)) {
        $dirs = scandir($apps_dir);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            $manifest = $apps_dir . '/' . $dir . '/manifest.json';
            if (file_exists($manifest)) {
                $meta = json_decode(file_get_contents($manifest), true);
                if (is_array($meta)) {
                    $meta['id'] = $dir;
                    $meta['installed'] = file_exists($apps_dir . '/' . $dir . '/.installed');
                    $apps[] = $meta;
                }
            }
        }
    }
    gojs_json_response(array('apps' => $apps));
}

function gojs_appstore_install() {
    $app_id = gojs_get_param('app_id');
    if (!$app_id) {
        gojs_json_response(null, array('code' => 'missing_param', 'message' => 'Missing app_id'), 400);
        return;
    }

    $apps_dir = ROOT . '/apps';
    $app_dir = $apps_dir . '/' . $app_id;
    $manifest = $app_dir . '/manifest.json';

    if (!file_exists($manifest)) {
        gojs_json_response(null, array('code' => 'app_not_found', 'message' => 'App not found'), 404);
        return;
    }

    $meta = json_decode(file_get_contents($manifest), true);
    if (!is_array($meta)) {
        gojs_json_response(null, array('code' => 'invalid_manifest', 'message' => 'Invalid manifest'), 500);
        return;
    }

    $install_script = $app_dir . '/install.sh';
    $install_php = $app_dir . '/install.php';

    $result = array('app_id' => $app_id, 'success' => true, 'steps' => array());

    if (file_exists($install_php)) {
        include $install_php;
        $fn = 'gojs_app_install_' . str_replace('-', '_', $app_id);
        if (function_exists($fn)) {
            $result['steps'] = $fn();
        }
    } elseif (file_exists($install_script)) {
        $output = array();
        $exit_code = 0;
        exec('bash ' . escapeshellarg($install_script) . ' 2>&1', $output, $exit_code);
        $result['steps'][] = array('script' => 'install.sh', 'output' => $output, 'exit_code' => $exit_code);
        if ($exit_code !== 0) {
            $result['success'] = false;
        }
    }

    if ($result['success']) {
        file_put_contents($app_dir . '/.installed', date('c'));
    }

    gojs_json_response($result);
}

function gojs_appstore_uninstall() {
    $app_id = gojs_get_param('app_id');
    if (!$app_id) {
        gojs_json_response(null, array('code' => 'missing_param', 'message' => 'Missing app_id'), 400);
        return;
    }

    $app_dir = ROOT . '/apps/' . $app_id;
    $install_file = $app_dir . '/.installed';

    if (!file_exists($install_file)) {
        gojs_json_response(null, array('code' => 'not_installed', 'message' => 'App is not installed'), 400);
        return;
    }

    $uninstall_script = $app_dir . '/uninstall.sh';
    $uninstall_php = $app_dir . '/uninstall.php';

    if (file_exists($uninstall_php)) {
        include $uninstall_php;
        $fn = 'gojs_app_uninstall_' . str_replace('-', '_', $app_id);
        if (function_exists($fn)) {
            $fn();
        }
    } elseif (file_exists($uninstall_script)) {
        exec('bash ' . escapeshellarg($uninstall_script) . ' 2>&1');
    }

    @unlink($install_file);

    gojs_json_response(array('app_id' => $app_id, 'success' => true));
}