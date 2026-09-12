<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class OpcacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
        parent::tearDown();
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

    public function testHitRateCalculation(): void
    {
        $this->assertSame(0.75, gojs_opcache_hit_rate(75, 25));
        $this->assertSame(1.0, gojs_opcache_hit_rate(10, 0));
        $this->assertSame(0.0, gojs_opcache_hit_rate(0, 10));
    }

    public function testHitRateWithNoSamples(): void
    {
        $this->assertNull(gojs_opcache_hit_rate(0, 0));
    }

    public function testSummaryFromSyntheticStatus(): void
    {
        $status = array(
            'opcache_enabled' => true,
            'opcache_statistics' => array(
                'hits' => 900,
                'misses' => 100,
                'num_cached_scripts' => 42,
                'oom_restarts' => 1,
                'hash_restarts' => 2,
                'manual_restarts' => 3,
            ),
            'memory_usage' => array('used_memory' => 1024, 'free_memory' => 2048, 'wasted_memory' => 64),
            'jit' => array('enabled' => true, 'on' => true, 'kind' => 'tracing'),
        );
        $s = gojs_opcache_summary($status);
        $this->assertTrue($s['enabled']);
        $this->assertSame(900, $s['hits']);
        $this->assertSame(100, $s['misses']);
        $this->assertSame(0.9, $s['hit_rate']);
        $this->assertSame(42, $s['cached_scripts']);
        $this->assertSame(1, $s['oom_restarts']);
        $this->assertSame(1024, $s['used_memory']);
        $this->assertSame('tracing', $s['jit']['kind']);
    }

    public function testSummaryHandlesUnavailableStatus(): void
    {
        $s = gojs_opcache_summary(false);
        $this->assertFalse($s['enabled']);
        $this->assertNull($s['hit_rate']);
        $this->assertSame(0, $s['hits']);
    }

    public function testAvailableReturnsBool(): void
    {
        $this->assertIsBool(gojs_opcache_available());
    }

    public function testStatusApiShape(): void
    {
        $r = $this->call(function () { gojs_api_php_opcache_status(); });
        if (gojs_opcache_available()) {
            $this->assertSame(200, $r['status']);
            $this->assertArrayHasKey('summary', $r['data']);
            $this->assertArrayHasKey('config', $r['data']);
        } else {
            $this->assertSame(501, $r['status']);
            $this->assertSame('opcache_unavailable', $r['error']['code']);
            $this->assertArrayHasKey('hint', $r['error']);
        }
    }

    public function testResetApiNeverReturns200WithoutOpcache(): void
    {
        $r = $this->call(function () { gojs_api_php_opcache_reset(); });
        if (gojs_opcache_available()) {
            $this->assertContains($r['status'], array(200, 501));
        } else {
            $this->assertSame(501, $r['status']);
            $this->assertSame('opcache_unavailable', $r['error']['code']);
        }
    }

    public function testToggleRejectsInvalidState(): void
    {
        if (!gojs_opcache_available()) {
            $this->markTestSkipped('OPcache 不可用');
        }
        $GLOBALS['gojs_body_override'] = array('state' => 'maybe');
        $r = $this->call(function () { gojs_api_php_opcache_toggle(); });
        $this->assertSame(400, $r['status']);
        $this->assertSame('invalid_state', $r['error']['code']);
        $GLOBALS['gojs_body_override'] = null;
    }

    public function testProfileTargetsContainJitBaseline(): void
    {
        $targets = gojs_opcache_profile_targets();
        $this->assertSame('256', $targets['opcache.memory_consumption']);
        $this->assertSame('tracing', $targets['opcache.jit']);
        $this->assertSame('256M', $targets['opcache.jit_buffer_size']);
        $this->assertSame('1', $targets['opcache.enable']);
    }
}
