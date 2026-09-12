<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class PhpExtensionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(CONFIG_DIR)) {
            @mkdir(CONFIG_DIR, 0700, true);
        }
        @unlink(CONFIG_DIR . '/php_favorites.json');
        $GLOBALS['gojs_defer_response'] = false;
        $GLOBALS['gojs_deferred_response'] = null;
    }

    protected function tearDown(): void
    {
        @unlink(CONFIG_DIR . '/php_favorites.json');
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

    public function testListIsSortedAndNonEmpty(): void
    {
        $rows = gojs_php_extensions_list();
        $this->assertNotEmpty($rows);
        $names = array();
        foreach ($rows as $r) {
            $names[] = $r['name'];
            $this->assertArrayHasKey('version', $r);
            $this->assertIsBool($r['zend']);
            $this->assertIsBool($r['favorite']);
        }
        $sorted = $names;
        usort($sorted, function ($a, $b) { return strcasecmp($a, $b); });
        $this->assertSame($sorted, $names);
    }

    public function testZendExtensionSetIsArray(): void
    {
        $set = gojs_php_zend_extensions();
        $this->assertIsArray($set);
    }

    public function testFavoritesLoadDefaultsToEmpty(): void
    {
        $store = gojs_php_favorites_load();
        $this->assertSame(array(), $store['extensions']);
    }

    public function testFavoritesSaveAndLoadRoundtrip(): void
    {
        $store = array('extensions' => array('json', 'opcache'));
        $this->assertTrue(gojs_php_favorites_save($store));
        $loaded = gojs_php_favorites_load();
        $this->assertSame(array('json', 'opcache'), $loaded['extensions']);
    }

    public function testNormalizeFavoritesDedupes(): void
    {
        $out = gojs_php_favorites_normalize(array('json', 'json', '', '  ', 'zip'));
        $this->assertSame(array('json', 'zip'), $out);
    }

    public function testListApiShape(): void
    {
        $r = $this->call(function () { gojs_api_php_extensions_list(); });
        $this->assertSame(200, $r['status']);
        $this->assertArrayHasKey('extensions', $r['data']);
        $this->assertSame(count($r['data']['extensions']), $r['data']['count']);
        $this->assertIsArray($r['data']['favorites']);
    }

    public function testFavoriteApiRejectsUnknownExtension(): void
    {
        $GLOBALS['gojs_body_override'] = array('name' => 'definitely_not_an_extension_xyz');
        $r = $this->call(function () { gojs_api_php_extensions_favorite(); });
        $this->assertSame(404, $r['status']);
        $this->assertSame('extension_not_loaded', $r['error']['code']);
    }

    public function testFavoriteApiRejectsEmptyName(): void
    {
        $GLOBALS['gojs_body_override'] = array('name' => '   ');
        $r = $this->call(function () { gojs_api_php_extensions_favorite(); });
        $this->assertSame(400, $r['status']);
        $this->assertSame('invalid_name', $r['error']['code']);
    }

    public function testFavoriteApiToggles(): void
    {
        $rows = gojs_php_extensions_list();
        $name = $rows[0]['name'];
        $GLOBALS['gojs_body_override'] = array('name' => $name, 'favorite' => true);
        $r = $this->call(function () { gojs_api_php_extensions_favorite(); });
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['favorite']);
        $this->assertContains($name, $r['data']['favorites']);

        $GLOBALS['gojs_body_override'] = array('name' => $name, 'favorite' => false);
        $r2 = $this->call(function () { gojs_api_php_extensions_favorite(); });
        $this->assertSame(200, $r2['status']);
        $this->assertFalse($r2['data']['favorite']);
        $this->assertNotContains($name, $r2['data']['favorites']);
    }
}
