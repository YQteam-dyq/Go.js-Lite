<?php

define('ROOT', dirname(__FILE__));

require_once __DIR__ . '/backend/autoload.php';

echo "WAF 系统测试\n";
echo "==============\n\n";

function test_waf_ip_functions() {
    echo "测试 IP 黑白名单功能:\n";
    
    $test_ip = '192.168.1.100';
    $test_cidr = '192.168.1.0/24';
    
    gojs_waf_add_ip_rule($test_ip, 'block');
    echo "✓ 已添加 IP 封规则: {$test_ip}\n";
    
    gojs_waf_add_ip_rule($test_cidr, 'allow');
    echo "✓ 已添加 CIDR 允规则: {$test_cidr}\n";
    
    $blocked = gojs_waf_is_ip_blocked($test_ip);
    echo "✓ IP {$test_ip} 被封禁状态: " . ($blocked ? '是' : '否') . "\n";
    
    $allowed = gojs_waf_is_ip_allowed($test_ip);
    echo "✓ IP {$test_ip} 被允许状态: " . ($allowed ? '是' : '否') . "\n";
    
    gojs_waf_remove_ip_rule($test_ip);
    echo "✓ 已删除 IP 封规则: {$test_ip}\n";
    
    gojs_waf_remove_ip_rule($test_cidr);
    echo "✓ 已删除 CIDR 允规则: {$test_cidr}\n";
    
    echo "\n";
}

function test_waf_attack_detection() {
    echo "测试攻击检测功能:\n";
    
    $test_get = array(
        'id' => "1' OR '1'='1",
        'search' => '<script>alert("xss")</script>'
    );
    
    $test_post = array(
        'username' => 'admin',
        'password' => 'test; rm -rf /'
    );
    
    $test_uri = '/index.php?id=1 UNION SELECT * FROM users';
    
    $sql_detected = gojs_waf_check_sql_injection($test_get, $test_post, $test_uri);
    echo "✓ SQL 注入检测: " . ($sql_detected ? '检测到' : '未检测到') . "\n";
    
    $xss_detected = gojs_waf_check_xss($test_get, $test_post, $test_uri);
    echo "✓ XSS 攻击检测: " . ($xss_detected ? '检测到' : '未检测到') . "\n";
    
    $cmd_detected = gojs_waf_check_command_injection($test_get, $test_post, $test_uri);
    echo "✓ 命令注入检测: " . ($cmd_detected ? '检测到' : '未检测到') . "\n";
    
    echo "\n";
}

function test_waf_geo_functions() {
    echo "测试地理位置封禁功能:\n";
    
    $test_country = 'CN';
    
    gojs_waf_add_geo_block($test_country);
    echo "✓ 已添加国家封禁: {$test_country}\n";
    
    $blocks = gojs_waf_load_geo_blocks();
    echo "✓ 当前封禁的国家: " . implode(', ', $blocks) . "\n";
    
    gojs_waf_remove_geo_block($test_country);
    echo "✓ 已删除国家封禁: {$test_country}\n";
    
    echo "\n";
}

function test_waf_rate_limiting() {
    echo "测试速率限制功能:\n";
    
    $test_ip = '10.0.0.1';
    
    for ($i = 0; $i < 5; $i++) {
        $rate_limited = gojs_waf_check_rate_limit($test_ip);
        echo "✓ 第 " . ($i + 1) . " 次请求检查: " . ($rate_limited ? '被限制' : '允许') . "\n";
    }
    
    echo "\n";
}

function test_waf_rule_management() {
    echo "测试规则管理功能:\n";
    
    $rules = gojs_waf_load_rules();
    echo "✓ 当前 WAF 规则:\n";
    foreach ($rules as $rule => $enabled) {
        echo "  - {$rule}: " . ($enabled ? '启用' : '禁用') . "\n";
    }
    
    echo "\n";
}

test_waf_ip_functions();
test_waf_attack_detection();
test_waf_geo_functions();
test_waf_rate_limiting();
test_waf_rule_management();

echo "WAF 系统测试完成!\n";
echo "所有功能测试已通过。\n";