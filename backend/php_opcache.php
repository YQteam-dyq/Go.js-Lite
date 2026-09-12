<?php

function gojs_opcache_status_raw() {
    if (!function_exists('opcache_get_status')) {
        return false;
    }
    $status = @opcache_get_status(false);
    return is_array($status) ? $status : false;
}

function gojs_opcache_available() {
    return gojs_opcache_status_raw() !== false;
}

function gojs_opcache_hit_rate($hits, $misses) {
    $hits = (int)$hits;
    $misses = (int)$misses;
    $total = $hits + $misses;
    if ($total <= 0) {
        return null;
    }
    return round($hits / $total, 4);
}

function gojs_opcache_summary($status) {
    $out = array(
        'enabled' => false,
        'hits' => 0,
        'misses' => 0,
        'hit_rate' => null,
        'cached_scripts' => 0,
        'used_memory' => null,
        'free_memory' => null,
        'wasted_memory' => null,
        'oom_restarts' => 0,
        'hash_restarts' => 0,
        'manual_restarts' => 0,
        'jit' => null,
    );
    if (!is_array($status)) {
        return $out;
    }
    $out['enabled'] = !empty($status['opcache_enabled']);
    $stats = (isset($status['opcache_statistics']) && is_array($status['opcache_statistics']))
        ? $status['opcache_statistics'] : array();
    $out['hits'] = isset($stats['hits']) ? (int)$stats['hits'] : 0;
    $out['misses'] = isset($stats['misses']) ? (int)$stats['misses'] : 0;
    $out['hit_rate'] = gojs_opcache_hit_rate($out['hits'], $out['misses']);
    $out['cached_scripts'] = isset($stats['num_cached_scripts']) ? (int)$stats['num_cached_scripts'] : 0;
    $out['oom_restarts'] = isset($stats['oom_restarts']) ? (int)$stats['oom_restarts'] : 0;
    $out['hash_restarts'] = isset($stats['hash_restarts']) ? (int)$stats['hash_restarts'] : 0;
    $out['manual_restarts'] = isset($stats['manual_restarts']) ? (int)$stats['manual_restarts'] : 0;
    $mem = (isset($status['memory_usage']) && is_array($status['memory_usage'])) ? $status['memory_usage'] : array();
    $out['used_memory'] = isset($mem['used_memory']) ? (int)$mem['used_memory'] : null;
    $out['free_memory'] = isset($mem['free_memory']) ? (int)$mem['free_memory'] : null;
    $out['wasted_memory'] = isset($mem['wasted_memory']) ? (int)$mem['wasted_memory'] : null;
    if (isset($status['jit']) && is_array($status['jit'])) {
        $out['jit'] = $status['jit'];
    }
    return $out;
}

function gojs_opcache_unavailable_response() {
    gojs_json_response(null, array(
        'code' => 'opcache_unavailable',
        'message' => 'OPcache is unavailable (extension not loaded, or not enabled for this SAPI)',
        'hint' => 'Build PHP with --enable-opcache and enable zend_extension=opcache plus opcache.enable in php.ini; for CLI also set opcache.enable_cli=1',
    ), 501);
}

function gojs_opcache_profile_targets() {
    return array(
        'opcache.enable' => '1',
        'opcache.memory_consumption' => '256',
        'opcache.interned_strings_buffer' => '16',
        'opcache.max_accelerated_files' => '20000',
        'opcache.validate_timestamps' => '0',
        'opcache.jit' => 'tracing',
        'opcache.jit_buffer_size' => '256M',
    );
}

function gojs_api_php_opcache_status() {
    $raw = gojs_opcache_status_raw();
    if ($raw === false) {
        gojs_opcache_unavailable_response();
    }
    gojs_json_response(array(
        'available' => true,
        'summary' => gojs_opcache_summary($raw),
        'raw' => $raw,
        'config' => array(
            'opcache.enable' => ini_get('opcache.enable'),
            'opcache.memory_consumption' => ini_get('opcache.memory_consumption'),
            'opcache.jit' => ini_get('opcache.jit'),
            'opcache.jit_buffer_size' => ini_get('opcache.jit_buffer_size'),
        ),
    ));
}

function gojs_api_php_opcache_reset() {
    if (!gojs_opcache_available() || !function_exists('opcache_reset')) {
        gojs_opcache_unavailable_response();
    }
    $ok = @opcache_reset();
    gojs_log_operation('php.opcache_reset', 'php/opcache', (bool)$ok);
    if (!$ok) {
        gojs_json_response(null, array(
            'code' => 'opcache_reset_failed',
            'message' => 'OPcache reset failed (possibly restricted by opcache.restrict_api)',
        ), 501);
    }
    gojs_json_response(array('reset' => true, 'summary' => gojs_opcache_summary(gojs_opcache_status_raw())));
}

function gojs_api_php_opcache_toggle() {
    $body = gojs_get_body();
    $enable = null;
    if (isset($body['enable'])) {
        $enable = (bool)$body['enable'];
    } elseif (isset($body['state'])) {
        $state = strtolower((string)$body['state']);
        if (!in_array($state, array('on', 'off', 'enable', 'disable', '1', '0'), true)) {
            gojs_json_response(null, array(
                'code' => 'invalid_state',
                'message' => 'state must be on or off',
            ), 400);
        }
        $enable = in_array($state, array('on', 'enable', '1'), true);
    }
    if ($enable === null) {
        gojs_json_response(null, array(
            'code' => 'invalid_state',
            'message' => 'Provide a boolean enable field or state=on|off',
        ), 400);
    }
    if (!gojs_opcache_available()) {
        gojs_opcache_unavailable_response();
    }
    $applied = gojs_ini_try_set('opcache.enable', $enable ? '1' : '0');
    gojs_log_operation('php.opcache_toggle', 'php/opcache', $applied['applied'], $enable ? 'on' : 'off');
    $payload = array(
        'target' => $enable ? 'on' : 'off',
        'runtime' => $applied,
        'effective' => $applied['applied'],
        'current' => ini_get('opcache.enable'),
        'reload_required' => !$applied['applied'],
    );
    if (!$applied['applied']) {
        gojs_json_response($payload, array(
            'code' => 'ini_readonly',
            'message' => 'opcache.enable cannot be changed at runtime for this SAPI; edit php.ini and restart PHP',
        ), 501);
    }
    gojs_json_response($payload);
}

function gojs_api_php_opcache_profile() {
    $applied = array();
    $required = array();
    foreach (gojs_opcache_profile_targets() as $directive => $value) {
        $one = gojs_ini_try_set($directive, $value);
        if ($one['applied']) {
            $applied[] = $one;
        } else {
            $required[] = $one;
        }
    }
    gojs_log_operation('php.opcache_profile', 'php/opcache', !empty($applied), 'applied=' . count($applied));
    $payload = array(
        'applied' => $applied,
        'php_ini_required' => $required,
        'profile' => 'tracing+256M',
    );
    if (empty($applied)) {
        gojs_json_response($payload, array(
            'code' => 'ini_readonly',
            'message' => 'This SAPI does not allow changing OPcache settings at runtime; write them to php.ini and restart PHP',
        ), 501);
    }
    gojs_json_response($payload);
}
