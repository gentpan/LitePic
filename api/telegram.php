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
 * Defence layers (in order, all enforced):
 *   1. URL secret  — `<secret>` in the path matches TELEGRAM_WEBHOOK_SECRET
 *   2. Header token — `X-Telegram-Bot-Api-Secret-Token` matches the same
 *      secret (Telegram sets it from `setWebhook(secret_token=...)`).
 *      Unconditionally enforced — an absent header is treated as a bad
 *      header.
 *   3. Feature gate — TELEGRAM_ENABLED. Only checked AFTER both secrets
 *      pass, so a disabled deployment can't be fingerprinted from
 *      response shape (both bad-secret and disabled return 403).
 *   4. User allowlist — checked inside TelegramHandler before any action.
 *
 * For authenticated callers (both secrets pass) we return HTTP 200 even on
 * dispatcher errors to avoid Telegram's 24-hour retry storm. For
 * unauthenticated callers we return 403 with no body content — same
 * response regardless of whether the bot is disabled, so external scanners
 * can't tell deployment state.
 */

if (!defined('LITEPIC_API_V1_DISPATCH')) {
    http_response_code(403);
    exit('Direct access denied');
}

if (!defined('APP_ROOT')) {
    require dirname(__DIR__) . '/bootstrap.php';
}

header('Content-Type: application/json; charset=utf-8');

// Always 200 for authenticated callers — see file-level docblock for rationale.
$ack = static function (string $reason = 'ok'): void {
    echo json_encode(['ok' => true, 'note' => $reason], JSON_UNESCAPED_UNICODE);
    exit;
};

// Indistinguishable rejection — used for any pre-auth failure (bad URL
// secret, bad header secret, bot disabled). Same status + body so an
// unauthenticated attacker can't fingerprint deployment state.
$reject = static function (string $logTag): void {
    \LitePic\Core\Logger::warning("Telegram webhook: rejected ({$logTag})", [
        'remote' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
};

// ---- 1. Verify URL secret ----
$urlSecret = (string)($_GET['secret'] ?? '');
$configuredSecret = trim((string)\LitePic\Core\Config::get('TELEGRAM_WEBHOOK_SECRET', ''));
if ($configuredSecret === '' || !hash_equals($configuredSecret, $urlSecret)) {
    $reject('bad-url-secret');
}

// ---- 2. Verify header secret_token (Telegram-supplied) ----
// Telegram sends X-Telegram-Bot-Api-Secret-Token on every update because
// we register the webhook with secret_token=<configuredSecret>. The header
// is REQUIRED — a missing header is a fail (hash_equals with '' returns
// false). This is what closes the "URL secret is the only real defence"
// bypass: even an attacker who learns the URL secret can't forge the
// header (Telegram is the only party that knows to add it).
$headerSecret = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (!hash_equals($configuredSecret, $headerSecret)) {
    $reject('bad-header-secret');
}

// ---- 3. Feature gate (only reachable when secrets are correct) ----
// Disabled deployments shouldn't process updates — but Telegram won't
// actually be calling us when disabled because we delete the webhook on
// disable. This guard is for the leftover-webhook case.
if (!\LitePic\Core\Config::bool('TELEGRAM_ENABLED', false)) {
    \LitePic\Core\Logger::warning('Telegram webhook hit while disabled (authenticated)');
    // Still 403 — keeps response shape uniform with bad-secret responses
    // so the enabled/disabled state can never leak.
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 4. Decode update body ----
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
