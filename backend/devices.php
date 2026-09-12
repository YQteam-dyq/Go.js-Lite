<?php

function gojs_devices_ttl() {
    return 14 * 86400;
}

function gojs_devices_current_ip() {
    return function_exists('gojs_get_client_ip') ? gojs_get_client_ip() : '';
}

function gojs_devices_current_ua() {
    return isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
}

function gojs_devices_fingerprint($ip, $ua, $first_seen_at) {
    return hash('sha256', (string)$ip . '|' . (string)$ua . '|' . (int)$first_seen_at);
}

function gojs_devices_prune_list($devices, $now = null) {
    if (!is_array($devices)) return array();
    $now = $now === null ? time() : $now;
    $out = array();
    foreach ($devices as $d) {
        if (!is_array($d)) continue;
        if (!empty($d['expires_at']) && (int)$d['expires_at'] < $now) continue;
        $out[] = $d;
    }
    return array_values($out);
}

function gojs_devices_list($user_id) {
    $user = function_exists('gojs_users_find_by_id') ? gojs_users_find_by_id($user_id) : null;
    if (!$user) return array();
    $devices = isset($user['trusted_devices']) && is_array($user['trusted_devices']) ? $user['trusted_devices'] : array();
    return gojs_devices_prune_list($devices);
}

function gojs_devices_mark_current($device) {
    $target = gojs_devices_current_fingerprint();
    if ($target === '' || !is_array($device)) return $device;
    $device['current'] = isset($device['fingerprint']) && $device['fingerprint'] === $target;
    return $device;
}

function gojs_devices_current_fingerprint() {
    $user_id = function_exists('gojs_current_user_id') ? gojs_current_user_id() : null;
    if (!$user_id) return '';
    $devices = gojs_devices_list($user_id);
    $ip = gojs_devices_current_ip();
    $ua = gojs_devices_current_ua();
    foreach ($devices as $d) {
        if ((isset($d['ip']) ? $d['ip'] : '') === $ip && (isset($d['ua']) ? $d['ua'] : '') === $ua) {
            return isset($d['fingerprint']) ? $d['fingerprint'] : '';
        }
    }
    return '';
}

function gojs_devices_save_for_user($user_id, $devices) {
    $user = function_exists('gojs_users_find_by_id') ? gojs_users_find_by_id($user_id) : null;
    if (!$user) return false;
    $user['trusted_devices'] = array_values($devices);
    return function_exists('gojs_users_upsert') ? gojs_users_upsert($user) : false;
}

function gojs_devices_trust($user_id, $ttl = null) {
    $user = function_exists('gojs_users_find_by_id') ? gojs_users_find_by_id($user_id) : null;
    if (!$user) {
        return array('ok' => false, 'code' => 'user_not_found');
    }
    $ttl = $ttl === null ? gojs_devices_ttl() : (int)$ttl;
    if ($ttl <= 0) $ttl = gojs_devices_ttl();

    $now = time();
    $ip = gojs_devices_current_ip();
    $ua = gojs_devices_current_ua();
    $devices = gojs_devices_prune_list(
        isset($user['trusted_devices']) && is_array($user['trusted_devices']) ? $user['trusted_devices'] : array(),
        $now
    );

    $existing = null;
    foreach ($devices as $i => $d) {
        if ((isset($d['ip']) ? $d['ip'] : '') === $ip && (isset($d['ua']) ? $d['ua'] : '') === $ua) {
            $existing = $i;
            break;
        }
    }

    if ($existing !== null) {
        $fp = isset($devices[$existing]['fingerprint']) ? $devices[$existing]['fingerprint'] : gojs_devices_fingerprint($ip, $ua, $now);
        $devices[$existing]['trusted_at'] = $now;
        $devices[$existing]['expires_at'] = $now + $ttl;
        $entry = $devices[$existing];
    } else {
        $fp = gojs_devices_fingerprint($ip, $ua, $now);
        $entry = array(
            'fingerprint' => $fp,
            'ip' => $ip,
            'ua' => $ua,
            'first_seen_at' => $now,
            'trusted_at' => $now,
            'expires_at' => $now + $ttl,
        );
        $devices[] = $entry;
    }

    if (!gojs_devices_save_for_user($user_id, $devices)) {
        return array('ok' => false, 'code' => 'write_failed');
    }
    return array('ok' => true, 'device' => $entry);
}

function gojs_devices_revoke($user_id, $fingerprint) {
    $fingerprint = trim((string)$fingerprint);
    if ($fingerprint === '') {
        return array('ok' => false, 'code' => 'invalid_fingerprint');
    }
    $user = function_exists('gojs_users_find_by_id') ? gojs_users_find_by_id($user_id) : null;
    if (!$user) {
        return array('ok' => false, 'code' => 'user_not_found');
    }
    $devices = isset($user['trusted_devices']) && is_array($user['trusted_devices']) ? $user['trusted_devices'] : array();
    $found = false;
    $out = array();
    foreach ($devices as $d) {
        if (isset($d['fingerprint']) && $d['fingerprint'] === $fingerprint) {
            $found = true;
            continue;
        }
        $out[] = $d;
    }
    if (!$found) {
        return array('ok' => false, 'code' => 'not_found');
    }
    if (!gojs_devices_save_for_user($user_id, $out)) {
        return array('ok' => false, 'code' => 'write_failed');
    }
    return array('ok' => true);
}

function gojs_devices_is_trusted($user_id) {
    $ip = gojs_devices_current_ip();
    $ua = gojs_devices_current_ua();
    foreach (gojs_devices_list($user_id) as $d) {
        if ((isset($d['ip']) ? $d['ip'] : '') === $ip && (isset($d['ua']) ? $d['ua'] : '') === $ua) {
            return true;
        }
    }
    return false;
}

function gojs_api_devices_list() {
    $uid = function_exists('gojs_current_user_id') ? gojs_current_user_id() : null;
    if (!$uid) {
        gojs_json_response(null, array('code' => 'unauthorized', 'message' => 'Please sign in first'), 401);
    }
    $rows = array();
    foreach (gojs_devices_list($uid) as $d) {
        $rows[] = gojs_devices_mark_current($d);
    }
    gojs_json_response(array(
        'devices' => $rows,
        'total' => count($rows),
        'ttl_days' => (int)(gojs_devices_ttl() / 86400),
    ));
}

function gojs_api_devices_trust() {
    $uid = function_exists('gojs_current_user_id') ? gojs_current_user_id() : null;
    if (!$uid) {
        gojs_json_response(null, array('code' => 'unauthorized', 'message' => 'Please sign in first'), 401);
    }
    $result = gojs_devices_trust($uid);
    if (empty($result['ok'])) {
        gojs_json_response(null, array('code' => $result['code'], 'message' => 'Failed to trust the device'), 500);
    }
    gojs_log_operation('device.trust', $result['device']['fingerprint'], true);
    gojs_json_response(gojs_devices_mark_current($result['device']), null, 201);
}

function gojs_api_devices_revoke($fingerprint) {
    $uid = function_exists('gojs_current_user_id') ? gojs_current_user_id() : null;
    if (!$uid) {
        gojs_json_response(null, array('code' => 'unauthorized', 'message' => 'Please sign in first'), 401);
    }
    $result = gojs_devices_revoke($uid, $fingerprint);
    if (empty($result['ok'])) {
        $status = $result['code'] === 'not_found' ? 404 : 400;
        gojs_json_response(null, array('code' => $result['code'], 'message' => 'Failed to revoke the device'), $status);
    }
    gojs_log_operation('device.revoke', $fingerprint, true);
    gojs_json_response(array('success' => true));
}

function gojs_api_devices_route($api, $method) {
    if ($api === 'devices/trust') {
        if ($method !== 'POST') {
            gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => 'Method not allowed'), 405);
        }
        gojs_api_devices_trust();
        return;
    }
    if (preg_match('#^devices/([A-Za-z0-9_]+)$#', $api, $m)) {
        if ($method !== 'DELETE') {
            gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => 'Method not allowed'), 405);
        }
        gojs_api_devices_revoke($m[1]);
        return;
    }
    if ($api === 'devices') {
        if ($method === 'GET') gojs_api_devices_list();
        else gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => 'Method not allowed'), 405);
        return;
    }
    gojs_json_response(null, array('code' => 'not_found', 'message' => 'Unknown API action'), 404);
}
