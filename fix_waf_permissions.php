<?php

// Fix WAF directory permissions
define('ROOT', dirname(__FILE__));
define('CONFIG_DIR', ROOT . '/.gojs');

// Create config directory with proper permissions
if (!is_dir(CONFIG_DIR)) {
    @mkdir(CONFIG_DIR, 0700, true);
}

// Create initial config files
$config_files = [
    CONFIG_DIR . '/waf_ip_rules.json' => json_encode(array(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    CONFIG_DIR . '/waf_rules.json' => json_encode(array(
        'sql_injection' => true,
        'xss' => true,
        'command_injection' => true
    ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    CONFIG_DIR . '/waf_rate_limits.json' => json_encode(array(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    CONFIG_DIR . '/waf_geo_blocks.json' => json_encode(array(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    CONFIG_DIR . '/ip_geo_cache.json' => json_encode(array(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    CONFIG_DIR . '/waf_attack_log.txt' => ''
];

foreach ($config_files as $file => $content) {
    if (!file_exists($file)) {
        $result = file_put_contents($file, $content, LOCK_EX);
        if ($result !== false) {
            @chmod($file, 0600);
            echo "Created: {$file}\n";
        } else {
            echo "Failed to create: {$file}\n";
        }
    } else {
        echo "Exists: {$file}\n";
    }
}

echo "WAF permissions fixed successfully!\n";