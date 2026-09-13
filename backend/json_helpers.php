<?php

function gojs_read_json_lock_safe(string $path, $default = array()) {
    if (!file_exists($path)) return $default;

    $fp = @fopen($path, 'r');
    if ($fp === false) return $default;

    if (!flock($fp, LOCK_SH)) {
        @fclose($fp);
        return $default;
    }

    $json = @stream_get_contents($fp);
    flock($fp, LOCK_UN);
    @fclose($fp);

    if ($json === false) return $default;

    $data = json_decode($json, true);
    return is_array($data) ? $data : $default;
}

function gojs_write_json_lock_safe(string $path, array $data, bool $pretty = true): array {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0700, true)) {
            return array('success' => false, 'error' => 'Cannot create directory: ' . $dir);
        }
    }

    $flags = $pretty ? (JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : JSON_UNESCAPED_UNICODE;
    $json = json_encode($data, $flags);

    if ($json === false) {
        return array('success' => false, 'error' => 'JSON encoding failed: ' . json_last_error_msg());
    }

    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));

    $bytes_written = @file_put_contents($tmp, $json, LOCK_EX);
    if ($bytes_written === false) {
        @unlink($tmp);
        return array('success' => false, 'error' => 'Cannot write to temporary file');
    }

    if ($bytes_written !== strlen($json)) {
        @unlink($tmp);
        return array('success' => false, 'error' => 'Incomplete write to temporary file');
    }

    if (!@chmod($tmp, 0600)) {
        @unlink($tmp);
        return array('success' => false, 'error' => 'Cannot set file permissions');
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return array('success' => false, 'error' => 'Cannot rename temporary file to target');
    }

    return array('success' => true, 'message' => 'File saved successfully');
}

?>
