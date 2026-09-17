<?php

require_once dirname(__DIR__) . '/backend/version.php';

define('VERSION', gojs_version());
define('APP_VERSION', gojs_version());
if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}
define('PANEL_ROOT', ROOT);

$testTmp = rtrim(sys_get_temp_dir(), '/\\') . '/gojs-lite-tests-' . getmypid();
if (!defined('CONFIG_DIR')) {
    define('CONFIG_DIR', $testTmp);
}
define('CONFIG_FILE', CONFIG_DIR . '/config.php');
define('AUTH_LOG', CONFIG_DIR . '/auth.log');
define('DB_CONNECTIONS_FILE', CONFIG_DIR . '/db_connections.json');
define('GOJS_ACME_ACCOUNT_FILE', CONFIG_DIR . '/acme_account.json');
define('GOJS_ACME_CERTS_FILE', CONFIG_DIR . '/acme_certs.json');
define('GOJS_ACME_CHALLENGES_DIRNAME', 'acme_challenges');
define('GOJS_ACME_CHALLENGES_DIR', CONFIG_DIR . '/' . GOJS_ACME_CHALLENGES_DIRNAME);

if (!is_dir(CONFIG_DIR)) {
    @mkdir(CONFIG_DIR, 0700, true);
}

$config = array();
$installed = false;
$root_path = ROOT;
$GLOBALS['files_root'] = ROOT;
$capabilities = null;

ini_set('display_errors', '0');

require_once dirname(__DIR__) . '/backend/autoload.php';