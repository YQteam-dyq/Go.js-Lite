<?php

function gojs_activity_log_path() {
    return CONFIG_DIR . '/operation_log.json';
}

function gojs_activity_load_entries() {
    $path = gojs_activity_log_path();
    if (!file_exists($path)) {
        return array();
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return array();
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array();
    }
    if (isset($decoded['logs']) && is_array($decoded['logs'])) {
        $decoded = $decoded['logs'];
    }
    $out = array();
    foreach ($decoded as $e) {
        if (!is_array($e)) continue;
        $out[] = $e;
    }
    usort($out, function ($a, $b) {
        $ta = isset($a['timestamp']) ? (int)$a['timestamp'] : 0;
        $tb = isset($b['timestamp']) ? (int)$b['timestamp'] : 0;
        return $tb <=> $ta;
    });
    return $out;
}

function gojs_activity_since_ts($since) {
    if (function_exists('gojs_php_errors_since_ts')) {
        return gojs_php_errors_since_ts($since);
    }
    if (!is_string($since) || trim($since) === '') {
        return null;
    }
    $since = trim($since);
    if (preg_match('/^(\d+)([smhd])$/', $since, $m)) {
        $mult = array('s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400);
        return time() - ((int)$m[1]) * $mult[$m[2]];
    }
    $ts = @strtotime($since);
    return ($ts === false) ? null : $ts;
}

function gojs_user_activity_feed($user_id = null, $sinceTs = null, $limit = 100) {
    $limit = max(1, min(500, (int)$limit));
    $out = array();
    foreach (gojs_activity_load_entries() as $e) {
        if ($user_id !== null && !isset($e['user_id'])) continue;
        if ($user_id !== null && (string)$e['user_id'] !== (string)$user_id) continue;
        if ($sinceTs !== null && (int)(isset($e['timestamp']) ? $e['timestamp'] : 0) < $sinceTs) continue;
        $out[] = $e;
        if (count($out) >= $limit) break;
    }
    return $out;
}

function gojs_user_activity_aggregate($sinceTs = null, $by = 'user_id', $limit = 50) {
    $allowed = array('user_id', 'action', 'hour');
    if (!in_array($by, $allowed, true)) {
        $by = 'user_id';
    }
    $map = array();
    $total = 0;
    $failed = 0;
    foreach (gojs_activity_load_entries() as $e) {
        $ts = (int)(isset($e['timestamp']) ? $e['timestamp'] : 0);
        if ($sinceTs !== null && $ts < $sinceTs) continue;
        $total++;
        $isFail = isset($e['result']) && $e['result'] === false;
        if ($isFail) $failed++;
        if ($by === 'user_id') {
            $key = isset($e['user_id']) && $e['user_id'] !== '' ? (string)$e['user_id'] : 'admin';
        } elseif ($by === 'action') {
            $key = isset($e['action']) && $e['action'] !== '' ? (string)$e['action'] : 'unknown';
        } else {
            $key = $ts > 0 ? gmdate('Y-m-d\TH:00:00\Z', (int)($ts - ($ts % 3600))) : 'unknown';
        }
        if (!isset($map[$key])) {
            $map[$key] = array('key' => $key, 'total' => 0, 'failed' => 0, 'last_at' => 0);
        }
        $map[$key]['total']++;
        if ($isFail) $map[$key]['failed']++;
        if ($ts > $map[$key]['last_at']) $map[$key]['last_at'] = $ts;
    }
    $rows = array_values($map);
    foreach ($rows as $i => $r) {
        $rows[$i]['error_rate'] = $r['total'] > 0 ? round($r['failed'] / $r['total'], 4) : 0.0;
    }
    usort($rows, function ($a, $b) {
        return $b['total'] <=> $a['total'];
    });
    return array(
        'total' => $total,
        'failed' => $failed,
        'rows' => array_slice($rows, 0, max(1, (int)$limit)),
    );
}

function gojs_user_activity_online() {
    $currentSid = isset($_COOKIE[session_name()]) ? (string)$_COOKIE[session_name()] : '';
    $currentFp = ($currentSid !== '') ? substr(hash('sha256', $currentSid), 0, 8) : '';
    $rows = array();
    $seen = array();
    if (function_exists('gojs_sessions_collect')) {
        foreach (gojs_sessions_collect() as $row) {
            $fp = substr(hash('sha256', (string)$row['sid']), 0, 8);
            if ($fp === '' || isset($seen[$fp])) continue;
            $seen[$fp] = true;
            $rows[] = gojs_sessions_row($row['sid'], $row['payload'], $currentFp, $row['mtime']);
        }
    }
    if ($currentFp !== '' && !isset($seen[$currentFp])) {
        $rows[] = gojs_sessions_row($currentSid, $_SESSION, $currentFp);
    }
    return $rows;
}

function gojs_api_audit_aggregate() {
    $since = isset($_GET['since']) ? (string)$_GET['since'] : '24h';
    $by = isset($_GET['by']) ? (string)$_GET['by'] : 'user_id';
    if (!in_array($by, array('user_id', 'action', 'hour'), true)) {
        gojs_json_response(null, array('code' => 'invalid_by', 'message' => 'by must be user_id, action or hour'), 400);
    }
    $sinceTs = gojs_activity_since_ts($since);
    $agg = gojs_user_activity_aggregate($sinceTs, $by);
    gojs_log_operation('audit.aggregate', 'audit', true, 'by=' . $by . ' total=' . $agg['total']);
    gojs_json_response(array(
        'since' => $since,
        'since_ts' => $sinceTs,
        'by' => $by,
        'total' => $agg['total'],
        'failed' => $agg['failed'],
        'rows' => $agg['rows'],
    ));
}

function gojs_api_user_activity_online() {
    $rows = gojs_user_activity_online();
    gojs_json_response(array('sessions' => $rows, 'total' => count($rows)));
}

function gojs_api_user_activity_recent() {
    $since = isset($_GET['since']) ? (string)$_GET['since'] : '24h';
    $sinceTs = gojs_activity_since_ts($since);
    $agg = gojs_user_activity_aggregate($sinceTs, 'user_id');
    gojs_json_response(array(
        'since' => $since,
        'since_ts' => $sinceTs,
        'total' => $agg['total'],
        'failed' => $agg['failed'],
        'rows' => $agg['rows'],
    ));
}

function gojs_api_user_activity_user($user_id) {
    $since = isset($_GET['since']) ? (string)$_GET['since'] : '7d';
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $sinceTs = gojs_activity_since_ts($since);
    $entries = gojs_user_activity_feed($user_id, $sinceTs, $limit);
    $user = function_exists('gojs_users_find_by_id') ? gojs_users_find_by_id($user_id) : null;
    gojs_json_response(array(
        'user_id' => $user_id,
        'username' => $user ? $user['username'] : ($user_id === 'admin' ? 'admin' : null),
        'role' => $user && isset($user['role']) ? $user['role'] : null,
        'since' => $since,
        'since_ts' => $sinceTs,
        'count' => count($entries),
        'entries' => $entries,
    ));
}

function gojs_api_user_activity_route($api, $method) {
    if ($method !== 'GET') {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => 'Method not allowed'), 405);
    }
    $sub = ltrim(substr($api, strlen('user_activity')), '/');
    if ($sub === '' || $sub === 'recent') {
        gojs_api_user_activity_recent();
        return;
    }
    if ($sub === 'online') {
        gojs_api_user_activity_online();
        return;
    }
    if (preg_match('#^([A-Za-z0-9_\-]+)$#', $sub, $m)) {
        gojs_api_user_activity_user($m[1]);
        return;
    }
    gojs_json_response(null, array('code' => 'not_found', 'message' => 'Unknown API action'), 404);
}
