<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class NotificationPrefsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(CONFIG_DIR)) {
            @mkdir(CONFIG_DIR, 0700, true);
        }
        @unlink(CONFIG_DIR . '/users.json');
    }

    protected function tearDown(): void
    {
        @unlink(CONFIG_DIR . '/users.json');
        parent::tearDown();
    }

    public function testDefaultOnForAdmin(): void
    {
        $d = gojs_notifications_default_for_role('admin');
        $this->assertSame('info', $d['email']['severity_min']);
        $this->assertSame('info', $d['inapp']['severity_min']);
        $this->assertSame(array(), $d['email']['categories']);
    }

    public function testDefaultOffForViewerAndOperator(): void
    {
        $this->assertSame('off', gojs_notifications_default_for_role('viewer')['email']['severity_min']);
        $this->assertSame('off', gojs_notifications_default_for_role('operator')['inapp']['severity_min']);
    }

    public function testNormalizeClampsInvalidSeverity(): void
    {
        $out = gojs_notifications_normalize(array(
            'email' => array('severity_min' => 'bogus', 'categories' => array()),
        ), 'admin');
        $this->assertSame('info', $out['email']['severity_min']);
    }

    public function testNormalizeFiltersUnknownCategories(): void
    {
        $out = gojs_notifications_normalize(array(
            'inapp' => array('severity_min' => 'critical', 'categories' => array('security', 'bogus', 'cron')),
        ), 'viewer');
        $this->assertSame(array('security', 'cron'), $out['inapp']['categories']);
        $this->assertSame('critical', $out['inapp']['severity_min']);
    }

    public function testEffectiveUsesRoleDefaultWhenUnset(): void
    {
        $user = array('id' => 'u_v', 'role' => 'viewer');
        $this->assertSame('off', gojs_notifications_effective($user)['email']['severity_min']);
    }

    public function testEffectiveUsesStoredPreferences(): void
    {
        $user = array(
            'id' => 'u_a',
            'role' => 'admin',
            'preferences' => array('notifications' => array(
                'email' => array('severity_min' => 'critical', 'categories' => array('security')),
                'inapp' => array('severity_min' => 'off', 'categories' => array()),
            )),
        );
        $eff = gojs_notifications_effective($user);
        $this->assertSame('critical', $eff['email']['severity_min']);
        $this->assertSame(array('security'), $eff['email']['categories']);
        $this->assertSame('off', $eff['inapp']['severity_min']);
    }

    public function testViewerDefaultDoesNotDeliver(): void
    {
        $user = array('id' => 'u_v', 'role' => 'viewer');
        $this->assertFalse(gojs_notifications_should_deliver($user, 'critical', 'security', 'email'));
        $this->assertFalse(gojs_notifications_should_deliver($user, 'critical', 'security', 'inapp'));
    }

    public function testAdminDeliversAtOrAboveMinimum(): void
    {
        $user = array('id' => 'u_a', 'role' => 'admin');
        $this->assertTrue(gojs_notifications_should_deliver($user, 'info', 'system', 'email'));
        $this->assertTrue(gojs_notifications_should_deliver($user, 'critical', 'system', 'email'));
        $this->assertFalse(gojs_notifications_should_deliver($user, 'debug', 'system', 'email'));
    }

    public function testCategoryFilterApplies(): void
    {
        $user = array(
            'id' => 'u_a',
            'role' => 'admin',
            'preferences' => array('notifications' => array(
                'email' => array('severity_min' => 'info', 'categories' => array('security')),
                'inapp' => array('severity_min' => 'info', 'categories' => array()),
            )),
        );
        $this->assertTrue(gojs_notifications_should_deliver($user, 'info', 'security', 'email'));
        $this->assertFalse(gojs_notifications_should_deliver($user, 'info', 'backup', 'email'));
    }

    public function testSeverityOrdering(): void
    {
        $this->assertGreaterThan(gojs_notifications_severity_rank('info'), gojs_notifications_severity_rank('critical'));
        $this->assertGreaterThan(gojs_notifications_severity_rank('warning'), gojs_notifications_severity_rank('critical'));
        $this->assertGreaterThan(gojs_notifications_severity_rank('info'), gojs_notifications_severity_rank('warning'));
        $this->assertSame(0, gojs_notifications_severity_rank('off'));
    }

    public function testDefaultPreferencesAreRoleAware(): void
    {
        $config = array();
        $this->assertSame('info', gojs_default_preferences($config, 'admin')['notifications']['email']['severity_min']);
        $this->assertSame('off', gojs_default_preferences($config, 'viewer')['notifications']['email']['severity_min']);
    }
}
