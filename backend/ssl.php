<?php

// SSL/ACME: certificate issuance, renewal, domain management, ACME client.
// Split from api.php; keep original function signatures and behavior unchanged.

function gojs_api_ssl_check() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = array();
    }
    $domain = isset($input['domain']) ? trim($input['domain']) : '';

    if (empty($domain)) {
        gojs_json_response(null, array(
            'code' => 'domain_required',
            'message' => '请提供域名',
        ), 400);
    }

    // Validate domain format (allows localhost, IPs, and TLD-less intranet domains).
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]*(\.[a-zA-Z]{2,})?$/', $domain)) {
        gojs_json_response(null, array(
            'code' => 'invalid_domain',
            'message' => '域名格式无效',
        ), 400);
    }

    if (!extension_loaded('openssl')) {
        gojs_json_response(array(
            'domain' => $domain,
            'enabled' => false,
            'status' => 'failed',
            'error' => 'openssl_not_available',
            'error_key' => 'openssl_unavailable',
            'message' => 'OpenSSL 扩展不可用，无法检测 SSL 证书',
        ));
    }

    $port = 443;
    $timeout = 10;

    $context = stream_context_create(array(
        'ssl' => array(
            'capture_peer_cert' => true,
            'capture_peer_chain' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ),
    ));

    $socket = @stream_socket_client(
        "ssl://{$domain}:{$port}",
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if ($socket === false) {
        gojs_json_response(array(
            'domain' => $domain,
            'enabled' => false,
            'status' => 'failed',
            'error' => 'connection_failed',
            'error_key' => 'connect_failed',
            'error_params' => array('detail' => "{$domain}:443 — {$errstr}"),
            'message' => "无法连接到 {$domain}:443 — {$errstr}",
        ));
    }

    $params = stream_context_get_params($socket);
    fclose($socket);

    if (!isset($params['options']['ssl']['peer_certificate'])) {
        gojs_json_response(array(
            'domain' => $domain,
            'enabled' => false,
            'status' => 'failed',
            'error' => 'no_certificate',
            'error_key' => 'certificate_empty',
            'message' => '未获取到证书数据',
        ));
    }

    $cert = $params['options']['ssl']['peer_certificate'];
    $cert_info = openssl_x509_parse($cert);

    if (!$cert_info) {
        gojs_json_response(array(
            'domain' => $domain,
            'enabled' => false,
            'status' => 'failed',
            'error' => 'parse_failed',
            'error_key' => 'certificate_parse_error',
            'message' => '证书解析失败',
        ));
    }

    $valid_from = $cert_info['validFrom_time_t'];
    $valid_to = $cert_info['validTo_time_t'];
    $now = time();
    $days_remaining = (int)(($valid_to - $now) / 86400);

    // Chain completeness.
    $chain_complete = isset($params['options']['ssl']['peer_certificate_chain']) &&
                      count($params['options']['ssl']['peer_certificate_chain']) > 1;

    // Certificate health status.
    $cert_status = 'ok';
    if ($days_remaining < 0) {
        $cert_status = 'expired';
    } else if ($days_remaining < 7) {
        $cert_status = 'critical';
    } else if ($days_remaining < 14) {
        $cert_status = 'warning';
    }

    gojs_json_response(array(
        'domain' => $domain,
        'enabled' => true,
        'status' => 'ok',
        'cert_status' => $cert_status,
        'issuer' => isset($cert_info['issuer']['CN']) ? $cert_info['issuer']['CN'] : (isset($cert_info['issuer']['O']) ? $cert_info['issuer']['O'] : 'Unknown'),
        'subject' => isset($cert_info['subject']['CN']) ? $cert_info['subject']['CN'] : $domain,
        'valid_from' => date('Y-m-d', $valid_from),
        'valid_to' => date('Y-m-d', $valid_to),
        'days_remaining' => $days_remaining,
        'chain_complete' => $chain_complete,
    ));
}

function gojs_api_ssl_list() {
    global $config;

    $domains = isset($config['ssl_domains']) ? $config['ssl_domains'] : array();
    if (!is_array($domains)) {
        $domains = array();
    }

    $current_domain = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    // Strip the port, if present.
    if ($current_domain && strpos($current_domain, ':') !== false) {
        $current_domain = substr($current_domain, 0, strpos($current_domain, ':'));
    }
    if ($current_domain && !in_array($current_domain, $domains)) {
        array_unshift($domains, $current_domain);
    }

    gojs_json_response(array('domains' => array_values($domains)));
}

function gojs_api_ssl_add_domain() {
    global $config;

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = array();
    }
    $domain = isset($input['domain']) ? trim($input['domain']) : '';

    if (empty($domain)) {
        gojs_json_response(null, array(
            'code' => 'domain_required',
            'message' => '请提供域名',
        ), 400);
    }

    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]*(\.[a-zA-Z]{2,})?$/', $domain)) {
        gojs_json_response(null, array(
            'code' => 'invalid_domain',
            'message' => '域名格式无效',
        ), 400);
    }

    if (!isset($config['ssl_domains']) || !is_array($config['ssl_domains'])) {
        $config['ssl_domains'] = array();
    }
    if (!in_array($domain, $config['ssl_domains'])) {
        $config['ssl_domains'][] = $domain;
        gojs_save_config();
    }

    gojs_log_operation('ssl_add_domain', $domain, true);
    gojs_json_response(array('ok' => true));
}

function gojs_api_ssl_remove_domain() {
    global $config;

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = array();
    }
    $domain = isset($input['domain']) ? trim($input['domain']) : '';

    if (isset($config['ssl_domains']) && is_array($config['ssl_domains'])) {
        $config['ssl_domains'] = array_values(array_filter($config['ssl_domains'], function($d) use ($domain) {
            return $d !== $domain;
        }));
        gojs_save_config();
    }

    gojs_log_operation('ssl_remove_domain', $domain, true);
    gojs_json_response(array('ok' => true));
}

function gojs_acme_docroot(): string {
    global $root_path;
    if (!empty($_SERVER['DOCUMENT_ROOT'])) {
        return rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']), '/');
    }
    if (is_string($root_path) && is_dir($root_path)) {
        return rtrim($root_path, '/');
    }
    return rtrim(PANEL_ROOT, '/');
}

function gojs_acme_challenges_docroot_dir(): string {
    $docroot = gojs_acme_docroot();
    return $docroot . '/.well-known/acme-challenge';
}

function gojs_acme_ensure_dirs(): void {
    if (!is_dir(CONFIG_DIR)) {
        @mkdir(CONFIG_DIR, 0700, true);
    }
    if (!is_dir(GOJS_ACME_CHALLENGES_DIR)) {
        @mkdir(GOJS_ACME_CHALLENGES_DIR, 0700, true);
    }
    $docroot_challenges = gojs_acme_challenges_docroot_dir();
    if (!is_dir($docroot_challenges)) {
        @mkdir($docroot_challenges, 0755, true);
    }
}

function gojs_acme_base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function gojs_acme_base64url_decode(string $data): string {
    $rest = strlen($data) % 4;
    if ($rest) {
        $data .= str_repeat('=', 4 - $rest);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function gojs_acme_dir(string $ca = 'letsencrypt'): array {
    static $cache = array();
    if (isset($cache[$ca])) {
        return $cache[$ca];
    }
    $urls = array(
        'letsencrypt' => 'https://acme-v02.api.letsencrypt.org/directory',
        'letsencrypt-staging' => 'https://acme-staging-v02.api.letsencrypt.org/directory',
    );
    $dir_url = isset($urls[$ca]) ? $urls[$ca] : $urls['letsencrypt'];

    $ch = curl_init($dir_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Go.js-ACME-Client/' . VERSION);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300 || !$body) {
        throw new RuntimeException('ACME directory discovery failed for ' . $ca . ': HTTP ' . $code);
    }
    $dir = json_decode($body, true);
    if (!is_array($dir)) {
        throw new RuntimeException('ACME directory returned invalid JSON');
    }
    $cache[$ca] = array(
        'newNonce' => isset($dir['newNonce']) ? $dir['newNonce'] : '',
        'newAccount' => isset($dir['newAccount']) ? $dir['newAccount'] : '',
        'newOrder' => isset($dir['newOrder']) ? $dir['newOrder'] : '',
        'revokeCert' => isset($dir['revokeCert']) ? $dir['revokeCert'] : '',
        'keyChange' => isset($dir['keyChange']) ? $dir['keyChange'] : '',
    );
    return $cache[$ca];
}

function gojs_acme_get_nonce(string $ca = 'letsencrypt', string $last_nonce = ''): string {
    static $last = array();
    if ($last_nonce !== '') {
        $last[$ca] = $last_nonce;
    }
    if (!empty($last[$ca])) {
        $n = $last[$ca];
        $last[$ca] = '';
        return $n;
    }
    $dir = gojs_acme_dir($ca);
    $ch = curl_init($dir['newNonce']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Go.js-ACME-Client/' . VERSION);
    $resp = curl_exec($ch);
    curl_close($ch);
    if (!$resp) return '';
    if (preg_match('/^Replay-Nonce:\s*(\S+)/mi', $resp, $m)) {
        return trim($m[1]);
    }
    return '';
}

function gojs_acme_rsa_jwk_from_pkey($pkey): array {
    $details = openssl_pkey_get_details($pkey);
    if (!$details || empty($details['rsa'])) {
        throw new RuntimeException('Unable to extract RSA key details');
    }
    $rsa = $details['rsa'];
    return array(
        'kty' => 'RSA',
        'n' => gojs_acme_base64url_encode($rsa['n']),
        'e' => gojs_acme_base64url_encode($rsa['e']),
    );
}

function gojs_acme_thumbprint(string $jwk_json_or_array): string {
    if (is_array($jwk_json_or_array)) {
        $jwk = $jwk_json_or_array;
    } else {
        $jwk = json_decode($jwk_json_or_array, true);
        if (!is_array($jwk)) $jwk = array();
    }
    $canonical = json_encode(array(
        'e' => isset($jwk['e']) ? $jwk['e'] : '',
        'kty' => isset($jwk['kty']) ? $jwk['kty'] : '',
        'n' => isset($jwk['n']) ? $jwk['n'] : '',
    ), JSON_UNESCAPED_SLASHES);
    $hash = hash('sha256', $canonical, true);
    return gojs_acme_base64url_encode($hash);
}

function gojs_acme_signed_request(string $url, $payload, $account_or_jwk, string $nonce, string $ca = 'letsencrypt', int $depth = 0): array {
    $is_jwk = is_array($account_or_jwk) && isset($account_or_jwk['kty']);
    $key_pem = $is_jwk ? '' : (isset($account_or_jwk['key_pem']) ? $account_or_jwk['key_pem'] : '');
    $pkey = openssl_pkey_get_private($key_pem);
    if (!$pkey) {
        throw new RuntimeException('Unable to load ACME account private key');
    }
    $jwk = $is_jwk ? $account_or_jwk : gojs_acme_rsa_jwk_from_pkey($pkey);

    $protected = array(
        'alg' => 'RS256',
        'nonce' => $nonce,
        'url' => $url,
    );
    if ($is_jwk) {
        $protected['jwk'] = $jwk;
    } else {
        if (empty($account_or_jwk['kid'])) {
            throw new RuntimeException('Account kid missing for signed request');
        }
        $protected['kid'] = $account_or_jwk['kid'];
    }

    if ($payload === '') {
        $payload_encoded = '';
    } elseif (is_array($payload)) {
        $payload_encoded = gojs_acme_base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    } else {
        $payload_encoded = gojs_acme_base64url_encode((string)$payload);
    }
    $protected_encoded = gojs_acme_base64url_encode(json_encode($protected, JSON_UNESCAPED_SLASHES));
    $signing_input = $protected_encoded . '.' . $payload_encoded;

    $signature = '';
    openssl_sign($signing_input, $signature, $pkey, 'sha256WithRSAEncryption');
    $sig_encoded = gojs_acme_base64url_encode($signature);

    $body = json_encode(array(
        'protected' => $protected_encoded,
        'payload' => $payload_encoded,
        'signature' => $sig_encoded,
    ));

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/jose+json',
        'Accept: application/json, application/problem+json',
    ));
    curl_setopt($ch, CURLOPT_USERAGENT, 'Go.js-ACME-Client/' . VERSION);
    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers_raw = substr($response, 0, $header_size);
    $resp_body = substr($response, $header_size);
    curl_close($ch);

    $new_nonce = '';
    if (preg_match('/^Replay-Nonce:\s*(\S+)/mi', $headers_raw, $m)) {
        $new_nonce = trim($m[1]);
        gojs_acme_get_nonce($ca, $new_nonce);
    }
    $location = '';
    if (preg_match('/^Location:\s*(\S+)/mi', $headers_raw, $m)) {
        $location = trim($m[1]);
    }
    $retry_after = 0;
    if (preg_match('/^Retry-After:\s*(\d+)/mi', $headers_raw, $m)) {
        $retry_after = (int)$m[1];
    }

    $parsed = json_decode($resp_body, true);
    $body_result = is_array($parsed) ? $parsed : $resp_body;

    if ($code >= 400 && is_array($body_result) && isset($body_result['type']) && $body_result['type'] === 'urn:ietf:params:acme:error:badNonce' && $depth === 0) {
        $new_nonce_retry = gojs_acme_get_nonce($ca);
        return gojs_acme_signed_request($url, $payload, $account_or_jwk, $new_nonce_retry, $ca, 1);
    }

    return array(
        'code' => $code,
        'body' => $body_result,
        'retry_after' => $retry_after,
        'location' => $location,
    );
}

function gojs_acme_load_account(): array {
    if (!file_exists(GOJS_ACME_ACCOUNT_FILE)) {
        return array();
    }
    $raw = @file_get_contents(GOJS_ACME_ACCOUNT_FILE);
    if (!$raw) return array();
    $data = json_decode($raw, true);
    if (!is_array($data)) return array();
    return $data;
}

function gojs_acme_save_account(array $account): void {
    gojs_acme_ensure_dirs();
    @file_put_contents(GOJS_ACME_ACCOUNT_FILE, json_encode($account, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    @chmod(GOJS_ACME_ACCOUNT_FILE, 0600);
}

function gojs_acme_ensure_account(string $email, string $ca = 'letsencrypt', bool $terms_agreed = true): array {
    gojs_acme_ensure_dirs();
    $stored = gojs_acme_load_account();
    $key_pem = '';
    $ca_key = $ca . '_' . md5(strtolower($email));

    if (isset($stored[$ca_key]) && is_array($stored[$ca_key])) {
        $existing = $stored[$ca_key];
        if (!empty($existing['account_key_pem_enc_sealed'])) {
            $pem = gojs_decrypt($existing['account_key_pem_enc_sealed']);
            if (is_string($pem) && $pem !== '' && !empty($existing['kid'])) {
                return array(
                    'account_url' => isset($existing['account_url']) ? $existing['account_url'] : '',
                    'kid' => $existing['kid'],
                    'account_key_pem_enc_sealed' => $existing['account_key_pem_enc_sealed'],
                    'key_pem_plain' => $pem,
                );
            }
        }
    }

    $pkey = openssl_pkey_new(array(
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ));
    if (!$pkey) {
        throw new RuntimeException('Failed to generate RSA account key');
    }
    $pem_export = '';
    openssl_pkey_export($pkey, $pem_export);
    if ($pem_export === '' || $pem_export === null) {
        throw new RuntimeException('Failed to export account private key');
    }
    $sealed = gojs_seal_secret($pem_export);
    $jwk = gojs_acme_rsa_jwk_from_pkey($pkey);

    $nonce = gojs_acme_get_nonce($ca);
    $dir = gojs_acme_dir($ca);
    $payload = array(
        'termsOfServiceAgreed' => $terms_agreed,
        'contact' => array('mailto:' . $email),
    );
    $account_wrap = array('key_pem' => $pem_export);
    $resp = gojs_acme_signed_request($dir['newAccount'], $payload, $jwk, $nonce, $ca);

    if ($resp['code'] !== 200 && $resp['code'] !== 201) {
        $msg = is_array($resp['body']) && isset($resp['body']['detail']) ? $resp['body']['detail'] : 'HTTP ' . $resp['code'];
        throw new RuntimeException('ACME newAccount failed: ' . $msg);
    }
    $kid = $resp['location'];
    if ($kid === '') {
        throw new RuntimeException('ACME server did not return account location (kid)');
    }

    $stored[$ca_key] = array(
        'email' => $email,
        'ca' => $ca,
        'kid' => $kid,
        'account_url' => $kid,
        'account_key_pem_enc_sealed' => $sealed,
        'jwk_thumbprint' => gojs_acme_thumbprint($jwk),
        'registered_at' => time(),
    );
    gojs_acme_save_account($stored);

    return array(
        'account_url' => $kid,
        'kid' => $kid,
        'account_key_pem_enc_sealed' => $sealed,
        'key_pem_plain' => $pem_export,
    );
}

function gojs_acme_load_certs(): array {
    if (!file_exists(GOJS_ACME_CERTS_FILE)) {
        return array();
    }
    $raw = @file_get_contents(GOJS_ACME_CERTS_FILE);
    if (!$raw) return array();
    $data = json_decode($raw, true);
    if (!is_array($data)) return array();
    return $data;
}

function gojs_acme_save_certs(array $records): void {
    gojs_acme_ensure_dirs();
    @file_put_contents(GOJS_ACME_CERTS_FILE, json_encode($records, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    @chmod(GOJS_ACME_CERTS_FILE, 0600);
}

function gojs_acme_place_http01(string $domain, string $token, $jwk): bool {
    gojs_acme_ensure_dirs();
    if (is_array($jwk) && isset($jwk['kty'])) {
        $thumb = gojs_acme_thumbprint($jwk);
    } else {
        $stored = gojs_acme_load_account();
        $first = reset($stored);
        $thumb = is_array($first) && isset($first['jwk_thumbprint']) ? $first['jwk_thumbprint'] : gojs_acme_thumbprint($jwk);
    }
    $content = $token . '.' . $thumb;

    $ok = false;

    $docroot_dir = gojs_acme_challenges_docroot_dir();
    if (!is_dir($docroot_dir)) {
        @mkdir($docroot_dir, 0755, true);
    }
    $target = $docroot_dir . '/' . $token;
    $wrote = @file_put_contents($target, $content);
    if ($wrote !== false) {
        @chmod($target, 0644);
        $ok = true;
    }

    $fallback_dir = GOJS_ACME_CHALLENGES_DIR;
    if (!is_dir($fallback_dir)) {
        @mkdir($fallback_dir, 0700, true);
    }
    @file_put_contents($fallback_dir . '/' . $token, $content);
    @chmod($fallback_dir . '/' . $token, 0600);

    return $ok;
}

function gojs_acme_order(string $domain, string $email, string $ca = 'letsencrypt'): array {
    $account = gojs_acme_ensure_account($email, $ca, true);
    $nonce = gojs_acme_get_nonce($ca);
    $dir = gojs_acme_dir($ca);

    $identifiers = array(array('type' => 'dns', 'value' => $domain));
    $payload = array('identifiers' => $identifiers);
    $account_wrap = array('key_pem' => $account['key_pem_plain'], 'kid' => $account['kid']);

    $resp = gojs_acme_signed_request($dir['newOrder'], $payload, $account_wrap, $nonce, $ca);

    if ($resp['code'] !== 201) {
        $msg = is_array($resp['body']) && isset($resp['body']['detail']) ? $resp['body']['detail'] : 'HTTP ' . $resp['code'];
        return array('ok' => false, 'error' => 'ACME newOrder failed: ' . $msg);
    }

    $order_url = $resp['location'];
    $body = is_array($resp['body']) ? $resp['body'] : array();
    $authorizations = isset($body['authorizations']) && is_array($body['authorizations']) ? $body['authorizations'] : array();

    $auth_details = array();
    foreach ($authorizations as $auth_url) {
        $auth_nonce = gojs_acme_get_nonce($ca);
        $auth_resp = gojs_acme_signed_request($auth_url, '', $account_wrap, $auth_nonce, $ca);
        if ($auth_resp['code'] === 200 && is_array($auth_resp['body'])) {
            $ab = $auth_resp['body'];
            $challenges = array();
            if (isset($ab['challenges']) && is_array($ab['challenges'])) {
                foreach ($ab['challenges'] as $c) {
                    if (is_array($c) && isset($c['type']) && $c['type'] === 'http-01') {
                        $challenges[] = array(
                            'type' => 'http-01',
                            'token' => isset($c['token']) ? $c['token'] : '',
                            'url' => isset($c['url']) ? $c['url'] : '',
                            'status' => isset($c['status']) ? $c['status'] : 'pending',
                        );
                    }
                }
            }
            $auth_details[] = array(
                'identifier' => isset($ab['identifier']) ? $ab['identifier'] : array(),
                'challenges' => $challenges,
                'auth_url' => $auth_url,
            );
        }
    }

    $finalize_url = isset($body['finalize']) ? $body['finalize'] : '';

    return array(
        'ok' => true,
        'order_url' => $order_url,
        'finalize_url' => $finalize_url,
        'authorizations' => $auth_details,
        '_account' => $account,
    );
}

function gojs_acme_respond_challenge(string $challenge_url, $account_wrap, string $ca = 'letsencrypt'): array {
    $nonce = gojs_acme_get_nonce($ca);
    return gojs_acme_signed_request($challenge_url, array(), $account_wrap, $nonce, $ca);
}

function gojs_acme_poll_url(string $url, $account_wrap, string $ca = 'letsencrypt', int $max_attempts = 15, int $sleep_sec = 2): array {
    for ($i = 0; $i < $max_attempts; $i++) {
        $nonce = gojs_acme_get_nonce($ca);
        $resp = gojs_acme_signed_request($url, '', $account_wrap, $nonce, $ca);
        $code = $resp['code'];
        $body = is_array($resp['body']) ? $resp['body'] : array();
        $status = isset($body['status']) ? $body['status'] : '';
        if ($code === 200 && ($status === 'valid' || $status === 'invalid' || $status === 'ready' || $status === 'processing')) {
            if ($status === 'valid' || $status === 'invalid' || $status === 'ready') {
                return $resp;
            }
        }
        $sleep = $resp['retry_after'] > 0 ? $resp['retry_after'] : $sleep_sec;
        if ($sleep > 0) sleep(min($sleep, 10));
    }
    return array('code' => 504, 'body' => array('error' => 'Poll timeout', 'detail' => 'Order/authorization did not reach valid/ready state in time'));
}

function gojs_acme_pem_to_der(string $pem): string {
    $lines = explode("\n", $pem);
    $der = '';
    $in = false;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (strpos($line, '-----BEGIN') === 0) {
            $in = true;
            continue;
        }
        if (strpos($line, '-----END') === 0) {
            break;
        }
        if ($in) $der .= $line;
    }
    return base64_decode($der);
}

function gojs_acme_finalize(string $finalize_url, string $csr_pem, $account_wrap, string $ca = 'letsencrypt'): array {
    $der = gojs_acme_pem_to_der($csr_pem);
    $payload = array('csr' => gojs_acme_base64url_encode($der));
    $nonce = gojs_acme_get_nonce($ca);
    return gojs_acme_signed_request($finalize_url, $payload, $account_wrap, $nonce, $ca);
}

function gojs_acme_fetch_certificate(string $cert_url, $account_wrap, string $ca = 'letsencrypt'): string {
    $nonce = gojs_acme_get_nonce($ca);
    $resp = gojs_acme_signed_request($cert_url, '', $account_wrap, $nonce, $ca);
    if ($resp['code'] !== 200) {
        throw new RuntimeException('Failed to fetch certificate: HTTP ' . $resp['code']);
    }
    if (is_string($resp['body'])) {
        return $resp['body'];
    }
    return '';
}

function gojs_acme_split_fullchain(string $fullchain): array {
    $certs = array();
    $lines = explode("\n", $fullchain);
    $buf = '';
    $in = false;
    foreach ($lines as $line) {
        if (strpos($line, '-----BEGIN CERTIFICATE-----') !== false) {
            $in = true;
            $buf = $line . "\n";
            continue;
        }
        if ($in) {
            $buf .= $line . "\n";
            if (strpos($line, '-----END CERTIFICATE-----') !== false) {
                $in = false;
                $certs[] = trim($buf);
            }
        }
    }
    if (count($certs) === 0) return array('cert' => '', 'fullchain' => $fullchain);
    $cert = $certs[0];
    return array('cert' => $cert, 'fullchain' => $fullchain);
}

function gojs_acme_cert_parse_dates(string $cert_pem): array {
    $parsed = openssl_x509_parse($cert_pem);
    if (!$parsed) return array('not_before' => 0, 'not_after' => 0);
    $nb = isset($parsed['validFrom_time_t']) ? (int)$parsed['validFrom_time_t'] : 0;
    $na = isset($parsed['validTo_time_t']) ? (int)$parsed['validTo_time_t'] : 0;
    return array('not_before' => $nb, 'not_after' => $na);
}

function gojs_acme_cert_issuer(string $cert_pem): string {
    $parsed = openssl_x509_parse($cert_pem);
    if (!$parsed) return '';
    $issuer = isset($parsed['issuer']) ? $parsed['issuer'] : array();
    if (is_array($issuer)) {
        return isset($issuer['CN']) ? $issuer['CN'] : (isset($issuer['O']) ? $issuer['O'] : '');
    }
    return (string)$issuer;
}

function gojs_acme_issue_cert(array $payload): array {
    $domain = isset($payload['domain']) ? trim((string)$payload['domain']) : '';
    $email = isset($payload['email']) ? trim((string)$payload['email']) : '';
    $ca = isset($payload['ca']) ? (string)$payload['ca'] : 'letsencrypt';
    $accept_tos = !empty($payload['accept_tos']);

    if ($domain === '') {
        return array('ok' => false, 'error' => 'ssl.acme.domainRequired', 'error_message' => 'Domain required');
    }
    if (!filter_var('user@' . $domain, FILTER_VALIDATE_DOMAIN) && !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.]*\.[a-zA-Z]{2,}$/', $domain)) {
        return array('ok' => false, 'error' => 'ssl.acme.domainInvalid', 'error_message' => 'Invalid domain format');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return array('ok' => false, 'error' => 'ssl.acme.emailInvalid', 'error_message' => 'Valid email required');
    }
    if (!$accept_tos) {
        return array('ok' => false, 'error' => 'ssl.acme.acceptTosRequired', 'error_message' => 'Must accept TOS');
    }
    if (!extension_loaded('openssl')) {
        return array('ok' => false, 'error' => 'ssl.acme.opensslUnavailable', 'error_message' => 'OpenSSL extension required');
    }
    $allowed_ca = array('letsencrypt', 'letsencrypt-staging');
    if (!in_array($ca, $allowed_ca, true)) $ca = 'letsencrypt';

    try {
        $order_result = gojs_acme_order($domain, $email, $ca);
        if (!$order_result['ok']) {
            return array('ok' => false, 'error' => 'ssl.acme.orderFailed', 'error_message' => $order_result['error'] ?? 'Order failed');
        }

        $account = isset($order_result['_account']) ? $order_result['_account'] : null;
        if (!$account || empty($account['key_pem_plain'])) {
            return array('ok' => false, 'error' => 'ssl.acme.accountMissing', 'error_message' => 'Account setup failed');
        }
        $account_wrap = array('key_pem' => $account['key_pem_plain'], 'kid' => $account['kid']);
        $pkey_for_jwk = openssl_pkey_get_private($account['key_pem_plain']);
        if (!$pkey_for_jwk) {
            return array('ok' => false, 'error' => 'ssl.acme.keyLoadFailed', 'error_message' => 'Key load failed');
        }
        $jwk = gojs_acme_rsa_jwk_from_pkey($pkey_for_jwk);

        $authorizations = isset($order_result['authorizations']) ? $order_result['authorizations'] : array();
        foreach ($authorizations as $auth) {
            $challenges = isset($auth['challenges']) ? $auth['challenges'] : array();
            foreach ($challenges as $c) {
                if (isset($c['type']) && $c['type'] === 'http-01' && !empty($c['token'])) {
                    gojs_acme_place_http01($domain, $c['token'], $jwk);
                    gojs_acme_respond_challenge($c['url'], $account_wrap, $ca);
                }
            }
            $auth_url = isset($auth['auth_url']) ? $auth['auth_url'] : '';
            if ($auth_url !== '') {
                $polled = gojs_acme_poll_url($auth_url, $account_wrap, $ca, 10, 2);
                $pb = is_array($polled['body']) ? $polled['body'] : array();
                if (isset($pb['status']) && $pb['status'] === 'invalid') {
                    $detail = isset($pb['error']['detail']) ? $pb['error']['detail'] : (is_array($polled['body']) && isset($polled['body']['detail']) ? $polled['body']['detail'] : 'Authorization invalid');
                    return array('ok' => false, 'error' => 'ssl.acme.authInvalid', 'error_message' => 'Authorization failed: ' . $detail);
                }
            }
        }

        $order_url = $order_result['order_url'];
        $order_poll = gojs_acme_poll_url($order_url, $account_wrap, $ca, 10, 2);
        $order_body = is_array($order_poll['body']) ? $order_poll['body'] : array();
        $order_status = isset($order_body['status']) ? $order_body['status'] : '';
        if ($order_status !== 'ready') {
            if ($order_status === 'invalid') {
                $detail = isset($order_body['error']['detail']) ? $order_body['error']['detail'] : 'Order invalid';
                return array('ok' => false, 'error' => 'ssl.acme.orderInvalid', 'error_message' => 'Order invalid: ' . $detail);
            }
        }

        $cert_pkey = openssl_pkey_new(array(
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ));
        if (!$cert_pkey) {
            return array('ok' => false, 'error' => 'ssl.acme.certKeyGenFailed', 'error_message' => 'Certificate key generation failed');
        }
        $cert_privkey_pem = '';
        openssl_pkey_export($cert_pkey, $cert_privkey_pem);

        $san = '';
        $dn = array(
            'countryName' => 'XX',
            'organizationName' => 'Go.js ACME',
            'commonName' => $domain,
        );
        $csr = openssl_csr_new($dn, $cert_pkey, array('digest_alg' => 'sha256'));
        if ($csr === false) {
            return array('ok' => false, 'error' => 'ssl.acme.csrFailed', 'error_message' => 'CSR generation failed');
        }
        $csr_pem = '';
        openssl_csr_export($csr, $csr_pem);

        $finalize_url = isset($order_result['finalize_url']) ? $order_result['finalize_url'] : '';
        if ($finalize_url === '' && is_array($order_body)) {
            $finalize_url = isset($order_body['finalize']) ? $order_body['finalize'] : '';
        }
        gojs_acme_finalize($finalize_url, $csr_pem, $account_wrap, $ca);

        $order_final = gojs_acme_poll_url($order_url, $account_wrap, $ca, 15, 3);
        $final_body = is_array($order_final['body']) ? $order_final['body'] : array();
        $final_status = isset($final_body['status']) ? $final_body['status'] : '';
        if ($final_status !== 'valid') {
            $detail = isset($final_body['error']['detail']) ? $final_body['error']['detail'] : 'Order did not finalize (status=' . $final_status . ')';
            return array('ok' => false, 'error' => 'ssl.acme.finalizeFailed', 'error_message' => 'Finalize failed: ' . $detail);
        }
        $cert_url = isset($final_body['certificate']) ? $final_body['certificate'] : '';
        if ($cert_url === '') {
            return array('ok' => false, 'error' => 'ssl.acme.certUrlMissing', 'error_message' => 'Certificate URL missing');
        }
        $fullchain = gojs_acme_fetch_certificate($cert_url, $account_wrap, $ca);
        $split = gojs_acme_split_fullchain($fullchain);
        $cert_pem = $split['cert'];
        $dates = gojs_acme_cert_parse_dates($cert_pem);
        $issuer = gojs_acme_cert_issuer($cert_pem);

        $records = gojs_acme_load_certs();
        $id = 'cert_' . uniqid('', true);
        $record = array(
            'id' => $id,
            'domain' => $domain,
            'status' => 'valid',
            'not_before_ts' => $dates['not_before'],
            'not_after_ts' => $dates['not_after'],
            'last_ordered_at' => time(),
            'auto_renew_days_before' => 30,
            'cert_pem_enc' => gojs_seal_secret($cert_pem),
            'fullchain_pem_enc' => gojs_seal_secret($fullchain),
            'privkey_pem_enc' => gojs_seal_secret($cert_privkey_pem),
            'issuer_url' => $issuer,
            'chain_thumbprint' => gojs_acme_thumbprint($jwk),
            'ca' => $ca,
            'san_domains' => array($domain),
            'account_email' => $email,
        );
        $records[] = $record;
        gojs_acme_save_certs($records);

        gojs_append_notification(array(
            'category' => 'ssl',
            'severity' => 'success',
            'title_key' => 'ssl.acme.certIssuedTitle',
            'body_key' => 'ssl.acme.certIssuedBody',
            'body_params' => array('domain' => $domain),
            'payload' => array('certificate_id' => $id, 'domain' => $domain),
        ));
        gojs_log_operation('ssl_acme_issue', $domain, true, 'issued cert id=' . $id);

        return array(
            'ok' => true,
            'certificate_id' => $id,
            'cert_pem' => $cert_pem,
            'fullchain_pem' => $fullchain,
            'privkey_pem' => $cert_privkey_pem,
            'not_before' => $dates['not_before'],
            'not_after' => $dates['not_after'],
            'issuer' => $issuer,
        );
    } catch (Throwable $e) {
        gojs_append_notification(array(
            'category' => 'ssl',
            'severity' => 'error',
            'title_key' => 'ssl.acme.certIssueFailedTitle',
            'body_key' => 'ssl.acme.certIssueFailedBody',
            'body_params' => array('domain' => $domain, 'error' => $e->getMessage()),
            'payload' => array('domain' => $domain),
        ));
        return array('ok' => false, 'error' => 'ssl.acme.exception', 'error_message' => $e->getMessage());
    }
}

function gojs_acme_renew_all_due(): array {
    $records = gojs_acme_load_certs();
    $now = time();
    $renewed = 0;
    $skipped = 0;
    $failed = 0;
    $paused = 0;
    $failed_records = array();

    // Collect per-record field updates, then reload, apply by id, and save once after the loop.
    $updates = array();

    foreach ($records as $i => $r) {
        if (!is_array($r)) continue;
        $status = isset($r['status']) ? $r['status'] : '';
        if ($status !== 'valid') {
            $skipped++;
            continue;
        }
        $not_after = isset($r['not_after_ts']) ? (int)$r['not_after_ts'] : 0;
        $days_before = isset($r['auto_renew_days_before']) ? (int)$r['auto_renew_days_before'] : 30;
        if ($days_before <= 0) {
            $skipped++;
            continue;
        }
        if (($not_after - $now) >= ($days_before * 86400)) {
            $skipped++;
            continue;
        }
        $id = isset($r['id']) ? $r['id'] : '';
        $domain = isset($r['domain']) ? $r['domain'] : '';
        $email = isset($r['account_email']) ? $r['account_email'] : '';
        $ca = isset($r['ca']) ? $r['ca'] : 'letsencrypt';

        // Cooldown debounce: skip auto-renewal after >= 5 consecutive failures to avoid repeated webcron triggering.
        $renew_attempts = isset($r['renew_attempts']) ? (int)$r['renew_attempts'] : 0;
        if ($renew_attempts >= 5) {
            $paused++;
            if ($id !== '') {
                $updates[$id] = array('auto_paused' => true);
            }
            continue;
        }

        if ($domain === '' || $email === '') {
            $failed++;
            if ($id !== '') {
                $updates[$id] = array(
                    'last_renew_error' => 'ssl.acme.missingInfo',
                    'last_renew_attempt_ts' => $now,
                    'renew_attempts' => $renew_attempts + 1,
                    'auto_paused' => ($renew_attempts + 1 >= 5),
                );
                $failed_records[] = array('domain' => $domain, 'error' => 'ssl.acme.missingInfo');
            }
            continue;
        }
        $result = gojs_acme_issue_cert(array(
            'domain' => $domain,
            'email' => $email,
            'accept_tos' => true,
            'ca' => $ca,
        ));
        if (!empty($result['ok'])) {
            if (isset($result['certificate_id'])) {
                $new_id = $result['certificate_id'];
                $recs_reloaded = gojs_acme_load_certs();
                $old_id = $id;
                $recs_reloaded = array_values(array_filter($recs_reloaded, function($x) use ($old_id) {
                    return !is_array($x) || !isset($x['id']) || $x['id'] !== $old_id;
                }));
                gojs_acme_save_certs($recs_reloaded);
            }
            // On success: record success time and clear the failure counter (skipped naturally if the old record was deleted).
            if ($id !== '') {
                $updates[$id] = array(
                    'last_renew_ok_ts' => $now,
                    'last_renew_error' => null,
                    'last_renew_attempt_ts' => null,
                    'renew_attempts' => 0,
                    'auto_paused' => false,
                );
            }
            $renewed++;
        } else {
            $failed++;
            $error = isset($result['error']) && is_string($result['error']) && $result['error'] !== ''
                ? $result['error']
                : 'unknown';
            if ($id !== '') {
                $updates[$id] = array(
                    'last_renew_error' => $error,
                    'last_renew_attempt_ts' => $now,
                    'renew_attempts' => $renew_attempts + 1,
                    'auto_paused' => ($renew_attempts + 1 >= 5),
                );
                $failed_records[] = array('domain' => $domain, 'error' => $error);
            }
        }
    }

    // Reload after the loop and apply updates by id (already-deleted ids are skipped).
    if (count($updates) > 0) {
        $records_now = gojs_acme_load_certs();
        $changed = false;
        foreach ($records_now as $i => $rr) {
            if (!is_array($rr) || !isset($rr['id'])) continue;
            $rid = $rr['id'];
            if (!isset($updates[$rid])) continue;
            foreach ($updates[$rid] as $k => $v) {
                $records_now[$i][$k] = $v;
            }
            $changed = true;
        }
        if ($changed) {
            gojs_acme_save_certs($records_now);
        }
    }

    return array(
        'renewed_count' => $renewed,
        'skipped' => $skipped,
        'failed' => $failed,
        'paused' => $paused,
        'failed_records' => $failed_records,
    );
}

function gojs_acme_schedule_register_cronjob(): void {
    global $config;
    if (!isset($config['acme_last_renew_check_ts'])) {
        $config['acme_last_renew_check_ts'] = 0;
    }
}

function gojs_internal_cron_acme_renew_hook(array &$stats): void {
    global $config;
    $now = time();
    $key = 'acme_last_renew_check_ts';
    $last = isset($config[$key]) ? (int)$config[$key] : 0;
    if (($now - $last) < 82800) {
        return;
    }
    $config[$key] = $now;
    gojs_save_config();
    $renew_stats = gojs_acme_renew_all_due();
    if (is_array($renew_stats)) {
        $stats['acme_renewed'] = isset($renew_stats['renewed_count']) ? $renew_stats['renewed_count'] : 0;
        $stats['acme_skipped'] = isset($renew_stats['skipped']) ? $renew_stats['skipped'] : 0;
        $stats['acme_failed'] = isset($renew_stats['failed']) ? $renew_stats['failed'] : 0;
    }
}

function gojs_api_ssl_acme_capabilities(): void {
    gojs_acme_ensure_dirs();
    $docroot = gojs_acme_docroot();
    $docroot_known = !empty($_SERVER['DOCUMENT_ROOT']) || is_dir($docroot);
    $challenges_dir = gojs_acme_challenges_docroot_dir();
    if (!is_dir($challenges_dir)) {
        @mkdir($challenges_dir, 0755, true);
    }
    $challenges_writable = is_dir($challenges_dir) && is_writable($challenges_dir);
    $openssl_ok = extension_loaded('openssl');
    $curl_ok = extension_loaded('curl');
    $ext_ok = $openssl_ok && ($curl_ok || ini_get('allow_url_fopen'));
    $available = $ext_ok && $challenges_writable;
    $reason_key = null;
    if (!$openssl_ok) $reason_key = 'ssl.acme.subheaderNeedExtOpenssl';
    elseif (!$docroot_known) $reason_key = 'ssl.acme.subheaderDocrootUnknown';
    elseif (!$challenges_writable) $reason_key = 'ssl.acme.subheaderChallengesNotWritable';
    gojs_json_response(array(
        'available' => $available,
        'acme_extensions_ok' => $ext_ok,
        'docroot_known' => $docroot_known,
        'challenges_dir_writable' => $challenges_writable,
        'reason_key' => $reason_key,
    ));
}

function gojs_api_ssl_acme_certificates_list(): void {
    $records = gojs_acme_load_certs();
    $now = time();
    $result = array();
    foreach ($records as $r) {
        if (!is_array($r)) continue;
        $redacted = $r;
        unset($redacted['privkey_pem_enc']);
        $not_after = isset($r['not_after_ts']) ? (int)$r['not_after_ts'] : 0;
        $days_before = isset($r['auto_renew_days_before']) ? (int)$r['auto_renew_days_before'] : 30;
        $status_derived = isset($r['status']) ? $r['status'] : 'pending';
        if ($status_derived === 'valid') {
            if ($not_after > 0 && $now >= $not_after) {
                $status_derived = 'expired';
            } elseif ($not_after > 0 && ($not_after - $now) < ($days_before * 86400)) {
                $status_derived = 'expiring_soon';
            }
        }
        // Renewal-failed state: auto-paused (>= 5 consecutive failures) and cert not expired -> renew_failed.
        if (($status_derived === 'valid' || $status_derived === 'expiring_soon') && $not_after > 0 && $now < $not_after) {
            $auto_paused = !empty($r['auto_paused']);
            $renew_attempts = isset($r['renew_attempts']) ? (int)$r['renew_attempts'] : 0;
            if ($auto_paused || $renew_attempts >= 5) {
                $status_derived = 'renew_failed';
            }
        }
        $redacted['status_derived'] = $status_derived;
        $result[] = $redacted;
    }
    gojs_json_response(array('records' => $result));
}

function gojs_api_ssl_acme_issue_cert(): void {
    $payload = gojs_get_body();
    if (!is_array($payload)) $payload = array();
    gojs_log_operation('ssl_acme_issue_start', isset($payload['domain']) ? $payload['domain'] : '', true);
    $result = gojs_acme_issue_cert($payload);
    if (!empty($result['ok'])) {
        gojs_json_response(array(
            'ok' => true,
            'certificate_id' => isset($result['certificate_id']) ? $result['certificate_id'] : '',
        ), null, 202);
    } else {
        $code = isset($result['error']) ? $result['error'] : 'ssl.acme.issueFailed';
        $msg = isset($result['error_message']) ? $result['error_message'] : 'Certificate issuance failed';
        gojs_json_response(null, array('code' => $code, 'message' => $msg, 'message_key' => $code), 400);
    }
}

function gojs_api_ssl_acme_cert_renew(string $id): void {
    $records = gojs_acme_load_certs();
    $found = null;
    $found_idx = -1;
    foreach ($records as $i => $r) {
        if (is_array($r) && isset($r['id']) && $r['id'] === $id) {
            $found = $r;
            $found_idx = $i;
            break;
        }
    }
    if ($found === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'Certificate not found', 'message_key' => 'ssl.acme.certNotFound'), 404);
        return;
    }
    $domain = isset($found['domain']) ? $found['domain'] : '';
    $email = isset($found['account_email']) ? $found['account_email'] : '';
    $ca = isset($found['ca']) ? $found['ca'] : 'letsencrypt';
    if ($domain === '' || $email === '') {
        gojs_json_response(null, array('code' => 'ssl.acme.missingInfo', 'message' => 'Domain or email missing on record'), 400);
        return;
    }
    gojs_log_operation('ssl_acme_renew', $domain, true, 'id=' . $id);
    $result = gojs_acme_issue_cert(array(
        'domain' => $domain,
        'email' => $email,
        'accept_tos' => true,
        'ca' => $ca,
    ));
    if (!empty($result['ok'])) {
        if (isset($result['certificate_id']) && $result['certificate_id'] !== $id) {
            $reloaded = gojs_acme_load_certs();
            $reloaded = array_values(array_filter($reloaded, function($x) use ($id) {
                return !is_array($x) || !isset($x['id']) || $x['id'] !== $id;
            }));
            gojs_acme_save_certs($reloaded);
        }
        gojs_json_response(array('ok' => true, 'renewed' => true, 'certificate_id' => isset($result['certificate_id']) ? $result['certificate_id'] : $id));
    } else {
        $code = isset($result['error']) ? $result['error'] : 'ssl.acme.renewFailed';
        $msg = isset($result['error_message']) ? $result['error_message'] : 'Renew failed';
        gojs_json_response(null, array('code' => $code, 'message' => $msg, 'message_key' => $code), 400);
    }
}

function gojs_api_ssl_acme_cert_delete(string $id): void {
    $records = gojs_acme_load_certs();
    $domain = '';
    $kept = array();
    foreach ($records as $r) {
        if (is_array($r) && isset($r['id']) && $r['id'] === $id) {
            $domain = isset($r['domain']) ? $r['domain'] : '';
            continue;
        }
        $kept[] = $r;
    }
    if (count($kept) === count($records)) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'Certificate not found', 'message_key' => 'ssl.acme.certNotFound'), 404);
        return;
    }
    gojs_acme_save_certs($kept);
    gojs_log_operation('ssl_acme_delete', $domain, true, 'id=' . $id);
    gojs_json_response(array('ok' => true));
}

function gojs_api_ssl_acme_cert_auto_renew(string $id): void {
    $body = gojs_get_body();
    $days = isset($body['auto_renew_days_before']) ? (int)$body['auto_renew_days_before'] : 0;
    if ($days < 0) $days = 0;
    if ($days > 90) $days = 90;
    $records = gojs_acme_load_certs();
    $found = false;
    foreach ($records as $i => $r) {
        if (is_array($r) && isset($r['id']) && $r['id'] === $id) {
            $records[$i]['auto_renew_days_before'] = $days;
            $found = true;
            break;
        }
    }
    if (!$found) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'Certificate not found', 'message_key' => 'ssl.acme.certNotFound'), 404);
        return;
    }
    gojs_acme_save_certs($records);
    gojs_json_response(array('ok' => true, 'auto_renew_days_before' => $days));
}

function gojs_api_ssl_acme_cert_download_pem(string $id): void {
    $records = gojs_acme_load_certs();
    $found = null;
    foreach ($records as $r) {
        if (is_array($r) && isset($r['id']) && $r['id'] === $id) {
            $found = $r;
            break;
        }
    }
    if ($found === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'Certificate not found', 'message_key' => 'ssl.acme.certNotFound'), 404);
        return;
    }
    $fullchain = isset($found['fullchain_pem_enc']) ? gojs_decrypt($found['fullchain_pem_enc']) : '';
    $privkey = isset($found['privkey_pem_enc']) ? gojs_decrypt($found['privkey_pem_enc']) : '';
    $cert = isset($found['cert_pem_enc']) ? gojs_decrypt($found['cert_pem_enc']) : '';
    $domain = isset($found['domain']) ? $found['domain'] : 'certificate';
    $bundle = '';
    if (is_string($cert) && $cert !== '') $bundle .= $cert . "\n";
    if (is_string($fullchain) && $fullchain !== '' && $fullchain !== $cert) $bundle .= $fullchain . "\n";
    if (is_string($privkey) && $privkey !== '') $bundle .= $privkey . "\n";
    if ($bundle === '') {
        gojs_json_response(null, array('code' => 'ssl.acme.certEmpty', 'message' => 'No certificate data', 'message_key' => 'ssl.acme.certEmpty'), 404);
        return;
    }
    $filename = preg_replace('/[^a-zA-Z0-9\-\.]/', '_', $domain) . '.pem';
    if (!headers_sent()) {
        header('Content-Type: application/x-pem-file');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bundle));
        header('X-Content-Type-Options: nosniff');
    }
    gojs_monitor_bump_bandwidth(0, strlen($bundle));
    echo $bundle;
    exit;
}
