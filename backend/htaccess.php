<?php




function gojs_htaccess_default_content() {
    return <<<'HTACCESS'




<DirectoryMatch "^.*/\.gojs/">
    Require all denied
</DirectoryMatch>

<FilesMatch "^\.gojs">
    Require all denied
</FilesMatch>


<FilesMatch "\.php$">
    SetEnvIf Request_URI "^/api\.php$" allow_php=1
    Require env allow_php
</FilesMatch>


<Files "config.php">
    Require all denied
</Files>


<Files ".htaccess">
    Require all denied
</Files>


<FilesMatch "\.log$">
    Require all denied
</FilesMatch>


<FilesMatch "db_connections\.json$">
    Require all denied
</FilesMatch>


<FilesMatch "\.md$">
    Require all denied
</FilesMatch>


DirectoryIndex index.html


RewriteEngine On


RewriteRule ^api\.php$ - [R=404,L]


RewriteCond %{REQUEST_FILENAME} -f
RewriteRule ^ - [L]

RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]


RewriteRule ^api/(.*)$ api.php?api=$1 [QSA,L]


RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.html [L]


<IfModule mod_headers.c>
    
    Header always set X-Frame-Options "SAMEORIGIN"

    
    Header always set X-Content-Type-Options "nosniff"

    
    Header always set X-XSS-Protection "1; mode=block"

    
    Header always set Referrer-Policy "strict-origin-when-cross-origin"

    
    <FilesMatch "\.(php)$">
        Header set Cache-Control "no-cache, no-store, must-revalidate"
        Header set Pragma "no-cache"
        Header set Expires "0"
    </FilesMatch>
</IfModule>


<IfModule mod_php.c>
    php_flag display_errors Off
    php_flag log_errors On
    php_value error_log .gojs/php_errors.log
    php_flag expose_php Off
    php_flag allow_url_include Off
</IfModule>
HTACCESS;
}

function gojs_htaccess_rule_template($rule, $from = '', $to = '') {
    switch ($rule) {
        case 'force_https':
            return <<<'HTACCESS'

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https:
</IfModule>
HTACCESS;
        case 'block_sensitive':
            return <<<'HTACCESS'

<FilesMatch "^\.">
    Require all denied
</FilesMatch>
<FilesMatch "\.(log|sql|bak|backup|ini|conf|config|sh|bash)$">
    Require all denied
</FilesMatch>
<Files "config.php">
    Require all denied
</Files>
<DirectoryMatch "^.*/\.gojs/">
    Require all denied
</DirectoryMatch>
HTACCESS;
        case 'prevent_hotlink':
            return <<<'HTACCESS'

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP_REFERER} !^$
    RewriteCond %{HTTP_REFERER} !^https?:
    RewriteRule \.(jpg|jpeg|png|gif|bmp|webp|svg|css|js)$ - [F,NC]
</IfModule>
HTACCESS;
        case 'redirect_301':
            $from_clean = ltrim($from, '/');
            $to_clean = $to;
            return "# 301 重定向\nRedirect 301 /" . $from_clean . " " . $to_clean;
        case 'gzip_compress':
            return <<<'HTACCESS'

<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/html text/plain text/css text/xml application/xml application/rss+xml application/javascript application/x-javascript application/json image/svg+xml
</IfModule>
HTACCESS;
        case 'browser_cache':
            return <<<'HTACCESS'

<IfModule mod_expires.c>
    ExpiresActive On
    ExpiresByType image/jpg "access plus 1 month"
    ExpiresByType image/jpeg "access plus 1 month"
    ExpiresByType image/png "access plus 1 month"
    ExpiresByType image/gif "access plus 1 month"
    ExpiresByType image/webp "access plus 1 month"
    ExpiresByType image/svg+xml "access plus 1 month"
    ExpiresByType text/css "access plus 1 week"
    ExpiresByType application/javascript "access plus 1 week"
    ExpiresByType text/javascript "access plus 1 week"
    ExpiresByType application/font-woff "access plus 1 year"
    ExpiresByType application/font-woff2 "access plus 1 year"
    ExpiresByType application/x-font-ttf "access plus 1 year"
    ExpiresByType font/opentype "access plus 1 year"
</IfModule>
<IfModule mod_headers.c>
    <FilesMatch "\.(css|js|woff|woff2|ttf|eot|otf|jpg|jpeg|png|gif|webp|svg)$">
        Header set Cache-Control "public, max-age=604800"
    </FilesMatch>
</IfModule>
HTACCESS;
        case 'block_dir_browsing':
            return <<<'HTACCESS'

Options -Indexes
HTACCESS;
        default:
            return '';
    }
}

function gojs_api_htaccess() {
    $method = gojs_get_method();

    $safe_path = gojs_safe_path('.htaccess');
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    if ($method === 'GET') {
        $exists = is_file($safe_path);
        $content = '';
        if ($exists) {
            $content = @file_get_contents($safe_path);
            if ($content === false) {
                $content = '';
            }
        } else {
            $content = gojs_htaccess_default_content();
        }

        $writable = $exists ? is_writable($safe_path) : is_writable(dirname($safe_path));

        gojs_json_response(array(
            'content' => $content,
            'path' => '.htaccess',
            'writable' => $writable,
            'exists' => $exists,
        ));
    } elseif ($method === 'PUT') {
        $content = gojs_get_param('content', '');
        if (!is_string($content)) {
            $content = '';
        }

        gojs_ensure_not_protected($safe_path, '修改');

        if (file_exists($safe_path) && !is_writable($safe_path)) {
            gojs_json_response(null, array(
                'code' => 'not_writable',
                'message' => '文件不可写',
            ), 403);
        }

        if (!file_exists($safe_path) && !is_writable(dirname($safe_path))) {
            gojs_json_response(null, array(
                'code' => 'not_writable',
                'message' => '目录不可写',
            ), 403);
        }

        $result = @file_put_contents($safe_path, $content, LOCK_EX);
        if ($result === false) {
            gojs_json_response(null, array(
                'code' => 'write_failed',
                'message' => '写入文件失败',
            ), 500);
        }

        gojs_json_response(array('success' => true));
    } else {
        gojs_json_response(null, array('code' => 'method_not_allowed', 'message' => '方法不允许'), 405);
    }
}

function gojs_api_htaccess_generate() {
    $rules = gojs_get_param('rules', array());
    if (!is_array($rules)) {
        $rules = array();
    }

    $body = gojs_get_body();
    $redirect_from = isset($body['from']) ? (string)$body['from'] : '';
    $redirect_to = isset($body['to']) ? (string)$body['to'] : '';

    $supported_rules = array(
        'force_https',
        'block_sensitive',
        'prevent_hotlink',
        'redirect_301',
        'gzip_compress',
        'browser_cache',
        'block_dir_browsing',
    );

    $valid_rules = array();
    foreach ($rules as $rule) {
        if (in_array($rule, $supported_rules, true)) {
            $valid_rules[] = $rule;
        }
    }

    $sections = array();
    foreach ($valid_rules as $rule) {
        $section = gojs_htaccess_rule_template($rule, $redirect_from, $redirect_to);
        if ($section !== '') {
            $sections[] = $section;
        }
    }

    $content = '';
    if (!empty($sections)) {
        $content = "# Go.js .htaccess 规则生成\n# 生成时间: " . date('Y-m-d H:i:s') . "\n\n";
        $content .= implode("\n\n", $sections);
        $content .= "\n";
    }

    gojs_json_response(array(
        'content' => $content,
        'rules' => $valid_rules,
    ));
}

function gojs_api_htaccess_reset() {
    $safe_path = gojs_safe_path('.htaccess');
    if ($safe_path === false) {
        gojs_json_response(null, array(
            'code' => 'forbidden',
            'message' => '路径访问被拒绝',
        ), 403);
    }

    gojs_ensure_not_protected($safe_path, '重置');

    if (file_exists($safe_path) && !is_writable($safe_path)) {
        gojs_json_response(null, array(
            'code' => 'not_writable',
            'message' => '文件不可写',
        ), 403);
    }

    if (!file_exists($safe_path) && !is_writable(dirname($safe_path))) {
        gojs_json_response(null, array(
            'code' => 'not_writable',
            'message' => '目录不可写',
        ), 403);
    }

    $default_content = gojs_htaccess_default_content();

    $result = @file_put_contents($safe_path, $default_content, LOCK_EX);
    if ($result === false) {
        gojs_json_response(null, array(
            'code' => 'write_failed',
            'message' => '写入文件失败',
        ), 500);
    }

    gojs_json_response(array(
        'success' => true,
        'content' => $default_content,
    ));
}


