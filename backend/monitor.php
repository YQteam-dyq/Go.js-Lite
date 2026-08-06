<?php

// Monitoring and notification storage: system sampling, alerts, notification channel delivery.
// Split from api.php; keep original function signatures and behavior unchanged.

function gojs_notifications_path(): string {
    return CONFIG_DIR . '/notifications.json';
}

function gojs_outbox_path(): string {
    return CONFIG_DIR . '/outbox.json';
}

function gojs_read_json_lock_safe(string $path, $default = array()) {
    if (!file_exists($path)) return $default;
    $fp = @fopen($path, 'r');
    if (!$fp) {
        $fallback = @file_get_contents($path);
        if ($fallback === false) return $default;
        $data = json_decode($fallback, true);
        return is_array($data) ? $data : $default;
    }
    if (!@flock($fp, LOCK_SH)) {
        fclose($fp);
        $fallback = @file_get_contents($path);
        if ($fallback === false) return $default;
        $data = json_decode($fallback, true);
        return is_array($data) ? $data : $default;
    }
    $raw = '';
    while (!feof($fp)) $raw .= fread($fp, 8192);
    @flock($fp, LOCK_UN);
    fclose($fp);
    if ($raw === '') return $default;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function gojs_write_json_lock_safe(string $path, array $data, bool $pretty = true): void {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $flags = $pretty ? (JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : JSON_UNESCAPED_UNICODE;
    $json = json_encode($data, $flags);
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    @file_put_contents($tmp, $json, LOCK_EX);
    @chmod($tmp, 0600);
    @rename($tmp, $path);
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

function gojs_save_notifications(array $items): void {
    global $config;
    $meta = isset($config['notifications_meta']) ? $config['notifications_meta'] : array();
    $cap = isset($meta['trim_cap']) ? (int)$meta['trim_cap'] : 10000;
    if ($cap < 100) $cap = 10000;
    if (count($items) > $cap) {
        $items = array_slice($items, -$cap);
    }
    gojs_write_json_lock_safe(gojs_notifications_path(), $items, true);
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

function gojs_monitor_history_save(array $items): void {
    $cap = gojs_monitor_history_cap();
    if (count($items) > $cap) {
        $items = array_slice($items, -$cap);
    }
    gojs_write_json_lock_safe(gojs_monitor_history_path(), $items, true);
}

function gojs_monitor_count_inodes(): array {
    // Inode proxy: recursively count files+dirs under the root, with a hard cap to avoid timeouts.
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

    // Bandwidth delta = bytes added since the last sampling snapshot.
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

function gojs_channel_webhook_send(array $channel, array $payload): array {
    $url = isset($channel['url']) ? $channel['url'] : '';
    if (!$url) {
        return array('ok' => false, 'error' => 'webhook: missing url');
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
