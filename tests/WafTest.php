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
            ['id' => "1' or '1'='1", 'expected' => true],
            ['id' => "1 or 1=1", 'expected' => true],
            ['id' => "' or 1=1#", 'expected' => true],
            ['id' => "1; DROP TABLE users", 'expected' => true],
            ['id' => "1 UNION SELECT * FROM users", 'expected' => true],
            ['id' => "1' AND 1=1--", 'expected' => true],
            ['id' => "1' WAITFOR DELAY '0:0:5'--", 'expected' => true],
            ['id' => "admin'--", 'expected' => true],
            ['id' => "Robert'); DROP TABLE students;--", 'expected' => true],
            ['id' => "1 union select password from users", 'expected' => true],
            ['id' => "id=1 and 1=1", 'expected' => true],
            ['id' => "normal input", 'expected' => false],
            ['id' => "123", 'expected' => false],
            ['id' => "test@example.com", 'expected' => false],
            ['id' => "Hello World", 'expected' => false],
            ['id' => "O'Brien", 'expected' => false],
            ['id' => "tea and coffee", 'expected' => false],
            ['id' => "tomato or potato", 'expected' => false]
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
        $this->assertFalse(gojs_waf_is_country_blocked($this->testCountry));

        $this->assertTrue(gojs_waf_add_geo_block(strtolower($this->testCountry)));
        $this->assertTrue(gojs_waf_is_country_blocked($this->testCountry));
        $this->assertTrue(gojs_waf_is_country_blocked('cn'));
        $this->assertFalse(gojs_waf_is_country_blocked('US'));
        $this->assertFalse(gojs_waf_is_country_blocked('not-a-code'));
        $this->assertFalse(gojs_waf_is_country_blocked(''));

        $this->assertFalse(gojs_waf_check_geo_block('203.0.113.10'));

        $cacheFile = CONFIG_DIR . '/ip_geo_cache.json';
        $cacheRaw = file_exists($cacheFile) ? (string) file_get_contents($cacheFile) : '';
        $this->assertStringNotContainsString('unknown', $cacheRaw);

        file_put_contents($cacheFile, json_encode(['198.51.100.7' => 'cn']));
        $this->assertTrue(gojs_waf_check_geo_block('198.51.100.7'));

        $this->assertFalse(gojs_waf_check_geo_block('not-an-ip'));

        $this->assertTrue(gojs_waf_remove_geo_block($this->testCountry));
        $this->assertFalse(gojs_waf_is_country_blocked($this->testCountry));
        $this->assertFalse(gojs_waf_check_geo_block('198.51.100.7'));

        $this->assertFalse(gojs_waf_add_geo_block('invalid'));
        $this->assertFalse(gojs_waf_remove_geo_block('invalid'));
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
        gojs_waf_save_rules([
            'sql_injection' => true,
            'xss' => true,
            'command_injection' => true
        ]);
        gojs_waf_save_geo_blocks([$this->testCountry]);
        gojs_waf_check_rate_limit($this->testIp);
        gojs_waf_log_attack('TEST_ATTACK', $this->testIp, '/test');

        $files = [
            WAF_IP_RULES_FILE,
            WAF_RULES_FILE,
            WAF_GEO_BLOCK_FILE,
            WAF_RATE_LIMIT_FILE,
            WAF_ATTACK_LOG_FILE
        ];

        foreach ($files as $file) {
            $this->assertFileExists($file);
            $this->assertEquals(0600, fileperms($file) & 0777, $file);
        }
    }

    public function testRuleConfigurationControlsDetection()
    {
        $wafPath = realpath(__DIR__ . '/../backend/waf.php');
        $this->assertNotFalse($wafPath);

        $probe = 'define("ROOT", ' . var_export(dirname(__DIR__), true) . ');'
            . 'define("CONFIG_DIR", ' . var_export(CONFIG_DIR, true) . ');'
            . 'require ' . var_export($wafPath, true) . ';'
            . '$_SERVER["REMOTE_ADDR"] = "192.0.2.55";'
            . '$_SERVER["REQUEST_URI"] = "/index.php";'
            . '$_SERVER["REQUEST_METHOD"] = "GET";'
            . '$_POST = array();'
            . '$_GET = array("id" => "1 OR 1=1");'
            . 'gojs_waf_check_request();'
            . 'echo "ALLOWED";';

        gojs_waf_save_rules([
            'sql_injection' => false,
            'xss' => true,
            'command_injection' => true
        ]);

        $output = $this->runProbe($probe);
        $this->assertSame('ALLOWED', $output);

        gojs_waf_save_rules([
            'command_injection' => false
        ]);

        $output = $this->runProbe($probe);
        $this->assertStringNotContainsString('ALLOWED', $output);
        $this->assertStringContainsString('SQL injection detected', $output);

        gojs_waf_save_rules([
            'sql_injection' => true,
            'xss' => true,
            'command_injection' => true
        ]);

        $output = $this->runProbe($probe);
        $this->assertStringNotContainsString('ALLOWED', $output);
        $this->assertStringContainsString('SQL injection detected', $output);
    }

    private function runProbe($code)
    {
        $lines = array();
        exec(PHP_BINARY . ' -r ' . escapeshellarg($code) . ' 2>&1', $lines);

        return trim(implode("\n", $lines));
    }

    public function testWafModuleDoesNotRedefineSharedConstants()
    {
        $wafPath = realpath(__DIR__ . '/../backend/waf.php');
        $this->assertNotFalse($wafPath);

        $probe = 'error_reporting(E_ALL); ini_set("display_errors", "1");'
            . 'define("ROOT", sys_get_temp_dir());'
            . 'define("CONFIG_DIR", sys_get_temp_dir() . "/gojs-waf-guard");'
            . 'require ' . var_export($wafPath, true) . ';'
            . 'echo "CLEAN";';

        $lines = array();
        exec(PHP_BINARY . ' -r ' . escapeshellarg($probe) . ' 2>&1', $lines, $rc);
        $output = trim(implode("\n", $lines));

        $this->assertSame('CLEAN', $output, $output);
        $this->assertSame(0, $rc, $output);
    }
}