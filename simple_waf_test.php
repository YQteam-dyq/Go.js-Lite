<?php

// Simple test to verify WAF functions work
require_once __DIR__ . '/backend/waf.php';

echo "Testing WAF functions...\n";

// Test IP rule functions
echo "Testing IP rule functions...\n";
$result1 = gojs_waf_add_ip_rule('192.168.1.100', 'block');
echo "Add IP rule result: " . ($result1 ? 'true' : 'false') . "\n";

$result2 = gojs_waf_is_ip_blocked('192.168.1.100');
echo "Is IP blocked result: " . ($result2 ? 'true' : 'false') . "\n";

$result3 = gojs_waf_add_ip_rule('192.168.1.0/24', 'allow');
echo "Add CIDR rule result: " . ($result3 ? 'true' : 'false') . "\n";

$result4 = gojs_waf_is_ip_allowed('192.168.1.100');
echo "Is IP allowed result: " . ($result4 ? 'true' : 'false') . "\n";

// Test SQL injection detection
echo "\nTesting SQL injection detection...\n";
$sql_test = array('id' => "1' OR '1'='1");
$sql_result = gojs_waf_check_sql_injection($sql_test, array(), '');
echo "SQL injection detection result: " . ($sql_result ? 'true' : 'false') . "\n";

// Test XSS detection
echo "\nTesting XSS detection...\n";
$xss_test = array('input' => '<script>alert("xss")</script>');
$xss_result = gojs_waf_check_xss($xss_test, array(), '');
echo "XSS detection result: " . ($xss_result ? 'true' : 'false') . "\n";

echo "\nWAF test completed!\n";