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

function gojs_waf_init() {
    if (!is_dir(CONFIG_DIR)) {
        @mkdir(CONFIG_DIR, 0700, true);
    }
    
    if (!file_exists(WAF_IP_RULES_FILE)) {
        file_put_contents(WAF_IP_RULES_FILE, json_encode(array(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    
    if (!file_exists(WAF_RULES_FILE)) {
        file_put_contents(WAF_RULES_FILE, json_encode(array(
            'sql_injection' => true,
            'xss' => true,
            'command_injection' => true
        ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    
    if (!file_exists(WAF_RATE_LIMIT_FILE)) {
        file_put_contents(WAF_RATE_LIMIT_FILE, json_encode(array(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
    
    if (!file_exists(WAF_GEO_BLOCK_FILE)) {
        file_put_contents(WAF_GEO_BLOCK_FILE, json_encode(array(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

function gojs_waf_check_request() {
    $ip = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $request_uri = $_SERVER['REQUEST_URI'];
    $request_method = $_SERVER['REQUEST_METHOD'];
    $post_data = $_POST;
    $get_data = $_GET;
    
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
    
    if (gojs_waf_check_sql_injection($get_data, $post_data, $request_uri)) {
        gojs_waf_log_attack('SQL_INJECTION', $ip, $request_uri);
        header('HTTP/1.1 403 Forbidden');
        exit('SQL injection detected');
    }
    
    if (gojs_waf_check_xss($get_data, $post_data, $request_uri)) {
        gojs_waf_log_attack('XSS', $ip, $request_uri);
        header('HTTP/1.1 403 Forbidden');
        exit('XSS attack detected');
    }
    
    if (gojs_waf_check_command_injection($get_data, $post_data, $request_uri)) {
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
    $limits = gojs_waf_load_rate_limits();
    $current_time = time();
    $window_start = $current_time - 3600;
    
    if (!isset($limits[$ip])) {
        $limits[$ip] = array();
    }
    
    $limits[$ip] = array_filter($limits[$ip], function($timestamp) use ($window_start) {
        return $timestamp > $window_start;
    });
    
    if (count($limits[$ip]) >= 1000) {
        return true;
    }
    
    $limits[$ip][] = $current_time;
    gojs_waf_save_rate_limits($limits);
    return false;
}

function gojs_waf_check_geo_block($ip) {
    $blocks = gojs_waf_load_geo_blocks();
    if (empty($blocks)) {
        return false;
    }
    
    $country = gojs_waf_get_ip_country($ip);
    if (!$country) {
        return false;
    }
    
    return in_array($country, $blocks);
}

function gojs_waf_get_ip_country($ip) {
    $cache_file = CONFIG_DIR . '/ip_geo_cache.json';
    $cache = array();
    
    if (file_exists($cache_file)) {
        $cache = json_decode(file_get_contents($cache_file), true);
    }
    
    if (isset($cache[$ip])) {
        return $cache[$ip];
    }
    
    $country = 'unknown';
    if (function_exists('geoip_country_code_by_name')) {
        $country = @geoip_country_code_by_name($ip);
    }
    
    if ($country === false || $country === null) {
        $country = 'unknown';
    }
    
    $cache[$ip] = $country;
    file_put_contents($cache_file, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    
    return $country;
}

function gojs_waf_check_sql_injection($get_data, $post_data, $request_uri) {
    $patterns = array(
        '/union\s+select/i',
        '/select\s+.*\s+from/i',
        '/insert\s+into/i',
        '/delete\s+from/i',
        '/update\s+.*\s+set/i',
        '/drop\s+table/i',
        '/exec\s*\(/i',
        '/xp_cmdshell/i',
        '/--/i',
        '/\/\*/i',
        '/\#\#/i',
        '/waitfor\s+delay/i',
        '/\b(?:or|and)\s+\w+\s*=\s*\w+/i'
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
    $log_file = CONFIG_DIR . '/waf_attack_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] [{$type}] [{$ip}] [{$uri}]\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
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
    $content = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $result = @file_put_contents(WAF_IP_RULES_FILE, $content, LOCK_EX);
    if ($result !== false) {
        @chmod(WAF_IP_RULES_FILE, 0600);
    }
    return $result !== false;
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
    $content = json_encode($limits, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $result = @file_put_contents(WAF_RATE_LIMIT_FILE, $content, LOCK_EX);
    if ($result !== false) {
        @chmod(WAF_RATE_LIMIT_FILE, 0600);
    }
    return $result !== false;
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
    $content = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $result = @file_put_contents(WAF_GEO_BLOCK_FILE, $content, LOCK_EX);
    if ($result !== false) {
        @chmod(WAF_GEO_BLOCK_FILE, 0600);
    }
    return $result !== false;
}

function gojs_waf_add_geo_block($country) {
    $blocks = gojs_waf_load_geo_blocks();
    if (!in_array($country, $blocks)) {
        $blocks[] = $country;
        return gojs_waf_save_geo_blocks($blocks);
    }
    return true;
}

function gojs_waf_remove_geo_block($country) {
    $blocks = gojs_waf_load_geo_blocks();
    $blocks = array_filter($blocks, function($c) use ($country) {
        return $c !== $country;
    });
    $blocks = array_values($blocks);
    return gojs_waf_save_geo_blocks($blocks);
}

function gojs_waf_load_rules() {
    $content = @file_get_contents(WAF_RULES_FILE);
    if (!$content) {
        return array(
            'sql_injection' => true,
            'xss' => true,
            'command_injection' => true
        );
    }
    $data = json_decode($content, true);
    return is_array($data) ? $data : array(
        'sql_injection' => true,
        'xss' => true,
        'command_injection' => true
    );
}

function gojs_waf_save_rules($rules) {
    $content = json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $result = @file_put_contents(WAF_RULES_FILE, $content, LOCK_EX);
    if ($result !== false) {
        @chmod(WAF_RULES_FILE, 0600);
    }
    return $result !== false;
}

gojs_waf_init();