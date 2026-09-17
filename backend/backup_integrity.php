<?php

function gojs_backup_integrity_manifest_name() {
    return 'manifest.json';
}

function gojs_backup_integrity_sidecar_path($filename) {
    return CONFIG_DIR . '/backups/' . $filename . '.sha256';
}

function gojs_backup_integrity_file_hash($file) {
    if (!is_file($file) || !is_readable($file)) {
        return '';
    }
    $hash = @hash_file('sha256', $file);
    return is_string($hash) ? $hash : '';
}

function gojs_backup_integrity_entries($zip) {
    $manifest_name = gojs_backup_integrity_manifest_name();
    $entries = array();

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!$stat || !isset($stat['name'])) {
            continue;
        }
        $name = $stat['name'];
        if ($name === $manifest_name) {
            continue;
        }
        if (substr($name, -1) === '/') {
            $entries[] = array('name' => $name, 'dir' => true, 'size' => 0, 'sha256' => '');
            continue;
        }
        $content = $zip->getFromIndex($i);
        $entries[] = array(
            'name' => $name,
            'dir' => false,
            'size' => $content === false ? 0 : strlen($content),
            'sha256' => $content === false ? '' : hash('sha256', $content),
        );
    }

    usort($entries, function ($left, $right) {
        return strcmp($left['name'], $right['name']);
    });

    return $entries;
}

function gojs_backup_integrity_digest($entries) {
    $canonical = array();
    foreach ($entries as $entry) {
        $canonical[] = $entry['name'] . "\n"
            . (empty($entry['dir']) ? 'f' : 'd') . "\n"
            . (int)$entry['size'] . "\n"
            . $entry['sha256'] . "\n";
    }
    return hash('sha256', implode('', $canonical));
}

function gojs_backup_integrity_build_manifest($zip) {
    $entries = gojs_backup_integrity_entries($zip);

    return array(
        'schema' => 1,
        'algorithm' => 'sha256',
        'created_at' => date('Y-m-d H:i:s'),
        'entry_count' => count($entries),
        'entries' => $entries,
        'manifest_sha256' => gojs_backup_integrity_digest($entries),
    );
}

function gojs_backup_integrity_build_manifest_from_file($file) {
    if (!class_exists('ZipArchive') || !is_file($file)) {
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        return null;
    }

    $manifest = gojs_backup_integrity_build_manifest($zip);
    $zip->close();

    return $manifest;
}

function gojs_backup_integrity_append_manifest($file, $manifest) {
    if (!is_array($manifest) || !class_exists('ZipArchive')) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        return false;
    }

    $written = $zip->addFromString(
        gojs_backup_integrity_manifest_name(),
        json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
    $zip->close();

    return $written !== false;
}

function gojs_backup_integrity_write_sidecar($filename, $hash) {    if ($hash === '') {
        return false;
    }
    gojs_backup_integrity_dir();
    return @file_put_contents(gojs_backup_integrity_sidecar_path($filename), $hash . "\n", LOCK_EX) !== false;
}

function gojs_backup_integrity_read_sidecar($filename) {
    $path = gojs_backup_integrity_sidecar_path($filename);
    if (!is_file($path)) {
        return '';
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
        return '';
    }
    $raw = trim($raw);
    return preg_match('/^[0-9a-f]{64}$/', $raw) === 1 ? $raw : '';
}

function gojs_backup_integrity_dir() {
    $dir = CONFIG_DIR . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function gojs_backup_integrity_result($filename, $code, $message) {
    return array(
        'filename' => $filename,
        'ok' => false,
        'legacy' => false,
        'code' => $code,
        'message' => $message,
        'entry_count' => 0,
        'entries_checked' => 0,
        'mismatched' => array(),
        'missing' => array(),
        'extra' => array(),
        'archive_sha256' => '',
        'expected_archive_sha256' => '',
    );
}

function gojs_backup_verify_archive($filename) {
    if (!gojs_backup_filename_valid($filename)) {
        return gojs_backup_integrity_result($filename, 'invalid_filename', 'Invalid backup filename');
    }

    $file = CONFIG_DIR . '/backups/' . $filename;
    if (!is_file($file)) {
        return gojs_backup_integrity_result($filename, 'not_found', 'Backup file not found');
    }

    if (!class_exists('ZipArchive')) {
        return gojs_backup_integrity_result($filename, 'zip_unavailable', 'The ZipArchive extension is unavailable');
    }

    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        return gojs_backup_integrity_result($filename, 'zip_open_failed', 'The backup archive could not be opened');
    }

    $manifest_raw = $zip->getFromName(gojs_backup_integrity_manifest_name());
    $actual = gojs_backup_integrity_entries($zip);
    $zip->close();

    $result = gojs_backup_integrity_result($filename, 'ok', 'The backup archive is intact');
    $result['ok'] = true;
    $result['archive_sha256'] = gojs_backup_integrity_file_hash($file);
    $result['expected_archive_sha256'] = gojs_backup_integrity_read_sidecar($filename);
    $result['entries_checked'] = count($actual);

    if ($manifest_raw === false || $manifest_raw === '') {
        $result['legacy'] = true;
        $result['code'] = 'manifest_missing';
        $result['message'] = 'The archive predates the integrity manifest, so only the archive hash could be checked';
        if ($result['expected_archive_sha256'] !== '' && $result['archive_sha256'] !== ''
            && $result['expected_archive_sha256'] !== $result['archive_sha256']) {
            $result['ok'] = false;
            $result['code'] = 'archive_hash_mismatch';
            $result['message'] = 'The archive hash does not match the recorded checksum';
        }
        return $result;
    }

    $manifest = json_decode($manifest_raw, true);
    if (!is_array($manifest) || !isset($manifest['entries']) || !is_array($manifest['entries'])
        || !isset($manifest['manifest_sha256']) || !is_string($manifest['manifest_sha256'])) {
        $result['ok'] = false;
        $result['code'] = 'manifest_invalid';
        $result['message'] = 'The integrity manifest is malformed';
        return $result;
    }

    $recorded = array();
    foreach ($manifest['entries'] as $entry) {
        if (is_array($entry) && isset($entry['name']) && is_string($entry['name'])) {
            $recorded[$entry['name']] = $entry;
        }
    }

    $expected_digest = gojs_backup_integrity_digest($manifest['entries']);
    if ($expected_digest !== $manifest['manifest_sha256']) {
        $result['ok'] = false;
        $result['code'] = 'manifest_mismatch';
        $result['message'] = 'The integrity manifest was modified after the backup was written';
        $result['entry_count'] = count($recorded);
        return $result;
    }

    $result['entry_count'] = count($recorded);

    $present = array();
    foreach ($actual as $entry) {
        $present[$entry['name']] = $entry;
    }

    foreach ($recorded as $name => $entry) {
        if (!isset($present[$name])) {
            $result['missing'][] = $name;
            continue;
        }
        if (empty($entry['dir']) && isset($entry['sha256']) && $present[$name]['sha256'] !== $entry['sha256']) {
            $result['mismatched'][] = $name;
        }
    }

    foreach ($present as $name => $entry) {
        if (!isset($recorded[$name])) {
            $result['extra'][] = $name;
        }
    }

    $result['missing'] = array_slice($result['missing'], 0, 25);
    $result['mismatched'] = array_slice($result['mismatched'], 0, 25);
    $result['extra'] = array_slice($result['extra'], 0, 25);

    if (!empty($result['mismatched'])) {
        $result['ok'] = false;
        $result['code'] = 'entry_mismatch';
        $result['message'] = 'One or more entries changed after the backup was written';
        return $result;
    }

    if (!empty($result['missing'])) {
        $result['ok'] = false;
        $result['code'] = 'entry_missing';
        $result['message'] = 'The archive is missing entries recorded in the integrity manifest';
        return $result;
    }

    if (!empty($result['extra'])) {
        $result['ok'] = false;
        $result['code'] = 'entry_untracked';
        $result['message'] = 'The archive contains entries that the integrity manifest does not record';
        return $result;
    }

    if ($result['expected_archive_sha256'] !== '' && $result['archive_sha256'] !== ''
        && $result['expected_archive_sha256'] !== $result['archive_sha256']) {
        $result['ok'] = false;
        $result['code'] = 'archive_hash_mismatch';
        $result['message'] = 'The archive hash does not match the recorded checksum';
        return $result;
    }

    return $result;
}

function gojs_backup_archive_stats($filename) {
    $file = CONFIG_DIR . '/backups/' . $filename;
    $stats = array(
        'entries' => 0,
        'uncompressed_bytes' => 0,
        'file_entries' => 0,
        'database_entries' => 0,
        'traversal_entries' => array(),
        'has_metadata' => false,
    );

    if (!class_exists('ZipArchive')) {
        return $stats;
    }

    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        return $stats;
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!$stat || !isset($stat['name'])) {
            continue;
        }
        $name = $stat['name'];
        $stats['entries']++;
        $stats['uncompressed_bytes'] += isset($stat['size']) ? (int)$stat['size'] : 0;

        if ($name === 'backup.json') {
            $stats['has_metadata'] = true;
        }
        if (strpos($name, 'database/') === 0 && substr($name, -4) === '.sql') {
            $stats['database_entries']++;
        }
        if ($name === 'manifest.json') {
            continue;
        }
        if (substr($name, -1) !== '/') {
            $stats['file_entries']++;
        }

        foreach (explode('/', $name) as $part) {
            if ($part === '..') {
                $stats['traversal_entries'][] = $name;
                break;
            }
        }
    }

    $zip->close();

    $stats['traversal_entries'] = array_slice(array_unique($stats['traversal_entries']), 0, 25);

    return $stats;
}

function gojs_backup_restore_precheck($filename, $strict) {
    $verification = gojs_backup_verify_archive($filename);
    $precheck = array(
        'filename' => $filename,
        'ok' => true,
        'code' => 'ok',
        'message' => 'The backup can be restored',
        'strict' => (bool)$strict,
        'errors' => array(),
        'warnings' => array(),
        'verification' => $verification,
        'stats' => array(),
        'free_space' => null,
        'required_bytes' => 0,
    );

    if ($verification['code'] === 'invalid_filename' || $verification['code'] === 'not_found'
        || $verification['code'] === 'zip_unavailable' || $verification['code'] === 'zip_open_failed') {
        $precheck['ok'] = false;
        $precheck['code'] = $verification['code'];
        $precheck['message'] = $verification['message'];
        $precheck['errors'][] = $verification['message'];
        return $precheck;
    }

    if (!$verification['ok']) {
        $precheck['errors'][] = $verification['message'];
    }

    if (!empty($verification['legacy'])) {
        if ($strict) {
            $precheck['errors'][] = 'The archive has no integrity manifest and strict mode is enabled';
        } else {
            $precheck['warnings'][] = 'The archive has no integrity manifest, so only the archive hash was checked';
        }
    }

    $stats = gojs_backup_archive_stats($filename);
    $precheck['stats'] = $stats;
    $precheck['required_bytes'] = (int)$stats['uncompressed_bytes'];

    if ($stats['entries'] === 0) {
        $precheck['errors'][] = 'The archive contains no entry';
    }

    if (!$stats['has_metadata']) {
        $precheck['errors'][] = 'The archive is missing backup.json';
    }

    if (!empty($stats['traversal_entries'])) {
        $precheck['errors'][] = 'The archive contains entries with a parent directory segment';
    }

    $files_root = isset($GLOBALS['files_root']) ? $GLOBALS['files_root'] : '';
    if ($files_root === '' || !is_dir($files_root)) {
        $precheck['errors'][] = 'The files root directory is not available';
    } elseif (!is_writable($files_root)) {
        $precheck['errors'][] = 'The files root directory is not writable';
    } else {
        $free = @disk_free_space($files_root);
        if ($free !== false) {
            $precheck['free_space'] = (int)$free;
            if ($precheck['required_bytes'] > 0 && $precheck['required_bytes'] > (int)$free) {
                $precheck['errors'][] = 'The files root directory does not have enough free space for the restored content';
            }
        }
    }

    $connections = gojs_load_db_connections();
    $known_ids = array();
    foreach ($connections as $connection) {
        if (!empty($connection['id'])) {
            $known_ids[] = $connection['id'];
        }
    }
    $precheck['known_databases'] = $known_ids;
    if ($stats['database_entries'] > 0 && empty($known_ids)) {
        $precheck['warnings'][] = 'The archive holds database dumps but no database connection is configured';
    }

    if (!empty($precheck['errors'])) {
        $precheck['ok'] = false;
        $precheck['code'] = 'restore_precheck_failed';
        $precheck['message'] = $precheck['errors'][0];
        return $precheck;
    }

    if (!empty($precheck['warnings'])) {
        $precheck['code'] = 'ok_with_warnings';
        $precheck['message'] = $precheck['warnings'][0];
    }

    return $precheck;
}

function gojs_api_backup_verify() {
    $filename = gojs_get_param('filename', '');
    gojs_json_response(gojs_backup_verify_archive($filename));
}

function gojs_api_backup_precheck() {
    $filename = gojs_get_param('filename', '');
    $strict = gojs_get_param('strict', '');
    $strict = ($strict === '1' || $strict === 'true' || $strict === true);

    gojs_json_response(gojs_backup_restore_precheck($filename, $strict));
}
