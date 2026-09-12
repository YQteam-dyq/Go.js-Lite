<?php

function gojs_autoload_registered_names($autoloadSource) {
    $names = array();
    if (!is_string($autoloadSource) || $autoloadSource === '') {
        return $names;
    }
    if (preg_match_all('/__DIR__\s*\.\s*[\'"]([^\'"]+\.php)[\'"]/', $autoloadSource, $m)) {
        foreach ($m[1] as $rel) {
            $names[basename(str_replace('\\', '/', $rel))] = true;
        }
    }
    return $names;
}

function gojs_autoload_statement_target($line) {
    if (!preg_match('/\b(require|require_once|include|include_once)\b\s*\(?\s*(.+)$/', $line, $m)) {
        return null;
    }
    if (!preg_match('/[\'"]([^\'"]+\.php)[\'"]/', $m[2], $t)) {
        return null;
    }
    return array(
        'statement' => trim($m[0]),
        'target' => $t[1],
    );
}

function gojs_autoload_audit_scan($dir, $autoloadSource, $rootLabel = null) {
    $registered = gojs_autoload_registered_names($autoloadSource);
    $out = array(
        'registered' => array(),
        'unregistered' => array(),
        'suggestions' => array(),
    );
    if (!is_dir($dir)) {
        return $out;
    }
    $files = @glob(rtrim($dir, '/\\') . '/*.php');
    if (!is_array($files)) {
        return $out;
    }
    sort($files);
    $prefix = ($rootLabel !== null) ? rtrim($rootLabel, '/\\') . '/' : '';
    foreach ($files as $file) {
        $src = @file_get_contents($file);
        if (!is_string($src)) {
            continue;
        }
        $rel = $prefix . basename($file);
        $lines = preg_split('/\r?\n/', $src);
        foreach ($lines as $i => $line) {
            $hit = gojs_autoload_statement_target($line);
            if ($hit === null) {
                continue;
            }
            $name = basename(str_replace('\\', '/', $hit['target']));
            $entry = array(
                'file' => $rel,
                'line' => $i + 1,
                'statement' => $hit['statement'],
                'target' => $hit['target'],
            );
            if (isset($registered[$name]) || $name === 'autoload.php') {
                $entry['suggestion'] = 'already-in-autoload';
                $out['registered'][] = $entry;
                continue;
            }
            $suggestion = (strpos($hit['target'], 'vendor/') !== false) ? 'composer autoload' : 'move to autoload.php';
            $entry['suggestion'] = $suggestion;
            $out['unregistered'][] = $entry;
            $out['suggestions'][] = $entry;
        }
    }
    return $out;
}

function gojs_api_php_autoload_audit() {
    if (!defined('PANEL_ROOT')) {
        gojs_json_response(null, array('code' => 'no_panel_root', 'message' => 'PANEL_ROOT is not defined'), 500);
    }
    $backendDir = rtrim(PANEL_ROOT, '/\\') . '/backend';
    $autoloadPath = $backendDir . '/autoload.php';
    $autoloadSource = file_exists($autoloadPath) ? @file_get_contents($autoloadPath) : '';
    $scan = gojs_autoload_audit_scan($backendDir, $autoloadSource, 'backend');
    $composerAutoload = file_exists(rtrim(PANEL_ROOT, '/\\') . '/vendor/autoload.php');
    gojs_json_response(array(
        'backend_dir' => $backendDir,
        'autoload_file' => $autoloadPath,
        'vendor_autoload_present' => $composerAutoload,
        'registered_count' => count($scan['registered']),
        'unregistered_count' => count($scan['unregistered']),
        'registered' => $scan['registered'],
        'suggestions' => $scan['suggestions'],
    ));
}
