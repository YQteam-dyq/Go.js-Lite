<?php

function gojs_custom_error_pages_config_path(): string {
    return CONFIG_DIR . '/custom_error_pages.json';
}

function gojs_read_json_lock_safe(string $path, $default = array()) {
    if (!file_exists($path)) return $default;
    $fp = @fopen($path, 'r');
    if (!$fp) {
        $fallback = @file_get_contents($path);
        if ($fallback === false) return $default;
        $data = json_decode($fallback, true);
        return is_array($data) ? $data : $default;
    }
    if (!@flock($fp, LOCK_SH)) {
        fclose($fp);
        $fallback = @file_get_contents($path);
        if ($fallback === false) return $default;
        $data = json_decode($fallback, true);
        return is_array($data) ? $data : $default;
    }
    $raw = '';
    while (!feof($fp)) $raw .= fread($fp, 8192);
    @flock($fp, LOCK_UN);
    fclose($fp);
    if ($raw === '') return $default;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function gojs_write_json_lock_safe(string $path, array $data, bool $pretty = true): void {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $flags = $pretty ? (JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : JSON_UNESCAPED_UNICODE;
    $json = json_encode($data, $flags);
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    @file_put_contents($tmp, $json, LOCK_EX);
    @chmod($tmp, 0600);
    @rename($tmp, $path);
}

function gojs_custom_error_pages_load_config(): array {
    return gojs_read_json_lock_safe(gojs_custom_error_pages_config_path(), array());
}

function gojs_custom_error_pages_save_config(array $config): void {
    gojs_write_json_lock_safe(gojs_custom_error_pages_config_path(), $config, true);
}

function gojs_custom_error_pages_get_default_template(string $error_code): string {
    $templates = array(
        '403' => array(
            'title' => '403 Forbidden',
            'content' => '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Forbidden</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f5f5f5; }
        .error-container { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 500px; margin: 0 auto; }
        .error-code { font-size: 72px; font-weight: bold; color: #e74c3c; margin-bottom: 20px; }
        .error-title { font-size: 24px; margin-bottom: 15px; color: #2c3e50; }
        .error-message { font-size: 16px; color: #7f8c8d; line-height: 1.6; margin-bottom: 30px; }
        .back-link { color: #3498db; text-decoration: none; font-weight: 500; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-code">403</div>
        <div class="error-title">Forbidden</div>
        <div class="error-message">
            抱歉，您没有权限访问此页面。<br>
            请联系管理员或返回上一页。
        </div>
        <a href="javascript:history.back()" class="back-link">← 返回上一页</a>
    </div>
</body>
</html>'
        ),
        '404' => array(
            'title' => '404 Not Found',
            'content' => '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 Not Found</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f5f5f5; }
        .error-container { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 500px; margin: 0 auto; }
        .error-code { font-size: 72px; font-weight: bold; color: #e74c3c; margin-bottom: 20px; }
        .error-title { font-size: 24px; margin-bottom: 15px; color: #2c3e50; }
        .error-message { font-size: 16px; color: #7f8c8d; line-height: 1.6; margin-bottom: 30px; }
        .back-link { color: #3498db; text-decoration: none; font-weight: 500; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-code">404</div>
        <div class="error-title">页面未找到</div>
        <div class="error-message">
            抱歉，您访问的页面不存在。<br>
            请检查URL是否正确，或返回首页。
        </div>
        <a href="/" class="back-link">← 返回首页</a>
    </div>
</body>
</html>'
        ),
        '500' => array(
            'title' => '500 Internal Server Error',
            'content' => '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 Internal Server Error</title>
    <style>
        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f5f5f5; }
        .error-container { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 500px; margin: 0 auto; }
        .error-code { font-size: 72px; font-weight: bold; color: #e74c3c; margin-bottom: 20px; }
        .error-title { font-size: 24px; margin-bottom: 15px; color: #2c3e50; }
        .error-message { font-size: 16px; color: #7f8c8d; line-height: 1.6; margin-bottom: 30px; }
        .back-link { color: #3498db; text-decoration: none; font-weight: 500; }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-code">500</div>
        <div class="error-title">服务器内部错误</div>
        <div class="error-message">
            抱歉，服务器遇到了一个错误。<br>
            请稍后再试，或联系网站管理员。
        </div>
        <a href="/" class="back-link">← 返回首页</a>
    </div>
</body>
</html>'
        )
    );
    
    return isset($templates[$error_code]) ? $templates[$error_code] : null;
}

function gojs_custom_error_pages_validate_template(string $content): array {
    $errors = array();
    
    if (empty($content)) {
        $errors[] = '模板内容不能为空';
        return $errors;
    }
    
    if (strlen($content) > 50000) {
        $errors[] = '模板内容过大，最大支持50KB';
    }
    
    if (!preg_match('/<!DOCTYPE/i', $content)) {
        $errors[] = '模板必须包含DOCTYPE声明';
    }
    
    if (!preg_match('/<html/i', $content) || !preg_match('/<\/html\s*>/i', $content)) {
        $errors[] = '模板必须包含完整的html标签';
    }
    
    if (!preg_match('/<head/i', $content) || !preg_match('/<\/head\s*>/i', $content)) {
        $errors[] = '模板必须包含head标签';
    }
    
    if (!preg_match('/<body/i', $content) || !preg_match('/<\/body\s*>/i', $content)) {
        $errors[] = '模板必须包含body标签';
    }
    
    return $errors;
}

function gojs_api_custom_error_pages_config() {
    $config = gojs_custom_error_pages_load_config();
    gojs_json_response($config);
}

function gojs_api_custom_error_pages_update_template() {
    $data = gojs_get_body();
    if (!is_array($data) || empty($data['error_code']) || empty($data['content'])) {
        gojs_json_response(null, array('code' => 'invalid_data', 'message' => 'Invalid data format'), 400);
        return;
    }
    
    $error_code = $data['error_code'];
    $content = $data['content'];
    $title = isset($data['title']) ? $data['title'] : '';
    
    if (!in_array($error_code, array('403', '404', '500'))) {
        gojs_json_response(null, array('code' => 'invalid_error_code', 'message' => 'Invalid error code'), 400);
        return;
    }
    
    $errors = gojs_custom_error_pages_validate_template($content);
    if (!empty($errors)) {
        gojs_json_response(null, array('code' => 'validation_failed', 'message' => 'Template validation failed', 'errors' => $errors), 400);
        return;
    }
    
    $config = gojs_custom_error_pages_load_config();
    if (!isset($config['templates'])) {
        $config['templates'] = array();
    }
    
    $config['templates'][$error_code] = array(
        'title' => $title,
        'content' => $content,
        'updated_at' => time(),
    );
    
    gojs_custom_error_pages_save_config($config);
    gojs_json_response(array(
        'error_code' => $error_code,
        'title' => $title,
        'content' => $content,
        'updated_at' => $config['templates'][$error_code]['updated_at'],
    ));
}

function gojs_api_custom_error_pages_reset_template() {
    $data = gojs_get_body();
    if (!is_array($data) || empty($data['error_code'])) {
        gojs_json_response(null, array('code' => 'invalid_data', 'message' => 'Invalid data format'), 400);
        return;
    }
    
    $error_code = $data['error_code'];
    
    if (!in_array($error_code, array('403', '404', '500'))) {
        gojs_json_response(null, array('code' => 'invalid_error_code', 'message' => 'Invalid error code'), 400);
        return;
    }
    
    $default_template = gojs_custom_error_pages_get_default_template($error_code);
    if (!$default_template) {
        gojs_json_response(null, array('code' => 'template_not_found', 'message' => 'Default template not found'), 404);
        return;
    }
    
    $config = gojs_custom_error_pages_load_config();
    if (!isset($config['templates'])) {
        $config['templates'] = array();
    }
    
    $config['templates'][$error_code] = array(
        'title' => $default_template['title'],
        'content' => $default_template['content'],
        'updated_at' => time(),
    );
    
    gojs_custom_error_pages_save_config($config);
    gojs_json_response(array(
        'error_code' => $error_code,
        'title' => $default_template['title'],
        'content' => $default_template['content'],
        'updated_at' => $config['templates'][$error_code]['updated_at'],
    ));
}

function gojs_api_custom_error_pages_preview() {
    $data = gojs_get_body();
    if (!is_array($data) || empty($data['content'])) {
        gojs_json_response(null, array('code' => 'invalid_data', 'message' => 'Invalid data format'), 400);
        return;
    }
    
    $content = $data['content'];
    $errors = gojs_custom_error_pages_validate_template($content);
    
    if (!empty($errors)) {
        gojs_json_response(null, array('code' => 'validation_failed', 'message' => 'Template validation failed', 'errors' => $errors), 400);
        return;
    }
    
    gojs_json_response(array(
        'content' => $content,
        'valid' => true,
    ));
}