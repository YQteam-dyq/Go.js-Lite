<?php

function gojs_deprecation_registry() {
    return array(
        'query_api' => array(
            'id' => 'query_api',
            'feature' => 'Query-style API endpoint',
            'target' => 'api.php?api=<action>',
            'replacement' => '/api/<action>',
            'surfaces' => array(
                'api.php?api=<action>',
                '<any entry point>?api=<action>',
            ),
            'deprecated_in' => '0.8.0',
            'deprecated_at' => '2026-09-17T00:00:00Z',
            'remove_in' => '1.0.0',
            'sunset_at' => '2027-06-30T00:00:00Z',
            'docs' => 'docs/deprecations.md#query_api',
            'message' => 'The ?api=<action> query form is deprecated. Send the action as the request path, for example /api/<action>.',
        ),
        'legacy_access_token' => array(
            'id' => 'legacy_access_token',
            'feature' => 'Legacy access token',
            'target' => '?token=<access_token>',
            'replacement' => 'X-API-Token: <token> or Authorization: Bearer <token>',
            'surfaces' => array(
                '?token=<access_token>',
                'X-Access-Token: <access_token>',
                'POST /api/regenerate-access-token',
                'config key access_token',
            ),
            'deprecated_in' => '0.8.0',
            'deprecated_at' => '2026-09-17T00:00:00Z',
            'remove_in' => '1.0.0',
            'sunset_at' => '2027-06-30T00:00:00Z',
            'docs' => 'docs/deprecations.md#legacy_access_token',
            'message' => 'The legacy access token is deprecated. Use a scoped API token (X-API-Token or Authorization: Bearer) or an authenticated session.',
        ),
    );
}

function gojs_deprecation_ids() {
    return array_keys(gojs_deprecation_registry());
}

function gojs_deprecation_get($id) {
    $registry = gojs_deprecation_registry();

    if (!is_string($id) || !isset($registry[$id])) {
        return null;
    }

    return $registry[$id];
}

function gojs_deprecation_http_date($iso8601) {
    $timestamp = is_string($iso8601) ? strtotime($iso8601) : false;

    if ($timestamp === false || $timestamp <= 0) {
        return '';
    }

    return gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';
}

function gojs_deprecation_notice($entry) {
    return array(
        'id' => $entry['id'],
        'feature' => $entry['feature'],
        'target' => $entry['target'],
        'replacement' => $entry['replacement'],
        'surfaces' => isset($entry['surfaces']) ? array_values($entry['surfaces']) : array(),
        'deprecatedIn' => $entry['deprecated_in'],
        'removeIn' => $entry['remove_in'],
        'sunsetAt' => $entry['sunset_at'],
        'docs' => $entry['docs'],
        'message' => $entry['message'],
        'deprecated' => true,
    );
}

function gojs_deprecation_payload() {
    $payload = array();

    foreach (gojs_deprecation_registry() as $id => $entry) {
        $payload[$id] = gojs_deprecation_notice($entry);
    }

    return $payload;
}

function gojs_deprecation_emitted() {
    if (!isset($GLOBALS['gojs_deprecations_emitted']) || !is_array($GLOBALS['gojs_deprecations_emitted'])) {
        return array();
    }

    return $GLOBALS['gojs_deprecations_emitted'];
}

function gojs_deprecation_header_names($headers) {
    $names = array();

    foreach ($headers as $header) {
        $parts = explode(':', $header, 2);
        $names[] = strtolower(trim($parts[0]));
    }

    return $names;
}

function gojs_deprecation_emit($id) {
    $entry = gojs_deprecation_get($id);

    if (!is_array($entry)) {
        return false;
    }

    if (!isset($GLOBALS['gojs_deprecations_emitted']) || !is_array($GLOBALS['gojs_deprecations_emitted'])) {
        $GLOBALS['gojs_deprecations_emitted'] = array();
    }

    if (isset($GLOBALS['gojs_deprecations_emitted'][$id])) {
        return true;
    }

    $GLOBALS['gojs_deprecations_emitted'][$id] = gojs_deprecation_notice($entry);

    if (headers_sent()) {
        return true;
    }

    $sent = gojs_deprecation_header_names(headers_list());
    $deprecated_at = gojs_deprecation_http_date($entry['deprecated_at']);
    $sunset_at = gojs_deprecation_http_date($entry['sunset_at']);

    if ($deprecated_at !== '' && !in_array('deprecation', $sent, true)) {
        header('Deprecation: ' . $deprecated_at);
    }

    if ($sunset_at !== '' && !in_array('sunset', $sent, true)) {
        header('Sunset: ' . $sunset_at);
    }

    header('Link: <' . $entry['docs'] . '>; rel="deprecation"; type="text/markdown"', false);
    header('X-Gojs-Deprecations: ' . implode(', ', array_keys($GLOBALS['gojs_deprecations_emitted'])));

    return true;
}

function gojs_request_api_style() {
    $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
    $path = (string)parse_url($uri, PHP_URL_PATH);
    $path = str_replace('\\', '/', $path);

    if ($path !== '' && preg_match('#(?:^|/)api(/|$)#', $path) === 1) {
        return 'path';
    }

    $query = isset($_SERVER['QUERY_STRING']) ? (string)$_SERVER['QUERY_STRING'] : '';
    if ($query !== '' && preg_match('/(?:^|&)api=/i', $query) === 1) {
        return 'query';
    }

    if (isset($_GET['api']) && is_string($_GET['api']) && $_GET['api'] !== '') {
        return 'query';
    }

    return 'path';
}
