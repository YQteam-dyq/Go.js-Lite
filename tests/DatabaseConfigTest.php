<?php

namespace Gojs\Tests;

use PHPUnit\Framework\TestCase;

/**
 * 数据库连接/元数据与配置读写（backend/database.php、backend/common.php）单元测试。
 */
class DatabaseConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['config'] = array();
        \GoJS_Context::instance()->reset()->setConfig(array());
    }

    protected function tearDown(): void
    {
        $GLOBALS['config'] = array();
        \GoJS_Context::instance()->reset();
        parent::tearDown();
    }

    public function testReturnBytes(): void
    {
        $this->assertSame(0, gojs_return_bytes(''));
        $this->assertSame(0, gojs_return_bytes('0'));
        $this->assertSame(1024, gojs_return_bytes('1K'));
        $this->assertSame(1024, gojs_return_bytes('1k'));
        $this->assertSame(2 * 1024 * 1024, gojs_return_bytes('2M'));
        $this->assertSame(3 * 1024 * 1024 * 1024, gojs_return_bytes('3G'));
        $this->assertSame(5, gojs_return_bytes('5'));
    }

    public function testSaveConfigWritesFile(): void
    {
        $data = array(
            'installed' => true,
            'password_hash' => 'hash123',
            'session_timeout' => 1800,
        );
        $GLOBALS['config'] = $data;
        gojs_save_config();

        $this->assertFileExists(CONFIG_FILE);
        $loaded = include CONFIG_FILE;
        $this->assertSame($data, $loaded);
    }

    public function testSqlSplitStatements(): void
    {
        $sql = "SELECT 1;\nSELECT 'a;b';\nSELECT 2;";
        $stmts = gojs_sql_split_statements($sql);
        $this->assertCount(3, $stmts);
        $this->assertSame('SELECT 1', $stmts[0]);
        $this->assertSame("SELECT 'a;b'", $stmts[1]);
        $this->assertSame('SELECT 2', $stmts[2]);
    }

    public function testSqlSplitUnterminatedLastStatement(): void
    {
        $stmts = gojs_sql_split_statements('CREATE TABLE t (id INT)');
        $this->assertCount(1, $stmts);
        $this->assertSame('CREATE TABLE t (id INT)', $stmts[0]);
    }

    public function testSqlStripComments(): void
    {
        $sql = "-- header comment\nSELECT * FROM t;\n# hash comment\n";
        $cleaned = gojs_sql_strip_comments($sql);
        $this->assertStringContainsString('SELECT * FROM t;', $cleaned);
        $this->assertStringNotContainsString('header comment', $cleaned);
    }

    public function testSqlDetectDangerousStatements(): void
    {
        $dangerous = gojs_sql_detect_dangerous_statements("DROP DATABASE app");
        $this->assertNotEmpty($dangerous);
    }

    public function testSqlDetectNoDangerousStatements(): void
    {
        $dangerous = gojs_sql_detect_dangerous_statements("SELECT * FROM users WHERE id = 1");
        $this->assertEmpty($dangerous);
    }

    public function testDbConnectionsLoadEmpty(): void
    {
        if (file_exists(DB_CONNECTIONS_FILE)) {
            @unlink(DB_CONNECTIONS_FILE);
        }
        $this->assertSame(array(), gojs_load_db_connections());
    }

    public function testDbConnectionsSaveAndLoadRoundtrip(): void
    {
        $connections = array(
            array(
                'id' => 'conn_1',
                'name' => 'local',
                'host' => 'localhost',
                'port' => 3306,
                'username' => 'root',
                'password' => 'encrypted',
                'database' => 'app',
            ),
        );
        $this->assertTrue(gojs_save_db_connections($connections));
        $this->assertSame($connections, gojs_load_db_connections());
    }

    public function testGetDbConnectionById(): void
    {
        $connections = array(
            array('id' => 'conn_a', 'name' => 'A'),
            array('id' => 'conn_b', 'name' => 'B'),
        );
        gojs_save_db_connections($connections);
        $conn = gojs_get_db_connection('conn_b');
        $this->assertSame('B', $conn['name']);
        $this->assertNull(gojs_get_db_connection('missing'));
    }

    public function testDbConnectReturnsFailureWithoutExtension(): void
    {
        // 保持纯单元：不依赖真实 MySQL；若扩展缺失返回失败，若存在也会因连接失败返回失败
        $conn = array(
            'host' => '127.0.0.1',
            'port' => 1,
            'username' => 'nouser',
            'password' => '',
            'database' => '',
        );
        $result = gojs_db_connect($conn);
        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('error', $result);
    }

    public function testDbEscapeValueNull(): void
    {
        $this->assertSame('NULL', gojs_db_escape_value(null, 'pdo', null));
    }

    public function testDbEscapeValueInt(): void
    {
        $this->assertSame('42', gojs_db_escape_value(null, 'pdo', 42));
    }

    public function testGetCapabilitiesStructure(): void
    {
        $caps = gojs_get_capabilities();
        $this->assertArrayHasKey('phpVersion', $caps);
        $this->assertArrayHasKey('maxUpload', $caps);
        $this->assertArrayHasKey('mysql', $caps);
    }

    public function testSealAndUnsealSecret(): void
    {
        $GLOBALS['config'] = array('encryption_key' => 'test-key-abcdefghijklmnop');
        $sealed = gojs_seal_secret('secret-plain');
        $this->assertSame('secret-plain', gojs_unseal_secret($sealed));
    }
}