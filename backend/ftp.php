<?php
function gojs_ftp_capabilities(): array {
    global $config, $root_path;

    $supported = array('proftpd_authfile', 'pureftpd_passwd');
    $active_provider = null;
    $detected_path = null;
    $can_write = true;

    $provider_override = isset($config['ftp']['provider']) ? $config['ftp']['provider'] : '';
    $path_override = isset($config['ftp']['path']) ? $config['ftp']['path'] : '';

    if ($provider_override && $path_override) {
        $active_provider = $provider_override;
        $detected_path = $path_override;
        $can_write = is_writable($detected_path) || (!file_exists($detected_path) && is_writable(dirname($detected_path)));
    } else {
        $docroot_parent = rtrim($root_path, '/\\') . DIRECTORY_SEPARATOR . '..';
        $proftpd_candidates = array(
            rtrim($docroot_parent, '/\\') . DIRECTORY_SEPARATOR . '.ftp_passwd',
            rtrim($docroot_parent, '/\\') . DIRECTORY_SEPARATOR . 'proftpd.passwd',
            CONFIG_DIR . DIRECTORY_SEPARATOR . 'ftpd.passwd',
        );
        foreach ($proftpd_candidates as $cand) {
            if (file_exists($cand) && is_readable($cand)) {
                $active_provider = 'proftpd_authfile';
                $detected_path = $cand;
                $can_write = is_writable($cand);
                break;
            }
        }

        if ($active_provider === null) {
            $pureftpd_candidates = array(
                CONFIG_DIR . DIRECTORY_SEPARATOR . 'pureftpd.passwd',
                rtrim($docroot_parent, '/\\') . DIRECTORY_SEPARATOR . 'pureftpd.passwd',
            );
            foreach ($pureftpd_candidates as $cand) {
                if (file_exists($cand) && is_readable($cand)) {
                    $active_provider = 'pureftpd_passwd';
                    $detected_path = $cand;
                    $can_write = is_writable($cand);
                    break;
                }
            }
        }

        if ($active_provider === null) {
            $can_write = true;
        }
    }

    $posix_available = function_exists('posix_getuid') && function_exists('posix_getgid');
    $default_uid = $posix_available ? posix_getuid() : 1000;
    $default_gid = $posix_available ? posix_getgid() : 1000;

    
    
    $reasons = array();
    if (!$posix_available) {
        $reasons[] = array('code' => 'posix_unavailable', 'key' => 'ftp.degradePosix', 'severity' => 'warning');
    }
    if ($active_provider === null) {
        $reasons[] = array('code' => 'provider_not_found', 'key' => 'ftp.degradeNoProvider', 'severity' => 'warning');
    } elseif (!$can_write) {
        $reasons[] = array('code' => 'provider_not_writable', 'key' => 'ftp.degradeNotWritable', 'severity' => 'danger');
    }
    
    if (!extension_loaded('ftp') && !function_exists('fsockopen')) {
        $reasons[] = array('code' => 'ftp_ext_unavailable', 'key' => 'ftp.degradeFtpExt', 'severity' => 'info');
    }

    
    $degradation_reasons = array();
    foreach ($reasons as $r) {
        $degradation_reasons[] = array('key' => $r['code'], 'message_key' => $r['key']);
    }

    return array(
        'available' => true,
        'degraded' => count($reasons) > 0,
        'reasons' => $reasons,
        'degradation_reasons' => $degradation_reasons,
        'posix_available' => $posix_available,
        'supported_providers' => $supported,
        'active_provider' => $active_provider,
        'path' => $detected_path,
        'can_write' => $can_write,
        'default_uid' => $default_uid,
        'default_gid' => $default_gid,
    );
}

function gojs_api_ftp_capabilities() {
    gojs_json_response(gojs_ftp_capabilities());
}

function gojs_ftp_accounts_load(): array {
    global $config;
    if (!isset($config['ftp_accounts']) || !is_array($config['ftp_accounts'])) {
        return array();
    }
    return $config['ftp_accounts'];
}

function gojs_ftp_accounts_save(array $accounts): void {
    global $config;
    $config['ftp_accounts'] = array_values($accounts);
    gojs_save_config();
}

function gojs_ftp_account_redact(array $account): array {
    $redacted = $account;
    unset($redacted['password_hash_enc']);
    $redacted['password'] = null;
    return $redacted;
}

function gojs_ftp_validate_username(string $username): bool {
    return (bool)preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username);
}

function gojs_ftp_validate_home_dir(string $home_dir): bool {
    if ($home_dir === '' || $home_dir === null) {
        return false;
    }
    if (strpos($home_dir, '..') !== false) {
        return false;
    }
    return true;
}

function gojs_api_ftp_accounts_list() {
    $accounts = gojs_ftp_accounts_load();
    $redacted = array_map('gojs_ftp_account_redact', $accounts);
    gojs_json_response(array('accounts' => $redacted));
}

function gojs_ftp_hash_password(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, array('cost' => 10));
}

function gojs_ftp_gen_id(): string {
    return 'ftp_' . bin2hex(random_bytes(8));
}

function gojs_api_ftp_accounts_create() {
    $username = gojs_get_param('username', '');
    $password = gojs_get_param('password', '');
    $home_dir = gojs_get_param('home_dir', '');
    $uid = gojs_get_param('uid', null);
    $gid = gojs_get_param('gid', null);
    $quota_size_mb = gojs_get_param('quota_size_mb', null);
    $quota_files = gojs_get_param('quota_files', null);
    $upload_bw_kbps = gojs_get_param('upload_bw_kbps', null);
    $download_bw_kbps = gojs_get_param('download_bw_kbps', null);
    $allow_client_ips = gojs_get_param('allow_client_ips', '');
    $deny_client_ips = gojs_get_param('deny_client_ips', '');
    $enabled = gojs_get_param('enabled', true);
    $expires_at_ts = gojs_get_param('expires_at_ts', null);

    $username = trim($username);
    $home_dir = trim($home_dir);

    if (!gojs_ftp_validate_username($username)) {
        gojs_json_response(null, array(
            'code' => 'invalid_username',
            'message' => '用户名格式无效，仅允许3-32位字母数字下划线',
        ), 400);
    }
    if (strlen($password) < 8) {
        gojs_json_response(null, array(
            'code' => 'weak_password',
            'message' => '密码至少需要8个字符',
        ), 400);
    }
    if (!gojs_ftp_validate_home_dir($home_dir)) {
        gojs_json_response(null, array(
            'code' => 'invalid_home_dir',
            'message' => '家目录无效',
        ), 400);
    }

    $accounts = gojs_ftp_accounts_load();
    foreach ($accounts as $acc) {
        if ($acc['username'] === $username) {
            gojs_json_response(null, array(
                'code' => 'username_exists',
                'message' => '该用户名已存在',
            ), 409);
        }
    }

    $caps = gojs_ftp_capabilities();
    $now = time();

    $account = array(
        'id' => gojs_ftp_gen_id(),
        'username' => $username,
        'password_hash_enc' => gojs_ftp_hash_password($password),
        'home_dir' => $home_dir,
        'uid' => $uid !== null ? (int)$uid : (isset($caps['default_uid']) ? (int)$caps['default_uid'] : 1000),
        'gid' => $gid !== null ? (int)$gid : (isset($caps['default_gid']) ? (int)$caps['default_gid'] : 1000),
        'quota_size_mb' => $quota_size_mb !== null ? ((int)$quota_size_mb > 0 ? (int)$quota_size_mb : null) : null,
        'quota_files' => $quota_files !== null ? ((int)$quota_files > 0 ? (int)$quota_files : null) : null,
        'upload_bw_kbps' => $upload_bw_kbps !== null ? ((int)$upload_bw_kbps > 0 ? (int)$upload_bw_kbps : null) : null,
        'download_bw_kbps' => $download_bw_kbps !== null ? ((int)$download_bw_kbps > 0 ? (int)$download_bw_kbps : null) : null,
        'allow_client_ips' => is_string($allow_client_ips) ? trim($allow_client_ips) : '',
        'deny_client_ips' => is_string($deny_client_ips) ? trim($deny_client_ips) : '',
        'enabled' => (bool)$enabled,
        'expires_at_ts' => $expires_at_ts !== null ? ((int)$expires_at_ts > 0 ? (int)$expires_at_ts : null) : null,
        'created_at' => $now,
        'last_changed_at' => $now,
        'last_login_at' => null,
    );

    $accounts[] = $account;
    gojs_ftp_accounts_save($accounts);

    if ($caps['active_provider'] && $caps['can_write'] && $caps['path']) {
        gojs_ftp_sync_to_provider($accounts, $caps);
    }

    gojs_json_response(array('account' => gojs_ftp_account_redact($account)));
}

function gojs_api_ftp_accounts_update($id) {
    $accounts = gojs_ftp_accounts_load();
    $index = null;
    foreach ($accounts as $i => $acc) {
        if ($acc['id'] === $id) {
            $index = $i;
            break;
        }
    }
    if ($index === null) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '账户不存在',
        ), 404);
    }

    $account = $accounts[$index];

    $username = gojs_get_param('username', null);
    $password = gojs_get_param('password', null);
    $password_renew = gojs_get_param('password_renew', null);
    $home_dir = gojs_get_param('home_dir', null);
    $uid = gojs_get_param('uid', null);
    $gid = gojs_get_param('gid', null);
    $quota_size_mb = gojs_get_param('quota_size_mb', null);
    $quota_files = gojs_get_param('quota_files', null);
    $upload_bw_kbps = gojs_get_param('upload_bw_kbps', null);
    $download_bw_kbps = gojs_get_param('download_bw_kbps', null);
    $allow_client_ips = gojs_get_param('allow_client_ips', null);
    $deny_client_ips = gojs_get_param('deny_client_ips', null);
    $enabled = gojs_get_param('enabled', null);
    $expires_at_ts = gojs_get_param('expires_at_ts', null);

    if ($username !== null) {
        $username = trim($username);
        if (!gojs_ftp_validate_username($username)) {
            gojs_json_response(null, array(
                'code' => 'invalid_username',
                'message' => '用户名格式无效',
            ), 400);
        }
        foreach ($accounts as $i => $acc) {
            if ($i !== $index && $acc['username'] === $username) {
                gojs_json_response(null, array(
                    'code' => 'username_exists',
                    'message' => '该用户名已存在',
                ), 409);
            }
        }
        $account['username'] = $username;
    }

    if ($password !== null && $password !== '') {
        if (strlen($password) < 8) {
            gojs_json_response(null, array(
                'code' => 'weak_password',
                'message' => '密码至少需要8个字符',
            ), 400);
        }
        $account['password_hash_enc'] = gojs_ftp_hash_password($password);
    } elseif ($password_renew !== null && $password_renew !== '') {
        if (strlen($password_renew) < 8) {
            gojs_json_response(null, array(
                'code' => 'weak_password',
                'message' => '密码至少需要8个字符',
            ), 400);
        }
        $account['password_hash_enc'] = gojs_ftp_hash_password($password_renew);
    }

    if ($home_dir !== null) {
        $home_dir = trim($home_dir);
        if (!gojs_ftp_validate_home_dir($home_dir)) {
            gojs_json_response(null, array(
                'code' => 'invalid_home_dir',
                'message' => '家目录无效',
            ), 400);
        }
        $account['home_dir'] = $home_dir;
    }

    if ($uid !== null) $account['uid'] = (int)$uid;
    if ($gid !== null) $account['gid'] = (int)$gid;
    if (isset($quota_size_mb)) $account['quota_size_mb'] = ($quota_size_mb === null || (int)$quota_size_mb <= 0) ? null : (int)$quota_size_mb;
    if (isset($quota_files)) $account['quota_files'] = ($quota_files === null || (int)$quota_files <= 0) ? null : (int)$quota_files;
    if (isset($upload_bw_kbps)) $account['upload_bw_kbps'] = ($upload_bw_kbps === null || (int)$upload_bw_kbps <= 0) ? null : (int)$upload_bw_kbps;
    if (isset($download_bw_kbps)) $account['download_bw_kbps'] = ($download_bw_kbps === null || (int)$download_bw_kbps <= 0) ? null : (int)$download_bw_kbps;
    if ($allow_client_ips !== null) $account['allow_client_ips'] = trim($allow_client_ips);
    if ($deny_client_ips !== null) $account['deny_client_ips'] = trim($deny_client_ips);
    if ($enabled !== null) $account['enabled'] = (bool)$enabled;
    if (isset($expires_at_ts)) $account['expires_at_ts'] = ($expires_at_ts === null || (int)$expires_at_ts <= 0) ? null : (int)$expires_at_ts;

    $account['last_changed_at'] = time();

    $accounts[$index] = $account;
    gojs_ftp_accounts_save($accounts);

    $caps = gojs_ftp_capabilities();
    if ($caps['active_provider'] && $caps['can_write'] && $caps['path']) {
        gojs_ftp_sync_to_provider($accounts, $caps);
    }

    gojs_json_response(array('account' => gojs_ftp_account_redact($account)));
}

function gojs_api_ftp_accounts_delete($id) {
    $accounts = gojs_ftp_accounts_load();
    $found = false;
    $accounts = array_values(array_filter($accounts, function($acc) use ($id, &$found) {
        if ($acc['id'] === $id) {
            $found = true;
            return false;
        }
        return true;
    }));

    if (!$found) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '账户不存在',
        ), 404);
    }

    gojs_ftp_accounts_save($accounts);

    $caps = gojs_ftp_capabilities();
    if ($caps['active_provider'] && $caps['can_write'] && $caps['path']) {
        gojs_ftp_sync_to_provider($accounts, $caps);
    }

    gojs_json_response(array('ok' => true));
}

function gojs_api_ftp_accounts_test_login($id) {
    $accounts = gojs_ftp_accounts_load();
    $account = null;
    foreach ($accounts as $acc) {
        if ($acc['id'] === $id) {
            $account = $acc;
            break;
        }
    }
    if ($account === null) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '账户不存在',
        ), 404);
    }

    $password = gojs_get_param('password', '');
    if ($password === '') {
        gojs_json_response(null, array(
            'code' => 'password_required',
            'message' => '需要密码',
        ), 400);
    }

    $hash = isset($account['password_hash_enc']) ? $account['password_hash_enc'] : '';
    $ok = $hash !== '' && password_verify($password, $hash);

    if ($ok) {
        $accounts_copy = $accounts;
        foreach ($accounts_copy as $i => $acc) {
            if ($acc['id'] === $id) {
                $accounts_copy[$i]['last_login_at'] = time();
                break;
            }
        }
        gojs_ftp_accounts_save($accounts_copy);
    }

    gojs_json_response(array('ok' => $ok));
}

function gojs_ftp_proftpd_write(array $accounts, string $path): array {
    $lines = array();
    foreach ($accounts as $acc) {
        if (empty($acc['enabled'])) continue;
        $hash = isset($acc['password_hash_enc']) ? $acc['password_hash_enc'] : '';
        $uid = isset($acc['uid']) ? (int)$acc['uid'] : 1000;
        $gid = isset($acc['gid']) ? (int)$acc['gid'] : 1000;
        $gecos = $acc['username'];
        $homedir = $acc['home_dir'];
        $shell = '/bin/false';
        $lines[] = $acc['username'] . ':' . $hash . ':' . $uid . ':' . $gid . ':' . $gecos . ':' . $homedir . ':' . $shell;
    }
    $content = implode("\n", $lines) . "\n";
    $written = @file_put_contents($path, $content, LOCK_EX);
    if ($written === false) {
        $err = error_get_last();
        return array('ok' => false, 'error' => isset($err['message']) ? $err['message'] : '写入失败');
    }
    @chmod($path, 0640);
    return array('ok' => true);
}

function gojs_ftp_pureftpd_write(array $accounts, string $path): array {
    $lines = array();
    foreach ($accounts as $acc) {
        if (empty($acc['enabled'])) continue;
        $hash = isset($acc['password_hash_enc']) ? $acc['password_hash_enc'] : '';
        $uid = isset($acc['uid']) ? (int)$acc['uid'] : 1000;
        $gid = isset($acc['gid']) ? (int)$acc['gid'] : 1000;
        $gecos = $acc['username'];
        $homedir = $acc['home_dir'];
        $ul_bw = isset($acc['upload_bw_kbps']) ? (int)$acc['upload_bw_kbps'] : 0;
        $dl_bw = isset($acc['download_bw_kbps']) ? (int)$acc['download_bw_kbps'] : 0;
        $ul_ratio = 0;
        $dl_ratio = 0;
        $q_files = isset($acc['quota_files']) ? (int)$acc['quota_files'] : 0;
        $q_size_mb = isset($acc['quota_size_mb']) ? (int)$acc['quota_size_mb'] : 0;
        $q_size = $q_size_mb * 1024;
        $time_offset = 0;
        $allow_ip = isset($acc['allow_client_ips']) ? $acc['allow_client_ips'] : '';
        $deny_ip = isset($acc['deny_client_ips']) ? $acc['deny_client_ips'] : '';
        $allow_local = '';
        $deny_local = '';
        $max_concurrent = 0;
        $max_per_ip = 0;
        $min_uid = $uid;
        $allowed_chmod = '';
        $deleted_flag = 0;
        $expire = isset($acc['expires_at_ts']) ? (int)$acc['expires_at_ts'] : 0;

        $fields = array(
            $acc['username'],
            $hash,
            $uid,
            $gid,
            $gecos,
            '/',
            $homedir,
            $ul_bw,
            $dl_bw,
            $ul_ratio,
            $dl_ratio,
            $q_files,
            $q_size,
            $time_offset,
            $allow_ip,
            $deny_ip,
            $allow_local,
            $deny_local,
            $max_concurrent,
            $max_per_ip,
            $min_uid,
            $allowed_chmod,
            $deleted_flag,
            $expire,
        );
        $lines[] = implode(':', $fields);
    }
    $content = implode("\n", $lines) . "\n";
    $written = @file_put_contents($path, $content, LOCK_EX);
    if ($written === false) {
        $err = error_get_last();
        return array('ok' => false, 'error' => isset($err['message']) ? $err['message'] : '写入失败');
    }
    @chmod($path, 0640);
    return array('ok' => true);
}

function gojs_ftp_sync_to_provider(array $accounts, array $caps): array {
    $provider = $caps['active_provider'];
    $path = $caps['path'];
    if ($provider === 'proftpd_authfile') {
        return gojs_ftp_proftpd_write($accounts, $path);
    } elseif ($provider === 'pureftpd_passwd') {
        return gojs_ftp_pureftpd_write($accounts, $path);
    }
    return array('ok' => false, 'error' => '未知的 provider');
}

function gojs_api_ftp_sync() {
    $caps = gojs_ftp_capabilities();
    if (!$caps['active_provider']) {
        gojs_json_response(null, array(
            'code' => 'no_provider',
            'message' => '未检测到系统 FTP provider，无法同步',
        ), 400);
    }
    if (!$caps['can_write'] || !$caps['path']) {
        gojs_json_response(null, array(
            'code' => 'not_writable',
            'message' => '检测到 provider 文件但不可写',
        ), 400);
    }
    $accounts = gojs_ftp_accounts_load();
    $result = gojs_ftp_sync_to_provider($accounts, $caps);
    if (!$result['ok']) {
        gojs_json_response(null, array(
            'code' => 'sync_failed',
            'message' => isset($result['error']) ? $result['error'] : '同步失败',
        ), 500);
    }
    gojs_json_response(array('ok' => true, 'count' => count($accounts)));
}

function gojs_api_ftp_export() {
    $format = gojs_get_param('format', 'proftpd_authfile');
    $accounts = gojs_ftp_accounts_load();

    $filename = '';
    $content = '';

    if ($format === 'pureftpd_passwd') {
        $tmp_path = tempnam(sys_get_temp_dir(), 'pureftpd_');
        $result = gojs_ftp_pureftpd_write($accounts, $tmp_path);
        if (!$result['ok']) {
            @unlink($tmp_path);
            gojs_json_response(null, array(
                'code' => 'export_failed',
                'message' => isset($result['error']) ? $result['error'] : '导出失败',
            ), 500);
        }
        $content = file_get_contents($tmp_path);
        @unlink($tmp_path);
        $filename = 'pureftpd.passwd';
    } else {
        $tmp_path = tempnam(sys_get_temp_dir(), 'proftpd_');
        $result = gojs_ftp_proftpd_write($accounts, $tmp_path);
        if (!$result['ok']) {
            @unlink($tmp_path);
            gojs_json_response(null, array(
                'code' => 'export_failed',
                'message' => isset($result['error']) ? $result['error'] : '导出失败',
            ), 500);
        }
        $content = file_get_contents($tmp_path);
        @unlink($tmp_path);
        $filename = 'ftpd.passwd';
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    gojs_monitor_bump_bandwidth(0, strlen($content));
    echo $content;
    exit;
}

