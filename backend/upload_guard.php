<?php

function gojs_upload_guard_default_blocked_extensions() {
    return array(
        'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8',
        'phps', 'phpt', 'phtml', 'phtm', 'pht', 'phar', 'hphp',
        'php-s', 'php-dist', 'inc',
    );
}

function gojs_upload_guard_default_protected_names() {
    return array(
        '.htaccess',
        '.htpasswd',
        '.user.ini',
        'php.ini',
        '.env',
        'web.config',
    );
}

function gojs_upload_guard_default_php_payload_exempt_extensions() {
    return array('txt', 'sql', 'md', 'html');
}

function gojs_upload_guard_settings() {
    $settings = array(
        'enforce' => true,
        'sniff' => true,
        'block_active_content' => true,
        'block_php_payload' => true,
        'allow_active_svg' => false,
        'max_filename_bytes' => 200,
        'max_scan_bytes' => 0,
        'blocked_extensions' => array(),
        'allowed_extensions' => array(),
        'protected_names' => array(),
        'php_payload_exempt_extensions' => gojs_upload_guard_default_php_payload_exempt_extensions(),
    );

    if (!isset($GLOBALS['config']) || !is_array($GLOBALS['config'])
        || !isset($GLOBALS['config']['upload_guard']) || !is_array($GLOBALS['config']['upload_guard'])) {
        return $settings;
    }

    $configured = $GLOBALS['config']['upload_guard'];

    $flags = array('enforce', 'sniff', 'block_active_content', 'block_php_payload', 'allow_active_svg');
    foreach ($flags as $flag) {
        if (array_key_exists($flag, $configured)) {
            $settings[$flag] = (bool)$configured[$flag];
        }
    }

    if (isset($configured['max_filename_bytes']) && is_numeric($configured['max_filename_bytes'])) {
        $settings['max_filename_bytes'] = max(16, (int)$configured['max_filename_bytes']);
    }

    if (isset($configured['max_scan_bytes']) && is_numeric($configured['max_scan_bytes'])) {
        $settings['max_scan_bytes'] = max(0, (int)$configured['max_scan_bytes']);
    }

    foreach (array('blocked_extensions', 'allowed_extensions', 'php_payload_exempt_extensions') as $key) {
        if (!isset($configured[$key]) || !is_array($configured[$key])) {
            continue;
        }
        $values = array();
        foreach ($configured[$key] as $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = strtolower(trim($value));
            $value = ltrim($value, '.');
            if ($value === '' || in_array($value, $values, true)) {
                continue;
            }
            $values[] = $value;
        }
        $settings[$key] = $values;
    }

    if (isset($configured['protected_names']) && is_array($configured['protected_names'])) {
        $names = array();
        foreach ($configured['protected_names'] as $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = strtolower(trim($value));
            if ($value === '' || in_array($value, $names, true)) {
                continue;
            }
            $names[] = $value;
        }
        $settings['protected_names'] = $names;
    }

    return $settings;
}

function gojs_upload_guard_blocked_extensions($settings = null) {
    if ($settings === null) {
        $settings = gojs_upload_guard_settings();
    }
    $extensions = gojs_upload_guard_default_blocked_extensions();
    if (isset($settings['blocked_extensions']) && is_array($settings['blocked_extensions'])) {
        foreach ($settings['blocked_extensions'] as $extension) {
            if (is_string($extension) && $extension !== '' && !in_array($extension, $extensions, true)) {
                $extensions[] = $extension;
            }
        }
    }
    return $extensions;
}

function gojs_upload_guard_protected_names($settings = null) {
    if ($settings === null) {
        $settings = gojs_upload_guard_settings();
    }
    $names = gojs_upload_guard_default_protected_names();
    if (isset($settings['protected_names']) && is_array($settings['protected_names'])) {
        foreach ($settings['protected_names'] as $name) {
            if (is_string($name) && $name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
    }
    return $names;
}

function gojs_upload_guard_strip_format_characters($value) {
    $value = (string)$value;
    $sequences = array(
        "\xe2\x80\x8b", "\xe2\x80\x8c", "\xe2\x80\x8d", "\xe2\x80\x8e", "\xe2\x80\x8f",
        "\xe2\x80\xaa", "\xe2\x80\xab", "\xe2\x80\xac", "\xe2\x80\xad", "\xe2\x80\xae",
        "\xe2\x81\xa6", "\xe2\x81\xa7", "\xe2\x81\xa8", "\xe2\x81\xa9",
        "\xef\xbb\xbf",
    );
    return str_replace($sequences, '', $value);
}

function gojs_upload_guard_clean_name($name) {
    $name = (string)$name;
    $name = gojs_upload_guard_strip_format_characters($name);
    $name = str_replace(chr(0), '', $name);
    $name = str_replace('\\', '/', $name);
    $slash = strrpos($name, '/');
    if ($slash !== false) {
        $name = substr($name, $slash + 1);
    }
    $name = strtolower($name);
    $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name);
    if (!is_string($name)) {
        return '';
    }
    return rtrim($name, ". \t\r\n;");
}

function gojs_upload_guard_extension_candidates($name) {
    $candidates = array();

    $variants = array(gojs_upload_guard_clean_name($name));
    $decoded = $variants[0];
    for ($pass = 0; $pass < 2; $pass++) {
        if (strpos($decoded, '%') === false) {
            break;
        }
        $next = rawurldecode($decoded);
        if ($next === $decoded) {
            break;
        }
        $decoded = gojs_upload_guard_clean_name($next);
        $variants[] = $decoded;
    }

    foreach ($variants as $variant) {
        if ($variant === '') {
            continue;
        }
        $segments = preg_split('/[.]+/', $variant);
        if (!is_array($segments)) {
            continue;
        }
        $count = count($segments);
        for ($i = 1; $i < $count; $i++) {
            $segment = trim($segments[$i]);
            if ($segment === '') {
                continue;
            }
            if (!in_array($segment, $candidates, true)) {
                $candidates[] = $segment;
            }
            $parts = preg_split('/[\s;:]+/', $segment);
            if (!is_array($parts)) {
                continue;
            }
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part !== '' && !in_array($part, $candidates, true)) {
                    $candidates[] = $part;
                }
            }
        }
    }

    return $candidates;
}

function gojs_upload_guard_last_extension($name) {
    $candidates = gojs_upload_guard_extension_candidates($name);
    if (empty($candidates)) {
        return '';
    }
    return $candidates[count($candidates) - 1];
}

function gojs_upload_guard_extension_families() {
    return array(
        'php' => array(
            'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8',
            'phps', 'phpt', 'phtml', 'phtm', 'pht', 'phar', 'hphp',
            'php-s', 'php-dist', 'inc',
        ),
        'html' => array('html', 'htm', 'xhtml', 'xht'),
        'svg' => array('svg', 'svgz'),
        'image' => array(
            'png', 'jpg', 'jpeg', 'jpe', 'jfif', 'gif', 'bmp', 'webp',
            'tif', 'tiff', 'ico', 'avif', 'heic', 'apng',
        ),
        'archive' => array(
            'zip', 'tar', 'gz', 'tgz', 'bz2', 'tbz', 'tbz2', '7z', 'rar',
            'xz', 'zst', 'cab', 'iso',
        ),
        'audio' => array('mp3', 'wav', 'ogg', 'oga', 'flac', 'm4a', 'aac', 'wma', 'opus', 'mid', 'midi'),
        'video' => array('mp4', 'm4v', 'mov', 'avi', 'mkv', 'webm', 'wmv', 'flv', 'mpg', 'mpeg', '3gp', 'ogv'),
        'pdf' => array('pdf'),
        'font' => array('woff', 'woff2', 'ttf', 'otf', 'eot'),
        'office' => array(
            'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods',
            'odp', 'rtf', 'pages', 'numbers', 'key',
        ),
        'executable' => array(
            'exe', 'com', 'scr', 'msi', 'dll', 'so', 'dylib', 'bin', 'elf',
            'deb', 'rpm', 'dmg', 'apk', 'jar', 'class', 'wasm',
        ),
        'script' => array(
            'sh', 'bash', 'zsh', 'ksh', 'ps1', 'psm1', 'bat', 'cmd', 'vbs',
            'vbe', 'wsf', 'wsh', 'js', 'mjs', 'cjs', 'py', 'rb', 'pl',
            'cgi', 'lua', 'tcl', 'php-cli',
        ),
        'text' => array(
            'txt', 'text', 'md', 'markdown', 'rst', 'log', 'ini', 'conf',
            'cfg', 'yml', 'yaml', 'json', 'xml', 'csv', 'tsv', 'sql', 'tex',
            'srt', 'vtt', 'diff', 'patch',
        ),
    );
}

function gojs_upload_guard_extension_family($name) {
    $extension = gojs_upload_guard_last_extension($name);
    if ($extension === '') {
        return 'other';
    }

    $families = gojs_upload_guard_extension_families();
    foreach ($families as $family => $extensions) {
        if (in_array($extension, $extensions, true)) {
            return $family;
        }
    }

    return 'other';
}

function gojs_upload_guard_mime_family($mime) {
    $mime = strtolower(trim((string)$mime));
    if ($mime === '') {
        return 'unknown';
    }

    if (strpos($mime, 'image/svg') === 0) {
        return 'svg';
    }
    if (strpos($mime, 'text/html') === 0 || strpos($mime, 'application/xhtml') === 0) {
        return 'html';
    }
    if ($mime === 'text/x-php' || strpos($mime, 'application/x-php') === 0
        || strpos($mime, 'application/x-httpd-php') === 0) {
        return 'php';
    }
    if (strpos($mime, 'officedocument') !== false || strpos($mime, 'msword') !== false
        || strpos($mime, 'ms-excel') !== false || strpos($mime, 'ms-powerpoint') !== false
        || strpos($mime, 'opendocument') !== false || strpos($mime, 'rtf') !== false) {
        return 'office';
    }
    if (strpos($mime, 'zip') !== false || strpos($mime, 'gzip') !== false
        || strpos($mime, 'compressed') !== false || strpos($mime, 'tar') !== false
        || strpos($mime, 'x-rar') !== false || strpos($mime, 'x-7z') !== false) {
        return 'archive';
    }
    if (strpos($mime, 'x-dosexec') !== false || strpos($mime, 'x-executable') !== false
        || strpos($mime, 'x-sharedlib') !== false || strpos($mime, 'x-mach-binary') !== false
        || strpos($mime, 'x-msdownload') !== false || strpos($mime, 'x-elf') !== false
        || strpos($mime, 'x-java-archive') !== false) {
        return 'executable';
    }
    if (strpos($mime, 'x-shellscript') !== false || strpos($mime, 'x-python') !== false
        || strpos($mime, 'x-perl') !== false || strpos($mime, 'x-ruby') !== false
        || strpos($mime, 'javascript') !== false || strpos($mime, 'x-sh') !== false) {
        return 'script';
    }
    if (strpos($mime, 'image/') === 0) {
        return 'image';
    }
    if (strpos($mime, 'audio/') === 0) {
        return 'audio';
    }
    if (strpos($mime, 'video/') === 0) {
        return 'video';
    }
    if (strpos($mime, 'font/') === 0 || strpos($mime, 'application/font') === 0
        || strpos($mime, 'vnd.ms-fontobject') !== false) {
        return 'font';
    }
    if (strpos($mime, 'application/pdf') === 0) {
        return 'pdf';
    }
    if (strpos($mime, 'text/') === 0 || strpos($mime, 'application/json') === 0
        || strpos($mime, 'application/xml') === 0 || strpos($mime, 'application/csv') === 0) {
        return 'text';
    }
    if (strpos($mime, 'sqlite') !== false) {
        return 'binary';
    }

    return 'binary';
}

function gojs_upload_guard_sniff_text($head) {
    if (strpos($head, "\0") !== false) {
        return 'application/octet-stream';
    }
    return 'text/plain';
}

function gojs_upload_guard_sniff_magic($path) {
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return '';
    }
    $head = @fread($handle, 4096);
    @fclose($handle);
    if (!is_string($head) || $head === '') {
        return '';
    }

    $signatures = array(
        "\x89PNG\r\n\x1a\n" => 'image/png',
        "\xff\xd8\xff" => 'image/jpeg',
        'GIF87a' => 'image/gif',
        'GIF89a' => 'image/gif',
        '%PDF-' => 'application/pdf',
        "PK\x03\x04" => 'application/zip',
        "\x1f\x8b" => 'application/gzip',
        'BZh' => 'application/x-bzip2',
        "7z\xbc\xaf\x27\x1c" => 'application/x-7z-compressed',
        'Rar!' => 'application/x-rar-compressed',
        "\x7fELF" => 'application/x-executable',
        'SQLite format 3' => 'application/vnd.sqlite3',
        'OggS' => 'application/ogg',
        'fLaC' => 'audio/flac',
        'ID3' => 'audio/mpeg',
        "MZ" => 'application/x-dosexec',
    );

    foreach ($signatures as $signature => $mime) {
        if (strpos($head, $signature) === 0) {
            return $mime;
        }
    }

    if (strpos($head, 'RIFF') === 0 && strlen($head) >= 12 && substr($head, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    if (strlen($head) >= 262 && substr($head, 257, 5) === 'ustar') {
        return 'application/x-tar';
    }

    return gojs_upload_guard_sniff_text($head);
}

function gojs_upload_guard_sniff($path) {
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    if (class_exists('finfo')) {
        $handle = @finfo_open(FILEINFO_MIME_TYPE);
        if ($handle !== false && $handle !== null) {
            $mime = @finfo_file($handle, $path);
            @finfo_close($handle);
            if (is_string($mime) && trim($mime) !== '') {
                return strtolower(trim($mime));
            }
        }
    }

    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if (is_string($mime) && trim($mime) !== '') {
            return strtolower(trim($mime));
        }
    }

    return gojs_upload_guard_sniff_magic($path);
}

function gojs_upload_guard_scan_patterns() {
    return array(
        'php_tag' => '~<\?(php|=)|<script[^>]{0,120}language\s*=\s*[\'"]?php~i',
        'script_tag' => '~<script[\s>/]~i',
        'event_attribute' => '~\son[a-z]{3,20}\s*=\s*[\'"]?~i',
        'javascript_uri' => '~(javascript|vbscript)\s*:|data\s*:\s*text/html~i',
        'foreign_object' => '~<foreignobject[\s>/]~i',
        'external_entity' => '~<!\s*entity~i',
        'frame_element' => '~<(iframe|object|embed|applet)[\s>/]~i',
        'meta_refresh' => '~<meta[^>]{0,200}http-equiv\s*=\s*[\'"]?refresh~i',
        'svg_element' => '~<svg[\s>/]~i',
    );
}

function gojs_upload_guard_scan_file($path, $limit = 0) {
    $findings = array();

    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return $findings;
    }

    $patterns = gojs_upload_guard_scan_patterns();
    $max_findings = count($patterns) + 2;
    $carry = '';
    $carry_length = 128;
    $scanned = 0;
    $first_chunk = true;

    while (!feof($handle)) {
        $chunk = @fread($handle, 65536);
        if ($chunk === false || $chunk === '') {
            break;
        }

        if ($first_chunk) {
            if (preg_match('~^\s*<%~', $chunk) === 1) {
                $findings['asp_tag'] = true;
            }
            if (preg_match('~^\s*<script[\s>]~i', $chunk) === 1) {
                $findings['script_magic'] = true;
            }
            $first_chunk = false;
        }

        $buffer = $carry . $chunk;

        foreach ($patterns as $finding => $pattern) {
            if (isset($findings[$finding])) {
                continue;
            }
            if (preg_match($pattern, $buffer) === 1) {
                $findings[$finding] = true;
            }
        }

        if (count($findings) >= $max_findings) {
            break;
        }

        $carry = strlen($buffer) > $carry_length ? substr($buffer, -$carry_length) : $buffer;
        $scanned += strlen($chunk);

        if ($limit > 0 && $scanned >= $limit) {
            break;
        }
    }

    @fclose($handle);

    return $findings;
}

function gojs_upload_guard_result($ok, $code, $message, $details = array()) {
    return array(
        'ok' => (bool)$ok,
        'code' => (string)$code,
        'message' => (string)$message,
        'details' => is_array($details) ? $details : array(),
    );
}

function gojs_upload_guard_check_name($name, $settings = null) {
    if ($settings === null) {
        $settings = gojs_upload_guard_settings();
    }

    $clean = gojs_upload_guard_clean_name($name);

    if ($clean === '' || $clean === '.' || $clean === '..') {
        return gojs_upload_guard_result(false, 'invalid_name', 'The file name is empty or invalid after normalisation', array(
            'name' => (string)$name,
        ));
    }

    $max_bytes = isset($settings['max_filename_bytes']) ? (int)$settings['max_filename_bytes'] : 200;
    if ($max_bytes > 0 && strlen($clean) > $max_bytes) {
        return gojs_upload_guard_result(false, 'filename_too_long', 'The file name is longer than the configured limit', array(
            'name' => $clean,
            'limit' => $max_bytes,
            'length' => strlen($clean),
        ));
    }

    $protected = gojs_upload_guard_protected_names($settings);
    if (in_array($clean, $protected, true)) {
        return gojs_upload_guard_result(false, 'protected_name', 'This file name is reserved by the server configuration and cannot be uploaded', array(
            'name' => $clean,
        ));
    }

    $candidates = gojs_upload_guard_extension_candidates($clean);

    $blocked = gojs_upload_guard_blocked_extensions($settings);
    $matched = array();
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $blocked, true)) {
            $matched[] = $candidate;
        }
    }
    if (!empty($matched)) {
        return gojs_upload_guard_result(false, 'blocked_extension', 'This file extension can be executed by the web server and is not allowed', array(
            'name' => $clean,
            'extensions' => $candidates,
            'matched' => $matched,
        ));
    }

    $allowed = isset($settings['allowed_extensions']) && is_array($settings['allowed_extensions'])
        ? $settings['allowed_extensions'] : array();
    if (!empty($allowed)) {
        $extension = gojs_upload_guard_last_extension($clean);
        if (!in_array($extension, $allowed, true)) {
            return gojs_upload_guard_result(false, 'extension_not_allowed', 'This file extension is not in the accepted extension list', array(
                'name' => $clean,
                'extension' => $extension,
                'allowed' => array_values($allowed),
            ));
        }
    }

    return gojs_upload_guard_result(true, 'ok', 'The file name passed the upload policy', array(
        'name' => $clean,
        'extensions' => $candidates,
        'extension' => gojs_upload_guard_last_extension($clean),
        'family' => gojs_upload_guard_extension_family($clean),
    ));
}

function gojs_upload_guard_type_spoofed($declared, $actual) {
    if ($declared === '' || $actual === '' || $actual === 'unknown' || $declared === $actual) {
        return false;
    }

    $binary_declared = array('image', 'archive', 'audio', 'video', 'pdf', 'font', 'office');
    $active_actual = array('html', 'svg', 'php', 'executable', 'script');
    if (in_array($declared, $binary_declared, true) && in_array($actual, $active_actual, true)) {
        return true;
    }

    $text_declared = array('text', 'html', 'svg');
    if (in_array($declared, $text_declared, true) && in_array($actual, array('php', 'executable'), true)) {
        return true;
    }

    return false;
}

function gojs_upload_guard_active_findings($findings, $rules) {
    $matched = array();
    foreach ($rules as $rule) {
        if (!empty($findings[$rule])) {
            $matched[] = $rule;
        }
    }
    return $matched;
}

function gojs_upload_guard_check_file($path, $display_name, $options = array()) {
    $settings = gojs_upload_guard_settings();
    if (isset($options['settings']) && is_array($options['settings'])) {
        $settings = array_merge($settings, $options['settings']);
    }

    if (empty($settings['enforce'])) {
        return gojs_upload_guard_result(true, 'ok', 'The upload guard is not enforcing', array(
            'enforced' => false,
        ));
    }

    $name_result = gojs_upload_guard_check_name($display_name, $settings);
    if (empty($name_result['ok'])) {
        return $name_result;
    }

    if (!is_file($path) || !is_readable($path)) {
        return gojs_upload_guard_result(false, 'unreadable', 'The uploaded file could not be read for content inspection', array(
            'path' => (string)$path,
        ));
    }

    $extension = gojs_upload_guard_last_extension($display_name);
    $declared = gojs_upload_guard_extension_family($display_name);

    $sniffed_mime = '';
    $sniffed = '';
    if (!empty($settings['sniff'])) {
        $sniffed_mime = gojs_upload_guard_sniff($path);
        $sniffed = gojs_upload_guard_mime_family($sniffed_mime);
    }

    $limit = isset($settings['max_scan_bytes']) ? (int)$settings['max_scan_bytes'] : 0;
    $findings = gojs_upload_guard_scan_file($path, $limit);

    $details = array(
        'extension' => $extension,
        'declared_family' => $declared,
        'sniffed_mime' => $sniffed_mime,
        'sniffed_family' => $sniffed,
        'findings' => array_keys($findings),
        'size' => @filesize($path),
    );

    if ($declared === 'php') {
        return gojs_upload_guard_result(false, 'blocked_extension', 'This file extension can be executed by the web server and is not allowed', $details);
    }

    $php_payload = !empty($findings['php_tag']) || !empty($findings['asp_tag']) || !empty($findings['script_magic']);
    $exempt = isset($settings['php_payload_exempt_extensions']) && is_array($settings['php_payload_exempt_extensions'])
        ? $settings['php_payload_exempt_extensions'] : array();
    $is_exempt = $extension !== '' && in_array($extension, $exempt, true);

    if (!empty($settings['block_php_payload']) && $php_payload && !$is_exempt) {
        return gojs_upload_guard_result(false, 'php_payload', 'The file content contains server-side script tags that do not match its extension', $details);
    }

    $spoof_exempt = $is_exempt && $sniffed === 'php';

    if (!empty($settings['sniff']) && !$spoof_exempt && gojs_upload_guard_type_spoofed($declared, $sniffed)) {
        return gojs_upload_guard_result(false, 'type_spoof', 'The file content does not match its extension', $details);
    }

    if (!empty($settings['block_active_content'])) {
        $svg_rules = array(
            'script_tag', 'event_attribute', 'javascript_uri', 'foreign_object',
            'external_entity', 'frame_element', 'meta_refresh',
        );

        if ($declared === 'svg') {
            $mismatch = gojs_upload_guard_active_findings($findings, $svg_rules);
            if (!empty($mismatch) && empty($settings['allow_active_svg'])) {
                $details['matched'] = $mismatch;
                return gojs_upload_guard_result(false, 'active_svg', 'The SVG file contains active content that is not allowed for inline delivery', $details);
            }
        } else {
            $binary_families = array('image', 'archive', 'audio', 'video', 'pdf', 'font', 'office', 'other');
            $binary_rules = array('script_tag', 'frame_element', 'javascript_uri', 'meta_refresh');
            if (in_array($declared, $binary_families, true)) {
                $mismatch = gojs_upload_guard_active_findings($findings, $binary_rules);
                if (!empty($mismatch)) {
                    $details['matched'] = $mismatch;
                    return gojs_upload_guard_result(false, 'active_content', 'The file content contains active markup that does not match its extension', $details);
                }
            }
        }
    }

    return gojs_upload_guard_result(true, 'ok', 'The file passed the upload policy', $details);
}

function gojs_upload_guard_report() {
    $settings = gojs_upload_guard_settings();

    return array(
        'enforce' => (bool)$settings['enforce'],
        'sniff' => (bool)$settings['sniff'],
        'block_active_content' => (bool)$settings['block_active_content'],
        'block_php_payload' => (bool)$settings['block_php_payload'],
        'allow_active_svg' => (bool)$settings['allow_active_svg'],
        'max_filename_bytes' => (int)$settings['max_filename_bytes'],
        'max_scan_bytes' => (int)$settings['max_scan_bytes'],
        'blocked_extensions' => gojs_upload_guard_blocked_extensions($settings),
        'allowed_extensions' => isset($settings['allowed_extensions']) ? array_values($settings['allowed_extensions']) : array(),
        'protected_names' => gojs_upload_guard_protected_names($settings),
        'php_payload_exempt_extensions' => isset($settings['php_payload_exempt_extensions']) ? array_values($settings['php_payload_exempt_extensions']) : array(),
        'sniff_available' => class_exists('finfo') || function_exists('mime_content_type'),
    );
}

function gojs_api_upload_guard() {
    $path = gojs_get_param('path', '');
    $response = array(
        'success' => true,
        'policy' => gojs_upload_guard_report(),
    );

    if (is_string($path) && $path !== '') {
        $safe_path = gojs_safe_path($path);
        if ($safe_path === false) {
            gojs_json_response(null, array(
                'code' => 'forbidden',
                'message' => 'Path access is not allowed',
            ), 403);
        }

        if (!is_file($safe_path)) {
            gojs_json_response(null, array(
                'code' => 'not_found',
                'message' => 'File not found',
            ), 404);
        }

        $response['inspection'] = gojs_upload_guard_check_file($safe_path, basename($safe_path));
    }

    gojs_json_response($response);
}
