<?php
declare(strict_types=1);

/**
 * 用户验证 API
 * 处理登录、退出等认证相关的请求
 */

require_once __DIR__ . '/../bootstrap.php';

// 设置返回的内容类型为 JSON
header('Content-Type: application/json');

// 获取请求数据
$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // 处理退出请求
    if (isset($data['action']) && $data['action'] === 'logout') {
        // 删除 cookie
        setcookie(API_KEY_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => COOKIE_PATH,
            'domain' => COOKIE_DOMAIN,
            'secure' => COOKIE_SECURE,
            'httponly' => COOKIE_HTTPONLY,
            'samesite' => COOKIE_SAMESITE
        ]);

        // 清除 session
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        session_destroy();

        success_response([
            'message' => '已退出登录',
            'cookie_name' => API_KEY_COOKIE
        ]);
        exit;
    }
    
    // 处理登录请求
    if (isset($data['apiKey'])) {
        // 速率限制检查
        if (!check_login_rate_limit()) {
            error_response('登录尝试过于频繁，请 5 分钟后再试', 429);
        }

        $api_key = trim($data['apiKey']);
        
        if (ADMIN_API_KEY !== '' && hash_equals(ADMIN_API_KEY, $api_key)) {
            setcookie(
                API_KEY_COOKIE,
                hash('sha256', $api_key),
                [
                    'expires' => time() + COOKIE_LIFETIME,
                    'path' => COOKIE_PATH,
                    'domain' => COOKIE_DOMAIN,
                    'secure' => COOKIE_SECURE,
                    'httponly' => COOKIE_HTTPONLY,
                    'samesite' => COOKIE_SAMESITE
                ]
            );
            
            success_response(['message' => '登录成功']);
        } else {
            record_login_failure();
            error_response('API Key 无效', 401);
        }
        exit;
    }
}

// 处理无效请求
error_response('无效的请求', 405);
