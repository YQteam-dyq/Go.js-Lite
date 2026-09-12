<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class PhpProcessesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @unlink(CONFIG_DIR . '/php_snapshot.txt');
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
    }

    protected function tearDown(): void
    {
        @unlink(CONFIG_DIR . '/php_snapshot.txt');
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

    public function testParsePsSkipsHeaderAndParsesRows(): void
    {
        $out = "  PID USER      %MEM %CPU     ELAPSED COMMAND\n"
            . " 1234 www-data  0.5  0.1       01:02:03 php-fpm: pool www\n"
            . " 5678 deploy    1.2  9.9       10:00:00 php /var/www/cron.php\n";
        $rows = gojs_process_parse_ps($out);
        $this->assertCount(2, $rows);
        $this->assertSame(1234, $rows[0]['pid']);
        $this->assertSame('www-data', $rows[0]['user']);
        $this->assertSame(0.5, $rows[0]['mem_percent']);
        $this->assertSame('php-fpm: pool www', $rows[0]['cmdline']);
    }

    public function testParsePsIgnoresGarbage(): void
    {
        $rows = gojs_process_parse_ps("not a process line\nshort row\n");
        $this->assertSame(array(), $rows);
    }

    public function testFilterPhpKeepsOnlyPhpRows(): void
    {
        $rows = array(
            array('cmdline' => 'php-fpm: pool www'),
            array('cmdline' => 'nginx: worker process'),
            array('cmdline' => 'grep php'),
            array('cmdline' => '/usr/bin/php /var/www/app.php'),
        );
        $filtered = gojs_process_filter_php($rows);
        $this->assertCount(2, $filtered);
        $this->assertSame('php-fpm: pool www', $filtered[0]['cmdline']);
    }

    public function testParseTasklistCsv(): void
    {
        $out = "\"php.exe\",\"1234\",\"Console\",\"1\",\"45,678 K\",\"0\",\"SYSTEM\",\"0\",\"2,345 K\"\n"
            . "\"php.exe\",\"4321\",\"Console\",\"1\",\"12,000 K\",\"0\",\"deploy\",\"0\",\"1,000 K\"\n"
            . "INFO: No tasks are running which match the specified criteria.\n";
        $rows = gojs_process_parse_tasklist_csv($out);
        $this->assertCount(2, $rows);
        $this->assertSame(1234, $rows[0]['pid']);
        $this->assertSame(45678, $rows[0]['mem_kb']);
        $this->assertSame('deploy', $rows[1]['user']);
    }

    public function testProcessListShape(): void
    {
        $list = gojs_process_list();
        $this->assertArrayHasKey('supported', $list);
        $this->assertArrayHasKey('processes', $list);
        $this->assertIsArray($list['processes']);
    }

    public function testProcessesApiShape(): void
    {
        $r = $this->call(function () { gojs_api_php_processes(); });
        $this->assertSame(200, $r['status']);
        $this->assertArrayHasKey('processes', $r['data']);
        $this->assertArrayHasKey('supported', $r['data']);
        $this->assertSame(gojs_php_binary(), $r['data']['php_binary']);
    }

    public function testSnapshotApiWritesFileOrReportsUnavailable(): void
    {
        $r = $this->call(function () { gojs_api_php_processes_snapshot(); });
        $this->assertContains($r['status'], array(200, 501));
        if ($r['status'] === 200) {
            $this->assertTrue(file_exists(gojs_php_snapshot_path()));
            $content = file_get_contents(gojs_php_snapshot_path());
            $this->assertStringContainsString('## php -m', $content);
        } else {
            $this->assertSame('snapshot_unavailable', $r['error']['code']);
        }
    }

    public function testSnapshotDownloadReturns404WhenMissing(): void
    {
        $r = $this->call(function () { gojs_api_php_processes_snapshot_download(); });
        $this->assertSame(404, $r['status']);
        $this->assertSame('snapshot_missing', $r['error']['code']);
    }

    public function testSnapshotDownloadReturnsContent(): void
    {
        file_put_contents(gojs_php_snapshot_path(), "## php -m\njson\n");
        $r = $this->call(function () { gojs_api_php_processes_snapshot_download(); });
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('json', $r['data']['content']);
    }
}
