<?php

function gojs_appstore_app_dir($app_id) {
    if (!is_string($app_id) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $app_id)) {
        return false;
    }

    $apps_root = realpath(ROOT . '/apps');
    if ($apps_root === false) {
        return false;
    }

    $app_dir = realpath($apps_root . '/' . $app_id);
    if ($app_dir === false || !is_dir($app_dir)) {
        return false;
    }

    if (strpos($app_dir, $apps_root . DIRECTORY_SEPARATOR) !== 0) {
        return false;
    }

    return $app_dir;
}

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

    $app_dir = gojs_appstore_app_dir($app_id);
    if ($app_dir === false) {
        gojs_json_response(null, array('code' => 'app_not_found', 'message' => 'App not found'), 404);
        return;
    }

    $manifest = $app_dir . '/manifest.json';

    if (!is_file($manifest)) {
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

    if (is_file($install_php)) {
        include $install_php;
        $fn = 'gojs_app_install_' . str_replace('-', '_', $app_id);
        if (function_exists($fn)) {
            $result['steps'] = $fn();
        }
    } elseif (is_file($install_script)) {
        $output = array();
        $exit_code = 0;
        exec('bash ' . escapeshellarg($install_script) . ' 2>&1', $output, $exit_code);
        $result['steps'][] = array('script' => 'install.sh', 'output' => $output, 'exit_code' => $exit_code);
        if ($exit_code !== 0) {
            $result['success'] = false;
        }
    }

    if ($result['success']) {
        $install_info = array(
            'version' => isset($meta['version']) ? $meta['version'] : 'latest',
            'installed_at' => date('c')
        );
        file_put_contents($app_dir . '/.installed', json_encode($install_info), LOCK_EX);
    }

    gojs_json_response($result);
}

// App Store 扩展功能实现 - 并行组 D
// 作者：yq-nova-agent小组

function gojs_appstore_uninstall() {
    $app_id = gojs_get_param('app_id');
    if (!$app_id) {
        gojs_json_response(null, array('code' => 'missing_param', 'message' => 'Missing app_id'), 400);
        return;
    }

    $app_dir = gojs_appstore_app_dir($app_id);
    if ($app_dir === false) {
        gojs_json_response(null, array('code' => 'app_not_found', 'message' => 'App not found'), 404);
        return;
    }

    $install_file = $app_dir . '/.installed';

    if (!is_file($install_file)) {
        gojs_json_response(null, array('code' => 'not_installed', 'message' => 'App is not installed'), 400);
        return;
    }

    $uninstall_script = $app_dir . '/uninstall.sh';
    $uninstall_php = $app_dir . '/uninstall.php';

    if (is_file($uninstall_php)) {
        include $uninstall_php;
        $fn = 'gojs_app_uninstall_' . str_replace('-', '_', $app_id);
        if (function_exists($fn)) {
            $fn();
        }
    } elseif (is_file($uninstall_script)) {
        exec('bash ' . escapeshellarg($uninstall_script) . ' 2>&1');
    }

    @unlink($install_file);

    gojs_json_response(array('app_id' => $app_id, 'success' => true));
}

function gojs_appstore_check_updates() {
    $app_id = gojs_get_param('app_id');
    if (!$app_id) {
        gojs_json_response(null, array('code' => 'missing_param', 'message' => 'Missing app_id'), 400);
        return;
    }

    $app_dir = gojs_appstore_app_dir($app_id);
    if ($app_dir === false) {
        gojs_json_response(null, array('code' => 'app_not_found', 'message' => 'App not found'), 404);
        return;
    }

    $manifest = $app_dir . '/manifest.json';
    if (!is_file($manifest)) {
        gojs_json_response(null, array('code' => 'app_not_found', 'message' => 'App not found'), 404);
        return;
    }

    $meta = json_decode(file_get_contents($manifest), true);
    if (!is_array($meta)) {
        gojs_json_response(null, array('code' => 'invalid_manifest', 'message' => 'Invalid manifest'), 500);
        return;
    }

    $install_file = $app_dir . '/.installed';
    $current_version = null;
    $has_update = false;

    if (is_file($install_file)) {
        $install_info = json_decode(file_get_contents($install_file), true);
        if (is_array($install_info) && isset($install_info['version'])) {
            $current_version = $install_info['version'];
        }
        if (isset($meta['version']) && $current_version !== $meta['version']) {
            $has_update = true;
        }
    }

    gojs_json_response(array(
        'app_id' => $app_id,
        'current_version' => $current_version,
        'latest_version' => isset($meta['version']) ? $meta['version'] : null,
        'has_update' => $has_update,
        'update_info' => $has_update ? array(
            'changelog' => isset($meta['changelog']) ? $meta['changelog'] : 'No changelog available',
            'update_time' => isset($meta['update_time']) ? $meta['update_time'] : date('c')
        ) : null
    ));
}

function gojs_appstore_update() {
    $app_id = gojs_get_param('app_id');
    if (!$app_id) {
        gojs_json_response(null, array('code' => 'missing_param', 'message' => 'Missing app_id'), 400);
        return;
    }

    $app_dir = gojs_appstore_app_dir($app_id);
    if ($app_dir === false) {
        gojs_json_response(null, array('code' => 'app_not_found', 'message' => 'App not found'), 404);
        return;
    }

    $manifest = $app_dir . '/manifest.json';
    if (!is_file($manifest)) {
        gojs_json_response(null, array('code' => 'app_not_found', 'message' => 'App not found'), 404);
        return;
    }

    $meta = json_decode(file_get_contents($manifest), true);
    if (!is_array($meta)) {
        gojs_json_response(null, array('code' => 'invalid_manifest', 'message' => 'Invalid manifest'), 500);
        return;
    }

    $install_file = $app_dir . '/.installed';
    if (!is_file($install_file)) {
        gojs_json_response(null, array('code' => 'not_installed', 'message' => 'App is not installed'), 400);
        return;
    }

    $update_script = $app_dir . '/update.sh';
    $update_php = $app_dir . '/update.php';

    $result = array('app_id' => $app_id, 'success' => true, 'steps' => array());

    if (is_file($update_php)) {
        include $update_php;
        $fn = 'gojs_app_update_' . str_replace('-', '_', $app_id);
        if (function_exists($fn)) {
            $result['steps'] = $fn();
        }
    } elseif (is_file($update_script)) {
        $output = array();
        $exit_code = 0;
        exec('bash ' . escapeshellarg($update_script) . ' 2>&1', $output, $exit_code);
        $result['steps'][] = array('script' => 'update.sh', 'output' => $output, 'exit_code' => $exit_code);
        if ($exit_code !== 0) {
            $result['success'] = false;
        }
    } else {
        $result['steps'][] = array('message' => 'No update script available, skipping update');
    }

    if ($result['success']) {
        $install_info = array(
            'version' => isset($meta['version']) ? $meta['version'] : 'latest',
            'updated_at' => date('c')
        );
        file_put_contents($install_file, json_encode($install_info), LOCK_EX);
    }

    gojs_json_response($result);
}

function gojs_appstore_clone() {
    $source_app_id = gojs_get_param('source_app_id');
    $target_app_id = gojs_get_param('target_app_id');
    
    if (!$source_app_id || !$target_app_id) {
        gojs_json_response(null, array('code' => 'missing_param', 'message' => 'Missing source_app_id or target_app_id'), 400);
        return;
    }

    $source_app_dir = gojs_appstore_app_dir($source_app_id);
    if ($source_app_dir === false) {
        gojs_json_response(null, array('code' => 'source_app_not_found', 'message' => 'Source app not found'), 404);
        return;
    }

    $target_app_dir = gojs_appstore_app_dir($target_app_id);
    if ($target_app_dir !== false) {
        gojs_json_response(null, array('code' => 'target_app_exists', 'message' => 'Target app already exists'), 400);
        return;
    }

    $apps_root = realpath(ROOT . '/apps');
    $new_target_dir = $apps_root . '/' . $target_app_id;
    
    if (!is_dir($apps_root)) {
        gojs_json_response(null, array('code' => 'apps_dir_not_found', 'message' => 'Apps directory not found'), 500);
        return;
    }

    if (!is_writable($apps_root)) {
        gojs_json_response(null, array('code' => 'permission_denied', 'message' => 'Permission denied'), 403);
        return;
    }

    $result = array('source_app_id' => $source_app_id, 'target_app_id' => $target_app_id, 'success' => true, 'steps' => array());

    try {
        if (!is_dir($new_target_dir)) {
            if (!mkdir($new_target_dir, 0755, true)) {
                throw new Exception("Failed to create target directory: " . $new_target_dir);
            }
            $result['steps'][] = array('action' => 'create_directory', 'path' => $new_target_dir);
        }

        $source_files = scandir($source_app_dir);
        foreach ($source_files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $source_file = $source_app_dir . '/' . $file;
            $target_file = $new_target_dir . '/' . $file;
            
            if (is_dir($source_file)) {
                if (!is_dir($target_file)) {
                    if (!mkdir($target_file, 0755, true)) {
                        throw new Exception("Failed to create target directory: " . $target_file);
                    }
                    $result['steps'][] = array('action' => 'create_directory', 'path' => $target_file);
                }
                
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source_file, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
                foreach ($iterator as $item) {
                    $relative_path = $iterator->getSubPathName();
                    $target_item = $new_target_dir . '/' . $file . '/' . $relative_path;
                    if ($item->isDir()) {
                        if (!is_dir($target_item)) {
                            if (!mkdir($target_item, 0755, true)) {
                                throw new Exception("Failed to create target directory: " . $target_item);
                            }
                        }
                    } else {
                        if (!copy($item->getPathname(), $target_item)) {
                            throw new Exception("Failed to copy file: " . $item->getPathname() . " -> " . $target_item);
                        }
                        $result['steps'][] = array('action' => 'copy_file', 'from' => $item->getPathname(), 'to' => $target_item);
                    }
                }
            } else {
                if (!copy($source_file, $target_file)) {
                    throw new Exception("Failed to copy file: " . $source_file . " -> " . $target_file);
                }
                $result['steps'][] = array('action' => 'copy_file', 'from' => $source_file, 'to' => $target_file);
            }
        }

        if (file_exists($source_app_dir . '/.installed')) {
            $install_info = json_decode(file_get_contents($source_app_dir . '/.installed'), true);
            if (is_array($install_info)) {
                $install_info['cloned_from'] = $source_app_id;
                $install_info['cloned_at'] = date('c');
                $installed_file = $new_target_dir . '/.installed';
                if (!file_put_contents($installed_file, json_encode($install_info), LOCK_EX)) {
                    throw new Exception("Failed to write install file: " . $installed_file);
                }
                $result['steps'][] = array('action' => 'create_install_marker', 'app_id' => $target_app_id);
            }
        }

        $manifest = $new_target_dir . '/manifest.json';
        if (file_exists($manifest)) {
            $meta = json_decode(file_get_contents($manifest), true);
            if (is_array($meta)) {
                $meta['id'] = $target_app_id;
                $meta['name'] = $meta['name'] . ' (Clone)';
                $meta['description'] = $meta['description'] . ' (Cloned from ' . $source_app_id . ')';
                if (!file_put_contents($manifest, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX)) {
                    throw new Exception("Failed to write manifest file: " . $manifest);
                }
                $result['steps'][] = array('action' => 'update_manifest', 'app_id' => $target_app_id);
            }
        }

    } catch (Exception $e) {
        if (is_dir($new_target_dir)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($new_target_dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            $rollback_errors = array();
            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    if (!@rmdir($item->getPathname())) {
                        $rollback_errors[] = "Failed to remove directory: " . $item->getPathname();
                    }
                } else {
                    if (!@unlink($item->getPathname())) {
                        $rollback_errors[] = "Failed to remove file: " . $item->getPathname();
                    }
                }
            }
            if (!@rmdir($new_target_dir)) {
                $rollback_errors[] = "Failed to remove target directory: " . $new_target_dir;
            }
            if (!empty($rollback_errors)) {
                $result['rollback_errors'] = $rollback_errors;
            }
        }
        $result['success'] = false;
        $result['error'] = $e->getMessage();
    }

    gojs_json_response($result);
}