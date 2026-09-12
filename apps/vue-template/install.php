<?php

function gojs_app_install_vue_template() {
    $target = gojs_get_param('target', getcwd() . '/vue-template');
    $steps = array();
    
    $steps[] = array('action' => 'create_directory', 'path' => $target);
    
    $steps[] = array('action' => 'run_npm', 'command' => 'create vue@latest ' . escapeshellarg($target) . ' -- --typescript');
    
    if (is_dir($target . '/src')) {
        $steps[] = array('action' => 'project_created', 'path' => $target);
    }
    
    $package_file = $target . '/package.json';
    if (file_exists($package_file)) {
        $package_content = file_get_contents($package_file);
        $package_content = str_replace('"name": "vue-project"', '"name": "vue-template"', $package_content);
        $package_content = str_replace('"description": "A Vue.js project"', '"description": "Vue 3 + TypeScript 项目模板"', $package_content);
        file_put_contents($package_file, $package_content);
        $steps[] = array('action' => 'update_package_json', 'file' => $package_file);
    }
    
    $vite_config = $target . '/vite.config.ts';
    if (file_exists($vite_config)) {
        $vite_content = file_get_contents($vite_config);
        $vite_content = str_replace('\'./index.html\'', '\'./public/index.html\'', $vite_content);
        file_put_contents($vite_config, $vite_content);
        $steps[] = array('action' => 'configure_vite', 'file' => $vite_config);
    }
    
    $tsconfig = $target . '/tsconfig.json';
    if (file_exists($tsconfig)) {
        $ts_content = file_get_contents($tsconfig);
        $ts_content = str_replace('"target": "esnext"', '"target": "es2020"', $ts_content);
        $ts_content = str_replace('"useDefineForClassFields": true,', '"useDefineForClassFields": true,\n    "strict": true,', $ts_content);
        file_put_contents($tsconfig, $ts_content);
        $steps[] = array('action' => 'configure_typescript', 'file' => $tsconfig);
    }
    
    $node_modules = $target . '/node_modules';
    if (is_dir($node_modules)) {
        $steps[] = array('action' => 'install_dependencies_complete', 'path' => $node_modules);
    }
    
    return $steps;
}