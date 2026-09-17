<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;
use ZipArchive;

class BackupIntegrityTest extends TestCase
{
    private $filename = 'backup-20260917-120000.zip';
    private $filesRootBackup;

    protected function setUp(): void
    {
        parent::setUp();
        gojs_backup_integrity_dir();
        $this->purge();
        $this->filesRootBackup = isset($GLOBALS['files_root']) ? $GLOBALS['files_root'] : null;
        $GLOBALS['files_root'] = ROOT;
    }

    protected function tearDown(): void
    {
        $this->purge();
        if ($this->filesRootBackup !== null) {
            $GLOBALS['files_root'] = $this->filesRootBackup;
        }
        parent::tearDown();
    }

    private function purge()
    {
        $entries = glob(CONFIG_DIR . '/backups/*');
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                @unlink($entry);
            }
        }
    }

    private function path($filename = null)
    {
        return CONFIG_DIR . '/backups/' . ($filename === null ? $this->filename : $filename);
    }

    private function makeArchive($filename = null, $withMetadata = true, $withManifest = true, $extra = array())
    {
        $path = $this->path($filename);
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('files/index.php', "<?php\nreturn 1;\n");
        $zip->addFromString('files/readme.txt', "hello world\n");
        $zip->addFromString('database/db1.sql', "SELECT 1;\n");
        foreach ($extra as $name => $content) {
            $zip->addFromString($name, $content);
        }
        if ($withMetadata) {
            $zip->addFromString('backup.json', json_encode(array('created_at' => '2026-09-17 12:00:00', 'version' => '0.8.0')));
        }
        $zip->close();

        if ($withManifest) {
            $manifest = gojs_backup_integrity_build_manifest_from_file($path);
            gojs_backup_integrity_append_manifest($path, $manifest);
        }

        return $path;
    }

    private function mutate($filename, $callback)
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($this->path($filename)) === true);
        $callback($zip);
        $zip->close();
    }

    public function testHealthyArchiveVerifies(): void
    {
        $this->makeArchive();

        $result = gojs_backup_verify_archive($this->filename);

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['code']);
        $this->assertFalse($result['legacy']);
        $this->assertGreaterThan(0, $result['entry_count']);
        $this->assertSame(array(), $result['mismatched']);
        $this->assertSame(array(), $result['missing']);
        $this->assertSame(array(), $result['extra']);
        $this->assertSame(64, strlen($result['archive_sha256']));
    }

    public function testManifestRecordsEveryEntryIncludingMetadata(): void
    {
        $this->makeArchive();

        $zip = new ZipArchive();
        $zip->open($this->path());
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        $names = array();
        foreach ($manifest['entries'] as $entry) {
            $names[] = $entry['name'];
        }

        $this->assertContains('backup.json', $names);
        $this->assertContains('files/index.php', $names);
        $this->assertContains('files/readme.txt', $names);
        $this->assertContains('database/db1.sql', $names);
        $this->assertNotContains('manifest.json', $names);
        $this->assertSame('sha256', $manifest['algorithm']);
        $this->assertSame(count($manifest['entries']), $manifest['entry_count']);
    }

    public function testRecordsContentHashes(): void
    {
        $this->makeArchive();

        $zip = new ZipArchive();
        $zip->open($this->path());
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        foreach ($manifest['entries'] as $entry) {
            if ($entry['name'] !== 'files/readme.txt') {
                continue;
            }
            $this->assertSame(hash('sha256', "hello world\n"), $entry['sha256']);
            $this->assertSame(strlen("hello world\n"), $entry['size']);
            return;
        }

        $this->fail('files/readme.txt is missing from the manifest');
    }

    public function testEntryTamperingIsDetected(): void
    {
        $this->makeArchive();
        $this->mutate($this->filename, function (ZipArchive $zip) {
            $zip->deleteName('files/readme.txt');
            $zip->addFromString('files/readme.txt', "tampered\n");
        });

        $result = gojs_backup_verify_archive($this->filename);

        $this->assertFalse($result['ok']);
        $this->assertSame('entry_mismatch', $result['code']);
        $this->assertContains('files/readme.txt', $result['mismatched']);
    }

    public function testRemovedEntryIsDetected(): void
    {
        $this->makeArchive();
        $this->mutate($this->filename, function (ZipArchive $zip) {
            $zip->deleteName('files/index.php');
        });

        $result = gojs_backup_verify_archive($this->filename);

        $this->assertFalse($result['ok']);
        $this->assertSame('entry_missing', $result['code']);
        $this->assertContains('files/index.php', $result['missing']);
    }

    public function testUntrackedEntryIsDetected(): void
    {
        $this->makeArchive();
        $this->mutate($this->filename, function (ZipArchive $zip) {
            $zip->addFromString('files/backdoor.php', "<?php\n");
        });

        $result = gojs_backup_verify_archive($this->filename);

        $this->assertFalse($result['ok']);
        $this->assertSame('entry_untracked', $result['code']);
        $this->assertContains('files/backdoor.php', $result['extra']);
    }

    public function testManifestTamperingIsDetected(): void
    {
        $this->makeArchive();
        $this->mutate($this->filename, function (ZipArchive $zip) {
            $manifest = json_decode($zip->getFromName('manifest.json'), true);
            $manifest['entries'][] = array('name' => 'files/injected.php', 'dir' => false, 'size' => 5, 'sha256' => hash('sha256', 'x'));
            $zip->deleteName('manifest.json');
            $zip->addFromString('manifest.json', json_encode($manifest));
        });

        $result = gojs_backup_verify_archive($this->filename);

        $this->assertFalse($result['ok']);
        $this->assertSame('manifest_mismatch', $result['code']);
    }

    public function testMalformedManifestIsReported(): void
    {
        $this->makeArchive();
        $this->mutate($this->filename, function (ZipArchive $zip) {
            $zip->deleteName('manifest.json');
            $zip->addFromString('manifest.json', 'not json');
        });

        $result = gojs_backup_verify_archive($this->filename);

        $this->assertFalse($result['ok']);
        $this->assertSame('manifest_invalid', $result['code']);
    }

    public function testArchiveHashMismatchIsDetected(): void
    {
        $this->makeArchive();
        gojs_backup_integrity_write_sidecar($this->filename, str_repeat('0', 64));

        $result = gojs_backup_verify_archive($this->filename);

        $this->assertFalse($result['ok']);
        $this->assertSame('archive_hash_mismatch', $result['code']);
        $this->assertSame(str_repeat('0', 64), $result['expected_archive_sha256']);
    }

    public function testMatchingSidecarIsAccepted(): void
    {
        $path = $this->makeArchive();
        gojs_backup_integrity_write_sidecar($this->filename, gojs_backup_integrity_file_hash($path));

        $result = gojs_backup_verify_archive($this->filename);

        $this->assertTrue($result['ok']);
        $this->assertSame($result['archive_sha256'], $result['expected_archive_sha256']);
    }

    public function testArchiveWithoutManifestIsReportedAsLegacy(): void
    {
        $this->makeArchive(null, true, false);

        $result = gojs_backup_verify_archive($this->filename);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['legacy']);
        $this->assertSame('manifest_missing', $result['code']);
    }

    public function testLegacyArchiveWithoutSidecarIsAcceptedByThePrecheck(): void
    {
        $this->makeArchive(null, true, false);

        $precheck = gojs_backup_restore_precheck($this->filename, false);

        $this->assertTrue($precheck['ok']);
        $this->assertNotEmpty($precheck['warnings']);
    }

    public function testStrictModeRejectsALegacyArchive(): void
    {
        $this->makeArchive(null, true, false);

        $precheck = gojs_backup_restore_precheck($this->filename, true);

        $this->assertFalse($precheck['ok']);
        $this->assertSame('restore_precheck_failed', $precheck['code']);
    }

    public function testInvalidFilenameIsRejected(): void
    {
        $result = gojs_backup_verify_archive('../../etc/passwd');

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_filename', $result['code']);
    }

    public function testMissingArchiveIsRejected(): void
    {
        $result = gojs_backup_verify_archive($this->filename);

        $this->assertFalse($result['ok']);
        $this->assertSame('not_found', $result['code']);
    }

    public function testPrecheckAcceptsAHealthyArchive(): void
    {
        $this->makeArchive();

        $precheck = gojs_backup_restore_precheck($this->filename, false);

        $this->assertTrue($precheck['ok']);
        $this->assertContains($precheck['code'], array('ok', 'ok_with_warnings'));
        $this->assertGreaterThan(0, $precheck['stats']['entries']);
        $this->assertGreaterThan(0, $precheck['stats']['file_entries']);
        $this->assertSame(1, $precheck['stats']['database_entries']);
        $this->assertTrue($precheck['stats']['has_metadata']);
        $this->assertSame(array(), $precheck['stats']['traversal_entries']);
    }

    public function testPrecheckReportsTheUncompressedFootprint(): void
    {
        $this->makeArchive();

        $precheck = gojs_backup_restore_precheck($this->filename, false);

        $expected = strlen("<?php\nreturn 1;\n") + strlen("hello world\n") + strlen("SELECT 1;\n");
        $this->assertGreaterThanOrEqual($expected, $precheck['required_bytes']);
        $this->assertNotNull($precheck['free_space']);
    }

    public function testPrecheckRejectsAnArchiveWithoutMetadata(): void
    {
        $this->makeArchive(null, false);

        $precheck = gojs_backup_restore_precheck($this->filename, false);

        $this->assertFalse($precheck['ok']);
        $this->assertSame('restore_precheck_failed', $precheck['code']);
        $this->assertNotEmpty($precheck['errors']);
    }

    public function testPrecheckRejectsTraversalEntries(): void
    {
        $this->makeArchive(null, true, false, array('files/../evil.php' => "<?php\n"));

        $precheck = gojs_backup_restore_precheck($this->filename, false);

        $this->assertFalse($precheck['ok']);
        $this->assertContains('files/../evil.php', $precheck['stats']['traversal_entries']);
    }

    public function testPrecheckRejectsATamperedArchive(): void
    {
        $this->makeArchive();
        $this->mutate($this->filename, function (ZipArchive $zip) {
            $zip->deleteName('files/index.php');
            $zip->addFromString('files/index.php', "<?php\necho 1;\n");
        });

        $precheck = gojs_backup_restore_precheck($this->filename, false);

        $this->assertFalse($precheck['ok']);
        $this->assertSame('restore_precheck_failed', $precheck['code']);
        $this->assertFalse($precheck['verification']['ok']);
    }

    public function testPrecheckRejectsAMissingFilesRoot(): void
    {
        $this->makeArchive();
        $GLOBALS['files_root'] = ROOT . '/definitely-not-a-directory';

        $precheck = gojs_backup_restore_precheck($this->filename, false);

        $this->assertFalse($precheck['ok']);
        $this->assertNotEmpty($precheck['errors']);
    }

    public function testPrecheckListsKnownDatabaseConnections(): void
    {
        $this->makeArchive();

        $precheck = gojs_backup_restore_precheck($this->filename, false);

        $this->assertArrayHasKey('known_databases', $precheck);
        $this->assertIsArray($precheck['known_databases']);
    }

    public function testArchiveBuiltFromSourcePassesVerification(): void
    {
        $source = rtrim(sys_get_temp_dir(), '/\\') . '/gojs-backup-src-' . bin2hex(random_bytes(4));
        @mkdir($source . '/nested', 0700, true);
        file_put_contents($source . '/app.php', "<?php\nreturn 2;\n");
        file_put_contents($source . '/nested/data.txt', "payload\n");

        $path = $this->path();
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        $count = gojs_backup_add_dir($zip, $source, 'files/', array());
        $zip->addFromString('backup.json', json_encode(array('created_at' => '2026-09-17 12:00:00')));
        $zip->close();

        $manifest = gojs_backup_integrity_build_manifest_from_file($path);
        $this->assertIsArray($manifest);
        $this->assertTrue(gojs_backup_integrity_append_manifest($path, $manifest));

        $result = gojs_backup_verify_archive($this->filename);
        $precheck = gojs_backup_restore_precheck($this->filename, true);

        @unlink($source . '/app.php');
        @unlink($source . '/nested/data.txt');
        @rmdir($source . '/nested');
        @rmdir($source);

        $this->assertGreaterThanOrEqual(2, $count);
        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['code']);
        $this->assertContains('files/app.php', array_column($manifest['entries'], 'name'));
        $this->assertTrue($precheck['ok']);
        $this->assertSame('ok', $precheck['code']);
    }

    public function testDigestChangesWithTheEntryList(): void
    {
        $entries = array(
            array('name' => 'a.txt', 'dir' => false, 'size' => 1, 'sha256' => hash('sha256', 'a')),
        );
        $first = gojs_backup_integrity_digest($entries);

        $entries[] = array('name' => 'b.txt', 'dir' => false, 'size' => 1, 'sha256' => hash('sha256', 'b'));
        $second = gojs_backup_integrity_digest($entries);

        $this->assertNotSame($first, $second);
    }

    public function testDigestChangesWithEntryContent(): void
    {
        $base = array(array('name' => 'a.txt', 'dir' => false, 'size' => 1, 'sha256' => hash('sha256', 'a')));
        $first = gojs_backup_integrity_digest($base);

        $base[0]['sha256'] = hash('sha256', 'b');
        $second = gojs_backup_integrity_digest($base);

        $this->assertNotSame($first, $second);
    }

    public function testSidecarRoundTrip(): void
    {
        $hash = str_repeat('a', 64);
        $this->assertTrue(gojs_backup_integrity_write_sidecar('backup-20260917-120001.zip', $hash));
        $this->assertSame($hash, gojs_backup_integrity_read_sidecar('backup-20260917-120001.zip'));
    }

    public function testSidecarRejectsGarbage(): void
    {
        file_put_contents(gojs_backup_integrity_sidecar_path($this->filename), 'not-a-hash');

        $this->assertSame('', gojs_backup_integrity_read_sidecar($this->filename));
    }
}
