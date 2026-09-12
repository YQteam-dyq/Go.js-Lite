<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class PhpAutoloadAuditTest extends TestCase
{
    private $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(CONFIG_DIR)) {
            @mkdir(CONFIG_DIR, 0700, true);
        }
        $this->dir = CONFIG_DIR . '/autoload-audit-fixture';
        $this->removeDir();
        @mkdir($this->dir, 0700, true);
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
    }

    protected function tearDown(): void
    {
        $this->removeDir();
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
        parent::tearDown();
    }

    private function removeDir(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        foreach ((array)glob($this->dir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
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

    public function testRegisteredNamesExtracted(): void
    {
        $src = "<?php\nrequire_once __DIR__ . '/groups.php';\nrequire_once __DIR__ . \"/tokens.php\";\n";
        $names = gojs_autoload_registered_names($src);
        $this->assertArrayHasKey('groups.php', $names);
        $this->assertArrayHasKey('tokens.php', $names);
    }

    public function testStatementTargetParsing(): void
    {
        $hit = gojs_autoload_statement_target("    require_once __DIR__ . '/foo.php';");
        $this->assertSame('foo.php', basename($hit['target']));
        $this->assertStringContainsString('require_once', $hit['statement']);
        $this->assertNull(gojs_autoload_statement_target('$x = 1;'));
        $this->assertNull(gojs_autoload_statement_target("include 'no-extension';"));
    }

    public function testScanGroupsRegisteredAndSuggests(): void
    {
        file_put_contents($this->dir . '/sample.php', implode("\n", array(
            '<?php',
            "require_once __DIR__ . '/registered.php';",
            "require_once __DIR__ . '/missing.php';",
            "require_once __DIR__ . '/vendor/pkg.php';",
        )) . "\n");
        $autoload = "<?php\nrequire_once __DIR__ . '/registered.php';\n";
        $scan = gojs_autoload_audit_scan($this->dir, $autoload, 'backend');
        $this->assertCount(1, $scan['registered']);
        $this->assertSame('backend/sample.php', $scan['registered'][0]['file']);
        $this->assertCount(2, $scan['unregistered']);
        $suggestions = array();
        foreach ($scan['suggestions'] as $s) {
            $suggestions[$s['target']] = $s['suggestion'];
        }
        $this->assertSame('move to autoload.php', $suggestions['/missing.php']);
        $this->assertSame('composer autoload', $suggestions['/vendor/pkg.php']);
    }

    public function testScanTreatsAutoloadReferenceAsRegistered(): void
    {
        file_put_contents($this->dir . '/sample.php', "<?php\nrequire_once __DIR__ . '/autoload.php';\n");
        $scan = gojs_autoload_audit_scan($this->dir, '', 'backend');
        $this->assertCount(1, $scan['registered']);
        $this->assertCount(0, $scan['unregistered']);
    }

    public function testScanMissingDirReturnsEmpty(): void
    {
        $scan = gojs_autoload_audit_scan(CONFIG_DIR . '/nope-dir', '', 'backend');
        $this->assertSame(array(), $scan['unregistered']);
        $this->assertSame(array(), $scan['registered']);
    }

    public function testApiShape(): void
    {
        $r = $this->call(function () { gojs_api_php_autoload_audit(); });
        $this->assertSame(200, $r['status']);
        $this->assertArrayHasKey('suggestions', $r['data']);
        $this->assertArrayHasKey('unregistered_count', $r['data']);
        $this->assertSame('backend', basename($r['data']['backend_dir']));
    }
}
