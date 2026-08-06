<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

/**
 * 文件操作与安全路径校验（backend/files.php）单元测试。
 *
 * 通过 GoJS_Context::instance()->setFilesRoot(...) 注入虚拟文件根目录，
 * 不依赖真实 HTTP 服务器。
 */
class FilesTest extends TestCase
{
    /** @var string 临时文件根目录 */
    private $filesRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->filesRoot = sys_get_temp_dir() . '/gojs-files-' . getmypid() . '-' . uniqid();
        @mkdir($this->filesRoot, 0700, true);
        $GLOBALS['config'] = array();
        \GoJS_Context::instance()->reset()->setConfig(array())->setFilesRoot($this->filesRoot);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->filesRoot);
        \GoJS_Context::instance()->reset();
        $GLOBALS['config'] = array();
        parent::tearDown();
    }

    private function rrmdir($dir): void
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
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function testResolveFilesRootFromContext(): void
    {
        \GoJS_Context::instance()->setFilesRoot('/ctx/root');
        $this->assertSame('/ctx/root', gojs_resolve_files_root());
    }

    public function testResolveFilesRootFallsBackToGlobals(): void
    {
        \GoJS_Context::instance()->setFilesRoot('');
        $GLOBALS['files_root'] = '/global/root';
        $this->assertSame('/global/root', gojs_resolve_files_root());
        $GLOBALS['files_root'] = ROOT;
    }

    public function testSafePathResolvesExistingFile(): void
    {
        @mkdir($this->filesRoot . '/sub', 0700, true);
        file_put_contents($this->filesRoot . '/sub/hello.txt', 'hi');
        $resolved = gojs_safe_path('sub/hello.txt');
        $this->assertSame(realpath($this->filesRoot . '/sub/hello.txt'), $resolved);
    }

    public function testSafePathRejectsTraversal(): void
    {
        $resolved = gojs_safe_path('../outside');
        $this->assertFalse($resolved);
    }

    public function testSafePathRejectsNonExistentParentOutsideRoot(): void
    {
        // parent 目录在根之外且不存在 -> 应被拒绝
        $resolved = gojs_safe_path('../../etc/passwd');
        $this->assertFalse($resolved);
    }

    public function testSafePathAllowsNewFileInsideRoot(): void
    {
        $resolved = gojs_safe_path('newfile.txt');
        $this->assertNotFalse($resolved);
        $this->assertStringStartsWith(realpath($this->filesRoot), $resolved);
    }

    public function testSafePathRejectsDoubleDotInBasename(): void
    {
        $resolved = gojs_safe_path('sub/..hidden');
        // basename 含 '..' 的新建路径应被拒绝
        $this->assertFalse($resolved);
    }

    public function testRelativePathRoot(): void
    {
        $this->assertSame('/', gojs_relative_path(realpath($this->filesRoot)));
    }

    public function testRelativePathSubdir(): void
    {
        @mkdir($this->filesRoot . '/sub', 0700, true);
        $this->assertSame('/sub', gojs_relative_path(realpath($this->filesRoot . '/sub')));
    }

    public function testRelativePathOutsideRootReturnsOriginal(): void
    {
        $this->assertSame('/tmp/somewhere', gojs_relative_path('/tmp/somewhere'));
    }

    public function testGetFileType(): void
    {
        @mkdir($this->filesRoot . '/adir', 0700, true);
        file_put_contents($this->filesRoot . '/file.txt', 'x');
        $this->assertSame('dir', gojs_get_file_type($this->filesRoot . '/adir'));
        $this->assertSame('file', gojs_get_file_type($this->filesRoot . '/file.txt'));
    }

    public function testGetPerms(): void
    {
        file_put_contents($this->filesRoot . '/perm.txt', 'x');
        @chmod($this->filesRoot . '/perm.txt', 0644);
        $this->assertSame('0644', gojs_get_perms($this->filesRoot . '/perm.txt'));
    }

    public function testGetFileInfo(): void
    {
        file_put_contents($this->filesRoot . '/info.txt', 'hello');
        $info = gojs_get_file_info($this->filesRoot . '/info.txt', '/info.txt');
        $this->assertSame('info.txt', $info['name']);
        $this->assertSame('/info.txt', $info['path']);
        $this->assertSame('file', $info['type']);
        $this->assertSame(5, $info['size']);
    }

    public function testIsProtectedPathForConfigDir(): void
    {
        $this->assertTrue(gojs_is_protected_path(CONFIG_DIR));
        $this->assertTrue(gojs_is_protected_path(CONFIG_FILE));
    }

    public function testIsProtectedPathForApiPhp(): void
    {
        $this->assertTrue(gojs_is_protected_path(ROOT . '/api.php'));
    }

    public function testIsProtectedPathForPublicAssetFalse(): void
    {
        $this->assertFalse(gojs_is_protected_path(ROOT . '/index.html'));
    }

    public function testNormalizeFilesArraySingle(): void
    {
        $files = array(
            'name' => 'a.txt',
            'type' => 'text/plain',
            'tmp_name' => '/tmp/php123',
            'error' => 0,
            'size' => 10,
        );
        $result = gojs_normalize_files_array($files);
        $this->assertCount(1, $result);
        $this->assertSame('a.txt', $result[0]['name']);
    }

    public function testNormalizeFilesArrayMultiple(): void
    {
        $files = array(
            'name' => array('a.txt', '', 'b.txt'),
            'type' => array('text/plain', 'text/plain', 'text/plain'),
            'tmp_name' => array('/tmp/1', '/tmp/2', '/tmp/3'),
            'error' => array(0, 0, 0),
            'size' => array(1, 2, 3),
        );
        $result = gojs_normalize_files_array($files);
        $this->assertCount(2, $result);
        $this->assertSame('a.txt', $result[0]['name']);
        $this->assertSame('b.txt', $result[1]['name']);
    }

    public function testUploadErrorMessage(): void
    {
        $this->assertIsString(gojs_upload_error_message(UPLOAD_ERR_INI_SIZE));
        $this->assertIsString(gojs_upload_error_message(9999));
    }

    public function testUniquePath(): void
    {
        file_put_contents($this->filesRoot . '/dup.txt', 'x');
        $unique = gojs_unique_path($this->filesRoot . '/dup.txt');
        $this->assertSame($this->filesRoot . '/dup (1).txt', $unique);
    }

    public function testDetectPhpMagic(): void
    {
        $file = $this->filesRoot . '/file.jpg';
        file_put_contents($file, '<?php echo 1;');
        $this->assertTrue(gojs_detect_php_magic($file, 'file.jpg'));
        $this->assertFalse(gojs_detect_php_magic($file, 'file.txt'));
    }
}