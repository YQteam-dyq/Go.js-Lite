<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class PhpUpgradeTest extends TestCase
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

    public function testParseConstraintPicksLowestBound(): void
    {
        $this->assertSame('7.4', gojs_upgrade_parse_php_constraint('^7.4 || ^8.0'));
        $this->assertSame('8.1', gojs_upgrade_parse_php_constraint('>=8.1'));
        $this->assertSame('8.0.12', gojs_upgrade_parse_php_constraint('^8.0.12'));
        $this->assertNull(gojs_upgrade_parse_php_constraint(''));
        $this->assertNull(gojs_upgrade_parse_php_constraint(null));
        $this->assertNull(gojs_upgrade_parse_php_constraint('*'));
    }

    public function testRecommendedPrefers82(): void
    {
        $this->assertSame('8.2', gojs_upgrade_recommended('7.4', '8.0'));
        $this->assertSame('8.3', gojs_upgrade_recommended('8.2', '7.4'));
        $this->assertSame('8.3', gojs_upgrade_recommended('8.3', '7.4'));
    }

    public function testScanDetectsEnumAsBlockerOn74(): void
    {
        $src = "<?php\nenum Suit: string {\n    case Hearts = 'H';\n}\n";
        $blockers = gojs_upgrade_scan_source($src, '7.4', 'backend/x.php');
        $this->assertCount(1, $blockers);
        $this->assertSame('8.1', $blockers[0]['requires']);
        $this->assertSame(2, $blockers[0]['line']);
        $this->assertSame('backend/x.php', $blockers[0]['file']);
    }

    public function testScanDetectsMatchAndNullsafe(): void
    {
        $src = "<?php\n\$v = match(\$x) { 1 => 'a', default => 'b' };\n\$y = \$obj?->prop;\n";
        $blockers = gojs_upgrade_scan_source($src, '7.4', 'backend/y.php');
        $this->assertCount(2, $blockers);
    }

    public function testScanSkipsFeaturesAlreadySupported(): void
    {
        $src = "<?php\nenum Suit { case A; }\n\$v = match(\$x) { default => 1 };\n";
        $this->assertSame(array(), gojs_upgrade_scan_source($src, '8.1', 'backend/z.php'));
    }

    public function testScanDirSkipsMissingDir(): void
    {
        $this->assertSame(array(), gojs_upgrade_scan_dir(CONFIG_DIR . '/does-not-exist', '7.4'));
    }

    public function testApiShape(): void
    {
        $r = $this->call(function () { gojs_api_php_upgrade_check(); });
        $this->assertSame(200, $r['status']);
        $this->assertArrayHasKey('current', $r['data']);
        $this->assertArrayHasKey('recommended', $r['data']);
        $this->assertArrayHasKey('blockers', $r['data']);
        $this->assertSame(PHP_VERSION, $r['data']['current']);
        $this->assertIsInt($r['data']['blocker_count']);
    }
}
