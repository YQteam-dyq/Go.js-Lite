<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class SessionsKickTest extends TestCase
{
    private $sessionDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(CONFIG_DIR)) {
            @mkdir(CONFIG_DIR, 0700, true);
        }
        $this->sessionDir = CONFIG_DIR . '/sessstore-test';
        $this->removeDir();
        @mkdir($this->sessionDir, 0700, true);
        $GLOBALS['gojs_sessions_dir_override'] = $this->sessionDir;
        @unlink(CONFIG_DIR . '/session_revoked.json');
        @unlink(CONFIG_DIR . '/operation_log.json');
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
        $GLOBALS['gojs_body_override'] = null;
        $_COOKIE = array();
        $_SESSION = array();
        @session_start();
    }

    protected function tearDown(): void
    {
        @session_unset();
        @session_destroy();
        $_COOKIE = array();
        $_SESSION = array();
        $this->removeDir();
        @unlink(CONFIG_DIR . '/session_revoked.json');
        @unlink(CONFIG_DIR . '/operation_log.json');
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
        $GLOBALS['gojs_body_override'] = null;
        parent::tearDown();
    }

    private function removeDir(): void
    {
        if (!is_dir($this->sessionDir)) {
            return;
        }
        foreach ((array)glob($this->sessionDir . '/sess_*') as $f) {
            @unlink($f);
        }
        @rmdir($this->sessionDir);
    }

    private function call(callable $fn): array
    {
        $GLOBALS['gojs_defer_response'] = true;
        $GLOBALS['gojs_deferred_response'] = null;
        try {
            $fn();
        } catch (\GoJSApiResponseSent $e) {
        }
        $GLOBALS['gojs_defer_response'] = false;
        $resp = $GLOBALS['gojs_deferred_response'];
        $GLOBALS['gojs_deferred_response'] = null;
        return is_array($resp) ? $resp : array('data' => null, 'error' => null, 'status' => 0);
    }

    private function writeSessionFile(string $sid, array $payload): void
    {
        $encoded = '';
        foreach ($payload as $k => $v) {
            $encoded .= $k . '|' . serialize($v);
        }
        file_put_contents($this->sessionDir . '/sess_' . $sid, $encoded);
    }

    public function testFingerprintIsSha256Prefix(): void
    {
        $sid = 'SOME_SESSION_ID';
        $this->assertSame(substr(hash('sha256', $sid), 0, 8), gojs_sessions_fingerprint($sid));
        $this->assertSame('', gojs_sessions_fingerprint(''));
    }

    public function testKickSelfReturns409(): void
    {
        $sid = 'SELF_SESSION_' . bin2hex(random_bytes(4));
        $_COOKIE[session_name()] = $sid;
        $_SESSION = array('authenticated' => true, 'user_id' => 'u_self', 'login_at' => time());
        $GLOBALS['gojs_body_override'] = array('sid' => gojs_sessions_fingerprint($sid));

        $r = $this->call(function () { gojs_api_sessions_kick(); });
        $this->assertSame(409, $r['status']);
        $this->assertSame('cannot_kick_self', $r['error']['code']);
    }

    public function testKickSelfByPathFormReturns409(): void
    {
        $sid = 'SELF_PATH_' . bin2hex(random_bytes(4));
        $_COOKIE[session_name()] = $sid;
        $GLOBALS['gojs_body_override'] = array();

        $r = $this->call(function () use ($sid) { gojs_api_sessions_kick(gojs_sessions_fingerprint($sid)); });
        $this->assertSame(409, $r['status']);
        $this->assertSame('cannot_kick_self', $r['error']['code']);
    }

    public function testKickUnknownSidReturns404(): void
    {
        $_COOKIE[session_name()] = 'CURRENT_' . bin2hex(random_bytes(4));
        $GLOBALS['gojs_body_override'] = array('sid' => 'zzzzzzzz');

        $r = $this->call(function () { gojs_api_sessions_kick(); });
        $this->assertSame(404, $r['status']);
        $this->assertSame('session_not_found', $r['error']['code']);
    }

    public function testKickRejectsMissingSid(): void
    {
        $GLOBALS['gojs_body_override'] = array('sid' => '   ');
        $r = $this->call(function () { gojs_api_sessions_kick(); });
        $this->assertSame(400, $r['status']);
        $this->assertSame('invalid_sid', $r['error']['code']);
    }

    public function testKickKnownSessionWritesRawSidAndDeletesFile(): void
    {
        $mySid = 'ADMIN_' . bin2hex(random_bytes(4));
        $otherSid = 'VICTIM' . bin2hex(random_bytes(4));
        $_COOKIE[session_name()] = $mySid;
        $_SESSION = array('authenticated' => true, 'user_id' => 'u_admin', 'login_at' => time());
        $this->writeSessionFile($otherSid, array(
            'authenticated' => true,
            'user_id' => 'u_victim',
            'username' => 'victim',
            'user_role' => 'viewer',
            'login_at' => time() - 600,
            'last_activity' => time() - 60,
        ));
        $GLOBALS['gojs_body_override'] = array('sid' => gojs_sessions_fingerprint($otherSid));

        $r = $this->call(function () { gojs_api_sessions_kick(); });
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['success']);

        $revoked = json_decode((string)@file_get_contents(CONFIG_DIR . '/session_revoked.json'), true);
        $this->assertIsArray($revoked);
        $this->assertArrayHasKey($otherSid, $revoked, 'The blacklist must store the raw sid for gojs_session_revoked_check to match');
        $this->assertSame('u_victim', $revoked[$otherSid]['user_id']);
        $this->assertGreaterThan(time(), $revoked[$otherSid]['exp']);
        $this->assertFileDoesNotExist($this->sessionDir . '/sess_' . $otherSid);
    }

    public function testSessionsListIncludesCurrentSession(): void
    {
        $sid = 'CUR_' . bin2hex(random_bytes(4));
        $loginAt = time() - 3600;
        $_COOKIE[session_name()] = $sid;
        $_SESSION = array(
            'authenticated' => true,
            'user_id' => 'u_a1',
            'username' => 'alice',
            'user_role' => 'admin',
            'login_at' => $loginAt,
            'last_activity' => $loginAt + 60,
            'login_ip' => '10.0.0.8',
        );

        $r = $this->call(function () { gojs_api_sessions_list(); });
        $this->assertSame(200, $r['status']);
        $this->assertSame(1, $r['data']['total']);
        $row = $r['data']['sessions'][0];
        $this->assertSame(gojs_sessions_fingerprint($sid), $row['sid']);
        $this->assertTrue($row['current']);
        $this->assertSame('u_a1', $row['user_id']);
        $this->assertSame('alice', $row['username']);
        $this->assertSame($loginAt, $row['login_at']);
        $this->assertSame('10.0.0.8', $row['ip']);
        $this->assertFalse($row['token_session']);
    }

    public function testSessionsListIncludesScannedSessionFile(): void
    {
        $curSid = 'CUR2_' . bin2hex(random_bytes(4));
        $otherSid = 'OTHER' . bin2hex(random_bytes(4));
        $_COOKIE[session_name()] = $curSid;
        $_SESSION = array('authenticated' => true, 'user_id' => 'u_me', 'login_at' => time(), 'last_activity' => time());
        $this->writeSessionFile($otherSid, array(
            'authenticated' => true,
            'user_id' => 'u_b1',
            'username' => 'bob',
            'user_role' => 'operator',
            'login_at' => time() - 120,
            'last_activity' => time() - 30,
        ));

        $r = $this->call(function () { gojs_api_sessions_list(); });
        $this->assertSame(200, $r['status']);
        $this->assertSame(2, $r['data']['total']);
        $mine = null;
        $other = null;
        foreach ($r['data']['sessions'] as $row) {
            if ($row['current']) $mine = $row;
            else $other = $row;
        }
        $this->assertNotNull($mine);
        $this->assertNotNull($other);
        $this->assertSame('u_b1', $other['user_id']);
        $this->assertSame('bob', $other['username']);
        $this->assertSame('operator', $other['role']);
        $this->assertFalse($other['current']);
    }

    public function testSessionsListIgnoresUnauthenticatedSessionFiles(): void
    {
        $_COOKIE[session_name()] = 'CUR3_' . bin2hex(random_bytes(4));
        $_SESSION = array('authenticated' => true, 'user_id' => 'u_me3');
        $this->writeSessionFile('GUEST' . bin2hex(random_bytes(4)), array(
            'last_activity' => time(),
        ));

        $r = $this->call(function () { gojs_api_sessions_list(); });
        $this->assertSame(1, $r['data']['total']);
    }
}
