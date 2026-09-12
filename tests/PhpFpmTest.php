<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class PhpFpmTest extends TestCase
{
    private $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
        $this->tmp = CONFIG_DIR . '/fpm-fixture.log';
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
        $GLOBALS['config'] = array();
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

    public function testSapiNotApplicableInCli(): void
    {
        $this->assertFalse(gojs_fpm_sapi_applicable());
    }

    public function testParseStatusJson(): void
    {
        $payload = json_encode(array(
            'pool' => 'www',
            'active processes' => 3,
            'idle processes' => 7,
            'total processes' => 10,
            'max active processes' => 5,
            'max children reached' => 0,
            'accepted conn' => 4321,
            'listen queue' => 1,
        ));
        $parsed = gojs_fpm_parse_status($payload);
        $this->assertSame('json', $parsed['format']);
        $this->assertSame(3, $parsed['active']);
        $this->assertSame(7, $parsed['idle']);
        $this->assertSame(10, $parsed['total']);
        $this->assertSame(4321, $parsed['accepted_conn']);
        $this->assertSame(1, $parsed['listen_queue']);
    }

    public function testParseStatusText(): void
    {
        $payload = "pool:                 www\n"
            . "accepted conn:        1234\n"
            . "listen queue:         0\n"
            . "active processes:     2\n"
            . "idle processes:       3\n"
            . "total processes:      5\n"
            . "max children reached: 0\n";
        $parsed = gojs_fpm_parse_status($payload);
        $this->assertSame('text', $parsed['format']);
        $this->assertSame(2, $parsed['active']);
        $this->assertSame(5, $parsed['total']);
        $this->assertSame(1234, $parsed['accepted_conn']);
    }

    public function testParseStatusReturnsNullOnGarbage(): void
    {
        $this->assertNull(gojs_fpm_parse_status('not a status page'));
    }

    public function testStatusUrlUsesPort(): void
    {
        $this->assertStringContainsString('127.0.0.1:9090', gojs_fpm_status_url(9090));
        $this->assertStringContainsString('fpm-status?json', gojs_fpm_status_url(9090));
    }

    public function testTailLinesReadsLastN(): void
    {
        file_put_contents($this->tmp, "l1\nl2\nl3\nl4\nl5\n");
        $tail = gojs_tail_lines($this->tmp, 3);
        $this->assertSame(array('l3', 'l4', 'l5'), $tail);
        $this->assertSame(array(), gojs_tail_lines(CONFIG_DIR . '/nope.log', 5));
    }

    public function testExtractDirectiveFromConf(): void
    {
        $conf = "; comment\n[global]\nslowlog = /var/log/php-fpm-slow.log\nrequest_slowlog_timeout = 5s\n";
        $this->assertSame('/var/log/php-fpm-slow.log', gojs_fpm_extract_directive($conf, 'slowlog'));
        $this->assertSame('5s', gojs_fpm_extract_directive($conf, 'request_slowlog_timeout'));
        $this->assertNull(gojs_fpm_extract_directive($conf, 'missing'));
    }

    public function testSlowlogPathUsesConfigOverride(): void
    {
        $GLOBALS['config'] = array('fpm_slowlog' => $this->tmp);
        $this->assertSame($this->tmp, gojs_fpm_slowlog_path());
    }

    public function testStatusApiReturns501OnCli(): void
    {
        $r = $this->call(function () { gojs_api_php_fpm_status(); });
        $this->assertSame(501, $r['status']);
        $this->assertSame('fpm_not_applicable', $r['error']['code']);
    }

    public function testSlowlogApiReturns501OnCli(): void
    {
        $r = $this->call(function () { gojs_api_php_fpm_slowlog(); });
        $this->assertSame(501, $r['status']);
        $this->assertSame('fpm_not_applicable', $r['error']['code']);
    }

    public function testSlowlogTailWithConfigOverrideButCliSapi(): void
    {
        file_put_contents($this->tmp, "slow1\nslow2\n");
        $GLOBALS['config'] = array('fpm_slowlog' => $this->tmp);
        $this->assertSame(array('slow1', 'slow2'), gojs_tail_lines(gojs_fpm_slowlog_path(), 100));
    }
}
