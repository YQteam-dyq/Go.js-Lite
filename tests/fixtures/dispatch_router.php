<?php

require_once __DIR__ . '/../bootstrap.php';

$path = isset($argv[1]) ? $argv[1] : '';
$method = isset($argv[2]) ? $argv[2] : 'GET';

$router = new GoJS_Router();
$router->add('GET', 'files', function () {
    echo 'HANDLER_FILES';
});

$router->dispatch($path, $method);