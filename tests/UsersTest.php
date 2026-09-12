<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class UsersTest extends TestCase
{

    private $tmpConfigDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpConfigDir = rtrim(sys_get_temp_dir(), '/\\') . '/gojs-users-' . bin2hex(random_bytes(4));
        if (!is_dir($this->tmpConfigDir)) {
            @mkdir($this->tmpConfigDir, 0700, true);
        }
        if (!defined('CONFIG_DIR')) {
            define('CONFIG_DIR', $this->tmpConfigDir);
        }
        if (!defined('CONFIG_FILE')) {
            define('CONFIG_FILE', CONFIG_DIR . '/config.php');
        }

        @unlink(CONFIG_DIR . '/users.json');
        @unlink(CONFIG_DIR . '/session_revoked.json');
    }

    protected function tearDown(): void
    {
        @unlink(CONFIG_DIR . '/users.json');
        @unlink(CONFIG_DIR . '/session_revoked.json');
        @rmdir($this->tmpConfigDir);
        parent::tearDown();
    }

    private function makeAdmin(string $id = 'u_admin', string $username = 'admin'): array
    {
        return array(
            'id' => $id,
            'username' => $username,
            'password_hash' => password_hash('admin123', PASSWORD_BCRYPT),
            'role' => 'admin',
            'path_allowlist' => array(),
            'disabled' => false,
            'created_at' => time(),
            'last_login_at' => 0,
            'password_changed_at' => time(),
            'password_expires_at' => time() + 90 * 86400,
            'failed_attempts' => 0,
            'lockout_until' => 0,
            'avatar_color' => gojs_users_avatar_color($username),
        );
    }

    public function testEnsureDefaultMirrorsConfigAdmin(): void
    {
        $GLOBALS['config'] = array(
            'password_hash' => password_hash('admin123', PASSWORD_BCRYPT),
        );
        $store = gojs_users_ensure_default();
        $hasAdmin = false;
        foreach ($store['users'] as $u) {
            if ($u['role'] === 'admin' && $u['username'] === 'admin') {
                $hasAdmin = true;
                break;
            }
        }
        $this->assertTrue($hasAdmin, 'admin should be mirrored from config.php');
    }

    public function testEnsureDefaultDoesNotDuplicate(): void
    {
        $GLOBALS['config'] = array(
            'password_hash' => password_hash('admin123', PASSWORD_BCRYPT),
        );
        $store1 = gojs_users_ensure_default();
        $store2 = gojs_users_ensure_default();
        $this->assertCount(count($store1['users']), $store2['users']);
    }

    public function testLoadSaveRoundtrip(): void
    {
        $store = array('users' => array($this->makeAdmin()));
        $this->assertTrue(gojs_users_save($store));
        $loaded = gojs_users_load();
        $this->assertCount(1, $loaded['users']);
        $this->assertSame('admin', $loaded['users'][0]['username']);
    }

    public function testFindAndFindById(): void
    {
        gojs_users_save(array('users' => array($this->makeAdmin('u_a1', 'alice'))));
        $byName = gojs_users_find('alice');
        $this->assertNotNull($byName);
        $this->assertSame('u_a1', $byName['id']);

        $byId = gojs_users_find_by_id('u_a1');
        $this->assertSame('alice', $byId['username']);

        $this->assertNull(gojs_users_find('nobody'));
        $this->assertNull(gojs_users_find_by_id('u_nope'));
    }

    public function testUpsertInsertsAndUpdates(): void
    {
        gojs_users_save(array('users' => array()));
        $u = $this->makeAdmin('u_x', 'bob');
        gojs_users_upsert($u);
        $this->assertSame('bob', gojs_users_find_by_id('u_x')['username']);

        $u['role'] = 'operator';
        gojs_users_upsert($u);
        $this->assertSame('operator', gojs_users_find_by_id('u_x')['role']);
    }

    public function testDeleteRemovesUser(): void
    {
        gojs_users_save(array('users' => array($this->makeAdmin('u_d', 'tobedel'))));
        $this->assertTrue(gojs_users_delete('u_d'));
        $this->assertNull(gojs_users_find_by_id('u_d'));
    }

    public function testAvatarColorIsStableAndFromPalette(): void
    {
        $c1 = gojs_users_avatar_color('alice');
        $c2 = gojs_users_avatar_color('alice');
        $this->assertSame($c1, $c2);
        $this->assertContains($c1, gojs_users_avatar_palette());
    }

    public function testPasswordPolicyRejectsShort(): void
    {
        $this->assertSame('weak_too_short', gojs_users_password_check_policy('1a'));
    }

    public function testPasswordPolicyRejectsNoDigit(): void
    {
        $this->assertSame('weak_no_digit', gojs_users_password_check_policy('abcdefgh'));
    }

    public function testPasswordPolicyRejectsNoLetter(): void
    {
        $this->assertSame('weak_no_letter', gojs_users_password_check_policy('12345678'));
    }

    public function testPasswordPolicyRejectsCommon(): void
    {
        $this->assertSame('weak_common_password', gojs_users_password_check_policy('password1'));
    }

    public function testPasswordPolicyAcceptsStrong(): void
    {
        $this->assertTrue(gojs_users_password_check_policy('Strong123'));
    }

    public function testLockoutCheckLocksUntilFuture(): void
    {
        $user = array('lockout_until' => time() + 600, 'failed_attempts' => 5);
        $r = gojs_users_lockout_check($user);
        $this->assertTrue($r['locked']);
        $this->assertGreaterThan(0, $r['retry_after']);
    }

    public function testLockoutCheckUnlocksAfterExpiry(): void
    {
        $user = array('lockout_until' => time() - 1);
        $r = gojs_users_lockout_check($user);
        $this->assertFalse($r['locked']);
        $this->assertSame(0, $r['retry_after']);
    }

    public function testCurrentUserIdFallbackForAdmin(): void
    {
        $_SESSION = array('authenticated' => true);
        $id = gojs_current_user_id();
        $this->assertSame('admin', $id);
    }

    public function testCurrentRoleRankOrdering(): void
    {
        $this->assertSame(1, gojs_role_rank('viewer'));
        $this->assertSame(2, gojs_role_rank('operator'));
        $this->assertSame(3, gojs_role_rank('admin'));
        $this->assertSame(0, gojs_role_rank('unknown'));
    }

    public function testUserTotpDefaultsToConfigFallback(): void
    {
        $GLOBALS['config'] = array('totp' => array('enabled' => true, 'secret_enc' => 's'));
        $_SESSION = array();
        $totp = gojs_user_totp_get();
        $this->assertTrue(!empty($totp['enabled']));
        $this->assertSame('s', $totp['secret_enc']);
    }
}
