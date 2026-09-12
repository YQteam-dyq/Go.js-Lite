<?php

// Test fixed SQL injection detection
require_once __DIR__ . '/backend/waf.php';

echo "Testing fixed SQL injection detection...\n";

// Test input that should be detected
$test_input = "1' OR '1'='1";
echo "Test input: {$test_input}\n";

// Test with actual function
$sql_test = array('id' => $test_input);
$sql_result = gojs_waf_check_sql_injection($sql_test, array(), '');
echo "Function result: " . ($sql_result ? 'DETECTED' : 'CLEAN') . "\n";

// Test other SQL injection patterns
$other_tests = array(
    "1' OR '1'='1",
    "1' or '1'='1",
    "1' OR '1'='1'--",
    "1' AND '1'='1",
    "1' and '1'='1",
    "1; DROP TABLE users",
    "1 UNION SELECT * FROM users",
    "1' AND 1=1--",
    "1' WAITFOR DELAY '0:0:5'--",
    "normal input"
);

foreach ($other_tests as $test) {
    $result = gojs_waf_check_sql_injection(['id' => $test], array(), '');
    echo "'{$test}' => " . ($result ? 'DETECTED' : 'CLEAN') . "\n";
}

echo "Test completed!\n";