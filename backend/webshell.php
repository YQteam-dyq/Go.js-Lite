<?php

function gojs_webshell_history_path(): string {
    return CONFIG_DIR . '/webshell_history.json';
}

function gojs_webshell_history_load(): array {
    $items = gojs_read_json_lock_safe(gojs_webshell_history_path(), array());
    $cap = 100;
    if (count($items) > $cap) {
        $items = array_slice($items, -$cap);
        gojs_write_json_lock_safe(gojs_webshell_history_path(), $items, true);
    }
    return $items;
}

function gojs_webshell_history_save(array $items): array {
    $cap = 100;
    if (count($items) > $cap) {
        $items = array_slice($items, -$cap);
    }
    $result = gojs_write_json_lock_safe(gojs_webshell_history_path(), $items, true);
    if (!$result['success']) {
        return array('success' => false, 'error' => $result['error']);
    }
    return array('success' => true);
}

function gojs_webshell_history_append(string $command, string $output, bool $success = true): string {
    $lock_file = sys_get_temp_dir() . '/gojs_webshell.lock';
    $lock_handle = @fopen($lock_file, 'w+');

    $lock_acquired = false;
    for ($i = 0; $i < 20; $i++) {
        if (@flock($lock_handle, LOCK_EX | LOCK_NB)) {
            $lock_acquired = true;
            break;
        }
        usleep(100000);
    }

    if (!$lock_acquired) {
        $id = uniqid('cmd_', true);
        $item = array(
            'id' => $id,
            'command' => $command,
            'output' => $output,
            'success' => $success,
            'timestamp' => time(),
        );

        $result = gojs_write_json_lock_safe(gojs_webshell_history_path(), array($item), true);
        if (!$result['success']) {
            $id = 'error';
        }
        return $id;
    }

    try {
        $items = gojs_webshell_history_load();
        $id = uniqid('cmd_', true);
        $item = array(
            'id' => $id,
            'command' => $command,
            'output' => $output,
            'success' => $success,
            'timestamp' => time(),
        );
        $items[] = $item;
        gojs_webshell_history_save($items);
        return $id;
    } finally {
        @flock($lock_handle, LOCK_UN);
        @fclose($lock_handle);
        @unlink($lock_file);
    }
}

function gojs_webshell_execute_command(string $command): array {
    if (empty($command)) {
        return array('output' => '', 'success' => false);
    }

    $command_parts = explode(' ', trim($command));
    $base_command = $command_parts[0];

    return gojs_webshell_execute_safe_command($base_command, array_slice($command_parts, 1));
}

function gojs_api_webshell_history() {
    $history = gojs_webshell_history_load();
    gojs_json_response($history);
}

function gojs_api_webshell_execute() {
    $command = gojs_get_param('command', '');
    if (empty($command)) {
        gojs_json_response(null, array('code' => 'missing_command', 'message' => 'Command is required'), 400);
        return;
    }

    $result = gojs_webshell_execute_command($command);
    $history_id = gojs_webshell_history_append($command, $result['output'], $result['success']);

    gojs_json_response(array(
        'output' => $result['output'],
        'success' => $result['success'],
        'history_id' => $history_id,
    ));
}

function gojs_api_webshell_clear_history() {
    $result = gojs_write_json_lock_safe(gojs_webshell_history_path(), array(), true);
    if (!$result['success']) {
        gojs_json_response(array('success' => false, 'error' => $result['error']));
        return;
    }
    gojs_json_response(array('success' => true, 'message' => 'History cleared'));
}

function gojs_api_webshell_autocomplete() {
    $input = gojs_get_param('input', '');
    $allowed_commands = array(
        'ls', 'll', 'dir', 'pwd', 'cd', 'mkdir', 'rmdir', 'rm', 'cp', 'mv', 'touch', 'cat', 'head', 'tail', 'grep', 'find', 'ps', 'top', 'htop', 'df', 'du', 'free', 'uname', 'whoami', 'id', 'date', 'cal', 'wc', 'sort', 'uniq', 'chmod', 'chown', 'tar', 'zip', 'unzip', 'gzip', 'gunzip'
    );

    $suggestions = array();
    foreach ($allowed_commands as $cmd) {
        if (strpos($cmd, $input) === 0) {
            $suggestions[] = $cmd;
        }
    }

    gojs_json_response($suggestions);
}
