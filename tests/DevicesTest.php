<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class DevicesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(CONFIG_DIR)) {
            @mkdir(CONFIG_DIR, 0700, true);
        }
        @unlink(CONFIG_DIR . '/users.json');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/1.0 (TestDevice)';
    }

    protected function tearDown(): void
    {
        @unlink(CONFIG_DIR . '/users.json');
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
        parent::tearDown();
    }

    private function seedUser(string $id = 'u_dev'): array
    {
        $user = array(
            'id' => $id,
            'username' => 'dev-' . $id,
            'role' => 'viewer',
            'path_allowlist' => array(),
            'disabled' => false,
            'created_at' => time(),
        );
        gojs_users_upsert($user);
        return $user;
    }

    public function testTrustCreatesDeviceWithExpiry(): void
    {
        $this->seedUser('u_dev');
        $r = gojs_devices_trust('u_dev');
        $this->assertTrue($r['ok']);
        $this->assertSame('203.0.113.7', $r['device']['ip']);
        $this->assertSame('PHPUnit/1.0 (TestDevice)', $r['device']['ua']);
        $this->assertSame(14 * 86400, $r['device']['expires_at'] - $r['device']['trusted_at']);
        $this->assertSame(64, strlen($r['device']['fingerprint']));
    }

    public function testFingerprintFormula(): void
    {
        $fp = gojs_devices_fingerprint('1.2.3.4', 'UA', 1000);
        $this->assertSame(hash('sha256', '1.2.3.4|UA|1000'), $fp);
    }

    public function testIsTrustedAfterTrust(): void
    {
        $this->seedUser('u_dev');
        $this->assertFalse(gojs_devices_is_trusted('u_dev'));
        gojs_devices_trust('u_dev');
        $this->assertTrue(gojs_devices_is_trusted('u_dev'));
    }

    public function testTrustTwiceRefreshesInsteadOfDuplicating(): void
    {
        $this->seedUser('u_dev');
        gojs_devices_trust('u_dev');
        gojs_devices_trust('u_dev');
        $this->assertCount(1, gojs_devices_list('u_dev'));
    }

    public function testRevoke(): void
    {
        $this->seedUser('u_dev');
        $trusted = gojs_devices_trust('u_dev');
        $fp = $trusted['device']['fingerprint'];

        $this->assertTrue(gojs_devices_revoke('u_dev', $fp)['ok']);
        $this->assertCount(0, gojs_devices_list('u_dev'));
        $this->assertFalse(gojs_devices_revoke('u_dev', $fp)['ok']);
        $this->assertSame('not_found', gojs_devices_revoke('u_dev', $fp)['code']);
    }

    public function testExpiredDeviceIsPruned(): void
    {
        $user = $this->seedUser('u_dev');
        $user['trusted_devices'] = array(array(
            'fingerprint' => gojs_devices_fingerprint('203.0.113.7', 'PHPUnit/1.0 (TestDevice)', time() - 2000000),
            'ip' => '203.0.113.7',
            'ua' => 'PHPUnit/1.0 (TestDevice)',
            'first_seen_at' => time() - 2000000,
            'trusted_at' => time() - 2000000,
            'expires_at' => time() - 10,
        ));
        gojs_users_upsert($user);

        $this->assertCount(0, gojs_devices_list('u_dev'));
        $this->assertFalse(gojs_devices_is_trusted('u_dev'));
    }

    public function testListEmptyForUnknownUser(): void
    {
        $this->assertSame(array(), gojs_devices_list('u_missing'));
    }

    public function testTrustUnknownUserFails(): void
    {
        $r = gojs_devices_trust('u_missing');
        $this->assertFalse($r['ok']);
        $this->assertSame('user_not_found', $r['code']);
    }
}
