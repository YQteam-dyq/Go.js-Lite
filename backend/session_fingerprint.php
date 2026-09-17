<?php

function gojs_session_fingerprint_settings() {
    $settings = array(
        'enforce' => true,
        'rotate_seconds' => 900,
        'signals' => array('ip', 'ua', 'lang'),
    );

    if (!isset($GLOBALS['config']) || !is_array($GLOBALS['config'])
        || !isset($GLOBALS['config']['session_fingerprint']) || !is_array($GLOBALS['config']['session_fingerprint'])) {
        return $settings;
    }

    $configured = $GLOBALS['config']['session_fingerprint'];

    if (array_key_exists('enforce', $configured)) {
        $settings['enforce'] = (bool)$configured['enforce'];
    }
    if (isset($configured['rotate_seconds']) && is_numeric($configured['rotate_seconds'])) {
        $settings['rotate_seconds'] = max(60, (int)$configured['rotate_seconds']);
    }
    if (isset($configured['signals']) && is_array($configured['signals'])) {
        $allowed = array('ip', 'ua', 'lang');
        $signals = array();
        foreach ($configured['signals'] as $name) {
            if (is_string($name) && in_array($name, $allowed, true) && !in_array($name, $signals, true)) {
                $signals[] = $name;
            }
        }
        $settings['signals'] = $signals;
    }

    return $settings;
}

function gojs_session_fingerprint_ip_prefix($ip) {
    $ip = trim((string)$ip);
    if ($ip === '') {
        return '';
    }

    if (strpos($ip, ':') !== false) {
        $prefix = array();
        foreach (explode(':', $ip) as $part) {
            if ($part === '') {
                break;
            }
            $prefix[] = strtolower($part);
            if (count($prefix) === 3) {
                break;
            }
        }
        return implode(':', $prefix);
    }

    if (preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\./', $ip, $matches)) {
        return $matches[1] . '.' . $matches[2] . '.' . $matches[3];
    }

    return '';
}

function gojs_session_fingerprint_language($server) {
    $accept = isset($server['HTTP_ACCEPT_LANGUAGE']) ? (string)$server['HTTP_ACCEPT_LANGUAGE'] : '';
    if (trim($accept) === '') {
        return '';
    }
    $first = explode(',', $accept);
    $tag = explode(';', $first[0]);
    return strtolower(trim($tag[0]));
}

function gojs_session_fingerprint_signals($server = null) {
    if ($server === null) {
        $server = $_SERVER;
    }

    $settings = gojs_session_fingerprint_settings();
    $signals = array();

    foreach ($settings['signals'] as $name) {
        if ($name === 'ip') {
            $ip = function_exists('gojs_get_client_ip')
                ? gojs_get_client_ip()
                : (isset($server['REMOTE_ADDR']) ? $server['REMOTE_ADDR'] : '');
            $signals['ip'] = gojs_session_fingerprint_ip_prefix($ip);
            continue;
        }
        if ($name === 'ua') {
            $ua = isset($server['HTTP_USER_AGENT']) ? (string)$server['HTTP_USER_AGENT'] : '';
            $signals['ua'] = $ua === '' ? '' : hash('sha256', $ua);
            continue;
        }
        if ($name === 'lang') {
            $signals['lang'] = gojs_session_fingerprint_language($server);
        }
    }

    return $signals;
}

function gojs_session_fingerprint_compute($salt, $signals) {
    $parts = array();
    foreach ($signals as $name => $value) {
        $parts[] = $name . '=' . $value;
    }
    return hash('sha256', (string)$salt . '|' . implode('&', $parts));
}

function gojs_session_fingerprint_new_salt() {
    return bin2hex(random_bytes(32));
}

function gojs_session_fingerprint_state() {
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        return array(
            'salt' => '',
            'value' => '',
            'issued_at' => 0,
            'rotations' => 0,
            'mismatches' => 0,
            'last_mismatch_at' => 0,
        );
    }

    return array(
        'salt' => isset($_SESSION['fp_salt']) ? (string)$_SESSION['fp_salt'] : '',
        'value' => isset($_SESSION['fp_value']) ? (string)$_SESSION['fp_value'] : '',
        'issued_at' => isset($_SESSION['fp_issued_at']) ? (int)$_SESSION['fp_issued_at'] : 0,
        'rotations' => isset($_SESSION['fp_rotations']) ? (int)$_SESSION['fp_rotations'] : 0,
        'mismatches' => isset($_SESSION['fp_mismatches']) ? (int)$_SESSION['fp_mismatches'] : 0,
        'last_mismatch_at' => isset($_SESSION['fp_last_mismatch_at']) ? (int)$_SESSION['fp_last_mismatch_at'] : 0,
    );
}

function gojs_session_fingerprint_state_clear() {
    foreach (array(
        'fp_salt', 'fp_value', 'fp_issued_at', 'fp_rotations',
        'fp_mismatches', 'fp_last_mismatch_at',
    ) as $key) {
        unset($_SESSION[$key]);
    }
}

function gojs_session_fingerprint_issue($now, $signals, $replace) {
    $salt = gojs_session_fingerprint_new_salt();
    $state = gojs_session_fingerprint_state();

    $_SESSION['fp_salt'] = $salt;
    $_SESSION['fp_value'] = gojs_session_fingerprint_compute($salt, $signals);
    $_SESSION['fp_issued_at'] = (int)$now;
    $_SESSION['fp_rotations'] = $replace ? $state['rotations'] + 1 : 0;

    return array(
        'salt' => $salt,
        'value' => $_SESSION['fp_value'],
        'issued_at' => (int)$now,
        'rotations' => $_SESSION['fp_rotations'],
    );
}

function gojs_session_fingerprint_destroy() {
    if (function_exists('gojs_log_operation')) {
        gojs_log_operation(
            'session_fingerprint_mismatch',
            isset($_SESSION['user_id']) ? $_SESSION['user_id'] : '',
            false
        );
    }

    $_SESSION = array();

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_unset();
        session_destroy();
    }
}

function gojs_session_fingerprint_verify($now = null, $server = null) {
    $now = $now === null ? time() : (int)$now;
    $settings = gojs_session_fingerprint_settings();

    $result = array(
        'ok' => true,
        'code' => 'ok',
        'enforced' => (bool)$settings['enforce'],
        'issued' => false,
        'rotated' => false,
        'signals' => $settings['signals'],
        'issued_at' => 0,
        'age' => 0,
        'rotations' => 0,
        'mismatches' => 0,
        'rotate_seconds' => (int)$settings['rotate_seconds'],
    );

    if (empty($_SESSION['authenticated']) && empty($_SESSION['access_token_valid'])) {
        $result['code'] = 'not_applicable';
        return $result;
    }

    $signals = gojs_session_fingerprint_signals($server);
    $state = gojs_session_fingerprint_state();

    if ($state['salt'] === '' || $state['value'] === '') {
        $issued = gojs_session_fingerprint_issue($now, $signals, false);
        $result['issued'] = true;
        $result['issued_at'] = $issued['issued_at'];
        $result['rotations'] = $issued['rotations'];
        return $result;
    }

    $result['issued_at'] = $state['issued_at'];
    $result['age'] = max(0, $now - $state['issued_at']);
    $result['rotations'] = $state['rotations'];
    $result['mismatches'] = $state['mismatches'];

    if (gojs_session_fingerprint_compute($state['salt'], $signals) === $state['value']) {
        if ($result['age'] >= (int)$settings['rotate_seconds']) {
            $issued = gojs_session_fingerprint_issue($now, $signals, true);
            $result['rotated'] = true;
            $result['issued_at'] = $issued['issued_at'];
            $result['age'] = 0;
            $result['rotations'] = $issued['rotations'];
        }
        return $result;
    }

    $result['mismatches'] = $state['mismatches'] + 1;
    $_SESSION['fp_mismatches'] = $result['mismatches'];
    $_SESSION['fp_last_mismatch_at'] = $now;

    if (!empty($settings['enforce'])) {
        $result['ok'] = false;
        $result['code'] = 'session_fingerprint_mismatch';
        gojs_session_fingerprint_destroy();
        return $result;
    }

    $issued = gojs_session_fingerprint_issue($now, $signals, false);
    $result['code'] = 'session_fingerprint_refreshed';
    $result['issued'] = true;
    $result['issued_at'] = $issued['issued_at'];
    $result['age'] = 0;
    $result['rotations'] = $issued['rotations'];

    return $result;
}

function gojs_session_fingerprint_enforce() {
    $result = gojs_session_fingerprint_verify();

    if (!empty($result['ok'])) {
        return $result;
    }

    gojs_json_response(null, array(
        'code' => $result['code'],
        'message' => 'This session was opened from another client, please sign in again',
        'fingerprint' => array(
            'rotations' => $result['rotations'],
            'mismatches' => $result['mismatches'],
            'signals' => $result['signals'],
        ),
    ), 401);
}

function gojs_session_fingerprint_report() {
    $settings = gojs_session_fingerprint_settings();
    $state = gojs_session_fingerprint_state();
    $now = time();

    return array(
        'bound' => $state['value'] !== '',
        'signals' => $settings['signals'],
        'enforce' => (bool)$settings['enforce'],
        'rotateSeconds' => (int)$settings['rotate_seconds'],
        'issuedAt' => $state['issued_at'],
        'age' => $state['issued_at'] > 0 ? max(0, $now - $state['issued_at']) : 0,
        'rotations' => $state['rotations'],
        'mismatches' => $state['mismatches'],
        'lastMismatchAt' => $state['last_mismatch_at'],
    );
}

function gojs_api_session_fingerprint() {
    if (strtoupper(gojs_get_method()) === 'POST') {
        gojs_session_fingerprint_issue(time(), gojs_session_fingerprint_signals(), true);
        gojs_log_operation('session_fingerprint_rotate', '', true);
    }

    gojs_json_response(gojs_session_fingerprint_report());
}
