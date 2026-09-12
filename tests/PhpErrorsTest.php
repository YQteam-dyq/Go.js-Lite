<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class PhpErrorsTest extends TestCase
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

    public function testClassifySeverities(): void
    {
        $this->assertSame('fatal', gojs_php_error_classify('[11-Sep-2026 10:00:00 UTC] PHP Fatal error:  Uncaught Error'));
        $this->assertSame('fatal', gojs_php_error_classify('PHP Parse error:  syntax error'));
        $this->assertSame('warning', gojs_php_error_classify('[11-Sep-2026 10:00:00 UTC] PHP Warning:  Undefined array key'));
        $this->assertSame('notice', gojs_php_error_classify('PHP Notice:  Trying to access array offset'));
        $this->assertSame('deprecated', gojs_php_error_classify('PHP Deprecated:  Function foo() is deprecated'));
        $this->assertNull(gojs_php_error_classify('[11-Sep-2026 10:00:00 UTC] plain log row'));
    }

    public function testErrorCodeMapping(): void
    {
        $this->assertSame('E_ERROR', gojs_php_error_code('fatal'));
        $this->assertSame('E_WARNING', gojs_php_error_code('warning'));
        $this->assertSame('E_NOTICE', gojs_php_error_code('notice'));
        $this->assertSame('E_DEPRECATED', gojs_php_error_code('deprecated'));
        $this->assertSame('E_UNKNOWN', gojs_php_error_code('other'));
    }

    public function testTimestampExtraction(): void
    {
        $ts = gojs_php_error_timestamp('[11-Sep-2026 10:00:00 UTC] PHP Warning: x');
        $this->assertIsInt($ts);
        $this->assertSame(strtotime('11-Sep-2026 10:00:00 UTC'), $ts);
        $this->assertNull(gojs_php_error_timestamp('no timestamp here'));
    }

    public function testSinceParsing(): void
    {
        $now = time();
        $this->assertEqualsWithDelta($now - 3600, gojs_php_errors_since_ts('1h'), 5);
        $this->assertEqualsWithDelta($now - 120, gojs_php_errors_since_ts('2m'), 5);
        $this->assertEqualsWithDelta($now - 86400, gojs_php_errors_since_ts('1d'), 5);
        $this->assertNull(gojs_php_errors_since_ts(''));
        $this->assertNull(gojs_php_errors_since_ts('nonsense'));
    }

    public function testParseAndAggregate(): void
    {
        $raw = "[11-Sep-2026 10:05:00 UTC] PHP Warning:  Undefined array key \"a\"\n"
            . "[11-Sep-2026 10:35:00 UTC] PHP Warning:  Undefined array key \"b\"\n"
            . "[11-Sep-2026 11:15:00 UTC] PHP Fatal error:  Uncaught Error\n"
            . "[11-Sep-2026 10:20:00 UTC] PHP Notice:  something\n"
            . "random line without severity\n";
        $entries = gojs_php_errors_parse($raw);
        $this->assertCount(4, $entries);
        $agg = gojs_php_errors_aggregate($entries);
        $this->assertSame(4, $agg['total']);
        $this->assertSame(1, $agg['by_severity']['fatal']);
        $this->assertSame(2, $agg['by_severity']['warning']);
        $this->assertSame(1, $agg['by_severity']['notice']);
        $this->assertSame(1, $agg['by_code']['E_ERROR']);
        $this->assertSame(2, $agg['by_code']['E_WARNING']);
        $this->assertCount(2, $agg['buckets']);
    }

    public function testAggregateRespectsSeverityFilter(): void
    {
        $entries = gojs_php_errors_parse(
            "PHP Fatal error: a\nPHP Warning: b\nPHP Warning: c\n"
        );
        $agg = gojs_php_errors_aggregate($entries, null, array('warning'));
        $this->assertSame(2, $agg['total']);
        $this->assertSame(0, $agg['by_severity']['fatal']);
    }

    public function testAggregateRespectsSince(): void
    {
        $entries = gojs_php_errors_parse(
            "[11-Sep-2020 10:00:00 UTC] PHP Warning:  old\n"
            . '[' . gmdate('d-M-Y H:i:s') . ' UTC] PHP Warning:  fresh' . "\n"
        );
        $agg = gojs_php_errors_aggregate($entries, time() - 3600);
        $this->assertSame(1, $agg['total']);
    }

    public function testTopCodesLimitsResults(): void
    {
        $top = gojs_php_errors_top_codes(array('E_WARNING' => 5, 'E_ERROR' => 3, 'E_NOTICE' => 1), 2);
        $this->assertCount(2, $top);
        $this->assertSame('E_WARNING', $top[0]['code']);
        $this->assertSame(5, $top[0]['count']);
    }

    public function testApiShape(): void
    {
        $r = $this->call(function () { gojs_api_php_errors(); });
        $this->assertSame(200, $r['status']);
        $this->assertArrayHasKey('aggregate', $r['data']);
        $this->assertArrayHasKey('top_codes', $r['data']['aggregate']);
        $this->assertSame('24h', $r['data']['since']);
    }
}
