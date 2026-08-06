<?php

// Backup: create/list/download/delete/restore.
// Split from api.php; keep original function signatures and behavior unchanged.

function gojs_backup_filename_valid($filename) {
    return is_string($filename) && preg_match('/^backup-[0-9]{8}-[0-9]{6}\.zip$/', $filename);
}

function gojs_api_backup_create() {
    $input = gojs_get_body();
    $include_files = !isset($input['include_files']) ? true : (bool)$input['include_files'];
    $include_db = !isset($input['include_db']) ? true : (bool)$input['include_db'];
    $include_config = !isset($input['include_config']) ? true : (bool)$input['include_config'];
    $exclude_dirs = isset($input['exclude_dirs']) && is_array($input['exclude_dirs'])
        ? $input['exclude_dirs']
        : array('cache', 'node_modules', '.git', '.gojs');

    $backup_dir = CONFIG_DIR . '/backups';
    if (!is_dir($backup_dir)) {
        if (!@mkdir($backup_dir, 0700, true)) {
            gojs_json_response(null, array(
                'code' => 'mkdir_failed',
                'message' => '无法创建备份目录',
            ), 500);
        }
    }

    $timestamp = date('Ymd-His');
    $backup_name = "backup-{$timestamp}";
    $backup_file = $backup_dir . "/{$backup_name}.zip";

    if (!class_exists('ZipArchive')) {
        gojs_json_response(null, array(
            'code' => 'zip_not_available',
            'message' => 'ZipArchive 扩展不可用',
        ), 500);
    }

    $zip = new ZipArchive();
    if ($zip->open($backup_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        gojs_json_response(null, array(
            'code' => 'zip_create_failed',
            'message' => '创建备份包失败',
        ), 500);
    }

    @set_time_limit(0);

    $metadata = array(
        'created_at' => date('Y-m-d H:i:s'),
        'version' => APP_VERSION,
        'files' => null,
        'databases' => array(),
        'config' => false,
    );

    $files_root = $GLOBALS['files_root'];

    // Package site files.
    if ($include_files && is_dir($files_root)) {
        $file_count = gojs_backup_add_dir($zip, $files_root, 'files/', $exclude_dirs);
        $metadata['files'] = array(
            'count' => $file_count,
            'root' => basename($files_root),
        );
    }

    // Export each configured database connection.
    if ($include_db) {
        $connections = gojs_load_db_connections();
        foreach ($connections as $conn) {
            if (empty($conn['id'])) continue;
            $conn_config = gojs_get_db_connection($conn['id']);
            if (!$conn_config) continue;

            try {
                $sql_content = gojs_backup_export_db($conn_config);
                if ($sql_content !== '') {
                    $safe_id = preg_replace('/[^A-Za-z0-9_-]/', '_', $conn['id']);
                    $zip->addFromString('database/' . $safe_id . '.sql', $sql_content);
                    $metadata['databases'][] = array(
                        'id' => $conn['id'],
                        'name' => isset($conn['name']) ? $conn['name'] : $conn['id'],
                        'database' => isset($conn_config['database']) ? $conn_config['database'] : '',
                        'size' => strlen($sql_content),
                    );
                }
            } catch (Exception $e) {
                if (!isset($metadata['db_error'])) {
                    $metadata['db_error'] = array();
                }
                $metadata['db_error'][] = (isset($conn['name']) ? $conn['name'] : $conn['id']) . ': ' . $e->getMessage();
            }
        }
    }

    // Package the panel config.
    if ($include_config && file_exists(CONFIG_FILE)) {
        $zip->addFile(CONFIG_FILE, 'config/config.php');
        $metadata['config'] = true;
    }

    // Write metadata.
    $zip->addFromString('backup.json', json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $zip->close();

    $size = @filesize($backup_file);
    gojs_log_operation('backup_create', $backup_name . '.zip', true);

    gojs_json_response(array(
        'filename' => $backup_name . '.zip',
        'size' => $size,
        'metadata' => $metadata,
    ));
}

// Recursively add a directory to the zip, skipping exclude_dirs and protected paths.
function gojs_backup_add_dir($zip, $dir, $zip_prefix, $exclude_dirs) {
    $count = 0;
    $items = @scandir($dir);
    if ($items === false) return 0;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if (in_array($item, $exclude_dirs, true)) continue;

        $path = $dir . '/' . $item;
        $zip_path = $zip_prefix . $item;

        // Skip the panel's own sensitive files and the .gojs config dir.
        if (gojs_is_protected_path($path)) continue;

        if (is_dir($path)) {
            $zip->addEmptyDir($zip_path);
            $count += gojs_backup_add_dir($zip, $path, $zip_path . '/', $exclude_dirs);
        } else if (is_file($path)) {
            $zip->addFile($path, $zip_path);
            $count++;
        }
    }
    return $count;
}

// Export a single database connection to a SQL string, reusing existing connection/export helpers.
function gojs_backup_export_db($conn_config) {
    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        return '';
    }

    $db = $result['connection'];
    $type = $result['type'];

    $name = isset($conn_config['database']) ? $conn_config['database'] : '';
    $host = isset($conn_config['host']) ? $conn_config['host'] : 'localhost';

    $sql = "-- Go.js Lite Backup\n";
    $sql .= "-- Host: {$host}\n";
    $sql .= "-- Database: {$name}\n";
    $sql .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
    $sql .= "SET NAMES utf8;\n";
    $sql .= "SET SQL_MODE=\"\";\n\n";

    $tables = gojs_db_fetch_tables_list($db, $type);
    $batch_size = 1000;

    foreach ($tables as $table) {
        $table_escaped = '`' . str_replace('`', '``', $table) . '`';

        $sql .= "\n-- ------------------------------------------------------------\n";
        $sql .= "-- Table structure for `{$table}`\n";
        $sql .= "-- ------------------------------------------------------------\n";
        $sql .= "DROP TABLE IF EXISTS {$table_escaped};\n";

        $create_sql = gojs_db_show_create_table($db, $type, $table_escaped);
        if ($create_sql !== '') {
            $sql .= $create_sql . ";\n";
        }

        $columns = gojs_db_fetch_columns($db, $type, $table_escaped);
        if (empty($columns)) {
            $sql .= "\n";
            continue;
        }

        $col_list_escaped = array();
        foreach ($columns as $col) {
            $col_list_escaped[] = '`' . str_replace('`', '``', $col) . '`';
        }
        $col_list_sql = implode(', ', $col_list_escaped);

        $sql .= "\n-- Dumping data for `{$table}`\n";

        $offset = 0;
        $has_more = true;
        while ($has_more) {
            $limit_sql = 'SELECT * FROM ' . $table_escaped . ' LIMIT ' . (int)$offset . ', ' . (int)$batch_size;

            $rows = array();
            if ($type === 'mysqli') {
                $res = $db->query($limit_sql);
                if ($res === false || $res === true) break;
                while ($row = $res->fetch_assoc()) {
                    $rows[] = $row;
                }
                $res->free();
            } elseif ($type === 'pdo') {
                $stmt = $db->query($limit_sql);
                if ($stmt === false) break;
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if (empty($rows)) break;

            foreach ($rows as $row) {
                $values = array();
                foreach ($columns as $col) {
                    $val = isset($row[$col]) ? $row[$col] : null;
                    $values[] = gojs_db_escape_value($db, $type, $val);
                }
                $sql .= "INSERT INTO {$table_escaped} ({$col_list_sql}) VALUES (" . implode(', ', $values) . ");\n";
            }

            if (count($rows) < $batch_size) {
                $has_more = false;
            } else {
                $offset += $batch_size;
            }
        }
        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    if ($type === 'mysqli') {
        $db->close();
    }

    return $sql;
}

function gojs_api_backup_list() {
    $backup_dir = CONFIG_DIR . '/backups';
    $backups = array();

    if (!is_dir($backup_dir)) {
        gojs_json_response(array('backups' => array()));
    }

    $files = glob($backup_dir . '/backup-*.zip');
    if (!is_array($files)) $files = array();

    foreach ($files as $file) {
        $basename = basename($file);
        if (!gojs_backup_filename_valid($basename)) continue;

        $meta = null;
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($file) === true) {
                $meta_content = $zip->getFromName('backup.json');
                if ($meta_content) {
                    $decoded = json_decode($meta_content, true);
                    if (is_array($decoded)) {
                        $meta = $decoded;
                    }
                }
                $zip->close();
            }
        }

        $backups[] = array(
            'filename' => $basename,
            'size' => (int)@filesize($file),
            'created' => (int)@filemtime($file),
            'metadata' => $meta,
        );
    }

    // Newest first.
    usort($backups, function ($a, $b) {
        return $b['created'] - $a['created'];
    });

    gojs_json_response(array('backups' => $backups));
}

function gojs_api_backup_download() {
    $filename = gojs_get_param('filename', '');
    if (!gojs_backup_filename_valid($filename)) {
        gojs_json_response(null, array(
            'code' => 'invalid_filename',
            'message' => '无效的备份文件名',
        ), 400);
    }

    $file = CONFIG_DIR . '/backups/' . $filename;
    if (!is_file($file)) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '备份文件不存在',
        ), 404);
    }

    gojs_log_operation('backup_download', $filename, true);

    // Clear all output buffers so binary output is not corrupted.
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    gojs_monitor_bump_bandwidth(0, filesize($file));
    readfile($file);
    exit;
}

function gojs_api_backup_delete() {
    $filename = gojs_get_param('filename', '');
    if (!gojs_backup_filename_valid($filename)) {
        gojs_json_response(null, array(
            'code' => 'invalid_filename',
            'message' => '无效的备份文件名',
        ), 400);
    }

    $file = CONFIG_DIR . '/backups/' . $filename;
    if (!is_file($file)) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '备份文件不存在',
        ), 404);
    }

    $result = @unlink($file);
    gojs_log_operation('backup_delete', $filename, $result);

    if (!$result) {
        gojs_json_response(null, array(
            'code' => 'delete_failed',
            'message' => '删除备份文件失败',
        ), 500);
    }

    gojs_json_response(array('success' => true));
}

// Restore from an existing backup archive: files, databases, and config.
function gojs_api_backup_restore() {
    $filename = gojs_get_param('filename', '');
    if (!gojs_backup_filename_valid($filename)) {
        gojs_json_response(null, array(
            'code' => 'invalid_filename',
            'message' => '无效的备份文件名',
        ), 400);
    }

    $file = CONFIG_DIR . '/backups/' . $filename;
    if (!is_file($file)) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '备份文件不存在',
        ), 404);
    }

    if (!class_exists('ZipArchive')) {
        gojs_json_response(null, array(
            'code' => 'zip_not_available',
            'message' => 'ZipArchive 扩展不可用',
        ), 500);
    }

    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        gojs_json_response(null, array(
            'code' => 'zip_open_failed',
            'message' => '打开备份包失败',
        ), 500);
    }

    @set_time_limit(0);

    $files_root = $GLOBALS['files_root'];
    $restored_files = 0;
    $restored_db = 0;
    $db_errors = array();

    // Restore site files.
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->statIndex($i);
        if (!$entry) continue;
        $name = $entry['name'];

        if (strpos($name, 'files/') !== 0 || $name === 'files/') continue;

        $relative = substr($name, strlen('files/'));
        if ($relative === '' || $relative === false) continue;

        // Path-traversal protection: reject relative paths containing ".." segments.
        $parts = explode('/', $relative);
        $traversal = false;
        foreach ($parts as $p) {
            if ($p === '..') { $traversal = true; break; }
        }
        if ($traversal) continue;

        $dest = $files_root . '/' . $relative;

        // Do not overwrite the panel's own sensitive files.
        if (gojs_is_protected_path($dest)) continue;

        if (substr($name, -1) === '/') {
            if (!is_dir($dest)) @mkdir($dest, 0755, true);
        } else {
            $dir = dirname($dest);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                if (@file_put_contents($dest, $content) !== false) {
                    $restored_files++;
                }
            }
        }
    }

    // Restore databases, matching configured connections by connection id.
    $connections = gojs_load_db_connections();
    $conn_by_id = array();
    foreach ($connections as $conn) {
        if (!empty($conn['id'])) {
            $conn_by_id[$conn['id']] = $conn;
        }
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->statIndex($i);
        if (!$entry) continue;
        $name = $entry['name'];

        if (strpos($name, 'database/') !== 0) continue;
        if (substr($name, -4) !== '.sql') continue;

        $conn_id = basename($name, '.sql');
        if (!isset($conn_by_id[$conn_id])) continue;

        $conn_config = gojs_get_db_connection($conn_id);
        if (!$conn_config) continue;

        $sql_content = $zip->getFromIndex($i);
        if ($sql_content === false) continue;

        $result = gojs_db_connect($conn_config);
        if (!$result['success']) {
            $db_errors[] = (isset($conn_config['name']) ? $conn_config['name'] : $conn_id) . ': ' . $result['error'];
            continue;
        }

        $db = $result['connection'];
        $type = $result['type'];

        @$db->query('SET FOREIGN_KEY_CHECKS=0');
        @$db->query('SET NAMES utf8');
        @$db->query('SET SQL_MODE=""');

        $statements = gojs_sql_split_statements($sql_content);
        foreach ($statements as $stmt) {
            $stmt = gojs_sql_strip_comments($stmt);
            if ($stmt === '') continue;
            @$db->query($stmt);
        }

        $restored_db++;

        if ($type === 'mysqli') {
            $db->close();
        }
    }

    $zip->close();

    $ok = $restored_files > 0 || $restored_db > 0;
    gojs_log_operation('backup_restore', $filename, $ok, 'files=' . $restored_files . ', db=' . $restored_db);

    gojs_json_response(array(
        'restored_files' => $restored_files,
        'restored_db' => $restored_db,
        'db_errors' => $db_errors,
    ));
}

/**
 * SSL certificate status detection: uses stream_socket_client to inspect a domain's
 * SSL expiry time and link state; display-only, never auto-renews.
 */
