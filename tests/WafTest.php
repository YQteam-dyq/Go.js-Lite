<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../backend/waf.php';

class WafTest extends TestCase
{
    private $testIp = '192.168.1.100';
    private $testCidr = '192.168.1.0/24';
    private $testCountry = 'CN';

    protected function setUp(): void
    {
        $this->clearTestFiles();
    }

    protected function tearDown(): void
    {
        $this->clearTestFiles();
    }

    private function clearTestFiles()
    {
        $files = [
            CONFIG_DIR . '/waf_ip_rules.json',
            CONFIG_DIR . '/waf_rules.json',
            CONFIG_DIR . '/waf_rate_limits.json',
            CONFIG_DIR . '/waf_geo_blocks.json',
            CONFIG_DIR . '/ip_geo_cache.json',
            CONFIG_DIR . '/waf_attack_log.txt'
        ];
        
        foreach ($files as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        
        if (is_dir(CONFIG_DIR) && count(scandir(CONFIG_DIR)) === 2) {
            @rmdir(CONFIG_DIR);
        }
    }

    public function testIpBlacklistFunctionality()
    {
        $this->assertTrue(gojs_waf_add_ip_rule($this->testIp, 'block'));
        $this->assertTrue(gojs_waf_is_ip_blocked($this->testIp));
        $this->assertFalse(gojs_waf_is_ip_allowed($this->testIp));
        
        $this->assertTrue(gojs_waf_add_ip_rule($this->testCidr, 'allow'));
        $this->assertTrue(gojs_waf_is_ip_allowed($this->testIp));
        
        $this->assertTrue(gojs_waf_remove_ip_rule($this->testIp));
        $this->assertFalse(gojs_waf_is_ip_blocked($this->testIp));
    }

    public function testCidrMatching()
    {
        gojs_waf_add_ip_rule($this->testCidr, 'block');
        
        $this->assertTrue(gojs_waf_is_ip_blocked('192.168.1.1'));
        $this->assertTrue(gojs_waf_is_ip_blocked('192.168.1.255'));
        $this->assertFalse(gojs_waf_is_ip_blocked('192.168.2.1'));
        $this->assertFalse(gojs_waf_is_ip_blocked('10.0.0.1'));
    }

    public function testSqlInjectionDetection()
    {
        $sqlInjectionTests = [
            ['id' => "1' OR '1'='1", 'expected' => true],
            ['id' => "1; DROP TABLE users", 'expected' => true],
            ['id' => "1 UNION SELECT * FROM users", 'expected' => true],
            ['id' => "1' AND 1=1--", 'expected' => true],
            ['id' => "1' WAITFOR DELAY '0:0:5'--", 'expected' => true],
            ['id' => "normal input", 'expected' => false],
            ['id' => "123", 'expected' => false],
            ['id' => "test@example.com", 'expected' => false]
        ];
        
        foreach ($sqlInjectionTests as $test) {
            $result = gojs_waf_check_sql_injection(['id' => $test['id']], [], '');
            $this->assertEquals($test['expected'], $result, "SQL injection detection failed for: " . $test['id']);
        }
    }

    public function testXssDetection()
    {
        $xssTests = [
            ['input' => '<script>alert("xss")</script>', 'expected' => true],
            ['input' => '<iframe src="evil.com"></iframe>', 'expected' => true],
            ['input' => 'javascript:alert("xss")', 'expected' => true],
            ['input' => 'onload=alert("xss")', 'expected' => true],
            ['input' => '<img src=x onerror=alert("xss")>', 'expected' => true],
            ['input' => '<?php system("id")?>', 'expected' => true],
            ['input' => 'normal text', 'expected' => false],
            ['input' => 'Hello World', 'expected' => false],
            ['input' => 'user@example.com', 'expected' => false]
        ];
        
        foreach ($xssTests as $test) {
            $result = gojs_waf_check_xss(['input' => $test['input']], [], '');
            $this->assertEquals($test['expected'], $result, "XSS detection failed for: " . $test['input']);
        }
    }

    public function testCommandInjectionDetection()
    {
        $commandInjectionTests = [
            ['input' => 'test; rm -rf /', 'expected' => true],
            ['input' => 'test | cat /etc/passwd', 'expected' => true],
            ['input' => 'test && rm -rf /', 'expected' => true],
            ['input' => 'test || rm -rf /', 'expected' => true],
            ['input' => 'test $(whoami)', 'expected' => true],
            ['input' => 'test `whoami`', 'expected' => true],
            ['input' => 'test /bin/bash', 'expected' => true],
            ['input' => 'test cmd.exe', 'expected' => true],
            ['input' => 'test powershell', 'expected' => true],
            ['input' => 'normal input', 'expected' => false],
            ['input' => 'hello world', 'expected' => false],
            ['input' => 'test123', 'expected' => false]
        ];
        
        foreach ($commandInjectionTests as $test) {
            $result = gojs_waf_check_command_injection(['input' => $test['input']], [], '');
            $this->assertEquals($test['expected'], $result, "Command injection detection failed for: " . $test['input']);
        }
    }

    public function testRateLimiting()
    {
        $this->assertFalse(gojs_waf_check_rate_limit($this->testIp));
        $this->assertFalse(gojs_waf_check_rate_limit($this->testIp));
        
        for ($i = 0; $i < 1000; $i++) {
            gojs_waf_check_rate_limit($this->testIp);
        }
        
        $this->assertTrue(gojs_waf_check_rate_limit($this->testIp));
    }

    public function testGeographicBlocking()
    {
        gojs_waf_add_geo_block($this->testCountry);
        
        $this->assertTrue(gojs_waf_check_geo_block($this->testCountry));
        $this->assertFalse(gojs_waf_check_geo_block('US'));
        
        gojs_waf_remove_geo_block($this->testCountry);
        $this->assertFalse(gojs_waf_check_geo_block($this->testCountry));
    }

    public function testRuleManagement()
    {
        $originalRules = gojs_waf_load_rules();
        
        $this->assertTrue($originalRules['sql_injection']);
        $this->assertTrue($originalRules['xss']);
        $this->assertTrue($originalRules['command_injection']);
        
        $newRules = [
            'sql_injection' => false,
            'xss' => true,
            'command_injection' => false
        ];
        
        $this->assertTrue(gojs_waf_save_rules($newRules));
        $updatedRules = gojs_waf_load_rules();
        
        $this->assertFalse($updatedRules['sql_injection']);
        $this->assertTrue($updatedRules['xss']);
        $this->assertFalse($updatedRules['command_injection']);
    }

    public function testAttackLogging()
    {
        $logFile = CONFIG_DIR . '/waf_attack_log.txt';
        
        if (file_exists($logFile)) {
            @unlink($logFile);
        }
        
        gojs_waf_log_attack('TEST_ATTACK', $this->testIp, '/test');
        
        $this->assertFileExists($logFile);
        
        $logContent = file_get_contents($logFile);
        $this->assertStringContainsString('TEST_ATTACK', $logContent);
        $this->assertStringContainsString($this->testIp, $logContent);
        $this->assertStringContainsString('/test', $logContent);
    }

    public function testFilePermissions()
    {
        gojs_waf_add_ip_rule($this->testIp, 'block');
        
        $this->assertFileExists(WAF_IP_RULES_FILE);
        
        $filePermissions = fileperms(WAF_IP_RULES_FILE);
        $this->assertEquals(0600, $filePermissions & 0777);
    }
}