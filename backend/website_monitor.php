<?php

function gojs_website_monitor_config_path(): string {
    return CONFIG_DIR . '/website_monitor.json';
}

function gojs_website_monitor_history_path(): string {
    return CONFIG_DIR . '/website_monitor_history.json';
}

function gojs_website_monitor_notifications_path(): string {
    return CONFIG_DIR . '/website_monitor_notifications.json';
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

function gojs_website_monitor_load_config(): array {
    return gojs_read_json_lock_safe(gojs_website_monitor_config_path(), array());
}

function gojs_website_monitor_save_config(array $config): void {
    gojs_write_json_lock_safe(gojs_website_monitor_config_path(), $config, true);
}

function gojs_website_monitor_load_history(): array {
    $items = gojs_read_json_lock_safe(gojs_website_monitor_history_path(), array());
    $cap = 1000;
    if (count($items) > $cap) {
        $items = array_slice($items, -$cap);
        gojs_write_json_lock_safe(gojs_website_monitor_history_path(), $items, true);
    }
    return $items;
}

function gojs_website_monitor_save_history(array $items): void {
    $cap = 1000;
    if (count($items) > $cap) {
        $items = array_slice($items, -$cap);
    }
    gojs_write_json_lock_safe(gojs_website_monitor_history_path(), $items, true);
}

function gojs_website_monitor_load_notifications(): array {
    return gojs_read_json_lock_safe(gojs_website_monitor_notifications_path(), array());
}

function gojs_website_monitor_save_notifications(array $notifications): void {
    gojs_write_json_lock_safe(gojs_website_monitor_notifications_path(), $notifications, true);
}

function gojs_website_monitor_check_url(string $url, int $timeout = 10): array {
    $result = array(
        'url' => $url,
        'timestamp' => time(),
        'status' => 'unknown',
        'response_time' => 0,
        'status_code' => 0,
        'error' => null,
        'content_size' => 0,
    );
    
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $result['status'] = 'invalid_url';
        $result['error'] = 'Invalid URL format';
        return $result;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_NOBODY, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $start_time = microtime(true);
    $response = curl_exec($ch);
    $end_time = microtime(true);
    
    $result['response_time'] = round(($end_time - $start_time) * 1000, 2);
    $result['status_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $result['content_size'] = strlen($response);
    $result['error'] = curl_error($ch);
    
    curl_close($ch);
    
    if ($result['error'] !== '') {
        $result['status'] = 'error';
    } elseif ($result['status_code'] >= 200 && $result['status_code'] < 400) {
        $result['status'] = 'up';
    } else {
        $result['status'] = 'down';
    }
    
    return $result;
}

function gojs_website_monitor_run_checks(): void {
    $config = gojs_website_monitor_load_config();
    $websites = isset($config['websites']) && is_array($config['websites']) ? $config['websites'] : array();
    
    $history = gojs_website_monitor_load_history();
    $notifications = gojs_website_monitor_load_notifications();
    
    foreach ($websites as $website) {
        if (empty($website['url']) || empty($website['enabled'])) {
            continue;
        }
        
        $timeout = isset($website['timeout']) ? (int)$website['timeout'] : 10;
        $check_result = gojs_website_monitor_check_url($website['url'], $timeout);
        
        $history[] = $check_result;
        
        if ($check_result['status'] === 'down' && !empty($website['notifications'])) {
            $notification = array(
                'id' => uniqid('wm_', true),
                'website_id' => $website['id'],
                'website_name' => $website['name'],
                'url' => $website['url'],
                'status' => 'down',
                'status_code' => $check_result['status_code'],
                'response_time' => $check_result['response_time'],
                'error' => $check_result['error'],
                'timestamp' => time(),
                'sent' => false,
            );
            $notifications[] = $notification;
        }
    }
    
    gojs_website_monitor_save_history($history);
    gojs_website_monitor_save_notifications($notifications);
}

function gojs_api_website_monitor_config() {
    $config = gojs_website_monitor_load_config();
    gojs_json_response($config);
}

function gojs_api_website_monitor_update_config() {
    $data = gojs_get_body();
    if (!is_array($data)) {
        gojs_json_response(null, array('code' => 'invalid_data', 'message' => 'Invalid data format'), 400);
        return;
    }
    
    $config = gojs_website_monitor_load_config();
    
    if (isset($data['websites']) && is_array($data['websites'])) {
        $config['websites'] = $data['websites'];
    }
    
    if (isset($data['check_interval'])) {
        $config['check_interval'] = (int)$data['check_interval'];
    }
    
    gojs_website_monitor_save_config($config);
    gojs_json_response($config);
}

function gojs_api_website_monitor_history() {
    $history = gojs_website_monitor_load_history();
    gojs_json_response($history);
}

function gojs_api_website_monitor_run_check() {
    gojs_website_monitor_run_checks();
    gojs_json_response(array('message' => 'Checks completed'));
}

function gojs_api_website_monitor_notifications() {
    $notifications = gojs_website_monitor_load_notifications();
    gojs_json_response($notifications);
}

function gojs_api_website_monitor_clear_notifications() {
    gojs_website_monitor_save_notifications(array());
    gojs_json_response(array('message' => 'Notifications cleared'));
}

function gojs_api_website_monitor_notification_acknowledge($notification_id) {
    $notifications = gojs_website_monitor_load_notifications();
    $updated = false;
    
    foreach ($notifications as &$notification) {
        if ($notification['id'] === $notification_id) {
            $notification['acknowledged'] = true;
            $notification['acknowledged_at'] = time();
            $updated = true;
            break;
        }
    }
    
    if ($updated) {
        gojs_website_monitor_save_notifications($notifications);
        gojs_json_response(array('message' => 'Notification acknowledged'));
    } else {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'Notification not found'), 404);
    }
}