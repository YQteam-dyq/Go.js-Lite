<?php

function gojs_app_install_ghost() {
    $target = gojs_get_param('target', getcwd() . '/ghost');
    $steps = array();

    $steps[] = array('action' => 'download', 'status' => 'running', 'message' => 'Downloading Ghost...');
    $url = 'https://github.com/TryGhost/Ghost/releases/download/5.96.0/Ghost-5.96.0.zip';
    $zipFile = $target . '.zip';
    $zipContent = @file_get_contents($url);

    if ($zipContent === false) {
        exec('wget -q ' . escapeshellarg($url) . ' -O ' . escapeshellarg($zipFile) . ' 2>&1', $out, $code);
    } else {
        file_put_contents($zipFile, $zipContent);
        $code = 0;
    }

    if ($code === 0 && file_exists($zipFile)) {
        $steps[] = array('action' => 'extract', 'status' => 'running', 'message' => 'Extracting Ghost...');
        $zip = new ZipArchive();
        if ($zip->open($zipFile) === true) {
            if (!is_dir($target)) mkdir($target, 0755, true);
            $zip->extractTo($target);
            $zip->close();
            @unlink($zipFile);
            $steps[] = array('action' => 'extract', 'status' => 'done', 'message' => 'Extracted successfully');
        } else {
            $steps[] = array('action' => 'extract', 'status' => 'failed', 'message' => 'Failed to extract zip');
        }
    } else {
        $steps[] = array('action' => 'download', 'status' => 'failed', 'message' => 'Failed to download Ghost');
    }

    $configFile = $target . '/config.production.json';
    if (!file_exists($configFile)) {
        $config = array(
            'url' => gojs_get_param('app_url', 'http://localhost:2368'),
            'server' => array('host' => '127.0.0.1', 'port' => 2368),
            'database' => array(
                'client' => 'sqlite3',
                'connection' => array('filename' => 'content/data/ghost.db'),
            ),
        );
        file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        $steps[] = array('action' => 'config', 'status' => 'done', 'message' => 'Config created');
    }

    @chmod($target . '/content', 0755);

    return $steps;
}