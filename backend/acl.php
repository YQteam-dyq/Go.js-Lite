<?php

function gojs_role_rank($role) {
    static $rank = array('viewer' => 1, 'operator' => 2, 'admin' => 3);
    return isset($rank[$role]) ? $rank[$role] : 0;
}

function gojs_acl_fail($code, $message) {
    if (!headers_sent()) {
        http_response_code(403);
    }
    gojs_json_response(null, array(
        'code' => $code,
        'message' => $message,
    ), 403);
    exit;
}

function gojs_require_role($minRole) {
    $currentRank = function_exists('gojs_current_role_rank') ? gojs_current_role_rank() : 0;
    $needRank    = gojs_role_rank($minRole);
    if ($currentRank < $needRank) {
        gojs_acl_fail('insufficient_role', '当前角色无权访问该接口');
    }
}

function gojs_require_admin() {
    gojs_require_role('admin');
}

function gojs_user_permissions_boost() {
    $u = function_exists('gojs_current_user') ? gojs_current_user() : null;
    if (!$u || empty($u['permissions_boost']) || !is_array($u['permissions_boost'])) {
        return array();
    }
    return array_values(array_filter($u['permissions_boost'], 'is_string'));
}

function gojs_action_allowed($action) {
    return in_array($action, gojs_user_permissions_boost(), true);
}

function gojs_acl_action_name($api) {
    static $map = array(
        'files'        => 'files.list',
        'upload'       => 'files.upload',
        'upload-chunk' => 'files.upload',
        'file-save'    => 'file.save',
        'file-delete'  => 'file.delete',
        'file-rename'  => 'file.rename',
        'file-mkdir'   => 'file.mkdir',
        'file-copy'    => 'file.copy',
        'file-chmod'   => 'file.chmod',
        'db/sql'       => 'db.execute_select',
    );
    return isset($map[$api]) ? $map[$api] : null;
}

function gojs_require_path_access($abs_path) {
    $u = function_exists('gojs_current_user') ? gojs_current_user() : null;
    if (!$u) {
        return;
    }
    $role = isset($u['role']) ? $u['role'] : 'admin';
    if (gojs_role_rank($role) >= gojs_role_rank('operator')) {
        return;
    }

    $allowed = function_exists('gojs_groups_user_paths')
        ? gojs_groups_user_paths($u)
        : (isset($u['path_allowlist']) && is_array($u['path_allowlist']) ? array_values($u['path_allowlist']) : array());

    if (empty($allowed)) {
        gojs_acl_fail('path_not_allowed', '当前账户没有任何可访问路径');
    }

    $normalized = str_replace('\\', '/', (string)$abs_path);
    foreach ($allowed as $prefix) {
        $p = str_replace('\\', '/', $prefix);
        if ($p !== '' && strpos($normalized, rtrim($p, '/') . '/') === 0) {
            return;
        }
        if ($p !== '' && $normalized === rtrim($p, '/')) {
            return;
        }
    }
    gojs_acl_fail('path_not_allowed', '当前账户无权访问该路径');
}
