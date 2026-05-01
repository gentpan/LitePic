<?php
declare(strict_types=1);

/**
 * 用户验证 API
 * 处理登录、退出、改密等认证相关的请求。
 */

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    \LitePic\Core\Response::error('无效的请求', 405);
}

$action = isset($data['action']) ? (string)$data['action'] : '';

// ---- 退出登录 ----------------------------------------------------------
if ($action === 'logout') {
    setcookie(API_KEY_COOKIE, '', [
        'expires' => time() - 3600,
        'path' => COOKIE_PATH,
        'domain' => COOKIE_DOMAIN,
        'secure' => COOKIE_SECURE,
        'httponly' => COOKIE_HTTPONLY,
        'samesite' => COOKIE_SAMESITE,
    ]);
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    session_destroy();

    \LitePic\Core\Response::success([
        'message' => '已退出登录',
        'cookie_name' => API_KEY_COOKIE,
    ]);
    exit;
}

// ---- 修改密码 ----------------------------------------------------------
//
// 必须先登录（cookie 或 master key 任一通过即可），然后传入：
//   - currentPassword: 当前密码（防止 cookie 被劫持后改密）
//   - newPassword:     新密码
//
// 写入流程：Config::write(['ADMIN_API_KEY' => 新密码]) → SQLite settings 表，
// 然后立刻把当前请求的 cookie 换成新 hash，用户保持登录状态。
if ($action === 'change_password') {
    if (!(new \LitePic\Service\Auth\AuthService())->isApiRequestAuthorized()) {
        \LitePic\Core\Response::error('未授权', 401);
        exit;
    }

    $current = isset($data['currentPassword']) ? trim((string)$data['currentPassword']) : '';
    $next    = isset($data['newPassword'])     ? trim((string)$data['newPassword'])     : '';
    $confirm = isset($data['confirmPassword']) ? trim((string)$data['confirmPassword']) : $next;

    if ($current === '' || $next === '') {
        \LitePic\Core\Response::error('请输入当前密码和新密码', 400);
        exit;
    }
    if (!hash_equals(ADMIN_API_KEY, $current)) {
        \LitePic\Core\Response::error('当前密码不正确', 403);
        exit;
    }
    if ($next !== $confirm) {
        \LitePic\Core\Response::error('两次输入的新密码不一致', 400);
        exit;
    }
    if (strlen($next) < 8) {
        \LitePic\Core\Response::error('新密码至少 8 位', 400);
        exit;
    }
    if ($next === DEFAULT_ADMIN_API_KEY) {
        \LitePic\Core\Response::error('新密码不能继续使用默认密码', 400);
        exit;
    }
    if ($next === $current) {
        \LitePic\Core\Response::error('新密码不能与当前密码相同', 400);
        exit;
    }

    $ok = \LitePic\Core\Config::write(['ADMIN_API_KEY' => $next]);
    if (!$ok) {
        \LitePic\Core\Response::error('密码保存失败，请稍后重试', 500);
        exit;
    }

    // 续签 cookie，让用户保持登录态。新密码已经写进 $_ENV / Config 缓存，
    // 但 ADMIN_API_KEY 常量是本次请求开始时定义的，所以这里直接用 $next。
    setcookie(
        API_KEY_COOKIE,
        hash('sha256', $next),
        [
            'expires' => time() + COOKIE_LIFETIME,
            'path' => COOKIE_PATH,
            'domain' => COOKIE_DOMAIN,
            'secure' => COOKIE_SECURE,
            'httponly' => COOKIE_HTTPONLY,
            'samesite' => COOKIE_SAMESITE,
        ]
    );

    \LitePic\Core\Response::success([
        'message' => '密码修改成功',
        'must_change_password' => false,
    ]);
    exit;
}

// ---- 登录 --------------------------------------------------------------
if (isset($data['apiKey'])) {
    if (!(new \LitePic\Repository\LoginAttemptRepository())->isAllowedForCurrentIp()) {
        \LitePic\Core\Response::error('登录尝试过于频繁，请 5 分钟后再试', 429);
    }

    $api_key = trim((string)$data['apiKey']);

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
                'samesite' => COOKIE_SAMESITE,
            ]
        );

        // 是否仍在使用默认密码 — 是则要求修改
        $mustChange = defined('DEFAULT_ADMIN_API_KEY')
            && hash_equals(DEFAULT_ADMIN_API_KEY, $api_key);

        \LitePic\Core\Response::success([
            'message' => $mustChange ? '登录成功，请立即修改默认密码' : '登录成功',
            'must_change_password' => $mustChange,
        ]);
    } else {
        (new \LitePic\Repository\LoginAttemptRepository())->recordFailureForCurrentIp();
        \LitePic\Core\Response::error('API Key 无效', 401);
    }
    exit;
}

\LitePic\Core\Response::error('无效的请求', 405);
