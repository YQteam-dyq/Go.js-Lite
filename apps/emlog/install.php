<?php

function gojs_app_install_emlog() {
    $target = gojs_get_param('target', getcwd() . '/emlog');
    $steps = array();
    
    $steps[] = array('action' => 'create_directory', 'path' => $target);
    
    $download_url = 'https://github.com/limingxinleo/emlog/releases/download/6.0.1/emlog_6.0.1.zip';
    $zip_file = $target . '/emlog.zip';
    
    $steps[] = array('action' => 'download', 'url' => $download_url, 'file' => $zip_file);
    
    if (file_exists($zip_file)) {
        $zip = new ZipArchive();
        if ($zip->open($zip_file) === TRUE) {
            $zip->extractTo($target);
            $zip->close();
            $steps[] = array('action' => 'extract', 'file' => $zip_file, 'target' => $target);
        }
    }
    
    $config_file = $target . '/config.php';
    if (file_exists($config_file)) {
        $config_content = file_get_contents($config_file);
        $config_content = str_replace('$DB_HOST = \'localhost\';', '$DB_HOST = \'localhost\';', $config_content);
        $config_content = str_replace('$DB_USER = \'root\';', '$DB_USER = \'root\';', $config_content);
        $config_content = str_replace('$DB_PASSWD = \'\';', '$DB_PASSWD = \'\';', $config_content);
        $config_content = str_replace('$DB_NAME = \'emlog\';', '$DB_NAME = \'emlog\';', $config_content);
        file_put_contents($config_file, $config_content);
        $steps[] = array('action' => 'configure', 'file' => $config_file);
    }
    
    $content_dir = $target . '/content';
    if (is_dir($content_dir)) {
        chmod($content_dir, 0777);
        $steps[] = array('action' => 'set_permissions', 'path' => $content_dir);
    }
    
    $cache_dir = $target . '/cache';
    if (is_dir($cache_dir)) {
        chmod($cache_dir, 0777);
        $steps[] = array('action' => 'set_permissions', 'path' => $cache_dir);
    }
    
    return $steps;
}