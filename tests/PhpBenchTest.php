<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class PhpBenchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
        $GLOBALS['gojs_bench_iterations'] = 2;
        $this->removeBenchDir();
    }

    protected function tearDown(): void
    {
        $this->removeBenchDir();
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
        $GLOBALS['gojs_body_override'] = null;
        unset($GLOBALS['gojs_bench_iterations']);
        parent::tearDown();
    }

    private function removeBenchDir(): void
    {
        $dir = gojs_bench_dir();
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array)glob($dir . '/*.json') as $f) {
            @unlink($f);
        }
        @rmdir($dir);
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

    public function testDefaultIterationsIsOneThousand(): void
    {
        unset($GLOBALS['gojs_bench_iterations']);
        $this->assertSame(1000, gojs_bench_iterations());
        $GLOBALS['gojs_bench_iterations'] = 2;
        $this->assertSame(2, gojs_bench_iterations());
    }

    public function testNamesCoverEightItems(): void
    {
        $names = gojs_bench_names();
        $this->assertCount(8, $names);
        $this->assertContains('serialize', $names);
        $this->assertContains('json', $names);
        $this->assertContains('regex', $names);
    }

    public function testRunAllReturnsEveryItem(): void
    {
        $result = gojs_bench_run_all(2);
        $this->assertSame(2, $result['iterations']);
        $this->assertCount(8, $result['items']);
        $names = array();
        foreach ($result['items'] as $item) {
            $names[] = $item['name'];
            $this->assertArrayHasKey('available', $item);
            if ($item['available']) {
                $this->assertNotNull($item['avg_us']);
            } else {
                $this->assertNotNull($item['reason']);
            }
        }
        $this->assertSame(gojs_bench_names(), $names);
    }

    public function testSerializeAndJsonAreAvailable(): void
    {
        $result = gojs_bench_run_all(2);
        $byName = array();
        foreach ($result['items'] as $item) {
            $byName[$item['name']] = $item;
        }
        $this->assertTrue($byName['serialize']['available']);
        $this->assertTrue($byName['json']['available']);
        $this->assertTrue($byName['regex']['available']);
        $this->assertGreaterThan(0, $byName['serialize']['avg_us']);
    }

    public function testSaveListAndLoad(): void
    {
        $saved = gojs_bench_save(array('iterations' => 2, 'items' => array()));
        $this->assertArrayHasKey('id', $saved);
        $this->assertTrue(gojs_bench_validate_id($saved['id']));
        $list = gojs_bench_list();
        $this->assertNotEmpty($list);
        $loaded = gojs_bench_load($saved['id']);
        $this->assertSame($saved['id'], $loaded['id']);
    }

    public function testLoadRejectsPathTraversal(): void
    {
        $this->assertNull(gojs_bench_load('../../etc/passwd'));
        $this->assertNull(gojs_bench_load('..\\..\\secret'));
        $this->assertNull(gojs_bench_load(''));
    }

    public function testCompareComputesDiffPercent(): void
    {
        $a = array('items' => array(
            array('name' => 'json', 'available' => true, 'avg_us' => 10.0),
            array('name' => 'regex', 'available' => true, 'avg_us' => 20.0),
        ));
        $b = array('items' => array(
            array('name' => 'json', 'available' => true, 'avg_us' => 8.0),
            array('name' => 'regex', 'available' => true, 'avg_us' => 22.0),
        ));
        $rows = gojs_bench_compare($a, $b);
        $this->assertCount(2, $rows);
        $this->assertSame('json', $rows[0]['name']);
        $this->assertSame(-20.0, $rows[0]['diff_pct']);
        $this->assertSame('b', $rows[0]['faster']);
        $this->assertSame(10.0, $rows[1]['diff_pct']);
        $this->assertSame('a', $rows[1]['faster']);
    }

    public function testCompareHandlesMissingItem(): void
    {
        $a = array('items' => array(array('name' => 'json', 'available' => false, 'avg_us' => null)));
        $b = array('items' => array(array('name' => 'json', 'available' => true, 'avg_us' => 5.0)));
        $rows = gojs_bench_compare($a, $b);
        $this->assertNull($rows[0]['diff_pct']);
        $this->assertNull($rows[0]['faster']);
    }

    public function testRunApiShrinksIterationsAndPersists(): void
    {
        $GLOBALS['gojs_body_override'] = array('iterations' => 2);
        $r = $this->call(function () { gojs_api_php_bench_run(); });
        $this->assertSame(200, $r['status']);
        $this->assertCount(8, $r['data']['items']);
        $this->assertArrayHasKey('id', $r['data']);
        $this->assertNotNull(gojs_bench_load($r['data']['id']));
    }

    public function testRunApiRejectsOutOfRangeIterations(): void
    {
        $GLOBALS['gojs_body_override'] = array('iterations' => 999999);
        $r = $this->call(function () { gojs_api_php_bench_run(); });
        $this->assertSame(400, $r['status']);
        $this->assertSame('invalid_iterations', $r['error']['code']);
    }

    public function testCompareApiNeedsTwoRuns(): void
    {
        $r = $this->call(function () { gojs_api_php_bench_compare(); });
        $this->assertSame(404, $r['status']);
        $this->assertSame('not_enough_runs', $r['error']['code']);
    }

    public function testCompareApiWithTwoRuns(): void
    {
        $GLOBALS['gojs_body_override'] = array('iterations' => 2);
        $this->call(function () { gojs_api_php_bench_run(); });
        $this->call(function () { gojs_api_php_bench_run(); });
        $GLOBALS['gojs_body_override'] = null;
        $r = $this->call(function () { gojs_api_php_bench_compare(); });
        $this->assertSame(200, $r['status']);
        $this->assertCount(8, $r['data']['rows']);
    }
}
