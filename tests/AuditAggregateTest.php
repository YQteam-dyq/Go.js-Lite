<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class AuditAggregateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(CONFIG_DIR)) {
            @mkdir(CONFIG_DIR, 0700, true);
        }
        @unlink(CONFIG_DIR . '/operation_log.json');
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
        $_GET = array();
        $_SESSION = array();
    }

    protected function tearDown(): void
    {
        @unlink(CONFIG_DIR . '/operation_log.json');
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
        $_GET = array();
        parent::tearDown();
    }

    private function seed(): void
    {
        $now = time();
        $entries = array(
            array('timestamp' => $now - 60, 'action' => 'file.save', 'target' => '/a', 'result' => true, 'user_id' => 'u_a'),
            array('timestamp' => $now - 120, 'action' => 'file.save', 'target' => '/b', 'result' => false, 'user_id' => 'u_a'),
            array('timestamp' => $now - 180, 'action' => 'db.sql', 'target' => 'SELECT 1', 'result' => true, 'user_id' => 'u_b'),
            array('timestamp' => $now - 200 * 86400, 'action' => 'old.entry', 'target' => 'x', 'result' => true, 'user_id' => 'u_a'),
        );
        @file_put_contents(CONFIG_DIR . '/operation_log.json', json_encode($entries));
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

    public function testFeedFiltersByUser(): void
    {
        $this->seed();
        $rows = gojs_user_activity_feed('u_a', null, 100);
        $this->assertCount(3, $rows);
        foreach ($rows as $r) {
            $this->assertSame('u_a', $r['user_id']);
        }
        $this->assertCount(0, gojs_user_activity_feed('u_missing', null, 100));
    }

    public function testFeedRespectsSinceAndLimit(): void
    {
        $this->seed();
        $this->assertCount(3, gojs_user_activity_feed(null, time() - 86400, 100));
        $this->assertCount(1, gojs_user_activity_feed(null, time() - 86400, 1));
    }

    public function testAggregateByUserComputesFailuresAndErrorRate(): void
    {
        $this->seed();
        $agg = gojs_user_activity_aggregate(time() - 86400, 'user_id');
        $this->assertSame(3, $agg['total']);
        $this->assertSame(1, $agg['failed']);
        $byKey = array();
        foreach ($agg['rows'] as $r) {
            $byKey[$r['key']] = $r;
        }
        $this->assertSame(2, $byKey['u_a']['total']);
        $this->assertSame(1, $byKey['u_a']['failed']);
        $this->assertEqualsWithDelta(0.5, $byKey['u_a']['error_rate'], 0.0001);
        $this->assertSame(1, $byKey['u_b']['total']);
        $this->assertSame(0.0, $byKey['u_b']['error_rate']);
    }

    public function testAggregateByActionAndHour(): void
    {
        $this->seed();
        $byAction = gojs_user_activity_aggregate(time() - 86400, 'action');
        $keys = array();
        foreach ($byAction['rows'] as $r) {
            $keys[$r['key']] = $r['total'];
        }
        $this->assertSame(2, $keys['file.save']);
        $this->assertSame(1, $keys['db.sql']);

        $byHour = gojs_user_activity_aggregate(time() - 86400, 'hour');
        $this->assertCount(1, $byHour['rows']);
        $this->assertSame(3, $byHour['rows'][0]['total']);
    }

    public function testAggregateEmptyLog(): void
    {
        $agg = gojs_user_activity_aggregate(null, 'user_id');
        $this->assertSame(0, $agg['total']);
        $this->assertSame(array(), $agg['rows']);
    }

    public function testAuditAggregateApiRejectsInvalidBy(): void
    {
        $_GET['by'] = 'bogus';
        $r = $this->call(function () { gojs_api_audit_aggregate(); });
        $this->assertSame(400, $r['status']);
        $this->assertSame('invalid_by', $r['error']['code']);
    }

    public function testAuditAggregateApiShape(): void
    {
        $this->seed();
        $_GET = array('since' => '24h', 'by' => 'user_id');
        $r = $this->call(function () { gojs_api_audit_aggregate(); });
        $this->assertSame(200, $r['status']);
        $this->assertSame('user_id', $r['data']['by']);
        $this->assertSame(3, $r['data']['total']);
        $this->assertSame(1, $r['data']['failed']);
        $this->assertCount(2, $r['data']['rows']);
    }

    public function testUserActivityRecentApiShape(): void
    {
        $this->seed();
        $r = $this->call(function () { gojs_api_user_activity_recent(); });
        $this->assertSame(200, $r['status']);
        $this->assertSame(3, $r['data']['total']);
        $this->assertSame('24h', $r['data']['since']);
    }

    public function testUserActivityUserFeedApiShape(): void
    {
        $this->seed();
        $_GET = array('since' => '1d', 'limit' => 10);
        $r = $this->call(function () { gojs_api_user_activity_user('u_b'); });
        $this->assertSame(200, $r['status']);
        $this->assertSame('u_b', $r['data']['user_id']);
        $this->assertSame(1, $r['data']['count']);
        $this->assertSame('db.sql', $r['data']['entries'][0]['action']);
    }

    public function testUserActivityRouteRejectsNonGet(): void
    {
        $r = $this->call(function () { gojs_api_user_activity_route('user_activity/recent', 'POST'); });
        $this->assertSame(405, $r['status']);
        $this->assertSame('method_not_allowed', $r['error']['code']);
    }
}
