<?php
/**
 * PHPUnit 测试引导（bootstrap）。
 *
 * 仅加载 backend 模块定义（不调用 gojs_init()，避免启动 session 与 HTTP 分发），
 * 并注入与 api.php 一致的全局常量 / 全局变量，供纯单元测试使用。
 *
 * 注意：CONFIG_DIR / CONFIG_FILE / AUTH_LOG 等指向系统临时目录，隔离真实项目配置，
 * 避免测试写入 .gojs/ 目录。
 */

// ---- 全局常量（与 api.php 开头保持一致，但 CONFIG_DIR 指向临时目录）----
define('VERSION', '0.5.0');
define('APP_VERSION', '0.5.0');
define('ROOT', dirname(__DIR__));
define('PANEL_ROOT', ROOT);

$testTmp = rtrim(sys_get_temp_dir(), '/\\') . '/gojs-lite-tests-' . getmypid();
define('CONFIG_DIR', $testTmp);
define('CONFIG_FILE', CONFIG_DIR . '/config.php');
define('AUTH_LOG', CONFIG_DIR . '/auth.log');
define('DB_CONNECTIONS_FILE', CONFIG_DIR . '/db_connections.json');
define('GOJS_ACME_ACCOUNT_FILE', CONFIG_DIR . '/acme_account.json');
define('GOJS_ACME_CERTS_FILE', CONFIG_DIR . '/acme_certs.json');
define('GOJS_ACME_CHALLENGES_DIRNAME', 'acme_challenges');
define('GOJS_ACME_CHALLENGES_DIR', CONFIG_DIR . '/' . GOJS_ACME_CHALLENGES_DIRNAME);

// 确保临时配置目录存在
if (!is_dir(CONFIG_DIR)) {
    @mkdir(CONFIG_DIR, 0700, true);
}

// ---- 全局变量（与 api.php 顶部的初始状态一致）----
$config = array();
$installed = false;
$root_path = ROOT;
$GLOBALS['files_root'] = ROOT;
$capabilities = null;

// 关闭错误显示，避免测试输出被污染
ini_set('display_errors', '0');

// 加载全部 backend 模块（仅定义函数/类，不触发 gojs_init()）
require_once dirname(__DIR__) . '/backend/autoload.php';