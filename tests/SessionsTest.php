<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class SessionsTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/sessions_probe.php';

    public function testLogoutAllAddsSidToRevokedList(): void
    {
        $sid = 'TEST_SID_' . bin2hex(random_bytes(4));
        $res = $this->runProbe(array(
            'fn' => 'logout_all',
            'sid' => $sid,
            'session' => array(
                'authenticated' => true,
                'user_id' => 'u_a1',
                'login_at' => time(),
                'last_activity' => time(),
            ),
        ));

        list($json, $stateRaw) = $this->splitOutput($res['stdout']);
        $this->assertSame(0, $res['rc']);
        $this->assertIsArray($json, 'expected JSON array, raw=' . $res['stdout']);
        $this->assertOk($json);
        $this->assertSame(true, $json['data']['success'] ?? null);

        $this->assertNotSame('', $stateRaw, 'logout_all should write session_revoked.json');
        $revoked = json_decode($stateRaw, true);
        $this->assertIsArray($revoked);
        $this->assertArrayHasKey($sid, $revoked);
        $this->assertGreaterThan(time(), $revoked[$sid]['exp']);
        $this->assertSame('u_a1', $revoked[$sid]['user_id']);
    }

    public function testSessionsListReturnsCurrentSession(): void
    {
        $sid = 'TEST_SID_' . bin2hex(random_bytes(4));
        $loginAt = time() - 3600;
        $res = $this->runProbe(array(
            'fn' => 'sessions_list',
            'sid' => $sid,
            'session' => array(
                'authenticated' => true,
                'user_id' => 'u_a1',
                'login_at' => $loginAt,
                'last_activity' => $loginAt + 60,
            ),
        ));

        list($json) = $this->splitOutput($res['stdout']);
        $this->assertSame(0, $res['rc']);
        $this->assertOk($json);
        $this->assertSame(1, $json['data']['total'] ?? null);
        $this->assertSame('u_a1', $json['data']['sessions'][0]['user_id'] ?? null);
        $this->assertSame($loginAt, $json['data']['sessions'][0]['login_at'] ?? null);
    }

    public function testSessionsKickRejectsMissingSid(): void
    {

        $res = $this->runProbe(array(
            'fn' => 'sessions_kick',
            'sid' => 'TEST_SID_' . bin2hex(random_bytes(4)),
        ));

        list($json) = $this->splitOutput($res['stdout']);
        $this->assertSame(0, $res['rc']);
        $this->assertIsArray($json, 'expected JSON array, raw=' . $res['stdout']);
        $this->assertError($json, 'invalid_sid');
    }

    public function testRevokedCheckAllowsWhenSidNotListed(): void
    {
        $res = $this->runProbe(array(
            'fn' => 'revoked_check',
            'sid' => 'MINE',
            'revoked' => array('OTHER' => array('exp' => time() + 600)),
        ));

        $this->assertSame(0, $res['rc']);
        $this->assertStringContainsString('ALLOW', $res['stdout']);
    }

    public function testRevokedCheckAllowsWhenEntryExpired(): void
    {
        $res = $this->runProbe(array(
            'fn' => 'revoked_check',
            'sid' => 'OLD_EXPIRED',
            'revoked' => array('OLD_EXPIRED' => array('exp' => time() - 10, 'user_id' => null)),
        ));

        $this->assertSame(0, $res['rc']);
        $this->assertStringContainsString('ALLOW', $res['stdout']);
    }

    public function testRevokedCheckRejectsActiveRevokedSid(): void
    {
        $res = $this->runProbe(array(
            'fn' => 'revoked_check',
            'sid' => 'NEW_ACTIVE',
            'revoked' => array('NEW_ACTIVE' => array('exp' => time() + 600)),
        ));

        list($json) = $this->splitOutput($res['stdout']);
        $this->assertSame(0, $res['rc']);
        $this->assertIsArray($json, 'expected JSON array, raw=' . $res['stdout']);
        $this->assertError($json, 'session_revoked');
    }

    private function splitOutput(string $stdout): array
    {
        $marker = '###STATE###';
        $pos = strpos($stdout, $marker);
        $stateRaw = '';
        if ($pos === false) {
            $jsonPart = trim($stdout);
        } else {
            $jsonPart = trim(substr($stdout, 0, $pos));
            $stateB64 = trim(substr($stdout, $pos + strlen($marker)));
            if ($stateB64 !== '') {
                $stateRaw = (string)base64_decode($stateB64, true);
            }
        }
        $decoded = json_decode($jsonPart, true);
        return array(is_array($decoded) ? $decoded : null, $stateRaw);
    }

    private function runProbe(array $scenario): array
    {
        $arg = base64_encode(json_encode($scenario));
        $cmd = PHP_BINARY . ' ' . escapeshellarg(self::FIXTURE) . ' ' . escapeshellarg($arg) . ' 2>&1';
        $lines = array();
        exec($cmd, $lines, $rc);
        return array('rc' => $rc, 'stdout' => trim(implode("\n", $lines)));
    }

    private function assertOk(array $json): void
    {
        $this->assertArrayHasKey('ok', $json);
        $this->assertTrue($json['ok']);
    }

    private function assertError(array $json, string $code): void
    {
        $this->assertArrayHasKey('ok', $json);
        $this->assertFalse($json['ok']);
        $this->assertSame($code, $json['error']['code'] ?? null);
    }
}
