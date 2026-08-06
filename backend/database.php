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
