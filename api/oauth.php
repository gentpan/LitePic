<?php
declare(strict_types=1);

/**
 * OAuth 端点 — /oauth/<provider>/start 与 /oauth/<provider>/callback。
 *
 * 由 index.php 在路径匹配到 /oauth/ 前缀时引入。成功后把用户带回
 * /gallery（已登录）或 /register（失败时回显错误）。
 */

require __DIR__ . '/../bootstrap.php';

use LitePic\Service\Auth\OAuth\OAuthService;
use LitePic\Service\Auth\UserContext;

$uriPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$uriPath = is_string($uriPath) ? rtrim($uriPath, '/') : '';

$fail = static function (string $message): void {
    $target = UserContext::enabled() ? '/register' : '/';
    \LitePic\Core\HttpCache::redirect($target . '?oauth_error=' . rawurlencode($message));
};

if (!preg_match('#^/oauth/([a-z]+)/(start|callback)$#', $uriPath, $m)) {
    http_response_code(404);
    echo '404 Not Found';
    exit;
}
[, $provider, $step] = $m;

if (!OAuthService::isValidProvider($provider) || !OAuthService::isEnabled($provider)) {
    $fail('该第三方登录未启用');
    exit;
}

$oauth = new OAuthService();

if ($step === 'start') {
    try {
        $inviteCode = trim((string)($_GET['invite'] ?? ''));
        $url = $oauth->startUrl($provider, $inviteCode);
    } catch (\InvalidArgumentException $e) {
        $fail($e->getMessage());
        exit;
    }
    header('Location: ' . $url, true, 302);
    exit;
}

// callback
try {
    $user = $oauth->handleCallback($provider, $_GET);
} catch (\InvalidArgumentException $e) {
    $fail($e->getMessage());
    exit;
}

UserContext::loginUser((int)$user['id']);
\LitePic\Core\HttpCache::redirect('/gallery');
