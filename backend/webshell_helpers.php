<?php

function gojs_webshell_validate_command_args(string $base_command, array $args): array {
    $allowed_commands = array(
        'ls' => array(
            'args' => array('max_args' => 2, 'allowed_flags' => array('-l', '-a', '-h', '-R')),
            'description' => 'List directory contents'
        ),
        'll' => array(
            'args' => array('max_args' => 1, 'allowed_flags' => array()),
            'description' => 'List directory contents (alias for ls -la)'
        ),
        'dir' => array(
            'args' => array('max_args' => 2, 'allowed_flags' => array('/A', '/B', '/C', '/D', '/N', '/O', '/P', '/Q', '/S', '/T', '/W', '/X')),
            'description' => 'List directory contents (Windows)'
        ),
        'pwd' => array(
            'args' => array('max_args' => 0, 'allowed_flags' => array()),
            'description' => 'Print working directory'
        ),
        'cd' => array(
            'args' => array('max_args' => 1, 'allowed_flags' => array()),
            'description' => 'Change directory'
        ),
        'mkdir' => array(
            'args' => array('max_args' => 10, 'allowed_flags' => array('-p', '-v', '-m')),
            'description' => 'Create directories'
        ),
        'rmdir' => array(
            'args' => array('max_args' => 10, 'allowed_flags' => array('-p', '-v')),
            'description' => 'Remove empty directories'
        ),
        'rm' => array(
            'args' => array('max_args' => 10, 'allowed_flags' => array('-r', '-f', '-v', '-i')),
            'description' => 'Remove files or directories'
        ),
        'cp' => array(
            'args' => array('max_args' => 10, 'allowed_flags' => array('-r', '-f', '-v', '-i', '-p')),
            'description' => 'Copy files or directories'
        ),
        'mv' => array(
            'args' => array('max_args' => 10, 'allowed_flags' => array('-f', '-v', '-i')),
            'description' => 'Move or rename files'
        ),
        'touch' => array(
            'args' => array('max_args' => 10, 'allowed_flags' => array('-a', '-c', '-m', '-r')),
            'description' => 'Change file timestamps'
        ),
        'cat' => array(
            'args' => array('max_args' => 10, 'allowed_flags' => array('-n', '-b', '-s', '-A', '-E', '-T')),
            'description' => 'Concatenate and display files'
        ),
        'head' => array(
            'args' => array('max_args' => 2, 'allowed_flags' => array('-n', '-c', '-v')),
            'description' => 'Display first lines of files'
        ),
        'tail' => array(
            'args' => array('max_args' => 2, 'allowed_flags' => array('-n', '-c', '-f', '-v')),
            'description' => 'Display last lines of files'
        ),
        'grep' => array(
            'args' => array('max_args' => 10, 'allowed_flags' => array('-i', '-r', '-n', '-v', '-c', '-l', '-w', '-A', '-B')),
            'description' => 'Search for patterns'
        ),
        'find' => array(
            'args' => array('max_args' => 10, 'allowed_flags' => array('-name', '-type', '-size', '-mtime', '-maxdepth', '-mindepth', '-user', '-group')),
            'description' => 'Search for files'
        ),
        'ps' => array(
            'args' => array('max_args' => 5, 'allowed_flags' => array('-a', '-u', '-x', '-e', '-f', '-l', '-o')),
            'description' => 'Display running processes'
        ),
        'top' => array(
            'args' => array('max_args' => 3, 'allowed_flags' => array('-d', '-p', '-n')),
            'description' => 'Display processes'
        ),
        'htop' => array(
            'args' => array('max_args' => 3, 'allowed_flags' => array('-d', '-p', '-n')),
            'description' => 'Display processes (htop)'
        ),
        'df' => array(
            'args' => array('max_args' => 5, 'allowed_flags' => array('-h', '-T', '-a', '-i', '-x')),
            'description' => 'Display disk space usage'
        ),
        'du' => array(
            'args' => array('max_args' => 5, 'allowed_flags' => array('-h', '-s', '-a', '-c', '-x')),
            'description' => 'Estimate file space usage'
        ),
        'free' => array(
            'args' => array('max_args' => 3, 'allowed_flags' => array('-h', '-m', '-g', '-k')),
            'description' => 'Display memory usage'
        ),
        'uname' => array(
            'args' => array('max_args' => 3, 'allowed_flags' => array('-a', '-s', '-r', '-v', '-n', '-m', '-p', '-i')),
            'description' => 'Display system information'
        ),
        'whoami' => array(
            'args' => array('max_args' => 0, 'allowed_flags' => array()),
            'description' => 'Display current user'
        ),
        'id' => array(
            'args' => array('max_args' => 2, 'allowed_flags' => array('-u', '-g', '-n', '-un', '-gn')),
            'description' => 'Display user identity'
        ),
        'date' => array(
            'args' => array('max_args' => 2, 'allowed_flags' => array('-R', '-u', '+')),
            'description' => 'Display or set system date'
        ),
        'cal' => array(
            'args' => array('max_args' => 3, 'allowed_flags' => array('-j', '-y', '-m', '-3')),
            'description' => 'Display calendar'
        ),
        'wc' => array(
            'args' => array('max_args' => 5, 'allowed_flags' => array('-l', '-w', '-c', '-m', '-L')),
            'description' => 'Count lines, words, and characters'
        ),
        'sort' => array(
            'args' => array('max_args' => 5, 'allowed_flags' => array('-n', '-r', '-f', '-u', '-k')),
            'description' => 'Sort lines of text'
        ),
        'uniq' => array(
            'args' => array('max_args' => 5, 'allowed_flags' => array('-i', '-u', '-c', '-d', '-s', '-w')),
            'description' => 'Remove duplicate lines'
        ),
        'chmod' => array(
            'args' => array('max_args' => 5, 'allowed_flags' => array('-R', '-v', '-c', '-f')),
            'description' => 'Change file permissions'
        ),
        'chown' => array(
            'args' => array('max_args' => 5, 'allowed_flags' => array('-R', '-v', '-c', '-f')),
            'description' => 'Change file ownership'
        ),
        'tar' => array(
            'args' => array('max_args' => 10, 'allowed_flags' => array('c', 'x', 't', 'r', 'u', 'f', 'v', 'z', 'j', 'C')),
            'description' => 'Archive utility'
        ),
        'zip' => array(
            'args' => array('max_args' => 10, 'allowed_flags' => array('-r', '-f', '-u', '-v', '-m', '-q')),
            'description' => 'Compress files'
        ),
        'unzip' => array(
            'args' => array('max_args' => 5, 'allowed_flags' => array('-l', '-t', '-u', '-v', '-x')),
            'description' => 'Extract zip files'
        ),
        'gzip' => array(
            'args' => array('max_args' => 5, 'allowed_flags' => array('-c', '-d', '-f', '-h', '-k', '-l', '-n', '-q', '-r', '-v')),
            'description' => 'Compress files'
        ),
        'gunzip' => array(
            'args' => array('max_args' => 5, 'allowed_flags' => array('-c', '-d', '-f', '-h', '-k', '-l', '-n', '-q', '-r', '-v')),
            'description' => 'Decompress files'
        )
    );

    if (!isset($allowed_commands[$base_command])) {
        return array('valid' => false, 'error' => 'Command not allowed: ' . $base_command);
    }

    $cmd_config = $allowed_commands[$base_command];
    $max_args = $cmd_config['args']['max_args'];
    $allowed_flags = $cmd_config['args']['allowed_flags'];

    if (count($args) > $max_args) {
        return array('valid' => false, 'error' => 'Too many arguments for ' . $base_command . '. Maximum: ' . $max_args);
    }

    foreach ($args as $arg) {
        if (strpos($arg, '-') === 0) {
            if (!in_array($arg, $allowed_flags)) {
                return array('valid' => false, 'error' => 'Flag not allowed: ' . $arg);
            }
        } else {
            if (preg_match('/[;&|`$(){}[\]<>]/', $arg)) {
                return array('valid' => false, 'error' => 'Invalid characters in path: ' . $arg);
            }
        }
    }

    return array('valid' => true, 'command' => $base_command, 'args' => $args);
}

function gojs_webshell_execute_safe_command(string $base_command, array $args): array {
    $validation = gojs_webshell_validate_command_args($base_command, $args);
    
    if (!$validation['valid']) {
        return array('output' => 'Error: ' . $validation['error'], 'success' => false);
    }

    $command_parts = array_merge(array($base_command), $args);
    $safe_command = implode(' ', array_map('escapeshellarg', $command_parts));
    
    $output = array();
    $return_var = 0;
    
    exec($safe_command . ' 2>&1', $output, $return_var);
    
    return array(
        'output' => implode("\n", $output),
        'success' => $return_var === 0
    );
}

?>