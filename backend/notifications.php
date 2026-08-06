<?php




function gojs_api_notification_channels($method) {
    if ($method === 'GET') {
        $channels = gojs_load_channels();
        $redacted = array_map('gojs_channel_redact', $channels);
        gojs_json_response(array_values($redacted));
    } elseif ($method === 'POST') {
        $body = gojs_get_body();
        $channels = gojs_load_channels();
        $type = isset($body['type']) ? $body['type'] : '';
        $name = isset($body['name']) ? trim((string)$body['name']) : '';
        if (!in_array($type, array('email', 'smtp', 'webhook'), true)) {
            gojs_json_response(null, array('code' => 'invalid_type', 'message' => '无效的 channel 类型'), 400);
        }
        if ($name === '') {
            gojs_json_response(null, array('code' => 'invalid_name', 'message' => '名称不能为空'), 400);
        }
        $id = uniqid('ch_', true);
        $channel = array(
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'enabled' => isset($body['enabled']) ? (bool)$body['enabled'] : true,
            'created_at' => time(),
        );
        if ($type === 'email') {
            if (isset($body['from_addr'])) $channel['from_addr'] = (string)$body['from_addr'];
            if (isset($body['to_addr'])) $channel['to_addr'] = (string)$body['to_addr'];
        } elseif ($type === 'smtp') {
            $channel['host'] = isset($body['host']) ? (string)$body['host'] : '';
            $channel['port'] = isset($body['port']) ? (int)$body['port'] : 25;
            $channel['from_addr'] = isset($body['from_addr']) ? (string)$body['from_addr'] : '';
            if (isset($body['to_addr'])) $channel['to_addr'] = (string)$body['to_addr'];
            if (isset($body['username'])) $channel['username'] = (string)$body['username'];
            if (isset($body['use_tls'])) $channel['use_tls'] = (bool)$body['use_tls'];
            if (isset($body['password']) && $body['password'] !== '' && $body['password'] !== '****') {
                $channel['password_enc'] = gojs_seal_secret($body['password']);
            }
        } elseif ($type === 'webhook') {
            $channel['url'] = isset($body['url']) ? (string)$body['url'] : '';
            if (isset($body['method'])) $channel['method'] = in_array(strtoupper((string)$body['method']), array('POST', 'PUT'), true) ? strtoupper((string)$body['method']) : 'POST';
            if (isset($body['headers']) && is_array($body['headers']) && count($body['headers']) > 0) {
                $channel['headers_enc'] = gojs_seal_secret(json_encode($body['headers'], JSON_UNESCAPED_UNICODE));
            }
        }
        $channels[] = $channel;
        gojs_save_channels($channels);
        gojs_log_operation('notify_channel_create', $id . '::' . $type, true);
        gojs_json_response(gojs_channel_redact($channel));
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}

function gojs_api_notification_channel($id, $method) {
    $channels = gojs_load_channels();
    $idx = -1;
    $target = null;
    foreach ($channels as $i => $ch) {
        if (isset($ch['id']) && $ch['id'] === $id) {
            $idx = $i;
            $target = $ch;
            break;
        }
    }
    if ($target === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'Channel 不存在'), 404);
    }

    if ($method === 'PUT') {
        $body = gojs_get_body();
        if (isset($body['name'])) $target['name'] = trim((string)$body['name']);
        if (isset($body['enabled'])) $target['enabled'] = (bool)$body['enabled'];
        $type = $target['type'];
        if ($type === 'email') {
            if (isset($body['from_addr'])) $target['from_addr'] = (string)$body['from_addr'];
            if (isset($body['to_addr'])) $target['to_addr'] = (string)$body['to_addr'];
        } elseif ($type === 'smtp') {
            if (isset($body['host'])) $target['host'] = (string)$body['host'];
            if (isset($body['port'])) $target['port'] = (int)$body['port'];
            if (isset($body['from_addr'])) $target['from_addr'] = (string)$body['from_addr'];
            if (isset($body['to_addr'])) $target['to_addr'] = (string)$body['to_addr'];
            if (isset($body['username'])) $target['username'] = (string)$body['username'];
            if (isset($body['use_tls'])) $target['use_tls'] = (bool)$body['use_tls'];
            if (isset($body['password']) && $body['password'] !== '' && $body['password'] !== '****') {
                $target['password_enc'] = gojs_seal_secret($body['password']);
            }
        } elseif ($type === 'webhook') {
            if (isset($body['url'])) $target['url'] = (string)$body['url'];
            if (isset($body['method'])) $target['method'] = in_array(strtoupper((string)$body['method']), array('POST', 'PUT'), true) ? strtoupper((string)$body['method']) : 'POST';
            if (isset($body['headers']) && is_array($body['headers'])) {
                if (count($body['headers']) > 0) {
                    $target['headers_enc'] = gojs_seal_secret(json_encode($body['headers'], JSON_UNESCAPED_UNICODE));
                } elseif (isset($target['headers_enc'])) {
                    unset($target['headers_enc']);
                }
            }
        }
        $channels[$idx] = $target;
        gojs_save_channels($channels);
        gojs_log_operation('notify_channel_update', $id, true);
        gojs_json_response(gojs_channel_redact($target));
    } elseif ($method === 'DELETE') {
        $channels = array_values(array_filter($channels, function ($c) use ($id) {
            return !(isset($c['id']) && $c['id'] === $id);
        }));
        gojs_save_channels($channels);
        gojs_log_operation('notify_channel_delete', $id, true);
        gojs_json_response(array('success' => true));
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}

function gojs_api_notification_channel_test($id) {
    $channels = gojs_load_channels();
    $target = null;
    foreach ($channels as $ch) {
        if (isset($ch['id']) && $ch['id'] === $id) {
            $target = $ch;
            break;
        }
    }
    if ($target === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => 'Channel 不存在'), 404);
    }
    $type = isset($target['type']) ? $target['type'] : '';
    $payload = array(
        'subject' => '[Go.js] 通道测试',
        'body' => "这是 Go.js Panel 于 " . date('Y-m-d H:i:s') . " 发送的通道测试消息。如果你收到此消息，说明该通知通道配置正确。",
        'test' => true,
        'sent_at' => time(),
    );
    $result = null;
    if ($type === 'email') {
        $result = gojs_channel_mail_send($target, $payload);
    } elseif ($type === 'smtp') {
        $result = gojs_channel_smtp_send($target, $payload);
    } elseif ($type === 'webhook') {
        $webhook_payload = array(
            'event' => 'channel.test',
            'channel_id' => $id,
            'channel_name' => isset($target['name']) ? $target['name'] : '',
            'sent_at' => time(),
            'data' => $payload,
        );
        $result = gojs_channel_webhook_send($target, $webhook_payload);
    } else {
        $result = array('ok' => false, 'error' => 'unknown channel type');
    }
    gojs_json_response($result);
}

function gojs_api_notifications($method) {
    if ($method !== 'GET') {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
    $category = gojs_get_param('category', '');
    $read_filter = gojs_get_param('read', '');
    $unread_only = gojs_get_param('unread_only', '');
    $limit = (int)gojs_get_param('limit', 50);
    $offset = (int)gojs_get_param('offset', 0);
    if ($limit < 1) $limit = 50;
    if ($limit > 500) $limit = 500;
    if ($offset < 0) $offset = 0;

    $items = gojs_load_notifications();
    $items = array_reverse($items);

    $filtered = array();
    $unread_count = 0;
    foreach ($items as $it) {
        $has_read = !empty($it['read_at']);
        if (!$has_read) $unread_count++;
        if ($category !== '' && $category !== 'all') {
            $cat = isset($it['category']) ? $it['category'] : '';
            if ($cat !== $category) continue;
        }
        if (($unread_only === '1' || $unread_only === 'true') && $has_read) continue;
        if ($read_filter === 'read' && !$has_read) continue;
        if ($read_filter === 'unread' && $has_read) continue;
        $filtered[] = $it;
    }
    $total = count($filtered);
    $page = array_slice($filtered, $offset, $limit);
    gojs_json_response(array(
        'items' => $page,
        'total' => $total,
        'unread_count' => $unread_count,
    ));
}

function gojs_api_notifications_summary() {
    $items = gojs_load_notifications();
    $total = count($items);
    $unread = 0;
    $unread_items = array();
    foreach (array_reverse($items) as $it) {
        if (empty($it['read_at'])) {
            $unread++;
            if (count($unread_items) < 5) {
                $unread_items[] = $it;
            }
        }
    }
    gojs_json_response(array(
        'total' => $total,
        'unread' => $unread,
        'latest_5' => $unread_items,
    ));
}

function gojs_api_notification_mark_read($id) {
    $items = gojs_load_notifications();
    $found = false;
    foreach ($items as $idx => $it) {
        if (isset($it['id']) && $it['id'] === $id) {
            $items[$idx]['read_at'] = time();
            $found = true;
            break;
        }
    }
    if (!$found) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '通知不存在'), 404);
    }
    gojs_save_notifications($items);
    gojs_json_response(array('success' => true));
}

function gojs_api_notifications_read_all() {
    $items = gojs_load_notifications();
    $now = time();
    foreach ($items as $idx => $it) {
        if (empty($it['read_at'])) {
            $items[$idx]['read_at'] = $now;
        }
    }
    gojs_save_notifications($items);
    gojs_json_response(array('success' => true));
}

function gojs_api_notification_delete($id) {
    $items = gojs_load_notifications();
    $before = count($items);
    $items = array_values(array_filter($items, function ($it) use ($id) {
        return !(isset($it['id']) && $it['id'] === $id);
    }));
    if (count($items) === $before) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '通知不存在'), 404);
    }
    gojs_save_notifications($items);
    gojs_json_response(array('success' => true));
}

function gojs_api_notifications_clear_read() {
    $items = gojs_load_notifications();
    $items = array_values(array_filter($items, function ($it) {
        return empty($it['read_at']);
    }));
    gojs_save_notifications($items);
    gojs_json_response(array('success' => true));
}

function gojs_api_internal_drain_outbox() {
    global $config;
    $provided_token = gojs_get_param('internal_cron_token', '');
    if ($provided_token === '') {
        $headers = function_exists('getallheaders') ? getallheaders() : array();
        if (!$headers) $headers = array();
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'x-internal-cron-token') {
                $provided_token = $v;
                break;
            }
        }
    }
    $valid_token = isset($config['internal_cron_token']) ? $config['internal_cron_token'] : '';
    $admin_allowed = !empty($_SESSION['authenticated']);
    if (!$admin_allowed && ($provided_token === '' || $valid_token === '' || !hash_equals($valid_token, $provided_token))) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '需要 internal_cron_token 或管理员登录',
        ), 403);
    }
    $stats = gojs_channels_deliver_all();
    gojs_json_response($stats);
}
