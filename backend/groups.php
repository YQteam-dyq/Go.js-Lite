<?php

function gojs_groups_path() {
    return CONFIG_DIR . '/groups.json';
}

function gojs_groups_load() {
    $path = gojs_groups_path();
    if (!file_exists($path)) {
        return array('groups' => array());
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return array('groups' => array());
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return array('groups' => array());
    }
    if (!isset($decoded['groups']) || !is_array($decoded['groups'])) {
        $decoded['groups'] = array();
    }
    return $decoded;
}

function gojs_groups_save($store) {
    $path = gojs_groups_path();
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

function gojs_groups_find($id) {
    $store = gojs_groups_load();
    foreach ($store['groups'] as $g) {
        if (isset($g['id']) && $g['id'] === $id) {
            return $g;
        }
    }
    return null;
}

function gojs_groups_normalize_paths($paths) {
    if (!is_array($paths)) {
        return array();
    }
    $out = array();
    foreach ($paths as $p) {
        if (!is_string($p)) continue;
        $p = trim($p);
        if ($p === '') continue;
        $out[] = $p;
    }
    return array_values(array_unique($out));
}

function gojs_groups_normalize_members($ids) {
    if (!is_array($ids)) {
        return array();
    }
    $out = array();
    foreach ($ids as $i) {
        if (!is_string($i) || $i === '') continue;
        $out[] = $i;
    }
    return array_values(array_unique($out));
}

function gojs_groups_create($name, $path_allowlist = array(), $member_ids = array()) {
    $name = trim((string)$name);
    if ($name === '') {
        return array('ok' => false, 'code' => 'invalid_name');
    }
    $store = gojs_groups_load();
    foreach ($store['groups'] as $g) {
        if (isset($g['name']) && strcasecmp($g['name'], $name) === 0) {
            return array('ok' => false, 'code' => 'name_exists');
        }
    }
    $group = array(
        'id' => 'g_' . bin2hex(random_bytes(6)),
        'name' => $name,
        'path_allowlist' => gojs_groups_normalize_paths($path_allowlist),
        'member_ids' => gojs_groups_normalize_members($member_ids),
        'created_at' => time(),
    );
    $store['groups'][] = $group;
    if (!gojs_groups_save($store)) {
        return array('ok' => false, 'code' => 'write_failed');
    }
    return array('ok' => true, 'group' => $group);
}

function gojs_groups_update($id, $patch) {
    $store = gojs_groups_load();
    foreach ($store['groups'] as $i => $g) {
        if (!isset($g['id']) || $g['id'] !== $id) continue;
        if (isset($patch['name'])) {
            $name = trim((string)$patch['name']);
            if ($name === '') {
                return array('ok' => false, 'code' => 'invalid_name');
            }
            foreach ($store['groups'] as $j => $other) {
                if ($j !== $i && isset($other['name']) && strcasecmp($other['name'], $name) === 0) {
                    return array('ok' => false, 'code' => 'name_exists');
                }
            }
            $store['groups'][$i]['name'] = $name;
        }
        if (isset($patch['path_allowlist'])) {
            $store['groups'][$i]['path_allowlist'] = gojs_groups_normalize_paths($patch['path_allowlist']);
        }
        if (isset($patch['member_ids'])) {
            $store['groups'][$i]['member_ids'] = gojs_groups_normalize_members($patch['member_ids']);
        }
        if (!gojs_groups_save($store)) {
            return array('ok' => false, 'code' => 'write_failed');
        }
        return array('ok' => true, 'group' => $store['groups'][$i]);
    }
    return array('ok' => false, 'code' => 'not_found');
}

function gojs_groups_delete($id) {
    $store = gojs_groups_load();
    $out = array();
    $found = false;
    foreach ($store['groups'] as $g) {
        if (isset($g['id']) && $g['id'] === $id) { $found = true; continue; }
        $out[] = $g;
    }
    if (!$found) {
        return array('ok' => false, 'code' => 'not_found');
    }
    $store['groups'] = $out;
    if (!gojs_groups_save($store)) {
        return array('ok' => false, 'code' => 'write_failed');
    }
    return array('ok' => true);
}

function gojs_groups_set_members($id, $add = array(), $remove = array()) {
    $store = gojs_groups_load();
    foreach ($store['groups'] as $i => $g) {
        if (!isset($g['id']) || $g['id'] !== $id) continue;
        $members = isset($g['member_ids']) && is_array($g['member_ids']) ? $g['member_ids'] : array();
        $add = gojs_groups_normalize_members($add);
        $remove = gojs_groups_normalize_members($remove);
        foreach ($add as $a) {
            if (!in_array($a, $members, true)) $members[] = $a;
        }
        if (!empty($remove)) {
            $members = array_values(array_filter($members, function ($m) use ($remove) {
                return !in_array($m, $remove, true);
            }));
        }
        $store['groups'][$i]['member_ids'] = array_values($members);
        if (!gojs_groups_save($store)) {
            return array('ok' => false, 'code' => 'write_failed');
        }
        return array('ok' => true, 'group' => $store['groups'][$i]);
    }
    return array('ok' => false, 'code' => 'not_found');
}

function gojs_groups_for_user($user_id) {
    if (!is_string($user_id) || $user_id === '') return array();
    $store = gojs_groups_load();
    $out = array();
    foreach ($store['groups'] as $g) {
        if (empty($g['member_ids']) || !is_array($g['member_ids'])) continue;
        if (in_array($user_id, $g['member_ids'], true)) {
            $out[] = $g;
        }
    }
    return $out;
}

function gojs_groups_user_paths($user) {
    $paths = array();
    if (is_array($user) && !empty($user['path_allowlist']) && is_array($user['path_allowlist'])) {
        $paths = gojs_groups_normalize_paths($user['path_allowlist']);
    }
    if (is_array($user) && !empty($user['id'])) {
        foreach (gojs_groups_for_user($user['id']) as $g) {
            if (!empty($g['path_allowlist']) && is_array($g['path_allowlist'])) {
                foreach (gojs_groups_normalize_paths($g['path_allowlist']) as $p) {
                    $paths[] = $p;
                }
            }
        }
    }
    return array_values(array_unique($paths));
}

function gojs_groups_ids_for_user($user_id) {
    $ids = array();
    foreach (gojs_groups_for_user($user_id) as $g) {
        $ids[] = $g['id'];
    }
    return $ids;
}

function gojs_api_groups_list() {
    $store = gojs_groups_load();
    gojs_json_response(array('groups' => $store['groups'], 'total' => count($store['groups'])));
}

function gojs_api_groups_create() {
    $body = gojs_get_body();
    $result = gojs_groups_create(
        isset($body['name']) ? $body['name'] : '',
        isset($body['path_allowlist']) ? $body['path_allowlist'] : array(),
        isset($body['member_ids']) ? $body['member_ids'] : array()
    );
    if (empty($result['ok'])) {
        $status = $result['code'] === 'name_exists' ? 409 : 400;
        gojs_json_response(null, array('code' => $result['code'], 'message' => '创建用户组失败'), $status);
    }
    gojs_log_operation('group.create', $result['group']['id'], true);
    gojs_json_response($result['group'], null, 201);
}

function gojs_api_groups_update($id) {
    $body = gojs_get_body();
    $result = gojs_groups_update($id, $body);
    if (empty($result['ok'])) {
        $status = $result['code'] === 'not_found' ? 404 : ($result['code'] === 'name_exists' ? 409 : 400);
        gojs_json_response(null, array('code' => $result['code'], 'message' => '更新用户组失败'), $status);
    }
    gojs_log_operation('group.update', $id, true);
    gojs_json_response($result['group']);
}

function gojs_api_groups_delete($id) {
    $result = gojs_groups_delete($id);
    if (empty($result['ok'])) {
        gojs_json_response(null, array('code' => $result['code'], 'message' => '删除用户组失败'), 404);
    }
    gojs_log_operation('group.delete', $id, true);
    gojs_json_response(array('success' => true));
}

function gojs_api_groups_members($id) {
    $body = gojs_get_body();
    $result = gojs_groups_set_members(
        $id,
        isset($body['add']) ? $body['add'] : array(),
        isset($body['remove']) ? $body['remove'] : array()
    );
    if (empty($result['ok'])) {
        gojs_json_response(null, array('code' => $result['code'], 'message' => '更新组成员失败'), 404);
    }
    gojs_log_operation('group.members', $id, true);
    gojs_json_response($result['group']);
}

function gojs_api_groups_route($api, $method) {
    if (preg_match('#^groups/([A-Za-z0-9_]+)/members$#', $api, $m)) {
        if ($method !== 'POST') {
            gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
        }
        gojs_api_groups_members($m[1]);
        return;
    }
    if (preg_match('#^groups/([A-Za-z0-9_]+)$#', $api, $m)) {
        $id = $m[1];
        if ($method === 'PATCH' || $method === 'PUT') gojs_api_groups_update($id);
        elseif ($method === 'DELETE') gojs_api_groups_delete($id);
        else gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
        return;
    }
    if ($api === 'groups') {
        if ($method === 'GET') gojs_api_groups_list();
        elseif ($method === 'POST') gojs_api_groups_create();
        else gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
        return;
    }
    gojs_json_response(null, array('code' => 'not_found', 'message' => 'API 不存在'), 404);
}
