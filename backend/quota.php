<?php

function gojs_quota_path() {
    return CONFIG_DIR . '/quota';
}

function gojs_quota_ensure_dir() {
    $dir = gojs_quota_path();
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

function gojs_quota_check($user, $verb) {
    if (!is_array($user) || empty($user['role'])) {
        return array('allow' => true);
    }
    $role = $user['role'];
    if ($role === 'admin') {
        return array('allow' => true);
    }
    $limits = array(
        'viewer'   => array('get' => 5,   'write' => 1),
        'operator' => array('get' => 30,  'write' => 5),
    );
    if (!isset($limits[$role])) {
        return array('allow' => true);
    }
    $is_write = in_array(strtoupper($verb), array('POST','PUT','PATCH','DELETE'), true);
    $limit = $is_write ? $limits[$role]['write'] : $limits[$role]['get'];

    $uid = isset($user['id']) ? $user['id'] : 'unknown';
    $minute = (int)floor(time() / 60);
    $bucket = $minute;
    $cur = 0;
    gojs_quota_ensure_dir();
    foreach (array($bucket, $bucket - 1) as $b) {
        $file = gojs_quota_path() . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $uid) . '_' . $b . '.json';
        if (file_exists($file)) {
            $raw = @file_get_contents($file);
            $d = $raw ? json_decode($raw, true) : null;
            if (is_array($d) && isset($d['count'])) {
                $cur += (int)$d['count'];
            }
        }
    }
    if ($cur >= $limit) {
        return array(
            'allow' => false,
            'limit_get' => $limits[$role]['get'],
            'limit_write' => $limits[$role]['write'],
            'reset_in' => 60 - (time() % 60),
            'count' => $cur,
        );
    }

    $file = gojs_quota_path() . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $uid) . '_' . $bucket . '.json';
    $data = array('count' => 1, 'last_ts' => time());
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        $d = $raw ? json_decode($raw, true) : null;
        if (is_array($d) && isset($d['count'])) {
            $data['count'] = (int)$d['count'] + 1;
        }
    }
    @file_put_contents($file, json_encode($data), LOCK_EX);

    $entries = glob(gojs_quota_path() . '/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $uid) . '_*.json');
    if (is_array($entries)) {
        foreach ($entries as $f) {
            if (preg_match('/_(\d+)\.json$/', $f, $mm)) {
                if ((int)$mm[1] < $bucket - 1) @unlink($f);
            }
        }
    }

    return array(
        'allow' => true,
        'limit_get' => $limits[$role]['get'],
        'limit_write' => $limits[$role]['write'],
        'remaining' => max(0, $limit - $cur - 1),
    );
}

function gojs_quota_enforce() {
    $u = function_exists('gojs_current_user') ? gojs_current_user() : null;
    if (!$u) return;
    $verb = function_exists('gojs_get_method') ? gojs_get_method() : 'GET';
    $r = gojs_quota_check($u, $verb);
    if (!empty($r['allow'])) {
        if (isset($r['limit_get'])) {
            header('X-RateLimit-Limit-Get: ' . $r['limit_get']);
            header('X-RateLimit-Limit-Write: ' . $r['limit_write']);
            if (isset($r['remaining'])) header('X-RateLimit-Remaining: ' . $r['remaining']);
        }
        return;
    }
    header('Retry-After: ' . (int)$r['reset_in']);
    header('X-RateLimit-Limit-Get: ' . (int)$r['limit_get']);
    header('X-RateLimit-Limit-Write: ' . (int)$r['limit_write']);
    header('X-RateLimit-Remaining: 0');
    gojs_json_response(null, array(
        'code' => 'too_many_requests',
        'message' => 'Too many requests, please retry in ' . (int)$r['reset_in'] . ' seconds',
        'retry_after' => (int)$r['reset_in'],
    ), 429);
}
