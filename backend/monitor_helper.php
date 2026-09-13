<?php

// Monitor helper functions to handle the new gojs_write_json_lock_safe return format

function gojs_safe_write_json(string $path, array $data, bool $pretty = true): array {
    $result = gojs_write_json_lock_safe($path, $data, $pretty);
    if (!$result['success']) {
        return array('success' => false, 'error' => $result['error']);
    }
    return array('success' => true);
}

function gojs_safe_save_outbox(array $items): array {
    if (count($items) > 5000) {
        $items = array_slice($items, -5000);
    }
    return gojs_safe_write_json(gojs_outbox_path(), $items, false);
}

function gojs_safe_save_bandwidth(int $in_bytes, int $out_bytes): array {
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
    return gojs_safe_write_json($path, $data, false);
}

?>