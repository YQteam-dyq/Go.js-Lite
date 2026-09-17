<?php

function gojs_project_dir() {
    return dirname(__DIR__);
}

function gojs_version_manifest_path() {
    return gojs_project_dir() . '/version.json';
}

function gojs_version_manifest() {
    static $manifest = null;

    if ($manifest !== null) {
        return $manifest;
    }

    $path = gojs_version_manifest_path();
    $manifest = array();

    if (!file_exists($path)) {
        return $manifest;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return $manifest;
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $manifest = $decoded;
    }

    return $manifest;
}

function gojs_version_is_valid($version) {
    if (!is_string($version)) {
        return false;
    }
    $version = trim($version);
    if ($version === '') {
        return false;
    }
    return preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1;
}

function gojs_version_from_package_json() {
    $path = gojs_project_dir() . '/package.json';

    if (!file_exists($path)) {
        return '';
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return '';
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['version'])) {
        return '';
    }

    $candidate = trim((string)$decoded['version']);

    return gojs_version_is_valid($candidate) ? $candidate : '';
}

function gojs_version() {
    static $version = null;

    if ($version !== null) {
        return $version;
    }

    $manifest = gojs_version_manifest();
    $candidate = isset($manifest['version']) ? trim((string)$manifest['version']) : '';

    if (gojs_version_is_valid($candidate)) {
        $version = $candidate;
        return $version;
    }

    $fallback = gojs_version_from_package_json();
    $version = $fallback !== '' ? $fallback : '0.0.0';

    return $version;
}
