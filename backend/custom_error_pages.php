<?php

function gojs_custom_error_pages_config_path(): string {
    return CONFIG_DIR . '/custom_error_pages.json';
}



function gojs_custom_error_pages_load_config(): array {
    return gojs_read_json_lock_safe(gojs_custom_error_pages_config_path(), array());
}

function gojs_custom_error_pages_save_config(array $config): array {
    $result = gojs_write_json_lock_safe(gojs_custom_error_pages_config_path(), $config, true);
    if (!$result['success']) {
        gojs_json_response(array('success' => false, 'error' => $result['error']));
    }
    gojs_json_response(array('success' => true));
}

function gojs_custom_error_pages_get_default_template(string $error_code): string {
    $templates = array(
        '403' => array(
            'title' => '403 Forbidden',
            'content' => '<!DOCTYPE html>
<html lang="en">
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
            Sorry, you do not have permission to access this page.<br>
            Please contact the administrator or go back to the previous page.
        </div>
        <a href="javascript:history.back()" class="back-link">← Back to previous page</a>
    </div>
</body>
</html>'
        ),
        '404' => array(
            'title' => '404 Not Found',
            'content' => '<!DOCTYPE html>
<html lang="en">
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
        <div class="error-title">Page Not Found</div>
        <div class="error-message">
            Sorry, the page you are looking for does not exist.<br>
            Please check the URL or return to the home page.
        </div>
        <a href="/" class="back-link">← Back to home</a>
    </div>
</body>
</html>'
        ),
        '500' => array(
            'title' => '500 Internal Server Error',
            'content' => '<!DOCTYPE html>
<html lang="en">
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
        <div class="error-title">Internal Server Error</div>
        <div class="error-message">
            Sorry, the server encountered an error.<br>
            Please try again later or contact the site administrator.
        </div>
        <a href="/" class="back-link">← Back to home</a>
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
        $errors[] = 'Template content cannot be empty';
        return $errors;
    }
    
    if (strlen($content) > 50000) {
        $errors[] = 'Template content is too large, maximum supported size is 50KB';
    }
    
    if (!preg_match('/<!DOCTYPE/i', $content)) {
        $errors[] = 'Template must include a DOCTYPE declaration';
    }
    
    if (!preg_match('/<html/i', $content) || !preg_match('/<\/html\s*>/i', $content)) {
        $errors[] = 'Template must include complete html tags';
    }
    
    if (!preg_match('/<head/i', $content) || !preg_match('/<\/head\s*>/i', $content)) {
        $errors[] = 'Template must include head tags';
    }
    
    if (!preg_match('/<body/i', $content) || !preg_match('/<\/body\s*>/i', $content)) {
        $errors[] = 'Template must include body tags';
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