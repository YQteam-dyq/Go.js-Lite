<?php





function gojs_totp_generate_secret(int $bytes = 20): string {
    return GOJS_Base32::encode(random_bytes($bytes));
}


function gojs_totp_compute(string $secret, ?int $time = null, int $digits = 6, int $step = 30, string $algo = 'sha1'): string {
    if ($time === null) $time = time();
    $secretBin = GOJS_Base32::decode($secret);
    $counter = (int)floor($time / $step);
    $counterBin = pack('J', $counter);
    $hmac = hash_hmac($algo, $counterBin, $secretBin, true);
    $offset = ord(substr($hmac, -1)) & 0x0F;
    $code = unpack('N', substr($hmac, $offset, 4))[1];
    $code = $code & 0x7FFFFFFF;
    $code = $code % pow(10, $digits);
    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}


function gojs_totp_validate(string $secret, string $code, int $window = 1): bool {
    $time = time();
    $code = preg_replace('/\D/', '', $code);
    for ($i = -$window; $i <= $window; $i++) {
        $computed = gojs_totp_compute($secret, $time + ($i * 30));
        if (hash_equals($computed, $code)) return true;
    }
    return false;
}


function gojs_crypto_get_rand_alphanum(int $len): string {
    $bytes = random_bytes((int)ceil($len * 3 / 4));
    $hex = bin2hex($bytes);
    $base = '';
    for ($i = 0; $i < strlen($hex); $i += 2) {
        $val = hexdec(substr($hex, $i, 2));
        $base .= base_convert((string)$val, 10, 36);
    }
    $result = strtoupper(substr($base, 0, $len));
    while (strlen($result) < $len) {
        $extra = random_bytes(4);
        $result .= strtoupper(base_convert(bin2hex($extra), 16, 36));
        $result = substr($result, 0, $len);
    }
    return $result;
}

function gojs_totp_build_qr_svg_data_url(string $label, string $user, string $secret): string {
    $otpauth = 'otpauth://totp/' . rawurlencode($label) . ':' . rawurlencode($user) . '?secret=' . $secret . '&issuer=' . rawurlencode($label);
    $otpauth_esc = htmlspecialchars($otpauth, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $secret_esc = htmlspecialchars($secret, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $label_esc = htmlspecialchars($label . ' (' . $user . ')', ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="360" viewBox="0 0 320 360" shape-rendering="crispEdges">' .
        '<rect width="320" height="360" fill="#ffffff"/>' .
        '<text x="160" y="22" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="13" fill="#111827" font-weight="bold">Scan with Google Authenticator/Authy</text>' .
        '<text x="160" y="40" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="11" fill="#4B5563">or copy the secret below</text>' .
        '<rect x="30" y="52" width="260" height="190" rx="8" fill="#111827"/>' .
        '<text x="160" y="105" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="12" fill="#9CA3AF">TOTP Setup: ' . $label_esc . '</text>' .
        '<text x="160" y="135" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="11" fill="#D1D5DB">Secret Key (Base32)</text>' .
        '<text x="160" y="165" text-anchor="middle" font-family="Courier New,monospace" font-size="14" fill="#10B981" font-weight="bold" letter-spacing="1">' . wordwrap($secret_esc, 4, ' ', true) . '</text>' .
        '<text x="160" y="195" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="10" fill="#6B7280">Digits: 6 · Step: 30s · SHA1</text>' .
        '<rect x="30" y="254" width="260" height="90" rx="8" fill="#F3F4F6" stroke="#E5E7EB" stroke-width="1"/>' .
        '<text x="42" y="276" font-family="Arial,Helvetica,sans-serif" font-size="11" fill="#374151" font-weight="bold">otpauth URL:</text>' .
        '<foreignObject x="40" y="282" width="240" height="58">' .
        '<div xmlns="http://www.w3.org/1999/xhtml" style="font-family:Courier New,monospace;font-size:9px;color:#1F2937;word-break:break-all;line-height:1.3;padding:2px">' . $otpauth_esc . '</div>' .
        '</foreignObject>' .
        '</svg>';

    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

function gojs_api_bootstrap() {
    global $config, $installed;

    gojs_check_access_token();

    $capabilities = gojs_get_capabilities();
    $csrf_token = gojs_generate_csrf_token();

    $authenticated = !empty($_SESSION['authenticated']);

    $data = array(
        'authenticated' => $authenticated,
        'installed' => $installed,
        'csrfToken' => $csrf_token,
        'capabilities' => $capabilities,
        'backendVersion' => VERSION,
        'frontendVersion' => VERSION,
    );

    if ($authenticated) {
        $data['user'] = array(
            'username' => 'admin',
        );

        $settings = isset($_SESSION['settings']) ? $_SESSION['settings'] : array();
        if (!$settings) {
            $settings = array(
                'theme' => 'system',
                'language' => 'zh',
                'sessionTimeout' => isset($config['session_timeout']) ? (int)$config['session_timeout'] : 1800,
            );
        }
        $data['settings'] = $settings;
    }

    gojs_json_response($data);
}

function gojs_api_install() {
    global $config, $installed, $root_path;

    if ($installed) {
        gojs_json_response(null, array(
            'code' => 'already_installed',
            'message' => '系统已安装',
        ), 400);
    }

    $password = gojs_get_param('password', '');
    $root_path_param = gojs_get_param('rootPath', '');

    if (strlen($password) < 8) {
        gojs_json_response(null, array(
            'code' => 'invalid_password',
            'message' => '密码长度至少为8位',
        ), 400);
    }

    $new_root_path = ROOT;
    if ($root_path_param) {
        $real_path = realpath($root_path_param);
        if (!$real_path || !is_dir($real_path)) {
            gojs_json_response(null, array(
                'code' => 'invalid_root_path',
                'message' => '根目录路径无效',
            ), 400);
        }
        $new_root_path = $real_path;
    }

    if (!is_dir(CONFIG_DIR)) {
        if (!@mkdir(CONFIG_DIR, 0700, true)) {
            gojs_json_response(null, array(
                'code' => 'create_config_dir_failed',
                'message' => '创建配置目录失败',
            ), 500);
        }
    }

    $encryption_key = bin2hex(random_bytes(16));

    $access_token = bin2hex(random_bytes(24));

    $config_data = array(
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'root_path' => $new_root_path,
        'installed' => true,
        'install_time' => time(),
        'session_timeout' => 1800,
        'encryption_key' => $encryption_key,
        'access_token' => $access_token,
        'version' => APP_VERSION,
    );

    $config_content = '<?php' . "\n" . 'return ' . var_export($config_data, true) . ';' . "\n";

    if (@file_put_contents(CONFIG_FILE, $config_content, LOCK_EX) === false) {
        gojs_json_response(null, array(
            'code' => 'write_config_failed',
            'message' => '写入配置文件失败',
        ), 500);
    }

    @chmod(CONFIG_FILE, 0600);

    $config = $config_data;
    $installed = true;
    $root_path = $new_root_path;

    $_SESSION['access_token_valid'] = true;
    $_SESSION['authenticated'] = true;
    $_SESSION['username'] = 'admin';
    $_SESSION['last_activity'] = time();
    $csrf_token = gojs_generate_csrf_token();

    $capabilities = gojs_get_capabilities();
    gojs_json_response(array(
        'authenticated' => true,
        'installed' => true,
        'csrfToken' => $csrf_token,
        'capabilities' => $capabilities,
        'user' => array('username' => 'admin'),
        'backendVersion' => VERSION,
        'frontendVersion' => VERSION,
        'accessToken' => $access_token,
    ));
}

function gojs_api_login() {
    global $config, $installed;

    gojs_check_access_token();

    if (!$installed) {
        gojs_json_response(null, array(
            'code' => 'not_installed',
            'message' => '系统未安装',
        ), 400);
    }

    $brute_check = gojs_check_brute_force();
    if (!empty($brute_check['locked'])) {
        gojs_json_response(null, array(
            'code' => 'ip_locked',
            'message' => '该 IP 已被临时锁定，请稍后再试',
            'retry_after' => (int)$brute_check['retry_after'],
        ), 429);
    }

    $username = gojs_get_param('username', '');
    $password = gojs_get_param('password', '');
    $totp = gojs_get_param('totp', null);
    $recoveryCode = gojs_get_param('recovery_code', null);

    if ($username !== 'admin') {
        gojs_log_auth_attempt(false);
        gojs_json_response(null, array(
            'code' => 'invalid_credentials',
            'message' => '用户名或密码错误',
        ), 401);
    }

    if (empty($config['password_hash']) || !password_verify($password, $config['password_hash'])) {
        gojs_log_auth_attempt(false);
        gojs_json_response(null, array(
            'code' => 'invalid_credentials',
            'message' => '用户名或密码错误',
        ), 401);
    }

    $totpEnabled = !empty($config['totp']['enabled']);
    $hasTotp = $totp !== null && $totp !== '';
    $hasRecovery = $recoveryCode !== null && $recoveryCode !== '';

    if ($totpEnabled && !$hasTotp && !$hasRecovery) {
        gojs_log_auth_attempt(false);
        gojs_json_response(null, array(
            'code' => 'totp_required',
            'message' => '需要双因素验证码',
            'message_key' => 'login.totpRequired',
            'challenge' => array(
                'algorithm' => 'TOTP',
            ),
        ), 401);
    }

    if ($hasTotp) {
        $secret = isset($config['totp']['secret_enc']) ? $config['totp']['secret_enc'] : '';
        if (!$secret || !gojs_totp_validate($secret, $totp, 1)) {
            gojs_log_auth_attempt(false);
            gojs_json_response(null, array(
                'code' => 'totp_invalid',
                'message' => '双因素验证码错误或已过期',
                'message_key' => 'login.totpInvalid',
            ), 401);
        }
    } elseif ($hasRecovery) {
        $recoveryCodesEnc = isset($config['totp']['recovery_codes_enc']) && is_array($config['totp']['recovery_codes_enc']) ? $config['totp']['recovery_codes_enc'] : array();
        $usedCodes = isset($config['totp']['used_codes']) && is_array($config['totp']['used_codes']) ? $config['totp']['used_codes'] : array();
        $codesFormat = isset($config['totp']['codes_format']) ? $config['totp']['codes_format'] : (count($recoveryCodesEnc) > 0 ? 'hash_legacy' : 'enc');
        $cleanRecovery = strtoupper(str_replace('-', '', $recoveryCode));
        $matched = false;
        $matchedKey = '';

        if ($codesFormat === 'enc') {
            foreach ($recoveryCodesEnc as $sealed) {
                $plain = gojs_unseal_secret($sealed);
                if ($plain === false || $plain === '') continue;
                if (strtoupper(str_replace('-', '', $plain)) === $cleanRecovery) {
                    $matched = true;
                    $matchedKey = hash('sha256', strtoupper(str_replace('-', '', $plain)));
                    break;
                }
            }
        } else {
            foreach ($recoveryCodesEnc as $hash) {
                if (password_verify($cleanRecovery, $hash)) {
                    $matched = true;
                    $matchedKey = $hash;
                    break;
                }
            }
        }

        if (!$matched) {
            gojs_log_auth_attempt(false);
            gojs_json_response(null, array(
                'code' => 'recovery_code_invalid',
                'message' => '恢复码无效',
                'message_key' => 'login.recoveryCodeInvalid',
            ), 401);
        }

        if (isset($usedCodes[$matchedKey])) {
            gojs_log_auth_attempt(false);
            gojs_json_response(null, array(
                'code' => 'recovery_code_already_used',
                'message' => '该恢复码已被使用过',
                'message_key' => 'login.recoveryCodeAlreadyUsed',
            ), 401);
        }

        $config['totp']['used_codes'][$matchedKey] = time();
        gojs_save_config();
    }

    gojs_log_auth_attempt(true);

    gojs_clear_auth_attempts(gojs_get_client_ip());

    session_regenerate_id(true);

    $_SESSION['authenticated'] = true;
    $_SESSION['username'] = 'admin';
    $_SESSION['last_activity'] = time();
    $csrf_token = gojs_generate_csrf_token();

    $capabilities = gojs_get_capabilities();

    $data = array(
        'authenticated' => true,
        'installed' => true,
        'csrfToken' => $csrf_token,
        'capabilities' => $capabilities,
        'backendVersion' => VERSION,
        'frontendVersion' => VERSION,
        'user' => array(
            'username' => 'admin',
        ),
    );

    $settings = isset($_SESSION['settings']) ? $_SESSION['settings'] : array();
    if (!$settings) {
        $settings = array(
            'theme' => 'system',
            'language' => 'zh',
            'sessionTimeout' => isset($config['session_timeout']) ? (int)$config['session_timeout'] : 1800,
        );
    }
    $data['settings'] = $settings;

    gojs_json_response($data);
}

function gojs_api_logout() {
    session_unset();
    session_destroy();

    
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    gojs_json_response(array('success' => true));
}

function gojs_api_change_password() {
    global $config;

    $old_password = gojs_get_param('oldPassword', '');
    $new_password = gojs_get_param('newPassword', '');

    if (empty($config['password_hash']) || !password_verify($old_password, $config['password_hash'])) {
        gojs_json_response(null, array(
            'code' => 'invalid_password',
            'message' => '旧密码错误',
        ), 400);
    }

    if (strlen($new_password) < 8) {
        gojs_json_response(null, array(
            'code' => 'invalid_password',
            'message' => '新密码长度至少为8位',
        ), 400);
    }

    $config['password_hash'] = password_hash($new_password, PASSWORD_BCRYPT);

    $config_content = '<?php' . "\n" . 'return ' . var_export($config, true) . ';' . "\n";

    if (@file_put_contents(CONFIG_FILE, $config_content, LOCK_EX) === false) {
        gojs_json_response(null, array(
            'code' => 'write_config_failed',
            'message' => '写入配置文件失败',
        ), 500);
    }

    gojs_log_operation('password_change', 'user', true);
    gojs_json_response(array('success' => true));
}

function gojs_api_get_settings() {
    global $config;

    $settings = isset($_SESSION['settings']) ? $_SESSION['settings'] : array();
    if (!$settings) {
        $settings = array(
            'theme' => 'system',
            'language' => 'zh',
            'sessionTimeout' => isset($config['session_timeout']) ? (int)$config['session_timeout'] : 1800,
            'logRetention' => isset($config['log_retention']) ? (int)$config['log_retention'] : 500,
        );
    }

    if (!empty($config['access_token'])) {
        $settings['accessToken'] = $config['access_token'];
    }
    if (!isset($settings['logRetention'])) {
        $settings['logRetention'] = isset($config['log_retention']) ? (int)$config['log_retention'] : 500;
    }

    gojs_json_response($settings);
}

function gojs_api_update_settings() {
    global $config;

    $body = gojs_get_body();

    $current_settings = isset($_SESSION['settings']) ? $_SESSION['settings'] : array();
    if (!$current_settings) {
        $current_settings = array(
            'theme' => 'system',
            'language' => 'zh',
            'sessionTimeout' => isset($config['session_timeout']) ? (int)$config['session_timeout'] : 1800,
            'logRetention' => isset($config['log_retention']) ? (int)$config['log_retention'] : 500,
        );
    }

    $new_settings = array_merge($current_settings, $body);

    if (isset($new_settings['theme']) && !in_array($new_settings['theme'], array('light', 'dark', 'system'))) {
        $new_settings['theme'] = 'system';
    }
    if (isset($new_settings['language']) && !in_array($new_settings['language'], array('zh', 'en'))) {
        $new_settings['language'] = 'zh';
    }
    if (isset($new_settings['sessionTimeout'])) {
        $new_settings['sessionTimeout'] = max(300, min(86400, (int)$new_settings['sessionTimeout']));

        $config['session_timeout'] = $new_settings['sessionTimeout'];
    }
    if (isset($new_settings['logRetention'])) {
        $log_retention = (int)$new_settings['logRetention'];
        if ($log_retention < 50) $log_retention = 500;
        $new_settings['logRetention'] = $log_retention;
        $config['log_retention'] = $log_retention;
    }

    
    if (isset($new_settings['sessionTimeout']) || isset($new_settings['logRetention'])) {
        $config_content = '<?php' . "\n" . 'return ' . var_export($config, true) . ';' . "\n";
        @file_put_contents(CONFIG_FILE, $config_content, LOCK_EX);
    }

    $_SESSION['settings'] = $new_settings;

    gojs_log_operation('settings_update', 'config', true);
    gojs_json_response($new_settings);
}

function gojs_api_regenerate_access_token() {
    global $config;

    $new_token = bin2hex(random_bytes(24));
    $config['access_token'] = $new_token;
    gojs_save_config();

    $_SESSION['access_token_valid'] = true;

    gojs_log_operation('token_regenerate', 'access_token', true);
    gojs_json_response(array(
        'accessToken' => $new_token,
    ));
}

function gojs_api_totp_status() {
    global $config;

    $totp = isset($config['totp']) ? $config['totp'] : array();
    $enabled = !empty($totp['enabled']);
    $hasSecret = !empty($totp['secret_enc']);
    $recoveryCodesCount = isset($totp['recovery_codes_enc']) && is_array($totp['recovery_codes_enc']) ? count($totp['recovery_codes_enc']) : 0;

    gojs_json_response(array(
        'enabled' => $enabled,
        'hasSecret' => $hasSecret,
        'recoveryCodesCount' => $recoveryCodesCount,
    ));
}

function gojs_api_totp_enroll() {
    global $config;

    $secret = gojs_totp_generate_secret(20);
    $recoveryCodes = array();
    $recoveryCodesEnc = array();

    for ($i = 0; $i < 8; $i++) {
        $code = gojs_crypto_get_rand_alphanum(16);
        $formatted = substr($code, 0, 4) . '-' . substr($code, 4, 4) . '-' . substr($code, 8, 4) . '-' . substr($code, 12, 4);
        $recoveryCodes[] = $formatted;
        $recoveryCodesEnc[] = gojs_seal_secret($formatted);
    }

    $_SESSION['totp_secret_pending_enc'] = $secret;
    $_SESSION['totp_recovery_codes_pending_enc'] = $recoveryCodesEnc;

    $label = 'Go.js Panel';
    $user = 'admin';
    $otpauthUrl = 'otpauth://totp/' . rawurlencode($label) . ':' . rawurlencode($user) . '?secret=' . $secret . '&issuer=' . rawurlencode($label);
    $qrSvgDataUrl = gojs_totp_build_qr_svg_data_url($label, $user, $secret);

    gojs_log_operation('totp_enroll_start', 'security', true);
    gojs_json_response(array(
        'secret' => $secret,
        'otpauth_url' => $otpauthUrl,
        'qr_svg_data_url' => $qrSvgDataUrl,
        'recovery_codes' => $recoveryCodes,
    ));
}

function gojs_api_totp_confirm() {
    global $config;

    $code = gojs_get_param('code', '');
    $code = preg_replace('/\D/', '', $code);

    if (strlen($code) !== 6) {
        gojs_json_response(null, array(
            'code' => 'code_invalid',
            'message' => '请输入 6 位数字验证码',
        ), 400);
    }

    $pendingSecret = isset($_SESSION['totp_secret_pending_enc']) ? $_SESSION['totp_secret_pending_enc'] : '';
    $pendingRecovery = isset($_SESSION['totp_recovery_codes_pending_enc']) ? $_SESSION['totp_recovery_codes_pending_enc'] : array();

    if (!$pendingSecret || !is_array($pendingRecovery) || count($pendingRecovery) === 0) {
        gojs_json_response(null, array(
            'code' => 'no_pending_enroll',
            'message' => '没有待确认的启用请求，请先点击启用',
        ), 400);
    }

    if (!gojs_totp_validate($pendingSecret, $code, 1)) {
        gojs_json_response(null, array(
            'code' => 'totp_invalid',
            'message' => '验证码错误或已过期',
        ), 400);
    }

    if (!isset($config['totp']) || !is_array($config['totp'])) {
        $config['totp'] = array(
            'enabled' => false,
            'secret_enc' => '',
            'recovery_codes_enc' => array(),
            'used_codes' => array(),
        );
    }

    $config['totp']['enabled'] = true;
    $config['totp']['secret_enc'] = $pendingSecret;
    $config['totp']['recovery_codes_enc'] = $pendingRecovery;
    $config['totp']['used_codes'] = array();
    $config['totp']['codes_format'] = 'enc';

    gojs_save_config();

    unset($_SESSION['totp_secret_pending_enc']);
    unset($_SESSION['totp_recovery_codes_pending_enc']);

    gojs_log_operation('totp_enabled', 'security', true);
    gojs_json_response(array('success' => true));
}

function gojs_api_totp_disable() {
    global $config;

    $adminPassword = gojs_get_param('admin_password', '');

    if (empty($config['password_hash']) || !password_verify($adminPassword, $config['password_hash'])) {
        gojs_json_response(null, array(
            'code' => 'invalid_password',
            'message' => '管理员密码错误',
        ), 400);
    }

    if (isset($config['totp'])) {
        $config['totp']['enabled'] = false;
        $config['totp']['secret_enc'] = '';
        $config['totp']['recovery_codes_enc'] = array();
        $config['totp']['used_codes'] = array();
        gojs_save_config();
    }

    gojs_log_operation('totp_disabled', 'security', true);
    gojs_json_response(array('success' => true));
}

function gojs_api_totp_recovery_codes() {
    global $config;

    $adminPassword = gojs_get_param('admin_password', '');
    $action = gojs_get_param('action', 'view');

    if (empty($config['password_hash']) || !password_verify($adminPassword, $config['password_hash'])) {
        gojs_json_response(null, array(
            'code' => 'invalid_password',
            'message' => '管理员密码错误',
        ), 400);
    }

    $recoveryCodesEnc = isset($config['totp']['recovery_codes_enc']) && is_array($config['totp']['recovery_codes_enc']) ? $config['totp']['recovery_codes_enc'] : array();
    $usedCodes = isset($config['totp']['used_codes']) && is_array($config['totp']['used_codes']) ? $config['totp']['used_codes'] : array();
    $codesFormat = isset($config['totp']['codes_format']) ? $config['totp']['codes_format'] : (count($recoveryCodesEnc) > 0 ? 'hash_legacy' : 'enc');

    if ($action === 'regenerate') {
        $recoveryCodes = array();
        $recoveryCodesSealed = array();

        for ($i = 0; $i < 8; $i++) {
            $code = gojs_crypto_get_rand_alphanum(16);
            $formatted = substr($code, 0, 4) . '-' . substr($code, 4, 4) . '-' . substr($code, 8, 4) . '-' . substr($code, 12, 4);
            $recoveryCodes[] = $formatted;
            $recoveryCodesSealed[] = gojs_seal_secret($formatted);
        }

        if (!isset($config['totp']) || !is_array($config['totp'])) {
            $config['totp'] = array(
                'enabled' => false,
                'secret_enc' => '',
                'recovery_codes_enc' => array(),
                'used_codes' => array(),
            );
        }

        $config['totp']['recovery_codes_enc'] = $recoveryCodesSealed;
        $config['totp']['used_codes'] = array();
        $config['totp']['codes_format'] = 'enc';
        gojs_save_config();

        gojs_log_operation('totp_recovery_regenerate', 'security', true);
        gojs_json_response(array(
            'recovery_codes' => $recoveryCodes,
            'codes_format' => 'enc',
            'regenerated' => true,
        ));
        return;
    }

    $isDownload = ($action === 'download');

    if ($codesFormat === 'enc') {
        $plainCodes = array();
        foreach ($recoveryCodesEnc as $sealed) {
            $plain = gojs_unseal_secret($sealed);
            if ($plain !== false && $plain !== '') {
                $plainCodes[] = $plain;
            }
        }

        $data = array(
            'recovery_codes' => $plainCodes,
            'codes_format' => 'enc',
            'recovery_codes_count' => count($recoveryCodesEnc),
            'used_count' => count($usedCodes),
        );

        if ($isDownload) {
            $data['download'] = true;
            $data['filename'] = 'gojs-recovery-codes-' . date('Ymd') . '.txt';
            gojs_log_operation('totp_recovery_download', 'security', true);
        } else {
            gojs_log_operation('totp_recovery_view', 'security', true);
        }

        gojs_json_response($data);
        return;
    }

    gojs_log_operation($isDownload ? 'totp_recovery_download' : 'totp_recovery_view', 'security', true);
    gojs_json_response(array(
        'recovery_codes_count' => count($recoveryCodesEnc),
        'used_count' => count($usedCodes),
        'view_only' => true,
        'legacy' => true,
        'message_key' => 'totp.recoveryLegacyNotice',
    ));
}

function gojs_api_settings_export() {
    global $config;

    $settings = isset($_SESSION['settings']) ? $_SESSION['settings'] : array();
    if (!$settings) {
        $settings = array(
            'theme' => 'system',
            'language' => 'zh',
            'sessionTimeout' => isset($config['session_timeout']) ? (int)$config['session_timeout'] : 1800,
        );
    }

    $export = array(
        'theme' => isset($settings['theme']) ? $settings['theme'] : 'system',
        'language' => isset($settings['language']) ? $settings['language'] : 'zh',
        'sessionTimeout' => isset($config['session_timeout']) ? (int)$config['session_timeout'] : (isset($settings['sessionTimeout']) ? (int)$settings['sessionTimeout'] : 1800),
        'rootPath' => isset($config['root_path']) ? $config['root_path'] : ROOT,
        'exportedAt' => time(),
        'version' => VERSION,
    );

    $json = json_encode($export, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $filename = 'gojs-config-' . date('Ymd_His') . '.json';

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Length: ' . strlen($json));

    gojs_monitor_bump_bandwidth(0, strlen($json));
    echo $json;
    exit;
}

function gojs_api_settings_reset() {
    global $config, $installed;

    session_unset();
    if (session_status() !== PHP_SESSION_NONE) {
        @session_destroy();
    }

    if (file_exists(CONFIG_FILE)) {
        @unlink(CONFIG_FILE);
    }

    $config = array();
    $installed = false;

    gojs_json_response(array('success' => true));
}
