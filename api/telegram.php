<?php
declare(strict_types=1);

/**
 * Telegram webhook entry — receives Bot API updates and hands off to
 * {@see \LitePic\Service\Telegram\TelegramHandler}.
 *
 * Routing:
 *   The v1 dispatcher (api/v1.php) matches `/api/v1/telegram/webhook/<secret>`
 *   and require()s this file with `$_GET['secret']` already populated.
 *
 * Defence layers:
 *   1. URL secret  — `<secret>` in the path matches TELEGRAM_WEBHOOK_SECRET
 *   2. Header token — `X-Telegram-Bot-Api-Secret-Token` matches the same
 *      secret (Telegram sets it from `setWebhook(secret_token=...)`)
 *   3. User allowlist — checked inside TelegramHandler before any action
 *
 * We always return HTTP 200, even on errors. Telegram retries non-2xx
 * responses up to 24 hours, which would create a flood of duplicate
 * messages if our handler has a bug. Logged failures are visible in
 * the LitePic admin logs page.
 */

if (!defined('LITEPIC_API_V1_DISPATCH')) {
    http_response_code(403);
    exit('Direct access denied');
}

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__) . '/bootstrap.php';
}

header('Content-Type: application/json; charset=utf-8');

// Always 200 — see file-level docblock for rationale.
$ack = static function (string $reason = 'ok'): void {
    echo json_encode(['ok' => true, 'note' => $reason], JSON_UNESCAPED_UNICODE);
    exit;
};

// ---- Feature gate ----
if (!\LitePic\Core\Config::bool('TELEGRAM_ENABLED', false)) {
    \LitePic\Core\Logger::warning('Telegram webhook hit while disabled');
    $ack('disabled');
}

// ---- Verify URL secret ----
$urlSecret = (string)($_GET['secret'] ?? '');
$configuredSecret = trim((string)\LitePic\Core\Config::get('TELEGRAM_WEBHOOK_SECRET', ''));
if ($configuredSecret === '' || !hash_equals($configuredSecret, $urlSecret)) {
    \LitePic\Core\Logger::warning('Telegram webhook: bad URL secret', [
        'remote' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);
    // 403 here is fine — attacker hitting random URLs, not Telegram retrying.
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Verify header secret_token (Telegram-supplied) ----
// Telegram sends X-Telegram-Bot-Api-Secret-Token on every update if we
// registered the webhook with secret_token. We reuse the same value as
// the URL secret — single source of truth, simpler ops.
$headerSecret = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if ($headerSecret !== '' && !hash_equals($configuredSecret, $headerSecret)) {
    \LitePic\Core\Logger::warning('Telegram webhook: bad header secret_token');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- Decode update body ----
$body = file_get_contents('php://input');
if (!is_string($body) || $body === '') $ack('empty');

$update = json_decode($body, true);
if (!is_array($update)) {
    \LitePic\Core\Logger::error('Telegram webhook: non-JSON body', [
        'preview' => substr((string)$body, 0, 200),
    ]);
    $ack('non-json');
}

// ---- Dispatch ----
try {
    (new \LitePic\Service\Telegram\TelegramHandler())->handle($update);
} catch (\Throwable $e) {
    \LitePic\Core\Logger::error('Telegram webhook handler threw', [
        'msg'   => $e->getMessage(),
        'trace' => substr($e->getTraceAsString(), 0, 2000),
    ]);
}
$ack('handled');
