<?php

if (!defined('ROOT')) {
    define('ROOT', dirname(__FILE__, 2));
}
if (!defined('CONFIG_DIR')) {
    define('CONFIG_DIR', ROOT . '/.gojs');
}
define('WAF_IP_RULES_FILE', CONFIG_DIR . '/waf_ip_rules.json');
define('WAF_RULES_FILE', CONFIG_DIR . '/waf_rules.json');
define('WAF_RATE_LIMIT_FILE', CONFIG_DIR . '/waf_rate_limits.json');
define('WAF_GEO_BLOCK_FILE', CONFIG_DIR . '/waf_geo_blocks.json');
define('WAF_GEO_CACHE_FILE', CONFIG_DIR . '/ip_geo_cache.json');
define('WAF_ATTACK_LOG_FILE', CONFIG_DIR . '/waf_attack_log.txt');

function gojs_waf_write_file($file, $content, $flags = 0) {
    $result = gojs_waf_write_file_result($file, $content, $flags);

    return $result['ok'] === true;
}

function gojs_waf_write_file_result($file, $content, $flags = 0) {
    $directory = dirname($file);

    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        return gojs_waf_storage_failure($file, 'storage_directory_unavailable', 'Security storage directory is not available');
    }

    $result = @file_put_contents($file, $content, $flags | LOCK_EX);

    if ($result === false) {
        return gojs_waf_storage_failure($file, 'storage_write_failed', 'Security storage file could not be written');
    }

    @chmod($file, 0600);

    return gojs_waf_storage_success($file);
}

function gojs_waf_storage_success($file) {
    return array(
        'ok' => true,
        'code' => '',
        'message' => '',
        'file' => $file
    );
}

function gojs_waf_storage_failure($file, $code, $message) {
    $failure = array(
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'file' => $file
    );

    $GLOBALS['gojs_waf_storage_error'] = $failure;

    return $failure;
}

function gojs_waf_last_storage_error() {
    return isset($GLOBALS['gojs_waf_storage_error']) ? $GLOBALS['gojs_waf_storage_error'] : null;
}

function gojs_waf_storage_ready() {
    return !empty($GLOBALS['gojs_waf_storage_ready']);
}

function gojs_waf_init() {
    $GLOBALS['gojs_waf_storage_error'] = null;
    $GLOBALS['gojs_waf_storage_ready'] = false;

    if (!is_dir(CONFIG_DIR) && !@mkdir(CONFIG_DIR, 0700, true) && !is_dir(CONFIG_DIR)) {
        gojs_waf_storage_failure(CONFIG_DIR, 'storage_directory_unavailable', 'Security storage directory is not available');

        return false;
    }

    $defaults = array(
        WAF_IP_RULES_FILE => array(),
        WAF_RULES_FILE => array(
            'sql_injection' => true,
            'xss' => true,
            'command_injection' => true
        ),
        WAF_RATE_LIMIT_FILE => array(),
        WAF_GEO_BLOCK_FILE => array()
    );

    foreach ($defaults as $file => $contents) {
        if (file_exists($file)) {
            continue;
        }

        gojs_waf_write_file($file, json_encode($contents, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    $ready = true;

    foreach (array_keys($defaults) as $file) {
        if (!file_exists($file) || !is_readable($file)) {
            $ready = false;
            break;
        }
    }

    if (!$ready && gojs_waf_last_storage_error() === null) {
        gojs_waf_storage_failure(CONFIG_DIR, 'storage_files_unavailable', 'Security storage files are not available');
    }

    $GLOBALS['gojs_waf_storage_ready'] = $ready;

    return $ready;
}

function gojs_waf_enforce_request() {
    if (defined('GOJS_SKIP_WAF') && GOJS_SKIP_WAF) {
        return;
    }

    if (PHP_SAPI === 'cli') {
        return;
    }

    if (defined('GOJS_WAF_REQUEST_CHECKED') && GOJS_WAF_REQUEST_CHECKED) {
        return;
    }

    define('GOJS_WAF_REQUEST_CHECKED', true);

    gojs_waf_check_request();
}

function gojs_waf_check_request() {
    $GLOBALS['gojs_waf_storage_error'] = null;

    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $post_data = isset($_POST) && is_array($_POST) ? $_POST : array();
    $get_data = isset($_GET) && is_array($_GET) ? $_GET : array();
    $rules = gojs_waf_load_rules();

    if (!gojs_waf_storage_ready()) {
        gojs_waf_deny('503 Service Unavailable', 'Security storage unavailable', 'STORAGE_UNAVAILABLE', $ip, $request_uri);
    }

    if ($ip !== '') {
        if (gojs_waf_is_ip_blocked($ip)) {
            gojs_waf_deny('403 Forbidden', 'Access denied: IP blocked', 'IP_BLOCKED', $ip, $request_uri);
        }

        if (gojs_waf_is_ip_allowed($ip)) {
            return;
        }

        if (gojs_waf_check_rate_limit($ip)) {
            if (gojs_waf_last_storage_error() !== null) {
                gojs_waf_deny('503 Service Unavailable', 'Security storage unavailable', 'STORAGE_UNAVAILABLE', $ip, $request_uri);
            }

            gojs_waf_deny('429 Too Many Requests', 'Rate limit exceeded', 'RATE_LIMIT', $ip, $request_uri);
        }

        if (gojs_waf_check_geo_block($ip)) {
            gojs_waf_deny('403 Forbidden', 'Access denied: Geographic restriction', 'GEO_BLOCK', $ip, $request_uri);
        }
    }

    if (!empty($rules['sql_injection']) && gojs_waf_check_sql_injection($get_data, $post_data, $request_uri)) {
        gojs_waf_deny('403 Forbidden', 'SQL injection detected', 'SQL_INJECTION', $ip, $request_uri);
    }

    if (!empty($rules['xss']) && gojs_waf_check_xss($get_data, $post_data, $request_uri)) {
        gojs_waf_deny('403 Forbidden', 'XSS attack detected', 'XSS', $ip, $request_uri);
    }

    if (!empty($rules['command_injection']) && gojs_waf_check_command_injection($get_data, $post_data, $request_uri)) {
        gojs_waf_deny('403 Forbidden', 'Command injection detected', 'COMMAND_INJECTION', $ip, $request_uri);
    }
}

function gojs_waf_deny($status, $message, $type, $ip, $uri) {
    gojs_waf_log_attack($type, $ip, $uri);
    header('HTTP/1.1 ' . $status);
    exit($message);
}

function gojs_waf_is_ip_blocked($ip) {
    $rules = gojs_waf_load_ip_rules();
    foreach ($rules as $rule) {
        if ($rule['type'] === 'block' && gojs_waf_match_ip($ip, $rule['ip'])) {
            return true;
        }
    }
    return false;
}

function gojs_waf_is_ip_allowed($ip) {
    $rules = gojs_waf_load_ip_rules();
    foreach ($rules as $rule) {
        if ($rule['type'] === 'allow' && gojs_waf_match_ip($ip, $rule['ip'])) {
            return true;
        }
    }
    return false;
}

function gojs_waf_match_ip($ip, $rule) {
    if (strpos($rule, '/') !== false) {
        list($network, $mask) = explode('/', $rule);
        $ip_long = ip2long($ip);
        $network_long = ip2long($network);
        $mask_long = ~((1 << (32 - $mask)) - 1);
        return ($ip_long & $mask_long) === ($network_long & $mask_long);
    }
    return $ip === $rule;
}

function gojs_waf_check_rate_limit($ip) {
    $directory = dirname(WAF_RATE_LIMIT_FILE);

    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        gojs_waf_storage_failure(WAF_RATE_LIMIT_FILE, 'storage_directory_unavailable', 'Security storage directory is not available');

        return true;
    }

    $handle = @fopen(WAF_RATE_LIMIT_FILE, 'c+');

    if ($handle === false) {
        gojs_waf_storage_failure(WAF_RATE_LIMIT_FILE, 'storage_write_failed', 'Rate limit state file could not be opened');

        return true;
    }

    @chmod(WAF_RATE_LIMIT_FILE, 0600);

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);

        gojs_waf_storage_failure(WAF_RATE_LIMIT_FILE, 'storage_lock_failed', 'Rate limit state file could not be locked');

        return true;
    }

    $raw = stream_get_contents($handle);
    $limits = json_decode((string) $raw, true);

    if (!is_array($limits)) {
        $limits = array();
    }

    $current_time = time();
    $window_start = $current_time - 3600;

    if (!isset($limits[$ip]) || !is_array($limits[$ip])) {
        $limits[$ip] = array();
    }

    $limits[$ip] = array_values(array_filter($limits[$ip], function($timestamp) use ($window_start) {
        return is_numeric($timestamp) && (int) $timestamp > $window_start;
    }));

    $exceeded = count($limits[$ip]) >= 1000;

    if (!$exceeded) {
        $limits[$ip][] = $current_time;
    }

    $encoded = json_encode($limits, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    if ($encoded === false) {
        flock($handle, LOCK_UN);
        fclose($handle);

        gojs_waf_storage_failure(WAF_RATE_LIMIT_FILE, 'storage_encode_failed', 'Rate limit state could not be encoded');

        return true;
    }

    ftruncate($handle, 0);
    rewind($handle);
    $written = fwrite($handle, $encoded);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    if ($written === false || $written !== strlen($encoded)) {
        gojs_waf_storage_failure(WAF_RATE_LIMIT_FILE, 'storage_write_failed', 'Rate limit state could not be persisted');

        return true;
    }

    return $exceeded;
}

function gojs_waf_normalize_country($country) {
    if (!is_string($country)) {
        return '';
    }

    $code = strtoupper(trim($country));

    return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : '';
}

function gojs_waf_is_country_blocked($country) {
    $code = gojs_waf_normalize_country($country);
    if ($code === '') {
        return false;
    }

    foreach (gojs_waf_load_geo_blocks() as $blocked) {
        if (gojs_waf_normalize_country($blocked) === $code) {
            return true;
        }
    }

    return false;
}

function gojs_waf_check_geo_block($ip) {
    $blocks = gojs_waf_load_geo_blocks();
    if (empty($blocks)) {
        return false;
    }

    $country = gojs_waf_get_ip_country($ip);
    if ($country === null) {
        return false;
    }

    return gojs_waf_is_country_blocked($country);
}

function gojs_waf_get_ip_country($ip) {
    if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return null;
    }

    $cached = gojs_waf_read_geo_cache($ip);

    if ($cached !== null) {
        return $cached;
    }

    $resolved = function_exists('geoip_country_code_by_name') ? @geoip_country_code_by_name($ip) : false;
    $code = is_string($resolved) ? gojs_waf_normalize_country($resolved) : '';

    if ($code === '') {
        return null;
    }

    gojs_waf_store_geo_cache($ip, $code);

    return $code;
}

function gojs_waf_read_geo_cache($ip) {
    $content = @file_get_contents(WAF_GEO_CACHE_FILE);

    if ($content === false) {
        return null;
    }

    $decoded = json_decode($content, true);

    if (!is_array($decoded) || !isset($decoded[$ip])) {
        return null;
    }

    $cached = gojs_waf_normalize_country($decoded[$ip]);

    return $cached !== '' ? $cached : null;
}

function gojs_waf_store_geo_cache($ip, $code) {
    $directory = dirname(WAF_GEO_CACHE_FILE);

    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        return gojs_waf_storage_failure(WAF_GEO_CACHE_FILE, 'storage_directory_unavailable', 'Security storage directory is not available');
    }

    $handle = @fopen(WAF_GEO_CACHE_FILE, 'c+');

    if ($handle === false) {
        return gojs_waf_storage_failure(WAF_GEO_CACHE_FILE, 'storage_write_failed', 'Geolocation cache file could not be opened');
    }

    @chmod(WAF_GEO_CACHE_FILE, 0600);

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);

        return gojs_waf_storage_failure(WAF_GEO_CACHE_FILE, 'storage_lock_failed', 'Geolocation cache file could not be locked');
    }

    $raw = stream_get_contents($handle);
    $cache = json_decode((string) $raw, true);

    if (!is_array($cache)) {
        $cache = array();
    }

    $cache[$ip] = $code;

    $encoded = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    if ($encoded === false) {
        flock($handle, LOCK_UN);
        fclose($handle);

        return gojs_waf_storage_failure(WAF_GEO_CACHE_FILE, 'storage_encode_failed', 'Geolocation cache could not be encoded');
    }

    ftruncate($handle, 0);
    rewind($handle);
    $written = fwrite($handle, $encoded);
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    if ($written === false) {
        return gojs_waf_storage_failure(WAF_GEO_CACHE_FILE, 'storage_write_failed', 'Geolocation cache could not be persisted');
    }

    return gojs_waf_storage_success(WAF_GEO_CACHE_FILE);
}

function gojs_waf_sql_injection_patterns() {
    return array(
        'union_select' => '/\bunion\b[\s\S]{0,32}?\bselect\b/i',
        'select_from' => '/\bselect\b[\s\S]{0,96}?\bfrom\b/i',
        'insert_into' => '/\binsert\s+into\b/i',
        'delete_from' => '/\bdelete\s+from\b/i',
        'update_set' => '/\bupdate\b[\s\S]{0,96}?\bset\b/i',
        'drop_table' => '/\bdrop\s+table\b/i',
        'truncate_table' => '/\btruncate\s+table\b/i',
        'alter_table' => '/\balter\s+table\b/i',
        'exec_call' => '/\b(?:exec|execute)\s*\(/i',
        'stored_procedure' => '/\b(?:xp_cmdshell|sp_executesql|sp_configure)\b/i',
        'time_delay' => '/\b(?:waitfor\s+delay\b|pg_sleep\s*\(|benchmark\s*\()/i',
        'stacked_query' => '/;\s*(?:select|insert|update|delete|drop|truncate|alter|create|grant|exec|execute)\b/i',
        'schema_probe' => '/\b(?:information_schema|pg_catalog|sysobjects)\b/i',
        'comment_marker' => '/(?:--(?:\s|$)|#(?:\s|$)|##|\/\*[\s\S]*?\*\/)/',
        'boolean_tautology' => '/(?:^|\W)(?:or|and)\s+(?:\d+|[\'"][^\'"]{0,64}[\'"]?)\s*(?:=|<>|!=|<=|>=|<|>|\blike\b)\s*(?:\d+|[\'"][^\'"]{0,64}[\'"]?)/i'
    );
}

function gojs_waf_collect_scalar_values($data, $depth = 0) {
    if ($depth > 10) {
        return array();
    }

    $values = array();

    foreach ((array) $data as $value) {
        if (is_array($value)) {
            foreach (gojs_waf_collect_scalar_values($value, $depth + 1) as $nested) {
                $values[] = $nested;
            }
        } elseif (is_scalar($value)) {
            $values[] = (string) $value;
        }
    }

    return $values;
}

function gojs_waf_any_pattern_matches($values, $patterns) {
    foreach ($values as $value) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }
    }

    return false;
}

function gojs_waf_check_sql_injection($get_data, $post_data, $request_uri) {
    $values = gojs_waf_collect_scalar_values(array($get_data, $post_data, $request_uri));

    return gojs_waf_any_pattern_matches($values, gojs_waf_sql_injection_patterns());
}

function gojs_waf_check_xss($get_data, $post_data, $request_uri) {
    $patterns = array(
        '/<script[^>]*?>.*?<\/script>/i',
        '/<iframe[^>]*?>.*?<\/iframe>/i',
        '/<object[^>]*?>.*?<\/object>/i',
        '/<embed[^>]*?>.*?<\/embed>/i',
        '/javascript:/i',
        '/vbscript:/i',
        '/on\w+\s*=/i',
        '/<\?php/i',
        '/<\?=/i',
        '/eval\s*\(/i',
        '/alert\s*\(/i',
        '/document\.cookie/i',
        '/window\.location/i'
    );
    
    $values = gojs_waf_collect_scalar_values(array($get_data, $post_data, $request_uri));

    return gojs_waf_any_pattern_matches($values, $patterns);
}

function gojs_waf_check_command_injection($get_data, $post_data, $request_uri) {
    $patterns = array(
        '/\b(?:ls|dir|cat|type|more|less|head|tail|grep|find|exec|system|passthru|shell_exec|proc_open|popen)\b/i',
        '/\|\s*\w+/i',
        '/;\s*\w+/i',
        '/&&\s*\w+/i',
        '/\|\|/i',
        '/`.*?`/i',
        '/\$\([^)]*\)/i',
        '/\/bin\/sh/i',
        '/\/bin\/bash/i',
        '/cmd\.exe/i',
        '/powershell/i',
        '/\.exe\s/i'
    );
    
    $values = gojs_waf_collect_scalar_values(array($get_data, $post_data, $request_uri));

    return gojs_waf_any_pattern_matches($values, $patterns);
}

function gojs_waf_log_attack($type, $ip, $uri) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] [{$type}] [{$ip}] [{$uri}]\n";

    return gojs_waf_write_file_result(WAF_ATTACK_LOG_FILE, $log_entry, FILE_APPEND);
}

function gojs_waf_load_ip_rules() {
    $content = @file_get_contents(WAF_IP_RULES_FILE);
    if (!$content) {
        return array();
    }
    $data = json_decode($content, true);
    return is_array($data) ? $data : array();
}

function gojs_waf_save_ip_rules($rules) {
    return gojs_waf_write_file(WAF_IP_RULES_FILE, json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function gojs_waf_add_ip_rule($ip, $type) {
    if ($type !== 'allow' && $type !== 'block') {
        return false;
    }
    
    $rules = gojs_waf_load_ip_rules();
    $rules[] = array(
        'ip' => $ip,
        'type' => $type,
        'created' => date('Y-m-d H:i:s')
    );
    return gojs_waf_save_ip_rules($rules);
}

function gojs_waf_remove_ip_rule($ip) {
    $rules = gojs_waf_load_ip_rules();
    $rules = array_filter($rules, function($rule) use ($ip) {
        return $rule['ip'] !== $ip;
    });
    $rules = array_values($rules);
    return gojs_waf_save_ip_rules($rules);
}

function gojs_waf_load_rate_limits() {
    $content = @file_get_contents(WAF_RATE_LIMIT_FILE);
    if (!$content) {
        return array();
    }
    $data = json_decode($content, true);
    return is_array($data) ? $data : array();
}

function gojs_waf_save_rate_limits($limits) {
    return gojs_waf_write_file(WAF_RATE_LIMIT_FILE, json_encode($limits, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function gojs_waf_load_geo_blocks() {
    $content = @file_get_contents(WAF_GEO_BLOCK_FILE);
    if (!$content) {
        return array();
    }
    $data = json_decode($content, true);
    return is_array($data) ? $data : array();
}

function gojs_waf_save_geo_blocks($blocks) {
    return gojs_waf_write_file(WAF_GEO_BLOCK_FILE, json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function gojs_waf_add_geo_block($country) {
    $code = gojs_waf_normalize_country($country);
    if ($code === '') {
        return false;
    }

    $blocks = gojs_waf_load_geo_blocks();
    foreach ($blocks as $blocked) {
        if (gojs_waf_normalize_country($blocked) === $code) {
            return true;
        }
    }

    $blocks[] = $code;

    return gojs_waf_save_geo_blocks($blocks);
}

function gojs_waf_remove_geo_block($country) {
    $code = gojs_waf_normalize_country($country);
    if ($code === '') {
        return false;
    }

    $kept = array();
    foreach (gojs_waf_load_geo_blocks() as $blocked) {
        if (gojs_waf_normalize_country($blocked) !== $code) {
            $kept[] = $blocked;
        }
    }

    return gojs_waf_save_geo_blocks($kept);
}

function gojs_waf_load_rules() {
    $defaults = array(
        'sql_injection' => true,
        'xss' => true,
        'command_injection' => true
    );

    $content = @file_get_contents(WAF_RULES_FILE);

    if (!$content) {
        return $defaults;
    }

    $data = json_decode($content, true);

    if (!is_array($data)) {
        return $defaults;
    }

    return array_merge($defaults, $data);
}

function gojs_waf_save_rules($rules) {
    return gojs_waf_write_file(WAF_RULES_FILE, json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

gojs_waf_init();