<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class VersionSourceTest extends TestCase
{
    private function projectDir()
    {
        return dirname(__DIR__);
    }

    private function readSource($relative)
    {
        $raw = @file_get_contents($this->projectDir() . '/' . $relative);
        $this->assertNotFalse($raw, $relative . ' must be readable');

        return (string)$raw;
    }

    private function readJsonSource($relative)
    {
        $decoded = json_decode($this->readSource($relative), true);
        $this->assertIsArray($decoded, $relative . ' must contain a JSON object');

        return $decoded;
    }

    public function testVersionManifestIsAValidSemverString(): void
    {
        $manifest = $this->readJsonSource('version.json');

        $this->assertArrayHasKey('version', $manifest);
        $this->assertIsString($manifest['version']);
        $this->assertSame(
            1,
            preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $manifest['version']),
            'version.json must hold a semver string, got ' . var_export($manifest['version'], true)
        );
    }

    public function testRuntimeConstantsDeriveFromTheManifest(): void
    {
        $manifest = $this->readJsonSource('version.json');

        $this->assertSame($manifest['version'], gojs_version());
        $this->assertSame($manifest['version'], VERSION);
        $this->assertSame($manifest['version'], APP_VERSION);
    }

    public function testVersionHelperPrefersTheManifestOverPackageJson(): void
    {
        $manifest = $this->readJsonSource('version.json');

        $this->assertSame($manifest['version'], gojs_version());
        $this->assertTrue(gojs_version_is_valid(gojs_version()));
        $this->assertFalse(gojs_version_is_valid('not-a-version'));
        $this->assertFalse(gojs_version_is_valid('1.2'));
    }

    public function testPackageManifestsMatchTheSingleSource(): void
    {
        $manifest = $this->readJsonSource('version.json');
        $package = $this->readJsonSource('package.json');
        $lock = $this->readJsonSource('package-lock.json');

        $this->assertSame($manifest['version'], $package['version']);
        $this->assertSame($manifest['version'], $lock['version']);
        $this->assertArrayHasKey('packages', $lock);
        $this->assertArrayHasKey('', $lock['packages']);
        $this->assertSame($manifest['version'], $lock['packages']['']['version']);
    }

    public function testDerivedSourcesHaveNoHardCodedVersion(): void
    {
        $sources = array('api.php', 'tests/bootstrap.php', 'shared/version.ts');

        foreach ($sources as $relative) {
            $this->assertSame(
                0,
                preg_match('/[0-9]+\.[0-9]+\.[0-9]+/', $this->readSource($relative)),
                $relative . ' must not hard-code a version, read it from version.json'
            );
        }
    }

    public function testDerivedSourcesPointAtTheSingleSource(): void
    {
        $this->assertStringContainsString('gojs_version()', $this->readSource('api.php'));
        $this->assertStringContainsString('gojs_version()', $this->readSource('tests/bootstrap.php'));
        $this->assertStringContainsString('version.json', $this->readSource('shared/version.ts'));
    }

    public function testDocumentationBadgesMatchTheSingleSource(): void
    {
        $manifest = $this->readJsonSource('version.json');

        foreach (array('README.md', 'README.zh-CN.md') as $relative) {
            $this->assertStringContainsString(
                'version-' . $manifest['version'] . '-blue.svg',
                $this->readSource($relative),
                $relative . ' badge must show the version from version.json'
            );
        }
    }

    public function testChangelogHasASectionForTheCurrentVersion(): void
    {
        $manifest = $this->readJsonSource('version.json');

        $this->assertStringContainsString('## [' . $manifest['version'] . ']', $this->readSource('CHANGELOG.md'));
    }
}
