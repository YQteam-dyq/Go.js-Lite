<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class PreferencesTest extends TestCase
{
    private $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = rtrim(sys_get_temp_dir(), '/\\') . '/gojs-pref-' . bin2hex(random_bytes(4));
        if (!is_dir($this->tmpDir)) {
            @mkdir($this->tmpDir, 0700, true);
        }
        if (!defined('CONFIG_DIR')) {
            define('CONFIG_DIR', $this->tmpDir);
        }
        if (!defined('CONFIG_FILE')) {
            define('CONFIG_FILE', CONFIG_DIR . '/config.php');
        }

        $_SESSION = array();
        @unlink(CONFIG_DIR . '/users.json');
        $GLOBALS['config'] = array();
    }

    protected function tearDown(): void
    {
        @unlink(CONFIG_DIR . '/users.json');
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    private function seedUser(string $id, string $username, array $prefs = array()): array
    {
        return array(
            'id' => $id,
            'username' => $username,
            'password_hash' => password_hash('pw123', PASSWORD_BCRYPT),
            'role' => 'admin',
            'path_allowlist' => array(),
            'disabled' => false,
            'created_at' => time(),
            'last_login_at' => 0,
            'password_changed_at' => time(),
            'password_expires_at' => time() + 90 * 86400,
            'failed_attempts' => 0,
            'lockout_until' => 0,
            'avatar_color' => '#3b82f6',
            'preferences' => $prefs,
        );
    }

    public function testMigrateSeedsDefaultsWhenPreferencesMissing(): void
    {
        $u = $this->seedUser('u_x', 'alice');
        gojs_users_save(array('users' => array($u)));

        $loaded = gojs_users_find_by_id('u_x');
        unset($loaded['preferences']);
        gojs_users_save(array('users' => array($loaded)));

        $GLOBALS['config'] = array('session_timeout' => 1800, 'log_retention' => 500);
        $prefs = gojs_preferences_migrate_if_needed(gojs_users_find_by_id('u_x'), $GLOBALS['config']);

        $this->assertSame('system', $prefs['theme']);
        $this->assertSame('zh', $prefs['language']);
        $this->assertSame(1800, $prefs['sessionTimeout']);
        $this->assertSame(500, $prefs['logRetention']);
        $this->assertArrayHasKey('notifications', $prefs);
    }

    public function testMigrateDoesNotOverwriteExistingPreferences(): void
    {
        $existing = array(
            'theme' => 'dark',
            'language' => 'en',
            'sessionTimeout' => 3600,
            'logRetention' => 100,
            'dashboardLayout' => 'compact',
        );
        $u = $this->seedUser('u_x', 'alice', $existing);
        gojs_users_save(array('users' => array($u)));

        $GLOBALS['config'] = array('session_timeout' => 1800);
        $prefs = gojs_preferences_migrate_if_needed(gojs_users_find_by_id('u_x'), $GLOBALS['config']);

        $this->assertSame('dark', $prefs['theme']);
        $this->assertSame('en', $prefs['language']);
        $this->assertSame(3600, $prefs['sessionTimeout']);
        $this->assertSame(100, $prefs['logRetention']);
        $this->assertSame('compact', $prefs['dashboardLayout']);
    }

    public function testMigrateCopiesLegacySessionSettings(): void
    {
        $u = $this->seedUser('u_x', 'alice');
        gojs_users_save(array('users' => array($u)));

        $_SESSION['settings'] = array(
            'theme' => 'dark',
            'language' => 'en',
            'sessionTimeout' => 2400,
            'logRetention' => 200,
        );
        $GLOBALS['config'] = array('session_timeout' => 1800);
        $prefs = gojs_preferences_migrate_if_needed(gojs_users_find_by_id('u_x'), $GLOBALS['config']);

        $this->assertSame('dark', $prefs['theme']);
        $this->assertSame('en', $prefs['language']);
        $this->assertSame(2400, $prefs['sessionTimeout']);
        $this->assertSame(200, $prefs['logRetention']);
        $this->assertArrayNotHasKey('settings', $_SESSION, 'legacy session.settings should be cleared');
    }

    public function testDefaultPreferencesHasNotificationsBlock(): void
    {
        $GLOBALS['config'] = array();
        $prefs = gojs_default_preferences($GLOBALS['config']);
        $this->assertArrayHasKey('notifications', $prefs);
        $this->assertArrayHasKey('email', $prefs['notifications']);
        $this->assertArrayHasKey('inapp', $prefs['notifications']);
        $this->assertSame('info', $prefs['notifications']['email']['severity_min']);
        $this->assertSame('info', $prefs['notifications']['inapp']['severity_min']);
    }

    public function testDefaultPreferencesAreRoleAware(): void
    {
        $GLOBALS['config'] = array();
        $admin = gojs_default_preferences($GLOBALS['config'], 'admin');
        $viewer = gojs_default_preferences($GLOBALS['config'], 'viewer');
        $operator = gojs_default_preferences($GLOBALS['config'], 'operator');

        $this->assertSame('info', $admin['notifications']['email']['severity_min']);
        $this->assertSame('off', $viewer['notifications']['email']['severity_min']);
        $this->assertSame('off', $operator['notifications']['inapp']['severity_min']);
    }
}
