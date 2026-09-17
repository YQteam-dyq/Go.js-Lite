<?php

function gojs_security_headers_settings() {
    $settings = array(
        'disable' => array(),
        'overrides' => array(),
        'csp' => array(),
    );

    if (!isset($GLOBALS['config']) || !is_array($GLOBALS['config'])) {
        return $settings;
    }

    if (!isset($GLOBALS['config']['security_headers']) || !is_array($GLOBALS['config']['security_headers'])) {
        return $settings;
    }

    $configured = $GLOBALS['config']['security_headers'];

    if (isset($configured['disable']) && is_array($configured['disable'])) {
        foreach ($configured['disable'] as $name) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            $settings['disable'][] = strtolower(trim($name));
        }
    }

    if (isset($configured['overrides']) && is_array($configured['overrides'])) {
        foreach ($configured['overrides'] as $name => $value) {
            if (!is_string($name) || trim($name) === '' || !is_string($value)) {
                continue;
            }
            $settings['overrides'][trim($name)] = $value;
        }
    }

    if (isset($configured['csp']) && is_array($configured['csp'])) {
        foreach ($configured['csp'] as $context => $value) {
            if (!is_string($context) || trim($context) === '' || !is_string($value) || trim($value) === '') {
                continue;
            }
            $settings['csp'][trim($context)] = trim($value);
        }
    }

    return $settings;
}

function gojs_security_headers_is_https() {
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    return false;
}

function gojs_security_headers_defaults() {
    return array(
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'accelerometer=(), autoplay=(), camera=(), display-capture=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), usb=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'X-Permitted-Cross-Domain-Policies' => 'none',
    );
}

function gojs_security_headers_contexts() {
    return array('api', 'html');
}

function gojs_security_headers_csp($context) {
    $policies = array(
        'api' => "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'; object-src 'none'",
        'html' => "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; img-src 'self' data: blob:; font-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; media-src 'self' blob:; worker-src 'self' blob:",
    );

    $settings = gojs_security_headers_settings();
    if (isset($settings['csp'][$context])) {
        return $settings['csp'][$context];
    }
    if (isset($policies[$context])) {
        return $policies[$context];
    }
    return $policies['api'];
}

function gojs_security_headers_policy($context) {
    $settings = gojs_security_headers_settings();
    $policy = gojs_security_headers_defaults();

    if (gojs_security_headers_is_https()) {
        $policy['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
    }

    $policy['Content-Security-Policy'] = gojs_security_headers_csp($context);

    foreach ($settings['overrides'] as $name => $value) {
        $matched = null;
        foreach ($policy as $existing => $current) {
            if (strcasecmp($existing, $name) === 0) {
                $matched = $existing;
                break;
            }
        }
        if ($matched !== null) {
            $policy[$matched] = $value;
            continue;
        }
        $policy[$name] = $value;
    }

    if (!empty($settings['disable'])) {
        foreach ($policy as $name => $value) {
            if (in_array(strtolower($name), $settings['disable'], true)) {
                unset($policy[$name]);
            }
        }
    }

    return $policy;
}

function gojs_security_headers_build($context) {
    $lines = array();
    foreach (gojs_security_headers_policy($context) as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }
    return $lines;
}

function gojs_security_headers_build_base() {
    $policy = gojs_security_headers_policy('api');
    unset($policy['Content-Security-Policy']);

    $lines = array();
    foreach ($policy as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }
    return $lines;
}

function gojs_security_headers_reset() {
    $GLOBALS['gojs_security_headers_emitted'] = array();
}

function gojs_security_headers_send($lines, $sender) {
    if (!isset($GLOBALS['gojs_security_headers_emitted']) || !is_array($GLOBALS['gojs_security_headers_emitted'])) {
        $GLOBALS['gojs_security_headers_emitted'] = array();
    }

    $emitted = $GLOBALS['gojs_security_headers_emitted'];

    foreach ($lines as $line) {
        $separator = strpos($line, ':');
        if ($separator === false) {
            continue;
        }
        $name = strtolower(trim(substr($line, 0, $separator)));
        if ($name === '' || isset($emitted[$name])) {
            continue;
        }
        $emitted[$name] = true;
        if ($sender !== null) {
            call_user_func($sender, $line);
            continue;
        }
        if (!headers_sent()) {
            header($line);
        }
    }

    $GLOBALS['gojs_security_headers_emitted'] = $emitted;
}

function gojs_security_headers_apply_base($sender = null) {
    gojs_security_headers_send(gojs_security_headers_build_base(), $sender);
}

function gojs_security_headers_apply($context = 'api', $sender = null) {
    gojs_security_headers_send(gojs_security_headers_build($context), $sender);
}

function gojs_security_headers_report() {
    $contexts = array();
    foreach (gojs_security_headers_contexts() as $context) {
        $contexts[$context] = gojs_security_headers_policy($context);
    }

    return array(
        'https' => gojs_security_headers_is_https(),
        'defaultContext' => 'api',
        'headers' => gojs_security_headers_policy('api'),
        'contexts' => $contexts,
        'count' => count(gojs_security_headers_policy('api')),
    );
}

function gojs_api_security_headers() {
    gojs_json_response(gojs_security_headers_report());
}
