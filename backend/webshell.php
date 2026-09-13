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

function gojs_webshell_history_save(array $items): void {
    $cap = 100;
    if (count($items) > $cap) {
        $items = array_slice($items, -$cap);
    }
    gojs_write_json_lock_safe(gojs_webshell_history_path(), $items, true);
}

function gojs_webshell_history_append(string $command, string $output, bool $success = true): string {
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
}

function gojs_webshell_execute_command(string $command): array {
    $output = '';
    $success = false;
    
    if (empty($command)) {
        return array('output' => '', 'success' => false);
    }
    
    $allowed_commands = array(
        'ls', 'll', 'dir', 'pwd', 'cd', 'mkdir', 'rmdir', 'rm', 'cp', 'mv', 'touch', 'cat', 'head', 'tail', 'grep', 'find', 'ps', 'top', 'htop', 'df', 'du', 'free', 'uname', 'whoami', 'id', 'date', 'cal', 'wc', 'sort', 'uniq', 'awk', 'sed', 'chmod', 'chown', 'tar', 'zip', 'unzip', 'gzip', 'gunzip'
    );
    
    $command_parts = explode(' ', trim($command));
    $base_command = $command_parts[0];
    
    if (!in_array($base_command, $allowed_commands, true)) {
        return array('output' => 'Error: Command not allowed: ' . $base_command, 'success' => false);
    }
    
    $temp_file = tempnam(sys_get_temp_dir(), 'gojs_webshell_');
    if ($temp_file === false) {
        return array('output' => 'Error: Cannot create temporary file', 'success' => false);
    }
    
    $safe_command = escapeshellcmd($command);
    $result = array();
    $return_var = 0;
    
    exec($safe_command . ' 2>&1', $result, $return_var);
    
    if ($return_var === 0) {
        $success = true;
    }
    
    $output = implode("\n", $result);
    
    if (file_exists($temp_file)) {
        @unlink($temp_file);
    }
    
    return array('output' => $output, 'success' => $success);
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
    gojs_write_json_lock_safe(gojs_webshell_history_path(), array(), true);
    gojs_json_response(array('message' => 'History cleared'));
}

function gojs_api_webshell_autocomplete() {
    $input = gojs_get_param('input', '');
    $allowed_commands = array(
        'ls', 'll', 'dir', 'pwd', 'cd', 'mkdir', 'rmdir', 'rm', 'cp', 'mv', 'touch', 'cat', 'head', 'tail', 'grep', 'find', 'ps', 'top', 'htop', 'df', 'du', 'free', 'uname', 'whoami', 'id', 'date', 'cal', 'wc', 'sort', 'uniq', 'awk', 'sed', 'chmod', 'chown', 'tar', 'zip', 'unzip', 'gzip', 'gunzip'
    );
    
    $suggestions = array();
    foreach ($allowed_commands as $cmd) {
        if (strpos($cmd, $input) === 0) {
            $suggestions[] = $cmd;
        }
    }
    
    gojs_json_response($suggestions);
}