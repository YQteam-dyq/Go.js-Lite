<?php

function gojs_notifications_levels() {
    return array(
        'off' => 0,
        'debug' => 1,
        'info' => 2,
        'warning' => 3,
        'critical' => 4,
    );
}

function gojs_notifications_categories() {
    return array('security', 'auth', 'system', 'files', 'backup', 'cron');
}

function gojs_notifications_channels() {
    return array('email', 'inapp');
}

function gojs_notifications_default_for_role($role) {
    $on = in_array($role, array('admin'), true);
    $severity = $on ? 'info' : 'off';
    $channel = array('severity_min' => $severity, 'categories' => array());
    return array('email' => $channel, 'inapp' => $channel);
}

function gojs_notifications_severity_rank($severity) {
    $levels = gojs_notifications_levels();
    return isset($levels[$severity]) ? $levels[$severity] : 0;
}

function gojs_notifications_normalize_channel($raw, $fallback) {
    if (!is_array($raw)) return $fallback;
    $levels = gojs_notifications_levels();
    $severity = isset($raw['severity_min']) ? strtolower(trim((string)$raw['severity_min'])) : $fallback['severity_min'];
    if (!isset($levels[$severity])) {
        $severity = $fallback['severity_min'];
    }
    $allowed = gojs_notifications_categories();
    $categories = array();
    if (isset($raw['categories']) && is_array($raw['categories'])) {
        foreach ($raw['categories'] as $c) {
            if (!is_string($c)) continue;
            $c = strtolower(trim($c));
            if ($c === '') continue;
            if (in_array($c, $allowed, true)) $categories[] = $c;
        }
        $categories = array_values(array_unique($categories));
    }
    return array('severity_min' => $severity, 'categories' => $categories);
}

function gojs_notifications_normalize($raw, $role = 'viewer') {
    $fallback = gojs_notifications_default_for_role($role);
    if (!is_array($raw)) return $fallback;
    $out = array();
    foreach (gojs_notifications_channels() as $channel) {
        $out[$channel] = gojs_notifications_normalize_channel(
            isset($raw[$channel]) ? $raw[$channel] : null,
            $fallback[$channel]
        );
    }
    return $out;
}

function gojs_notifications_effective($user) {
    $role = (is_array($user) && isset($user['role'])) ? $user['role'] : 'viewer';
    if (is_array($user) && !empty($user['preferences']['notifications']) && is_array($user['preferences']['notifications'])) {
        return gojs_notifications_normalize($user['preferences']['notifications'], $role);
    }
    return gojs_notifications_default_for_role($role);
}

function gojs_notifications_should_deliver($user, $severity, $category, $channel = 'inapp') {
    $config = gojs_notifications_effective($user);
    if (!isset($config[$channel])) return false;
    $cfg = $config[$channel];
    $min = gojs_notifications_severity_rank($cfg['severity_min']);
    if ($min <= 0) return false;
    if (gojs_notifications_severity_rank($severity) < $min) return false;
    if (!empty($cfg['categories']) && !in_array($category, $cfg['categories'], true)) return false;
    return true;
}

function gojs_api_notification_preferences_get() {
    $user = function_exists('gojs_current_user') ? gojs_current_user() : null;
    if (!$user) {
        gojs_json_response(null, array('code' => 'unauthorized', 'message' => '请先登录'), 401);
    }
    $role = isset($user['role']) ? $user['role'] : 'viewer';
    gojs_json_response(array(
        'notifications' => gojs_notifications_effective($user),
        'defaults' => gojs_notifications_default_for_role($role),
        'levels' => array_keys(gojs_notifications_levels()),
        'categories' => gojs_notifications_categories(),
        'role' => $role,
    ));
}

function gojs_api_notification_preferences_update() {
    $user = function_exists('gojs_current_user') ? gojs_current_user() : null;
    if (!$user) {
        gojs_json_response(null, array('code' => 'unauthorized', 'message' => '请先登录'), 401);
    }
    $role = isset($user['role']) ? $user['role'] : 'viewer';
    $body = gojs_get_body();
    $incoming = isset($body['notifications']) && is_array($body['notifications']) ? $body['notifications'] : $body;

    $prefs = isset($user['preferences']) && is_array($user['preferences']) ? $user['preferences'] : array();
    $current = isset($prefs['notifications']) && is_array($prefs['notifications'])
        ? gojs_notifications_normalize($prefs['notifications'], $role)
        : gojs_notifications_default_for_role($role);

    $merged = array();
    foreach (gojs_notifications_channels() as $channel) {
        $base = $current[$channel];
        if (isset($incoming[$channel]) && is_array($incoming[$channel])) {
            $candidate = array_merge($base, $incoming[$channel]);
            $merged[$channel] = gojs_notifications_normalize_channel($candidate, $base);
        } else {
            $merged[$channel] = $base;
        }
    }

    $prefs['notifications'] = $merged;
    $user['preferences'] = $prefs;
    if (function_exists('gojs_users_upsert')) {
        gojs_users_upsert($user);
    }
    gojs_log_operation('notifications.update', $user['id'], true);
    gojs_json_response(array(
        'notifications' => $merged,
        'defaults' => gojs_notifications_default_for_role($role),
        'levels' => array_keys(gojs_notifications_levels()),
        'categories' => gojs_notifications_categories(),
        'role' => $role,
    ));
}

function gojs_api_notification_preferences_route($api, $method) {
    if ($api !== 'notification-preferences') {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'API 不存在'), 404);
    }
    if ($method === 'GET') {
        gojs_api_notification_preferences_get();
    } elseif ($method === 'PATCH' || $method === 'PUT' || $method === 'POST') {
        gojs_api_notification_preferences_update();
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}
