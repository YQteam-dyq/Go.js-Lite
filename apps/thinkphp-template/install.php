<?php

function gojs_app_install_thinkphp_template() {
    $target = gojs_get_param('target', getcwd() . '/thinkphp-template');
    $steps = array();
    
    $steps[] = array('action' => 'create_directory', 'path' => $target);
    
    $steps[] = array('action' => 'run_composer', 'command' => 'create-project topthink/think ' . escapeshellarg($target) . ' 6.1.0');
    
    if (is_dir($target . '/app')) {
        $steps[] = array('action' => 'project_created', 'path' => $target);
    }
    
    $env_file = $target . '/.env';
    if (!file_exists($env_file)) {
        $env_content = 'APP_DEBUG = false
APP_TRACE = false

[APP]
DEFAULT_TIMEZONE = Asia/Shanghai

[DATABASE]
TYPE = mysql
HOSTNAME = 127.0.0.1
DATABASE = thinkphp
USERNAME = root
PASSWORD = 
HOSTPORT = 3306
CHARSET = utf8mb4
DEBUG = true

[CACHE]
DRIVER = file
[LANG]
default_lang = zh-cn
';
        file_put_contents($env_file, $env_content);
        $steps[] = array('action' => 'create_env_file', 'file' => $env_file);
    }
    
    $config_file = $target . '/config/database.php';
    if (file_exists($config_file)) {
        $config_content = file_get_contents($config_file);
        $config_content = str_replace('\'hostname\' => \'127.0.0.1\',', '\'hostname\' => \'127.0.0.1\',', $config_content);
        $config_content = str_replace('\'database\' => \'thinkphp\',', '\'database\' => \'thinkphp\',', $config_content);
        $config_content = str_replace('\'username\' => \'root\',', '\'username\' => \'root\',', $config_content);
        $config_content = str_replace('\'password\' => \'\',', '\'password\' => \'\',', $config_content);
        file_put_contents($config_file, $config_content);
        $steps[] = array('action' => 'configure_database', 'file' => $config_file);
    }
    
    $runtime_dir = $target . '/runtime';
    if (is_dir($runtime_dir)) {
        chmod($runtime_dir, 0777);
        $steps[] = array('action' => 'set_permissions', 'path' => $runtime_dir);
    }
    
    return $steps;
}