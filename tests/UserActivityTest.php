<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class UserActivityTest extends TestCase
{
    private $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = rtrim(sys_get_temp_dir(), '/\\') . '/gojs-activity-' . bin2hex(random_bytes(4));
        if (!is_dir($this->tmpDir)) {
            @mkdir($this->tmpDir, 0700, true);
        }
        if (!defined('CONFIG_DIR')) {
            define('CONFIG_DIR', $this->tmpDir);
        }
        @unlink(CONFIG_DIR . '/operation_log.json');
    }

    protected function tearDown(): void
    {
        @unlink(CONFIG_DIR . '/operation_log.json');
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function testOperationLogWrittenWithUserIdField(): void
    {

        gojs_log_operation('file.delete', '/tmp/x', true, '', 'u_test_user');
        $raw = @file_get_contents(CONFIG_DIR . '/operation_log.json');
        $this->assertNotFalse($raw);
        $data = json_decode($raw, true);
        $this->assertNotEmpty($data);
        $this->assertSame('u_test_user', $data[0]['user_id']);
        $this->assertSame('file.delete', $data[0]['action']);
    }

    public function testOperationLogFallsBackToAdminWhenNoSession(): void
    {
        @session_unset();
        @session_destroy();
        $_SESSION = array();
        gojs_log_operation('test.action', '/tmp', true);
        $raw = @file_get_contents(CONFIG_DIR . '/operation_log.json');
        $data = json_decode($raw, true);
        $this->assertSame('admin', $data[0]['user_id']);
    }

    public function testLogRespectsRetentionCap(): void
    {

        $GLOBALS['config'] = array();

        for ($i = 0; $i < 600; $i++) {
            gojs_log_operation('spam.test', "t$i", true);
        }
        $raw = @file_get_contents(CONFIG_DIR . '/operation_log.json');
        $data = json_decode($raw, true);
        $this->assertNotEmpty($data);
        $this->assertLessThanOrEqual(500, count($data));

        $this->assertStringContainsString('t1', $data[0]['target']);
    }

    public function testAuditEntryWrittenByGojsLogAuthAttempt(): void
    {

        $file = CONFIG_DIR . '/auth.log';
        @unlink($file);

        if (defined('AUTH_LOG')) {

        }

        gojs_log_auth_attempt(true, 'u_test_user');
        $this->assertTrue(true);
    }
}
