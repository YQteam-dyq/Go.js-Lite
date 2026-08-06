<?php
function gojs_is_protected_path($full_path) {
    $real_path = rtrim(str_replace('\\', '/', realpath($full_path) ?: $full_path), '/');
    $gojs_dir = rtrim(str_replace('\\', '/', CONFIG_DIR), '/');
    $index_file = str_replace('\\', '/', ROOT . '/api.php');
    $panel_root = rtrim(str_replace('\\', '/', realpath(ROOT) ?: ROOT), '/');

    if ($real_path === $gojs_dir || strpos($real_path, $gojs_dir . '/') === 0) {
        return true;
    }

    if ($real_path === $index_file) {
        return true;
    }

    if ($real_path === $panel_root || strpos($real_path, $panel_root . '/.htaccess') !== false) {
        return true;
    }
    if ($real_path === $panel_root . '/index.html' || $real_path === $panel_root . '/favicon.svg') {
        return false;
    }
    if (strpos($real_path, $panel_root . '/assets/') === 0) {
        return false;
    }
    if ($real_path !== $panel_root && strpos($real_path, $panel_root . '/') === 0) {
        $relative = substr($real_path, strlen($panel_root) + 1);
        if (strpos($relative, '.') === 0) {
            return true;
        }
        $protected_suffix = array('api.php', '.htaccess', '.user.ini', 'config.php');
        foreach ($protected_suffix as $sfx) {
            if ($relative === $sfx) {
                return true;
            }
        }
    }

    return false;
}

function gojs_ensure_not_protected($full_path, $action = '操作') {
    if (gojs_is_protected_path($full_path)) {
        gojs_json_response(null, array(
            'code' => 'protected_path',
            'message' => '该文件为 GOJS 系统文件，禁止' . $action,
        ), 403);
    }
}

function gojs_resolve_files_root() {
    global $root_path;

    $ctx_root = gojs_files_root();
    if ($ctx_root !== '') {
        return $ctx_root;
    }
    if (!empty($GLOBALS['files_root'])) {
        return $GLOBALS['files_root'];
    }
    return !empty($root_path) ? $root_path : ROOT;
}

function gojs_safe_path($relative_path) {
    $files_root = gojs_resolve_files_root();

    $relative_path = ltrim($relative_path, '/');
    $relative_path = str_replace('\\', '/', $relative_path);

    $full_path = $files_root . '/' . $relative_path;

    $root_real = rtrim(str_replace('\\', '/', realpath($files_root)), '/');
    if (!$root_real) {
        $root_real = rtrim(str_replace('\\', '/', $files_root), '/');
    }

    $real_path = realpath($full_path);
    if ($real_path) {
        $real_path = str_replace('\\', '/', $real_path);
    }

    if (!$real_path) {
        $parent_dir = dirname($full_path);
        $real_parent = realpath($parent_dir);
        if ($real_parent) {
            $real_parent = str_replace('\\', '/', $real_parent);
        }

        if (!$real_parent ||
            ($real_parent !== $root_real && strpos($real_parent, $root_real . '/') !== 0)) {
            return false;
        }

        $basename = basename($full_path);
        if (strpos($basename, '..') !== false) {
            return false;
        }

        return $real_parent . '/' . $basename;
    }

    $real_path = rtrim($real_path, '/');
    if ($real_path !== $root_real && strpos($real_path, $root_real . '/') !== 0) {
        return false;
    }

    return $real_path;
}

function gojs_relative_path($abs_path) {
    $files_root = gojs_resolve_files_root();

    $root_real = rtrim(realpath($files_root), '/');
    $abs_real = rtrim($abs_path, '/');

    if ($abs_real === $root_real) {
        return '/';
    }

    if (strpos($abs_real, $root_real) === 0) {
        return substr($abs_real, strlen($root_real));
    }

    return $abs_path;
}

function gojs_get_perms($file_path) {
    $perms = @fileperms($file_path);
    if ($perms === false) {
        return '0000';
    }
    return substr(sprintf('%o', $perms), -4);
}

function gojs_get_file_type($file_path) {
    if (is_link($file_path)) {
        return 'link';
    }
    if (is_dir($file_path)) {
        return 'dir';
    }
    return 'file';
}

function gojs_get_file_info($file_path, $relative_path) {
    $stat = @stat($file_path);

    return array(
        'name' => basename($file_path),
        'path' => $relative_path,
        'type' => gojs_get_file_type($file_path),
        'size' => $stat ? $stat['size'] : 0,
        'mtime' => $stat ? $stat['mtime'] : 0,
        'perms' => gojs_get_perms($file_path),
        'readable' => is_readable($file_path),
        'writable' => is_writable($file_path),
    );
}

function gojs_api_files() {
    global $root_path;

    $method = gojs_get_method();

    if ($method === 'GET') {
        $path = gojs_get_param('path', '/');
        $sort = gojs_get_param('sort', 'name');
        $order = gojs_get_param('order', 'asc');

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

        $entries = array();
        $dir_handle = @opendir($safe_path);
        if (!$dir_handle) {
            gojs_json_response(null, array(
                'code' => 'read_dir_failed',
                'message' => '读取目录失败',
            ), 500);
        }

        while (($entry = readdir($dir_handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full_path = $safe_path . '/' . $entry;

            if (gojs_is_protected_path($full_path)) {
                continue;
            }

            $rel = gojs_relative_path($full_path);
            $entries[] = gojs_get_file_info($full_path, $rel);
        }
        closedir($dir_handle);

        usort($entries, function($a, $b) use ($sort, $order) {

            $dir_compare = 0;
            if ($a['type'] === 'dir' && $b['type'] !== 'dir') {
                $dir_compare = -1;
            } elseif ($a['type'] !== 'dir' && $b['type'] === 'dir') {
                $dir_compare = 1;
            }

            if ($dir_compare !== 0) {
                return $dir_compare;
            }

            $cmp = 0;
            switch ($sort) {
                case 'size':
                    $cmp = $a['size'] - $b['size'];
                    break;
                case 'mtime':
                    $cmp = $a['mtime'] - $b['mtime'];
                    break;
                case 'name':
                default:
                    $cmp = strcasecmp($a['name'], $b['name']);
                    break;
            }

            return $order === 'desc' ? -$cmp : $cmp;
        });

        gojs_json_response(array(
            'files' => $entries,
            'path' => $path === '' ? '/' : $path,
        ));
    } elseif ($method === 'POST') {
        $action = gojs_get_param('action', '');

        switch ($action) {
            case 'create_file':
                gojs_api_file_touch();
                break;
            case 'create_dir':
                gojs_api_file_mkdir();
                break;
            case 'delete':
                gojs_api_file_delete();
                break;
            case 'rename':
                gojs_api_file_rename();
                break;
            case 'copy':
                gojs_api_file_copy();
                break;
            case 'chmod':
                gojs_api_file_chmod();
                break;
            default:
                gojs_json_response(null, array(
                    'code' => 'invalid_action',
                    'message' => '无效的操作',
                ), 400);
                break;
        }
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}

function gojs_api_file_content() {
    $method = gojs_get_method();

    if ($method === 'GET') {
        $path = gojs_get_param('path', '');

        $safe_path = gojs_safe_path($path);
        if ($safe_path === false) {
            gojs_json_response(null, array(
                'code' => 'forbidden',
                'message' => '路径访问被拒绝',
            ), 403);
        }

        gojs_ensure_not_protected($safe_path, '读取');

        if (!is_file($safe_path)) {
            gojs_json_response(null, array(
                'code' => 'not_file',
                'message' => '路径不是文件',
            ), 400);
        }

        if (!is_readable($safe_path)) {
            gojs_json_response(null, array(
                'code' => 'not_readable',
                'message' => '文件不可读',
            ), 403);
        }

        $size = filesize($safe_path);
        $max_size = 1024 * 1024; 
        $truncated = false;
        $read_size = $size;

        if ($size > $max_size) {
            $read_size = $max_size;
            $truncated = true;
        }

        $content = @file_get_contents($safe_path, false, null, 0, $read_size);
        if ($content === false) {
            gojs_json_response(null, array(
                'code' => 'read_failed',
                'message' => '读取文件失败',
            ), 500);
        }

        $mime = 'application/octet-stream';
        $type = 'binary';

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $safe_path);
            }
        }

        $is_text = false;
        $is_image = false;

        if ($mime) {
            if (strpos($mime, 'text/') === 0 || $mime === 'application/json' || $mime === 'application/javascript' || $mime === 'application/xml' || $mime === 'application/x-httpd-php') {
                $is_text = true;
            }
            if (strpos($mime, 'image/') === 0) {
                $is_image = true;
            }
        }

        $ext = strtolower(pathinfo($safe_path, PATHINFO_EXTENSION));
        $text_exts = array('txt', 'md', 'html', 'htm', 'css', 'js', 'json', 'xml', 'php', 'py', 'rb', 'java', 'c', 'cpp', 'h', 'sh', 'yml', 'yaml', 'ini', 'conf', 'log', 'csv', 'sql', 'ts', 'tsx', 'jsx', 'vue', 'less', 'scss', 'sass');
        $image_exts = array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico');

        if (!$is_text && in_array($ext, $text_exts)) {
            $is_text = true;
            $mime = 'text/plain';
        }
        if (!$is_image && in_array($ext, $image_exts)) {
            $is_image = true;
        }

        if ($is_text) {
            $type = 'text';
            $encoding = mb_detect_encoding($content, mb_detect_order(), true);
            $lines = $truncated ? null : substr_count($content, "\n") + 1;

            gojs_json_response(array(
                'type' => 'text',
                'content' => $content,
                'size' => $size,
                'mime' => $mime,
                'encoding' => $encoding,
                'lines' => $lines,
                'truncated' => $truncated,
            ));
        } elseif ($is_image) {
            $type = 'image';
            gojs_json_response(array(
                'type' => 'image',
                'content' => base64_encode($content),
                'size' => $size,
                'mime' => $mime,
                'encoding' => 'base64',
                'truncated' => $truncated,
            ));
        } else {
            gojs_json_response(array(
                'type' => 'binary',
                'content' => base64_encode($content),
                'size' => $size,
                'mime' => $mime,
                'encoding' => 'base64',
                'truncated' => $truncated,
            ));
        }
    } elseif ($method === 'PUT') {
        gojs_api_file_save();
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}

function gojs_api_file_save() {
    $path = gojs_get_param('path', '');
    $content = gojs_get_param('content', '');

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '修改');

    if (file_exists($safe_path) && !is_file($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_file',
            'message' => '路径不是文件',
        ), 400);
    }

    if (file_exists($safe_path) && !is_writable($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_writable',
            'message' => '文件不可写',
        ), 403);
    }

    $result = @file_put_contents($safe_path, $content, LOCK_EX);
    if ($result === false) {
        gojs_json_response(null, array(
            'code' => 'write_failed',
            'message' => '写入文件失败',
        ), 500);
    }

    gojs_log_operation('file_save', $path, true);
    gojs_json_response(array('success' => true));
}

function gojs_api_file_mkdir() {
    $path = gojs_get_param('path', '');

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '创建');

    if (file_exists($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'already_exists',
            'message' => '路径已存在',
        ), 400);
    }

    if (!@mkdir($safe_path, 0755, true)) {
        gojs_json_response(null, array(
            'code' => 'mkdir_failed',
            'message' => '创建目录失败',
        ), 500);
    }

    gojs_log_operation('file_mkdir', $path, true);
    $rel = gojs_relative_path($safe_path);
    $info = gojs_get_file_info($safe_path, $rel);
    gojs_json_response($info);
}

function gojs_api_file_touch() {
    $path = gojs_get_param('path', '');

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '创建');

    if (file_exists($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'already_exists',
            'message' => '文件已存在',
        ), 400);
    }

    if (@file_put_contents($safe_path, '') === false) {
        gojs_json_response(null, array(
            'code' => 'create_failed',
            'message' => '创建文件失败',
        ), 500);
    }

    $rel = gojs_relative_path($safe_path);
    $info = gojs_get_file_info($safe_path, $rel);
    gojs_json_response($info);
}

function gojs_recursive_delete($dir) {
    if (!is_dir($dir)) {
        return @unlink($dir);
    }

    $handle = @opendir($dir);
    if (!$handle) {
        return false;
    }

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            if (!gojs_recursive_delete($path)) {
                closedir($handle);
                return false;
            }
        } else {
            if (!@unlink($path)) {
                closedir($handle);
                return false;
            }
        }
    }
    closedir($handle);

    return @rmdir($dir);
}

function gojs_trash_dir() {
    return CONFIG_DIR . '/trash';
}

function gojs_trash_meta_path($trash_id) {
    return gojs_trash_dir() . '/' . $trash_id . '/meta.json';
}

function gojs_copy_recursive($src, $dst) {
    if (is_link($src)) {
        $target = @readlink($src);
        if ($target === false) return false;
        return @symlink($target, $dst);
    }
    if (is_dir($src)) {
        if (!@mkdir($dst, 0755, true)) {
            return false;
        }
        $handle = @opendir($src);
        if (!$handle) return false;
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') continue;
            if (!gojs_copy_recursive($src . '/' . $entry, $dst . '/' . $entry)) {
                closedir($handle);
                return false;
            }
        }
        closedir($handle);
        return true;
    }
    return @copy($src, $dst);
}

function gojs_trash_move($safe_path, $original_path) {
    $trash_dir = gojs_trash_dir();
    if (!is_dir($trash_dir)) {
        if (!@mkdir($trash_dir, 0700, true)) {
            return false;
        }
    }

    $trash_id = uniqid('tr_', true);
    $trash_path = $trash_dir . '/' . $trash_id;
    if (!@mkdir($trash_path, 0700, true)) {
        return false;
    }

    $is_dir = is_dir($safe_path) && !is_link($safe_path);
    if ($is_dir) {
        list(, $size) = gojs_count_files($safe_path, 10);
    } else {
        $size = @filesize($safe_path);
        if ($size === false) $size = 0;
    }

    $meta = array(
        'original_path' => '/' . ltrim($original_path, '/'),
        'type' => $is_dir ? 'dir' : 'file',
        'size' => (int)$size,
        'deleted_at' => time(),
    );
    @file_put_contents($trash_path . '/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $target = $trash_path . '/data';
    if (@rename($safe_path, $target)) {
        return true;
    }

    
    if (gojs_copy_recursive($safe_path, $target)) {
        if ($is_dir) {
            gojs_recursive_delete($safe_path);
        } else {
            @unlink($safe_path);
        }
        return true;
    }

    gojs_recursive_delete($trash_path);
    return false;
}

function gojs_api_trash_list() {
    global $config;

    $items = array();
    $total_size = 0;

    if (is_dir(gojs_trash_dir())) {
        $handle = @opendir(gojs_trash_dir());
        if ($handle) {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') continue;
                $meta_file = gojs_trash_meta_path($entry);
                if (!is_file($meta_file)) continue;
                $meta = json_decode(@file_get_contents($meta_file), true);
                if (!is_array($meta)) continue;
                $size = isset($meta['size']) ? (int)$meta['size'] : 0;
                $items[] = array(
                    'id' => $entry,
                    'orig_path' => isset($meta['original_path']) ? $meta['original_path'] : '',
                    'type' => (isset($meta['type']) && $meta['type'] === 'dir') ? 'dir' : 'file',
                    'size' => $size,
                    'deleted_at' => isset($meta['deleted_at']) ? (int)$meta['deleted_at'] : 0,
                );
                $total_size += $size;
            }
            closedir($handle);
        }
    }

    
    usort($items, function ($a, $b) {
        return $b['deleted_at'] - $a['deleted_at'];
    });

    gojs_json_response(array(
        'items' => $items,
        'total_size' => $total_size,
        'enabled' => !isset($config['trash_enabled']) ? true : (bool)$config['trash_enabled'],
    ));
}

function gojs_api_trash_restore() {
    $body = gojs_get_body();
    $id = isset($body['id']) ? trim((string)$body['id']) : '';
    if ($id === '' || preg_match('/[^A-Za-z0-9_.-]/', $id)) {
        gojs_json_response(null, array(
            'code' => 'invalid_trash_id',
            'message' => '无效的回收站条目',
        ), 400);
    }

    $trash_path = gojs_trash_dir() . '/' . $id;
    $meta_file = gojs_trash_meta_path($id);
    if (!is_dir($trash_path) || !is_file($meta_file)) {
        gojs_json_response(null, array(
            'code' => 'trash_not_found',
            'message' => '回收站条目不存在',
        ), 404);
    }

    $meta = json_decode(@file_get_contents($meta_file), true);
    if (!is_array($meta) || empty($meta['original_path'])) {
        gojs_json_response(null, array(
            'code' => 'trash_meta_invalid',
            'message' => '回收站条目数据损坏',
        ), 400);
    }

    $original_path = (string)$meta['original_path'];
    $safe_path = gojs_safe_path($original_path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    if (file_exists($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'restore_conflict',
            'message' => '目标位置已存在文件',
        ), 400);
    }

    $src = $trash_path . '/data';
    if (!file_exists($src)) {
        gojs_json_response(null, array(
            'code' => 'trash_not_found',
            'message' => '回收站条目不存在',
        ), 404);
    }

    
    $parent = dirname($safe_path);
    if (!is_dir($parent)) {
        if (!@mkdir($parent, 0755, true)) {
            gojs_json_response(null, array(
                'code' => 'restore_failed',
                'message' => '恢复失败',
            ), 500);
        }
    }

    if (!@rename($src, $safe_path)) {
        gojs_json_response(null, array(
            'code' => 'restore_failed',
            'message' => '恢复失败',
        ), 500);
    }

    gojs_recursive_delete($trash_path);
    gojs_log_operation('trash_restore', $original_path, true);
    gojs_json_response(array('success' => true));
}

function gojs_api_trash_purge() {
    $body = gojs_get_body();
    $id = isset($body['id']) ? trim((string)$body['id']) : '';
    $trash_dir = gojs_trash_dir();

    if ($id !== '') {
        if (preg_match('/[^A-Za-z0-9_.-]/', $id)) {
            gojs_json_response(null, array(
                'code' => 'invalid_trash_id',
                'message' => '无效的回收站条目',
            ), 400);
        }
        $trash_path = $trash_dir . '/' . $id;
        if (!is_dir($trash_path)) {
            gojs_json_response(null, array(
                'code' => 'trash_not_found',
                'message' => '回收站条目不存在',
            ), 404);
        }
        if (!gojs_recursive_delete($trash_path)) {
            gojs_json_response(null, array(
                'code' => 'purge_failed',
                'message' => '永久删除失败',
            ), 500);
        }
        gojs_log_operation('trash_purge', $id, true);
        gojs_json_response(array('success' => true));
    }

    
    $purged = 0;
    if (is_dir($trash_dir)) {
        $handle = @opendir($trash_dir);
        if ($handle) {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') continue;
                $p = $trash_dir . '/' . $entry;
                if (is_dir($p)) {
                    if (gojs_recursive_delete($p)) $purged++;
                } elseif (is_file($p)) {
                    if (@unlink($p)) $purged++;
                }
            }
            closedir($handle);
        }
    }
    gojs_log_operation('trash_purge', 'all', true);
    gojs_json_response(array('success' => true, 'purged' => $purged));
}

function gojs_api_trash_config() {
    global $config;
    $method = gojs_get_method();

    if ($method === 'GET') {
        gojs_json_response(array(
            'enabled' => !isset($config['trash_enabled']) ? true : (bool)$config['trash_enabled'],
        ));
    }

    if ($method === 'POST') {
        $body = gojs_get_body();
        $enabled = !empty($body['enabled']);
        $config['trash_enabled'] = $enabled;
        gojs_save_config();
        gojs_json_response(array('enabled' => $enabled));
    }

    gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
}

function gojs_api_file_delete() {
    $path = gojs_get_param('path', '');
    $recursive = gojs_get_param('recursive', false);

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '删除');

    if (!file_exists($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '路径不存在',
        ), 404);
    }

    global $root_path;
    $root_real = rtrim(realpath($root_path), '/');
    if (rtrim($safe_path, '/') === $root_real) {
        gojs_json_response(null, array(
            'code' => 'cannot_delete_root',
            'message' => '不能删除根目录',
        ), 400);
    }

    
    global $config;
    $trash_enabled = !isset($config['trash_enabled']) ? true : (bool)$config['trash_enabled'];
    if ($trash_enabled) {
        if (gojs_trash_move($safe_path, $path)) {
            gojs_log_operation('file_delete', $path, true, 'moved_to_trash');
            gojs_json_response(array('success' => true, 'trashed' => true));
        }
        gojs_json_response(null, array(
            'code' => 'trash_move_failed',
            'message' => '移入回收站失败',
        ), 500);
    }

    if (is_dir($safe_path) && !is_link($safe_path)) {
        if (!$recursive) {

            $handle = @opendir($safe_path);
            $empty = true;
            if ($handle) {
                while (($entry = readdir($handle)) !== false) {
                    if ($entry !== '.' && $entry !== '..') {
                        $empty = false;
                        break;
                    }
                }
                closedir($handle);
            }
            if (!$empty) {
                gojs_json_response(null, array(
                    'code' => 'dir_not_empty',
                    'message' => '目录不为空，请使用递归删除',
                ), 400);
            }
        }

        if (!gojs_recursive_delete($safe_path)) {
            gojs_json_response(null, array(
                'code' => 'delete_failed',
                'message' => '删除失败',
            ), 500);
        }
    } else {
        if (!@unlink($safe_path)) {
            gojs_json_response(null, array(
                'code' => 'delete_failed',
                'message' => '删除失败',
            ), 500);
        }
    }

    gojs_log_operation('file_delete', $path, true);
    gojs_json_response(array('success' => true));
}

function gojs_api_file_rename() {
    global $root_path;

    $path = gojs_get_param('path', '');
    $target = gojs_get_param('target', '');

    if (!$target) {
        gojs_json_response(null, array(
            'code' => 'invalid_target',
            'message' => '目标名称不能为空',
        ), 400);
    }

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '重命名');

    $target_has_sep = (strpos($target, '/') !== false || strpos($target, '\\') !== false);
    if ($target_has_sep) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '暂不支持跨目录重命名，请使用同目录内名称',
        ), 403);
    }

    if (strpos($target, '..') !== false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标名称包含非法字符',
        ), 403);
    }

    $files_root = gojs_resolve_files_root();
    $root_real = rtrim(realpath($files_root) ?: $files_root, '/');

    $parent_dir = dirname($safe_path);
    $safe_target = $parent_dir . '/' . basename($target);

    $real_parent = realpath($parent_dir);
    if (!$real_parent || strpos($real_parent, $root_real) !== 0) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标路径访问被拒绝',
        ), 403);
    }

    $target_base = basename($safe_target);
    $safe_target_final = $real_parent . '/' . $target_base;

    gojs_ensure_not_protected($safe_target_final, '重命名到');

    if (file_exists($safe_target_final)) {
        gojs_json_response(null, array(
            'code' => 'already_exists',
            'message' => '目标路径已存在',
        ), 400);
    }

    if (!@rename($safe_path, $safe_target_final)) {
        gojs_json_response(null, array(
            'code' => 'rename_failed',
            'message' => '重命名失败',
        ), 500);
    }

    gojs_log_operation('file_rename', $path . ' → ' . $target, true);
    $rel = gojs_relative_path($safe_target_final);
    $info = gojs_get_file_info($safe_target_final, $rel);
    gojs_json_response($info);
}

function gojs_recursive_copy($src, $dst) {
    if (is_file($src)) {
        return @copy($src, $dst);
    }

    if (!is_dir($dst)) {
        if (!@mkdir($dst, 0755, true)) {
            return false;
        }
    }

    $handle = @opendir($src);
    if (!$handle) {
        return false;
    }

    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $src_path = $src . '/' . $entry;
        $dst_path = $dst . '/' . $entry;

        if (is_dir($src_path) && !is_link($src_path)) {
            if (!gojs_recursive_copy($src_path, $dst_path)) {
                closedir($handle);
                return false;
            }
        } else {
            if (!@copy($src_path, $dst_path)) {
                closedir($handle);
                return false;
            }
        }
    }
    closedir($handle);

    return true;
}

function gojs_api_file_copy() {
    $path = gojs_get_param('path', '');
    $target = gojs_get_param('target', '');

    if (!$target) {
        gojs_json_response(null, array(
            'code' => 'invalid_target',
            'message' => '目标路径不能为空',
        ), 400);
    }

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '复制');

    $safe_target = gojs_safe_path($target);
    if ($safe_target === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_target, '复制到');

    if (file_exists($safe_target)) {
        gojs_json_response(null, array(
            'code' => 'already_exists',
            'message' => '目标路径已存在',
        ), 400);
    }

    if (!gojs_recursive_copy($safe_path, $safe_target)) {
        gojs_json_response(null, array(
            'code' => 'copy_failed',
            'message' => '复制失败',
        ), 500);
    }

    gojs_json_response(array('success' => true));
}

function gojs_api_file_zip() {
    if (!class_exists('ZipArchive')) {
        gojs_json_response(null, array(
            'code' => 'not_supported',
            'message' => '服务器不支持 Zip 扩展',
        ), 400);
    }

    $paths = gojs_get_param('paths', array());
    $target = gojs_get_param('target', '');

    if (empty($paths)) {
        gojs_json_response(null, array(
            'code' => 'invalid_paths',
            'message' => '请选择要压缩的文件',
        ), 400);
    }

    $safe_target = gojs_safe_path($target);
    if ($safe_target === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_target, '压缩到');

    if (file_exists($safe_target)) {
        gojs_json_response(null, array(
            'code' => 'already_exists',
            'message' => '目标文件已存在',
        ), 400);
    }

    $zip = new ZipArchive();
    if ($zip->open($safe_target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        gojs_json_response(null, array(
            'code' => 'zip_create_failed',
            'message' => '创建压缩包失败',
        ), 500);
    }

    foreach ($paths as $path) {
        $safe_path = gojs_safe_path($path);
        if ($safe_path === false) {
            continue;
        }
        if (gojs_is_protected_path($safe_path)) {
            continue;
        }
        if (!file_exists($safe_path)) {
            continue;
        }
        $base_name = basename($safe_path);
        if (is_dir($safe_path)) {
            gojs_add_dir_to_zip($zip, $safe_path, $base_name);
        } else {
            $zip->addFile($safe_path, $base_name);
        }
    }

    $zip->close();

    gojs_log_operation('file_compress', $target, true, 'zip');
    gojs_json_response(array('success' => true, 'target' => $target));
}

function gojs_add_dir_to_zip($zip, $dir, $zip_path) {
    $dir = rtrim($dir, '/') . '/';
    $zip_path = rtrim($zip_path, '/') . '/';
    $handle = opendir($dir);
    if (!$handle) return;
    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $dir . $entry;
        $zpath = $zip_path . $entry;
        if (gojs_is_protected_path($full)) continue;
        if (is_dir($full)) {
            gojs_add_dir_to_zip($zip, $full, $zpath);
        } else {
            $zip->addFile($full, $zpath);
        }
    }
    closedir($handle);
}

function gojs_api_file_unzip() {
    if (!class_exists('ZipArchive')) {
        gojs_json_response(null, array(
            'code' => 'not_supported',
            'message' => '服务器不支持 Zip 扩展',
        ), 400);
    }

    $path = gojs_get_param('path', '');
    $target = gojs_get_param('target', '');

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '解压');

    if (!is_file($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_file',
            'message' => '不是文件',
        ), 400);
    }

    $safe_target = gojs_safe_path($target);
    if ($safe_target === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_target, '解压到');

    if (!is_dir($safe_target)) {
        if (!@mkdir($safe_target, 0755, true)) {
            gojs_json_response(null, array(
                'code' => 'create_dir_failed',
                'message' => '创建目标目录失败',
            ), 500);
        }
    }

    $zip = new ZipArchive();
    if ($zip->open($safe_path) !== true) {
        gojs_json_response(null, array(
            'code' => 'zip_open_failed',
            'message' => '打开压缩包失败',
        ), 500);
    }

    $count = $zip->numFiles;
    $extracted = 0;

    for ($i = 0; $i < $count; $i++) {
        $name = $zip->getNameIndex($i);
        $full_path = $safe_target . '/' . $name;
        $real_target = realpath($safe_target);
        $real_full = realpath(dirname($full_path));
        if ($real_target === false || $real_full === false) continue;
        if (strpos($real_full, rtrim($real_target, '/')) !== 0) continue;
        if (gojs_is_protected_path($full_path)) continue;
        if ($zip->extractTo($safe_target, array($name))) {
            $extracted++;
        }
    }

    $zip->close();

    gojs_log_operation('file_extract', $path, true, 'zip, extracted: ' . $extracted);
    gojs_json_response(array('success' => true, 'extracted' => $extracted));
}

function gojs_api_file_targz() {
    if (!class_exists('PharData')) {
        gojs_json_response(null, array(
            'code' => 'not_supported',
            'message' => '服务器不支持 PharData 扩展',
        ), 404);
    }

    
if (ini_get('phar.readonly')) {
        gojs_json_response(null, array(
            'code' => 'phar_readonly',
            'message' => '服务器 phar.readonly 已启用，无法创建 tar.gz 压缩包。请在 php.ini 或 .htaccess 中设置 phar.readonly=0',
        ), 500);
    }

    $paths = gojs_get_param('paths', array());
    $target = gojs_get_param('target', '');

    if (empty($paths)) {
        gojs_json_response(null, array(
            'code' => 'invalid_paths',
            'message' => '请选择要压缩的文件',
        ), 400);
    }

    $safe_target = gojs_safe_path($target);
    if ($safe_target === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_target, '压缩到');

    if (file_exists($safe_target)) {
        gojs_json_response(null, array(
            'code' => 'already_exists',
            'message' => '目标文件已存在',
        ), 400);
    }

    

if (preg_match('/\.tar\.gz$/i', $safe_target)) {
        $tar_target = substr($safe_target, 0, -7) . '.tar';
    } elseif (preg_match('/\.tgz$/i', $safe_target)) {
        $tar_target = substr($safe_target, 0, -4) . '.tar';
    } else {
        $tar_target = $safe_target . '.tar';
    }

    if (file_exists($tar_target)) {
        gojs_json_response(null, array(
            'code' => 'already_exists',
            'message' => '临时 tar 文件已存在',
        ), 400);
    }

    try {
        $phar = new PharData($tar_target);
    } catch (Exception $e) {
        gojs_json_response(null, array(
            'code' => 'targz_create_failed',
            'message' => '创建 tar 失败: ' . $e->getMessage(),
        ), 500);
    }

    foreach ($paths as $path) {
        $safe_path = gojs_safe_path($path);
        if ($safe_path === false) {
            continue;
        }
        if (gojs_is_protected_path($safe_path)) {
            continue;
        }
        if (!file_exists($safe_path)) {
            continue;
        }
        $base_name = basename($safe_path);
        if (is_dir($safe_path)) {
            gojs_add_dir_to_tar($phar, $safe_path, $base_name);
        } else {
            $phar->addFile($safe_path, $base_name);
        }
    }

    
try {
        $phar->compress(Phar::GZ);
    } catch (Exception $e) {
        unset($phar);
        if (file_exists($tar_target)) {
            @unlink($tar_target);
        }
        gojs_json_response(null, array(
            'code' => 'targz_compress_failed',
            'message' => '压缩为 tar.gz 失败: ' . $e->getMessage(),
        ), 500);
    }

    

unset($phar);
    if (file_exists($tar_target)) {
        @unlink($tar_target);
    }

    gojs_log_operation('file_compress', $target, true, 'tar.gz');
    gojs_json_response(array('success' => true, 'target' => $target));
}

function gojs_add_dir_to_tar($phar, $dir, $tar_path) {
    $dir = rtrim($dir, '/') . '/';
    $tar_path = rtrim($tar_path, '/') . '/';
    $handle = opendir($dir);
    if (!$handle) return;
    while (($entry = readdir($handle)) !== false) {
        if ($entry === '.' || $entry === '..') continue;
        $full = $dir . $entry;
        $tpath = $tar_path . $entry;
        if (gojs_is_protected_path($full)) continue;
        if (is_dir($full)) {
            $phar->addEmptyDir($tpath);
            gojs_add_dir_to_tar($phar, $full, $tpath);
        } else {
            $phar->addFile($full, $tpath);
        }
    }
    closedir($handle);
}

function gojs_api_file_untargz() {
    if (!class_exists('PharData')) {
        gojs_json_response(null, array(
            'code' => 'not_supported',
            'message' => '服务器不支持 PharData 扩展',
        ), 404);
    }

    $path = gojs_get_param('path', '');
    $target = gojs_get_param('target', '');

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '解压');

    if (!is_file($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_file',
            'message' => '不是文件',
        ), 400);
    }

    $safe_target = gojs_safe_path($target);
    if ($safe_target === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_target, '解压到');

    if (!is_dir($safe_target)) {
        if (!@mkdir($safe_target, 0755, true)) {
            gojs_json_response(null, array(
                'code' => 'create_dir_failed',
                'message' => '创建目标目录失败',
            ), 500);
        }
    }

    $count = 0;
    $extracted = 0;
    try {
        $phar = new PharData($safe_path);
        $count = $phar->count();

        $base_phar_path = str_replace('\\', '/', realpath($safe_path));
        $prefix = 'phar:

        foreach (new RecursiveIteratorIterator($phar) as $file) {
            $full_name = str_replace('\\', '/', $file->getPathname());
            if (strpos($full_name, $prefix) !== 0) {
                continue;
            }
            $name = substr($full_name, strlen($prefix));
            if ($name === '' || substr($name, -1) === '/') {
                continue;
            }

            $full_path = $safe_target . '/' . $name;
            $real_target = realpath($safe_target);
            $real_full = realpath(dirname($full_path));
            if ($real_target === false || $real_full === false) continue;
            if (strpos($real_full, rtrim($real_target, '/')) !== 0) continue;
            if (gojs_is_protected_path($full_path)) continue;

            if ($phar->extractTo($safe_target, $name, true)) {
                $extracted++;
            }
        }
    } catch (Exception $e) {
        gojs_json_response(null, array(
            'code' => 'untargz_failed',
            'message' => '解压 tar.gz 失败: ' . $e->getMessage(),
        ), 500);
    }

    gojs_log_operation('file_extract', $path, true, 'tar.gz, extracted: ' . $extracted);
    gojs_json_response(array('success' => true, 'extracted' => $extracted));
}

function gojs_api_file_chmod() {
    $path = gojs_get_param('path', '');
    $perms = gojs_get_param('perms', '');

    if (!$perms) {
        gojs_json_response(null, array(
            'code' => 'invalid_perms',
            'message' => '权限值不能为空',
        ), 400);
    }

    $mode = octdec($perms);
    if ($mode <= 0 || $mode > 07777) {
        gojs_json_response(null, array(
            'code' => 'invalid_perms',
            'message' => '权限值无效',
        ), 400);
    }

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '修改权限');

    if (!file_exists($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_found',
            'message' => '路径不存在',
        ), 404);
    }

    if (!@chmod($safe_path, $mode)) {
        gojs_json_response(null, array(
            'code' => 'chmod_failed',
            'message' => '修改权限失败',
        ), 500);
    }

    gojs_log_operation('file_chmod', $path . ' → ' . $perms, true);
    gojs_json_response(array('success' => true));
}

function gojs_upload_error_message($error_code) {
    $messages = array(
        UPLOAD_ERR_INI_SIZE   => '文件过大（超过 php.ini 的 upload_max_filesize 限制）',
        UPLOAD_ERR_FORM_SIZE  => '文件过大（超过表单限制）',
        UPLOAD_ERR_PARTIAL    => '文件仅部分上传',
        UPLOAD_ERR_NO_FILE    => '没有文件被上传',
        UPLOAD_ERR_NO_TMP_DIR => '缺少临时目录',
        UPLOAD_ERR_CANT_WRITE => '写入磁盘失败',
        UPLOAD_ERR_EXTENSION  => 'PHP 扩展阻止了上传',
    );
    return isset($messages[$error_code]) ? $messages[$error_code] : '上传失败（未知错误）';
}

function gojs_normalize_files_array($files) {
    $result = array();
    if (!isset($files['name'])) {
        return $result;
    }

    if (is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($files['name'][$i] === '') {
                continue;
            }
            $result[] = array(
                'name'     => $files['name'][$i],
                'type'     => isset($files['type'][$i]) ? $files['type'][$i] : '',
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            );
        }
    } else {
        if ($files['name'] === '') {
            return $result;
        }
        $result[] = array(
            'name'     => $files['name'],
            'type'     => isset($files['type']) ? $files['type'] : '',
            'tmp_name' => $files['tmp_name'],
            'error'    => $files['error'],
            'size'     => $files['size'],
        );
    }

    return $result;
}

function gojs_unique_path($path) {
    if (!file_exists($path)) {
        return $path;
    }

    $dir = dirname($path);
    $basename = basename($path);
    $dot = strrpos($basename, '.');
    if ($dot === false) {
        $name = $basename;
        $ext = '';
    } else {
        $name = substr($basename, 0, $dot);
        $ext = substr($basename, $dot);
    }

    $counter = 1;
    while (file_exists($dir . '/' . $name . ' (' . $counter . ')' . $ext)) {
        $counter++;
    }

    return $dir . '/' . $name . ' (' . $counter . ')' . $ext;
}

function gojs_is_dangerous_filename($name) {
    $blacklist = array('php', 'phtml', 'php3', 'php4', 'php5', 'phar', 'php7', 'phps', 'php-s', 'phpt', 'inc');
    $parts = explode('.', $name);
    foreach ($parts as $part) {
        $ext = strtolower($part);
        if (in_array($ext, $blacklist, true)) {
            return true;
        }
    }
    return false;
}

function gojs_validate_upload_filename($name) {
    if ($name === '' || $name === '.' || $name === '..') {
        return false;
    }
    if (strpos($name, '/') !== false || strpos($name, '\\') !== false) {
        return false;
    }
    $len = strlen($name);
    for ($i = 0; $i < $len; $i++) {
        $ascii = ord($name[$i]);
        if ($ascii <= 31) {
            return false;
        }
    }
    if (strpos($name, ':') !== false || strpos($name, '<') !== false || strpos($name, '>') !== false) {
        return false;
    }
    if (strpos($name, '|') !== false || strpos($name, '?') !== false || strpos($name, '*') !== false) {
        return false;
    }
    if (strpos($name, '"') !== false || strpos($name, "'") !== false || strpos($name, '`') !== false) {
        return false;
    }
    if (gojs_is_dangerous_filename($name)) {
        return false;
    }
    return true;
}

function gojs_detect_php_magic($file_path, $filename) {
    $safe_exts = array('txt', 'sql', 'md', 'html');
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, $safe_exts, true)) {
        return false;
    }
    $header = @file_get_contents($file_path, false, null, 0, 4096);
    if ($header === false || $header === '') {
        return false;
    }
    $patterns = array(
        '/^\s*<\?php/i',
        '/^\s*<%/i',
        '/^\s*<script\s/i',
        '/^\s*<script>/i',
    );
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $header)) {
            return true;
        }
    }
    return false;
}

function gojs_api_upload() {
    $target = isset($_POST['target']) ? $_POST['target'] : '/';
    if ($target === '') {
        $target = '/';
    }

    $safe_target = gojs_safe_path($target);
    if ($safe_target === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标路径访问被拒绝',
        ), 403);
    }

    if (!is_dir($safe_target)) {
        gojs_json_response(null, array(
            'code' => 'not_directory',
            'message' => '目标路径不是目录',
        ), 400);
    }

    gojs_ensure_not_protected($safe_target, '上传到');

    if (!is_writable($safe_target)) {
        gojs_json_response(null, array(
            'code' => 'not_writable',
            'message' => '目标目录不可写',
        ), 403);
    }

    $files = array();
    if (isset($_FILES['files'])) {
        $files = gojs_normalize_files_array($_FILES['files']);
    } elseif (isset($_FILES['file'])) {
        $files = gojs_normalize_files_array($_FILES['file']);
    }

    if (empty($files)) {
        gojs_json_response(null, array(
            'code' => 'no_files',
            'message' => '没有上传文件',
        ), 400);
    }

    $capabilities = gojs_get_capabilities();
    $max_upload = $capabilities['maxUpload'];
    $disk_free = @disk_free_space($safe_target);

    $results = array();
    $errors = array();
    $uploaded_bytes = 0;

    foreach ($files as $file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = array(
                'name' => $file['name'],
                'error' => gojs_upload_error_message($file['error']),
            );
            continue;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = array(
                'name' => $file['name'],
                'error' => '无效的上传文件',
            );
            continue;
        }

        $basename = basename($file['name']);
        if (!gojs_validate_upload_filename($basename)) {
            $errors[] = array(
                'name' => $file['name'],
                'error' => '文件名无效',
            );
            continue;
        }

        if ($max_upload > 0 && $file['size'] > $max_upload) {
            $errors[] = array(
                'name' => $file['name'],
                'error' => '文件过大（超过 upload_max_filesize 限制）',
            );
            continue;
        }

        if ($disk_free !== false && $file['size'] > $disk_free) {
            $errors[] = array(
                'name' => $file['name'],
                'error' => '磁盘空间不足',
            );
            continue;
        }

        $final_path = $safe_target . '/' . $basename;

        gojs_ensure_not_protected($final_path, '上传到');

        if (file_exists($final_path)) {
            $final_path = gojs_unique_path($final_path);
        }

        if (!move_uploaded_file($file['tmp_name'], $final_path)) {
            $errors[] = array(
                'name' => $file['name'],
                'error' => '移动文件失败',
            );
            continue;
        }

        if (gojs_detect_php_magic($final_path, $basename)) {
            @unlink($final_path);
            $errors[] = array(
                'name' => $file['name'],
                'error' => '文件内容疑似脚本伪装，已拒绝',
            );
            continue;
        }

        @chmod($final_path, 0644);
        $uploaded_bytes += (int)$file['size'];

        $results[] = array(
            'name' => basename($final_path),
            'size' => @filesize($final_path),
        );
    }

    if (empty($results) && !empty($errors)) {
        gojs_json_response(null, array(
            'code' => 'upload_failed',
            'message' => $errors[0]['error'],
            'errors' => $errors,
        ), 400);
    }

    $uploaded_names = array();
    foreach ($results as $r) {
        $uploaded_names[] = isset($r['name']) ? $r['name'] : '';
    }
    gojs_log_operation('file_upload', implode(', ', $uploaded_names), true);

    
    gojs_monitor_bump_bandwidth($uploaded_bytes, 0);

    gojs_json_response(array(
        'success' => true,
        'files' => $results,
        'errors' => $errors,
    ));
}

function gojs_api_upload_chunk() {
    $body = gojs_get_body();

    $chunk = isset($body['chunk']) ? $body['chunk'] : '';
    $chunk_index = isset($body['chunkIndex']) ? (int)$body['chunkIndex'] : -1;
    $total_chunks = isset($body['totalChunks']) ? (int)$body['totalChunks'] : 0;
    $file_name = isset($body['fileName']) ? (string)$body['fileName'] : '';
    $target = isset($body['target']) ? (string)$body['target'] : '/';
    $upload_id = isset($body['uploadId']) ? (string)$body['uploadId'] : '';

    if ($target === '') {
        $target = '/';
    }

    if ($chunk === '' || $chunk_index < 0 || $total_chunks <= 0 || $file_name === '' || $upload_id === '') {
        gojs_json_response(null, array(
            'code' => 'invalid_params',
            'message' => '上传参数无效',
        ), 400);
    }

    if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/', $upload_id)) {
        gojs_json_response(null, array(
            'code' => 'invalid_upload_id',
            'message' => '上传 ID 无效',
        ), 400);
    }

    if (!gojs_validate_upload_filename($file_name)) {
        gojs_json_response(null, array(
            'code' => 'invalid_filename',
            'message' => '文件名无效',
        ), 400);
    }

    if ($chunk_index >= $total_chunks) {
        gojs_json_response(null, array(
            'code' => 'invalid_chunk_index',
            'message' => '分片索引越界',
        ), 400);
    }

    $chunk_data = base64_decode($chunk, true);
    if ($chunk_data === false) {
        gojs_json_response(null, array(
            'code' => 'invalid_chunk',
            'message' => '分片数据解码失败',
        ), 400);
    }

    $safe_target = gojs_safe_path($target);
    if ($safe_target === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '目标路径访问被拒绝',
        ), 403);
    }

    if (!is_dir($safe_target)) {
        gojs_json_response(null, array(
            'code' => 'not_directory',
            'message' => '目标路径不是目录',
        ), 400);
    }

    gojs_ensure_not_protected($safe_target, '上传到');

    if (!is_writable($safe_target)) {
        gojs_json_response(null, array(
            'code' => 'not_writable',
            'message' => '目标目录不可写',
        ), 403);
    }

    $tmp_base = CONFIG_DIR . '/tmp';
    if (!is_dir($tmp_base)) {
        if (!@mkdir($tmp_base, 0700, true)) {
            gojs_json_response(null, array(
                'code' => 'create_tmp_dir_failed',
                'message' => '创建临时目录失败',
            ), 500);
        }
    }

    $tmp_dir = $tmp_base . '/' . $upload_id;
    if (!is_dir($tmp_dir)) {
        if (!@mkdir($tmp_dir, 0700, true)) {
            gojs_json_response(null, array(
                'code' => 'create_tmp_dir_failed',
                'message' => '创建临时目录失败',
            ), 500);
        }
    }

    $chunk_file = $tmp_dir . '/chunk_' . sprintf('%08d', $chunk_index);
    if (@file_put_contents($chunk_file, $chunk_data, LOCK_EX) === false) {
        gojs_json_response(null, array(
            'code' => 'write_chunk_failed',
            'message' => '写入分片失败',
        ), 500);
    }

    
    gojs_monitor_bump_bandwidth(strlen($chunk_data), 0);

    $received = 0;
    for ($i = 0; $i < $total_chunks; $i++) {
        if (is_file($tmp_dir . '/chunk_' . sprintf('%08d', $i))) {
            $received++;
        }
    }

    $merged = false;
    $final_name = basename($file_name);

    if ($received === $total_chunks) {
        $final_path = $safe_target . '/' . $final_name;
        gojs_ensure_not_protected($final_path, '上传到');

        if (file_exists($final_path)) {
            $final_path = gojs_unique_path($final_path);
        }

        $total_size = 0;
        for ($i = 0; $i < $total_chunks; $i++) {
            $fsize = @filesize($tmp_dir . '/chunk_' . sprintf('%08d', $i));
            $total_size += $fsize ? $fsize : 0;
        }

        $disk_free = @disk_free_space($safe_target);
        if ($disk_free !== false && $total_size > $disk_free) {
            gojs_recursive_delete($tmp_dir);
            gojs_json_response(null, array(
                'code' => 'disk_full',
                'message' => '磁盘空间不足',
            ), 500);
        }

        $out = @fopen($final_path, 'wb');
        if (!$out) {
            gojs_recursive_delete($tmp_dir);
            gojs_json_response(null, array(
                'code' => 'merge_failed',
                'message' => '合并文件失败',
            ), 500);
        }

        $merge_ok = true;
        for ($i = 0; $i < $total_chunks; $i++) {
            $in = @fopen($tmp_dir . '/chunk_' . sprintf('%08d', $i), 'rb');
            if (!$in) {
                $merge_ok = false;
                break;
            }
            while (!feof($in)) {
                $buf = fread($in, 65536);
                if ($buf === false) {
                    break;
                }
                if (fwrite($out, $buf) === false) {
                    $merge_ok = false;
                    break;
                }
            }
            fclose($in);
            if (!$merge_ok) {
                break;
            }
        }
        fclose($out);

        if (!$merge_ok) {
            @unlink($final_path);
            gojs_recursive_delete($tmp_dir);
            gojs_json_response(null, array(
                'code' => 'merge_failed',
                'message' => '合并文件失败',
            ), 500);
        }

        if (gojs_detect_php_magic($final_path, $final_name)) {
            @unlink($final_path);
            gojs_recursive_delete($tmp_dir);
            gojs_json_response(null, array(
                'code' => 'php_magic_detected',
                'message' => '文件内容疑似脚本伪装，已拒绝',
            ), 400);
        }

        @chmod($final_path, 0644);
        gojs_recursive_delete($tmp_dir);

        $merged = true;
    }

    gojs_json_response(array(
        'success' => true,
        'merged' => $merged,
        'progress' => $received . '/' . $total_chunks,
        'received' => $received,
        'totalChunks' => $total_chunks,
    ));
}

function gojs_api_file_search() {
    $path = gojs_get_param('path', '/');
    $q = gojs_get_param('q', '');

    if (!$q) {
        gojs_json_response(array(
            'files' => array(),
            'total' => 0,
        ));
        return;
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

    $results = array();
    $max_results = 100;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($safe_path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        if (count($results) >= $max_results) {
            break;
        }

        $name = $file->getFilename();
        if (stripos($name, $q) !== false) {
            $full_path = $file->getPathname();

            if (gojs_is_protected_path($full_path)) {
                continue;
            }

            $rel = gojs_relative_path($full_path);
            $results[] = gojs_get_file_info($full_path, $rel);
        }
    }

    gojs_json_response(array(
        'files' => $results,
        'total' => count($results),
    ));
}

function gojs_api_download() {
    $path = gojs_get_param('path', '');

    $safe_path = gojs_safe_path($path);
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '下载');

    if (!is_file($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_file',
            'message' => '路径不是文件',
        ), 400);
    }

    if (!is_readable($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_readable',
            'message' => '文件不可读',
        ), 403);
    }

    $filename = basename($safe_path);
    $size = filesize($safe_path);

    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $safe_path);
        }
    }

    $ascii_name = preg_replace('/[\x00-\x1F\x7F"]/', '_', $filename);
    $encoded_name = rawurlencode($filename);

    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $ascii_name . '"; filename*=UTF-8\'' . $encoded_name);
    header('Content-Length: ' . $size);
    header('Accept-Ranges: bytes');

    readfile($safe_path);

    gojs_monitor_bump_bandwidth(0, $size);
    exit;
}
