<?php
/**
 * 路由分发夹具：在独立 PHP 进程中执行 GoJS_Router::dispatch()。
 *
 * 用法: php dispatch_router.php <path> <method>
 * 输出：gojs_json_response() 生成的 JSON（404/405 亦会 exit，由子进程捕获）。
 */

require_once __DIR__ . '/../bootstrap.php';

$path = isset($argv[1]) ? $argv[1] : '';
$method = isset($argv[2]) ? $argv[2] : 'GET';

$router = new GoJS_Router();
$router->add('GET', 'files', function () {
    echo 'HANDLER_FILES';
});

// 触发 dispatch：未知路径 -> notFound(404)；方法不匹配 -> methodNotAllowed(405)
$router->dispatch($path, $method);