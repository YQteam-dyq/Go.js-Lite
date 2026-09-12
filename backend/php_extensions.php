<?php

function gojs_php_favorites_path() {
    return CONFIG_DIR . '/php_favorites.json';
}

function gojs_php_favorites_load() {
    $path = gojs_php_favorites_path();
    if (!file_exists($path)) {
        return array('extensions' => array());
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return array('extensions' => array());
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array('extensions' => array());
    }
    if (!isset($decoded['extensions']) || !is_array($decoded['extensions'])) {
        $decoded['extensions'] = array();
    }
    return $decoded;
}

function gojs_php_favorites_save($store) {
    if (!is_dir(CONFIG_DIR)) {
        @mkdir(CONFIG_DIR, 0700, true);
    }
    $json = json_encode($store, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (@file_put_contents(gojs_php_favorites_path(), $json, LOCK_EX) === false) {
        return false;
    }
    @chmod(gojs_php_favorites_path(), 0600);
    return true;
}

function gojs_php_zend_extensions() {
    if (!function_exists('get_loaded_extensions')) {
        return array();
    }
    $zend = @get_loaded_extensions(true);
    if (!is_array($zend)) {
        return array();
    }
    $set = array();
    foreach ($zend as $z) {
        $set[strtolower((string)$z)] = true;
    }
    return $set;
}

function gojs_php_extensions_list() {
    if (!function_exists('get_loaded_extensions')) {
        return array();
    }
    $names = @get_loaded_extensions(false);
    if (!is_array($names)) {
        return array();
    }
    $zend = gojs_php_zend_extensions();
    $favorites = gojs_php_favorites_load();
    $fav = array();
    foreach ($favorites['extensions'] as $f) {
        $fav[strtolower((string)$f)] = true;
    }
    $rows = array();
    foreach ($names as $name) {
        $name = (string)$name;
        $rows[] = array(
            'name' => $name,
            'version' => gojs_php_extension_version($name),
            'zend' => isset($zend[strtolower($name)]),
            'favorite' => isset($fav[strtolower($name)]),
        );
    }
    usort($rows, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return $rows;
}

function gojs_php_extension_version($name) {
    if (!function_exists('phpversion')) {
        return null;
    }
    $v = @phpversion($name);
    return ($v === false) ? null : (string)$v;
}

function gojs_php_favorites_normalize($names) {
    if (!is_array($names)) {
        return array();
    }
    $out = array();
    foreach ($names as $n) {
        if (!is_string($n)) {
            continue;
        }
        $n = trim($n);
        if ($n === '') {
            continue;
        }
        $out[] = $n;
    }
    return array_values(array_unique($out));
}

function gojs_api_php_extensions_list() {
    $rows = gojs_php_extensions_list();
    $favorites = gojs_php_favorites_load();
    gojs_json_response(array(
        'count' => count($rows),
        'zend_count' => count(gojs_php_zend_extensions()),
        'favorites' => $favorites['extensions'],
        'extensions' => $rows,
    ));
}

function gojs_api_php_extensions_favorite() {
    $body = gojs_get_body();
    $name = isset($body['name']) ? trim((string)$body['name']) : '';
    if ($name === '') {
        gojs_json_response(null, array('code' => 'invalid_name', 'message' => 'Missing extension name'), 400);
    }
    $loaded = array();
    foreach (gojs_php_extensions_list() as $row) {
        $loaded[strtolower($row['name'])] = $row['name'];
    }
    if (!isset($loaded[strtolower($name)])) {
        gojs_json_response(null, array('code' => 'extension_not_loaded', 'message' => 'Extension is not loaded: ' . $name), 404);
    }
    $name = $loaded[strtolower($name)];
    $store = gojs_php_favorites_load();
    $current = $store['extensions'];
    $has = false;
    foreach ($current as $c) {
        if (strcasecmp((string)$c, $name) === 0) {
            $has = true;
            break;
        }
    }
    $favorite = isset($body['favorite']) ? (bool)$body['favorite'] : !$has;
    if ($favorite) {
        if (!$has) {
            $current[] = $name;
        }
    } else {
        $next = array();
        foreach ($current as $c) {
            if (strcasecmp((string)$c, $name) !== 0) {
                $next[] = $c;
            }
        }
        $current = $next;
    }
    $store['extensions'] = array_values($current);
    if (!gojs_php_favorites_save($store)) {
        gojs_json_response(null, array('code' => 'write_failed', 'message' => 'Failed to write php_favorites.json'), 500);
    }
    gojs_log_operation('php.extension_favorite', $name, true, $favorite ? 'on' : 'off');
    gojs_json_response(array('name' => $name, 'favorite' => $favorite, 'favorites' => $store['extensions']));
}
