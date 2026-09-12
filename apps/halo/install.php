<?php

function gojs_app_install_halo() {
    $target = gojs_get_param('target', getcwd() . '/halo');
    $steps = array();
    
    $steps[] = array('action' => 'create_directory', 'path' => $target);
    
    $download_url = 'https://github.com/halo-dev/halo/releases/download/v2.11.0/halo-2.11.0.jar';
    $jar_file = $target . '/halo.jar';
    
    $steps[] = array('action' => 'download', 'url' => $download_url, 'file' => $jar_file);
    
    if (file_exists($jar_file)) {
        $steps[] = array('action' => 'download_complete', 'file' => $jar_file);
    }
    
    $script_file = $target . '/start.sh';
    $script_content = '#!/bin/bash
cd ' . $target . '
java -jar halo.jar
';
    file_put_contents($script_file, $script_content);
    chmod($script_file, 0755);
    $steps[] = array('action' => 'create_startup_script', 'file' => $script_file);
    
    $config_file = $target . '/application.yaml';
    if (!file_exists($config_file)) {
        $config_content = 'server:
  port: 8090
spring:
  datasource:
    url: jdbc:mysql://localhost:3306/halo?useUnicode=true&characterEncoding=utf-8&useSSL=false&serverTimezone=UTC
    username: root
    password: 
    driver-class-name: com.mysql.cj.jdbc.Driver
halo:
  # 管理员用户名
  adminUsername: admin
  # 管理员密码
  adminPassword: password
  # 管理员邮箱
  adminEmail: admin@example.com
';
        file_put_contents($config_file, $config_content);
        $steps[] = array('action' => 'create_config', 'file' => $config_file);
    }
    
    return $steps;
}