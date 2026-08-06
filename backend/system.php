<?php




function gojs_count_files($dir, $max_depth = 5, $depth = 0) {
    $count = 0;
    $size = 0;

    if ($depth >= $max_depth) {
        return array($count, $size);
    }

    $handle = @opendir($dir);
    if (!$handle) {
        return array($count, $size);
    }

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;

        if (is_dir($path) && !is_link($path)) {
            list($sub_count, $sub_size) = gojs_count_files($path, $max_depth, $depth + 1);
            $count += $sub_count;
            $size += $sub_size;
        } else {
            $count++;
            $size += @filesize($path);
        }
    }
    closedir($handle);

    return array($count, $size);
}

function gojs_find_recent_files($dir, $limit = 5, $max_depth = 5, $depth = 0) {
    $files = array();

    if ($depth >= $max_depth) {
        return $files;
    }

    $handle = @opendir($dir);
    if (!$handle) {
        return $files;
    }

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;

        if (is_dir($path) && !is_link($path)) {
            $sub_files = gojs_find_recent_files($path, $limit, $max_depth, $depth + 1);
            $files = array_merge($files, $sub_files);
        } else {
            $mtime = @filemtime($path);
            $files[] = array(
                'path' => $path,
                'mtime' => $mtime,
            );
        }
    }
    closedir($handle);

    usort($files, function($a, $b) {
        return $b['mtime'] - $a['mtime'];
    });

    return array_slice($files, 0, $limit);
}

function gojs_api_dashboard() {
    global $root_path;

    $capabilities = gojs_get_capabilities();

    $disk_total = @disk_total_space($root_path);
    $disk_free = @disk_free_space($root_path);
    $disk_used = ($disk_total && $disk_free) ? ($disk_total - $disk_free) : 0;

    list($file_count, $total_size) = gojs_count_files($root_path, 5);

    $recent_raw = gojs_find_recent_files($root_path, 5, 5);
    $recent_files = array();
    foreach ($recent_raw as $item) {
        $rel = gojs_relative_path($item['path']);
        $recent_files[] = gojs_get_file_info($item['path'], $rel);
    }

    $hostname = function_exists('gethostname') ? @gethostname() : 'unknown';

    $data = array(
        'phpVersion' => $capabilities['phpVersion'],
        'sapi' => $capabilities['sapi'],
        'webServer' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : 'Unknown',
        'hostname' => $hostname ? $hostname : 'unknown',
        'timezone' => date_default_timezone_get(),
        'now' => time(),
        'diskTotal' => $disk_total ? $disk_total : 0,
        'diskFree' => $disk_free ? $disk_free : 0,
        'diskUsed' => $disk_used,
        'rootPath' => '/',
        'fileCount' => $file_count,
        'totalSize' => $total_size,
        'maxUpload' => $capabilities['maxUpload'],
        'maxPost' => $capabilities['maxPost'],
        'memoryLimit' => $capabilities['memoryLimit'],
        'recentFiles' => $recent_files,
    );

    gojs_json_response($data);
}

function gojs_api_phpinfo() {
    $core_ini_keys = array(
        'memory_limit',
        'upload_max_filesize',
        'post_max_size',
        'max_execution_time',
        'display_errors',
        'error_reporting',
        'date.timezone',
        'file_uploads',
        'max_file_uploads',
        'open_basedir',
        'allow_url_fopen',
        'session.gc_maxlifetime',
        'session.cookie_httponly',
        'session.cookie_secure',
    );

    $core_ini = array();
    foreach ($core_ini_keys as $key) {
        $core_ini[$key] = (string)ini_get($key);
    }

    $env_keys = array('PATH', 'HOME', 'USER', 'LANG');
    $env = array();
    foreach ($env_keys as $key) {
        if (isset($_ENV[$key])) {
            $env[$key] = $_ENV[$key];
        }
    }

    $server_keys = array(
        'SERVER_SOFTWARE',
        'SERVER_NAME',
        'SERVER_ADDR',
        'SERVER_PORT',
        'DOCUMENT_ROOT',
        'HTTP_HOST',
        'REQUEST_URI',
        'REMOTE_ADDR',
        'REMOTE_PORT',
        'SCRIPT_NAME',
        'PHP_SELF',
    );
    $server = array();
    foreach ($server_keys as $key) {
        if (isset($_SERVER[$key])) {
            $server[$key] = $_SERVER[$key];
        }
    }

    $data = array(
        'version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'iniFile' => php_ini_loaded_file(),
        'loadedExtensions' => get_loaded_extensions(),
        'coreIni' => $core_ini,
        'env' => $env,
        'server' => $server,
    );

    gojs_json_response($data);
}

function gojs_api_phpinfo_ini() {
    $search = gojs_get_param('search', '');

    $ini = ini_get_all(null, false);

    if ($search) {
        $result = array();
        foreach ($ini as $key => $value) {
            if (stripos($key, $search) !== false) {
                $result[$key] = (string)$value;
            }
        }
        gojs_json_response($result);
    } else {
        $result = array();
        foreach ($ini as $key => $value) {
            $result[$key] = (string)$value;
        }
        gojs_json_response($result);
    }
}

function gojs_ini_bool($key) {
    $val = strtolower((string)ini_get($key));
    return $val === '1' || $val === 'on' || $val === 'true';
}

function gojs_ini_display($key, $off_label = 'Off') {
    $val = (string)ini_get($key);
    return $val === '' ? $off_label : $val;
}

function gojs_api_health_check() {
    $security = array();
    $performance = array();
    $compatibility = array();

    $security[] = array(
        'name' => 'display_errors',
        'currentValue' => gojs_ini_display('display_errors'),
        'recommendedValue' => 'Off',
        'status' => gojs_ini_bool('display_errors') ? 'danger' : 'pass',
        'description' => '生产环境应关闭错误显示，避免向用户泄露敏感的路径与配置信息',
    );

    $security[] = array(
        'name' => 'expose_php',
        'currentValue' => gojs_ini_display('expose_php'),
        'recommendedValue' => 'Off',
        'status' => gojs_ini_bool('expose_php') ? 'warning' : 'pass',
        'description' => '关闭后可隐藏响应头中的 PHP 版本信息，避免攻击者利用已知版本漏洞',
    );

    $security[] = array(
        'name' => 'allow_url_include',
        'currentValue' => gojs_ini_display('allow_url_include'),
        'recommendedValue' => 'Off',
        'status' => gojs_ini_bool('allow_url_include') ? 'danger' : 'pass',
        'description' => '禁止远程文件包含，可有效防止远程文件包含（RFI）攻击',
    );

    $security[] = array(
        'name' => 'allow_url_fopen',
        'currentValue' => gojs_ini_display('allow_url_fopen'),
        'recommendedValue' => 'Off',
        'status' => gojs_ini_bool('allow_url_fopen') ? 'warning' : 'pass',
        'description' => '关闭远程文件访问可提升安全性，但可能影响部分依赖远程请求的功能',
    );

    $ob_val = (string)ini_get('open_basedir');
    $security[] = array(
        'name' => 'open_basedir',
        'currentValue' => $ob_val === '' ? '未设置' : $ob_val,
        'recommendedValue' => '设置目录限制',
        'status' => $ob_val === '' ? 'warning' : 'pass',
        'description' => '限制 PHP 可访问的目录范围，防止跨目录越权访问',
    );

    $df_val = (string)ini_get('disable_functions');
    $dangerous_funcs = array('exec', 'system', 'shell_exec', 'passthru', 'popen', 'proc_open');
    $disabled_funcs = $df_val ? array_map('trim', explode(',', $df_val)) : array();
    $not_disabled = array();
    foreach ($dangerous_funcs as $f) {
        if (!in_array($f, $disabled_funcs, true)) {
            $not_disabled[] = $f;
        }
    }
    if (count($not_disabled) >= 3) {
        $df_status = 'danger';
    } elseif (count($not_disabled) > 0) {
        $df_status = 'warning';
    } else {
        $df_status = 'pass';
    }
    $security[] = array(
        'name' => 'disable_functions',
        'currentValue' => $df_val === '' ? '未禁用' : $df_val,
        'recommendedValue' => '禁用 exec/system/shell_exec 等危险函数',
        'status' => $df_status,
        'description' => '禁用危险函数可显著降低命令执行类漏洞的风险',
    );

    $security[] = array(
        'name' => 'session.cookie_httponly',
        'currentValue' => gojs_ini_display('session.cookie_httponly'),
        'recommendedValue' => 'On',
        'status' => gojs_ini_bool('session.cookie_httponly') ? 'pass' : 'danger',
        'description' => '开启后 Cookie 无法被 JavaScript 读取，可缓解 XSS 窃取会话的风险',
    );

    $security[] = array(
        'name' => 'session.cookie_secure',
        'currentValue' => gojs_ini_display('session.cookie_secure'),
        'recommendedValue' => 'On（HTTPS 环境）',
        'status' => gojs_ini_bool('session.cookie_secure') ? 'pass' : 'warning',
        'description' => '仅在 HTTPS 连接下传输 Cookie，HTTPS 站点应开启',
    );

    $ss_val = (string)ini_get('session.cookie_samesite');
    $ss_lower = strtolower($ss_val);
    $security[] = array(
        'name' => 'session.cookie_samesite',
        'currentValue' => $ss_val === '' ? '未设置' : $ss_val,
        'recommendedValue' => 'Strict 或 Lax',
        'status' => ($ss_lower === 'strict' || $ss_lower === 'lax') ? 'pass' : 'warning',
        'description' => '设置 SameSite 属性可缓解跨站请求伪造（CSRF）攻击',
    );

    $performance[] = array(
        'name' => 'opcache.enable',
        'currentValue' => gojs_ini_display('opcache.enable'),
        'recommendedValue' => 'On',
        'status' => gojs_ini_bool('opcache.enable') ? 'pass' : 'warning',
        'description' => '启用 OPcache 可缓存字节码，显著提升 PHP 性能',
    );

    $rcs_val = (string)ini_get('realpath_cache_size');
    $rcs_bytes = gojs_return_bytes($rcs_val);
    $performance[] = array(
        'name' => 'realpath_cache_size',
        'currentValue' => $rcs_val === '' ? '未设置' : $rcs_val,
        'recommendedValue' => '>= 4096K',
        'status' => $rcs_bytes >= 4096 * 1024 ? 'pass' : 'warning',
        'description' => '增大路径缓存可减少文件系统 stat 调用，提升包含大量文件时的性能',
    );

    $ml_val = (string)ini_get('memory_limit');
    $ml_bytes = gojs_return_bytes($ml_val);
    $performance[] = array(
        'name' => 'memory_limit',
        'currentValue' => $ml_val === '' ? '未设置' : $ml_val,
        'recommendedValue' => '>= 128M',
        'status' => $ml_bytes >= 128 * 1024 * 1024 ? 'pass' : 'warning',
        'description' => '单个脚本可使用的内存上限，过小可能导致复杂任务内存不足',
    );

    $met_val = (string)ini_get('max_execution_time');
    $met_num = (int)$met_val;
    $performance[] = array(
        'name' => 'max_execution_time',
        'currentValue' => $met_val === '' ? '0' : $met_val,
        'recommendedValue' => '>= 30',
        'status' => $met_num >= 30 ? 'pass' : 'warning',
        'description' => '脚本最大执行时间（秒），过小可能导致长任务超时',
    );

    $umf_val = (string)ini_get('upload_max_filesize');
    $umf_bytes = gojs_return_bytes($umf_val);
    $performance[] = array(
        'name' => 'upload_max_filesize',
        'currentValue' => $umf_val === '' ? '未设置' : $umf_val,
        'recommendedValue' => '>= 10M',
        'status' => $umf_bytes >= 10 * 1024 * 1024 ? 'pass' : 'warning',
        'description' => '单文件上传大小上限，过小会影响大文件上传',
    );

    $pms_val = (string)ini_get('post_max_size');
    $pms_bytes = gojs_return_bytes($pms_val);
    $performance[] = array(
        'name' => 'post_max_size',
        'currentValue' => $pms_val === '' ? '未设置' : $pms_val,
        'recommendedValue' => '>= 10M',
        'status' => $pms_bytes >= 10 * 1024 * 1024 ? 'pass' : 'warning',
        'description' => 'POST 请求体大小上限，需大于 upload_max_filesize',
    );

    $compatibility[] = gojs_build_compat(
        'WordPress',
        version_compare(PHP_VERSION, '7.4.0', '>='),
        array('PHP 7.4+（推荐 8.0+）', 'MySQL 5.6+ / MariaDB 10.1+'),
        array('mysqli', 'json')
    );

    $compatibility[] = gojs_build_compat(
        'Typecho',
        version_compare(PHP_VERSION, '7.2.0', '>='),
        array('PHP 7.2+'),
        array('mbstring', 'json')
    );

    $compatibility[] = gojs_build_compat(
        'Laravel 11',
        version_compare(PHP_VERSION, '8.2.0', '>='),
        array('PHP 8.2+'),
        array('mbstring', 'openssl', 'pdo', 'tokenizer', 'xml')
    );

    $compatibility[] = gojs_build_compat(
        'ThinkPHP 8',
        version_compare(PHP_VERSION, '8.0.0', '>='),
        array('PHP 8.0+'),
        array('mbstring', 'json', 'pdo')
    );

    $summary = array('pass' => 0, 'warning' => 0, 'danger' => 0, 'total' => 0);
    foreach (array_merge($security, $performance) as $item) {
        $summary['total']++;
        if ($item['status'] === 'pass') {
            $summary['pass']++;
        } elseif ($item['status'] === 'warning') {
            $summary['warning']++;
        } elseif ($item['status'] === 'danger') {
            $summary['danger']++;
        }
    }
    foreach ($compatibility as $item) {
        $summary['total']++;
        if ($item['pass']) {
            $summary['pass']++;
        } else {
            $summary['danger']++;
        }
    }

    gojs_json_response(array(
        'security' => $security,
        'performance' => $performance,
        'compatibility' => $compatibility,
        'summary' => $summary,
    ));
}

function gojs_api_env_check() {
    $items = array();

    $extensions = array(
        'mysqli' => array('feature_key' => 'database_mysql', 'suggestion' => '联系主机商启用 mysqli 扩展'),
        'pdo_mysql' => array('feature_key' => 'database_pdo_mysql', 'suggestion' => '联系主机商启用 pdo_mysql 扩展'),
        'zip' => array('feature_key' => 'file_zip', 'suggestion' => '联系主机商启用 zip 扩展，或使用 PharData 替代'),
        'gd' => array('feature_key' => 'image_thumbnail', 'suggestion' => '联系主机商启用 gd 扩展'),
        'openssl' => array('feature_key' => 'crypto_ssl', 'suggestion' => '联系主机商启用 openssl 扩展'),
        'mbstring' => array('feature_key' => 'multibyte_string', 'suggestion' => '联系主机商启用 mbstring 扩展'),
        'json' => array('feature_key' => 'json_processing', 'suggestion' => 'PHP 7.4+ 应内置 json 扩展'),
        'session' => array('feature_key' => 'session_management', 'suggestion' => '联系主机商启用 session 扩展'),
    );

    foreach ($extensions as $ext => $info) {
        $loaded = extension_loaded($ext);
        $items[] = array(
            'name' => $ext,
            'category' => 'extension',
            'available' => $loaded,
            'reason_key' => $loaded ? '' : 'extension_not_installed',
            'reason_params' => $loaded ? null : array('ext' => $ext),
            'feature_key' => $info['feature_key'],
            'suggestion_key' => $loaded ? '' : 'suggestion_contact_host',
            'suggestion_params' => $loaded ? null : array('msg' => $info['suggestion']),
        );
    }

    $disabled = explode(',', (string)ini_get('disable_functions'));
    $disabled = array_map('trim', $disabled);

    $functions = array(
        'exec' => array('feature_key' => 'cron_terminal', 'suggestion' => '面板仍可使用，但 Cron 管理功能将不可用'),
        'proc_open' => array('feature_key' => 'process_terminal', 'suggestion' => '面板仍可使用，但部分高级功能受限'),
        'shell_exec' => array('feature_key' => 'command_exec', 'suggestion' => '面板仍可使用，但部分高级功能受限'),
    );

    foreach ($functions as $func => $info) {
        $exists = function_exists($func);
        $disabled_check = !in_array($func, $disabled);
        $available = $exists && $disabled_check;
        $reason_key = '';
        $reason_params = null;
        if (!$exists) {
            $reason_key = 'function_not_exists';
            $reason_params = array('func' => $func);
        } elseif (!$disabled_check) {
            $reason_key = 'function_disabled';
            $reason_params = array('func' => $func);
        }
        $items[] = array(
            'name' => $func . '()',
            'category' => 'function',
            'available' => $available,
            'reason_key' => $reason_key,
            'reason_params' => $reason_params,
            'feature_key' => $info['feature_key'],
            'suggestion_key' => $available ? '' : 'suggestion_contact_host',
            'suggestion_params' => $available ? null : array('msg' => $info['suggestion']),
        );
    }

    $proc_readable = is_readable('/proc');
    $items[] = array(
        'name' => '/proc 可读',
        'category' => 'system',
        'available' => $proc_readable,
        'reason_key' => $proc_readable ? '' : 'proc_not_readable',
        'reason_params' => null,
        'feature_key' => 'process_cpu_monitor',
        'suggestion_key' => $proc_readable ? '' : 'suggestion_proc_limited',
        'suggestion_params' => null,
    );

    $url_fopen = (bool)ini_get('allow_url_fopen');
    $items[] = array(
        'name' => 'allow_url_fopen',
        'category' => 'config',
        'available' => $url_fopen,
        'reason_key' => $url_fopen ? '' : 'allow_url_fopen_off',
        'reason_params' => null,
        'feature_key' => 'remote_file_download',
        'suggestion_key' => $url_fopen ? '' : 'suggestion_url_fopen_curl',
        'suggestion_params' => null,
    );

    $curl_available = function_exists('curl_init');
    $items[] = array(
        'name' => 'cURL',
        'category' => 'extension',
        'available' => $curl_available,
        'reason_key' => $curl_available ? '' : 'curl_not_installed',
        'reason_params' => null,
        'feature_key' => 'remote_file_download',
        'suggestion_key' => $curl_available ? '' : 'suggestion_contact_host',
        'suggestion_params' => $curl_available ? null : array('msg' => '联系主机商启用 curl 扩展'),
    );

    $total = count($items);
    $passed = count(array_filter($items, function($i) { return $i['available']; }));

    return gojs_json_response(array(
        'items' => $items,
        'summary' => array(
            'total' => $total,
            'passed' => $passed,
            'failed' => $total - $passed,
        ),
    ));
}

function gojs_build_compat($name, $php_ok, $php_req_lines, $exts) {
    $requirements = $php_req_lines;
    $missing = array();
    if (!$php_ok) {
        $missing[] = 'PHP 版本不满足';
    }
    foreach ($exts as $ext) {
        $requirements[] = '扩展: ' . $ext;
        if (!extension_loaded($ext)) {
            $missing[] = $ext;
        }
    }
    return array(
        'name' => $name,
        'pass' => $php_ok && empty($missing),
        'requirements' => $requirements,
        'missing' => $missing,
    );
}

function gojs_api_system() {
    global $root_path;

    $files_root = !empty($GLOBALS['files_root']) ? $GLOBALS['files_root'] : $root_path;

    $disk_total = @disk_total_space($files_root);
    $disk_free = @disk_free_space($files_root);
    $disk_used = ($disk_total && $disk_free) ? ($disk_total - $disk_free) : 0;

    $load_average = null;
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        if ($load) {
            $load_average = array_values($load);
        }
    }

    $uptime = null;
    if (is_readable('/proc/uptime')) {
        $content = @file_get_contents('/proc/uptime');
        if ($content) {
            $parts = preg_split('/\s+/', trim($content));
            if (!empty($parts[0])) {
                $uptime = (float)$parts[0];
            }
        }
    }

    $mem_total = null;
    $mem_available = null;
    $mem_used = null;
    $mem_percent = null;
    if (is_readable('/proc/meminfo')) {
        $meminfo = @file_get_contents('/proc/meminfo');
        if ($meminfo) {
            $lines = explode("\n", $meminfo);
            $mem_kv = array();
            foreach ($lines as $line) {
                if (preg_match('/^([A-Za-z_]+):\s*(\d+)/', $line, $m)) {
                    $mem_kv[$m[1]] = (int)$m[2];
                }
            }
            if (isset($mem_kv['MemTotal'])) {
                $mem_total = $mem_kv['MemTotal'];
            }
            if (isset($mem_kv['MemAvailable'])) {
                $mem_available = $mem_kv['MemAvailable'];
            } elseif (isset($mem_kv['MemFree'])) {
                $mem_available = $mem_kv['MemFree'];
                if (isset($mem_kv['Buffers'])) {
                    $mem_available += $mem_kv['Buffers'];
                }
                if (isset($mem_kv['Cached'])) {
                    $mem_available += $mem_kv['Cached'];
                }
            }
            if ($mem_total !== null && $mem_total > 0) {
                if ($mem_available !== null) {
                    $mem_used = $mem_total - $mem_available;
                    if ($mem_used < 0) {
                        $mem_used = 0;
                    }
                    $mem_percent = round(($mem_used / $mem_total) * 100, 1);
                }
            }
        }
    }

    $data = array(
        'diskTotal' => $disk_total ? $disk_total : 0,
        'diskFree' => $disk_free ? $disk_free : 0,
        'diskUsed' => $disk_used,
        'loadAverage' => $load_average,
        'uptime' => $uptime,
        'serverAddr' => isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : null,
        'serverName' => isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : null,
        'webServer' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : null,
        'memTotal' => $mem_total,
        'memAvailable' => $mem_available,
        'memUsed' => $mem_used,
        'memPercent' => $mem_percent,
    );

    gojs_json_response($data);
}

function gojs_api_processes() {
    if (!is_readable('/proc')) {
        gojs_json_response(null, array(
            'code' => 'not_supported',
            'message' => '系统不支持进程查看',
        ), 400);
    }

    $pids = array();
    $handle = @opendir('/proc');
    if (!$handle) {
        gojs_json_response(null, array(
            'code' => 'read_failed',
            'message' => '读取 /proc 失败',
        ), 500);
    }
    while (($entry = readdir($handle)) !== false) {
        if (preg_match('/^\d+$/', $entry)) {
            $pids[] = (int)$entry;
        }
    }
    closedir($handle);

    $total_mem = 0;
    $meminfo = @file_get_contents('/proc/meminfo');
    if ($meminfo) {
        if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $m)) {
            $total_mem = (int)$m[1];
        }
    }

    $sample_pids = array_slice($pids, 0, 50);

    function gojs_read_stat_fields($pid) {
        $stat_path = '/proc/' . $pid . '/stat';
        if (!is_readable($stat_path)) {
            return null;
        }
        $content = @file_get_contents($stat_path);
        if (!$content) {
            return null;
        }
        $open = strpos($content, '(');
        $close = strrpos($content, ')');
        if ($open === false || $close === false || $close <= $open) {
            return null;
        }
        $prefix = substr($content, 0, $open);
        $suffix = substr($content, $close + 1);
        $rest = $prefix . 'COMM' . $suffix;
        $fields = preg_split('/\s+/', trim($rest));
        if (count($fields) < 22) {
            return null;
        }
        return $fields;
    }

    $stat_t1 = array();
    $jiffies_total_t1 = 0;
    $stat_content = @file_get_contents('/proc/stat');
    if ($stat_content) {
        $lines = explode("\n", $stat_content);
        foreach ($lines as $line) {
            if (strpos($line, 'cpu ') === 0) {
                $parts = preg_split('/\s+/', trim($line));
                for ($i = 1; $i < count($parts) && $i <= 8; $i++) {
                    $jiffies_total_t1 += (int)$parts[$i];
                }
                break;
            }
        }
    }
    foreach ($sample_pids as $pid) {
        $f = gojs_read_stat_fields($pid);
        if ($f !== null) {
            $utime = isset($f[13]) ? (int)$f[13] : 0;
            $stime = isset($f[14]) ? (int)$f[14] : 0;
            $stat_t1[$pid] = $utime + $stime;
        }
    }

    usleep(200000);

    $stat_t2 = array();
    $jiffies_total_t2 = 0;
    $stat_content2 = @file_get_contents('/proc/stat');
    if ($stat_content2) {
        $lines = explode("\n", $stat_content2);
        foreach ($lines as $line) {
            if (strpos($line, 'cpu ') === 0) {
                $parts = preg_split('/\s+/', trim($line));
                for ($i = 1; $i < count($parts) && $i <= 8; $i++) {
                    $jiffies_total_t2 += (int)$parts[$i];
                }
                break;
            }
        }
    }
    foreach ($sample_pids as $pid) {
        $f = gojs_read_stat_fields($pid);
        if ($f !== null) {
            $utime = isset($f[13]) ? (int)$f[13] : 0;
            $stime = isset($f[14]) ? (int)$f[14] : 0;
            $stat_t2[$pid] = $utime + $stime;
        }
    }

    $delta_total = $jiffies_total_t2 - $jiffies_total_t1;

    $processes = array();
    foreach ($pids as $pid) {
        $status_file = '/proc/' . $pid . '/status';
        $cmdline_file = '/proc/' . $pid . '/cmdline';

        $name = '';
        $vm_rss = 0;

        if (is_readable($status_file)) {
            $status_content = @file_get_contents($status_file);
            if ($status_content) {
                $lines = explode("\n", $status_content);
                foreach ($lines as $line) {
                    if (strpos($line, 'Name:') === 0) {
                        $name = trim(substr($line, 5));
                    } elseif (strpos($line, 'VmRSS:') === 0) {
                        preg_match('/\d+/', $line, $matches);
                        if ($matches) {
                            $vm_rss = (int)$matches[0];
                        }
                    }
                }
            }
        }

        $cmdline = '';
        if (is_readable($cmdline_file)) {
            $cmdline_content = @file_get_contents($cmdline_file);
            if ($cmdline_content) {
                $cmdline = str_replace("\0", ' ', $cmdline_content);
                $cmdline = trim($cmdline);
            }
        }

        $mem_percent = 0;
        if ($total_mem > 0 && $vm_rss > 0) {
            $mem_percent = round(($vm_rss / $total_mem) * 100, 1);
        }

        $cpu = null;
        if (isset($stat_t1[$pid]) && isset($stat_t2[$pid]) && $delta_total > 0) {
            $delta_proc = $stat_t2[$pid] - $stat_t1[$pid];
            if ($delta_proc < 0) {
                $delta_proc = 0;
            }
            $cpu = round(($delta_proc / $delta_total) * 100, 1);
            if ($cpu < 0) {
                $cpu = 0;
            }
            if ($cpu > 100) {
                $cpu = 100.0;
            }
        }

        $processes[] = array(
            'pid' => $pid,
            'name' => $name,
            'cmdline' => $cmdline,
            'cpu' => $cpu,
            'mem' => $mem_percent,
        );
    }

    gojs_json_response($processes);
}

function gojs_api_cron() {
    $capabilities = gojs_get_capabilities();

    if (!$capabilities['cron']) {
        gojs_json_response(null, array(
            'code' => 'not_supported',
            'message' => '系统不支持 crontab',
        ), 400);
    }

    $output = array();
    $return_var = 0;

    exec('crontab -l 2>&1', $output, $return_var);

    $jobs = array();

    if ($return_var === 0 && !empty($output)) {
        foreach ($output as $line) {
            $line = trim($line);

            if (!$line || strpos($line, '#') === 0) {
                continue;
            }

            if (preg_match('/^(MAILTO|SHELL|PATH|HOME|CRON_TZ)\s*=/', $line)) {
                continue;
            }

            if (strpos($line, '@') === 0) {
                $parts = preg_split('/\s+/', $line, 2);
                if (count($parts) >= 2) {
                    $jobs[] = array(
                        'expression' => $parts[0],
                        'command' => $parts[1],
                        'raw' => $line,
                    );
                }
                continue;
            }

            $parts = preg_split('/\s+/', $line, 6);
            if (count($parts) >= 6) {
                $expression = implode(' ', array_slice($parts, 0, 5));
                $jobs[] = array(
                    'expression' => $expression,
                    'command' => $parts[5],
                    'raw' => $line,
                );
            }
        }
    }

    gojs_json_response($jobs);
}


function gojs_cron_capabilities() {
    $disabled = explode(',', (string)ini_get('disable_functions'));
    $disabled_list = array_map('trim', $disabled);
    $exec_available = function_exists('exec') && !in_array('exec', $disabled_list, true);

    $crontab_available = false;
    $method = 'none';
    $cron_file = null;
    $msg = '';
    $msg_key = null;
    $info_key = null;
    $info_params = array();

    if ($exec_available) {
        $out = array();
        $code = 0;
        @exec('command -v crontab 2>&1', $out, $code);
        if ($code === 0) {
            $crontab_available = true;
        }

        if (!$crontab_available) {
            $out2 = array();
            $code2 = 0;
            @exec('crontab -l 2>&1', $out2, $code2);
            if ($code2 === 0 || $code2 === 1) {
                $crontab_available = true;
            }
        }

        if ($crontab_available) {
            $method = 'exec';
        }
    }

    if (!$crontab_available) {
        $home = isset($_SERVER['HOME']) ? $_SERVER['HOME'] : '';
        $user = function_exists('get_current_user') ? get_current_user() : '';
        $cron_files = array();
        if ($home) {
            $cron_files[] = $home . '/.config/cron/crontab';
            $cron_files[] = $home . '/.crontab';
        }
        if ($user) {
            $cron_files[] = '/var/spool/cron/' . $user;
            $cron_files[] = '/var/spool/cron/crontabs/' . $user;
        }

        foreach ($cron_files as $file) {
            if ((is_writable(dirname($file)) || is_writable($file))) {
                $method = 'file';
                $cron_file = $file;
                $crontab_available = true;
                break;
            }
        }
    }

    $available = $exec_available;

    if (!$exec_available) {
        $msg_key = 'unavailable';
        $msg = '环境不支持 Cron 管理（exec 被禁且 crontab 文件不可写）';
    } elseif ($exec_available && !$crontab_available) {
        $info_key = 'crontab_cli_missing';
        $msg = 'exec() 可用但 crontab 命令未安装';
    }

    $result = array(
        'available'         => $available,
        'exec_available'    => $exec_available,
        'crontab_available' => $crontab_available,
        'message'           => $msg,
    );

    if ($msg_key !== null) {
        $result['message_key'] = $msg_key;
    }
    if ($info_key !== null) {
        $result['info_key'] = $info_key;
        $result['info_params'] = $info_params;
    }
    $result['method'] = $method;
    if ($cron_file !== null) {
        $result['cron_file'] = $cron_file;
    }

    return $result;
}


function gojs_cron_list() {
    $caps = gojs_cron_capabilities();
    if (!$caps['available']) {
        return array();
    }

    $cron_method = isset($caps['method']) ? $caps['method'] : 'none';

    $content = '';
    if ($cron_method === 'exec') {
        $output = array();
        @exec('crontab -l 2>/dev/null', $output);
        $content = implode("\n", $output);
    } else if ($cron_method === 'file' && isset($caps['cron_file'])) {
        if (file_exists($caps['cron_file'])) {
            $content = (string)@file_get_contents($caps['cron_file']);
        }
    }

    $jobs = array();
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        if (preg_match('/^(MAILTO|SHELL|PATH|HOME|CRON_TZ)\s*=/', $line)) {
            continue;
        }

        if (strpos($line, '@') === 0) {
            $parts = preg_split('/\s+/', $line, 2);
            if (count($parts) >= 2) {
                $jobs[] = array(
                    'expression' => $parts[0],
                    'command' => $parts[1],
                    'raw' => $line,
                );
            }
            continue;
        }

        $parts = preg_split('/\s+/', $line, 6);
        if (count($parts) >= 6) {
            $jobs[] = array(
                'expression' => implode(' ', array_slice($parts, 0, 5)),
                'command' => $parts[5],
                'raw' => $line,
            );
        }
    }

    return $jobs;
}


function gojs_cron_save($jobs) {
    $caps = gojs_cron_capabilities();
    if (!$caps['available']) {
        return false;
    }

    $content = "# Managed by Go.js Lite\n";
    foreach ($jobs as $job) {
        $expression = isset($job['expression']) ? trim($job['expression']) : '';
        $command = isset($job['command']) ? trim($job['command']) : '';
        if ($expression === '' || $command === '') {
            continue;
        }
        $content .= $expression . ' ' . $command . "\n";
    }

    if ($caps['method'] === 'exec') {
        $tmp = tempnam(sys_get_temp_dir(), 'gojs_cron');
        if ($tmp === false) {
            return false;
        }
        file_put_contents($tmp, $content);
        $output = array();
        $exit_code = 0;
        @exec('crontab ' . escapeshellarg($tmp) . ' 2>&1', $output, $exit_code);
        @unlink($tmp);
        return $exit_code === 0;
    } else if ($cron_method === 'file' && isset($caps['cron_file'])) {
        $dir = dirname($caps['cron_file']);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return @file_put_contents($caps['cron_file'], $content) !== false;
    }

    return false;
}

function gojs_api_cron_capabilities() {
    return gojs_json_response(gojs_cron_capabilities());
}

function gojs_api_cron_list() {
    return gojs_json_response(array('jobs' => gojs_cron_list()));
}

function gojs_api_cron_save() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['jobs']) || !is_array($input['jobs'])) {
        return gojs_json_response(null, array(
            'code' => 'invalid_input',
            'message' => '参数无效',
        ), 400);
    }

    foreach ($input['jobs'] as $job) {
        if (!isset($job['expression']) || !isset($job['command'])) {
            return gojs_json_response(null, array(
                'code' => 'invalid_job',
                'message' => '缺少 expression 或 command',
            ), 400);
        }
        
        $fields = preg_split('/\s+/', trim($job['expression']));
        if (count($fields) !== 5) {
            return gojs_json_response(null, array(
                'code' => 'invalid_expression',
                'message' => 'Cron 表达式必须为 5 个字段',
            ), 400);
        }
    }

    $result = gojs_cron_save($input['jobs']);
    if ($result) {
        gojs_log_operation('cron_save', count($input['jobs']) . ' jobs', true);
        return gojs_json_response(array('ok' => true));
    } else {
        gojs_log_operation('cron_save', count($input['jobs']) . ' jobs', false);
        return gojs_json_response(null, array(
            'code' => 'save_failed',
            'message' => '保存 crontab 失败',
        ), 500);
    }
}

function gojs_scan_dir_size($dir, $max_depth = 6, $depth = 0) {
    $size = 0;
    $count = 0;

    if ($depth >= $max_depth) {
        return array($size, $count);
    }

    $handle = @opendir($dir);
    if (!$handle) {
        return array($size, $count);
    }

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;

        if (is_dir($path) && !is_link($path)) {
            list($sub_size, $sub_count) = gojs_scan_dir_size($path, $max_depth, $depth + 1);
            $size += $sub_size;
            $count += $sub_count;
        } else {
            $fsize = @filesize($path);
            if ($fsize !== false) {
                $size += $fsize;
            }
            $count++;
        }
    }
    closedir($handle);

    return array($size, $count);
}

function gojs_find_large_files($dir, &$files, $threshold, $max_files, $max_depth = 8, $depth = 0) {
    if ($depth >= $max_depth || count($files) >= $max_files) {
        return;
    }

    $handle = @opendir($dir);
    if (!$handle) {
        return;
    }

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        if (count($files) >= $max_files) {
            break;
        }

        $path = $dir . '/' . $entry;

        if (is_dir($path) && !is_link($path)) {
            gojs_find_large_files($path, $files, $threshold, $max_files, $max_depth, $depth + 1);
        } else {
            $fsize = @filesize($path);
            if ($fsize !== false && $fsize >= $threshold) {
                $mtime = @filemtime($path);
                $rel = gojs_relative_path($path);
                $files[] = array(
                    'name' => $entry,
                    'path' => $rel,
                    'size' => $fsize,
                    'modified' => $mtime ? date('c', $mtime) : '',
                );
            }
        }
    }
    closedir($handle);
}

function gojs_api_disk_analysis() {
    global $root_path;

    $path = gojs_get_param('path', '/');
    if ($path === '') {
        $path = '/';
    }

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    if (!is_dir($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_directory',
            'message' => '路径不是目录',
        ), 400);
    }

    $disk_total = @disk_total_space($safe_path);
    $disk_free = @disk_free_space($safe_path);

    $directories = array();
    $total_size = 0;
    $max_dirs = 100;

    $handle = @opendir($safe_path);
    if (!$handle) {
        gojs_json_response(null, array(
            'code' => 'read_dir_failed',
            'message' => '读取目录失败',
        ), 500);
    }

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        if (count($directories) >= $max_dirs) {
            break;
        }

        $full_path = $safe_path . '/' . $entry;

        if (!is_dir($full_path) || is_link($full_path)) {
            continue;
        }

        list($size, $file_count) = gojs_scan_dir_size($full_path, 6);
        $total_size += $size;
        $rel = gojs_relative_path($full_path);
        $directories[] = array(
            'name' => $entry,
            'path' => $rel,
            'size' => $size,
            'fileCount' => $file_count,
            'percent' => 0,
        );
    }
    closedir($handle);

    foreach ($directories as &$d) {
        $d['percent'] = $total_size > 0 ? round(($d['size'] / $total_size) * 100, 2) : 0;
    }
    unset($d);

    usort($directories, function($a, $b) {
        return $b['size'] - $a['size'];
    });

    gojs_json_response(array(
        'directories' => $directories,
        'totalSize' => $total_size,
        'diskTotal' => $disk_total ? $disk_total : 0,
        'diskFree' => $disk_free ? $disk_free : 0,
    ));
}

function gojs_api_disk_analysis_large_files() {
    $path = gojs_get_param('path', '/');
    if ($path === '') {
        $path = '/';
    }

    $threshold = (int)gojs_get_param('threshold', 10485760);
    if ($threshold < 0) {
        $threshold = 10485760;
    }

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    if (!is_dir($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_directory',
            'message' => '路径不是目录',
        ), 400);
    }

    $files = array();
    $max_files = 100;

    gojs_find_large_files($safe_path, $files, $threshold, $max_files, 8);

    usort($files, function($a, $b) {
        return $b['size'] - $a['size'];
    });

    if (count($files) > $max_files) {
        $files = array_slice($files, 0, $max_files);
    }

    gojs_json_response(array(
        'files' => $files,
        'total' => count($files),
    ));
}

function gojs_find_error_log() {
    global $root_path;
    $candidates = array();
    $candidates[] = ini_get('error_log');
    $candidates[] = $root_path . '/.gojs/php_errors.log';
    $candidates[] = $root_path . '/error_log';
    $candidates[] = $root_path . '/logs/error.log';
    $candidates[] = $root_path . '/php_errorlog';
    $candidates[] = dirname($root_path) . '/logs/error.log';
    $candidates[] = dirname($root_path) . '/error_log';

    foreach ($candidates as $path) {
        if (!$path) continue;
        if (is_file($path) && is_readable($path)) {
            return $path;
        }
    }
    return false;
}

function gojs_api_error_log() {
    $log_path = gojs_find_error_log();

    if (!$log_path) {
        gojs_json_response(array(
            'found' => false,
            'entries' => array(),
            'path' => null,
        ));
    }

    $limit = (int)gojs_get_param('limit', 50);
    if ($limit <= 0) $limit = 50;
    if ($limit > 500) $limit = 500;

    $entries = array();
    $file = @fopen($log_path, 'r');
    if (!$file) {
        gojs_json_response(array(
            'found' => true,
            'path' => $log_path,
            'entries' => array(),
            'size' => filesize($log_path),
        ));
    }

    $lines = array();
    while (($line = fgets($file)) !== false) {
        $lines[] = $line;
        if (count($lines) > $limit * 2) {
            array_splice($lines, 0, count($lines) - $limit);
        }
    }
    fclose($file);

    $lines = array_slice($lines, -$limit);
    $lines = array_reverse($lines);

    foreach ($lines as $line) {
        $line = trim($line);
        if (!$line) continue;
        $type = 'info';
        if (stripos($line, 'Fatal error') !== false || stripos($line, 'PHP Fatal') !== false) {
            $type = 'fatal';
        } elseif (stripos($line, 'Warning') !== false || stripos($line, 'PHP Warning') !== false) {
            $type = 'warning';
        } elseif (stripos($line, 'Notice') !== false || stripos($line, 'PHP Notice') !== false) {
            $type = 'notice';
        } elseif (stripos($line, 'Deprecated') !== false || stripos($line, 'PHP Deprecated') !== false) {
            $type = 'deprecated';
        }
        $entries[] = array(
            'message' => $line,
            'type' => $type,
        );
    }

    gojs_json_response(array(
        'found' => true,
        'path' => $log_path,
        'entries' => $entries,
        'size' => filesize($log_path),
    ));
}

function gojs_api_error_log_clear() {
    $log_path = gojs_find_error_log();
    if (!$log_path) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '未找到错误日志',
        ), 404);
    }

    gojs_ensure_not_protected($log_path, '清空日志');

    if (@file_put_contents($log_path, '') === false) {
        gojs_json_response(null, array(
            'code' => 'clear_failed',
            'message' => '清空日志失败',
        ), 500);
    }

    gojs_json_response(array('success' => true));
}

function gojs_api_operation_log() {
    $log_file = CONFIG_DIR . '/operation_log.json';
    $logs = array();
    if (file_exists($log_file)) {
        $content = @file_get_contents($log_file);
        if ($content) {
            $logs = json_decode($content, true);
            if (!is_array($logs)) $logs = array();
        }
    }

    
    $logs = array_reverse($logs);

    
    $type = isset($_GET['type']) ? $_GET['type'] : '';
    $ip = isset($_GET['ip']) ? $_GET['ip'] : '';
    $user = isset($_GET['user']) ? $_GET['user'] : '';
    $date_from = isset($_GET['date_from']) ? (int)$_GET['date_from'] : 0;
    $date_to = isset($_GET['date_to']) ? (int)$_GET['date_to'] : 0;

    if ($type) {
        $logs = array_filter($logs, function($l) use ($type) {
            return isset($l['action']) && strpos($l['action'], $type) !== false;
        });
    }
    if ($ip) {
        $logs = array_filter($logs, function($l) use ($ip) {
            return isset($l['ip']) && strpos($l['ip'], $ip) !== false;
        });
    }
    if ($user !== '') {
        $logs = array_filter($logs, function($l) use ($user) {
            return isset($l['user']) && strpos($l['user'], $user) !== false;
        });
    }
    if ($date_from > 0) {
        $logs = array_filter($logs, function($l) use ($date_from) {
            return isset($l['timestamp']) && (int)$l['timestamp'] >= $date_from;
        });
    }
    if ($date_to > 0) {
        $logs = array_filter($logs, function($l) use ($date_to) {
            return isset($l['timestamp']) && (int)$l['timestamp'] <= $date_to;
        });
    }

    
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = 50;
    $total = count($logs);
    $logs = array_slice($logs, ($page - 1) * $per_page, $per_page);

    return gojs_json_response(array(
        'logs' => array_values($logs),
        'total' => $total,
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => ceil($total / $per_page),
    ));
}

function gojs_api_operation_log_clear() {
    $log_file = CONFIG_DIR . '/operation_log.json';
    @file_put_contents($log_file, '[]');
    gojs_log_operation('operation_log_clear', $log_file, true);
    return gojs_json_response(array('ok' => true));
}

function gojs_api_install_check() {
    global $root_path;

    $checks = array();

    $checks[] = array(
        'name' => 'PHP 版本',
        'pass' => version_compare(PHP_VERSION, '7.4.0', '>='),
        'value' => PHP_VERSION,
        'required' => '>= 7.4.0',
    );

    $required_extensions = array('json', 'mbstring', 'fileinfo');
    $optional_extensions = array('zip' => 'Zip 压缩', 'mysqli' => 'MySQL 数据库', 'gd' => 'GD 图像处理');

    foreach ($required_extensions as $ext) {
        $checks[] = array(
            'name' => '扩展: ' . $ext,
            'pass' => extension_loaded($ext),
            'value' => extension_loaded($ext) ? '已安装' : '未安装',
            'required' => '必需',
        );
    }

    foreach ($optional_extensions as $ext => $label) {
        $checks[] = array(
            'name' => '扩展: ' . $label,
            'pass' => extension_loaded($ext),
            'value' => extension_loaded($ext) ? '已安装' : '未安装',
            'required' => '可选',
        );
    }

    $checks[] = array(
        'name' => '根目录可写',
        'pass' => is_writable($root_path),
        'value' => is_writable($root_path) ? '可写' : '不可写',
        'required' => '必需',
    );

    $config_dir = $root_path . '/.gojs';
    $config_writable = true;
    if (is_dir($config_dir)) {
        $config_writable = is_writable($config_dir);
    } else {
        $config_writable = is_writable($root_path);
    }
    $checks[] = array(
        'name' => '配置目录可写',
        'pass' => $config_writable,
        'value' => $config_writable ? '可写' : '不可写',
        'required' => '必需',
    );

    $disabled = explode(',', ini_get('disable_functions'));
    $disabled = array_map('trim', $disabled);
    $important = array('exec', 'shell_exec', 'system', 'passthru');
    $missing_funcs = array();
    foreach ($important as $f) {
        if (in_array($f, $disabled)) {
            $missing_funcs[] = $f;
        }
    }
    $checks[] = array(
        'name' => '系统函数可用',
        'pass' => empty($missing_funcs),
        'value' => empty($missing_funcs) ? '正常' : '禁用: ' . implode(', ', $missing_funcs),
        'required' => '可选',
    );

    $all_pass = true;
    foreach ($checks as $c) {
        if ($c['required'] === '必需' && !$c['pass']) {
            $all_pass = false;
            break;
        }
    }

    gojs_json_response(array(
        'pass' => $all_pass,
        'checks' => $checks,
        'disabledFunctions' => $disabled,
    ));
}

function gojs_get_encryption_key() {
    global $config;

    if (!empty($config['encryption_key'])) {
        $key = $config['encryption_key'];

        return substr(hash('sha256', $key, true), 0, 32);
    }

    if (!empty($config['password_hash'])) {
        return substr(hash('sha256', $config['password_hash'], true), 0, 32);
    }

    return str_repeat("\0", 32);
}

function gojs_api_operation_log_export() {
    $log_file = CONFIG_DIR . '/operation_log.json';
    $logs = array();
    if (file_exists($log_file)) {
        $content = @file_get_contents($log_file);
        if ($content) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $logs = $decoded;
            }
        }
    }

    $body = gojs_get_body();
    $format = isset($body['format']) ? $body['format'] : 'csv';
    $scope = isset($body['scope']) ? $body['scope'] : 'all';

    if (!in_array($format, array('csv', 'jsonl', 'json'))) {
        $format = 'csv';
    }

    if ($scope === 'current_filter') {
        $action_filter = isset($body['action']) && is_array($body['action']) ? $body['action'] : array();
        $ip_like = isset($body['ip_like']) ? $body['ip_like'] : '';
        $user_like = isset($body['user']) ? $body['user'] : '';
        $from_ts = isset($body['date_from']) ? (int)$body['date_from'] : (isset($body['from_ts']) ? (int)$body['from_ts'] : 0);
        $to_ts = isset($body['date_to']) ? (int)$body['date_to'] : (isset($body['to_ts']) ? (int)$body['to_ts'] : 0);

        $logs = array_filter($logs, function($l) use ($action_filter, $ip_like, $user_like, $from_ts, $to_ts) {
            if (!empty($action_filter)) {
                $act = isset($l['action']) ? $l['action'] : '';
                if (!in_array($act, $action_filter, true)) return false;
            }
            if ($ip_like !== '') {
                $ip = isset($l['ip']) ? $l['ip'] : '';
                if (strpos($ip, $ip_like) === false) return false;
            }
            if ($user_like !== '') {
                $u = isset($l['user']) ? $l['user'] : '';
                if (strpos($u, $user_like) === false) return false;
            }
            if ($from_ts > 0) {
                $ts = isset($l['timestamp']) ? (int)$l['timestamp'] : 0;
                if ($ts < $from_ts) return false;
            }
            if ($to_ts > 0) {
                $ts = isset($l['timestamp']) ? (int)$l['timestamp'] : 0;
                if ($ts > $to_ts) return false;
            }
            return true;
        });
    }

    $logs = array_values($logs);

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $now = date('Ymd_His');
    $ext = $format === 'csv' ? 'csv' : ($format === 'json' ? 'json' : 'jsonl');
    $filename = 'operation_log_' . $now . '.' . $ext;

    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $export_bytes = 0;

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        $n = fwrite($out, "\xEF\xBB\xBF");
        $export_bytes += $n !== false ? $n : 0;
        $n = fputcsv($out, array('timestamp_iso', 'ip', 'action', 'detail', 'user'));
        $export_bytes += $n !== false ? $n : 0;
        $chunk = 0;
        foreach ($logs as $l) {
            $ts_iso = isset($l['time']) ? $l['time'] : date('Y-m-d H:i:s', isset($l['timestamp']) ? (int)$l['timestamp'] : time());
            $row = array(
                $ts_iso,
                isset($l['ip']) ? $l['ip'] : '',
                isset($l['action']) ? $l['action'] : '',
                isset($l['detail']) ? $l['detail'] : (isset($l['target']) ? $l['target'] : ''),
                isset($l['user']) ? $l['user'] : 'admin',
            );
            $n = fputcsv($out, $row);
            $export_bytes += $n !== false ? $n : 0;
            $chunk++;
            if ($chunk >= 100) {
                flush();
                usleep(0);
                $chunk = 0;
            }
        }
        fclose($out);
    } elseif ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $arr = array();
        foreach ($logs as $l) {
            $ts_iso = isset($l['time']) ? $l['time'] : date('Y-m-d H:i:s', isset($l['timestamp']) ? (int)$l['timestamp'] : time());
            $arr[] = array(
                'timestamp_iso' => $ts_iso,
                'timestamp' => isset($l['timestamp']) ? (int)$l['timestamp'] : 0,
                'ip' => isset($l['ip']) ? $l['ip'] : '',
                'action' => isset($l['action']) ? $l['action'] : '',
                'detail' => isset($l['detail']) ? $l['detail'] : (isset($l['target']) ? $l['target'] : ''),
                'target' => isset($l['target']) ? $l['target'] : '',
                'result' => isset($l['result']) ? (bool)$l['result'] : true,
                'user' => isset($l['user']) ? $l['user'] : 'admin',
            );
        }
        $export_json = json_encode($arr, JSON_UNESCAPED_UNICODE);
        $export_bytes += strlen($export_json);
        echo $export_json;
    } else {
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $chunk = 0;
        foreach ($logs as $l) {
            $ts_iso = isset($l['time']) ? $l['time'] : date('Y-m-d H:i:s', isset($l['timestamp']) ? (int)$l['timestamp'] : time());
            $obj = array(
                'timestamp_iso' => $ts_iso,
                'timestamp' => isset($l['timestamp']) ? (int)$l['timestamp'] : 0,
                'ip' => isset($l['ip']) ? $l['ip'] : '',
                'action' => isset($l['action']) ? $l['action'] : '',
                'detail' => isset($l['detail']) ? $l['detail'] : (isset($l['target']) ? $l['target'] : ''),
                'target' => isset($l['target']) ? $l['target'] : '',
                'result' => isset($l['result']) ? (bool)$l['result'] : true,
                'user' => isset($l['user']) ? $l['user'] : 'admin',
            );
            $line = json_encode($obj, JSON_UNESCAPED_UNICODE) . "\n";
            $export_bytes += strlen($line);
            echo $line;
            $chunk++;
            if ($chunk >= 100) {
                flush();
                usleep(0);
                $chunk = 0;
            }
        }
    }

    flush();
    gojs_monitor_bump_bandwidth(0, $export_bytes);
    exit;
}

function gojs_load_alert_rules(): array {
    global $config;
    return isset($config['alert_rules']) && is_array($config['alert_rules'])
        ? $config['alert_rules']
        : array();
}

function gojs_save_alert_rules(array $rules): void {
    global $config;
    $config['alert_rules'] = $rules;
    gojs_save_config();
}

function gojs_api_alert_rules($method) {
    if ($method === 'GET') {
        gojs_json_response(gojs_load_alert_rules());
    } elseif ($method === 'POST') {
        $body = gojs_get_body();
        $rules = gojs_load_alert_rules();
        $id = 'rule_' . uniqid() . '_' . bin2hex(random_bytes(3));
        $name = isset($body['name']) ? (string)$body['name'] : 'Unnamed Rule';
        $enabled = isset($body['enabled']) ? (bool)$body['enabled'] : true;
        $when_raw = isset($body['when']) && is_array($body['when']) ? $body['when'] : array();
        $then_raw = isset($body['then']) && is_array($body['then']) ? $body['then'] : array();

        $when = array();
        if (isset($when_raw['action_in']) && is_array($when_raw['action_in'])) {
            $when['action_in'] = array_values(array_filter($when_raw['action_in'], 'is_string'));
        }
        if (isset($when_raw['action_not_in']) && is_array($when_raw['action_not_in'])) {
            $when['action_not_in'] = array_values(array_filter($when_raw['action_not_in'], 'is_string'));
        }
        if (isset($when_raw['ip_not_in_whitelist'])) {
            $when['ip_not_in_whitelist'] = (bool)$when_raw['ip_not_in_whitelist'];
        }
        if (!empty($when_raw['outside_hours_range'])) {
            $when['outside_hours_range'] = (string)$when_raw['outside_hours_range'];
        }
        if (isset($when_raw['consecutive_fail_login_gt_N'])) {
            $when['consecutive_fail_login_gt_N'] = (int)$when_raw['consecutive_fail_login_gt_N'];
        }

        $then = array(
            'channel_ids' => isset($then_raw['channel_ids']) && is_array($then_raw['channel_ids'])
                ? array_values(array_filter($then_raw['channel_ids'], 'is_string'))
                : array(),
            'severity' => isset($then_raw['severity']) && in_array($then_raw['severity'], array('info', 'warning', 'critical'), true)
                ? $then_raw['severity']
                : 'warning',
        );

        $rule = array(
            'id' => $id,
            'name' => $name,
            'enabled' => $enabled,
            'when' => $when,
            'then' => $then,
        );

        $rules[] = $rule;
        gojs_save_alert_rules($rules);
        gojs_json_response($rule);
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}

function gojs_api_alert_rule($id, $method) {
    $rules = gojs_load_alert_rules();
    $idx = -1;
    foreach ($rules as $i => $r) {
        if (isset($r['id']) && $r['id'] === $id) {
            $idx = $i;
            break;
        }
    }
    if ($idx < 0) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '规则不存在'), 404);
    }

    if ($method === 'PUT') {
        $body = gojs_get_body();
        $rule = $rules[$idx];
        if (isset($body['name'])) $rule['name'] = (string)$body['name'];
        if (isset($body['enabled'])) $rule['enabled'] = (bool)$body['enabled'];

        if (isset($body['when']) && is_array($body['when'])) {
            $when_raw = $body['when'];
            $when = isset($rule['when']) && is_array($rule['when']) ? $rule['when'] : array();
            if (array_key_exists('action_in', $when_raw)) {
                if (is_array($when_raw['action_in'])) {
                    $when['action_in'] = array_values(array_filter($when_raw['action_in'], 'is_string'));
                } else {
                    unset($when['action_in']);
                }
            }
            if (array_key_exists('action_not_in', $when_raw)) {
                if (is_array($when_raw['action_not_in'])) {
                    $when['action_not_in'] = array_values(array_filter($when_raw['action_not_in'], 'is_string'));
                } else {
                    unset($when['action_not_in']);
                }
            }
            if (array_key_exists('ip_not_in_whitelist', $when_raw)) {
                if ($when_raw['ip_not_in_whitelist'] === null) {
                    unset($when['ip_not_in_whitelist']);
                } else {
                    $when['ip_not_in_whitelist'] = (bool)$when_raw['ip_not_in_whitelist'];
                }
            }
            if (array_key_exists('outside_hours_range', $when_raw)) {
                if ($when_raw['outside_hours_range'] === '' || $when_raw['outside_hours_range'] === null) {
                    unset($when['outside_hours_range']);
                } else {
                    $when['outside_hours_range'] = (string)$when_raw['outside_hours_range'];
                }
            }
            if (array_key_exists('consecutive_fail_login_gt_N', $when_raw)) {
                if ($when_raw['consecutive_fail_login_gt_N'] === null) {
                    unset($when['consecutive_fail_login_gt_N']);
                } else {
                    $when['consecutive_fail_login_gt_N'] = (int)$when_raw['consecutive_fail_login_gt_N'];
                }
            }
            $rule['when'] = $when;
        }

        if (isset($body['then']) && is_array($body['then'])) {
            $then_raw = $body['then'];
            $then = isset($rule['then']) && is_array($rule['then']) ? $rule['then'] : array('channel_ids' => array(), 'severity' => 'warning');
            if (isset($then_raw['channel_ids']) && is_array($then_raw['channel_ids'])) {
                $then['channel_ids'] = array_values(array_filter($then_raw['channel_ids'], 'is_string'));
            }
            if (isset($then_raw['severity']) && in_array($then_raw['severity'], array('info', 'warning', 'critical'), true)) {
                $then['severity'] = $then_raw['severity'];
            }
            $rule['then'] = $then;
        }

        $rules[$idx] = $rule;
        gojs_save_alert_rules($rules);
        gojs_json_response($rule);
    } elseif ($method === 'DELETE') {
        array_splice($rules, $idx, 1);
        gojs_save_alert_rules($rules);
        gojs_json_response(array('ok' => true));
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}

function gojs_api_alert_rule_test($id) {
    $rules = gojs_load_alert_rules();
    $rule = null;
    foreach ($rules as $r) {
        if (isset($r['id']) && $r['id'] === $id) {
            $rule = $r;
            break;
        }
    }
    if ($rule === null) {
        gojs_json_response(null, array('code' => 'not_found', 'message' => '规则不存在'), 404);
    }

    $severity = isset($rule['then']['severity']) ? $rule['then']['severity'] : 'warning';
    $channel_ids = isset($rule['then']['channel_ids']) ? $rule['then']['channel_ids'] : array();
    $rule_name = isset($rule['name']) ? $rule['name'] : 'Unnamed Rule';

    $category = $severity === 'critical' ? 'security' : 'system';
    $title_key_map = array(
        'info' => 'oplog_alert_info',
        'warning' => 'oplog_alert_warning',
        'critical' => 'oplog_alert_critical',
    );
    $title_key = isset($title_key_map[$severity]) ? $title_key_map[$severity] : 'oplog_alert_warning';

    $body_params = array(
        'rule_name' => $rule_name,
    );

    gojs_append_notification(array(
        'category' => $category,
        'severity' => $severity,
        'title_key' => $title_key,
        'body_key' => 'oplog_alert_test_body',
        'body_params' => $body_params,
        'payload' => array(
            'source' => 'alert_rule_test',
            'rule_id' => $id,
            'rule_name' => $rule_name,
            'synthetic' => true,
        ),
    ));

    gojs_append_outbox(array(
        'channel_ids' => $channel_ids,
        'payload' => array(
            'subject' => '[Go.js Alert TEST] ' . $rule_name,
            'body' => "Alert rule test firing:\nRule: " . $rule_name . "\nSeverity: " . $severity . "\nTime: " . date('Y-m-d H:i:s'),
        ),
    ));

    gojs_json_response(array('ok' => true, 'fired' => true));
}

function gojs_alerts_evaluate(string $kind, array $context): array {
    global $config;
    $rules = gojs_load_alert_rules();
    $fired = array();

    $whitelist = isset($config['alert_whitelist_ips']) && is_array($config['alert_whitelist_ips'])
        ? $config['alert_whitelist_ips']
        : array();
    $whitelist_empty = count($whitelist) === 0;

    foreach ($rules as $rule) {
        if (empty($rule['enabled'])) continue;
        $when = isset($rule['when']) && is_array($rule['when']) ? $rule['when'] : array();
        $matches_all = true;

        if ($kind === 'oplog') {
            $action = isset($context['action']) ? $context['action'] : '';
            $ip = isset($context['ip']) ? $context['ip'] : '';
            $ts = isset($context['timestamp']) ? (int)$context['timestamp'] : time();

            if (isset($when['action_in']) && is_array($when['action_in']) && count($when['action_in']) > 0) {
                if (!in_array($action, $when['action_in'], true)) {
                    $matches_all = false;
                }
            }
            if ($matches_all && isset($when['action_not_in']) && is_array($when['action_not_in']) && count($when['action_not_in']) > 0) {
                if (in_array($action, $when['action_not_in'], true)) {
                    $matches_all = false;
                }
            }
            if ($matches_all && !empty($when['ip_not_in_whitelist'])) {
                if ($whitelist_empty) {
                    $matches_all = false;
                } else {
                    if (in_array($ip, $whitelist, true)) {
                        $matches_all = false;
                    }
                }
            }
            if ($matches_all && !empty($when['outside_hours_range'])) {
                $range = $when['outside_hours_range'];
                if (preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $range, $m)) {
                    $start_str = $m[1];
                    $end_str = $m[2];
                    $now_hm = date('H:i', $ts);
                    $now_min = (int)substr($now_hm, 0, 2) * 60 + (int)substr($now_hm, 3, 2);
                    $s_min = (int)substr($start_str, 0, 2) * 60 + (int)substr($start_str, 3, 2);
                    $e_min = (int)substr($end_str, 0, 2) * 60 + (int)substr($end_str, 3, 2);
                    $inside = false;
                    if ($s_min <= $e_min) {
                        $inside = ($now_min >= $s_min && $now_min <= $e_min);
                    } else {
                        $inside = ($now_min >= $s_min || $now_min <= $e_min);
                    }
                    if ($inside) {
                        $matches_all = false;
                    }
                }
            }
        } elseif ($kind === 'auth_fail') {
            $fail_count = isset($context['fail_count']) ? (int)$context['fail_count'] : 0;
            if (isset($when['consecutive_fail_login_gt_N'])) {
                $n = (int)$when['consecutive_fail_login_gt_N'];
                if ($fail_count <= $n) {
                    $matches_all = false;
                }
            } else {
                $matches_all = false;
            }
        } else {
            $matches_all = false;
        }

        if ($matches_all) {
            $then = isset($rule['then']) && is_array($rule['then']) ? $rule['then'] : array();
            $severity = isset($then['severity']) ? $then['severity'] : 'warning';
            $channel_ids = isset($then['channel_ids']) && is_array($then['channel_ids']) ? $then['channel_ids'] : array();
            $rule_name = isset($rule['name']) ? $rule['name'] : 'Unnamed Rule';
            $rule_id = isset($rule['id']) ? $rule['id'] : '';

            $category = $severity === 'critical' ? 'security' : 'system';
            $title_key_map = array(
                'info' => 'oplog_alert_info',
                'warning' => 'oplog_alert_warning',
                'critical' => 'oplog_alert_critical',
            );
            $title_key = isset($title_key_map[$severity]) ? $title_key_map[$severity] : 'oplog_alert_warning';

            $body_params = array(
                'rule_name' => $rule_name,
            );
            if ($kind === 'oplog') {
                $body_params['action'] = isset($context['action']) ? $context['action'] : '';
                $body_params['ip'] = isset($context['ip']) ? $context['ip'] : '';
                $body_params['detail'] = isset($context['detail']) ? $context['detail'] : '';
            } elseif ($kind === 'auth_fail') {
                $body_params['ip'] = isset($context['ip']) ? $context['ip'] : '';
                $body_params['fail_count'] = (string)$fail_count;
            }

            gojs_append_notification(array(
                'category' => $category,
                'severity' => $severity,
                'title_key' => $title_key,
                'body_key' => $kind === 'auth_fail' ? 'oplog_alert_authfail_body' : 'oplog_alert_oplog_body',
                'body_params' => $body_params,
                'payload' => array(
                    'source' => 'alert_rule',
                    'rule_id' => $rule_id,
                    'rule_name' => $rule_name,
                    'kind' => $kind,
                    'context' => $context,
                ),
            ));

            $outbox_body = "Alert fired: " . $rule_name . "\nSeverity: " . $severity . "\nTime: " . date('Y-m-d H:i:s');
            if ($kind === 'oplog') {
                $outbox_body .= "\nAction: " . (isset($context['action']) ? $context['action'] : '') .
                    "\nIP: " . (isset($context['ip']) ? $context['ip'] : '') .
                    "\nDetail: " . (isset($context['detail']) ? $context['detail'] : '');
            } elseif ($kind === 'auth_fail') {
                $outbox_body .= "\nIP: " . (isset($context['ip']) ? $context['ip'] : '') .
                    "\nFailures: " . $fail_count;
            }

            gojs_append_outbox(array(
                'channel_ids' => $channel_ids,
                'payload' => array(
                    'subject' => '[Go.js Alert] ' . $severity . ' - ' . $rule_name,
                    'body' => $outbox_body,
                ),
            ));

            $fired[] = $rule_id;
        }
    }

    return $fired;
}

function gojs_encrypt($data) {
    if (!function_exists('openssl_encrypt')) {
        return base64_encode($data);
    }

    $key = gojs_get_encryption_key();
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);

    return base64_encode($iv . $encrypted);
}

function gojs_decrypt($data) {
    if (!function_exists('openssl_decrypt')) {
        return base64_decode($data);
    }

    $key = gojs_get_encryption_key();
    $raw = base64_decode($data);

    if (strlen($raw) < 16) {
        return false;
    }

    $iv = substr($raw, 0, 16);
    $encrypted = substr($raw, 16);

    return openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
}
