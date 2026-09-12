<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class QuotaTest extends TestCase
{
    private $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = rtrim(sys_get_temp_dir(), '/\\') . '/gojs-quota-' . bin2hex(random_bytes(4));
        if (!is_dir($this->tmpDir)) {
            @mkdir($this->tmpDir, 0700, true);
        }
        if (!defined('CONFIG_DIR')) {
            define('CONFIG_DIR', $this->tmpDir);
        }
    }

    protected function tearDown(): void
    {

        $entries = glob(CONFIG_DIR . '/quota/*.json');
        if (is_array($entries)) {
            foreach ($entries as $f) @unlink($f);
        }
        @rmdir(CONFIG_DIR . '/quota');
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function testAdminNeverLimited(): void
    {
        $admin = array('id' => 'u_a', 'role' => 'admin');
        for ($i = 0; $i < 100; $i++) {
            $r = gojs_quota_check($admin, 'GET');
            $this->assertTrue($r['allow']);
        }
        for ($i = 0; $i < 100; $i++) {
            $r = gojs_quota_check($admin, 'POST');
            $this->assertTrue($r['allow']);
        }
    }

    public function testViewerWriteLimitIsOnePerMinute(): void
    {
        $v = array('id' => 'u_v', 'role' => 'viewer');
        $r = gojs_quota_check($v, 'POST');
        $this->assertTrue($r['allow']);

        $r = gojs_quota_check($v, 'POST');
        $this->assertFalse($r['allow']);
        $this->assertGreaterThan(0, $r['reset_in']);
    }

    public function testViewerReadLimitIsFivePerMinute(): void
    {
        $v = array('id' => 'u_v', 'role' => 'viewer');
        for ($i = 0; $i < 5; $i++) {
            $r = gojs_quota_check($v, 'GET');
            $this->assertTrue($r['allow']);
        }
        $r = gojs_quota_check($v, 'GET');
        $this->assertFalse($r['allow']);
    }

    public function testOperatorReadLimitIs30PerMinute(): void
    {
        $op = array('id' => 'u_o', 'role' => 'operator');
        for ($i = 0; $i < 30; $i++) {
            $r = gojs_quota_check($op, 'GET');
            $this->assertTrue($r['allow']);
        }
        $r = gojs_quota_check($op, 'GET');
        $this->assertFalse($r['allow']);
    }

    public function testOperatorWriteLimitIs5PerMinute(): void
    {
        $op = array('id' => 'u_o', 'role' => 'operator');
        for ($i = 0; $i < 5; $i++) {
            $r = gojs_quota_check($op, 'POST');
            $this->assertTrue($r['allow']);
        }
        $r = gojs_quota_check($op, 'POST');
        $this->assertFalse($r['allow']);
    }

    public function testQuotaIsPerUser(): void
    {
        $alice = array('id' => 'u_alice', 'role' => 'viewer');
        $bob   = array('id' => 'u_bob',   'role' => 'viewer');

        $r = gojs_quota_check($alice, 'POST');
        $this->assertTrue($r['allow']);
        $r = gojs_quota_check($alice, 'POST');
        $this->assertFalse($r['allow']);

        $r = gojs_quota_check($bob, 'POST');
        $this->assertTrue($r['allow']);
    }
}
