<?php

/**
 * GoJS_Context — injectable application context (PHP 7.4 compatible).
 * gojs_init() keeps it in sync with the legacy globals for backward compatibility.
 */
class GoJS_Context {

    private static $instance = null;

    private $config = array();

    private $filesRoot = '';

    private $installed = false;

    private $capabilities = null;

    private function __construct() {
    }

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function reset() {
        $this->config = array();
        $this->filesRoot = '';
        $this->installed = false;
        $this->capabilities = null;
        return $this;
    }

    public function setConfig(array $config) {
        $this->config = $config;
        return $this;
    }

    public function config() {
        return $this->config;
    }

    public function configValue($key, $default = null) {
        return isset($this->config[$key]) ? $this->config[$key] : $default;
    }

    public function setFilesRoot($root) {
        $this->filesRoot = (string)$root;
        return $this;
    }

    public function filesRoot() {
        return $this->filesRoot;
    }

    public function setInstalled($installed) {
        $this->installed = (bool)$installed;
        return $this;
    }

    public function installed() {
        return $this->installed;
    }

    public function setCapabilities($capabilities) {
        $this->capabilities = $capabilities;
        return $this;
    }

    public function capabilities() {
        return $this->capabilities;
    }
}