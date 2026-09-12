<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class PermissionsBoostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(CONFIG_DIR)) {
            @mkdir(CONFIG_DIR, 0700, true);
        }
        @unlink(CONFIG_DIR . '/users.json');
        $GLOBALS['config'] = array();
        $_SESSION = array();
    }

    protected function tearDown(): void
    {
        @unlink(CONFIG_DIR . '/users.json');
        $GLOBALS['config'] = array();
        $_SESSION = array();
        parent::tearDown();
    }

    public function testActionNameMapping(): void
    {
        $this->assertSame('files.upload', gojs_acl_action_name('upload'));
        $this->assertSame('files.upload', gojs_acl_action_name('upload-chunk'));
        $this->assertSame('file.delete', gojs_acl_action_name('file-delete'));
        $this->assertSame('db.execute_select', gojs_acl_action_name('db/sql'));
        $this->assertNull(gojs_acl_action_name('dashboard'));
    }

    public function testBoostGrantsOnlyListedActions(): void
    {
        gojs_users_save(array('users' => array(array(
            'id' => 'u_v',
            'username' => 'viewer-bob',
            'role' => 'viewer',
            'path_allowlist' => array('/a'),
            'permissions_boost' => array('files.upload'),
            'disabled' => false,
        ))));
        $_SESSION = array('user_id' => 'u_v', 'authenticated' => true);

        $this->assertTrue(gojs_action_allowed('files.upload'));
        $this->assertFalse(gojs_action_allowed('file.delete'));
        $this->assertFalse(gojs_action_allowed('file.save'));
    }

    public function testEmptyBoostDeniesEverything(): void
    {
        gojs_users_save(array('users' => array(array(
            'id' => 'u_v2',
            'username' => 'viewer-noboost',
            'role' => 'viewer',
            'path_allowlist' => array(),
            'permissions_boost' => array(),
            'disabled' => false,
        ))));
        $_SESSION = array('user_id' => 'u_v2', 'authenticated' => true);

        $this->assertSame(array(), gojs_user_permissions_boost());
        $this->assertFalse(gojs_action_allowed('files.upload'));
    }

    public function testBoostSurvivesUserUpdate(): void
    {
        gojs_users_save(array('users' => array(array(
            'id' => 'u_v3',
            'username' => 'viewer-upd',
            'role' => 'viewer',
            'path_allowlist' => array(),
            'permissions_boost' => array('files.upload', 'db.execute_select'),
            'disabled' => false,
        ))));
        $_SESSION = array('user_id' => 'u_v3', 'authenticated' => true);

        $boost = gojs_user_permissions_boost();
        $this->assertContains('files.upload', $boost);
        $this->assertContains('db.execute_select', $boost);
    }
}
