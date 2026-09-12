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
    $directory = dirname($file);

    if (!is_dir($directory)) {
        @mkdir($directory, 0700, true);
    }

    $result = @file_put_contents($file, $content, $flags | LOCK_EX);

    if ($result !== false) {
        @chmod($file, 0600);
    }

    return $result !== false;
}

function gojs_waf_init() {
    if (!is_dir(CONFIG_DIR)) {
        @mkdir(CONFIG_DIR, 0700, true);
    }
    
    if (!file_exists(WAF_IP_RULES_FILE)) {
        gojs_waf_write_file(WAF_IP_RULES_FILE, json_encode(array(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    
    if (!file_exists(WAF_RULES_FILE)) {
        gojs_waf_write_file(WAF_RULES_FILE, json_encode(array(
            'sql_injection' => true,
            'xss' => true,
            'command_injection' => true
        ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    
    if (!file_exists(WAF_RATE_LIMIT_FILE)) {
        gojs_waf_write_file(WAF_RATE_LIMIT_FILE, json_encode(array(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    
    if (!file_exists(WAF_GEO_BLOCK_FILE)) {
        gojs_waf_write_file(WAF_GEO_BLOCK_FILE, json_encode(array(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

function gojs_waf_check_request() {
    $ip = $_SERVER['REMOTE_ADDR'];
    $request_uri = $_SERVER['REQUEST_URI'];
    $post_data = $_POST;
    $get_data = $_GET;
    $rules = gojs_waf_load_rules();
    
    if (gojs_waf_is_ip_blocked($ip)) {
        gojs_waf_log_attack('IP_BLOCKED', $ip, $request_uri);
        header('HTTP/1.1 403 Forbidden');
        exit('Access denied: IP blocked');
    }
    
    if (gojs_waf_is_ip_allowed($ip)) {
        return;
    }
    
    if (gojs_waf_check_rate_limit($ip)) {
        gojs_waf_log_attack('RATE_LIMIT', $ip, $request_uri);
        header('HTTP/1.1 429 Too Many Requests');
        exit('Rate limit exceeded');
    }
    
    if (gojs_waf_check_geo_block($ip)) {
        gojs_waf_log_attack('GEO_BLOCK', $ip, $request_uri);
        header('HTTP/1.1 403 Forbidden');
        exit('Access denied: Geographic restriction');
    }
    
    if (!empty($rules['sql_injection']) && gojs_waf_check_sql_injection($get_data, $post_data, $request_uri)) {
        gojs_waf_log_attack('SQL_INJECTION', $ip, $request_uri);
        header('HTTP/1.1 403 Forbidden');
        exit('SQL injection detected');
    }
    
    if (!empty($rules['xss']) && gojs_waf_check_xss($get_data, $post_data, $request_uri)) {
        gojs_waf_log_attack('XSS', $ip, $request_uri);
        header('HTTP/1.1 403 Forbidden');
        exit('XSS attack detected');
    }
    
    if (!empty($rules['command_injection']) && gojs_waf_check_command_injection($get_data, $post_data, $request_uri)) {
        gojs_waf_log_attack('COMMAND_INJECTION', $ip, $request_uri);
        header('HTTP/1.1 403 Forbidden');
        exit('Command injection detected');
    }
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

    if (!is_dir($directory)) {
        @mkdir($directory, 0700, true);
    }

    $handle = @fopen(WAF_RATE_LIMIT_FILE, 'c+');

    if ($handle === false) {
        return false;
    }

    @chmod(WAF_RATE_LIMIT_FILE, 0600);

    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return false;
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

    if ($encoded !== false) {
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, $encoded);
        fflush($handle);
    }

    flock($handle, LOCK_UN);
    fclose($handle);

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

    $cache = array();
    $content = @file_get_contents(WAF_GEO_CACHE_FILE);
    if ($content !== false) {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $cache = $decoded;
        }
    }

    if (isset($cache[$ip])) {
        $cached = gojs_waf_normalize_country($cache[$ip]);
        if ($cached !== '') {
            return $cached;
        }
        unset($cache[$ip]);
    }

    $resolved = function_exists('geoip_country_code_by_name') ? @geoip_country_code_by_name($ip) : false;
    $code = is_string($resolved) ? gojs_waf_normalize_country($resolved) : '';

    if ($code === '') {
        return null;
    }

    $cache[$ip] = $code;
    gojs_waf_write_file(WAF_GEO_CACHE_FILE, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    return $code;
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

function gojs_waf_check_sql_injection($get_data, $post_data, $request_uri) {
    $patterns = gojs_waf_sql_injection_patterns();
    
    $data = array_merge($get_data, $post_data, array($request_uri));
    
    foreach ($data as $value) {
        if (is_string($value)) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    return true;
                }
            }
        }
    }
    
    return false;
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
    
    $data = array_merge($get_data, $post_data, array($request_uri));
    
    foreach ($data as $value) {
        if (is_string($value)) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    return true;
                }
            }
        }
    }
    
    return false;
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
    
    $data = array_merge($get_data, $post_data, array($request_uri));
    
    foreach ($data as $value) {
        if (is_string($value)) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    return true;
                }
            }
        }
    }
    
    return false;
}

function gojs_waf_log_attack($type, $ip, $uri) {
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] [{$type}] [{$ip}] [{$uri}]\n";

    gojs_waf_write_file(WAF_ATTACK_LOG_FILE, $log_entry, FILE_APPEND);
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