<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class ExportsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!is_dir(CONFIG_DIR)) {
            @mkdir(CONFIG_DIR, 0700, true);
        }
        @unlink(CONFIG_DIR . '/users.json');
        @unlink(CONFIG_DIR . '/api_tokens.json');
        @unlink(CONFIG_DIR . '/exports.json');
        $this->clearExportFiles();
    }

    protected function tearDown(): void
    {
        @unlink(CONFIG_DIR . '/users.json');
        @unlink(CONFIG_DIR . '/api_tokens.json');
        @unlink(CONFIG_DIR . '/exports.json');
        $this->clearExportFiles();
        parent::tearDown();
    }

    private function clearExportFiles(): void
    {
        $dir = CONFIG_DIR . '/exports';
        if (is_dir($dir)) {
            foreach ((array)glob($dir . '/*') as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    private function seedUser(string $id = 'u_gdpr'): array
    {
        $user = array(
            'id' => $id,
            'username' => 'gdpr-' . $id,
            'role' => 'viewer',
            'path_allowlist' => array(),
            'preferences' => array('theme' => 'dark', 'language' => 'en'),
            'disabled' => false,
            'created_at' => time(),
        );
        gojs_users_upsert($user);
        return $user;
    }

    public function testCreateProducesZip(): void
    {
        $this->seedUser('u_gdpr');
        $r = gojs_exports_create('u_gdpr');

        $this->assertTrue($r['ok']);
        $this->assertStringStartsWith('exp_', $r['export_id']);
        $this->assertGreaterThan(0, $r['bytes']);
        $this->assertArrayHasKey('download_path', $r);

        $meta = gojs_exports_find($r['export_id']);
        $this->assertNotNull($meta);
        $this->assertFileExists($meta['file']);
    }

    public function testZipContainsRequiredEntries(): void
    {
        $this->seedUser('u_gdpr');
        $r = gojs_exports_create('u_gdpr');
        $meta = gojs_exports_find($r['export_id']);
        $zip = file_get_contents($meta['file']);

        $this->assertStringStartsWith("PK\x03\x04", $zip);
        $this->assertStringContainsString('audit.u_gdpr.json', $zip);
        $this->assertStringContainsString('audit.u_gdpr.csv', $zip);
        $this->assertStringContainsString('preferences.json', $zip);
        $this->assertStringContainsString('sessions.json', $zip);
        $this->assertStringContainsString('tokens.json', $zip);
        $this->assertStringContainsString("PK\x05\x06", $zip);
    }

    public function testVerifyAcceptsValidSignature(): void
    {
        $this->seedUser('u_gdpr');
        $r = gojs_exports_create('u_gdpr');
        $exp = $r['signed_expires_at'];
        $sig = gojs_exports_sign($r['export_id'], $exp);

        $check = gojs_exports_verify($r['export_id'], 'u_gdpr', $exp, $sig);
        $this->assertTrue($check['ok']);
    }

    public function testVerifyRejectsBadSignature(): void
    {
        $this->seedUser('u_gdpr');
        $r = gojs_exports_create('u_gdpr');
        $check = gojs_exports_verify($r['export_id'], 'u_gdpr', $r['signed_expires_at'], 'nope');
        $this->assertFalse($check['ok']);
        $this->assertSame('invalid_signature', $check['code']);
    }

    public function testVerifyRejectsExpiredLink(): void
    {
        $this->seedUser('u_gdpr');
        $r = gojs_exports_create('u_gdpr');
        $exp = time() - 5;
        $sig = gojs_exports_sign($r['export_id'], $exp);
        $check = gojs_exports_verify($r['export_id'], 'u_gdpr', $exp, $sig);
        $this->assertFalse($check['ok']);
        $this->assertSame('export_link_expired', $check['code']);
    }

    public function testVerifyRejectsOtherUser(): void
    {
        $this->seedUser('u_gdpr');
        $r = gojs_exports_create('u_gdpr');
        $sig = gojs_exports_sign($r['export_id'], $r['signed_expires_at']);
        $check = gojs_exports_verify($r['export_id'], 'u_other', $r['signed_expires_at'], $sig);
        $this->assertFalse($check['ok']);
        $this->assertSame('forbidden', $check['code']);
    }

    public function testVerifyUnknownExport(): void
    {
        $check = gojs_exports_verify('exp_missing', 'u_gdpr', time() + 100, 'x');
        $this->assertFalse($check['ok']);
        $this->assertSame('export_not_found', $check['code']);
    }

    public function testAuditCsvHasHeader(): void
    {
        $rows = array(array(
            'time' => '2026-01-01 00:00:00',
            'timestamp' => 1767225600,
            'ip' => '127.0.0.1',
            'user_id' => 'u_gdpr',
            'action' => 'login',
            'target' => 'self',
            'result' => true,
            'detail' => 'ok, "quoted"',
        ));
        $csv = gojs_exports_audit_csv($rows);
        $this->assertStringStartsWith('time,timestamp,ip,user_id,action,target,result,detail', $csv);
        $this->assertStringContainsString('"ok, ""quoted"""', $csv);
    }

    public function testPruneRemovesOldExports(): void
    {
        $this->seedUser('u_gdpr');
        $r = gojs_exports_create('u_gdpr');

        $store = gojs_exports_load();
        $store['exports'][$r['export_id']]['created_at'] = time() - (8 * 86400);
        gojs_exports_save($store);

        $meta = gojs_exports_find($r['export_id']);
        $removed = gojs_exports_prune();

        $this->assertSame(1, $removed);
        $this->assertNull(gojs_exports_find($r['export_id']));
        $this->assertFileDoesNotExist($meta['file']);
    }

    public function testPruneKeepsFreshExports(): void
    {
        $this->seedUser('u_gdpr');
        $r = gojs_exports_create('u_gdpr');
        $this->assertSame(0, gojs_exports_prune());
        $this->assertNotNull(gojs_exports_find($r['export_id']));
    }
}
