<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');

$action = (string)($_REQUEST['action'] ?? '');

// 实例化 WebAuthn（使用当前域名自动检测）
$rpName = SITE_NAME;
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
// rpId 不能包含端口；127.0.0.1 不被 WebAuthn 接受，需映射为 localhost
$rpId = preg_replace('/:\d+$/', '', $host);
if ($rpId === '127.0.0.1') {
    $rpId = 'localhost';
}
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$origin = $scheme . '://' . $host;

$webauthn = new \LitePic\Service\Auth\Passkey\WebAuthn($rpName, $rpId, $origin);

try {
    switch ($action) {
        case 'register_options':
            // 需要管理员权限才能注册新 Passkey
            if (!(new \LitePic\Service\Auth\AuthService())->isAdmin()) {
                \LitePic\Core\Response::error('需要管理员权限', 403);
            }
            \LitePic\Core\Response::success($webauthn->getRegistrationOptions());
            break;

        case 'register_verify':
            if (!(new \LitePic\Service\Auth\AuthService())->isAdmin()) {
                \LitePic\Core\Response::error('需要管理员权限', 403);
            }
            $clientDataJsonB64 = (string)($_POST['clientDataJSON'] ?? '');
            $attestationObject = (string)($_POST['attestationObject'] ?? '');
            $credentialId = (string)($_POST['credentialId'] ?? '');

            if ($clientDataJsonB64 === '' || $attestationObject === '' || $credentialId === '') {
                \LitePic\Core\Response::error('参数不完整');
            }

            // clientDataJSON 前端传的是 Base64URL，需解码为原始 JSON 字符串
            $clientDataJson = \LitePic\Service\Auth\Passkey\WebAuthn::base64UrlDecode($clientDataJsonB64);
            $result = $webauthn->verifyRegistration($clientDataJson, $attestationObject, $credentialId);
            \LitePic\Core\Response::success(['message' => 'Passkey 注册成功', 'credentialId' => $result['credentialId']]);
            break;

        case 'auth_options':
            $options = $webauthn->getAuthenticationOptions();
            if (empty($options['allowCredentials'])) {
                // Use 400 (not 404) so the JSON body survives intact:
                // many shared hosting nginx vhosts (e.g. BT panel) configure
                // `error_page 404 /404.html` which intercepts our JSON 404
                // and replaces it with an HTML page — frontend then chokes
                // on "Unexpected token '<'". 400 is unaffected.
                \LitePic\Core\Response::error('暂未配置 Passkey，请先在后台「设置 → 安全」中注册', 400);
            }
            \LitePic\Core\Response::success($options);
            break;

        case 'auth_verify':
            $credentialId = (string)($_POST['credentialId'] ?? '');
            $authenticatorData = (string)($_POST['authenticatorData'] ?? '');
            $clientDataJsonB64 = (string)($_POST['clientDataJSON'] ?? '');
            $signature = (string)($_POST['signature'] ?? '');

            if ($credentialId === '' || $authenticatorData === '' || $clientDataJsonB64 === '' || $signature === '') {
                \LitePic\Core\Response::error('参数不完整');
            }

            // clientDataJSON 前端传的是 Base64URL，需解码为原始 JSON 字符串
            $clientDataJson = \LitePic\Service\Auth\Passkey\WebAuthn::base64UrlDecode($clientDataJsonB64);
            if ($webauthn->verifyAuthentication($credentialId, $authenticatorData, $clientDataJson, $signature)) {
                // 认证成功，设置管理员 Cookie
                $sessionSecret = (string)\LitePic\Core\Config::get('ADMIN_SESSION_SECRET', '');
                if ($sessionSecret === '') {
                    $sessionSecret = bin2hex(random_bytes(32));
                    \LitePic\Core\Config::write(['ADMIN_SESSION_SECRET' => $sessionSecret]);
                }

                setcookie(
                    API_KEY_COOKIE,
                    hash('sha256', $sessionSecret),
                    [
                        'expires' => time() + COOKIE_LIFETIME,
                        'path' => COOKIE_PATH,
                        'domain' => COOKIE_DOMAIN,
                        'secure' => COOKIE_SECURE,
                        'httponly' => COOKIE_HTTPONLY,
                        'samesite' => COOKIE_SAMESITE
                    ]
                );
                \LitePic\Core\Response::success(['message' => 'Passkey 登录成功']);
            } else {
                \LitePic\Core\Response::error('Passkey 验证失败', 401);
            }
            break;

        case 'list':
            if (!(new \LitePic\Service\Auth\AuthService())->isAdmin()) {
                \LitePic\Core\Response::error('需要管理员权限', 403);
            }
            $creds = $webauthn->getCredentials();
            $list = [];
            foreach ($creds as $id => $cred) {
                $list[] = [
                    'credentialId' => $id,
                    'createdAt' => $cred['createdAt'] ?? '-',
                    'lastUsedAt' => $cred['lastUsedAt'] ?? '-',
                    'signCount' => $cred['signCount'] ?? 0,
                ];
            }
            \LitePic\Core\Response::success(['credentials' => $list]);
            break;

        case 'delete':
            if (!(new \LitePic\Service\Auth\AuthService())->isAdmin()) {
                \LitePic\Core\Response::error('需要管理员权限', 403);
            }
            $credId = (string)($_POST['credentialId'] ?? '');
            if ($credId === '') {
                \LitePic\Core\Response::error('未指定凭证 ID');
            }
            if ($webauthn->deleteCredential($credId)) {
                \LitePic\Core\Response::success(['message' => 'Passkey 已删除']);
            } else {
                \LitePic\Core\Response::error('删除失败', 500);
            }
            break;

        default:
            \LitePic\Core\Response::error('无效操作');
    }
} catch (Throwable $e) {
    error_log('Passkey error: ' . $e->getMessage());
    \LitePic\Core\Response::error(\LitePic\Core\Response::safeMessage($e), 500);
}
