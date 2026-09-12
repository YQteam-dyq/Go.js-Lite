<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class AclTest extends TestCase
{
    public function testRoleRankOrder(): void
    {
        $this->assertSame(0, gojs_role_rank(null));
        $this->assertSame(0, gojs_role_rank(''));
        $this->assertSame(1, gojs_role_rank('viewer'));
        $this->assertSame(2, gojs_role_rank('operator'));
        $this->assertSame(3, gojs_role_rank('admin'));
    }

    public function testRequireRoleDeniesAnonymousForAdminAction(): void
    {
        $res = $this->runProbe(array(
            'fn' => 'require_role',
            'min' => 'admin',
        ));
        $this->assertSame(0, $res['rc']);
        $this->assertDenied($res['stdout'], 'insufficient_role');
    }

    public function testRequireRoleDeniesViewerForAdmin(): void
    {
        $res = $this->runProbe(array(
            'fn' => 'require_role',
            'min' => 'admin',
            'actor' => array('id' => 'u_viewer', 'role' => 'viewer', 'path_allowlist' => array()),
        ));
        $this->assertSame(0, $res['rc']);
        $this->assertDenied($res['stdout'], 'insufficient_role');
    }

    public function testRequireRoleAllowsViewerForViewer(): void
    {
        $res = $this->runProbe(array(
            'fn' => 'require_role',
            'min' => 'viewer',
            'actor' => array('id' => 'u_viewer', 'role' => 'viewer', 'path_allowlist' => array()),
        ));
        $this->assertSame(0, $res['rc']);
        $this->assertStringContainsString('ALLOW', $res['stdout']);
    }

    public function testRequireRoleAllowsAdminForAny(): void
    {
        foreach (array('viewer', 'operator', 'admin') as $min) {
            $res = $this->runProbe(array(
                'fn' => 'require_role',
                'min' => $min,
                'actor' => array('id' => 'u_admin', 'role' => 'admin', 'path_allowlist' => array()),
            ));
            $this->assertSame(0, $res['rc']);
            $this->assertStringContainsString('ALLOW', $res['stdout']);
        }
    }

    public function testRequirePathAccessAllowsAdminEverywhere(): void
    {
        $res = $this->runProbe(array(
            'fn' => 'require_path',
            'path' => '/etc/passwd',
            'actor' => array('id' => 'u_admin', 'role' => 'admin', 'path_allowlist' => array()),
        ));
        $this->assertSame(0, $res['rc']);
        $this->assertStringContainsString('ALLOW', $res['stdout']);
    }

    public function testRequirePathAccessAllowsOperatorEverywhere(): void
    {
        $res = $this->runProbe(array(
            'fn' => 'require_path',
            'path' => '/var/www/site1/index.php',
            'actor' => array('id' => 'u_op', 'role' => 'operator', 'path_allowlist' => array()),
        ));
        $this->assertSame(0, $res['rc']);
        $this->assertStringContainsString('ALLOW', $res['stdout']);
    }

    public function testRequirePathAccessDeniesViewerOutsideAllowlist(): void
    {
        $res = $this->runProbe(array(
            'fn' => 'require_path',
            'path' => '/etc/passwd',
            'actor' => array('id' => 'u_viewer', 'role' => 'viewer', 'path_allowlist' => array('/var/www/site1')),
        ));
        $this->assertSame(0, $res['rc']);
        $this->assertDenied($res['stdout'], 'path_not_allowed');
    }

    public function testRequirePathAccessAllowsViewerInAllowlist(): void
    {
        $res = $this->runProbe(array(
            'fn' => 'require_path',
            'path' => '/var/www/site1/index.php',
            'actor' => array('id' => 'u_viewer', 'role' => 'viewer', 'path_allowlist' => array('/var/www/site1')),
        ));
        $this->assertSame(0, $res['rc']);
        $this->assertStringContainsString('ALLOW', $res['stdout']);
    }

    public function testRequirePathAccessRejectsAllowlistPrefixEvasion(): void
    {

        $res = $this->runProbe(array(
            'fn' => 'require_path',
            'path' => '/var/www/site1evil/x',
            'actor' => array('id' => 'u_viewer', 'role' => 'viewer', 'path_allowlist' => array('/var/www/site1')),
        ));
        $this->assertSame(0, $res['rc']);
        $this->assertDenied($res['stdout'], 'path_not_allowed');
    }

    public function testRequirePathAccessDeniesViewerWithEmptyAllowlist(): void
    {
        $res = $this->runProbe(array(
            'fn' => 'require_path',
            'path' => '/var/www/site1/index.php',
            'actor' => array('id' => 'u_viewer', 'role' => 'viewer', 'path_allowlist' => array()),
        ));
        $this->assertSame(0, $res['rc']);
        $this->assertDenied($res['stdout'], 'path_not_allowed');
    }

    public function testRequirePathAccessMergesGroupAllowlists(): void
    {

        $actor = array('id' => 'u_test', 'role' => 'viewer', 'path_allowlist' => array('/d'));
        $groups = array(
            array('id' => 'g1', 'name' => 'devs', 'path_allowlist' => array('/a'), 'member_ids' => array('u_test')),
            array('id' => 'g2', 'name' => 'ops', 'path_allowlist' => array('/b', '/c'), 'member_ids' => array('u_test')),
        );
        foreach (array('/a/some', '/b/some', '/c/some', '/d/some') as $path) {
            $res = $this->runProbe(array(
                'fn' => 'require_path',
                'path' => $path,
                'actor' => $actor,
                'groups' => $groups,
            ));
            $this->assertSame(0, $res['rc']);
            $this->assertStringContainsString('ALLOW', $res['stdout'], "path $path should be allowed");
        }

        $res = $this->runProbe(array(
            'fn' => 'require_path',
            'path' => '/etc/passwd',
            'actor' => $actor,
            'groups' => $groups,
        ));
        $this->assertSame(0, $res['rc']);
        $this->assertDenied($res['stdout'], 'path_not_allowed');
    }

    public function testRequirePathAccessIgnoresNonMemberGroupAllowlist(): void
    {

        $actor = array('id' => 'u_other', 'role' => 'viewer', 'path_allowlist' => array());
        $groups = array(
            array('id' => 'g1', 'name' => 'devs', 'path_allowlist' => array('/a'), 'member_ids' => array('u_test')),
        );
        $res = $this->runProbe(array(
            'fn' => 'require_path',
            'path' => '/a/some',
            'actor' => $actor,
            'groups' => $groups,
        ));
        $this->assertSame(0, $res['rc']);
        $this->assertDenied($res['stdout'], 'path_not_allowed');
    }

    private function runProbe(array $scenario): array
    {
        $fixture = __DIR__ . '/fixtures/acl_probe.php';
        $arg = base64_encode(json_encode($scenario));
        $cmd = PHP_BINARY . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($arg) . ' 2>&1';
        $lines = array();
        exec($cmd, $lines, $rc);
        return array('rc' => $rc, 'stdout' => trim(implode("\n", $lines)));
    }

    private function assertDenied(string $stdout, string $code): void
    {
        $json = json_decode($stdout, true);
        $this->assertIsArray($json, 'denial stdout should be JSON, got: ' . $stdout);
        $this->assertArrayHasKey('ok', $json);
        $this->assertFalse($json['ok']);
        $this->assertSame($code, $json['error']['code'] ?? null);
    }
}
