<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

require_once '../config.php';
require_once '../functions.php';

header('Content-Type: application/json');

$action = (string)($_REQUEST['action'] ?? '');

// 实例化 WebAuthn（使用当前域名自动检测）
$rpName = SITE_NAME;
$rpId = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$origin = $scheme . '://' . $rpId;

require_once __DIR__ . '/../lib/CborDecoder.php';
require_once __DIR__ . '/../lib/WebAuthn.php';
$webauthn = new WebAuthn($rpName, $rpId, $origin);

try {
    switch ($action) {
        case 'register_options':
            // 需要管理员权限才能注册新 Passkey
            if (!is_admin()) {
                error_response('需要管理员权限', 403);
            }
            success_response($webauthn->getRegistrationOptions());
            break;

        case 'register_verify':
            if (!is_admin()) {
                error_response('需要管理员权限', 403);
            }
            $clientDataJson = (string)($_POST['clientDataJSON'] ?? '');
            $attestationObject = (string)($_POST['attestationObject'] ?? '');
            $credentialId = (string)($_POST['credentialId'] ?? '');

            if ($clientDataJson === '' || $attestationObject === '' || $credentialId === '') {
                error_response('参数不完整');
            }

            $result = $webauthn->verifyRegistration($clientDataJson, $attestationObject, $credentialId);
            success_response(['message' => 'Passkey 注册成功', 'credentialId' => $result['credentialId']]);
            break;

        case 'auth_options':
            $options = $webauthn->getAuthenticationOptions();
            if (empty($options['allowCredentials'])) {
                error_response('尚未注册 Passkey', 404);
            }
            success_response($options);
            break;

        case 'auth_verify':
            $credentialId = (string)($_POST['credentialId'] ?? '');
            $authenticatorData = (string)($_POST['authenticatorData'] ?? '');
            $clientDataJson = (string)($_POST['clientDataJSON'] ?? '');
            $signature = (string)($_POST['signature'] ?? '');

            if ($credentialId === '' || $authenticatorData === '' || $clientDataJson === '' || $signature === '') {
                error_response('参数不完整');
            }

            if ($webauthn->verifyAuthentication($credentialId, $authenticatorData, $clientDataJson, $signature)) {
                // 认证成功，设置管理员 Cookie
                setcookie(
                    API_KEY_COOKIE,
                    hash('sha256', ADMIN_API_KEY),
                    [
                        'expires' => time() + COOKIE_LIFETIME,
                        'path' => COOKIE_PATH,
                        'domain' => COOKIE_DOMAIN,
                        'secure' => COOKIE_SECURE,
                        'httponly' => COOKIE_HTTPONLY,
                        'samesite' => COOKIE_SAMESITE
                    ]
                );
                success_response(['message' => 'Passkey 登录成功']);
            } else {
                error_response('Passkey 验证失败', 401);
            }
            break;

        case 'list':
            if (!is_admin()) {
                error_response('需要管理员权限', 403);
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
            success_response(['credentials' => $list]);
            break;

        case 'delete':
            if (!is_admin()) {
                error_response('需要管理员权限', 403);
            }
            $credId = (string)($_POST['credentialId'] ?? '');
            if ($credId === '') {
                error_response('未指定凭证 ID');
            }
            if ($webauthn->deleteCredential($credId)) {
                success_response(['message' => 'Passkey 已删除']);
            } else {
                error_response('删除失败', 500);
            }
            break;

        default:
            error_response('无效操作');
    }
} catch (Throwable $e) {
    error_log('Passkey error: ' . $e->getMessage());
    error_response(safe_error_message($e), 500);
}
