<?php

function gojs_approvals_path() {
    return CONFIG_DIR . '/approvals.json';
}

function gojs_approvals_ttl() {
    return 3600;
}

function gojs_approvals_load() {
    $path = gojs_approvals_path();
    if (!file_exists($path)) {
        return array('approvals' => array());
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return array('approvals' => array());
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array('approvals' => array());
    }
    if (!isset($decoded['approvals']) || !is_array($decoded['approvals'])) {
        $decoded['approvals'] = array();
    }
    return $decoded;
}

function gojs_approvals_save($store) {
    $path = gojs_approvals_path();
    if (!is_dir(CONFIG_DIR)) {
        @mkdir(CONFIG_DIR, 0700, true);
    }
    $json = json_encode($store, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (@file_put_contents($path, $json, LOCK_EX) === false) {
        return false;
    }
    @chmod($path, 0600);
    return true;
}

function gojs_approvals_policy() {
    return array(
        'database.delete' => 'db/import',
        'monitoring.disable' => 'monitor/disable',
        'sessions.kick_all' => 'logout-all',
        'trash.purge_all' => 'trash/purge',
        'appstore.uninstall' => 'appstore/uninstall',
    );
}

function gojs_approvals_action_for_api($api) {
    static $reverse = null;
    if ($reverse === null) {
        $reverse = array();
        foreach (gojs_approvals_policy() as $action => $apiName) {
            $reverse[$apiName] = $action;
        }
    }
    return isset($reverse[$api]) ? $reverse[$api] : null;
}

function gojs_approvals_effective_status($row, $now = null) {
    if (!is_array($row)) return 'expired';
    $now = $now === null ? time() : $now;
    $status = isset($row['status']) ? $row['status'] : 'pending';
    if ($status !== 'pending') return $status;
    if (!empty($row['expires_at']) && (int)$row['expires_at'] < $now) return 'expired';
    return 'pending';
}

function gojs_approvals_prune(&$store) {
    $now = time();
    $changed = false;
    foreach ($store['approvals'] as $i => $row) {
        if (gojs_approvals_effective_status($row, $now) === 'expired'
            && (isset($row['status']) ? $row['status'] : '') === 'pending') {
            $store['approvals'][$i]['status'] = 'expired';
            $store['approvals'][$i]['decided_at'] = $now;
            $store['approvals'][$i]['reason'] = 'timeout';
            $changed = true;
        }
    }
    if ($changed) {
        gojs_approvals_save($store);
    }
    return $store;
}

function gojs_approvals_find($id) {
    $store = gojs_approvals_load();
    foreach ($store['approvals'] as $row) {
        if (isset($row['id']) && $row['id'] === $id) {
            return $row;
        }
    }
    return null;
}

function gojs_approvals_admin_ids() {
    if (!function_exists('gojs_users_load')) return array();
    $store = gojs_users_load();
    $ids = array();
    foreach ($store['users'] as $u) {
        if (!isset($u['role']) || $u['role'] !== 'admin') continue;
        if (!empty($u['disabled'])) continue;
        $ids[] = $u['id'];
    }
    return $ids;
}

function gojs_approvals_admin_count() {
    return count(gojs_approvals_admin_ids());
}

function gojs_approvals_pending_for_decider($decider_user_id) {
    $store = gojs_approvals_load();
    gojs_approvals_prune($store);
    $out = array();
    foreach ($store['approvals'] as $row) {
        if (gojs_approvals_effective_status($row) !== 'pending') continue;
        if (isset($row['requester']) && $row['requester'] === $decider_user_id) continue;
        $out[] = $row;
    }
    return $out;
}

function gojs_approvals_mine($requester_user_id) {
    $store = gojs_approvals_load();
    gojs_approvals_prune($store);
    $out = array();
    foreach ($store['approvals'] as $row) {
        if (isset($row['requester']) && $row['requester'] === $requester_user_id) {
            $out[] = $row;
        }
    }
    return $out;
}

function gojs_approvals_all() {
    $store = gojs_approvals_load();
    gojs_approvals_prune($store);
    return $store['approvals'];
}

function gojs_approval_require($action, $api, $method, $payload, $requester) {
    if (gojs_approvals_admin_count() < 2) {
        return array('ok' => false, 'code' => 'single_admin_no_second_factor');
    }

    $store = gojs_approvals_load();
    gojs_approvals_prune($store);
    foreach ($store['approvals'] as $row) {
        if (gojs_approvals_effective_status($row) !== 'pending') continue;
        if (isset($row['requester']) && $row['requester'] === $requester
            && isset($row['api']) && $row['api'] === $api) {
            return array('ok' => false, 'code' => 'approval_already_pending');
        }
    }

    $now = time();
    $approval = array(
        'id' => 'ap_' . bin2hex(random_bytes(6)),
        'action' => $action,
        'api' => $api,
        'method' => strtoupper($method),
        'payload' => is_array($payload) ? $payload : array(),
        'requester' => $requester,
        'expires_at' => $now + gojs_approvals_ttl(),
        'status' => 'pending',
        'second_factor_user_id' => null,
        'decided_at' => 0,
        'reason' => '',
        'created_at' => $now,
    );
    $store['approvals'][] = $approval;
    if (!gojs_approvals_save($store)) {
        return array('ok' => false, 'code' => 'write_failed');
    }
    return array('ok' => true, 'approval' => $approval);
}

function gojs_approvals_gate($api, $method) {
    if (!empty($GLOBALS['gojs_approval_bypass'])) return;

    if ($api === 'trash/purge') {
        $body = gojs_get_body();
        if (!empty($body['id'])) return;
    }

    $action = gojs_approvals_action_for_api($api);
    if ($action === null) return;

    $role = function_exists('gojs_current_role') ? gojs_current_role() : null;
    if ($role !== 'admin') return;

    $requester = function_exists('gojs_current_user_id') ? gojs_current_user_id() : null;
    if (!$requester) return;

    if (gojs_approvals_admin_count() < 2) {
        gojs_json_response(null, array(
            'code' => 'single_admin_no_second_factor',
            'message' => '需要第二位 admin 才能执行该操作',
        ), 409);
    }

    $result = gojs_approval_require($action, $api, $method, gojs_get_body(), $requester);
    if (empty($result['ok'])) {
        $status = $result['code'] === 'approval_already_pending' ? 409 : 500;
        gojs_json_response(null, array('code' => $result['code'], 'message' => '审批创建失败'), $status);
    }
    gojs_log_operation('approval.request', $result['approval']['id'], true, $action);
    gojs_json_response(array(
        'status' => 'approval_pending',
        'approval' => $result['approval'],
    ), null, 202);
}

function gojs_approvals_execute($approval) {
    $api = isset($approval['api']) ? $approval['api'] : '';
    $method = isset($approval['method']) ? $approval['method'] : 'POST';
    $payload = isset($approval['payload']) && is_array($approval['payload']) ? $approval['payload'] : array();
    if ($api === '') {
        return array('ok' => false, 'code' => 'invalid_approval');
    }

    $GLOBALS['gojs_approval_bypass'] = true;
    $GLOBALS['gojs_body_override'] = $payload;
    $GLOBALS['gojs_defer_response'] = true;
    $GLOBALS['gojs_deferred_response'] = null;

    if (ob_get_level() > 0) {
        @ob_clean();
    }
    ob_start();
    try {
        $router = gojs_build_router();
        $router->dispatch($api, $method);
    } catch (GoJSApiResponseSent $e) {
    } catch (\Throwable $e) {
        $GLOBALS['gojs_deferred_response'] = array(
            'data' => null,
            'error' => array('code' => 'execution_failed', 'message' => $e->getMessage()),
            'status' => 500,
        );
    }
    if (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $GLOBALS['gojs_defer_response'] = false;
    $GLOBALS['gojs_approval_bypass'] = false;
    $GLOBALS['gojs_body_override'] = null;

    $captured = $GLOBALS['gojs_deferred_response'];
    $GLOBALS['gojs_deferred_response'] = null;
    if (!is_array($captured)) {
        return array('ok' => true, 'result' => null);
    }
    return array('ok' => true, 'result' => $captured);
}

function gojs_approval_decide($id, $decision, $reason, $decider) {
    $decision = strtolower((string)$decision);
    if (!in_array($decision, array('approve', 'deny'), true)) {
        return array('ok' => false, 'code' => 'invalid_decision', 'status' => 400);
    }
    $store = gojs_approvals_load();
    gojs_approvals_prune($store);
    foreach ($store['approvals'] as $i => $row) {
        if (!isset($row['id']) || $row['id'] !== $id) continue;

        $status = gojs_approvals_effective_status($row);
        if ($status === 'expired') {
            return array('ok' => false, 'code' => 'approval_expired', 'status' => 410);
        }
        if ($status !== 'pending') {
            return array('ok' => false, 'code' => 'approval_already_decided', 'status' => 409);
        }
        if (isset($row['requester']) && $row['requester'] === $decider) {
            return array('ok' => false, 'code' => 'cannot_self_approve', 'status' => 409);
        }

        $now = time();
        $store['approvals'][$i]['status'] = $decision === 'approve' ? 'approved' : 'denied';
        $store['approvals'][$i]['second_factor_user_id'] = $decider;
        $store['approvals'][$i]['decided_at'] = $now;
        $store['approvals'][$i]['reason'] = (string)$reason;
        if (!gojs_approvals_save($store)) {
            return array('ok' => false, 'code' => 'write_failed', 'status' => 500);
        }

        $updated = $store['approvals'][$i];
        if (function_exists('gojs_log_operation')) {
            gojs_log_operation('approval.' . $decision, $id, true, $row['action']);
        }

        if ($decision === 'deny') {
            return array('ok' => true, 'approval' => $updated, 'result' => null);
        }
        $exec = gojs_approvals_execute($updated);
        return array('ok' => true, 'approval' => $updated, 'result' => isset($exec['result']) ? $exec['result'] : null);
    }
    return array('ok' => false, 'code' => 'not_found', 'status' => 404);
}

function gojs_api_approvals_list() {
    $uid = function_exists('gojs_current_user_id') ? gojs_current_user_id() : null;
    $pending = gojs_approvals_pending_for_decider($uid);
    $mine = gojs_approvals_mine($uid);
    gojs_json_response(array(
        'pending' => $pending,
        'mine' => $mine,
        'total' => count($pending) + count($mine),
        'pending_total' => count($pending),
        'admin_count' => gojs_approvals_admin_count(),
        'ttl_seconds' => gojs_approvals_ttl(),
        'policy' => array_keys(gojs_approvals_policy()),
    ));
}

function gojs_api_approvals_decide($id, $decision) {
    $uid = function_exists('gojs_current_user_id') ? gojs_current_user_id() : null;
    if (!$uid) {
        gojs_json_response(null, array('code' => 'unauthorized', 'message' => '请先登录'), 401);
    }
    $body = gojs_get_body();
    $reason = isset($body['reason']) ? (string)$body['reason'] : '';
    $result = gojs_approval_decide($id, $decision, $reason, $uid);
    if (empty($result['ok'])) {
        $status = isset($result['status']) ? (int)$result['status'] : 400;
        gojs_json_response(null, array('code' => $result['code'], 'message' => '审批失败'), $status);
    }
    gojs_json_response(array(
        'status' => $result['approval']['status'],
        'approval' => $result['approval'],
        'result' => $result['result'],
    ));
}

function gojs_api_approvals_route($api, $method) {
    if (preg_match('#^approvals/([A-Za-z0-9_]+)/(approve|deny)$#', $api, $m)) {
        if ($method !== 'POST') {
            gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
        }
        gojs_api_approvals_decide($m[1], $m[2]);
        return;
    }
    if ($api === 'approvals') {
        if ($method === 'GET') {
            gojs_api_approvals_list();
        } else {
            gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
        }
        return;
    }
    gojs_json_response(null, array('code' => 'not_found', 'message' => 'API 不存在'), 404);
}
