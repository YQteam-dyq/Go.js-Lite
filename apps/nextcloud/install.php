<?php

function gojs_app_install_nextcloud() {
    $target = gojs_get_param('target', getcwd() . '/nextcloud');
    $steps = array();

    $steps[] = array('action' => 'download', 'status' => 'running', 'message' => 'Downloading Nextcloud...');
    $url = 'https://download.nextcloud.com/server/releases/latest.zip';
    $zipFile = $target . '.zip';
    $zipContent = @file_get_contents($url);

    if ($zipContent === false) {
        exec('wget -q ' . escapeshellarg($url) . ' -O ' . escapeshellarg($zipFile) . ' 2>&1', $out, $code);
    } else {
        file_put_contents($zipFile, $zipContent);
        $code = 0;
    }

    if ($code === 0 && file_exists($zipFile)) {
        $steps[] = array('action' => 'extract', 'status' => 'running', 'message' => 'Extracting Nextcloud...');
        $zip = new ZipArchive();
        if ($zip->open($zipFile) === true) {
            $zip->extractTo(dirname($target));
            $zip->close();
            @unlink($zipFile);
            $extracted = dirname($target) . '/nextcloud';
            if (is_dir($extracted) && $extracted !== $target) {
                rename($extracted, $target);
            }
            $steps[] = array('action' => 'extract', 'status' => 'done', 'message' => 'Extracted successfully');
        } else {
            $steps[] = array('action' => 'extract', 'status' => 'failed', 'message' => 'Failed to extract zip');
        }
    } else {
        $steps[] = array('action' => 'download', 'status' => 'failed', 'message' => 'Failed to download Nextcloud');
    }

    return $steps;
}