<?php

function gojs_bench_iterations() {
    return isset($GLOBALS['gojs_bench_iterations']) ? max(1, (int)$GLOBALS['gojs_bench_iterations']) : 1000;
}

function gojs_bench_names() {
    return array(
        'curl_multi',
        'file_get_contents',
        'pdo_select',
        'redis_get',
        'opcache_hit',
        'serialize',
        'json',
        'regex',
    );
}

function gojs_bench_dir() {
    return CONFIG_DIR . '/benchmarks';
}

function gojs_bench_unavailable($name, $reason) {
    return array(
        'name' => $name,
        'available' => false,
        'reason' => $reason,
        'avg_us' => null,
        'ops_per_sec' => null,
    );
}

function gojs_bench_measure($name, $iterations, $fn) {
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $fn($i);
    }
    $elapsed = microtime(true) - $start;
    $avgUs = ($elapsed / $iterations) * 1000000;
    return array(
        'name' => $name,
        'available' => true,
        'reason' => null,
        'avg_us' => round($avgUs, 4),
        'ops_per_sec' => $elapsed > 0 ? (int)round($iterations / $elapsed) : null,
    );
}

function gojs_bench_item_serialize($iterations) {
    $payload = array('id' => 42, 'name' => 'gojs', 'tags' => array('a', 'b', 'c'), 'nested' => array('x' => 1.5));
    return gojs_bench_measure('serialize', $iterations, function () use ($payload) {
        $s = serialize($payload);
        unserialize($s);
    });
}

function gojs_bench_item_json($iterations) {
    $payload = array('id' => 42, 'name' => 'gojs', 'tags' => array('a', 'b', 'c'), 'nested' => array('x' => 1.5));
    return gojs_bench_measure('json', $iterations, function () use ($payload) {
        $s = json_encode($payload);
        json_decode($s, true);
    });
}

function gojs_bench_item_regex($iterations) {
    return gojs_bench_measure('regex', $iterations, function () {
        preg_match('/^([a-z]+)-(\d+)$/', 'item-42', $m);
    });
}

function gojs_bench_item_file($iterations) {
    $tmp = tempnam(sys_get_temp_dir(), 'gojsbench');
    if ($tmp === false) {
        return gojs_bench_unavailable('file_get_contents', 'Unable to create a temporary file');
    }
    file_put_contents($tmp, str_repeat('gojs-benchmark-payload', 64));
    $res = gojs_bench_measure('file_get_contents', $iterations, function () use ($tmp) {
        @file_get_contents($tmp);
    });
    @unlink($tmp);
    return $res;
}

function gojs_bench_item_opcache($iterations) {
    if (!function_exists('opcache_get_status')) {
        return gojs_bench_unavailable('opcache_hit', 'The OPcache extension is not loaded');
    }
    $status = @opcache_get_status(false);
    if (!is_array($status) || empty($status['opcache_enabled'])) {
        return gojs_bench_unavailable('opcache_hit', 'OPcache is not enabled');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'gojsobc');
    if ($tmp === false) {
        return gojs_bench_unavailable('opcache_hit', 'Unable to create a temporary file');
    }
    file_put_contents($tmp, '<?php function gojs_bench_probe_' . getmypid() . '() { return 1; }');
    $res = gojs_bench_measure('opcache_hit', $iterations, function () use ($tmp) {
        @include $tmp;
    });
    @unlink($tmp);
    return $res;
}

function gojs_bench_item_pdo($iterations) {
    if (!class_exists('PDO')) {
        return gojs_bench_unavailable('pdo_select', 'The PDO extension is not loaded');
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return gojs_bench_unavailable('pdo_select', 'The PDO sqlite driver is not available');
    }
    try {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE bench (id INTEGER PRIMARY KEY, v TEXT)');
        $pdo->exec("INSERT INTO bench (v) VALUES ('gojs')");
        $stmt = $pdo->prepare('SELECT v FROM bench WHERE id = ?');
        return gojs_bench_measure('pdo_select', $iterations, function () use ($stmt) {
            $stmt->execute(array(1));
            $stmt->fetchColumn();
        });
    } catch (Throwable $e) {
        return gojs_bench_unavailable('pdo_select', 'sqlite initialization failed: ' . $e->getMessage());
    }
}

function gojs_bench_item_redis($iterations) {
    if (!class_exists('Redis')) {
        return gojs_bench_unavailable('redis_get', 'The Redis extension is not loaded');
    }
    try {
        $redis = new Redis();
        if (!@$redis->connect('127.0.0.1', 6379, 0.5)) {
            return gojs_bench_unavailable('redis_get', 'Redis at 127.0.0.1:6379 is unreachable');
        }
        $redis->set('gojs_bench', 'payload');
        $res = gojs_bench_measure('redis_get', $iterations, function () use ($redis) {
            $redis->get('gojs_bench');
        });
        @$redis->close();
        return $res;
    } catch (Throwable $e) {
        return gojs_bench_unavailable('redis_get', 'Redis connection error: ' . $e->getMessage());
    }
}

function gojs_bench_item_curl_multi($iterations) {
    if (!function_exists('curl_multi_init')) {
        return gojs_bench_unavailable('curl_multi', 'The curl extension is not loaded');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'gojscurl');
    if ($tmp === false) {
        return gojs_bench_unavailable('curl_multi', 'Unable to create a temporary file');
    }
    file_put_contents($tmp, 'gojs');
    $url = 'file://' . str_replace('\\', '/', $tmp);
    $probe = @curl_init($url);
    if ($probe === false) {
        @unlink($tmp);
        return gojs_bench_unavailable('curl_multi', 'curl initialization failed');
    }
    @curl_setopt($probe, CURLOPT_RETURNTRANSFER, true);
    $ok = @curl_exec($probe);
    $err = @curl_errno($probe);
    @curl_close($probe);
    if ($ok === false || $err !== 0) {
        @unlink($tmp);
        return gojs_bench_unavailable('curl_multi', 'The curl file:// protocol is not available');
    }
    $res = gojs_bench_measure('curl_multi', $iterations, function () use ($url) {
        $mh = curl_multi_init();
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_multi_add_handle($mh, $ch);
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        curl_multi_close($mh);
    });
    @unlink($tmp);
    return $res;
}

function gojs_bench_runner_map() {
    return array(
        'curl_multi' => 'gojs_bench_item_curl_multi',
        'file_get_contents' => 'gojs_bench_item_file',
        'pdo_select' => 'gojs_bench_item_pdo',
        'redis_get' => 'gojs_bench_item_redis',
        'opcache_hit' => 'gojs_bench_item_opcache',
        'serialize' => 'gojs_bench_item_serialize',
        'json' => 'gojs_bench_item_json',
        'regex' => 'gojs_bench_item_regex',
    );
}

function gojs_bench_run_all($iterations = null) {
    $iterations = ($iterations === null) ? gojs_bench_iterations() : max(1, (int)$iterations);
    $map = gojs_bench_runner_map();
    $items = array();
    foreach (gojs_bench_names() as $name) {
        $fn = isset($map[$name]) ? $map[$name] : null;
        if ($fn === null || !function_exists($fn)) {
            $items[] = gojs_bench_unavailable($name, 'benchmark item is not implemented');
            continue;
        }
        try {
            $items[] = call_user_func($fn, $iterations);
        } catch (Throwable $e) {
            $items[] = gojs_bench_unavailable($name, 'Execution error: ' . $e->getMessage());
        }
    }
    return array(
        'iterations' => $iterations,
        'php_version' => PHP_VERSION,
        'items' => $items,
    );
}

function gojs_bench_validate_id($id) {
    if (!is_string($id) || $id === '') {
        return false;
    }
    return (bool)preg_match('/^[A-Za-z0-9\-]+$/', $id);
}

function gojs_bench_save($result) {
    if (!is_dir(gojs_bench_dir())) {
        @mkdir(gojs_bench_dir(), 0700, true);
    }
    $id = gmdate('Ymd-His') . '-' . substr(sha1((string)microtime(true) . random_int(0, 999999)), 0, 6);
    $result['id'] = $id;
    $result['created_at'] = time();
    $path = gojs_bench_dir() . '/' . $id . '.json';
    @file_put_contents($path, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    return $result;
}

function gojs_bench_list() {
    $dir = gojs_bench_dir();
    if (!is_dir($dir)) {
        return array();
    }
    $files = @glob($dir . '/*.json');
    if (!is_array($files)) {
        return array();
    }
    $out = array();
    foreach ($files as $f) {
        $id = basename($f, '.json');
        $out[] = array(
            'id' => $id,
            'created_at' => @filemtime($f),
            'size' => @filesize($f),
        );
    }
    usort($out, function ($a, $b) {
        return $b['created_at'] <=> $a['created_at'];
    });
    return $out;
}

function gojs_bench_load($id) {
    if (!gojs_bench_validate_id($id)) {
        return null;
    }
    $path = gojs_bench_dir() . '/' . $id . '.json';
    if (!file_exists($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function gojs_bench_compare($a, $b) {
    $aItems = (isset($a['items']) && is_array($a['items'])) ? $a['items'] : array();
    $bItems = (isset($b['items']) && is_array($b['items'])) ? $b['items'] : array();
    $index = array();
    foreach ($aItems as $item) {
        if (isset($item['name'])) {
            $index[$item['name']] = array('a' => $item, 'b' => null);
        }
    }
    foreach ($bItems as $item) {
        if (!isset($item['name'])) {
            continue;
        }
        if (!isset($index[$item['name']])) {
            $index[$item['name']] = array('a' => null, 'b' => null);
        }
        $index[$item['name']]['b'] = $item;
    }
    $rows = array();
    foreach (gojs_bench_names() as $name) {
        if (!isset($index[$name])) {
            continue;
        }
        $left = $index[$name]['a'];
        $right = $index[$name]['b'];
        $leftAvg = ($left !== null && $left['available']) ? (float)$left['avg_us'] : null;
        $rightAvg = ($right !== null && $right['available']) ? (float)$right['avg_us'] : null;
        $diff = null;
        if ($leftAvg !== null && $rightAvg !== null && $leftAvg > 0) {
            $diff = round((($rightAvg - $leftAvg) / $leftAvg) * 100, 2);
        }
        $rows[] = array(
            'name' => $name,
            'a_avg_us' => $leftAvg,
            'b_avg_us' => $rightAvg,
            'diff_pct' => $diff,
            'faster' => ($diff === null) ? null : ($diff < 0 ? 'b' : ($diff > 0 ? 'a' : 'equal')),
        );
    }
    return $rows;
}

function gojs_api_php_bench_run() {
    $body = gojs_get_body();
    $iterations = isset($body['iterations']) ? (int)$body['iterations'] : null;
    if ($iterations !== null && ($iterations < 1 || $iterations > 100000)) {
        gojs_json_response(null, array(
            'code' => 'invalid_iterations',
            'message' => 'iterations must be between 1 and 100000',
        ), 400);
    }
    $started = microtime(true);
    $result = gojs_bench_run_all($iterations);
    $result['duration_ms'] = (int)round((microtime(true) - $started) * 1000);
    $saved = gojs_bench_save($result);
    gojs_log_operation('php.bench_run', 'php/bench', true, 'id=' . $saved['id'] . ' ms=' . $result['duration_ms']);
    gojs_json_response(array(
        'id' => $saved['id'],
        'created_at' => $saved['created_at'],
        'iterations' => $result['iterations'],
        'duration_ms' => $result['duration_ms'],
        'items' => $result['items'],
    ));
}

function gojs_api_php_bench_compare() {
    $raw = isset($_GET['version']) ? (string)$_GET['version'] : '';
    $ids = array();
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part !== '') {
            $ids[] = $part;
        }
    }
    $available = gojs_bench_list();
    if (count($ids) < 2) {
        if (count($available) < 2) {
            gojs_json_response(null, array(
                'code' => 'not_enough_runs',
                'message' => 'At least two benchmark runs are needed for a comparison',
            ), 404);
        }
        $ids = array($available[1]['id'], $available[0]['id']);
    }
    $a = gojs_bench_load($ids[0]);
    $b = gojs_bench_load($ids[1]);
    if ($a === null || $b === null) {
        gojs_json_response(null, array(
            'code' => 'bench_run_not_found',
            'message' => 'The requested benchmark result does not exist',
            'ids' => $ids,
        ), 404);
    }
    gojs_json_response(array(
        'a' => array('id' => $a['id'], 'created_at' => isset($a['created_at']) ? $a['created_at'] : null, 'php_version' => isset($a['php_version']) ? $a['php_version'] : null),
        'b' => array('id' => $b['id'], 'created_at' => isset($b['created_at']) ? $b['created_at'] : null, 'php_version' => isset($b['php_version']) ? $b['php_version'] : null),
        'rows' => gojs_bench_compare($a, $b),
    ));
}
