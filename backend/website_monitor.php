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



function gojs_website_monitor_load_config(): array {
    return gojs_read_json_lock_safe(gojs_website_monitor_config_path(), array());
}

function gojs_website_monitor_save_config(array $config): array {
    $result = gojs_write_json_lock_safe(gojs_website_monitor_config_path(), $config, true);
    if (!$result['success']) {
        gojs_json_response(array('success' => false, 'error' => $result['error']));
    }
    gojs_json_response(array('success' => true));
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

function gojs_website_monitor_save_history(array $items): array {
    $cap = 1000;
    if (count($items) > $cap) {
        $items = array_slice($items, -$cap);
    }
    $result = gojs_write_json_lock_safe(gojs_website_monitor_history_path(), $items, true);
    if (!$result['success']) {
        return array('success' => false, 'error' => $result['error']);
    }
    return array('success' => true);
}

function gojs_website_monitor_load_notifications(): array {
    return gojs_read_json_lock_safe(gojs_website_monitor_notifications_path(), array());
}

function gojs_website_monitor_save_notifications(array $notifications): array {
    $result = gojs_write_json_lock_safe(gojs_website_monitor_notifications_path(), $notifications, true);
    if (!$result['success']) {
        return array('success' => false, 'error' => $result['error']);
    }
    return array('success' => true);
}

function gojs_website_monitor_validate_redirect_url(string $url, int $max_redirects = 5): array {
    $redirect_chain = array();
    $current_url = $url;
    
    for ($i = 0; $i <= $max_redirects; $i++) {
        $parsed_url = parse_url($current_url);
        if (!$parsed_url || !isset($parsed_url['scheme']) || !isset($parsed_url['host'])) {
            return array('valid' => false, 'error' => 'Invalid redirect URL');
        }
        
        if (!in_array($parsed_url['scheme'], array('http', 'https'))) {
            return array('valid' => false, 'error' => 'Redirect to non-HTTP/HTTPS protocol not allowed');
        }
        
        $host = $parsed_url['host'];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return array('valid' => false, 'error' => 'Redirect to private IP not allowed');
            }
        } else {
            if (preg_match('/\.(localhost|local|test|example\.com|internal|private)$/i', $host)) {
                return array('valid' => false, 'error' => 'Redirect to local/internal domain not allowed');
            }
        }
        
        $redirect_chain[] = $current_url;
        
        $ch = curl_init($current_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        curl_exec($ch);
        $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        
        if (empty($redirect_url)) {
            break;
        }
        
        $current_url = $redirect_url;
    }
    
    return array('valid' => true, 'redirect_chain' => $redirect_chain);
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
    
    $parsed_url = parse_url($url);
    if (!$parsed_url || !isset($parsed_url['scheme']) || !isset($parsed_url['host'])) {
        $result['status'] = 'invalid_url';
        $result['error'] = 'Invalid URL structure';
        return $result;
    }
    
    if (!in_array($parsed_url['scheme'], array('http', 'https'))) {
        $result['status'] = 'invalid_url';
        $result['error'] = 'Only HTTP and HTTPS protocols are allowed';
        return $result;
    }
    
    $host = $parsed_url['host'];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $result['status'] = 'invalid_url';
            $result['error'] = 'Monitoring private or local IP addresses is not allowed';
            return $result;
        }
    } else {
        if (preg_match('/\.(localhost|local|test|example\.com|internal|private)$/i', $host)) {
            $result['status'] = 'invalid_url';
            $result['error'] = 'Monitoring local/internal domains is not allowed';
            return $result;
        }
    }
    
    $redirect_validation = gojs_website_monitor_validate_redirect_url($url);
    if (!$redirect_validation['valid']) {
        $result['status'] = 'invalid_redirect';
        $result['error'] = $redirect_validation['error'];
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_CAINFO, null);
    
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
    $lock_file = sys_get_temp_dir() . '/gojs_website_monitor.lock';
    $lock_handle = @fopen($lock_file, 'w+');
    
    $lock_acquired = false;
    for ($i = 0; $i < 50; $i++) {
        if (@flock($lock_handle, LOCK_EX | LOCK_NB)) {
            $lock_acquired = true;
            break;
        }
        usleep(100000);
    }
    
    if (!$lock_acquired) {
        return;
    }
    
    try {
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
    
    gojs_website_monitor_send_pending_notifications();
    } finally {
        @flock($lock_handle, LOCK_UN);
        @fclose($lock_handle);
        @unlink($lock_file);
    }
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

function gojs_website_monitor_send_webhook_notification(array $notification, array $webhook_config): bool {
    $payload = array(
        'id' => $notification['id'],
        'website_id' => $notification['website_id'],
        'website_name' => $notification['website_name'],
        'url' => $notification['url'],
        'status' => $notification['status'],
        'status_code' => $notification['status_code'],
        'response_time' => $notification['response_time'],
        'error' => $notification['error'],
        'timestamp' => $notification['timestamp'],
        'acknowledged' => $notification['acknowledged'],
        'acknowledged_at' => $notification['acknowledged_at'],
    );
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $webhook_config['url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'User-Agent: Go.js-Lite-Website-Monitor'
    ));
    
    $timeout = isset($webhook_config['timeout']) ? (int)$webhook_config['timeout'] : 30;
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return $http_code >= 200 && $http_code < 300;
}

function gojs_website_monitor_send_pending_notifications(): void {
    $config = gojs_website_monitor_load_config();
    $notifications = gojs_website_monitor_load_notifications();
    $webhook_config = isset($config['webhook']) ? $config['webhook'] : null;
    
    if (!$webhook_config || !isset($webhook_config['url']) || empty($webhook_config['url'])) {
        return;
    }
    
    $updated_notifications = array();
    $sent_count = 0;
    
    foreach ($notifications as $notification) {
        if (!$notification['sent'] && !$notification['acknowledged']) {
            $sent = gojs_website_monitor_send_webhook_notification($notification, $webhook_config);
            if ($sent) {
                $notification['sent'] = true;
                $notification['sent_at'] = time();
                $sent_count++;
            }
        }
        $updated_notifications[] = $notification;
    }
    
    if ($sent_count > 0) {
        gojs_website_monitor_save_notifications($updated_notifications);
    }
}

function gojs_api_website_monitor_send_notifications() {
    $sent_count = 0;
    $config = gojs_website_monitor_load_config();
    $notifications = gojs_website_monitor_load_notifications();
    $webhook_config = isset($config['webhook']) ? $config['webhook'] : null;
    
    if (!$webhook_config || !isset($webhook_config['url']) || empty($webhook_config['url'])) {
        gojs_json_response(array('success' => false, 'message' => 'Webhook not configured'));
        return;
    }
    
    $updated_notifications = array();
    
    foreach ($notifications as $notification) {
        if (!$notification['sent'] && !$notification['acknowledged']) {
            $sent = gojs_website_monitor_send_webhook_notification($notification, $webhook_config);
            if ($sent) {
                $notification['sent'] = true;
                $notification['sent_at'] = time();
                $sent_count++;
            }
        }
        $updated_notifications[] = $notification;
    }
    
    if ($sent_count > 0) {
        gojs_website_monitor_save_notifications($updated_notifications);
    }
    
    gojs_json_response(array(
        'success' => true,
        'message' => "Sent $sent_count notifications",
        'sent_count' => $sent_count
    ));
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
