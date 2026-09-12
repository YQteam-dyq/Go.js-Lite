<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class PhpIniTest extends TestCase
{
    private $userIni;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(CONFIG_DIR)) {
            @mkdir(CONFIG_DIR, 0700, true);
        }
        $this->userIni = CONFIG_DIR . '/.user.ini';
        @unlink($this->userIni);
        $GLOBALS['gojs_user_ini_override'] = $this->userIni;
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
    }

    protected function tearDown(): void
    {
        @unlink($this->userIni);
        unset($GLOBALS['gojs_user_ini_override']);
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
        $GLOBALS['gojs_body_override'] = null;
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

    public function testValuesMatchHandlesBooleans(): void
    {
        $this->assertTrue(gojs_php_ini_values_match('1', '1'));
        $this->assertTrue(gojs_php_ini_values_match('On', '1'));
        $this->assertTrue(gojs_php_ini_values_match('', '0'));
        $this->assertTrue(gojs_php_ini_values_match('Off', '0'));
        $this->assertFalse(gojs_php_ini_values_match('1', '0'));
        $this->assertFalse(gojs_php_ini_values_match(false, '1'));
    }

    public function testSizeToMb(): void
    {
        $this->assertSame(128.0, gojs_php_size_to_mb('128M'));
        $this->assertSame(2048.0, gojs_php_size_to_mb('2G'));
        $this->assertSame(1.0, gojs_php_size_to_mb('1048576'));
        $this->assertNull(gojs_php_size_to_mb('-1'));
        $this->assertNull(gojs_php_size_to_mb(''));
        $this->assertNull(gojs_php_size_to_mb(false));
    }

    public function testJitModeNormalize(): void
    {
        $this->assertSame('none', gojs_php_jit_mode_normalize(''));
        $this->assertSame('none', gojs_php_jit_mode_normalize('0'));
        $this->assertSame('none', gojs_php_jit_mode_normalize('disable'));
        $this->assertSame('tracing', gojs_php_jit_mode_normalize('tracing'));
        $this->assertSame('function', gojs_php_jit_mode_normalize('function'));
        $this->assertSame('custom', gojs_php_jit_mode_normalize('1205'));
        $this->assertSame('custom', gojs_php_jit_mode_normalize('weird-value'));
    }

    public function testBaselineFileLoads(): void
    {
        $baseline = gojs_php_ini_baseline();
        $this->assertNotEmpty($baseline);
        $this->assertSame('1', $baseline['opcache.enable']);
        $this->assertSame('256', $baseline['opcache.memory_consumption']);
        $this->assertArrayHasKey('session.use_strict_mode', $baseline);
    }

    public function testDiffRowsShape(): void
    {
        $rows = gojs_php_ini_diff();
        $this->assertNotEmpty($rows);
        $row = $rows[0];
        $this->assertArrayHasKey('directive', $row);
        $this->assertArrayHasKey('current', $row);
        $this->assertArrayHasKey('recommended', $row);
        $this->assertContains($row['severity'], array('info', 'warning', 'danger'));
        $this->assertIsBool($row['match']);
    }

    public function testMetaCoversEveryBaselineDirective(): void
    {
        $baseline = gojs_php_ini_baseline();
        $meta = gojs_php_ini_meta();
        foreach (array_keys($baseline) as $directive) {
            $this->assertArrayHasKey($directive, $meta, '缺少 note/severity：' . $directive);
        }
    }

    public function testIniDiffApiShape(): void
    {
        $r = $this->call(function () { gojs_api_php_ini_diff(); });
        $this->assertSame(200, $r['status']);
        $this->assertArrayHasKey('rows', $r['data']);
        $this->assertSame(count($r['data']['rows']), $r['data']['total']);
        $this->assertArrayHasKey('mismatch', $r['data']);
    }

    public function testUserIniWriteAndReadRoundtrip(): void
    {
        $this->assertTrue(gojs_user_ini_merge(array('include_path' => '.:/opt/lib')));
        $this->assertTrue(file_exists($this->userIni));
        $read = gojs_user_ini_read();
        $this->assertSame('.:/opt/lib', $read['include_path']);

        gojs_user_ini_merge(array('include_path' => null, 'max_execution_time' => '120'));
        $read2 = gojs_user_ini_read();
        $this->assertArrayNotHasKey('include_path', $read2);
        $this->assertSame('120', $read2['max_execution_time']);
    }

    public function testJitGetApiShape(): void
    {
        $r = $this->call(function () { gojs_api_php_jit_get(); });
        $this->assertSame(200, $r['status']);
        $this->assertArrayHasKey('mode', $r['data']);
        $this->assertContains($r['data']['mode'], array('none', 'tracing', 'function', 'custom'));
    }

    public function testJitSetRejectsInvalidMode(): void
    {
        $GLOBALS['gojs_body_override'] = array('mode' => 'bogus');
        $r = $this->call(function () { gojs_api_php_jit_set(); });
        $this->assertSame(400, $r['status']);
        $this->assertSame('invalid_mode', $r['error']['code']);
    }

    public function testJitSetRejectsOutOfRangeBuffer(): void
    {
        $GLOBALS['gojs_body_override'] = array('mode' => 'tracing', 'buffer_size_mb' => 99999);
        $r = $this->call(function () { gojs_api_php_jit_set(); });
        $this->assertSame(400, $r['status']);
        $this->assertSame('invalid_buffer', $r['error']['code']);
    }

    public function testJitSetPersistsToUserIni(): void
    {
        $GLOBALS['gojs_body_override'] = array('mode' => 'tracing', 'buffer_size_mb' => 128);
        $r = $this->call(function () { gojs_api_php_jit_set(); });
        $this->assertContains($r['status'], array(200, 501));
        $this->assertTrue(file_exists($this->userIni));
        $ini = gojs_user_ini_read();
        $this->assertSame('tracing', $ini['opcache.jit']);
        $this->assertSame('128M', $ini['opcache.jit_buffer_size']);
        if ($r['status'] === 501) {
            $this->assertSame('ini_readonly', $r['error']['code']);
        } else {
            $this->assertTrue($r['data']['saved_to_ini']);
        }
    }
}
