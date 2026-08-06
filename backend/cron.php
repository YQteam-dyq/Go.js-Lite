<?php

// Cron: expression parsing, scheduling, backup plans, internal cron tick.
// Split from api.php; keep original function signatures and behavior unchanged.

function gojs_cron_expand_field($field, $min, $max) {
    if ($field === '' || $field === null) return false;
    $field = trim((string)$field);
    $allowed = array();

    if ($field === '*') {
        for ($i = $min; $i <= $max; $i++) $allowed[] = $i;
        return $allowed;
    }

    $parts = explode(',', $field);
    foreach ($parts as $part) {
        $step = 1;
        if (strpos($part, '/') !== false) {
            list($range_part, $step_str) = explode('/', $part, 2);
            $step = (int)$step_str;
            if ($step < 1) $step = 1;
        } else {
            $range_part = $part;
        }

        if ($range_part === '*' || $range_part === '') {
            for ($i = $min; $i <= $max; $i += $step) $allowed[] = $i;
        } elseif (strpos($range_part, '-') !== false) {
            list($start, $end) = explode('-', $range_part, 2);
            $start = (int)$start;
            $end = (int)$end;
            if ($start < $min) $start = $min;
            if ($end > $max) $end = $max;
            for ($i = $start; $i <= $end; $i += $step) $allowed[] = $i;
        } else {
            $v = (int)$range_part;
            if ($v < $min || $v > $max) return false;
            $allowed[] = $v;
        }
    }

    return array_values(array_unique($allowed));
}

function gojs_cron_next_run($expr, $from_ts) {
    if (!is_string($expr) || trim($expr) === '') {
        return $from_ts + 86400;
    }

    $fields = preg_split('/\s+/', trim($expr));
    if (count($fields) !== 5) {
        return $from_ts + 86400;
    }

    $minutes = gojs_cron_expand_field($fields[0], 0, 59);
    $hours = gojs_cron_expand_field($fields[1], 0, 23);
    $doms = gojs_cron_expand_field($fields[2], 1, 31);
    $months = gojs_cron_expand_field($fields[3], 1, 12);
    $dows = gojs_cron_expand_field($fields[4], 0, 6);

    if ($minutes === false || $hours === false || $doms === false || $months === false || $dows === false) {
        return $from_ts + 86400;
    }

    $minutes_set = array_flip($minutes);
    $hours_set = array_flip($hours);
    $doms_set = array_flip($doms);
    $months_set = array_flip($months);
    $dows_set = array_flip($dows);

    $start_ts = $from_ts + 60;
    $max_ts = $from_ts + 86400 * 366;

    $current = $start_ts - ($start_ts % 60);

    while ($current <= $max_ts) {
        $parts = getdate($current);
        $min = (int)$parts['minutes'];
        $hour = (int)$parts['hours'];
        $dom = (int)$parts['mday'];
        $mon = (int)$parts['mon'];
        $dow = (int)$parts['wday'];

        if (
            isset($months_set[$mon]) &&
            isset($doms_set[$dom]) &&
            isset($dows_set[$dow]) &&
            isset($hours_set[$hour]) &&
            isset($minutes_set[$min])
        ) {
            return $current;
        }

        $current += 60;
    }

    return $from_ts + 86400;
}

function gojs_cron_human_readable($expr) {
    if (!is_string($expr)) return $expr;
    $fields = preg_split('/\s+/', trim($expr));
    if (count($fields) !== 5) return $expr;

    list($min, $hour, $dom, $mon, $dow) = $fields;

    if ($min === '0' && $dom === '*' && $mon === '*' && $dow === '*' &&
        preg_match('#^\*/(\d+)$#', $hour, $m)) {
        $n = (int)$m[1];
        return "Every {$n} hours";
    }
    if ($min === '0' && $hour === '*' && $dom === '*' && $mon === '*' && $dow === '*') {
        return 'Every hour';
    }
    if ($min === '*' && $hour === '*' && $dom === '*' && $mon === '*' && $dow === '*') {
        return 'Every minute';
    }
    if ($min === '0' && $hour === '0' && $dom === '*' && $mon === '*' && $dow === '0') {
        return 'Weekly on Sunday 00:00';
    }
    if ($min === '0' && $hour === '0' && $dom === '1' && $mon === '*' && $dow === '*') {
        return 'Monthly on day 1 00:00';
    }
    if (preg_match('#^(\d+)$#', $min, $m1) && preg_match('#^(\d+)$#', $hour, $m2) &&
        $dom === '*' && $mon === '*' && $dow === '*') {
        $h = str_pad($m2[1], 2, '0', STR_PAD_LEFT);
        $m = str_pad($m1[1], 2, '0', STR_PAD_LEFT);
        return "Daily at {$h}:{$m}";
    }

    return trim($expr);
}

/* ============================================================
   A.2 Schedules storage + CRUD endpoints
   ============================================================ */

function gojs_schedules_load(): array {
    global $config;
    return isset($config['backup_schedules']) && is_array($config['backup_schedules'])
        ? $config['backup_schedules']
        : array();
}

function gojs_schedules_save(array $schedules): void {
    global $config;
    $config['backup_schedules'] = $schedules;
    gojs_save_config();
}

function gojs_backup_runs_path(): string {
    return CONFIG_DIR . '/backup_runs.json';
}

function gojs_backup_runs_load(): array {
    return gojs_read_json_lock_safe(gojs_backup_runs_path(), array());
}

function gojs_backup_runs_save(array $runs): void {
    $cap = 1000;
    if (count($runs) > $cap) {
        $runs = array_slice($runs, -$cap);
    }
    gojs_write_json_lock_safe(gojs_backup_runs_path(), $runs, true);
}

function gojs_validate_cron_expr($expr): bool {
    if (!is_string($expr)) return false;
    $fields = preg_split('/\s+/', trim($expr));
    if (count($fields) !== 5) return false;
    list($min, $hour, $dom, $mon, $dow) = $fields;
    return (
        gojs_cron_expand_field($min, 0, 59) !== false &&
        gojs_cron_expand_field($hour, 0, 23) !== false &&
        gojs_cron_expand_field($dom, 1, 31) !== false &&
        gojs_cron_expand_field($mon, 1, 12) !== false &&
        gojs_cron_expand_field($dow, 0, 6) !== false
    );
}

function gojs_validate_destination_ids($dest_ids): bool {
    if (!is_array($dest_ids)) return false;
    $dests = gojs_destinations_load();
    $dest_map = array();
    foreach ($dests as $d) {
        if (!empty($d['id'])) $dest_map[$d['id']] = true;
    }
    foreach ($dest_ids as $id) {
        if (!is_string($id) || !isset($dest_map[$id])) return false;
    }
    return true;
}

function gojs_api_backup_schedules_list() {
    $now = time();
    $schedules = gojs_schedules_load();
    $changed = false;

    foreach ($schedules as $i => $s) {
        if (empty($s['enabled'])) continue;
        $next_run_at = isset($s['next_run_at']) ? (int)$s['next_run_at'] : 0;
        if ($next_run_at === 0 || $next_run_at < $now) {
            $from = $next_run_at > 0 ? $next_run_at : $now;
            $schedules[$i]['next_run_at'] = gojs_cron_next_run(
                isset($s['cron_expr']) ? $s['cron_expr'] : '* * * * *',
                $from
            );
            $changed = true;
        }
    }

    if ($changed) {
        gojs_schedules_save($schedules);
    }

    gojs_json_response(array('schedules' => array_values($schedules)));
}

function gojs_api_backup_schedules_create() {
    $body = gojs_get_body();
    if (!is_array($body)) {
        gojs_json_response(null, array('code' => 'invalid_input', 'message' => '请求体无效'), 400);
    }

    $name = isset($body['name']) ? trim((string)$body['name']) : '';
    if ($name === '') {
        gojs_json_response(null, array('code' => 'invalid_input', 'message' => 'name 不能为空'), 400);
    }

    $cron_expr = isset($body['cron_expr']) ? trim((string)$body['cron_expr']) : '';
    if (!gojs_validate_cron_expr($cron_expr)) {
        gojs_json_response(null, array('code' => 'invalid_cron', 'message' => 'Cron 表达式无效'), 400);
    }

    $dest_ids = isset($body['destination_ids']) && is_array($body['destination_ids'])
        ? $body['destination_ids'] : array();
    if (!gojs_validate_destination_ids($dest_ids)) {
        gojs_json_response(null, array('code' => 'invalid_destination', 'message' => '目标 ID 列表包含不存在的目标'), 400);
    }

    $source = isset($body['source']) && is_array($body['source']) ? $body['source'] : array();
    $retention = isset($body['retention']) && is_array($body['retention']) ? $body['retention'] : array();

    $id = 'sch_' . substr(bin2hex(random_bytes(12)), 0, 16);
    $schedule = array(
        'id' => $id,
        'name' => $name,
        'enabled' => isset($body['enabled']) ? (bool)$body['enabled'] : true,
        'source' => array(
            'include_files' => isset($source['include_files']) ? (bool)$source['include_files'] : true,
            'include_db' => isset($source['include_db']) ? (bool)$source['include_db'] : true,
            'include_config' => isset($source['include_config']) ? (bool)$source['include_config'] : true,
            'exclude_dirs' => isset($source['exclude_dirs']) && is_array($source['exclude_dirs'])
                ? array_values($source['exclude_dirs'])
                : array('cache', 'node_modules', '.git', '.gojs'),
        ),
        'destination_ids' => array_values($dest_ids),
        'cron_expr' => $cron_expr,
        'retention' => array(
            'keep_last' => isset($retention['keep_last']) ? (int)$retention['keep_last'] : 30,
            'keep_daily' => isset($retention['keep_daily']) ? (int)$retention['keep_daily'] : 7,
            'keep_weekly' => isset($retention['keep_weekly']) ? (int)$retention['keep_weekly'] : 4,
            'keep_monthly' => isset($retention['keep_monthly']) ? (int)$retention['keep_monthly'] : 6,
        ),
        'next_run_at' => 0,
        'created_at' => time(),
    );

    if ($schedule['enabled']) {
        $schedule['next_run_at'] = gojs_cron_next_run($cron_expr, time());
    }

    $schedules = gojs_schedules_load();
    $schedules[] = $schedule;
    gojs_schedules_save($schedules);
    gojs_log_operation('backup_schedule_create', $name, true, $cron_expr);

    gojs_json_response(array('schedule' => $schedule));
}

function gojs_api_backup_schedules_update($id) {
    $body = gojs_get_body();
    if (!is_array($body)) {
        gojs_json_response(null, array('code' => 'invalid_input', 'message' => '请求体无效'), 400);
    }

    $schedules = gojs_schedules_load();
    $idx = null;
    foreach ($schedules as $i => $s) {
        if (isset($s['id']) && $s['id'] === $id) {
            $idx = $i;
            break;
        }
    }
    if ($idx === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '计划不存在'), 404);
    }

    $s = $schedules[$idx];
    $old_enabled = !empty($s['enabled']);
    $old_cron = isset($s['cron_expr']) ? $s['cron_expr'] : '';

    if (isset($body['name'])) {
        $name = trim((string)$body['name']);
        if ($name === '') {
            gojs_json_response(null, array('code' => 'invalid_input', 'message' => 'name 不能为空'), 400);
        }
        $s['name'] = $name;
    }

    $enabled_changed = false;
    if (isset($body['enabled'])) {
        $s['enabled'] = (bool)$body['enabled'];
        $enabled_changed = ($old_enabled !== $s['enabled']);
    }

    $cron_changed = false;
    if (isset($body['cron_expr'])) {
        $cron_expr = trim((string)$body['cron_expr']);
        if (!gojs_validate_cron_expr($cron_expr)) {
            gojs_json_response(null, array('code' => 'invalid_cron', 'message' => 'Cron 表达式无效'), 400);
        }
        $s['cron_expr'] = $cron_expr;
        $cron_changed = ($old_cron !== $cron_expr);
    }

    if (isset($body['destination_ids']) && is_array($body['destination_ids'])) {
        if (!gojs_validate_destination_ids($body['destination_ids'])) {
            gojs_json_response(null, array('code' => 'invalid_destination', 'message' => '目标 ID 列表包含不存在的目标'), 400);
        }
        $s['destination_ids'] = array_values($body['destination_ids']);
    }

    if (isset($body['source']) && is_array($body['source'])) {
        $source = $body['source'];
        $old_source = isset($s['source']) && is_array($s['source']) ? $s['source'] : array();
        $s['source'] = array(
            'include_files' => isset($source['include_files']) ? (bool)$source['include_files']
                : (isset($old_source['include_files']) ? (bool)$old_source['include_files'] : true),
            'include_db' => isset($source['include_db']) ? (bool)$source['include_db']
                : (isset($old_source['include_db']) ? (bool)$old_source['include_db'] : true),
            'include_config' => isset($source['include_config']) ? (bool)$source['include_config']
                : (isset($old_source['include_config']) ? (bool)$old_source['include_config'] : true),
            'exclude_dirs' => isset($source['exclude_dirs']) && is_array($source['exclude_dirs'])
                ? array_values($source['exclude_dirs'])
                : (isset($old_source['exclude_dirs']) ? $old_source['exclude_dirs']
                    : array('cache', 'node_modules', '.git', '.gojs')),
        );
    }

    if (isset($body['retention']) && is_array($body['retention'])) {
        $r = $body['retention'];
        $old_r = isset($s['retention']) && is_array($s['retention']) ? $s['retention'] : array();
        $s['retention'] = array(
            'keep_last' => isset($r['keep_last']) ? (int)$r['keep_last']
                : (isset($old_r['keep_last']) ? (int)$old_r['keep_last'] : 30),
            'keep_daily' => isset($r['keep_daily']) ? (int)$r['keep_daily']
                : (isset($old_r['keep_daily']) ? (int)$old_r['keep_daily'] : 7),
            'keep_weekly' => isset($r['keep_weekly']) ? (int)$r['keep_weekly']
                : (isset($old_r['keep_weekly']) ? (int)$old_r['keep_weekly'] : 4),
            'keep_monthly' => isset($r['keep_monthly']) ? (int)$r['keep_monthly']
                : (isset($old_r['keep_monthly']) ? (int)$old_r['keep_monthly'] : 6),
        );
    }

    if ($s['enabled'] && ($cron_changed || $enabled_changed || empty($s['next_run_at']))) {
        $s['next_run_at'] = gojs_cron_next_run(isset($s['cron_expr']) ? $s['cron_expr'] : '* * * * *', time());
    } elseif (!$s['enabled']) {
        $s['next_run_at'] = 0;
    }

    $schedules[$idx] = $s;
    gojs_schedules_save($schedules);
    gojs_log_operation('backup_schedule_update', $s['name'], true);

    gojs_json_response(array('schedule' => $s));
}

function gojs_api_backup_schedules_delete($id) {
    $schedules = gojs_schedules_load();
    $kept = array();
    $deleted = null;
    foreach ($schedules as $d) {
        if (isset($d['id']) && $d['id'] === $id) {
            $deleted = $d;
        } else {
            $kept[] = $d;
        }
    }
    if ($deleted === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '计划不存在'), 404);
    }

    gojs_schedules_save($kept);
    gojs_log_operation('backup_schedule_delete', $deleted['name'], true);
    gojs_json_response(array('success' => true));
}

function gojs_api_backup_schedules_run_now($id) {
    $schedules = gojs_schedules_load();
    $schedule = null;
    foreach ($schedules as $s) {
        if (isset($s['id']) && $s['id'] === $id) {
            $schedule = $s;
            break;
        }
    }
    if ($schedule === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '计划不存在'), 404);
    }

    $result = gojs_backup_execute_schedule($schedule);
    gojs_json_response(array('run_id' => $result['run_record_id'], 'ok' => $result['ok']));
}

/* ============================================================
   A.3 Backup execution + push destinations + retention cleanup
   ============================================================ */

function gojs_backup_create_local($source) {
    $backup_dir = CONFIG_DIR . '/backups';
    if (!is_dir($backup_dir)) {
        if (!@mkdir($backup_dir, 0700, true)) {
            return array('ok' => false, 'error' => '无法创建备份目录');
        }
    }

    $include_files = isset($source['include_files']) ? (bool)$source['include_files'] : true;
    $include_db = isset($source['include_db']) ? (bool)$source['include_db'] : true;
    $include_config = isset($source['include_config']) ? (bool)$source['include_config'] : true;
    $exclude_dirs = isset($source['exclude_dirs']) && is_array($source['exclude_dirs'])
        ? $source['exclude_dirs']
        : array('cache', 'node_modules', '.git', '.gojs');

    if (!$include_files && !$include_db && !$include_config) {
        return array('ok' => false, 'error' => '未选择任何备份范围');
    }

    $timestamp = date('Ymd_His');
    $backup_name = "gojs-backup-{$timestamp}";
    $backup_file = $backup_dir . "/{$backup_name}.tar.gz";

    if (class_exists('PharData')) {
        try {
            @set_time_limit(0);
            $phar = new PharData($backup_file . '.tar');
            $metadata = array(
                'created_at' => date('Y-m-d H:i:s'),
                'version' => APP_VERSION,
                'files' => null,
                'databases' => array(),
                'config' => false,
            );

            $files_root = $GLOBALS['files_root'];

            if ($include_files && is_dir($files_root)) {
                $file_count = 0;
                $items = new RecursiveIteratorIterator(
                    new RecursiveCallbackFilterIterator(
                        new RecursiveDirectoryIterator($files_root, RecursiveDirectoryIterator::SKIP_DOTS),
                        function ($current, $key, $iterator) use ($files_root, $exclude_dirs) {
                            if ($current->isDir()) {
                                $name = $current->getFilename();
                                if (in_array($name, $exclude_dirs, true)) return false;
                            }
                            $path = $current->getPathname();
                            return !gojs_is_protected_path($path);
                        }
                    ),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($items as $file) {
                    if ($file->isFile() && $file->isReadable()) {
                        $rel = substr($file->getPathname(), strlen($files_root) + 1);
                        if ($rel === false || $rel === '') continue;
                        try {
                            $phar->addFile($file->getPathname(), 'files/' . $rel);
                            $file_count++;
                        } catch (Exception $e) {
                        }
                    }
                }
                $metadata['files'] = array('count' => $file_count, 'root' => basename($files_root));
            }

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
                            $phar->addFromString('database/' . $safe_id . '.sql', $sql_content);
                            $metadata['databases'][] = array(
                                'id' => $conn['id'],
                                'name' => isset($conn['name']) ? $conn['name'] : $conn['id'],
                                'database' => isset($conn_config['database']) ? $conn_config['database'] : '',
                                'size' => strlen($sql_content),
                            );
                        }
                    } catch (Exception $e) {
                        if (!isset($metadata['db_error'])) $metadata['db_error'] = array();
                        $metadata['db_error'][] = (isset($conn['name']) ? $conn['name'] : $conn['id']) . ': ' . $e->getMessage();
                    }
                }
            }

            if ($include_config && file_exists(CONFIG_FILE)) {
                $phar->addFile(CONFIG_FILE, 'config/config.php');
                $metadata['config'] = true;
            }

            $phar->addFromString('backup.json', json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $phar->compress(Phar::GZ);
            unset($phar);
            @unlink($backup_file . '.tar');

            $size = @filesize($backup_file);
            gojs_log_operation('backup_schedule_create_local', basename($backup_file), true);
            return array(
                'ok' => true,
                'path' => $backup_file,
                'filename' => basename($backup_file),
                'size' => $size,
                'metadata' => $metadata,
            );
        } catch (Exception $e) {
            @unlink($backup_file);
            @unlink($backup_file . '.tar');
            return array('ok' => false, 'error' => $e->getMessage());
        }
    } else {
        return gojs_backup_create_local_zip($backup_dir, $backup_name, $include_files, $include_db, $include_config, $exclude_dirs);
    }
}

function gojs_backup_create_local_zip($backup_dir, $backup_name, $include_files, $include_db, $include_config, $exclude_dirs) {
    $backup_file = $backup_dir . "/{$backup_name}.zip";
    if (!class_exists('ZipArchive')) {
        return array('ok' => false, 'error' => 'ZipArchive 和 PharData 均不可用');
    }
    $zip = new ZipArchive();
    if ($zip->open($backup_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return array('ok' => false, 'error' => '创建 zip 包失败');
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

    if ($include_files && is_dir($files_root)) {
        $file_count = gojs_backup_add_dir($zip, $files_root, 'files/', $exclude_dirs);
        $metadata['files'] = array('count' => $file_count, 'root' => basename($files_root));
    }

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
                if (!isset($metadata['db_error'])) $metadata['db_error'] = array();
                $metadata['db_error'][] = (isset($conn['name']) ? $conn['name'] : $conn['id']) . ': ' . $e->getMessage();
            }
        }
    }

    if ($include_config && file_exists(CONFIG_FILE)) {
        $zip->addFile(CONFIG_FILE, 'config/config.php');
        $metadata['config'] = true;
    }

    $zip->addFromString('backup.json', json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $zip->close();

    $size = @filesize($backup_file);
    gojs_log_operation('backup_schedule_create_local', basename($backup_file), true);
    return array(
        'ok' => true,
        'path' => $backup_file,
        'filename' => basename($backup_file),
        'size' => $size,
        'metadata' => $metadata,
    );
}

function gojs_parse_backup_created_ts($filename) {
    if (preg_match('/gojs-backup-(\d{8})_(\d{6})/', $filename, $m)) {
        $date = $m[1];
        $time = $m[2];
        $year = (int)substr($date, 0, 4);
        $mon = (int)substr($date, 4, 2);
        $day = (int)substr($date, 6, 2);
        $hour = (int)substr($time, 0, 2);
        $min = (int)substr($time, 2, 2);
        $sec = (int)substr($time, 4, 2);
        return gmmktime($hour, $min, $sec, $mon, $day, $year);
    }
    return file_exists($filename) ? @filemtime($filename) : time();
}

function gojs_retention_prune($dest, $rule) {
    if (!is_array($dest)) return 0;

    $path_prefix = isset($dest['path_prefix']) ? trim((string)$dest['path_prefix'], '/') : '';
    $list_prefix = $path_prefix !== '' ? $path_prefix . '/gojs-backup-' : 'gojs-backup-';

    try {
        $adapter = gojs_destination_factory($dest);
    } catch (Exception $e) {
        return 0;
    }

    if (!method_exists($adapter, 'listObjects') || !method_exists($adapter, 'deleteObject')) {
        return 0;
    }

    $list_result = $adapter->listObjects($list_prefix, 1000);
    $objects = array();
    if (is_array($list_result)) {
        // Prefer plain array (unified adapter format); fall back to legacy wrapper {objects: [...]}.
        if (isset($list_result['objects']) && is_array($list_result['objects']) && !isset($list_result[0])) {
            $objects = $list_result['objects'];
        } else {
            $objects = $list_result;
        }
    }

    $now = time();
    $min_age = 48 * 3600;
    $candidates = array();
    foreach ($objects as $o) {
        $key = is_array($o) ? (isset($o['key']) ? $o['key'] : '') : (string)$o;
        if ($key === '') continue;
        $created_ts = gojs_parse_backup_created_ts($key);
        $age = $now - $created_ts;
        $candidates[] = array('key' => $key, 'created' => $created_ts, 'age' => $age);
    }

    usort($candidates, function($a, $b) { return $b['created'] - $a['created']; });

    $keep_last = isset($rule['keep_last']) ? (int)$rule['keep_last'] : 0;
    $keep_daily = isset($rule['keep_daily']) ? (int)$rule['keep_daily'] : 0;
    $keep_weekly = isset($rule['keep_weekly']) ? (int)$rule['keep_weekly'] : 0;
    $keep_monthly = isset($rule['keep_monthly']) ? (int)$rule['keep_monthly'] : 0;

    $kept = array();
    $remaining = $candidates;

    if ($keep_last > 0) {
        $to_keep = array_slice($remaining, 0, $keep_last);
        foreach ($to_keep as $c) {
            $kept[$c['key']] = true;
        }
        $remaining = array_slice($remaining, $keep_last);
    }

    if ($keep_daily > 0) {
        $daily_buckets = array();
        foreach ($remaining as $c) {
            $bucket = gmdate('Y-m-d', $c['created']);
            if (!isset($daily_buckets[$bucket])) $daily_buckets[$bucket] = array();
            $daily_buckets[$bucket][] = $c;
        }
        krsort($daily_buckets);
        $buckets_taken = 0;
        foreach ($daily_buckets as $bucket => $list) {
            if ($buckets_taken >= $keep_daily) break;
            if (count($list) > 0) {
                $kept[$list[0]['key']] = true;
                $buckets_taken++;
            }
        }
        $temp = array();
        foreach ($remaining as $c) {
            if (!isset($kept[$c['key']])) $temp[] = $c;
        }
        $remaining = $temp;
    }

    if ($keep_weekly > 0) {
        $weekly_buckets = array();
        foreach ($remaining as $c) {
            $week = gmdate('o-W', $c['created']);
            if (!isset($weekly_buckets[$week])) $weekly_buckets[$week] = array();
            $weekly_buckets[$week][] = $c;
        }
        krsort($weekly_buckets);
        $buckets_taken = 0;
        foreach ($weekly_buckets as $bucket => $list) {
            if ($buckets_taken >= $keep_weekly) break;
            if (count($list) > 0) {
                $kept[$list[0]['key']] = true;
                $buckets_taken++;
            }
        }
        $temp = array();
        foreach ($remaining as $c) {
            if (!isset($kept[$c['key']])) $temp[] = $c;
        }
        $remaining = $temp;
    }

    if ($keep_monthly > 0) {
        $monthly_buckets = array();
        foreach ($remaining as $c) {
            $bucket = gmdate('Y-m', $c['created']);
            if (!isset($monthly_buckets[$bucket])) $monthly_buckets[$bucket] = array();
            $monthly_buckets[$bucket][] = $c;
        }
        krsort($monthly_buckets);
        $buckets_taken = 0;
        foreach ($monthly_buckets as $bucket => $list) {
            if ($buckets_taken >= $keep_monthly) break;
            if (count($list) > 0) {
                $kept[$list[0]['key']] = true;
                $buckets_taken++;
            }
        }
    }

    $deleted = 0;
    foreach ($candidates as $c) {
        if (isset($kept[$c['key']])) continue;
        if ($c['age'] < $min_age) continue;
        try {
            $adapter->deleteObject($c['key']);
            $deleted++;
        } catch (Exception $e) {
        }
    }

    return $deleted;
}

function gojs_backup_execute_schedule($schedule) {
    $run_id = 'run_' . substr(bin2hex(random_bytes(12)), 0, 16);
    $started_at = time();
    $now = $started_at;

    $runs = gojs_backup_runs_load();
    $runs[] = array(
        'id' => $run_id,
        'schedule_id' => isset($schedule['id']) ? $schedule['id'] : '',
        'started_at' => $started_at,
        'ended_at' => null,
        'status' => 'running',
        'bytes_total' => 0,
        'destination_results' => array(),
        'pruned_count' => 0,
    );
    gojs_backup_runs_save($runs);

    $source = isset($schedule['source']) && is_array($schedule['source']) ? $schedule['source'] : array();
    $create_result = gojs_backup_create_local($source);

    $bytes_total = 0;
    $dest_results = array();
    $pruned_count = 0;
    $final_status = 'success';

    if (!$create_result['ok']) {
        $final_status = 'failed';
        if (function_exists('gojs_notifications_append')) {
            gojs_notifications_append(array(
                'id' => 'n_' . substr(bin2hex(random_bytes(8)), 0, 16),
                'category' => 'backup',
                'severity' => 'warning',
                'title_key' => 'backup_create_local_failed',
                'body_key' => 'backup_schedule_name',
                'body_params' => array('name' => isset($schedule['name']) ? $schedule['name'] : ''),
                'payload' => array('schedule_id' => isset($schedule['id']) ? $schedule['id'] : '', 'error' => $create_result['error']),
                'created_at' => time(),
            ));
        }
    } else {
        $local_path = $create_result['path'];
        $filename = $create_result['filename'];
        $bytes_total = isset($create_result['size']) ? (int)$create_result['size'] : 0;

        $dest_ids = isset($schedule['destination_ids']) && is_array($schedule['destination_ids'])
            ? $schedule['destination_ids']
            : array();
        $dests = gojs_destinations_load();
        $dest_map = array();
        foreach ($dests as $d) {
            if (!empty($d['id'])) $dest_map[$d['id']] = $d;
        }

        foreach ($dest_ids as $dest_id) {
            if (!isset($dest_map[$dest_id])) {
                $dest_results[] = array('dest_id' => $dest_id, 'ok' => false, 'error' => 'destination not found');
                $final_status = 'failed';
                continue;
            }
            $dest = $dest_map[$dest_id];
            $path_prefix = isset($dest['path_prefix']) ? trim((string)$dest['path_prefix'], '/') : '';
            $remote_key = $path_prefix !== '' ? $path_prefix . '/' . $filename : $filename;

            try {
                $adapter = gojs_destination_factory($dest);
                if (!method_exists($adapter, 'putObject')) {
                    $dest_results[] = array('dest_id' => $dest_id, 'ok' => false, 'error' => 'putObject not supported');
                    $final_status = 'failed';
                    continue;
                }
                $body = @file_get_contents($local_path);
                if ($body === false) {
                    $dest_results[] = array('dest_id' => $dest_id, 'ok' => false, 'error' => '无法读取本地备份文件');
                    $final_status = 'failed';
                    continue;
                }
                $push = $adapter->putObject($remote_key, $body);
                $push_ok = !empty($push['ok']);
                $dest_results[] = array(
                    'dest_id' => $dest_id,
                    'ok' => $push_ok,
                    'remote_path' => $push_ok ? $remote_key : null,
                    'error' => $push_ok ? null : (isset($push['error']) ? $push['error'] : 'unknown error'),
                );
                if (!$push_ok) $final_status = 'failed';
                unset($body);
            } catch (Exception $e) {
                $dest_results[] = array('dest_id' => $dest_id, 'ok' => false, 'error' => $e->getMessage());
                $final_status = 'failed';
            }
        }

        if ($final_status === 'success') {
            $retention = isset($schedule['retention']) && is_array($schedule['retention']) ? $schedule['retention'] : array();
            foreach ($dest_ids as $dest_id) {
                if (isset($dest_map[$dest_id])) {
                    $dest = $dest_map[$dest_id];
                    try {
                        $pruned_count += gojs_retention_prune($dest, $retention);
                    } catch (Exception $e) {
                    }
                }
            }
        }

        $keep_local = true;
        if (isset($source['keep_local']) && $source['keep_local'] === false) {
            $keep_local = false;
        }
        if (!$keep_local) {
            @unlink($local_path);
        }
    }

    $ended_at = time();
    $runs = gojs_backup_runs_load();
    foreach ($runs as $i => $r) {
        if (isset($r['id']) && $r['id'] === $run_id) {
            $runs[$i]['ended_at'] = $ended_at;
            $runs[$i]['status'] = $final_status;
            $runs[$i]['bytes_total'] = $bytes_total;
            $runs[$i]['destination_results'] = $dest_results;
            $runs[$i]['pruned_count'] = $pruned_count;
            break;
        }
    }
    gojs_backup_runs_save($runs);

    if (function_exists('gojs_notifications_append')) {
        gojs_notifications_append(array(
            'id' => 'n_' . substr(bin2hex(random_bytes(8)), 0, 16),
            'category' => 'backup',
            'severity' => $final_status === 'success' ? 'success' : 'critical',
            'title_key' => $final_status === 'success' ? 'backup_run_success' : 'backup_run_failed',
            'body_key' => 'backup_schedule_name',
            'body_params' => array('name' => isset($schedule['name']) ? $schedule['name'] : ''),
            'payload' => array('schedule_id' => isset($schedule['id']) ? $schedule['id'] : '', 'run_id' => $run_id),
            'created_at' => time(),
        ));
    }

    return array(
        'run_record_id' => $run_id,
        'ok' => $final_status === 'success',
        'bytes_total' => $bytes_total,
        'dest_results' => $dest_results,
        'pruned_count' => $pruned_count,
    );
}

/* ============================================================
   A.4 Internal cron tick engine
   ============================================================ */

function gojs_internal_cron_tick(): array {
    $now = time();
    $processed_schedules = 0;
    $processed_runs = 0;

    $schedules = gojs_schedules_load();
    $changed = false;

    foreach ($schedules as $i => $s) {
        if (empty($s['enabled'])) continue;
        $next_run_at = isset($s['next_run_at']) ? (int)$s['next_run_at'] : 0;
        if ($next_run_at > 0 && $next_run_at <= $now) {
            gojs_backup_execute_schedule($s);
            $schedules[$i]['last_run_at'] = $now;
            $schedules[$i]['next_run_at'] = gojs_cron_next_run(
                isset($s['cron_expr']) ? $s['cron_expr'] : '* * * * *',
                $now
            );
            $changed = true;
            $processed_schedules++;
        }
    }

    if ($changed) {
        gojs_schedules_save($schedules);
    }

    $runs = gojs_backup_runs_load();
    $processed_runs = count($runs);

    $drained_outbox = 0;
    if (function_exists('gojs_channels_deliver_all')) {
        $stats = gojs_channels_deliver_all();
        $drained_outbox = isset($stats['delivered']) ? (int)$stats['delivered']
            : (isset($stats['drained']) ? (int)$stats['drained'] : 0);
    }

    $stats = array(
        'processed_schedules' => $processed_schedules,
        'processed_runs' => $processed_runs,
        'drained_outbox' => $drained_outbox,
        'tick_at' => $now,
    );

    if (function_exists('gojs_internal_cron_acme_renew_hook')) {
        gojs_internal_cron_acme_renew_hook($stats);
    }

    // Append one history entry per tick (shared by webcron.php and admin manual trigger; inherently deduplicated).
    $history = gojs_webcron_history_load();
    $history[] = array(
        'id' => 'w_' . uniqid('', true),
        'tick_at' => $now,
        'status' => 'ok',
        'stats' => $stats,
    );
    gojs_webcron_history_save($history);

    return $stats;
}

function gojs_webcron_history_load(): array {
    return gojs_read_json_lock_safe(CONFIG_DIR . '/webcron_history.json', array());
}

function gojs_webcron_history_save(array $history): void {
    global $config;
    $cap = isset($config['webcron_history_cap']) ? (int)$config['webcron_history_cap'] : 100;
    if ($cap > 0 && count($history) > $cap) {
        $history = array_slice($history, -$cap);
    }
    gojs_write_json_lock_safe(CONFIG_DIR . '/webcron_history.json', $history, true);
}

function gojs_api_webcron_status() {
    global $config;
    $token = isset($config['internal_cron_token']) ? (string)$config['internal_cron_token'] : '';
    $token_set = ($token !== '');

    // webcron trigger URL (token returned masked; used for display only).
    $mask = '';
    $token_len = strlen($token);
    if ($token_len > 0) {
        if ($token_len <= 8) {
            $mask = $token[0] . str_repeat('*', max(0, $token_len - 1));
        } else {
            $mask = substr($token, 0, 4) . str_repeat('*', max(0, $token_len - 8)) . substr($token, -4);
        }
    }
    $webcron_url = $token !== '' ? 'webcron.php?token=' . urlencode($mask) : '';

    // History (descending; first entry is the most recent trigger).
    $history = gojs_webcron_history_load();
    usort($history, function($a, $b) {
        $ta = isset($a['tick_at']) ? (int)$a['tick_at'] : 0;
        $tb = isset($b['tick_at']) ? (int)$b['tick_at'] : 0;
        return $tb - $ta;
    });
    $last_triggered_at = null;
    if (isset($history[0]['tick_at'])) {
        $last_triggered_at = (int)$history[0]['tick_at'];
    }

    // Next backup run (min of next_run_at over enabled schedules with next_run_at > 0).
    $next_backup_run_at = null;
    $schedules = gojs_schedules_load();
    foreach ($schedules as $s) {
        if (!is_array($s) || empty($s['enabled'])) continue;
        $n = isset($s['next_run_at']) ? (int)$s['next_run_at'] : 0;
        if ($n > 0 && ($next_backup_run_at === null || $n < $next_backup_run_at)) {
            $next_backup_run_at = $n;
        }
    }

    $cap = isset($config['webcron_history_cap']) ? (int)$config['webcron_history_cap'] : 100;

    gojs_json_response(array(
        'token_set' => $token_set,
        'webcron_url' => $webcron_url,
        'last_triggered_at' => $last_triggered_at,
        'next_backup_run_at' => $next_backup_run_at,
        'cap' => $cap,
        'history' => array_slice($history, 0, 20),
    ));
}

function gojs_api_internal_cron_tick() {
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
    $stats = gojs_internal_cron_tick();
    gojs_json_response($stats);
}

function gojs_api_internal_cron_regenerate_token() {
    global $config;
    $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $token = '';
    for ($i = 0; $i < 32; $i++) {
        $token .= $chars[mt_rand(0, strlen($chars) - 1)];
    }
    $config['internal_cron_token'] = $token;
    gojs_save_config();
    gojs_json_response(array('token' => $token));
}

/* ============================================================
   A.5 Run log endpoints
   ============================================================ */

function gojs_api_backup_runs_list() {
    $schedule_id = gojs_get_param('schedule_id', '');
    $limit = (int)gojs_get_param('limit', 50);
    $offset = (int)gojs_get_param('offset', 0);

    if ($limit < 1) $limit = 1;
    if ($limit > 500) $limit = 500;
    if ($offset < 0) $offset = 0;

    $runs = gojs_backup_runs_load();
    usort($runs, function($a, $b) {
        $at_a = isset($a['started_at']) ? (int)$a['started_at'] : 0;
        $at_b = isset($b['started_at']) ? (int)$b['started_at'] : 0;
        return $at_b - $at_a;
    });

    if ($schedule_id !== '') {
        $runs = array_values(array_filter($runs, function($r) use ($schedule_id) {
            return isset($r['schedule_id']) && $r['schedule_id'] === $schedule_id;
        }));
    }

    $total = count($runs);
    $paged = array_slice($runs, $offset, $limit);

    gojs_json_response(array(
        'runs' => $paged,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
    ));
}

function gojs_api_backup_runs_get($id) {
    $runs = gojs_backup_runs_load();
    foreach ($runs as $r) {
        if (isset($r['id']) && $r['id'] === $id) {
            gojs_json_response(array('run' => $r));
        }
    }
    gojs_json_response(null, array('code' => 'not_found', 'message' => '运行记录不存在'), 404);
}
