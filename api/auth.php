<?php
declare(strict_types=1);

/**
 * 用户验证 API
 * 处理登录、退出、改密等认证相关的请求。
 */

require __DIR__ . '/../bootstrap.php';

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
    \LitePic\Service\Auth\AuthService::clearAdminCookie();
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
// 写入流程：
//   1. AdminAuthService::verifyPassword() 校验当前密码
//   2. Config::write(['ADMIN_API_KEY' => 明文, 'ADMIN_PASSWORD_HASH' => bcrypt])
//   3. 签发新的 session_secret cookie
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
    if (!\LitePic\Service\Auth\AuthService::verifyPassword($current)) {
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

    // 保存：明文保留给 API key 匹配，bcrypt hash 存储用于安全校验
    $newHash = password_hash($next, PASSWORD_BCRYPT);
    $newSecret = bin2hex(random_bytes(32));
    $ok = \LitePic\Core\Config::write([
        'ADMIN_API_KEY' => $next,
        'ADMIN_PASSWORD_HASH' => $newHash,
        'ADMIN_SESSION_SECRET' => $newSecret,
    ]);
    if (!$ok) {
        \LitePic\Core\Response::error('密码保存失败，请稍后重试', 500);
        exit;
    }

    // 续签 cookie，让用户保持登录态
    \LitePic\Service\Auth\AuthService::issueAdminCookie($newSecret);

    \LitePic\Core\Response::success([
        'message' => '密码修改成功',
        'must_change_password' => false,
        // Secret rotated — client must refresh CSRF so subsequent settings saves work.
        'csrf_token' => \LitePic\Core\Csrf::token(),
    ]);
    exit;
}

// ---- 登录 --------------------------------------------------------------
if (isset($data['apiKey'])) {
    if (!(new \LitePic\Repository\LoginAttemptRepository())->isAllowedForCurrentIp()) {
        \LitePic\Core\Response::error('登录尝试过于频繁，请 5 分钟后再试', 429);
    }

    $api_key = trim((string)$data['apiKey']);
    $auth = new \LitePic\Service\Auth\AuthService();

    if ($auth->verifyPassword($api_key)) {
        // 自动生成 session_secret（如果还没有）用于 cookie 签名
        $sessionSecret = (string)\LitePic\Core\Config::get('ADMIN_SESSION_SECRET', '');
        if ($sessionSecret === '') {
            $sessionSecret = bin2hex(random_bytes(32));
            \LitePic\Core\Config::write(['ADMIN_SESSION_SECRET' => $sessionSecret]);
        }

        // 如果密码还是明文存储，自动升级为 bcrypt
        if ($auth->isPasswordPlaintext()) {
            $newHash = password_hash($api_key, PASSWORD_BCRYPT);
            \LitePic\Core\Config::write(['ADMIN_PASSWORD_HASH' => $newHash]);
        }

        \LitePic\Service\Auth\AuthService::issueAdminCookie($sessionSecret);

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
