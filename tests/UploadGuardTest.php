<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

class UploadGuardTest extends TestCase
{
    private $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/gojs-upload-guard-' . getmypid() . '-' . uniqid();
        @mkdir($this->tmpDir, 0700, true);
        $GLOBALS['config'] = array();
        \GoJS_Context::instance()->reset()->setConfig(array())->setFilesRoot($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpDir);
        \GoJS_Context::instance()->reset();
        $GLOBALS['config'] = array();
        parent::tearDown();
    }

    private function removeTree($dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function writeFile($name, $bytes)
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $bytes);
        return $path;
    }

    public function testDefaultBlockedExtensionsCoverPhpFamily(): void
    {
        $extensions = gojs_upload_guard_default_blocked_extensions();

        foreach (array('php', 'phtml', 'phar', 'pht', 'phps', 'inc') as $expected) {
            $this->assertContains($expected, $extensions);
        }
        $this->assertNotContains('jpg', $extensions);
        $this->assertNotContains('js', $extensions);
    }

    public function testDefaultProtectedNamesCoverServerConfigFiles(): void
    {
        $names = gojs_upload_guard_default_protected_names();

        foreach (array('.htaccess', '.htpasswd', '.user.ini', 'php.ini', '.env', 'web.config') as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function testDefaultPhpPayloadExemptExtensionsMatchLegacyBehaviour(): void
    {
        $this->assertSame(
            array('txt', 'sql', 'md', 'html'),
            gojs_upload_guard_default_php_payload_exempt_extensions()
        );
    }

    public function testSettingsExposeDocumentedDefaults(): void
    {
        $settings = gojs_upload_guard_settings();

        $this->assertTrue($settings['enforce']);
        $this->assertTrue($settings['sniff']);
        $this->assertTrue($settings['block_active_content']);
        $this->assertTrue($settings['block_php_payload']);
        $this->assertFalse($settings['allow_active_svg']);
        $this->assertSame(200, $settings['max_filename_bytes']);
        $this->assertSame(0, $settings['max_scan_bytes']);
        $this->assertSame(array(), $settings['blocked_extensions']);
        $this->assertSame(array(), $settings['allowed_extensions']);
        $this->assertSame(array(), $settings['protected_names']);
    }

    public function testSettingsReadBooleanFlagsAndNumbersFromConfig(): void
    {
        $GLOBALS['config'] = array(
            'upload_guard' => array(
                'enforce' => false,
                'sniff' => false,
                'block_active_content' => false,
                'block_php_payload' => false,
                'allow_active_svg' => true,
                'max_filename_bytes' => 32,
                'max_scan_bytes' => 4096,
            ),
        );

        $settings = gojs_upload_guard_settings();

        $this->assertFalse($settings['enforce']);
        $this->assertFalse($settings['sniff']);
        $this->assertFalse($settings['block_active_content']);
        $this->assertFalse($settings['block_php_payload']);
        $this->assertTrue($settings['allow_active_svg']);
        $this->assertSame(32, $settings['max_filename_bytes']);
        $this->assertSame(4096, $settings['max_scan_bytes']);
    }

    public function testSettingsClampNumericValues(): void
    {
        $GLOBALS['config'] = array(
            'upload_guard' => array('max_filename_bytes' => 1, 'max_scan_bytes' => -10),
        );

        $settings = gojs_upload_guard_settings();

        $this->assertSame(16, $settings['max_filename_bytes']);
        $this->assertSame(0, $settings['max_scan_bytes']);
    }

    public function testSettingsNormaliseExtensionLists(): void
    {
        $GLOBALS['config'] = array(
            'upload_guard' => array(
                'blocked_extensions' => array('.PHP', ' Phtml ', 'phtml', '', 42, 'jsp'),
                'allowed_extensions' => array('JPG', '.png'),
            ),
        );

        $settings = gojs_upload_guard_settings();

        $this->assertSame(array('php', 'phtml', 'jsp'), $settings['blocked_extensions']);
        $this->assertSame(array('jpg', 'png'), $settings['allowed_extensions']);
    }

    public function testSettingsKeepLeadingDotsForProtectedNames(): void
    {
        $GLOBALS['config'] = array(
            'upload_guard' => array('protected_names' => array('.HTACCESS', 'php.ini', ' .env ')),
        );

        $settings = gojs_upload_guard_settings();

        $this->assertSame(array('.htaccess', 'php.ini', '.env'), $settings['protected_names']);
    }

    public function testBlockedExtensionsMergeDefaultsWithConfig(): void
    {
        $settings = array('blocked_extensions' => array('jsp', 'asp'));

        $extensions = gojs_upload_guard_blocked_extensions($settings);

        $this->assertContains('php', $extensions);
        $this->assertContains('jsp', $extensions);
        $this->assertContains('asp', $extensions);
    }

    public function testProtectedNamesMergeDefaultsWithConfig(): void
    {
        $settings = array('protected_names' => array('web.config.bak'));

        $names = gojs_upload_guard_protected_names($settings);

        $this->assertContains('.htaccess', $names);
        $this->assertContains('web.config.bak', $names);
    }

    public function testStripFormatCharactersRemovesZeroWidthAndBidiSequences(): void
    {
        $name = ".htac\xe2\x80\x8bcess\xe2\x80\xae.txt\xef\xbb\xbf";

        $this->assertSame('.htaccess.txt', gojs_upload_guard_strip_format_characters($name));
    }

    public function testCleanNameDropsDirectoriesControlsAndTrailingDots(): void
    {
        $this->assertSame('report.txt', gojs_upload_guard_clean_name('../../etc/report.txt'));
        $this->assertSame('report.txt', gojs_upload_guard_clean_name('C:\\temp\\Report.TXT'));
        $this->assertSame('payload.php', gojs_upload_guard_clean_name("payload.php.\r\n"));
        $this->assertSame('payload.php', gojs_upload_guard_clean_name('payload.php   '));
        $this->assertSame('payload.php', gojs_upload_guard_clean_name("pay\x00load.php"));
    }

    public function testExtensionCandidatesCollectEveryDotSegment(): void
    {
        $this->assertSame(array('php', 'jpg'), gojs_upload_guard_extension_candidates('photo.php.jpg'));
        $this->assertSame(array('php'), gojs_upload_guard_extension_candidates('photo.PHP'));
        $this->assertSame(array('php'), gojs_upload_guard_extension_candidates('photo..php'));
        $this->assertSame(array('php'), gojs_upload_guard_extension_candidates('photo.php.'));
    }

    public function testExtensionCandidatesDecodePercentEncoding(): void
    {
        $candidates = gojs_upload_guard_extension_candidates('photo.ph%70');

        $this->assertContains('php', $candidates);
    }

    public function testExtensionCandidatesSplitSemicolonAndColonPayloads(): void
    {
        $this->assertContains('php', gojs_upload_guard_extension_candidates('photo.php;.jpg'));
        $this->assertContains('php', gojs_upload_guard_extension_candidates("photo.php\x20.jpg"));
    }

    public function testLastExtensionUsesTheFinalSegment(): void
    {
        $this->assertSame('jpg', gojs_upload_guard_last_extension('photo.php.jpg'));
        $this->assertSame('', gojs_upload_guard_last_extension('README'));
        $this->assertSame('', gojs_upload_guard_last_extension('...'));
    }

    public function testExtensionFamilyMapping(): void
    {
        $cases = array(
            'a.php' => 'php',
            'a.phtml' => 'php',
            'a.html' => 'html',
            'a.svg' => 'svg',
            'a.png' => 'image',
            'a.zip' => 'archive',
            'a.mp3' => 'audio',
            'a.mp4' => 'video',
            'a.pdf' => 'pdf',
            'a.woff2' => 'font',
            'a.docx' => 'office',
            'a.exe' => 'executable',
            'a.sh' => 'script',
            'a.log' => 'text',
            'a.unknown-ext' => 'other',
            'README' => 'other',
        );

        foreach ($cases as $name => $expected) {
            $this->assertSame($expected, gojs_upload_guard_extension_family($name), $name);
        }
    }

    public function testMimeFamilyMapping(): void
    {
        $cases = array(
            'image/png' => 'image',
            'image/svg+xml' => 'svg',
            'text/html' => 'html',
            'text/x-php' => 'php',
            'application/pdf' => 'pdf',
            'application/zip' => 'archive',
            'application/x-dosexec' => 'executable',
            'text/x-shellscript' => 'script',
            'text/plain' => 'text',
            'application/json' => 'text',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'office',
            'application/octet-stream' => 'binary',
            '' => 'unknown',
        );

        foreach ($cases as $mime => $expected) {
            $this->assertSame($expected, gojs_upload_guard_mime_family($mime), $mime);
        }
    }

    public function testSniffMagicDetectsContainerSignatures(): void
    {
        $png = $this->writeFile('m.png', "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 32));
        $zip = $this->writeFile('m.zip', "PK\x03\x04" . str_repeat("\x11", 32));
        $gif = $this->writeFile('m.gif', 'GIF89a' . str_repeat('A', 32));

        $this->assertSame('image/png', gojs_upload_guard_sniff_magic($png));
        $this->assertSame('application/zip', gojs_upload_guard_sniff_magic($zip));
        $this->assertSame('image/gif', gojs_upload_guard_sniff_magic($gif));
    }

    public function testSniffMagicFallsBackToTextAndBinary(): void
    {
        $text = $this->writeFile('m.txt', "plain text payload\n");
        $binary = $this->writeFile('m.bin', "AB\x00\x01\x02CD");

        $this->assertSame('text/plain', gojs_upload_guard_sniff_magic($text));
        $this->assertSame('application/octet-stream', gojs_upload_guard_sniff_magic($binary));
    }

    public function testSniffReturnsEmptyStringForMissingFile(): void
    {
        $this->assertSame('', gojs_upload_guard_sniff($this->tmpDir . '/absent.png'));
    }

    public function testScanFileDetectsServerSideAndScriptMarkers(): void
    {
        $php = $this->writeFile('scan-php.txt', "prefix\n<?php echo 1;\n");
        $short = $this->writeFile('scan-short.txt', "value <?= \$a ?>\n");
        $script = $this->writeFile('scan-script.txt', "prefix\n<script>alert(1)</script>\n");

        $this->assertArrayHasKey('php_tag', gojs_upload_guard_scan_file($php));
        $this->assertArrayHasKey('php_tag', gojs_upload_guard_scan_file($short));
        $this->assertArrayHasKey('script_tag', gojs_upload_guard_scan_file($script));
    }

    public function testScanFileDetectsActiveMarkupFindings(): void
    {
        $svg = $this->writeFile(
            'scan.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><title onclick="x()">t</title>'
            . '<foreignObject></foreignObject><iframe src="//evil"></iframe>'
            . '<a href="javascript:alert(1)">a</a><!ENTITY xxe SYSTEM "file:///etc/passwd">'
            . '<meta http-equiv="refresh" content="0"></svg>'
        );

        $findings = gojs_upload_guard_scan_file($svg);

        $this->assertArrayHasKey('svg_element', $findings);
        $this->assertArrayHasKey('event_attribute', $findings);
        $this->assertArrayHasKey('foreign_object', $findings);
        $this->assertArrayHasKey('frame_element', $findings);
        $this->assertArrayHasKey('javascript_uri', $findings);
        $this->assertArrayHasKey('external_entity', $findings);
        $this->assertArrayHasKey('meta_refresh', $findings);
    }

    public function testScanFileOnlyMatchesPrefixPatternsAtTheStart(): void
    {
        $prefix = $this->writeFile('prefix-asp.txt', "  <% Response.Write 1 %>");
        $inline = $this->writeFile('inline-asp.txt', "padding padding <% not a prefix %>");
        $prefixScript = $this->writeFile('prefix-script.txt', "  <script>alert(1)</script>");
        $inlineScript = $this->writeFile('inline-script.txt', "padding padding <script>alert(1)</script>");

        $this->assertArrayHasKey('asp_tag', gojs_upload_guard_scan_file($prefix));
        $this->assertArrayNotHasKey('asp_tag', gojs_upload_guard_scan_file($inline));
        $this->assertArrayHasKey('script_magic', gojs_upload_guard_scan_file($prefixScript));
        $this->assertArrayNotHasKey('script_magic', gojs_upload_guard_scan_file($inlineScript));
    }

    public function testScanFileHonoursMaxScanBytes(): void
    {
        $path = $this->writeFile('late.php', str_repeat('A', 200000) . '<?php echo 1;');

        $limited = gojs_upload_guard_scan_file($path, 1024);
        $full = gojs_upload_guard_scan_file($path, 0);

        $this->assertArrayNotHasKey('php_tag', $limited);
        $this->assertArrayHasKey('php_tag', $full);
    }

    public function testCheckNameRejectsNestedAndCaseVariedScriptExtensions(): void
    {
        foreach (array('photo.php.jpg', 'photo.PHTML', 'photo.pHp5', 'shell.phar') as $name) {
            $result = gojs_upload_guard_check_name($name);
            $this->assertFalse($result['ok'], $name);
            $this->assertSame('blocked_extension', $result['code'], $name);
        }
    }

    public function testCheckNameRejectsTrailingCharacterBypasses(): void
    {
        foreach (array('payload.php.', 'payload.php ', "payload.php\t", 'payload.php;') as $name) {
            $result = gojs_upload_guard_check_name($name);
            $this->assertFalse($result['ok'], $name);
            $this->assertSame('blocked_extension', $result['code'], $name);
        }
    }

    public function testCheckNameRejectsEncodedAndSemicolonBypasses(): void
    {
        foreach (array('payload.ph%70', 'payload.php%2e', 'payload.php;.jpg', 'payload.php:.jpg') as $name) {
            $result = gojs_upload_guard_check_name($name);
            $this->assertFalse($result['ok'], $name);
            $this->assertSame('blocked_extension', $result['code'], $name);
        }
    }

    public function testCheckNameRejectsProtectedServerConfigNames(): void
    {
        foreach (array('.htaccess', '.HTACCESS', '.user.ini', 'php.ini', '.env', 'web.config') as $name) {
            $result = gojs_upload_guard_check_name($name);
            $this->assertFalse($result['ok'], $name);
            $this->assertSame('protected_name', $result['code'], $name);
        }
    }

    public function testCheckNameRejectsProtectedNameHiddenByZeroWidthCharacter(): void
    {
        $result = gojs_upload_guard_check_name(".htac\xe2\x80\x8bcess");

        $this->assertFalse($result['ok']);
        $this->assertSame('protected_name', $result['code']);
    }

    public function testCheckNameRejectsOverlongAndEmptyNames(): void
    {
        $long = str_repeat('a', 260) . '.txt';
        $result = gojs_upload_guard_check_name($long);
        $this->assertFalse($result['ok']);
        $this->assertSame('filename_too_long', $result['code']);

        $empty = gojs_upload_guard_check_name('...');
        $this->assertFalse($empty['ok']);
        $this->assertSame('invalid_name', $empty['code']);
    }

    public function testCheckNameAcceptsOrdinaryNames(): void
    {
        foreach (array('report.txt', 'photo-2024.jpg', 'archive.tar.gz', 'README') as $name) {
            $result = gojs_upload_guard_check_name($name);
            $this->assertTrue($result['ok'], $name);
            $this->assertSame('ok', $result['code'], $name);
        }
    }

    public function testCheckNameEnforcesAllowListWhenConfigured(): void
    {
        $settings = array('allowed_extensions' => array('png', 'jpg'));

        $this->assertSame('ok', gojs_upload_guard_check_name('photo.png', $settings)['code']);
        $this->assertSame('ok', gojs_upload_guard_check_name('photo.JPG', $settings)['code']);

        $denied = gojs_upload_guard_check_name('animation.gif', $settings);
        $this->assertFalse($denied['ok']);
        $this->assertSame('extension_not_allowed', $denied['code']);

        $this->assertSame('extension_not_allowed', gojs_upload_guard_check_name('README', $settings)['code']);
    }

    public function testCheckFileRejectsServerSidePayloadInsideImage(): void
    {
        $path = $this->writeFile('poly.jpg', 'GIF89a' . str_repeat('A', 32) . '<?php echo 1; ?>');

        $result = gojs_upload_guard_check_file($path, 'poly.jpg');

        $this->assertFalse($result['ok']);
        $this->assertSame('php_payload', $result['code']);
        $this->assertContains('php_tag', $result['details']['findings']);
    }

    public function testCheckFileKeepsLegacyExemptExtensions(): void
    {
        $path = $this->writeFile('notes.txt', '<?php echo 1; ?>');

        $result = gojs_upload_guard_check_file($path, 'notes.txt');

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['code']);
    }

    public function testCheckFileRejectsActiveSvgContent(): void
    {
        $path = $this->writeFile('banner.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $result = gojs_upload_guard_check_file($path, 'banner.svg');

        $this->assertFalse($result['ok']);
        $this->assertSame('active_svg', $result['code']);
        $this->assertContains('script_tag', $result['details']['matched']);
    }

    public function testCheckFileAllowsActiveSvgWhenOptedIn(): void
    {
        $path = $this->writeFile('banner.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $result = gojs_upload_guard_check_file($path, 'banner.svg', array(
            'settings' => array('allow_active_svg' => true),
        ));

        $this->assertTrue($result['ok']);
        $this->assertSame('ok', $result['code']);
    }

    public function testCheckFileAcceptsCleanSvgAndPlainImages(): void
    {
        $svg = $this->writeFile('clean.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="4" height="4"/></svg>');
        $png = $this->writeFile('clean.png', "\x89PNG\r\n\x1a\n" . str_repeat("\x7f", 256));

        $this->assertSame('ok', gojs_upload_guard_check_file($svg, 'clean.svg')['code']);
        $this->assertSame('ok', gojs_upload_guard_check_file($png, 'clean.png')['code']);
    }

    public function testCheckFileRejectsHtmlDisguisedAsImage(): void
    {
        $path = $this->writeFile('fake.png', '<!DOCTYPE html><html><body>hi</body></html>');

        $result = gojs_upload_guard_check_file($path, 'fake.png');

        $this->assertFalse($result['ok']);
        $this->assertSame('type_spoof', $result['code']);
        $this->assertSame('image', $result['details']['declared_family']);
    }

    public function testCheckFileRejectsActiveMarkupInsideArchive(): void
    {
        $path = $this->writeFile('bundle.zip', "PK\x03\x04<script>alert(1)</script>");

        $result = gojs_upload_guard_check_file($path, 'bundle.zip');

        $this->assertFalse($result['ok']);
        $this->assertSame('active_content', $result['code']);
    }

    public function testCheckFileAllowsHtmlPagesAndPlainArchives(): void
    {
        $html = $this->writeFile('index.html', '<!DOCTYPE html><html><body><script>init()</script></body></html>');
        $zip = $this->writeFile('plain.zip', "PK\x03\x04" . str_repeat("\x11", 128));

        $this->assertSame('ok', gojs_upload_guard_check_file($html, 'index.html')['code']);
        $this->assertSame('ok', gojs_upload_guard_check_file($zip, 'plain.zip')['code']);
    }

    public function testCheckFileReportsUnreadableTargets(): void
    {
        $result = gojs_upload_guard_check_file($this->tmpDir . '/absent.png', 'absent.png');

        $this->assertFalse($result['ok']);
        $this->assertSame('unreadable', $result['code']);
    }

    public function testCheckFileSkipsEverythingWhenEnforceIsOff(): void
    {
        $path = $this->writeFile('poly.jpg', 'GIF89a<?php echo 1; ?>');

        $result = gojs_upload_guard_check_file($path, 'poly.jpg', array(
            'settings' => array('enforce' => false),
        ));

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['details']['enforced']);
    }

    public function testCheckFileHonoursSniffingDisabled(): void
    {
        $path = $this->writeFile('fake.png', '<!DOCTYPE html><html><body>hi</body></html>');

        $result = gojs_upload_guard_check_file($path, 'fake.png', array(
            'settings' => array('sniff' => false),
        ));

        $this->assertTrue($result['ok']);
        $this->assertSame('', $result['details']['sniffed_mime']);
    }

    public function testReportExposesTheActivePolicy(): void
    {
        $report = gojs_upload_guard_report();

        $this->assertTrue($report['enforce']);
        $this->assertTrue($report['sniff']);
        $this->assertSame(200, $report['max_filename_bytes']);
        $this->assertContains('php', $report['blocked_extensions']);
        $this->assertContains('.htaccess', $report['protected_names']);
        $this->assertSame(array('txt', 'sql', 'md', 'html'), $report['php_payload_exempt_extensions']);
        $this->assertIsBool($report['sniff_available']);
    }

    public function testReportReflectsConfigOverrides(): void
    {
        $GLOBALS['config'] = array(
            'upload_guard' => array(
                'allow_active_svg' => true,
                'allowed_extensions' => array('png'),
                'blocked_extensions' => array('jsp'),
                'max_filename_bytes' => 64,
            ),
        );

        $report = gojs_upload_guard_report();

        $this->assertTrue($report['allow_active_svg']);
        $this->assertSame(array('png'), $report['allowed_extensions']);
        $this->assertContains('jsp', $report['blocked_extensions']);
        $this->assertContains('php', $report['blocked_extensions']);
        $this->assertSame(64, $report['max_filename_bytes']);
    }

    public function testResultHelperAlwaysReturnsTheDocumentedShape(): void
    {
        $result = gojs_upload_guard_result(false, 'blocked_extension', 'message', array('a' => 1));

        $this->assertSame(array('ok', 'code', 'message', 'details'), array_keys($result));
        $this->assertFalse($result['ok']);
        $this->assertSame('blocked_extension', $result['code']);
        $this->assertSame(array('a' => 1), $result['details']);

        $fallback = gojs_upload_guard_result(true, 'ok', 'message', 'not-an-array');
        $this->assertSame(array(), $fallback['details']);
    }

    public function testActiveFindingsHelperReturnsOnlyMatchedRules(): void
    {
        $findings = array('script_tag' => true, 'svg_element' => true);

        $matched = gojs_upload_guard_active_findings(
            $findings,
            array('script_tag', 'foreign_object', 'event_attribute')
        );

        $this->assertSame(array('script_tag'), $matched);
        $this->assertSame(array(), gojs_upload_guard_active_findings(array(), array('script_tag')));
    }

    public function testTypeSpoofHelperOnlyFlagsActiveOrExecutableActualTypes(): void
    {
        $this->assertTrue(gojs_upload_guard_type_spoofed('image', 'html'));
        $this->assertTrue(gojs_upload_guard_type_spoofed('archive', 'script'));
        $this->assertTrue(gojs_upload_guard_type_spoofed('text', 'executable'));
        $this->assertFalse(gojs_upload_guard_type_spoofed('archive', 'office'));
        $this->assertFalse(gojs_upload_guard_type_spoofed('image', 'binary'));
        $this->assertFalse(gojs_upload_guard_type_spoofed('image', 'image'));
        $this->assertFalse(gojs_upload_guard_type_spoofed('image', 'unknown'));
        $this->assertFalse(gojs_upload_guard_type_spoofed('', 'html'));
    }
}
