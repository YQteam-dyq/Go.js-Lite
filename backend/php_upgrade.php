<?php

function gojs_upgrade_parse_php_constraint($constraint) {
    if (!is_string($constraint) || trim($constraint) === '') {
        return null;
    }
    if (!preg_match_all('/\d+\.\d+(?:\.\d+)?/', $constraint, $m)) {
        return null;
    }
    $min = null;
    foreach ($m[0] as $v) {
        if ($min === null || version_compare($v, $min, '<')) {
            $min = $v;
        }
    }
    return $min;
}

function gojs_upgrade_recommended($current, $requiredMin) {
    foreach (array('8.2', '8.3') as $candidate) {
        if ($requiredMin !== null && version_compare($candidate, $requiredMin, '<')) {
            continue;
        }
        if (version_compare($candidate, $current, '>')) {
            return $candidate;
        }
    }
    return $current;
}

function gojs_upgrade_feature_rules() {
    return array(
        array('pattern' => '/\benum\s+[A-Za-z_]/', 'min' => '8.1', 'msg' => 'enum 语法需要 PHP 8.1+'),
        array('pattern' => '/\breadonly\s+(public|protected|private|\$)/', 'min' => '8.1', 'msg' => 'readonly 属性需要 PHP 8.1+'),
        array('pattern' => '/\)\s*:\s*never\b/', 'min' => '8.1', 'msg' => 'never 返回类型需要 PHP 8.1+'),
        array('pattern' => '/\bmatch\s*\(/', 'min' => '8.0', 'msg' => 'match 表达式需要 PHP 8.0+'),
        array('pattern' => '/\?->/', 'min' => '8.0', 'msg' => 'nullsafe 运算符需要 PHP 8.0+'),
        array('pattern' => '/function\s+__construct\s*\([^)]*\b(public|protected|private)\s+\$/', 'min' => '8.0', 'msg' => '构造器属性提升需要 PHP 8.0+'),
        array('pattern' => '/#\[\s*\\\\?Attribute\b/', 'min' => '8.0', 'msg' => 'Attribute 需要 PHP 8.0+'),
    );
}

function gojs_upgrade_scan_source($source, $currentVersion, $label = '') {
    $rules = gojs_upgrade_feature_rules();
    $lines = preg_split('/\r?\n/', (string)$source);
    $out = array();
    foreach ($lines as $i => $line) {
        foreach ($rules as $rule) {
            if (!preg_match($rule['pattern'], $line)) {
                continue;
            }
            if (version_compare($currentVersion, $rule['min'], '>=')) {
                continue;
            }
            $out[] = array(
                'file' => $label,
                'line' => $i + 1,
                'msg' => $rule['msg'],
                'requires' => $rule['min'],
            );
        }
    }
    return $out;
}

function gojs_upgrade_scan_dir($dir, $currentVersion, $maxFiles = 400) {
    $blockers = array();
    if (!is_dir($dir)) {
        return $blockers;
    }
    $files = @glob(rtrim($dir, '/\\') . '/*.php');
    if (!is_array($files)) {
        return $blockers;
    }
    $count = 0;
    foreach ($files as $file) {
        if ($count >= $maxFiles) {
            break;
        }
        $count++;
        $src = @file_get_contents($file);
        if (!is_string($src)) {
            continue;
        }
        $rel = (defined('PANEL_ROOT') && strpos($file, PANEL_ROOT) === 0)
            ? ltrim(substr($file, strlen(PANEL_ROOT)), '/\\')
            : $file;
        foreach (gojs_upgrade_scan_source($src, $currentVersion, $rel) as $b) {
            $blockers[] = $b;
        }
    }
    return $blockers;
}

function gojs_api_php_upgrade_check() {
    $json = function_exists('gojs_composer_read_json_file') ? gojs_composer_read_json_file('composer.json') : null;
    $constraint = null;
    if (is_array($json) && isset($json['require']['php'])) {
        $constraint = (string)$json['require']['php'];
    }
    $requiredMin = gojs_upgrade_parse_php_constraint($constraint);
    $current = PHP_VERSION;
    $blockers = array();
    if ($requiredMin !== null && version_compare($current, $requiredMin, '<')) {
        $blockers[] = array(
            'file' => 'composer.json',
            'line' => null,
            'msg' => '项目要求 PHP >= ' . $requiredMin . '，当前为 ' . $current,
            'requires' => $requiredMin,
        );
    }
    $scanRoot = (defined('PANEL_ROOT') ? rtrim(PANEL_ROOT, '/\\') : '') . '/backend';
    foreach (gojs_upgrade_scan_dir($scanRoot, $current) as $b) {
        $blockers[] = $b;
    }
    gojs_json_response(array(
        'current' => $current,
        'required_constraint' => $constraint,
        'required_min' => $requiredMin,
        'recommended' => gojs_upgrade_recommended($current, $requiredMin),
        'upgrade_needed' => $requiredMin !== null && version_compare($current, $requiredMin, '<'),
        'blocker_count' => count($blockers),
        'blockers' => $blockers,
    ));
}
