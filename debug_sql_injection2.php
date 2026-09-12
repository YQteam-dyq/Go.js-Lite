<?php

// Debug SQL injection detection with all patterns
require_once __DIR__ . '/backend/waf.php';

echo "Debugging SQL injection detection with all patterns...\n";

// Test input
$test_input = "1' OR '1'='1";
echo "Test input: {$test_input}\n";

// Get all patterns from the function
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
    '/\b(?:or|and)\s+\w+\s*=\s*\w+/i',
    '/\s+or\s+.*?=\s*.*?\s+/i',
    '/\s+and\s+.*?=\s*.*?\s+/i',
    '/\'.*?\'\s+or\s+.*?=\s*.*?\s+/i',
    '/\'.*?\'\s+and\s+.*?=\s*.*?\s+/i',
    '/\d+\'.*?\'\s+or\s+.*?=\s*.*?\s+/i',
    '/\d+\'.*?\'\s+and\s+.*?=\s*.*?\s+/i'
);

$matched_pattern = null;
foreach ($patterns as $index => $pattern) {
    $matches = preg_match($pattern, $test_input);
    echo "Pattern {$index} ({$pattern}): " . ($matches ? 'MATCH' : 'NO MATCH') . "\n";
    if ($matches) {
        $matched_pattern = $pattern;
    }
}

echo "Matched pattern: " . ($matched_pattern ?: 'None') . "\n";

// Test with actual function
$sql_test = array('id' => $test_input);
$sql_result = gojs_waf_check_sql_injection($sql_test, array(), '');
echo "Function result: " . ($sql_result ? 'true' : 'false') . "\n";

// Test other SQL injection patterns
$other_tests = array(
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

echo "Debug completed!\n";