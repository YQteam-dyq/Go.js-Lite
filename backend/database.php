<?php




function gojs_load_db_connections() {
    if (!file_exists(DB_CONNECTIONS_FILE)) {
        return array();
    }

    $content = @file_get_contents(DB_CONNECTIONS_FILE);
    if (!$content) {
        return array();
    }

    $data = json_decode($content, true);
    if (!is_array($data)) {
        return array();
    }

    return $data;
}

function gojs_save_db_connections($connections) {
    if (!is_dir(CONFIG_DIR)) {
        @mkdir(CONFIG_DIR, 0700, true);
    }

    $content = json_encode($connections, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $result = @file_put_contents(DB_CONNECTIONS_FILE, $content, LOCK_EX);

    if ($result !== false) {
        @chmod(DB_CONNECTIONS_FILE, 0600);
    }

    return $result !== false;
}

function gojs_get_db_connection($id) {
    $connections = gojs_load_db_connections();

    foreach ($connections as $conn) {
        if (!empty($conn['id']) && $conn['id'] === $id) {

            if (!empty($conn['password'])) {
                $conn['password'] = gojs_decrypt($conn['password']);
            }
            return $conn;
        }
    }

    return null;
}

function gojs_db_connect($conn) {
    $capabilities = gojs_get_capabilities();

    $host = !empty($conn['host']) ? $conn['host'] : 'localhost';
    $port = !empty($conn['port']) ? (int)$conn['port'] : 3306;
    $username = !empty($conn['username']) ? $conn['username'] : '';
    $password = !empty($conn['password']) ? $conn['password'] : '';
    $database = !empty($conn['database']) ? $conn['database'] : '';

    if (extension_loaded('mysqli')) {
        $old_report = null;
        try {
            $old_report = mysqli_report(MYSQLI_REPORT_OFF);
            $mysqli = @new mysqli($host, $username, $password, $database, $port);
            if ($mysqli->connect_error || $mysqli->connect_errno) {
                $err = $mysqli->connect_error ? $mysqli->connect_error : ('Connect error #' . $mysqli->connect_errno);
                return array(
                    'success' => false,
                    'error' => $err,
                );
            }
            $mysqli->set_charset('utf8mb4');
            return array(
                'success' => true,
                'type' => 'mysqli',
                'connection' => $mysqli,
            );
        } catch (Throwable $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        } finally {
            if ($old_report !== null) {
                @mysqli_report($old_report);
            }
        }
    }

    if (extension_loaded('pdo_mysql')) {
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return array(
                'success' => true,
                'type' => 'pdo',
                'connection' => $pdo,
            );
        } catch (PDOException $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage(),
            );
        }
    }

    return array(
        'success' => false,
        'error' => '没有可用的 MySQL 扩展',
    );
}

function gojs_api_db_connections() {
    $method = gojs_get_method();

    if ($method === 'GET') {
        $connections = gojs_load_db_connections();

        $result = array();
        foreach ($connections as $conn) {
            $item = $conn;
            unset($item['password']);
            $result[] = $item;
        }

        gojs_json_response($result);
    } elseif ($method === 'POST') {
        $capabilities = gojs_get_capabilities();

        if (!$capabilities['mysql']) {
            gojs_json_response(null, array(
                'code' => 'mysql_not_available',
                'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
                'message_key' => 'db.mysqlNotAvailable',
            ), 400);
        }

        $name = gojs_get_param('name', '');
        $host = gojs_get_param('host', 'localhost');
        $port = gojs_get_param('port', 3306);
        $username = gojs_get_param('username', '');
        $password = gojs_get_param('password', '');
        $database = gojs_get_param('database', '');

        if (!$name) {
            gojs_json_response(null, array(
                'code' => 'invalid_name',
                'message' => '连接名称不能为空',
            ), 400);
        }

        $test_conn = array(
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'database' => $database,
        );

        $connect_result = gojs_db_connect($test_conn);
        if (!$connect_result['success']) {
            gojs_json_response(null, array(
                'code' => 'db_connect_failed',
                'message' => '连接失败: ' . $connect_result['error'],
                'message_key' => 'db.connectFailed',
            ), 400);
        }

        $id = 'conn_' . substr(bin2hex(random_bytes(8)), 0, 12);

        $connections = gojs_load_db_connections();

        $new_conn = array(
            'id' => $id,
            'name' => $name,
            'host' => $host,
            'port' => (int)$port,
            'username' => $username,
            'password' => gojs_encrypt($password),
            'database' => $database,
            'created_at' => time(),
        );

        $connections[] = $new_conn;

        if (!gojs_save_db_connections($connections)) {
            gojs_json_response(null, array(
                'code' => 'save_failed',
                'message' => '保存连接失败',
            ), 500);
        }

        $result = $new_conn;
        unset($result['password']);

        gojs_json_response($result);
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}

function gojs_api_db_connection($id, $method) {
    if ($method === 'PUT') {
        $capabilities = gojs_get_capabilities();

        if (!$capabilities['mysql']) {
            gojs_json_response(null, array(
                'code' => 'mysql_not_available',
                'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
                'message_key' => 'db.mysqlNotAvailable',
            ), 400);
        }

        $connections = gojs_load_db_connections();
        $found = false;
        $updated_conn = null;

        foreach ($connections as &$conn) {
            if (!empty($conn['id']) && $conn['id'] === $id) {
                $name = gojs_get_param('name');
                $host = gojs_get_param('host');
                $port = gojs_get_param('port');
                $username = gojs_get_param('username');
                $password = gojs_get_param('password');
                $database = gojs_get_param('database');

                if ($name !== null) $conn['name'] = $name;
                if ($host !== null) $conn['host'] = $host;
                if ($port !== null) $conn['port'] = (int)$port;
                if ($username !== null) $conn['username'] = $username;
                if ($database !== null) $conn['database'] = $database;
                if ($password !== null && $password !== '') {
                    $conn['password'] = gojs_encrypt($password);
                }

                $found = true;
                $updated_conn = $conn;
                break;
            }
        }
        unset($conn);

        if (!$found) {
            gojs_json_response(null, array(
                'code' => 'not_found',
                'message' => '连接不存在',
            ), 404);
        }

        if (!gojs_save_db_connections($connections)) {
            gojs_json_response(null, array(
                'code' => 'save_failed',
                'message' => '保存连接失败',
            ), 500);
        }

        $result = $updated_conn;
        unset($result['password']);

        gojs_json_response($result);
    } elseif ($method === 'DELETE') {
        $connections = gojs_load_db_connections();
        $new_connections = array();
        $found = false;

        foreach ($connections as $conn) {
            if (!empty($conn['id']) && $conn['id'] === $id) {
                $found = true;
                continue;
            }
            $new_connections[] = $conn;
        }

        if (!$found) {
            gojs_json_response(null, array(
                'code' => 'not_found',
                'message' => '连接不存在',
            ), 404);
        }

        if (!gojs_save_db_connections($new_connections)) {
            gojs_json_response(null, array(
                'code' => 'save_failed',
                'message' => '保存连接失败',
            ), 500);
        }

        gojs_json_response(array('success' => true));
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}

function gojs_api_db_databases() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];
    $databases = array();

    if ($type === 'mysqli') {
        $res = $db->query('SHOW DATABASES');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $databases[] = $row['Database'];
            }
            $res->free();
        }
    } elseif ($type === 'pdo') {
        $stmt = $db->query('SHOW DATABASES');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $databases[] = $row['Database'];
            }
        }
    }

    gojs_json_response($databases);
}

function gojs_api_db_tables() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');

    if (!$database) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '数据库名不能为空',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config['database'] = $database;

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];
    $tables = array();

    if ($type === 'mysqli') {
        $res = $db->query('SHOW TABLE STATUS');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $tables[] = array(
                    'name' => $row['Name'],
                    'engine' => $row['Engine'],
                    'rows' => (int)$row['Rows'],
                    'size' => (int)$row['Data_length'] + (int)$row['Index_length'],
                    'collation' => $row['Collation'],
                    'comment' => $row['Comment'],
                );
            }
            $res->free();
        }
    } elseif ($type === 'pdo') {
        $stmt = $db->query('SHOW TABLE STATUS');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $tables[] = array(
                    'name' => $row['Name'],
                    'engine' => $row['Engine'],
                    'rows' => (int)$row['Rows'],
                    'size' => (int)$row['Data_length'] + (int)$row['Index_length'],
                    'collation' => $row['Collation'],
                    'comment' => $row['Comment'],
                );
            }
        }
    }

    gojs_json_response($tables);
}

function gojs_api_db_structure() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $table = gojs_get_param('table', '');

    if (!$database || !$table) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '数据库名和表名不能为空',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config['database'] = $database;

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];
    $columns = array();

    $table_escaped = '`' . str_replace('`', '``', $table) . '`';

    if ($type === 'mysqli') {
        $res = $db->query('DESCRIBE ' . $table_escaped);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $columns[] = array(
                    'name' => $row['Field'],
                    'type' => $row['Type'],
                    'nullable' => $row['Null'] === 'YES',
                    'key' => $row['Key'],
                    'default' => $row['Default'],
                    'extra' => $row['Extra'],
                );
            }
            $res->free();
        }
    } elseif ($type === 'pdo') {
        $stmt = $db->query('DESCRIBE ' . $table_escaped);
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = array(
                    'name' => $row['Field'],
                    'type' => $row['Type'],
                    'nullable' => $row['Null'] === 'YES',
                    'key' => $row['Key'],
                    'default' => $row['Default'],
                    'extra' => $row['Extra'],
                );
            }
        }
    }

    gojs_json_response($columns);
}

function gojs_api_db_sql() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $sql = gojs_get_param('sql', '');

    if (!$sql) {
        gojs_json_response(null, array(
            'code' => 'db_import_empty',
            'message' => 'SQL 不能为空',
            'message_key' => 'db.importEmpty',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    if ($database) {
        $conn_config['database'] = $database;
    }

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];

    $start_time = microtime(true);
    $results = array();

    $statement = trim($sql);

    $sql_result = array(
        'success' => true,
        'statement' => $statement,
    );

    try {
        if ($type === 'mysqli') {
            $res = $db->query($statement);

            if ($res === false) {
                $sql_result['success'] = false;
                $sql_result['error'] = $db->error;
            } elseif ($res === true) {
                $sql_result['affectedRows'] = $db->affected_rows;
            } else {
                $rows = array();
                while ($row = $res->fetch_assoc()) {
                    $rows[] = $row;
                }
                $res->free();
                $sql_result['rows'] = $rows;
            }
        } elseif ($type === 'pdo') {
            $stmt = $db->query($statement);

            if ($stmt === false) {
                $sql_result['success'] = false;
                $error_info = $db->errorInfo();
                $sql_result['error'] = $error_info[2];
            } else {
                if ($stmt->columnCount() > 0) {

                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $sql_result['rows'] = $rows;
                } else {

                    $sql_result['affectedRows'] = $stmt->rowCount();
                }
            }
        }
    } catch (Exception $e) {
        $sql_result['success'] = false;
        $sql_result['error'] = $e->getMessage();
    }

    $results[] = $sql_result;

    $execution_time = round((microtime(true) - $start_time) * 1000, 2);

    gojs_log_operation('db_sql_exec', $sql, !empty($sql_result['success']));
    gojs_json_response(array(
        'results' => $results,
        'executionTime' => $execution_time,
    ));
}

function gojs_db_escape_value($db, $type, $value) {
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    if (is_string($value)) {
        if ($type === 'mysqli') {
            return "'" . $db->real_escape_string($value) . "'";
        }
        if ($type === 'pdo') {
            $quoted = $db->quote($value);
            if ($quoted !== false) {
                return $quoted;
            }
            return "'" . addslashes($value) . "'";
        }
    }

    return "'" . addslashes((string)$value) . "'";
}

function gojs_db_show_create_table($db, $type, $table_escaped) {
    if ($type === 'mysqli') {
        $res = $db->query('SHOW CREATE TABLE ' . $table_escaped);
        if ($res) {
            $row = $res->fetch_assoc();
            $res->free();
            return isset($row['Create Table']) ? $row['Create Table'] : '';
        }
        return '';
    }
    if ($type === 'pdo') {
        $stmt = $db->query('SHOW CREATE TABLE ' . $table_escaped);
        if ($stmt) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return isset($row['Create Table']) ? $row['Create Table'] : '';
        }
        return '';
    }
    return '';
}

function gojs_db_fetch_tables_list($db, $type) {
    $tables = array();
    if ($type === 'mysqli') {
        $res = $db->query('SHOW TABLES');
        if ($res) {
            while ($row = $res->fetch_row()) {
                if (isset($row[0])) {
                    $tables[] = $row[0];
                }
            }
            $res->free();
        }
    } elseif ($type === 'pdo') {
        $stmt = $db->query('SHOW TABLES');
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                if (isset($row[0])) {
                    $tables[] = $row[0];
                }
            }
        }
    }
    return $tables;
}

function gojs_db_fetch_columns($db, $type, $table_escaped) {
    $columns = array();
    if ($type === 'mysqli') {
        $res = $db->query('SELECT * FROM ' . $table_escaped . ' LIMIT 1');
        if ($res) {
            $finfo = $res->fetch_fields();
            foreach ($finfo as $col) {
                $columns[] = $col->name;
            }
            $res->free();
        }
    } elseif ($type === 'pdo') {
        $stmt = $db->query('SELECT * FROM ' . $table_escaped . ' LIMIT 1');
        if ($stmt) {
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $meta = $stmt->getColumnMeta($i);
                if ($meta && isset($meta['name'])) {
                    $columns[] = $meta['name'];
                }
            }
        }
    }
    return $columns;
}

function gojs_api_db_export() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $tables_param = gojs_get_param('tables', null);
    $mode = gojs_get_param('mode', 'structure_data');

    if (!in_array($mode, array('structure_only', 'structure_data'), true)) {
        $mode = 'structure_data';
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    if ($database) {
        $conn_config['database'] = $database;
    }

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];

    $tables = array();
    if (is_array($tables_param)) {
        foreach ($tables_param as $t) {
            if (is_string($t) && $t !== '') {
                $tables[] = $t;
            }
        }
    }

    if (empty($tables)) {
        $tables = gojs_db_fetch_tables_list($db, $type);
    }

    @set_time_limit(0);
    if (function_exists('ini_set')) {
        @ini_set('memory_limit', '512M');
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $filename = 'backup_' . date('Ymd_His') . '.sql';

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    if (!$out) {
        gojs_json_response(null, array(
            'code' => 'db_export_failed',
            'message' => '导出失败：无法打开输出流',
            'message_key' => 'db.exportFailed',
        ), 400);
    }

    fwrite($out, "-- Go.js SQL Dump\n");
    fwrite($out, "-- Host: " . (isset($conn_config['host']) ? $conn_config['host'] : 'localhost') . "\n");
    fwrite($out, "-- Generation Time: " . date('Y-m-d H:i:s') . "\n");
    fwrite($out, "-- Database: " . (isset($conn_config['database']) ? $conn_config['database'] : '') . "\n");
    fwrite($out, "\n");
    fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($out, "SET NAMES utf8;\n");
    fwrite($out, "SET SQL_MODE=\"\";\n");
    fwrite($out, "\n");

    $batch_size = 1000;

    foreach ($tables as $table) {
        $table_escaped = '`' . str_replace('`', '``', $table) . '`';

        fwrite($out, "\n-- ------------------------------------------------------------\n");
        fwrite($out, "-- Table structure for `" . $table . "`\n");
        fwrite($out, "-- ------------------------------------------------------------\n");
        fwrite($out, "DROP TABLE IF EXISTS " . $table_escaped . ";\n");

        $create_sql = gojs_db_show_create_table($db, $type, $table_escaped);
        if ($create_sql !== '') {
            fwrite($out, $create_sql . ";\n");
        }

        if ($mode !== 'structure_data') {
            continue;
        }

        $columns = gojs_db_fetch_columns($db, $type, $table_escaped);
        if (empty($columns)) {
            continue;
        }

        $col_list_escaped = array();
        foreach ($columns as $col) {
            $col_list_escaped[] = '`' . str_replace('`', '``', $col) . '`';
        }
        $col_list_sql = implode(', ', $col_list_escaped);

        fwrite($out, "\n-- Dumping data for `" . $table . "`\n");

        $offset = 0;
        $has_more = true;

        while ($has_more) {
            $limit_sql = 'SELECT * FROM ' . $table_escaped . ' LIMIT ' . (int)$offset . ', ' . (int)$batch_size;

            $rows = array();
            if ($type === 'mysqli') {
                $res = $db->query($limit_sql);
                if ($res === false) {
                    fwrite($out, "-- ERROR fetching data: " . $db->error . "\n");
                    break;
                }
                if ($res === true) {
                    break;
                }
                while ($row = $res->fetch_assoc()) {
                    $rows[] = $row;
                }
                $res->free();
            } elseif ($type === 'pdo') {
                $stmt = $db->query($limit_sql);
                if ($stmt === false) {
                    break;
                }
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $values = array();
                foreach ($columns as $col) {
                    $val = isset($row[$col]) ? $row[$col] : null;
                    $values[] = gojs_db_escape_value($db, $type, $val);
                }
                fwrite($out, "INSERT INTO " . $table_escaped . " (" . $col_list_sql . ") VALUES (" . implode(', ', $values) . ");\n");
            }

            if (count($rows) < $batch_size) {
                $has_more = false;
            } else {
                $offset += $batch_size;
            }
        }
    }

    fwrite($out, "\nSET FOREIGN_KEY_CHECKS=1;\n");

    fclose($out);
    exit;
}

function gojs_sql_split_statements($content) {
    $statements = array();
    $len = strlen($content);

    $buffer = '';
    $in_single = false;
    $in_double = false;
    $in_backtick = false;
    $in_line_comment = false;
    $in_block_comment = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $content[$i];
        $next = ($i + 1 < $len) ? $content[$i + 1] : '';
        $prev = ($i > 0) ? $content[$i - 1] : '';

        if ($in_line_comment) {
            $buffer .= $ch;
            if ($ch === "\n") {
                $in_line_comment = false;
            }
            continue;
        }

        if ($in_block_comment) {
            $buffer .= $ch;
            if ($ch === '*' && $next === '/') {
                $buffer .= '/';
                $i++;
                $in_block_comment = false;
            }
            continue;
        }

        if ($in_single) {
            $buffer .= $ch;
            if ($ch === '\\' && $next !== '') {
                $buffer .= $next;
                $i++;
                continue;
            }
            if ($ch === "'") {
                $in_single = false;
            }
            continue;
        }

        if ($in_double) {
            $buffer .= $ch;
            if ($ch === '\\' && $next !== '') {
                $buffer .= $next;
                $i++;
                continue;
            }
            if ($ch === '"') {
                $in_double = false;
            }
            continue;
        }

        if ($in_backtick) {
            $buffer .= $ch;
            if ($ch === '`') {
                $in_backtick = false;
            }
            continue;
        }

        if ($ch === '-' && $next === '-' && ($prev === '' || $prev === "\n" || $prev === "\r" || $prev === ' ' || $prev === "\t")) {
            $in_line_comment = true;
            $buffer .= $ch;
            continue;
        }

        if ($ch === '#') {
            $in_line_comment = true;
            $buffer .= $ch;
            continue;
        }

        if ($ch === '/' && $next === '*') {
            $in_block_comment = true;
            $buffer .= $ch;
            continue;
        }

        if ($ch === "'") {
            $in_single = true;
            $buffer .= $ch;
            continue;
        }

        if ($ch === '"') {
            $in_double = true;
            $buffer .= $ch;
            continue;
        }

        if ($ch === '`') {
            $in_backtick = true;
            $buffer .= $ch;
            continue;
        }

        if ($ch === ';') {
            $stmt = trim($buffer);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $ch;
    }

    $stmt = trim($buffer);
    if ($stmt !== '') {
        $statements[] = $stmt;
    }

    return $statements;
}

function gojs_sql_strip_comments($statement) {
    $lines = explode("\n", $statement);
    $cleaned = array();
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '') {
            continue;
        }
        if (strpos($trimmed, '--') === 0) {
            continue;
        }
        if (strpos($trimmed, '#') === 0) {
            continue;
        }
        $cleaned[] = $line;
    }
    return trim(implode("\n", $cleaned));
}

function gojs_sql_detect_dangerous_statements($sql_content) {
    $dangerous = array();

    if (preg_match_all('/^\s*DROP\s+DATABASE\b/im', $sql_content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $dangerous[] = array('type' => 'DROP_DATABASE', 'statement' => substr($sql_content, $match[1], 100));
        }
    }

    if (preg_match_all('/^\s*DROP\s+TABLE\b/im', $sql_content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $dangerous[] = array('type' => 'DROP_TABLE', 'statement' => substr($sql_content, $match[1], 100));
        }
    }

    if (preg_match_all('/^\s*TRUNCATE\s+TABLE\b/im', $sql_content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $dangerous[] = array('type' => 'TRUNCATE_TABLE', 'statement' => substr($sql_content, $match[1], 100));
        }
    }

    if (preg_match_all('/^\s*DELETE\s+FROM\s+\S+\s*$/im', $sql_content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $dangerous[] = array('type' => 'DELETE_NO_WHERE', 'statement' => substr($sql_content, $match[1], 100));
        }
    }

    if (preg_match_all('/^\s*DELETE\s+FROM\s+\S+\s+ORDER\s+BY/im', $sql_content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $match) {
            $dangerous[] = array('type' => 'DELETE_NO_WHERE', 'statement' => substr($sql_content, $match[1], 100));
        }
    }

    return $dangerous;
}

function gojs_api_db_import() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = isset($_POST['connId']) ? $_POST['connId'] : '';
    if (!$conn_id) {
        $conn_id = gojs_get_param('connId', '');
    }
    $database = isset($_POST['database']) ? $_POST['database'] : '';
    if (!$database) {
        $database = gojs_get_param('database', '');
    }

    if (!$conn_id) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接 ID 不能为空',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    if (empty($_FILES['file'])) {
        gojs_json_response(null, array(
            'code' => 'db_import_empty',
            'message' => '没有上传文件',
            'message_key' => 'db.importEmpty',
        ), 400);
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        gojs_json_response(null, array(
            'code' => 'db_import_empty',
            'message' => '文件上传错误: ' . $file['error'],
            'message_key' => 'db.importEmpty',
        ), 400);
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        gojs_json_response(null, array(
            'code' => 'db_import_empty',
            'message' => '无效的上传文件',
            'message_key' => 'db.importEmpty',
        ), 400);
    }

    $filename = isset($file['name']) ? $file['name'] : 'import.sql';
    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'sql') {
        gojs_json_response(null, array(
            'code' => 'db_import_empty',
            'message' => '仅支持 .sql 文件',
            'message_key' => 'db.importEmpty',
        ), 400);
    }

    $sql_content = file_get_contents($file['tmp_name']);
    if ($sql_content === false) {
        gojs_json_response(null, array(
            'code' => 'db_import_failed',
            'message' => '无法读取上传文件',
            'message_key' => 'db.importFailed',
        ), 400);
    }
    $cleaned_sql = gojs_sql_strip_comments($sql_content);
    $dangerous_statements = gojs_sql_detect_dangerous_statements($cleaned_sql);
    $allow_dangerous = gojs_get_param('allowDangerous', false);
    if (!empty($dangerous_statements) && !$allow_dangerous) {
        gojs_json_response(null, array(
            'code' => 'dangerous_statements',
            'message' => 'SQL 文件中检测到危险语句，请确认后继续',
            'data' => array('dangerous' => $dangerous_statements),
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    if ($database) {
        $conn_config['database'] = $database;
    }

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];

    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    if ($type === 'mysqli') {
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        $db->query('SET NAMES utf8');
        $db->query('SET SQL_MODE=""');
        $db->autocommit(true);
    } else {
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        $db->query('SET NAMES utf8');
        $db->query('SET SQL_MODE=""');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
    }

    $handle = @fopen($file['tmp_name'], 'rb');
    if (!$handle) {
        gojs_json_response(null, array(
            'code' => 'db_import_failed',
            'message' => '无法读取上传文件',
            'message_key' => 'db.importFailed',
        ), 400);
    }

    $executed = 0;
    $failed = 0;
    $errors = array();
    $max_errors = 50;

    $buffer = '';
    $chunk_size = 65536;

    $in_single = false;
    $in_double = false;
    $in_backtick = false;
    $in_line_comment = false;
    $in_block_comment = false;

    while (!feof($handle)) {
        $chunk = fread($handle, $chunk_size);
        if ($chunk === false) {
            break;
        }

        $len = strlen($chunk);
        for ($i = 0; $i < $len; $i++) {
            $ch = $chunk[$i];
            $next = ($i + 1 < $len) ? $chunk[$i + 1] : '';
            $prev = ($i > 0) ? $chunk[$i - 1] : (($buffer !== '') ? $buffer[strlen($buffer) - 1] : '');

            if ($in_line_comment) {
                $buffer .= $ch;
                if ($ch === "\n") {
                    $in_line_comment = false;
                }
                continue;
            }

            if ($in_block_comment) {
                $buffer .= $ch;
                if ($ch === '*' && $next === '/') {
                    $buffer .= '/';
                    $i++;
                    $in_block_comment = false;
                }
                continue;
            }

            if ($in_single) {
                $buffer .= $ch;
                if ($ch === '\\' && $next !== '') {
                    $buffer .= $next;
                    $i++;
                    continue;
                }
                if ($ch === "'") {
                    $in_single = false;
                }
                continue;
            }

            if ($in_double) {
                $buffer .= $ch;
                if ($ch === '\\' && $next !== '') {
                    $buffer .= $next;
                    $i++;
                    continue;
                }
                if ($ch === '"') {
                    $in_double = false;
                }
                continue;
            }

            if ($in_backtick) {
                $buffer .= $ch;
                if ($ch === '`') {
                    $in_backtick = false;
                }
                continue;
            }

            if ($ch === '-' && $next === '-' && ($prev === '' || $prev === "\n" || $prev === "\r" || $prev === ' ' || $prev === "\t")) {
                $in_line_comment = true;
                $buffer .= $ch;
                continue;
            }

            if ($ch === '#') {
                $in_line_comment = true;
                $buffer .= $ch;
                continue;
            }

            if ($ch === '/' && $next === '*') {
                $in_block_comment = true;
                $buffer .= $ch;
                continue;
            }

            if ($ch === "'") {
                $in_single = true;
                $buffer .= $ch;
                continue;
            }

            if ($ch === '"') {
                $in_double = true;
                $buffer .= $ch;
                continue;
            }

            if ($ch === '`') {
                $in_backtick = true;
                $buffer .= $ch;
                continue;
            }

            if ($ch === ';') {
                $stmt = gojs_sql_strip_comments($buffer);
                $buffer = '';

                if ($stmt === '') {
                    continue;
                }

                $err = null;
                if ($type === 'mysqli') {
                    $res = @$db->query($stmt);
                    if ($res === false) {
                        $err = $db->error;
                    }
                } else {
                    $affected = $db->exec($stmt);
                    if ($affected === false) {
                        $info = $db->errorInfo();
                        $err = isset($info[2]) ? $info[2] : 'PDO error';
                    }
                }

                if ($err !== null) {
                    $failed++;
                    if (count($errors) < $max_errors) {
                        $errors[] = $err;
                    }
                } else {
                    $executed++;
                }
                continue;
            }

            $buffer .= $ch;
        }
    }

    fclose($handle);

    $stmt = gojs_sql_strip_comments($buffer);
    if ($stmt !== '') {
        $err = null;
        if ($type === 'mysqli') {
            $res = @$db->query($stmt);
            if ($res === false) {
                $err = $db->error;
            }
        } else {
            $affected = $db->exec($stmt);
            if ($affected === false) {
                $info = $db->errorInfo();
                $err = isset($info[2]) ? $info[2] : 'PDO error';
            }
        }

        if ($err !== null) {
            $failed++;
            if (count($errors) < $max_errors) {
                $errors[] = $err;
            }
        } else {
            $executed++;
        }
    }

    $db->query('SET FOREIGN_KEY_CHECKS=1');

    gojs_log_operation('db_import', $filename, $failed === 0, 'executed: ' . $executed . ', failed: ' . $failed);
    gojs_json_response(array(
        'success' => true,
        'executed' => $executed,
        'failed' => $failed,
        'errors' => $errors,
    ));
}

function gojs_api_db_table_data() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $table = gojs_get_param('table', '');
    $page = gojs_get_param('page', 1);
    $limit = gojs_get_param('limit', 50);
    $sort_field = gojs_get_param('sortField', '');
    $sort_order = gojs_get_param('sortOrder', 'ASC');

    if (!$database || !$table) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '数据库名和表名不能为空',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config['database'] = $database;

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];

    $table_escaped = '`' . str_replace('`', '``', $table) . '`';

    $total_query = 'SELECT COUNT(*) as total FROM ' . $table_escaped;
    $total_rows = 0;

    if ($type === 'mysqli') {
        $res = $db->query($total_query);
        if ($res) {
            $row = $res->fetch_assoc();
            $total_rows = (int)$row['total'];
            $res->free();
        }
    } elseif ($type === 'pdo') {
        $stmt = $db->query($total_query);
        if ($stmt) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $total_rows = (int)$row['total'];
        }
    }

    $offset = ($page - 1) * $limit;
    $data_query = 'SELECT * FROM ' . $table_escaped;
    
    if ($sort_field) {
        $data_query .= ' ORDER BY `' . str_replace('`', '``', $sort_field) . '` ' . $sort_order;
    }
    
    $data_query .= ' LIMIT ' . (int)$offset . ', ' . (int)$limit;

    $data = array();
    if ($type === 'mysqli') {
        $res = $db->query($data_query);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $data[] = $row;
            }
            $res->free();
        }
    } elseif ($type === 'pdo') {
        $stmt = $db->query($data_query);
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $data[] = $row;
            }
        }
    }

    gojs_json_response(array(
        'success' => true,
        'data' => $data,
        'pagination' => array(
            'page' => (int)$page,
            'limit' => (int)$limit,
            'total' => $total_rows,
            'totalPages' => ceil($total_rows / $limit)
        )
    ));
}

function gojs_api_db_insert_row() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $table = gojs_get_param('table', '');
    $data = gojs_get_param('data', array());

    if (!$database || !$table || empty($data)) {
        gojs_json_response(null, array(
            'code' => 'invalid_request',
            'message' => '请求参数不完整',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config['database'] = $database;

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];

    $table_escaped = '`' . str_replace('`', '``', $table) . '`';

    $columns = array();
    $values = array();
    foreach ($data as $key => $value) {
        $columns[] = '`' . str_replace('`', '``', $key) . '`';
        $values[] = gojs_db_escape_value($db, $type, $value);
    }

    $sql = 'INSERT INTO ' . $table_escaped . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';

    $insert_id = null;
    if ($type === 'mysqli') {
        $res = $db->query($sql);
        if ($res) {
            $insert_id = $db->insert_id;
        }
    } elseif ($type === 'pdo') {
        $stmt = $db->prepare($sql);
        if ($stmt && $stmt->execute()) {
            $insert_id = $db->lastInsertId();
        }
    }

    gojs_json_response(array(
        'success' => true,
        'insertId' => $insert_id,
        'sql' => $sql
    ));
}

function gojs_api_db_update_row() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $table = gojs_get_param('table', '');
    $primary_key = gojs_get_param('primaryKey', '');
    $primary_key_value = gojs_get_param('primaryKeyValue', '');
    $data = gojs_get_param('data', array());

    if (!$database || !$table || !$primary_key || $primary_key_value === '' || empty($data)) {
        gojs_json_response(null, array(
            'code' => 'invalid_request',
            'message' => '请求参数不完整',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config['database'] = $database;

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];

    $table_escaped = '`' . str_replace('`', '``', $table) . '`';
    $primary_key_escaped = '`' . str_replace('`', '``', $primary_key) . '`';

    $set_parts = array();
    foreach ($data as $key => $value) {
        $set_parts[] = '`' . str_replace('`', '``', $key) . '` = ' . gojs_db_escape_value($db, $type, $value);
    }

    $sql = 'UPDATE ' . $table_escaped . ' SET ' . implode(', ', $set_parts) . ' WHERE ' . $primary_key_escaped . ' = ' . gojs_db_escape_value($db, $type, $primary_key_value);

    $affected_rows = 0;
    if ($type === 'mysqli') {
        $res = $db->query($sql);
        if ($res) {
            $affected_rows = $db->affected_rows;
        }
    } elseif ($type === 'pdo') {
        $stmt = $db->prepare($sql);
        if ($stmt && $stmt->execute()) {
            $affected_rows = $stmt->rowCount();
        }
    }

    gojs_json_response(array(
        'success' => true,
        'affectedRows' => $affected_rows,
        'sql' => $sql
    ));
}

// A1: Table Data Editor Functions
function gojs_api_db_table_data() {
    $capabilities = gojs_get_capabilities();
    if (!$capabilities['db']) {
        gojs_json_response(null, array(
            'code' => 'capability_missing',
            'message' => 'Database capability required',
            'message_key' => 'capability.dbRequired',
        ), 403);
        return;
    }

    $conn_id = gojs_get_param('conn_id', '');
    $database = gojs_get_param('database', '');
    $table = gojs_get_param('table', '');
    $page = intval(gojs_get_param('page', 1));
    $limit = intval(gojs_get_param('limit', 50));
    $sort_by = gojs_get_param('sort_by', '');
    $sort_order = gojs_get_param('sort_order', 'ASC');

    if (empty($conn_id) || empty($database) || empty($table)) {
        gojs_json_response(null, array(
            'code' => 'missing_params',
            'message' => 'Missing required parameters',
            'message_key' => 'error.missingParams',
        ), 400);
        return;
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (empty($conn_config)) {
        gojs_json_response(null, array(
            'code' => 'connection_not_found',
            'message' => 'Database connection not found',
            'message_key' => 'db.connectionNotFound',
        ), 400);
        return;
    }

    $conn_config['database'] = $database;
    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => 'Database connection failed: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
        return;
    }

    $db = $result['connection'];
    $type = $result['type'];

    try {
        $table_escaped = '`' . str_replace('`', '``', $table) . '`';
        
        // Get total count
        $count_sql = 'SELECT COUNT(*) as total FROM ' . $table_escaped;
        $total_result = gojs_db_query($db, $type, $count_sql);
        $total = $total_result['success'] ? $total_result['data'][0]['total'] : 0;

        // Get data with pagination
        $offset = ($page - 1) * $limit;
        $data_sql = 'SELECT * FROM ' . $table_escaped;
        
        if (!empty($sort_by)) {
            $data_sql .= ' ORDER BY `' . str_replace('`', '``', $sort_by) . '` ' . ($sort_order === 'DESC' ? 'DESC' : 'ASC');
        }
        
        $data_sql .= ' LIMIT ' . intval($offset) . ', ' . intval($limit);
        
        $data_result = gojs_db_query($db, $type, $data_sql);
        
        gojs_db_close($db, $type);
        
        gojs_json_response(array(
            'success' => true,
            'data' => $data_result['success'] ? $data_result['data'] : array(),
            'pagination' => array(
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            )
        ));
    } catch (Exception $e) {
        gojs_db_close($db, $type);
        gojs_json_response(null, array(
            'code' => 'db_error',
            'message' => 'Database error: ' . $e->getMessage(),
            'message_key' => 'db.error',
        ), 500);
    }
}

function gojs_api_db_insert_row() {
    $capabilities = gojs_get_capabilities();
    if (!$capabilities['db']) {
        gojs_json_response(null, array(
            'code' => 'capability_missing',
            'message' => 'Database capability required',
            'message_key' => 'capability.dbRequired',
        ), 403);
        return;
    }

    $conn_id = gojs_get_param('conn_id', '');
    $database = gojs_get_param('database', '');
    $table = gojs_get_param('table', '');
    $data = gojs_get_param('data', array());

    if (empty($conn_id) || empty($database) || empty($table) || empty($data)) {
        gojs_json_response(null, array(
            'code' => 'missing_params',
            'message' => 'Missing required parameters',
            'message_key' => 'error.missingParams',
        ), 400);
        return;
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (empty($conn_config)) {
        gojs_json_response(null, array(
            'code' => 'connection_not_found',
            'message' => 'Database connection not found',
            'message_key' => 'db.connectionNotFound',
        ), 400);
        return;
    }

    $conn_config['database'] = $database;
    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => 'Database connection failed: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
        return;
    }

    $db = $result['connection'];
    $type = $result['type'];

    try {
        $table_escaped = '`' . str_replace('`', '``', $table) . '`';
        $columns = array();
        $values = array();
        $placeholders = array();

        foreach ($data as $key => $value) {
            $columns[] = '`' . str_replace('`', '``', $key) . '`';
            $values[] = $value;
            $placeholders[] = '?';
        }

        $sql = 'INSERT INTO ' . $table_escaped . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        
        $insert_result = gojs_db_query($db, $type, $sql, $values);
        
        gojs_db_close($db, $type);
        
        gojs_json_response(array(
            'success' => $insert_result['success'],
            'insertId' => $insert_result['insert_id'] ?? null,
            'sql' => $insert_result['sql']
        ));
    } catch (Exception $e) {
        gojs_db_close($db, $type);
        gojs_json_response(null, array(
            'code' => 'db_error',
            'message' => 'Database error: ' . $e->getMessage(),
            'message_key' => 'db.error',
        ), 500);
    }
}

function gojs_api_db_update_row() {
    $capabilities = gojs_get_capabilities();
    if (!$capabilities['db']) {
        gojs_json_response(null, array(
            'code' => 'capability_missing',
            'message' => 'Database capability required',
            'message_key' => 'capability.dbRequired',
        ), 403);
        return;
    }

    $conn_id = gojs_get_param('conn_id', '');
    $database = gojs_get_param('database', '');
    $table = gojs_get_param('table', '');
    $primary_key = gojs_get_param('primary_key', 'id');
    $primary_key_value = gojs_get_param('primary_key_value', '');
    $data = gojs_get_param('data', array());

    if (empty($conn_id) || empty($database) || empty($table) || empty($primary_key) || empty($primary_key_value) || empty($data)) {
        gojs_json_response(null, array(
            'code' => 'missing_params',
            'message' => 'Missing required parameters',
            'message_key' => 'error.missingParams',
        ), 400);
        return;
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (empty($conn_config)) {
        gojs_json_response(null, array(
            'code' => 'connection_not_found',
            'message' => 'Database connection not found',
            'message_key' => 'db.connectionNotFound',
        ), 400);
        return;
    }

    $conn_config['database'] = $database;
    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => 'Database connection failed: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
        return;
    }

    $db = $result['connection'];
    $type = $result['type'];

    try {
        $table_escaped = '`' . str_replace('`', '``', $table) . '`';
        $primary_key_escaped = '`' . str_replace('`', '``', $primary_key) . '`';
        
        $set_clauses = array();
        $values = array();

        foreach ($data as $key => $value) {
            $set_clauses[] = '`' . str_replace('`', '``', $key) . '` = ?';
            $values[] = $value;
        }

        $values[] = $primary_key_value;
        $sql = 'UPDATE ' . $table_escaped . ' SET ' . implode(', ', $set_clauses) . ' WHERE ' . $primary_key_escaped . ' = ?';
        
        $update_result = gojs_db_query($db, $type, $sql, $values);
        
        gojs_db_close($db, $type);
        
        gojs_json_response(array(
            'success' => $update_result['success'],
            'affectedRows' => $update_result['affected_rows'] ?? 0,
            'sql' => $update_result['sql']
        ));
    } catch (Exception $e) {
        gojs_db_close($db, $type);
        gojs_json_response(null, array(
            'code' => 'db_error',
            'message' => 'Database error: ' . $e->getMessage(),
            'message_key' => 'db.error',
        ), 500);
    }
}

// A2: Table Structure Manager Functions
function gojs_api_db_create_table() {
    $capabilities = gojs_get_capabilities();
    if (!$capabilities['db']) {
        gojs_json_response(null, array(
            'code' => 'capability_missing',
            'message' => 'Database capability required',
            'message_key' => 'capability.dbRequired',
        ), 403);
        return;
    }

    $conn_id = gojs_get_param('conn_id', '');
    $database = gojs_get_param('database', '');
    $table_name = gojs_get_param('table_name', '');
    $columns = gojs_get_param('columns', array());

    if (empty($conn_id) || empty($database) || empty($table_name) || empty($columns)) {
        gojs_json_response(null, array(
            'code' => 'missing_params',
            'message' => 'Missing required parameters',
            'message_key' => 'error.missingParams',
        ), 400);
        return;
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (empty($conn_config)) {
        gojs_json_response(null, array(
            'code' => 'connection_not_found',
            'message' => 'Database connection not found',
            'message_key' => 'db.connectionNotFound',
        ), 400);
        return;
    }

    $conn_config['database'] = $database;
    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => 'Database connection failed: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
        return;
    }

    $db = $result['connection'];
    $type = $result['type'];

    try {
        $table_escaped = '`' . str_replace('`', '``', $table_name) . '`';
        
        $column_definitions = array();
        foreach ($columns as $column) {
            $col_def = '`' . str_replace('`', '``', $column['name']) . '` ' . $column['type'];
            if (!empty($column['length'])) {
                $col_def .= '(' . $column['length'] . ')';
            }
            if (!empty($column['unsigned'])) {
                $col_def .= ' UNSIGNED';
            }
            if (!empty($column['nullable']) && !$column['nullable']) {
                $col_def .= ' NOT NULL';
            }
            if (!empty($column['auto_increment'])) {
                $col_def .= ' AUTO_INCREMENT';
            }
            if (!empty($column['primary_key'])) {
                $col_def .= ' PRIMARY KEY';
            }
            $column_definitions[] = $col_def;
        }

        $sql = 'CREATE TABLE ' . $table_escaped . ' (' . implode(', ', $column_definitions) . ')';
        
        $create_result = gojs_db_query($db, $type, $sql);
        
        gojs_db_close($db, $type);
        
        gojs_json_response(array(
            'success' => $create_result['success'],
            'sql' => $create_result['sql']
        ));
    } catch (Exception $e) {
        gojs_db_close($db, $type);
        gojs_json_response(null, array(
            'code' => 'db_error',
            'message' => 'Database error: ' . $e->getMessage(),
            'message_key' => 'db.error',
        ), 500);
    }
}

function gojs_api_db_alter_table() {
    $capabilities = gojs_get_capabilities();
    if (!$capabilities['db']) {
        gojs_json_response(null, array(
            'code' => 'capability_missing',
            'message' => 'Database capability required',
            'message_key' => 'capability.dbRequired',
        ), 403);
        return;
    }

    $conn_id = gojs_get_param('conn_id', '');
    $database = gojs_get_param('database', '');
    $table_name = gojs_get_param('table_name', '');
    $operation = gojs_get_param('operation', '');
    $column = gojs_get_param('column', array());

    if (empty($conn_id) || empty($database) || empty($table_name) || empty($operation) || empty($column)) {
        gojs_json_response(null, array(
            'code' => 'missing_params',
            'message' => 'Missing required parameters',
            'message_key' => 'error.missingParams',
        ), 400);
        return;
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (empty($conn_config)) {
        gojs_json_response(null, array(
            'code' => 'connection_not_found',
            'message' => 'Database connection not found',
            'message_key' => 'db.connectionNotFound',
        ), 400);
        return;
    }

    $conn_config['database'] = $database;
    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => 'Database connection failed: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
        return;
    }

    $db = $result['connection'];
    $type = $result['type'];

    try {
        $table_escaped = '`' . str_replace('`', '``', $table_name) . '`';
        $column_escaped = '`' . str_replace('`', '``', $column['name']) . '`';
        
        $sql = '';
        switch ($operation) {
            case 'add':
                $sql = 'ALTER TABLE ' . $table_escaped . ' ADD COLUMN ' . $column_escaped . ' ' . $column['type'];
                if (!empty($column['length'])) {
                    $sql .= '(' . $column['length'] . ')';
                }
                if (!empty($column['unsigned'])) {
                    $sql .= ' UNSIGNED';
                }
                if (!empty($column['nullable']) && !$column['nullable']) {
                    $sql .= ' NOT NULL';
                }
                break;
            case 'modify':
                $sql = 'ALTER TABLE ' . $table_escaped . ' MODIFY COLUMN ' . $column_escaped . ' ' . $column['type'];
                if (!empty($column['length'])) {
                    $sql .= '(' . $column['length'] . ')';
                }
                if (!empty($column['unsigned'])) {
                    $sql .= ' UNSIGNED';
                }
                if (!empty($column['nullable']) && !$column['nullable']) {
                    $sql .= ' NOT NULL';
                }
                break;
            case 'drop':
                $sql = 'ALTER TABLE ' . $table_escaped . ' DROP COLUMN ' . $column_escaped;
                break;
        }
        
        $alter_result = gojs_db_query($db, $type, $sql);
        
        gojs_db_close($db, $type);
        
        gojs_json_response(array(
            'success' => $alter_result['success'],
            'sql' => $alter_result['sql']
        ));
    } catch (Exception $e) {
        gojs_db_close($db, $type);
        gojs_json_response(null, array(
            'code' => 'db_error',
            'message' => 'Database error: ' . $e->getMessage(),
            'message_key' => 'db.error',
        ), 500);
    }
}

function gojs_api_db_create_index() {
    $capabilities = gojs_get_capabilities();
    if (!$capabilities['db']) {
        gojs_json_response(null, array(
            'code' => 'capability_missing',
            'message' => 'Database capability required',
            'message_key' => 'capability.dbRequired',
        ), 403);
        return;
    }

    $conn_id = gojs_get_param('conn_id', '');
    $database = gojs_get_param('database', '');
    $table_name = gojs_get_param('table_name', '');
    $index_name = gojs_get_param('index_name', '');
    $columns = gojs_get_param('columns', array());
    $unique = gojs_get_param('unique', false);

    if (empty($conn_id) || empty($database) || empty($table_name) || empty($index_name) || empty($columns)) {
        gojs_json_response(null, array(
            'code' => 'missing_params',
            'message' => 'Missing required parameters',
            'message_key' => 'error.missingParams',
        ), 400);
        return;
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (empty($conn_config)) {
        gojs_json_response(null, array(
            'code' => 'connection_not_found',
            'message' => 'Database connection not found',
            'message_key' => 'db.connectionNotFound',
        ), 400);
        return;
    }

    $conn_config['database'] = $database;
    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => 'Database connection failed: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
        return;
    }

    $db = $result['connection'];
    $type = $result['type'];

    try {
        $table_escaped = '`' . str_replace('`', '``', $table_name) . '`';
        $index_escaped = '`' . str_replace('`', '``', $index_name) . '`';
        
        $column_list = array();
        foreach ($columns as $column) {
            $column_list[] = '`' . str_replace('`', '``', $column) . '`';
        }
        
        $sql = ($unique ? 'CREATE UNIQUE INDEX' : 'CREATE INDEX') . ' ' . $index_escaped . ' ON ' . $table_escaped . ' (' . implode(', ', $column_list) . ')';
        
        $index_result = gojs_db_query($db, $type, $sql);
        
        gojs_db_close($db, $type);
        
        gojs_json_response(array(
            'success' => $index_result['success'],
            'sql' => $index_result['sql']
        ));
    } catch (Exception $e) {
        gojs_db_close($db, $type);
        gojs_json_response(null, array(
            'code' => 'db_error',
            'message' => 'Database error: ' . $e->getMessage(),
            'message_key' => 'db.error',
        ), 500);
    }
}

function gojs_api_db_drop_index() {
    $capabilities = gojs_get_capabilities();
    if (!$capabilities['db']) {
        gojs_json_response(null, array(
            'code' => 'capability_missing',
            'message' => 'Database capability required',
            'message_key' => 'capability.dbRequired',
        ), 403);
        return;
    }

    $conn_id = gojs_get_param('conn_id', '');
    $database = gojs_get_param('database', '');
    $table_name = gojs_get_param('table_name', '');
    $index_name = gojs_get_param('index_name', '');

    if (empty($conn_id) || empty($database) || empty($table_name) || empty($index_name)) {
        gojs_json_response(null, array(
            'code' => 'missing_params',
            'message' => 'Missing required parameters',
            'message_key' => 'error.missingParams',
        ), 400);
        return;
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (empty($conn_config)) {
        gojs_json_response(null, array(
            'code' => 'connection_not_found',
            'message' => 'Database connection not found',
            'message_key' => 'db.connectionNotFound',
        ), 400);
        return;
    }

    $conn_config['database'] = $database;
    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => 'Database connection failed: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
        return;
    }

    $db = $result['connection'];
    $type = $result['type'];

    try {
        $table_escaped = '`' . str_replace('`', '``', $table_name) . '`';
        $index_escaped = '`' . str_replace('`', '``', $index_name) . '`';
        
        $sql = 'DROP INDEX ' . $index_escaped . ' ON ' . $table_escaped;
        
        $drop_result = gojs_db_query($db, $type, $sql);
        
        gojs_db_close($db, $type);
        
        gojs_json_response(array(
            'success' => $drop_result['success'],
            'sql' => $drop_result['sql']
        ));
    } catch (Exception $e) {
        gojs_db_close($db, $type);
        gojs_json_response(null, array(
            'code' => 'db_error',
            'message' => 'Database error: ' . $e->getMessage(),
            'message_key' => 'db.error',
        ), 500);
    }
}

// A3: Query Builder Functions
function gojs_api_db_query_builder() {
    $capabilities = gojs_get_capabilities();
    if (!$capabilities['db']) {
        gojs_json_response(null, array(
            'code' => 'capability_missing',
            'message' => 'Database capability required',
            'message_key' => 'capability.dbRequired',
        ), 403);
        return;
    }

    $conn_id = gojs_get_param('conn_id', '');
    $database = gojs_get_param('database', '');
    $table = gojs_get_param('table', '');
    $selected_columns = gojs_get_param('selected_columns', array());
    $conditions = gojs_get_param('conditions', array());
    $group_by = gojs_get_param('group_by', array());
    $order_by = gojs_get_param('order_by', array());
    $limit = intval(gojs_get_param('limit', 100));
    $offset = intval(gojs_get_param('offset', 0));

    if (empty($conn_id) || empty($database) || empty($table)) {
        gojs_json_response(null, array(
            'code' => 'missing_params',
            'message' => 'Missing required parameters',
            'message_key' => 'error.missingParams',
        ), 400);
        return;
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (empty($conn_config)) {
        gojs_json_response(null, array(
            'code' => 'connection_not_found',
            'message' => 'Database connection not found',
            'message_key' => 'db.connectionNotFound',
        ), 400);
        return;
    }

    $conn_config['database'] = $database;
    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => 'Database connection failed: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
        return;
    }

    $db = $result['connection'];
    $type = $result['type'];

    try {
        $table_escaped = '`' . str_replace('`', '``', $table) . '`';
        
        // Build SELECT clause
        $select_columns = array('*');
        if (!empty($selected_columns) && $selected_columns[0] !== '*') {
            $select_columns = array();
            foreach ($selected_columns as $column) {
                $select_columns[] = '`' . str_replace('`', '``', $column) . '`';
            }
        }
        
        $sql = 'SELECT ' . implode(', ', $select_columns) . ' FROM ' . $table_escaped;
        
        // Build WHERE clause
        if (!empty($conditions)) {
            $where_clauses = array();
            $values = array();
            
            foreach ($conditions as $condition) {
                $column_escaped = '`' . str_replace('`', '``', $condition['column']) . '`';
                $where_clauses[] = $column_escaped . ' ' . $condition['operator'] . ' ?';
                $values[] = $condition['value'];
            }
            
            $sql .= ' WHERE ' . implode(' AND ', $where_clauses);
        }
        
        // Build GROUP BY clause
        if (!empty($group_by)) {
            $group_columns = array();
            foreach ($group_by as $column) {
                $group_columns[] = '`' . str_replace('`', '``', $column) . '`';
            }
            $sql .= ' GROUP BY ' . implode(', ', $group_columns);
        }
        
        // Build ORDER BY clause
        if (!empty($order_by)) {
            $order_clauses = array();
            foreach ($order_by as $order) {
                $column_escaped = '`' . str_replace('`', '``', $order['column']) . '`';
                $order_clauses[] = $column_escaped . ' ' . strtoupper($order['direction']);
            }
            $sql .= ' ORDER BY ' . implode(', ', $order_clauses);
        }
        
        // Build LIMIT clause
        if ($limit > 0) {
            $sql .= ' LIMIT ' . intval($limit);
            if ($offset > 0) {
                $sql .= ' OFFSET ' . intval($offset);
            }
        }
        
        $query_result = gojs_db_query($db, $type, $sql);
        
        gojs_db_close($db, $type);
        
        gojs_json_response(array(
            'success' => $query_result['success'],
            'data' => $query_result['success'] ? $query_result['data'] : array(),
            'sql' => $sql,
            'count' => $query_result['success'] ? count($query_result['data']) : 0
        ));
    } catch (Exception $e) {
        gojs_db_close($db, $type);
        gojs_json_response(null, array(
            'code' => 'db_error',
            'message' => 'Database error: ' . $e->getMessage(),
            'message_key' => 'db.error',
        ), 500);
    }
}

// A4: Export Enhanced Functions
function gojs_api_db_export_enhanced() {
    $capabilities = gojs_get_capabilities();
    if (!$capabilities['db']) {
        gojs_json_response(null, array(
            'code' => 'capability_missing',
            'message' => 'Database capability required',
            'message_key' => 'capability.dbRequired',
        ), 403);
        return;
    }

    $conn_id = gojs_get_param('conn_id', '');
    $database = gojs_get_param('database', '');
    $tables = gojs_get_param('tables', array());
    $format = gojs_get_param('format', 'sql');
    $include_structure = gojs_get_param('include_structure', true);
    $include_data = gojs_get_param('include_data', true);
    $compression = gojs_get_param('compression', 'none');

    if (empty($conn_id) || empty($database) || empty($tables) || empty($format)) {
        gojs_json_response(null, array(
            'code' => 'missing_params',
            'message' => 'Missing required parameters',
            'message_key' => 'error.missingParams',
        ), 400);
        return;
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (empty($conn_config)) {
        gojs_json_response(null, array(
            'code' => 'connection_not_found',
            'message' => 'Database connection not found',
            'message_key' => 'db.connectionNotFound',
        ), 400);
        return;
    }

    $conn_config['database'] = $database;
    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => 'Database connection failed: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
        return;
    }

    $db = $result['connection'];
    $type = $result['type'];

    try {
        $export_data = array();
        
        foreach ($tables as $table) {
            $table_escaped = '`' . str_replace('`', '``', $table) . '`';
            
            if ($include_structure) {
                // Get table structure
                $structure_sql = 'SHOW CREATE TABLE ' . $table_escaped;
                $structure_result = gojs_db_query($db, $type, $structure_sql);
                if ($structure_result['success'] && !empty($structure_result['data'])) {
                    $export_data[$table]['structure'] = $structure_result['data'][0]['Create Table'];
                }
            }
            
            if ($include_data) {
                // Get table data
                $data_sql = 'SELECT * FROM ' . $table_escaped;
                $data_result = gojs_db_query($db, $type, $data_sql);
                if ($data_result['success']) {
                    $export_data[$table]['data'] = $data_result['data'];
                }
            }
        }
        
        gojs_db_close($db, $type);
        
        $export_content = '';
        $filename = '';
        
        switch ($format) {
            case 'sql':
                $export_content = gojs_export_sql($export_data);
                $filename = 'database_export_' . date('Y-m-d_H-i-s') . '.sql';
                break;
            case 'json':
                $export_content = json_encode($export_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $filename = 'database_export_' . date('Y-m-d_H-i-s') . '.json';
                break;
            case 'csv':
                $export_content = gojs_export_csv($export_data);
                $filename = 'database_export_' . date('Y-m-d_H-i-s') . '.csv';
                break;
            case 'xml':
                $export_content = gojs_export_xml($export_data);
                $filename = 'database_export_' . date('Y-m-d_H-i-s') . '.xml';
                break;
            default:
                gojs_json_response(null, array(
                    'code' => 'invalid_format',
                    'message' => 'Invalid export format',
                    'message_key' => 'export.invalidFormat',
                ), 400);
                return;
        }
        
        if ($compression !== 'none' && !empty($export_content)) {
            switch ($compression) {
                case 'gzip':
                    $export_content = gzencode($export_content);
                    $filename .= '.gz';
                    break;
                case 'zip':
                    $export_content = gojs_export_zip($export_data);
                    $filename = 'database_export_' . date('Y-m-d_H-i-s') . '.zip';
                    break;
            }
        }
        
        gojs_json_response(array(
            'success' => true,
            'content' => base64_encode($export_content),
            'filename' => $filename,
            'size' => strlen($export_content),
            'format' => $format,
            'compression' => $compression
        ));
    } catch (Exception $e) {
        gojs_db_close($db, $type);
        gojs_json_response(null, array(
            'code' => 'db_error',
            'message' => 'Database error: ' . $e->getMessage(),
            'message_key' => 'db.error',
        ), 500);
    }
}

function gojs_export_sql($export_data) {
    $sql = '-- Database Export\n';
    $sql .= '-- Generated: ' . date('Y-m-d H:i:s') . '\n';
    $sql .= '-- Database: ' . DB_NAME . '\n\n';
    
    foreach ($export_data as $table => $data) {
        if (!empty($data['structure'])) {
            $sql .= $data['structure'] . ";\n\n";
        }
        
        if (!empty($data['data'])) {
            $sql .= '-- Table data: ' . $table . '\n';
            foreach ($data['data'] as $row) {
                $values = array();
                foreach ($row as $value) {
                    $values[] = is_null($value) ? 'NULL' : "'" . addslashes($value) . "'";
                }
                $sql .= 'INSERT INTO `' . $table . '` VALUES (' . implode(', ', $values) . ");\n";
            }
            $sql .= "\n";
        }
    }
    
    return $sql;
}

function gojs_export_csv($export_data) {
    $output = '';
    
    foreach ($export_data as $table => $data) {
        if (!empty($data['data'])) {
            $output .= '-- Table: ' . $table . '\n';
            $headers = array_keys($data['data'][0]);
            $output .= implode(',', $headers) . '\n';
            
            foreach ($data['data'] as $row) {
                $values = array();
                foreach ($row as $value) {
                    $values[] = '"' . str_replace('"', '""', $value) . '"';
                }
                $output .= implode(',', $values) . '\n';
            }
            $output .= '\n';
        }
    }
    
    return $output;
}

function gojs_export_xml($export_data) {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>\n';
    $xml .= '<database_export generated="' . date('Y-m-d H:i:s') . '" database="' . DB_NAME . '">\n';
    
    foreach ($export_data as $table => $data) {
        $xml .= '  <table name="' . htmlspecialchars($table) . '">\n';
        
        if (!empty($data['structure'])) {
            $xml .= '    <structure><![CDATA[' . $data['structure'] . ']]></structure>\n';
        }
        
        if (!empty($data['data'])) {
            $xml .= '    <data>\n';
            foreach ($data['data'] as $row) {
                $xml .= '      <row>\n';
                foreach ($row as $column => $value) {
                    $xml .= '        <column name="' . htmlspecialchars($column) . '">';
                    $xml .= '<![CDATA[' . (is_null($value) ? '' : $value) . ']]></column>\n';
                }
                $xml .= '      </row>\n';
            }
            $xml .= '    </data>\n';
        }
        
        $xml .= '  </table>\n';
    }
    
    $xml .= '</database_export>';
    
    return $xml;
}

function gojs_export_zip($export_data) {
    // Simple ZIP implementation - in production use ZipArchive
    $temp_file = tempnam(sys_get_temp_dir(), 'db_export');
    $zip = new ZipArchive();
    
    if ($zip->open($temp_file, ZipArchive::CREATE) === TRUE) {
        foreach ($export_data as $table => $data) {
            if (!empty($data['data'])) {
                $csv_content = '';
                $headers = array_keys($data['data'][0]);
                $csv_content .= implode(',', $headers) . '\n';
                
                foreach ($data['data'] as $row) {
                    $values = array();
                    foreach ($row as $value) {
                        $values[] = '"' . str_replace('"', '""', $value) . '"';
                    }
                    $csv_content .= implode(',', $values) . '\n';
                }
                
                $zip->addFromString($table . '.csv', $csv_content);
            }
        }
        $zip->close();
    }
    
    $content = file_get_contents($temp_file);
    unlink($temp_file);
    return $content;
}

function gojs_api_db_create_table() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $table_name = gojs_get_param('tableName', '');
    $columns = gojs_get_param('columns', array());
    $engine = gojs_get_param('engine', 'InnoDB');
    $charset = gojs_get_param('charset', 'utf8mb4');

    if (!$database || !$table_name || empty($columns)) {
        gojs_json_response(null, array(
            'code' => 'invalid_request',
            'message' => '请求参数不完整',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config['database'] = $database;

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];

    $table_escaped = '`' . str_replace('`', '``', $table_name) . '`';

    $column_definitions = array();
    foreach ($columns as $column) {
        $col_def = '`' . str_replace('`', '``', $column['name']) . '` ' . $column['type'];
        
        if ($column['nullable'] !== 'YES') {
            $col_def .= ' NOT NULL';
        }
        
        if (isset($column['default']) && $column['default'] !== '') {
            $col_def .= ' DEFAULT ' . gojs_db_escape_value($db, $type, $column['default']);
        }
        
        if (isset($column['auto_increment']) && $column['auto_increment']) {
            $col_def .= ' AUTO_INCREMENT';
        }
        
        if (isset($column['comment']) && $column['comment'] !== '') {
            $col_def .= ' COMMENT ' . gojs_db_escape_value($db, $type, $column['comment']);
        }
        
        $column_definitions[] = $col_def;
    }

    $sql = 'CREATE TABLE ' . $table_escaped . ' (' . implode(', ', $column_definitions) . ') ENGINE=' . $engine . ' DEFAULT CHARSET=' . $charset;

    $success = false;
    if ($type === 'mysqli') {
        $res = $db->query($sql);
        $success = $res !== false;
    } elseif ($type === 'pdo') {
        $stmt = $db->prepare($sql);
        $success = $stmt && $stmt->execute();
    }

    gojs_json_response(array(
        'success' => $success,
        'sql' => $sql
    ));
}

function gojs_api_db_query_builder() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $table = gojs_get_param('table', '');
    $columns = gojs_get_param('columns', array());
    $conditions = gojs_get_param('conditions', array());
    $group_by = gojs_get_param('groupBy', array());
    $order_by = gojs_get_param('orderBy', array());
    $limit = gojs_get_param('limit', 100);
    $offset = gojs_get_param('offset', 0);

    if (!$database || !$table) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '数据库名和表名不能为空',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config['database'] = $database;

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];

    $table_escaped = '`' . str_replace('`', '``', $table) . '`';

    $sql = 'SELECT ';

    if (empty($columns)) {
        $sql .= '*';
    } else {
        $column_list = array();
        foreach ($columns as $column) {
            if ($column === '*') {
                $column_list[] = '*';
            } else {
                $column_list[] = '`' . str_replace('`', '``', $column) . '`';
            }
        }
        $sql .= implode(', ', $column_list);
    }

    $sql .= ' FROM ' . $table_escaped;

    if (!empty($conditions)) {
        $where_parts = array();
        foreach ($conditions as $condition) {
            $field_escaped = '`' . str_replace('`', '``', $condition['field']) . '`';
            $value = gojs_db_escape_value($db, $type, $condition['value']);
            
            switch ($condition['operator']) {
                case 'eq':
                    $where_parts[] = $field_escaped . ' = ' . $value;
                    break;
                case 'ne':
                    $where_parts[] = $field_escaped . ' != ' . $value;
                    break;
                case 'gt':
                    $where_parts[] = $field_escaped . ' > ' . $value;
                    break;
                case 'gte':
                    $where_parts[] = $field_escaped . ' >= ' . $value;
                    break;
                case 'lt':
                    $where_parts[] = $field_escaped . ' < ' . $value;
                    break;
                case 'lte':
                    $where_parts[] = $field_escaped . ' <= ' . $value;
                    break;
                case 'like':
                    $where_parts[] = $field_escaped . ' LIKE ' . $value;
                    break;
                case 'in':
                    if (is_array($condition['value'])) {
                        $values = array();
                        foreach ($condition['value'] as $val) {
                            $values[] = gojs_db_escape_value($db, $type, $val);
                        }
                        $where_parts[] = $field_escaped . ' IN (' . implode(', ', $values) . ')';
                    }
                    break;
                case 'null':
                    $where_parts[] = $field_escaped . ' IS NULL';
                    break;
                case 'notnull':
                    $where_parts[] = $field_escaped . ' IS NOT NULL';
                    break;
            }
        }
        
        if (!empty($where_parts)) {
            $sql .= ' WHERE ' . implode(' AND ', $where_parts);
        }
    }

    if (!empty($group_by)) {
        $group_parts = array();
        foreach ($group_by as $field) {
            $group_parts[] = '`' . str_replace('`', '``', $field) . '`';
        }
        $sql .= ' GROUP BY ' . implode(', ', $group_parts);
    }

    if (!empty($order_by)) {
        $order_parts = array();
        foreach ($order_by as $order) {
            $field_escaped = '`' . str_replace('`', '``', $order['field']) . '`';
            $direction = strtoupper($order['direction']) === 'DESC' ? 'DESC' : 'ASC';
            $order_parts[] = $field_escaped . ' ' . $direction;
        }
        $sql .= ' ORDER BY ' . implode(', ', $order_parts);
    }

    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int)$limit;
        if ($offset > 0) {
            $sql .= ' OFFSET ' . (int)$offset;
        }
    }

    $data = array();
    if ($type === 'mysqli') {
        $res = $db->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $data[] = $row;
            }
            $res->free();
        }
    } elseif ($type === 'pdo') {
        $stmt = $db->query($sql);
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $data[] = $row;
            }
        }
    }

    gojs_json_response(array(
        'success' => true,
        'data' => $data,
        'sql' => $sql,
        'count' => count($data)
    ));
}

function gojs_api_db_export_enhanced() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $tables_param = gojs_get_param('tables', null);
    $format = gojs_get_param('format', 'sql');
    $compression = gojs_get_param('compression', 'none');
    $include_structure = gojs_get_param('includeStructure', true);
    $include_data = gojs_get_param('includeData', true);
    $where_clause = gojs_get_param('whereClause', '');

    if (!$database) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '数据库名不能为空',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config['database'] = $database;

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];

    $tables = array();
    if (is_array($tables_param)) {
        foreach ($tables_param as $t) {
            if (is_string($t) && $t !== '') {
                $tables[] = $t;
            }
        }
    }

    if (empty($tables)) {
        $tables = gojs_db_fetch_tables_list($db, $type);
    }

    @set_time_limit(0);
    if (function_exists('ini_set')) {
        @ini_set('memory_limit', '1G');
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $timestamp = date('Ymd_His');
    $filename = '';
    
    switch ($format) {
        case 'sql':
            $filename = 'backup_' . $timestamp . '.sql';
            header('Content-Type: application/sql; charset=utf-8');
            break;
        case 'json':
            $filename = 'backup_' . $timestamp . '.json';
            header('Content-Type: application/json; charset=utf-8');
            break;
        case 'csv':
            $filename = 'backup_' . $timestamp . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            break;
        case 'xml':
            $filename = 'backup_' . $timestamp . '.xml';
            header('Content-Type: application/xml; charset=utf-8');
            break;
    }

    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    if (!$out) {
        gojs_json_response(null, array(
            'code' => 'db_export_failed',
            'message' => '导出失败：无法打开输出流',
            'message_key' => 'db.exportFailed',
        ), 400);
    }

    switch ($format) {
        case 'sql':
            gojs_export_sql_enhanced($out, $db, $type, $tables, $include_structure, $include_data, $where_clause);
            break;
        case 'json':
            gojs_export_json_enhanced($out, $db, $type, $tables, $include_structure, $include_data, $where_clause);
            break;
        case 'csv':
            gojs_export_csv_enhanced($out, $db, $type, $tables, $include_data, $where_clause);
            break;
        case 'xml':
            gojs_export_xml_enhanced($out, $db, $type, $tables, $include_structure, $include_data, $where_clause);
            break;
    }

    fclose($out);
    exit;
}

function gojs_export_sql_enhanced($out, $db, $type, $tables, $include_structure, $include_data, $where_clause) {
    fwrite($out, "-- Go.js Enhanced SQL Dump\n");
    fwrite($out, "-- Host: " . (isset($GLOBALS['conn_config']['host']) ? $GLOBALS['conn_config']['host'] : 'localhost') . "\n");
    fwrite($out, "-- Generation Time: " . date('Y-m-d H:i:s') . "\n");
    fwrite($out, "-- Database: " . (isset($GLOBALS['conn_config']['database']) ? $GLOBALS['conn_config']['database'] : '') . "\n");
    fwrite($out, "\n");
    fwrite($out, "SET FOREIGN_KEY_CHECKS=0;\n");
    fwrite($out, "SET NAMES utf8;\n");
    fwrite($out, "SET SQL_MODE=\"\";\n");
    fwrite($out, "\n");

    foreach ($tables as $table) {
        $table_escaped = '`' . str_replace('`', '``', $table) . '`';

        if ($include_structure) {
            fwrite($out, "\n-- ------------------------------------------------------------\n");
            fwrite($out, "-- Table structure for `" . $table . "`\n");
            fwrite($out, "-- ------------------------------------------------------------\n");
            fwrite($out, "DROP TABLE IF EXISTS " . $table_escaped . ";\n");

            $create_sql = gojs_db_show_create_table($db, $type, $table_escaped);
            if ($create_sql !== '') {
                fwrite($out, $create_sql . ";\n");
            }
        }

        if ($include_data) {
            $columns = gojs_db_fetch_columns($db, $type, $table_escaped);
            if (empty($columns)) {
                continue;
            }

            $col_list_escaped = array();
            foreach ($columns as $col) {
                $col_list_escaped[] = '`' . str_replace('`', '``', $col) . '`';
            }
            $col_list_sql = implode(', ', $col_list_escaped);

            fwrite($out, "\n-- Dumping data for `" . $table . "`\n");

            $where_sql = '';
            if ($where_clause) {
                $where_sql = ' WHERE ' . $where_clause;
            }

            $offset = 0;
            $batch_size = 1000;
            $has_more = true;

            while ($has_more) {
                $limit_sql = 'SELECT * FROM ' . $table_escaped . $where_sql . ' LIMIT ' . (int)$offset . ', ' . (int)$batch_size;

                $rows = array();
                if ($type === 'mysqli') {
                    $res = $db->query($limit_sql);
                    if ($res === false) {
                        fwrite($out, "-- ERROR fetching data: " . $db->error . "\n");
                        break;
                    }
                    if ($res === true) {
                        break;
                    }
                    while ($row = $res->fetch_assoc()) {
                        $rows[] = $row;
                    }
                    $res->free();
                } elseif ($type === 'pdo') {
                    $stmt = $db->query($limit_sql);
                    if ($stmt === false) {
                        break;
                    }
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                if (empty($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    $values = array();
                    foreach ($columns as $col) {
                        $val = isset($row[$col]) ? $row[$col] : null;
                        $values[] = gojs_db_escape_value($db, $type, $val);
                    }
                    fwrite($out, "INSERT INTO " . $table_escaped . " (" . $col_list_sql . ") VALUES (" . implode(', ', $values) . ");\n");
                }

                if (count($rows) < $batch_size) {
                    $has_more = false;
                } else {
                    $offset += $batch_size;
                }
            }
        }
    }

    fwrite($out, "\nSET FOREIGN_KEY_CHECKS=1;\n");
}

function gojs_export_json_enhanced($out, $db, $type, $tables, $include_structure, $include_data, $where_clause) {
    $export_data = array(
        'metadata' => array(
            'export_time' => date('Y-m-d H:i:s'),
            'database' => isset($GLOBALS['conn_config']['database']) ? $GLOBALS['conn_config']['database'] : '',
            'host' => isset($GLOBALS['conn_config']['host']) ? $GLOBALS['conn_config']['host'] : 'localhost',
            'format' => 'json',
            'version' => '1.0'
        ),
        'tables' => array()
    );

    foreach ($tables as $table) {
        $table_data = array(
            'name' => $table,
            'structure' => null,
            'data' => array()
        );

        if ($include_structure) {
            $table_escaped = '`' . str_replace('`', '``', $table) . '`';
            $structure = array();
            
            if ($type === 'mysqli') {
                $res = $db->query('DESCRIBE ' . $table_escaped);
                if ($res) {
                    while ($row = $res->fetch_assoc()) {
                        $structure[] = $row;
                    }
                    $res->free();
                }
            } elseif ($type === 'pdo') {
                $stmt = $db->query('DESCRIBE ' . $table_escaped);
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $structure[] = $row;
                    }
                }
            }
            
            $table_data['structure'] = $structure;
        }

        if ($include_data) {
            $table_escaped = '`' . str_replace('`', '``', $table) . '`';
            $where_sql = '';
            if ($where_clause) {
                $where_sql = ' WHERE ' . $where_clause;
            }
            
            $data_query = 'SELECT * FROM ' . $table_escaped . $where_sql;
            
            if ($type === 'mysqli') {
                $res = $db->query($data_query);
                if ($res) {
                    while ($row = $res->fetch_assoc()) {
                        $table_data['data'][] = $row;
                    }
                    $res->free();
                }
            } elseif ($type === 'pdo') {
                $stmt = $db->query($data_query);
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $table_data['data'][] = $row;
                    }
                }
            }
        }

        $export_data['tables'][] = $table_data;
    }

    fwrite($out, json_encode($export_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function gojs_export_csv_enhanced($out, $db, $type, $tables, $include_data, $where_clause) {
    fputcsv($out, array('Table', 'Column', 'Value'));
    
    foreach ($tables as $table) {
        $table_escaped = '`' . str_replace('`', '``', $table) . '`';
        $columns = gojs_db_fetch_columns($db, $type, $table_escaped);
        
        if (empty($columns)) {
            continue;
        }

        $where_sql = '';
        if ($where_clause) {
            $where_sql = ' WHERE ' . $where_clause;
        }
        
        $data_query = 'SELECT * FROM ' . $table_escaped . $where_sql;
        
        if ($type === 'mysqli') {
            $res = $db->query($data_query);
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    foreach ($columns as $col) {
                        fputcsv($out, array($table, $col, isset($row[$col]) ? $row[$col] : ''));
                    }
                }
                $res->free();
            }
        } elseif ($type === 'pdo') {
            $stmt = $db->query($data_query);
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    foreach ($columns as $col) {
                        fputcsv($out, array($table, $col, isset($row[$col]) ? $row[$col] : ''));
                    }
                }
            }
        }
    }
}

function gojs_export_xml_enhanced($out, $db, $type, $tables, $include_structure, $include_data, $where_clause) {
    fwrite($out, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
    fwrite($out, '<database_export>' . "\n");
    fwrite($out, '  <metadata>' . "\n");
    fwrite($out, '    <export_time>' . date('Y-m-d H:i:s') . '</export_time>' . "\n");
    fwrite($out, '    <database>' . (isset($GLOBALS['conn_config']['database']) ? htmlspecialchars($GLOBALS['conn_config']['database']) : '') . '</database>' . "\n");
    fwrite($out, '    <host>' . (isset($GLOBALS['conn_config']['host']) ? htmlspecialchars($GLOBALS['conn_config']['host']) : 'localhost') . '</host>' . "\n");
    fwrite($out, '    <format>xml</format>' . "\n");
    fwrite($out, '    <version>1.0</version>' . "\n");
    fwrite($out, '  </metadata>' . "\n");

    foreach ($tables as $table) {
        fwrite($out, '  <table name="' . htmlspecialchars($table) . '">' . "\n");

        if ($include_structure) {
            $table_escaped = '`' . str_replace('`', '``', $table) . '`';
            
            if ($type === 'mysqli') {
                $res = $db->query('DESCRIBE ' . $table_escaped);
                if ($res) {
                    fwrite($out, '    <structure>' . "\n");
                    while ($row = $res->fetch_assoc()) {
                        fwrite($out, '      <column>' . "\n");
                        fwrite($out, '        <name>' . htmlspecialchars($row['Field']) . '</name>' . "\n");
                        fwrite($out, '        <type>' . htmlspecialchars($row['Type']) . '</type>' . "\n");
                        fwrite($out, '        <nullable>' . htmlspecialchars($row['Null']) . '</nullable>' . "\n");
                        fwrite($out, '        <key>' . htmlspecialchars($row['Key']) . '</key>' . "\n");
                        fwrite($out, '        <default>' . htmlspecialchars($row['Default']) . '</default>' . "\n");
                        fwrite($out, '        <extra>' . htmlspecialchars($row['Extra']) . '</extra>' . "\n");
                        fwrite($out, '      </column>' . "\n");
                    }
                    fwrite($out, '    </structure>' . "\n");
                    $res->free();
                }
            } elseif ($type === 'pdo') {
                $stmt = $db->query('DESCRIBE ' . $table_escaped);
                if ($stmt) {
                    fwrite($out, '    <structure>' . "\n");
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        fwrite($out, '      <column>' . "\n");
                        fwrite($out, '        <name>' . htmlspecialchars($row['Field']) . '</name>' . "\n");
                        fwrite($out, '        <type>' . htmlspecialchars($row['Type']) . '</type>' . "\n");
                        fwrite($out, '        <nullable>' . htmlspecialchars($row['Null']) . '</nullable>' . "\n");
                        fwrite($out, '        <key>' . htmlspecialchars($row['Key']) . '</key>' . "\n");
                        fwrite($out, '        <default>' . htmlspecialchars($row['Default']) . '</default>' . "\n");
                        fwrite($out, '        <extra>' . htmlspecialchars($row['Extra']) . '</extra>' . "\n");
                        fwrite($out, '      </column>' . "\n");
                    }
                    fwrite($out, '    </structure>' . "\n");
                }
            }
        }

        if ($include_data) {
            $table_escaped = '`' . str_replace('`', '``', $table) . '`';
            $where_sql = '';
            if ($where_clause) {
                $where_sql = ' WHERE ' . $where_clause;
            }
            
            $data_query = 'SELECT * FROM ' . $table_escaped . $where_sql;
            
            fwrite($out, '    <data>' . "\n");
            
            if ($type === 'mysqli') {
                $res = $db->query($data_query);
                if ($res) {
                    while ($row = $res->fetch_assoc()) {
                        fwrite($out, '      <row>' . "\n");
                        foreach ($row as $key => $value) {
                            fwrite($out, '        <' . htmlspecialchars($key) . '>' . htmlspecialchars($value) . '</' . htmlspecialchars($key) . '>' . "\n");
                        }
                        fwrite($out, '      </row>' . "\n");
                    }
                    $res->free();
                }
            } elseif ($type === 'pdo') {
                $stmt = $db->query($data_query);
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        fwrite($out, '      <row>' . "\n");
                        foreach ($row as $key => $value) {
                            fwrite($out, '        <' . htmlspecialchars($key) . '>' . htmlspecialchars($value) . '</' . htmlspecialchars($key) . '>' . "\n");
                        }
                        fwrite($out, '      </row>' . "\n");
                    }
                }
            }
            
            fwrite($out, '    </data>' . "\n");
        }

        fwrite($out, '  </table>' . "\n");
    }

    fwrite($out, '</database_export>' . "\n");
}

function gojs_api_db_query_builder_preview() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $table = gojs_get_param('table', '');
    $columns = gojs_get_param('columns', array());
    $conditions = gojs_get_param('conditions', array());
    $group_by = gojs_get_param('groupBy', array());
    $order_by = gojs_get_param('orderBy', array());

    if (!$database || !$table) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '数据库名和表名不能为空',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config['database'] = $database;

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];

    $table_escaped = '`' . str_replace('`', '``', $table) . '`';

    $sql = 'SELECT ';

    if (empty($columns)) {
        $sql .= '*';
    } else {
        $column_list = array();
        foreach ($columns as $column) {
            if ($column === '*') {
                $column_list[] = '*';
            } else {
                $column_list[] = '`' . str_replace('`', '``', $column) . '`';
            }
        }
        $sql .= implode(', ', $column_list);
    }

    $sql .= ' FROM ' . $table_escaped;

    if (!empty($conditions)) {
        $where_parts = array();
        foreach ($conditions as $condition) {
            $field_escaped = '`' . str_replace('`', '``', $condition['field']) . '`';
            $value = gojs_db_escape_value($db, $type, $condition['value']);
            
            switch ($condition['operator']) {
                case 'eq':
                    $where_parts[] = $field_escaped . ' = ' . $value;
                    break;
                case 'ne':
                    $where_parts[] = $field_escaped . ' != ' . $value;
                    break;
                case 'gt':
                    $where_parts[] = $field_escaped . ' > ' . $value;
                    break;
                case 'gte':
                    $where_parts[] = $field_escaped . ' >= ' . $value;
                    break;
                case 'lt':
                    $where_parts[] = $field_escaped . ' < ' . $value;
                    break;
                case 'lte':
                    $where_parts[] = $field_escaped . ' <= ' . $value;
                    break;
                case 'like':
                    $where_parts[] = $field_escaped . ' LIKE ' . $value;
                    break;
                case 'in':
                    if (is_array($condition['value'])) {
                        $values = array();
                        foreach ($condition['value'] as $val) {
                            $values[] = gojs_db_escape_value($db, $type, $val);
                        }
                        $where_parts[] = $field_escaped . ' IN (' . implode(', ', $values) . ')';
                    }
                    break;
                case 'null':
                    $where_parts[] = $field_escaped . ' IS NULL';
                    break;
                case 'notnull':
                    $where_parts[] = $field_escaped . ' IS NOT NULL';
                    break;
            }
        }
        
        if (!empty($where_parts)) {
            $sql .= ' WHERE ' . implode(' AND ', $where_parts);
        }
    }

    if (!empty($group_by)) {
        $group_parts = array();
        foreach ($group_by as $field) {
            $group_parts[] = '`' . str_replace('`', '``', $field) . '`';
        }
        $sql .= ' GROUP BY ' . implode(', ', $group_parts);
    }

    if (!empty($order_by)) {
        $order_parts = array();
        foreach ($order_by as $order) {
            $field_escaped = '`' . str_replace('`', '``', $order['field']) . '`';
            $direction = strtoupper($order['direction']) === 'DESC' ? 'DESC' : 'ASC';
            $order_parts[] = $field_escaped . ' ' . $direction;
        }
        $sql .= ' ORDER BY ' . implode(', ', $order_parts);
    }

    $sql .= ' LIMIT 10';

    $data = array();
    if ($type === 'mysqli') {
        $res = $db->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $data[] = $row;
            }
            $res->free();
        }
    } elseif ($type === 'pdo') {
        $stmt = $db->query($sql);
        if ($stmt) {
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $data[] = $row;
            }
        }
    }

    gojs_json_response(array(
        'success' => true,
        'data' => $data,
        'sql' => $sql,
        'count' => count($data)
    ));
}

function gojs_api_db_alter_table() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $table_name = gojs_get_param('tableName', '');
    $action = gojs_get_param('action', '');
    $column = gojs_get_param('column', array());

    if (!$database || !$table_name || !$action || empty($column)) {
        gojs_json_response(null, array(
            'code' => 'invalid_request',
            'message' => '请求参数不完整',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config['database'] = $database;

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];

    $table_escaped = '`' . str_replace('`', '``', $table_name) . '`';
    $column_escaped = '`' . str_replace('`', '``', $column['name']) . '`';

    $sql = '';
    switch ($action) {
        case 'ADD':
            $sql = 'ALTER TABLE ' . $table_escaped . ' ADD COLUMN `' . $column['name'] . '` ' . $column['type'];
            if ($column['nullable'] !== 'YES') {
                $sql .= ' NOT NULL';
            }
            if (isset($column['default']) && $column['default'] !== '') {
                $sql .= ' DEFAULT ' . gojs_db_escape_value($db, $type, $column['default']);
            }
            if (isset($column['after']) && $column['after'] !== '') {
                $sql .= ' AFTER `' . str_replace('`', '``', $column['after']) . '`';
            }
            break;
        
        case 'DROP':
            $sql = 'ALTER TABLE ' . $table_escaped . ' DROP COLUMN ' . $column_escaped;
            break;
        
        case 'MODIFY':
            $sql = 'ALTER TABLE ' . $table_escaped . ' MODIFY COLUMN ' . $column_escaped . ' ' . $column['type'];
            if ($column['nullable'] !== 'YES') {
                $sql .= ' NOT NULL';
            }
            if (isset($column['default']) && $column['default'] !== '') {
                $sql .= ' DEFAULT ' . gojs_db_escape_value($db, $type, $column['default']);
            }
            break;
    }

    $success = false;
    if ($type === 'mysqli') {
        $res = $db->query($sql);
        $success = $res !== false;
    } elseif ($type === 'pdo') {
        $stmt = $db->prepare($sql);
        $success = $stmt && $stmt->execute();
    }

    gojs_json_response(array(
        'success' => $success,
        'sql' => $sql
    ));
}

function gojs_api_db_create_index() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $table_name = gojs_get_param('tableName', '');
    $index_name = gojs_get_param('indexName', '');
    $columns = gojs_get_param('columns', array());
    $index_type = gojs_get_param('indexType', 'INDEX');

    if (!$database || !$table_name || !$index_name || empty($columns)) {
        gojs_json_response(null, array(
            'code' => 'invalid_request',
            'message' => '请求参数不完整',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config['database'] = $database;

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];

    $table_escaped = '`' . str_replace('`', '``', $table_name) . '`';
    $index_escaped = '`' . str_replace('`', '``', $index_name) . '`';

    $column_list = array();
    foreach ($columns as $column) {
        $column_list[] = '`' . str_replace('`', '``', $column) . '`';
    }

    $sql = 'ALTER TABLE ' . $table_escaped . ' ADD ' . $index_type . ' ' . $index_escaped . ' (' . implode(', ', $column_list) . ')';

    $success = false;
    if ($type === 'mysqli') {
        $res = $db->query($sql);
        $success = $res !== false;
    } elseif ($type === 'pdo') {
        $stmt = $db->prepare($sql);
        $success = $stmt && $stmt->execute();
    }

    gojs_json_response(array(
        'success' => $success,
        'sql' => $sql
    ));
}

function gojs_api_db_drop_index() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $table_name = gojs_get_param('tableName', '');
    $index_name = gojs_get_param('indexName', '');

    if (!$database || !$table_name || !$index_name) {
        gojs_json_response(null, array(
            'code' => 'invalid_request',
            'message' => '请求参数不完整',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config['database'] = $database;

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];

    $table_escaped = '`' . str_replace('`', '``', $table_name) . '`';
    $index_escaped = '`' . str_replace('`', '``', $index_name) . '`';

    $sql = 'ALTER TABLE ' . $table_escaped . ' DROP INDEX ' . $index_escaped;

    $success = false;
    if ($type === 'mysqli') {
        $res = $db->query($sql);
        $success = $res !== false;
    } elseif ($type === 'pdo') {
        $stmt = $db->prepare($sql);
        $success = $stmt && $stmt->execute();
    }

    gojs_json_response(array(
        'success' => $success,
        'sql' => $sql
    ));
}

function gojs_api_db_delete_row() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['mysql']) {
        gojs_json_response(null, array(
            'code' => 'mysql_not_available',
            'message' => '系统不支持 MySQL（缺少 mysqli 或 PDO_MySQL 扩展）',
            'message_key' => 'db.mysqlNotAvailable',
        ), 400);
    }

    $conn_id = gojs_get_param('connId', '');
    $database = gojs_get_param('database', '');
    $table = gojs_get_param('table', '');
    $primary_key = gojs_get_param('primaryKey', '');
    $primary_key_value = gojs_get_param('primaryKeyValue', '');

    if (!$database || !$table || !$primary_key || $primary_key_value === '') {
        gojs_json_response(null, array(
            'code' => 'invalid_request',
            'message' => '请求参数不完整',
        ), 400);
    }

    $conn_config = gojs_get_db_connection($conn_id);
    if (!$conn_config) {
        gojs_json_response(null, array(
            'code' => 'db_not_connected',
            'message' => '连接不存在或未选择数据库连接',
            'message_key' => 'db.notConnected',
        ), 400);
    }

    $conn_config['database'] = $database;

    $result = gojs_db_connect($conn_config);
    if (!$result['success']) {
        gojs_json_response(null, array(
            'code' => 'db_connect_failed',
            'message' => '连接失败: ' . $result['error'],
            'message_key' => 'db.connectFailed',
        ), 400);
    }

    $db = $result['connection'];
    $type = $result['type'];

    $table_escaped = '`' . str_replace('`', '``', $table) . '`';
    $primary_key_escaped = '`' . str_replace('`', '``', $primary_key) . '`';

    $sql = 'DELETE FROM ' . $table_escaped . ' WHERE ' . $primary_key_escaped . ' = ' . gojs_db_escape_value($db, $type, $primary_key_value);

    $affected_rows = 0;
    if ($type === 'mysqli') {
        $res = $db->query($sql);
        if ($res) {
            $affected_rows = $db->affected_rows;
        }
    } elseif ($type === 'pdo') {
        $stmt = $db->prepare($sql);
        if ($stmt && $stmt->execute()) {
            $affected_rows = $stmt->rowCount();
        }
    }

    gojs_json_response(array(
        'success' => true,
        'affectedRows' => $affected_rows,
        'sql' => $sql
    ));
}
