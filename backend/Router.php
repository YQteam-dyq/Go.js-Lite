<?php

/**
 * GoJS_Router — lightweight router dispatcher (PHP 7.4 compatible).
 * Dispatch order: exact static path first, then prefixes in registration order; 404 if no match.
 * Non-matching method returns 405, byte-compatible with the pre-split behavior.
 */
class GoJS_Router {

    private $routes = array();

    private $prefixes = array();

    public function add($methods, $path, $handler) {
        foreach ((array)$methods as $m) {
            $this->routes[$path][strtoupper($m)] = $handler;
        }
        return $this;
    }

    public function addPrefix($prefix, $resolver) {
        $this->prefixes[] = array($prefix, $resolver);
        return $this;
    }

    public function dispatch($path, $method) {
        $method = strtoupper($method);

        if (isset($this->routes[$path])) {
            if (isset($this->routes[$path][$method])) {
                call_user_func($this->routes[$path][$method], $method, $path);
                return;
            }
            $this->methodNotAllowed();
            return;
        }

        foreach ($this->prefixes as $item) {
            list($prefix, $resolver) = $item;
            if (strpos($path, $prefix) === 0) {
                call_user_func($resolver, $path, $method);
                return;
            }
        }

        $this->notFound($path);
    }

    private function methodNotAllowed() {
        gojs_json_response(null, array(
            'code' => 'method_not_allowed',
            'message' => '方法不允许',
        ), 405);
    }

    private function notFound($path) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '接口不存在: ' . $path,
        ), 404);
    }
}