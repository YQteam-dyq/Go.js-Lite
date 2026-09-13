<?php




function gojs_notifications_path(): string {
    return CONFIG_DIR . '/notifications.json';
}

function gojs_outbox_path(): string {
    return CONFIG_DIR . '/outbox.json';
}

function gojs_load_notifications(): array {
    global $config;
    $items = gojs_read_json_lock_safe(gojs_notifications_path(), array());
    $meta = isset($config['notifications_meta']) ? $config['notifications_meta'] : array();
    $cap = isset($meta['trim_cap']) ? (int)$meta['trim_cap'] : 10000;
    if ($cap < 100) $cap = 10000;
    if (count($items) > $cap) {
        $items = array_slice($items, -$cap);
        gojs_write_json_lock_safe(gojs_notifications_path(), $items, true);
    }
    return $items;
}

function gojs_save_notifications(array $items): array {
    global $config;
    $meta = isset($config['notifications_meta']) ? $config['notifications_meta'] : array();
    $cap = isset($meta['trim_cap']) ? (int)$meta['trim_cap'] : 10000;
    if ($cap < 100) $cap = 10000;
    if (count($items) > $cap) {
        $items = array_slice($items, -$cap);
    }
    $result = gojs_write_json_lock_safe(gojs_notifications_path(), $items, true);
    if (!$result['success']) {
        return array('success' => false, 'error' => $result['error']);
    }
    return array('success' => true);
}

function gojs_append_notification(array $payload): string {
    $items = gojs_load_notifications();
    $id = uniqid('n_', true);
    $item = array_merge($payload, array(
        'id' => $id,
        'created_at' => time(),
        'read_at' => null,
    ));
    $items[] = $item;
    gojs_save_notifications($items);
    return $id;
}

function gojs_append_outbox(array $payload): void {
    $items = gojs_read_json_lock_safe(gojs_outbox_path(), array());
    $items[] = array_merge($payload, array(
        'queued_at' => time(),
        'attempts' => 0,
        'next_attempt_at' => time(),
    ));
    if (count($items) > 5000) {
        $items = array_slice($items, -5000);
    }
    gojs_write_json_lock_safe(gojs_outbox_path(), $items, false);
}

function gojs_load_channels(): array {
    global $config;
    return isset($config['notification_channels']) && is_array($config['notification_channels'])
        ? $config['notification_channels']
        : array();
}

function gojs_save_channels(array $channels): void {
    global $config;
    $config['notification_channels'] = $channels;
    gojs_save_config();
}

function gojs_monitor_history_path(): string {
    return CONFIG_DIR . '/monitor_history.json';
}

function gojs_monitor_bandwidth_path(): string {
    return CONFIG_DIR . '/monitor_bandwidth.json';
}

function gojs_monitor_history_cap(): int {
    global $config;
    $cap = isset($config['monitor']['history_cap']) ? (int)$config['monitor']['history_cap'] : 168;
    if ($cap < 12) $cap = 168;
    return $cap;
}

function gojs_monitor_history_load(): array {
    $items = gojs_read_json_lock_safe(gojs_monitor_history_path(), array());
    $cap = gojs_monitor_history_cap();
    if (count($items) > $cap) {
        $items = array_slice($items, -$cap);
        gojs_write_json_lock_safe(gojs_monitor_history_path(), $items, true);
    }
    return $items;
}

function gojs_monitor_history_save(array $items): array {
    $cap = gojs_monitor_history_cap();
    if (count($items) > $cap) {
        $items = array_slice($items, -$cap);
    }
    $result = gojs_write_json_lock_safe(gojs_monitor_history_path(), $items, true);
    if (!$result['success']) {
        return array('success' => false, 'error' => $result['error']);
    }
    return array('success' => true);
}

function gojs_monitor_count_inodes(): array {
    
    $limit = 300000;
    $count = 0;
    $truncated = false;
    $root = isset($GLOBALS['root_path']) && is_string($GLOBALS['root_path']) ? $GLOBALS['root_path'] : ROOT;

    $stack = array($root);
    while (!empty($stack)) {
        $dir = array_pop($stack);
        $handle = @opendir($dir);
        if (!$handle) continue;
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $count++;
            if ($count >= $limit) {
                $truncated = true;
                closedir($handle);
                break 2;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $stack[] = $path;
            }
        }
        closedir($handle);
    }

    return array($count, $truncated);
}

function gojs_monitor_bump_bandwidth($in_bytes = 0, $out_bytes = 0) {
    $path = gojs_monitor_bandwidth_path();
    $data = gojs_read_json_lock_safe($path, array('total_in' => 0, 'total_out' => 0, 'day' => date('Ymd')));
    $today = date('Ymd');
    if (!isset($data['day']) || $data['day'] !== $today) {
        $data = array('total_in' => 0, 'total_out' => 0, 'day' => $today);
    }
    if (!isset($data['total_in'])) $data['total_in'] = 0;
    if (!isset($data['total_out'])) $data['total_out'] = 0;
    $data['total_in'] += (int)$in_bytes;
    $data['total_out'] += (int)$out_bytes;
    gojs_write_json_lock_safe($path, $data, false);
}

function gojs_monitor_sample(): array {
    global $config, $root_path;

    $mon = isset($config['monitor']) && is_array($config['monitor']) ? $config['monitor'] : array();
    $inode_cap = isset($mon['inode_cap']) ? (int)$mon['inode_cap'] : 200000;
    if ($inode_cap <= 0) $inode_cap = 200000;

    $disk_total = @disk_total_space($root_path);
    $disk_free = @disk_free_space($root_path);
    $disk_used = ($disk_total && $disk_free) ? max(0, $disk_total - $disk_free) : 0;
    $disk_used_pct = $disk_total > 0 ? round($disk_used / $disk_total * 100, 1) : 0;

    list($file_count, $truncated) = gojs_monitor_count_inodes();
    $inode_used_pct = round($file_count / $inode_cap * 100, 1);

    $bw = gojs_read_json_lock_safe(gojs_monitor_bandwidth_path(), array('total_in' => 0, 'total_out' => 0, 'day' => date('Ymd')));
    $bw_in = isset($bw['total_in']) ? (int)$bw['total_in'] : 0;
    $bw_out = isset($bw['total_out']) ? (int)$bw['total_out'] : 0;

    
    $history = gojs_monitor_history_load();
    $last_bw_in = 0;
    $last_bw_out = 0;
    if (!empty($history)) {
        $last = $history[count($history) - 1];
        $last_bw_in = isset($last['bandwidth_in_day']) ? (int)$last['bandwidth_in_day'] : 0;
        $last_bw_out = isset($last['bandwidth_out_day']) ? (int)$last['bandwidth_out_day'] : 0;
    }
    $bw_delta = max(0, ($bw_in - $last_bw_in) + ($bw_out - $last_bw_out));

    $sample = array(
        'ts' => time(),
        'disk_used_pct' => $disk_used_pct,
        'disk_used' => (int)$disk_used,
        'disk_total' => (int)$disk_total,
        'file_count' => $file_count,
        'inode_cap' => $inode_cap,
        'inode_used_pct' => $inode_used_pct,
        'inode_truncated' => $truncated,
        'bandwidth_in_day' => $bw_in,
        'bandwidth_out_day' => $bw_out,
        'bandwidth_delta' => $bw_delta,
    );

    $history[] = $sample;
    gojs_monitor_history_save($history);

    return $sample;
}

function gojs_monitor_fire_alert(string $kind, array $sample, $threshold) {
    $channels = gojs_load_channels();
    $channel_ids = array();
    foreach ($channels as $ch) {
        if (!empty($ch['enabled']) && isset($ch['id'])) {
            $channel_ids[] = $ch['id'];
        }
    }

    $is_disk = $kind === 'disk';
    $pct = $is_disk ? $sample['disk_used_pct'] : $sample['inode_used_pct'];

    gojs_append_notification(array(
        'category' => 'monitor',
        'severity' => 'warning',
        'title_key' => $is_disk ? 'monitor.alertDiskTitle' : 'monitor.alertInodeTitle',
        'body_key' => $is_disk ? 'monitor.alertDiskBody' : 'monitor.alertInodeBody',
        'body_params' => array(
            'pct' => $pct,
            'threshold' => $threshold,
        ),
        'payload' => array(
            'source' => 'monitor',
            'kind' => $kind,
            'ts' => $sample['ts'],
        ),
    ));

    gojs_append_outbox(array(
        'channel_ids' => $channel_ids,
        'payload' => array(
            'subject' => ($is_disk ? '[Go.js] Disk usage alert: ' : '[Go.js] Inode usage alert: ') . $pct . '%',
            'body' => ($is_disk ? 'Disk usage' : 'Inode usage') . " exceeded threshold\n"
                . 'Current: ' . $pct . "%\n"
                . 'Threshold: ' . $threshold . "%\n"
                . 'Time: ' . date('Y-m-d H:i:s', $sample['ts']),
        ),
    ));
}

function gojs_monitor_maybe_alert(array $sample): void {
    global $config;

    $mon = isset($config['monitor']) && is_array($config['monitor']) ? $config['monitor'] : array();
    $disk_threshold = isset($mon['disk_threshold_pct']) ? (float)$mon['disk_threshold_pct'] : 90;
    $inode_threshold = isset($mon['inode_threshold_pct']) ? (float)$mon['inode_threshold_pct'] : 90;
    $cooldown = 6 * 3600;
    $now = time();

    $last_disk = isset($mon['last_alert_disk']) ? (int)$mon['last_alert_disk'] : 0;
    $last_inode = isset($mon['last_alert_inode']) ? (int)$mon['last_alert_inode'] : 0;

    $changed = false;

    if ($sample['disk_used_pct'] >= $disk_threshold && ($now - $last_disk) > $cooldown) {
        gojs_monitor_fire_alert('disk', $sample, $disk_threshold);
        $config['monitor']['last_alert_disk'] = $now;
        $changed = true;
    }

    if ($sample['inode_used_pct'] >= $inode_threshold && ($now - $last_inode) > $cooldown) {
        gojs_monitor_fire_alert('inode', $sample, $inode_threshold);
        $config['monitor']['last_alert_inode'] = $now;
        $changed = true;
    }

    if ($changed) {
        gojs_save_config();
    }
}

function gojs_api_monitor() {
    global $config;

    $mon = isset($config['monitor']) && is_array($config['monitor']) ? $config['monitor'] : array();
    $interval = isset($mon['sample_interval_min']) ? (int)$mon['sample_interval_min'] : 60;
    if ($interval <= 0) $interval = 60;

    $history = gojs_monitor_history_load();
    $last_ts = 0;
    if (!empty($history)) {
        $last = $history[count($history) - 1];
        $last_ts = isset($last['ts']) ? (int)$last['ts'] : 0;
    }

    $now = time();
    $sample = null;
    if ($last_ts === 0 || ($now - $last_ts) >= $interval * 60) {
        $sample = gojs_monitor_sample();
        gojs_monitor_maybe_alert($sample);
        $history = gojs_monitor_history_load();
    } elseif (!empty($history)) {
        $sample = $history[count($history) - 1];
    }

    gojs_json_response(array(
        'sample' => $sample,
        'history' => $history,
        'thresholds' => array(
            'disk_threshold_pct' => isset($mon['disk_threshold_pct']) ? (float)$mon['disk_threshold_pct'] : 90,
            'inode_threshold_pct' => isset($mon['inode_threshold_pct']) ? (float)$mon['inode_threshold_pct'] : 90,
        ),
        'config' => array(
            'sample_interval_min' => $interval,
            'inode_cap' => isset($mon['inode_cap']) ? (int)$mon['inode_cap'] : 200000,
        ),
    ));
}

function gojs_channel_redact(array $channel): array {
    $redacted = $channel;
    if (isset($redacted['password_enc']) && $redacted['password_enc'] !== '') {
        $redacted['password_enc'] = '****';
    }
    if (isset($redacted['private_key_enc']) && $redacted['private_key_enc'] !== '') {
        $redacted['private_key_enc'] = '****';
    }
    if (isset($redacted['headers_enc']) && $redacted['headers_enc'] !== '') {
        $redacted['headers_enc'] = '****';
    }
    return $redacted;
}

function gojs_channel_mail_send(array $channel, array $payload): array {
    $to = isset($channel['to_addr']) ? $channel['to_addr'] : (isset($channel['from_addr']) ? $channel['from_addr'] : '');
    if (!$to) {
        return array('ok' => false, 'error' => 'email: missing recipient');
    }
    $subject = isset($payload['subject']) ? (string)$payload['subject'] : 'Go.js Notification';
    $body = isset($payload['body']) ? (string)$payload['body'] : (is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE));
    $from = isset($channel['from_addr']) && $channel['from_addr'] !== '' ? $channel['from_addr'] : 'no-reply@localhost';
    $headers = 'From: ' . $from . "\r\n" .
        'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
        'X-Mailer: PHP/' . phpversion();

    if (function_exists('mail')) {
        $sent = @mail($to, $subject, $body, $headers);
        if ($sent) return array('ok' => true);
    }
    return array('ok' => true);
}

function gojs_channel_smtp_send(array $channel, array $payload): array {
    $host = isset($channel['host']) ? $channel['host'] : '';
    $port = isset($channel['port']) ? (int)$channel['port'] : 25;
    $from = isset($channel['from_addr']) ? $channel['from_addr'] : '';
    if (!$host || !$from) {
        return array('ok' => false, 'error' => 'smtp: missing host or from_addr');
    }
    $use_tls = !empty($channel['use_tls']);
    $username = isset($channel['username']) ? $channel['username'] : '';
    $password_enc = isset($channel['password_enc']) ? $channel['password_enc'] : '';
    $password = $password_enc !== '' && $password_enc !== '****' ? gojs_decrypt($password_enc) : '';
    $to_addr = isset($channel['to_addr']) ? $channel['to_addr'] : $from;
    $subject = isset($payload['subject']) ? (string)$payload['subject'] : 'Go.js Notification';
    $body = isset($payload['body']) ? (string)$payload['body'] : (is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE));

    if (function_exists('fsockopen')) {
        $try_host = $use_tls ? 'tls://' . $host : $host;
        $fp = @fsockopen($try_host, $port, $errno, $errstr, 10);
        if ($fp) {
            stream_set_timeout($fp, 10);
            $read = function () use ($fp) {
                $data = '';
                while (($line = fgets($fp, 515)) !== false) {
                    $data .= $line;
                    if (isset($line[3]) && $line[3] === ' ') break;
                }
                return $data;
            };
            $write = function ($data) use ($fp) {
                fputs($fp, $data . "\r\n");
            };
            $banner = $read();
            if (stripos($banner, '220') !== false) {
                $write('EHLO gojs.local');
                $read();
                if ($username !== '') {
                    $write('AUTH LOGIN');
                    $read();
                    $write(base64_encode($username));
                    $read();
                    if ($password !== '') {
                        $write(base64_encode($password));
                        $read();
                    }
                }
                $write('MAIL FROM: <' . $from . '>');
                $read();
                $write('RCPT TO: <' . $to_addr . '>');
                $read();
                $write('DATA');
                $rdata = $read();
                if (stripos($rdata, '354') !== false) {
                    $write('Subject: ' . $subject);
                    $write('From: ' . $from);
                    $write('To: ' . $to_addr);
                    $write('Content-Type: text/plain; charset=UTF-8');
                    $write('');
                    foreach (explode("\n", $body) as $line) {
                        $write(rtrim($line, "\r"));
                    }
                    $write('.');
                    $read();
                }
                $write('QUIT');
            }
            fclose($fp);
            return array('ok' => true);
        }
    }
    return array('ok' => true);
}

// SSRF guard for outbound webhook URLs.
//
// The webhook target is user supplied, so it must never be allowed to point at
// the host itself or at the surrounding infrastructure. This helper accepts
// only absolute http/https URLs without userinfo or control characters, then
// resolves the host name and rejects the URL when any resolved address is a
// private, loopback, link-local, shared, multicast, reserved or unspecified
// address. Every address returned by the resolver is inspected, so a host that
// maps to at least one unsafe address is refused as a whole.
//
// Residual risk: DNS rebinding. The name is resolved here and resolved again by
// the HTTP client when the connection is actually opened, so a hostile DNS
// server could answer with a safe address now and an internal address on the
// next query. Closing that gap requires re-validating the connected peer
// address (or pinning the resolved IP) at connection time. This project has no
// shared HTTP request wrapper where such a check could live, so the limitation
// is documented here; gojs_channel_webhook_send() and the notification channel
// endpoints rely on this pre-flight validation only.
function gojs_webhook_url_allowed($url): bool {
    if (!is_string($url) || $url === '' || strlen($url) > 2048) {
        return false;
    }
    if (preg_match('/[\x00-\x1F\x7F\s]/', $url)) {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, array('http', 'https'), true)) {
        return false;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }
    if (isset($parts['port'])) {
        if (!is_int($parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535) {
            return false;
        }
    }
    $host = $parts['host'];
    if (!is_string($host) || $host === '') {
        return false;
    }
    // parse_url() keeps IPv6 literals wrapped in brackets; unwrap for validation.
    if (strlen($host) > 1 && $host[0] === '[' && substr($host, -1) === ']') {
        $host = substr($host, 1, -1);
    }
    $addresses = gojs_webhook_resolve_host($host);
    if (!$addresses) {
        return false;
    }
    foreach ($addresses as $address) {
        if (!gojs_webhook_ip_allowed($address)) {
            return false;
        }
    }
    return true;
}

// Resolve a webhook host to every address it maps to. IP literals (IPv4, IPv6
// and IPv4-mapped IPv6) are returned as-is; names are resolved through both
// gethostbynamel() and dns_get_record() so IPv4 and IPv6 answers are covered.
function gojs_webhook_resolve_host(string $host): array {
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return array($host);
    }
    $addresses = array();
    if (function_exists('gethostbynamel')) {
        $v4_list = @gethostbynamel($host);
        if (is_array($v4_list)) {
            foreach ($v4_list as $ip) {
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    $addresses[] = $ip;
                }
            }
        }
    }
    if (function_exists('dns_get_record')) {
        foreach (array(DNS_A, DNS_AAAA) as $record_type) {
            $records = @dns_get_record($host, $record_type);
            if (!is_array($records)) {
                continue;
            }
            foreach ($records as $record) {
                if ($record_type === DNS_A && !empty($record['ip']) && is_string($record['ip'])) {
                    $addresses[] = $record['ip'];
                } elseif ($record_type === DNS_AAAA && !empty($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }
    }
    return array_values(array_unique($addresses));
}

// Return true only when the address is a routable global unicast address.
function gojs_webhook_ip_allowed(string $ip): bool {
    // Normalize IPv4-mapped IPv6 (::ffff:a.b.c.d) down to plain IPv4.
    if (stripos($ip, '::ffff:') === 0) {
        $mapped = substr($ip, 7);
        if (filter_var($mapped, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ip = $mapped;
        }
    }
    $is_v4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    $is_v6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    if (!$is_v4 && !$is_v6) {
        return false;
    }
    // Primary check: PHP's native private/reserved range filter.
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }
    // Explicit guards for ranges the native filter may miss at the edges.
    if ($is_v4) {
        $octets = array_map('intval', explode('.', $ip));
        $first = intval($octets[0]);
        $second = isset($octets[1]) ? intval($octets[1]) : 0;
        if ($first === 0) return false;                         // 0.0.0.0/8, includes unspecified
        if ($first === 127) return false;                       // 127.0.0.0/8 loopback
        if ($first === 169 && $second === 254) return false;    // 169.254.0.0/16 link-local
        if ($first >= 224 && $first <= 239) return false;       // 224.0.0.0/4 multicast
        if ($first >= 240) return false;                        // 240.0.0.0/4 reserved
    } else {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }
        if ($packed === inet_pton('::1')) return false;        // ::1 loopback
        if (rtrim($packed, "\0") === '') return false;         // :: unspecified
        if ((ord($packed[0]) & 0xFE) === 0xFC) return false;   // fc00::/7 unique local
        if (ord($packed[0]) === 0xFE && (ord($packed[1]) & 0xC0) === 0x80) return false; // fe80::/10 link-local
        if (ord($packed[0]) === 0xFF) return false;            // ff00::/8 multicast
    }
    return true;
}

function gojs_channel_webhook_send(array $channel, array $payload): array {
    $url = isset($channel['url']) ? $channel['url'] : '';
    if (!$url) {
        return array('ok' => false, 'error' => 'webhook: missing url');
    }
    if (!gojs_webhook_url_allowed($url)) {
        return array('ok' => false, 'error' => 'webhook: url scheme not allowed');
    }
    $method = isset($channel['method']) && in_array(strtoupper($channel['method']), array('POST', 'PUT'))
        ? strtoupper($channel['method']) : 'POST';
    $headers = array('Content-Type: application/json');
    $headers_enc = isset($channel['headers_enc']) ? $channel['headers_enc'] : '';
    if ($headers_enc !== '' && $headers_enc !== '****') {
        $raw = gojs_decrypt($headers_enc);
        $extra = json_decode($raw, true);
        if (is_array($extra)) {
            foreach ($extra as $k => $v) {
                $headers[] = $k . ': ' . $v;
            }
        }
    }
    $body_data = $payload;
    $body_json = json_encode($body_data, JSON_UNESCAPED_UNICODE);

    if (function_exists('stream_context_create') && in_array('https', stream_get_wrappers())) {
        $ctx = stream_context_create(array(
            'http' => array(
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body_json,
                'timeout' => 10,
                'ignore_errors' => true,
            ),
        ));
        $result = @file_get_contents($url, false, $ctx);
        if ($result !== false) {
            return array('ok' => true);
        }
    }
    return array('ok' => true);
}

function gojs_channels_deliver_all(): array {
    global $config;

    $outbox_path = gojs_outbox_path();
    $items = gojs_read_json_lock_safe($outbox_path, array());
    $total = count($items);

    if ($total > 1000) {
        error_log('gojs: outbox size ' . $total . ' exceeds 1000, draining anyway');
    }

    $channels = gojs_load_channels();
    $enabled_channels = array();
    foreach ($channels as $ch) {
        if (empty($ch['enabled'])) continue;
        $enabled_channels[] = $ch;
    }

    $now = time();
    $processed = 0;
    $channel_failure_counts = array();
    $kept = array();

    foreach ($items as $idx => $item) {
        if (empty($item['next_attempt_at']) || (int)$item['next_attempt_at'] > $now) {
            $kept[] = $item;
            continue;
        }
        $attempts = isset($item['attempts']) ? (int)$item['attempts'] : 0;
        $match_ids = isset($item['channel_ids']) && is_array($item['channel_ids']) ? $item['channel_ids'] : null;
        $target_channels = $enabled_channels;
        if ($match_ids !== null && count($match_ids) > 0) {
            $id_set = array_flip($match_ids);
            $target_channels = array_filter($enabled_channels, function ($c) use ($id_set) {
                return isset($c['id']) && isset($id_set[$c['id']]);
            });
        }
        $payload = isset($item['payload']) ? $item['payload'] : array();
        $any_failed = false;
        foreach ($target_channels as $ch) {
            $cid = isset($ch['id']) ? $ch['id'] : '?';
            $type = isset($ch['type']) ? $ch['type'] : '';
            $engine_result = null;
            if ($type === 'email') {
                $engine_result = gojs_channel_mail_send($ch, $payload);
            } elseif ($type === 'smtp') {
                $engine_result = gojs_channel_smtp_send($ch, $payload);
            } elseif ($type === 'webhook') {
                $engine_result = gojs_channel_webhook_send($ch, $payload);
            } else {
                $engine_result = array('ok' => true);
            }
            if (empty($engine_result['ok'])) {
                if (!isset($channel_failure_counts[$cid])) $channel_failure_counts[$cid] = 0;
                $channel_failure_counts[$cid]++;
                $any_failed = true;
            }
        }
        if ($any_failed) {
            $attempts++;
            if ($attempts >= 5) {
                $processed++;
                continue;
            }
            $backoff = pow(2, $attempts) * 60;
            $item['attempts'] = $attempts;
            $item['next_attempt_at'] = $now + $backoff;
            $kept[] = $item;
        } else {
            $processed++;
        }
    }

    gojs_write_json_lock_safe($outbox_path, $kept, false);

    if (!isset($config['notifications_meta']) || !is_array($config['notifications_meta'])) {
        $config['notifications_meta'] = array();
    }
    $config['notifications_meta']['last_processed_at'] = $now;
    $config['notifications_meta']['last_processed_count'] = $processed;
    gojs_save_config();

    return array(
        'processed' => $processed,
        'channel_failure_counts' => $channel_failure_counts,
    );
}

class GOJS_Base32 {
    private static $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $bin): string {
        if ($bin === '') return '';
        $binary = '';
        foreach (str_split($bin) as $b) {
            $binary .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $binary = str_pad($binary, (int)ceil(strlen($binary) / 5) * 5, '0', STR_PAD_RIGHT);
        $result = '';
        foreach (str_split($binary, 5) as $chunk) {
            $result .= self::$alphabet[bindec($chunk)];
        }
        return $result;
    }

    public static function decode(string $b32): string {
        $b32 = strtoupper(rtrim($b32, '='));
        if ($b32 === '') return '';
        $binary = '';
        foreach (str_split($b32) as $c) {
            $pos = strpos(self::$alphabet, $c);
            if ($pos === false) continue;
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $binary = substr($binary, 0, (int)floor(strlen($binary) / 8) * 8);
        $result = '';
        foreach (str_split($binary, 8) as $chunk) {
            if (strlen($chunk) < 8) break;
            $result .= chr(bindec($chunk));
        }
        return $result;
    }
}
