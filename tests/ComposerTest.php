<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class ComposerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(CONFIG_DIR)) {
            @mkdir(CONFIG_DIR, 0700, true);
        }
        @unlink(CONFIG_DIR . '/composer_install.log');
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
    }

    protected function tearDown(): void
    {
        @unlink(CONFIG_DIR . '/composer_install.log');
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
        $GLOBALS['gojs_composer_override'] = null;
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

    public function testTreeDepthOnNestedRequires(): void
    {
        $lock = array('packages' => array(
            array('name' => 'a/a', 'require' => array('b/b' => '^1.0')),
            array('name' => 'b/b', 'require' => array('c/c' => '^1.0')),
            array('name' => 'c/c', 'require' => array('php' => '>=7.4')),
        ));
        $this->assertSame(3, gojs_composer_tree_depth($lock));
    }

    public function testTreeDepthIgnoresPlatformPackages(): void
    {
        $lock = array('packages' => array(
            array('name' => 'a/a', 'require' => array('php' => '>=7.4', 'ext-json' => '*', 'lib-icu' => '*')),
        ));
        $this->assertSame(1, gojs_composer_tree_depth($lock));
    }

    public function testTreeDepthEmptyLock(): void
    {
        $this->assertSame(0, gojs_composer_tree_depth(array()));
        $this->assertSame(0, gojs_composer_tree_depth(null));
        $this->assertSame(0, gojs_composer_tree_depth(array('packages' => array())));
    }

    public function testTreeDepthSurvivesCycles(): void
    {
        $lock = array('packages' => array(
            array('name' => 'a/a', 'require' => array('b/b' => '*')),
            array('name' => 'b/b', 'require' => array('a/a' => '*')),
        ));
        $this->assertGreaterThanOrEqual(1, gojs_composer_tree_depth($lock));
    }

    public function testLockStatsCountsAndHashes(): void
    {
        $lock = array(
            'packages' => array(array('name' => 'a/a'), array('name' => 'b/b')),
            'packages-dev' => array(array('name' => 'c/c')),
            'content-hash' => 'abc123',
            'plugin-api-version' => '2.6.0',
            'platform' => array('php' => '^8.0'),
        );
        $stats = gojs_composer_lock_stats($lock);
        $this->assertSame(2, $stats['packages']);
        $this->assertSame(1, $stats['dev_packages']);
        $this->assertSame('abc123', $stats['content_hash']);
        $this->assertSame('2.6.0', $stats['plugin_api_version']);
        $this->assertSame(1, $stats['depth']);
    }

    public function testLockStatsOnNull(): void
    {
        $stats = gojs_composer_lock_stats(null);
        $this->assertSame(0, $stats['packages']);
        $this->assertNull($stats['content_hash']);
    }

    public function testValidatePackage(): void
    {
        $this->assertTrue(gojs_composer_validate_package('phpseclib3/phpseclib'));
        $this->assertTrue(gojs_composer_validate_package('vendor/sub-package'));
        $this->assertFalse(gojs_composer_validate_package('noslash'));
        $this->assertFalse(gojs_composer_validate_package('bad name/pkg'));
        $this->assertFalse(gojs_composer_validate_package(''));
        $this->assertFalse(gojs_composer_validate_package(null));
    }

    public function testFindReturnsStringOrNull(): void
    {
        $found = gojs_composer_find();
        $this->assertTrue($found === null || is_string($found));
    }

    public function testStatusApiShape(): void
    {
        $r = $this->call(function () { gojs_api_composer_status(); });
        $this->assertSame(200, $r['status']);
        $this->assertArrayHasKey('available', $r['data']);
        $this->assertArrayHasKey('lock', $r['data']);
        $this->assertArrayHasKey('depth', $r['data']['lock']);
        $this->assertTrue($r['data']['vendor_present']);
    }

    public function testMutationsReturn501WhenComposerMissing(): void
    {
        $GLOBALS['gojs_composer_override'] = null;
        $this->assertFalse(gojs_composer_available());

        $r = $this->call(function () { gojs_api_composer_install(); });
        $this->assertSame(501, $r['status']);
        $this->assertSame('composer_unavailable', $r['error']['code']);
        $this->assertArrayHasKey('install_guide', $r['error']);
    }

    public function testRequireRejectsInvalidPackage(): void
    {
        $GLOBALS['gojs_composer_override'] = '/usr/bin/composer';
        $GLOBALS['gojs_body_override'] = array('package' => 'not-a-valid-name');
        $r = $this->call(function () { gojs_api_composer_require(); });
        $this->assertSame(400, $r['status']);
        $this->assertSame('invalid_package', $r['error']['code']);
        $GLOBALS['gojs_body_override'] = null;
    }

    public function testJsonApiReturnsJsonAndLock(): void
    {
        $r = $this->call(function () { gojs_api_composer_json(); });
        $this->assertSame(200, $r['status']);
        $this->assertArrayHasKey('json', $r['data']);
        $this->assertArrayHasKey('lock', $r['data']);
    }

    public function testLogAppendAndTail(): void
    {
        gojs_composer_log_append('install', array('ok' => true, 'code' => 0, 'output' => 'done'));
        $tail = gojs_composer_log_tail(10);
        $this->assertStringContainsString('install exit=0', $tail);
        $this->assertStringContainsString('done', $tail);
    }
}
