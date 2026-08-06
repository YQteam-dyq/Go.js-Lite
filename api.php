<?php

define('VERSION', '0.6.0');
define('APP_VERSION', '0.6.0');
define('ROOT', dirname(__FILE__));
define('PANEL_ROOT', ROOT);
define('CONFIG_DIR', ROOT . '/.gojs');
define('CONFIG_FILE', CONFIG_DIR . '/config.php');
define('AUTH_LOG', CONFIG_DIR . '/auth.log');
define('DB_CONNECTIONS_FILE', CONFIG_DIR . '/db_connections.json');
define('GOJS_ACME_ACCOUNT_FILE', CONFIG_DIR . '/acme_account.json');
define('GOJS_ACME_CERTS_FILE', CONFIG_DIR . '/acme_certs.json');
define('GOJS_ACME_CHALLENGES_DIRNAME', 'acme_challenges');
define('GOJS_ACME_CHALLENGES_DIR', CONFIG_DIR . '/' . GOJS_ACME_CHALLENGES_DIRNAME);

$config = array();
$installed = false;
$root_path = ROOT;
$GLOBALS['files_root'] = ROOT;
$capabilities = null;

// Disable error display before loading modules to avoid polluting HTTP response headers (gojs_init() re-applies it).
ini_set('display_errors', '0');
error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR);

// Lightweight module autoloader: load backend/*.php in dependency order.
// Keep this file as the single entry point; the router.php / webcron.php require contract is unchanged.
require_once __DIR__ . '/backend/autoload.php';

gojs_init();