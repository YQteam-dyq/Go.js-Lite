<?php

function gojs_app_install_discuz() {
    $target = gojs_get_param('target', getcwd() . '/discuz');
    $steps = array();
    
    $steps[] = array('action' => 'create_directory', 'path' => $target);
    
    $download_url = 'https://github.com/Discuz/DiscuzX/releases/download/v3.4/DiscuzX_3.4_SC_UTF8.zip';
    $zip_file = $target . '/discuz.zip';
    
    $steps[] = array('action' => 'download', 'url' => $download_url, 'file' => $zip_file);
    
    if (file_exists($zip_file)) {
        $zip = new ZipArchive();
        if ($zip->open($zip_file) === TRUE) {
            $zip->extractTo($target);
            $zip->close();
            $steps[] = array('action' => 'extract', 'file' => $zip_file, 'target' => $target);
        }
    }
    
    $config_file = $target . '/config/config_ucenter.php';
    if (file_exists($config_file)) {
        $config_content = file_get_contents($config_file);
        $config_content = str_replace('define(\'UC_CONNECT\', \'0\');', 'define(\'UC_CONNECT\', \'0\');', $config_content);
        file_put_contents($config_file, $config_content);
        $steps[] = array('action' => 'configure', 'file' => $config_file);
    }
    
    $data_dir = $target . '/data';
    if (is_dir($data_dir)) {
        chmod($data_dir, 0777);
        $steps[] = array('action' => 'set_permissions', 'path' => $data_dir);
    }
    
    $upload_dir = $target . '/static/image';
    if (is_dir($upload_dir)) {
        chmod($upload_dir, 0777);
        $steps[] = array('action' => 'set_permissions', 'path' => $upload_dir);
    }
    
    return $steps;
}