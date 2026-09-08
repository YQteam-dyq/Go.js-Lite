<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;
use GoJS_Router;

class RouterTest extends TestCase
{
    public function testAddRegistersAndDispatchesHandler(): void
    {
        $router = new GoJS_Router();
        $received = null;
        $router->add('GET', 'files', function ($method, $path) use (&$received) {
            $received = array($method, $path);
        });
        $router->dispatch('files', 'get');

        $this->assertSame(array('GET', 'files'), $received);
    }

    public function testAddSupportsMultipleMethodsArray(): void
    {
        $router = new GoJS_Router();
        $calls = array();
        $router->add(array('GET', 'POST'), 'settings', function ($method, $path) use (&$calls) {
            $calls[] = $method;
        });

        $router->dispatch('settings', 'get');
        $router->dispatch('settings', 'post');
        $this->assertSame(array('GET', 'POST'), $calls);
    }

    public function testAddPrefixResolvesDynamicPath(): void
    {
        $router = new GoJS_Router();
        $resolved = null;
        $router->addPrefix('ftp/accounts/', function ($path, $method) use (&$resolved) {
            $resolved = array($path, $method);
        });

        $router->dispatch('ftp/accounts/abc123', 'GET');
        $this->assertSame(array('ftp/accounts/abc123', 'GET'), $resolved);
    }

    public function testStaticRouteTakesPrecedenceOverPrefix(): void
    {
        $router = new GoJS_Router();
        $staticHit = false;
        $prefixHit = false;
        $router->add('GET', 'files', function () use (&$staticHit) {
            $staticHit = true;
        });
        $router->addPrefix('files/', function () use (&$prefixHit) {
            $prefixHit = true;
        });

        $router->dispatch('files', 'GET');
        $this->assertTrue($staticHit);
        $this->assertFalse($prefixHit);
    }

    public function testDispatchMethodIsCaseInsensitive(): void
    {
        $router = new GoJS_Router();
        $called = false;
        $router->add('DELETE', 'notifications/clear-read', function () use (&$called) {
            $called = true;
        });
        $router->dispatch('notifications/clear-read', 'delete');
        $this->assertTrue($called);
    }

    public function testDispatchUnknownPathReturns404(): void
    {
        $output = $this->runDispatch('does-not-exist', 'GET');
        $this->assertStringContainsString('not_found', $output);
    }

    public function testDispatchWrongMethodReturns405(): void
    {
        $output = $this->runDispatch('files', 'DELETE');
        $this->assertStringContainsString('method_not_allowed', $output);
    }

    private function runDispatch(string $path, string $method): string
    {
        $fixture = __DIR__ . '/fixtures/dispatch_router.php';
        $cmd = PHP_BINARY . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($path) . ' ' . escapeshellarg($method) . ' 2>&1';
        $output = array();
        exec($cmd, $output);
        return implode("\n", $output);
    }
}