<?php
declare(strict_types=1);

namespace LitePic\Service\Telegram;

use LitePic\Core\Config;
use LitePic\Core\Logger;

/**
 * Thin Telegram Bot API client.
 *
 * Why a hand-rolled client instead of pulling in a Composer SDK:
 *   - We use ~6 endpoints. A 200-line file is less to audit than a 3-MB
 *     dependency on a host where `composer install` may not even run
 *     (BT panel / restricted PHP).
 *   - All transport goes through cURL (which is enabled even on locked-down
 *     PHP-FPM where shell_exec / proc_open are off).
 *
 * Concurrency / rate limits:
 *   Telegram tolerates ~30 messages/sec to different chats. We're a single-user
 *   admin bot so we never get close — no rate-limit handling needed beyond
 *   surfacing errors back to the caller.
 *
 * All API methods return either:
 *   - `array` on success — the decoded `result` field of Telegram's response
 *   - `null`  on failure  — error already logged via {@see Logger}
 */
final class TelegramApi
{
    /** Telegram's documented base URL. */
    private const API_BASE  = 'https://api.telegram.org';

    /** cURL timeout for normal Bot API calls (sendMessage, getMe, …). */
    private const TIMEOUT   = 15;

    /** Longer timeout for downloading user-supplied photos / documents. */
    private const DL_TIMEOUT = 60;

    private string $token;

    public function __construct(?string $token = null)
    {
        // Most callers don't need to inject a token — pull from settings.
        // Token-injection is mostly for unit tests / one-off CLI utilities.
        $this->token = $token !== null ? $token : (string)Config::get('TELEGRAM_BOT_TOKEN', '');
    }

    /**
     * Has the admin actually configured a bot? Cheap precondition check —
     * use this from the webhook entrypoint before attempting anything.
     */
    public function isConfigured(): bool
    {
        // Basic token shape: `<bot_id>:<35-char-token>`. We don't validate
        // strictly because Telegram's format may evolve; this catches the
        // empty / obvious-typo cases.
        return $this->token !== '' && preg_match('/^\d+:[A-Za-z0-9_-]{30,}$/', $this->token) === 1;
    }

    // ==================== Bot API endpoints ====================

    /**
     * Verify the token belongs to a real bot. Returns the bot's profile
     * (id, username, first_name, can_join_groups, …) or null on error.
     *
     * @return array<string,mixed>|null
     */
    public function getMe(): ?array
    {
        return $this->call('getMe', []);
    }

    /**
     * Send a plain or HTML-formatted message. We always use HTML mode so
     * callers can include `<b>`, `<code>`, `<a href>` etc. — but we DON'T
     * escape user input for them; callers must do that themselves with
     * {@see self::escapeHtml()}.
     *
     * @return array<string,mixed>|null
     */
    public function sendMessage(int $chatId, string $text, array $extra = []): ?array
    {
        $payload = array_merge([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
            // Default to no link previews — keeps "/list" output compact.
            // Callers can override by passing `disable_web_page_preview => false`.
            'disable_web_page_preview' => true,
        ], $extra);

        return $this->call('sendMessage', $payload);
    }

    /**
     * Resolve a Telegram `file_id` into its current download path. The
     * returned `file_path` is valid for ~1 hour and is the input to
     * {@see self::downloadFile()}.
     *
     * @return array<string,mixed>|null
     */
    public function getFile(string $fileId): ?array
    {
        return $this->call('getFile', ['file_id' => $fileId]);
    }

    /**
     * Stream a Telegram-hosted file to a local path. Uses cURL to avoid
     * loading the whole image into memory.
     *
     * Returns true on a 2xx + non-empty file under the size cap; false
     * otherwise (logs the failure). The caller owns the destination — we
     * only write to it.
     *
     * Size cap (both Content-Length and rolling-byte-count enforced):
     *   - CURLOPT_MAXFILESIZE aborts before the body starts if the
     *     server-advertised size exceeds the cap.
     *   - CURLOPT_PROGRESSFUNCTION aborts mid-transfer if the actual
     *     download exceeds the cap (covers servers that lie about
     *     Content-Length or send chunked transfer-encoding).
     *
     * Default cap is MAX_FILE_SIZE from config (matches what UploadService
     * would reject downstream anyway). Caller can override for batch
     * import flows that legitimately need a higher ceiling.
     */
    public function downloadFile(string $filePath, string $destPath, int $maxBytes = 0): bool
    {
        // Sanity-check the path. Telegram's `file_path` is unquoted —
        // tail-segments may contain "/" — but should never start with one.
        $filePath = ltrim($filePath, '/');
        if ($filePath === '') return false;

        if ($maxBytes <= 0) {
            // Fall back to MAX_FILE_SIZE (~20MB default) when caller didn't
            // specify. We refuse to run with an unlimited cap — a malicious
            // 2GB "image/png" document would fill /tmp before our MIME
            // sniff ever ran.
            $maxBytes = defined('MAX_FILE_SIZE') ? max(1, (int)MAX_FILE_SIZE) : (20 * 1024 * 1024);
        }

        $url = self::API_BASE . '/file/bot' . rawurlencode($this->token) . '/' . $filePath;
        // rawurlencode mangled the colon — undo (Telegram's URL is pre-escaped already)
        $url = str_replace('bot' . rawurlencode($this->token), 'bot' . $this->token, $url);

        // Open the file in binary write mode — we let cURL stream into it.
        $fp = @fopen($destPath, 'wb');
        if ($fp === false) {
            Logger::error('Telegram downloadFile: cannot open dest', ['dest' => $destPath]);
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => self::DL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FAILONERROR    => true, // 4xx/5xx → curl_exec returns false
            CURLOPT_USERAGENT      => 'LitePic-Telegram/1.0',
            // Content-Length-based abort.
            CURLOPT_MAXFILESIZE    => $maxBytes,
            // Rolling-byte abort — fires every ~100ms with the bytes-so-far.
            // Returning non-zero from this callback tells cURL to abort.
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_PROGRESSFUNCTION => static function ($_res, $dlTotal, $dlNow) use ($maxBytes) {
                return ((int)$dlTotal > $maxBytes || (int)$dlNow > $maxBytes) ? 1 : 0;
            },
        ]);
        $ok       = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $httpCode < 200 || $httpCode >= 300) {
            Logger::error('Telegram downloadFile failed', [
                'http_code' => $httpCode,
                'curl_err'  => $err,
                'file_path' => $filePath,
                'max_bytes' => $maxBytes,
            ]);
            @unlink($destPath);
            return false;
        }
        // Defensive: 0-byte file is a failure even if HTTP 200.
        $writtenBytes = (int)@filesize($destPath);
        if ($writtenBytes <= 0) {
            @unlink($destPath);
            return false;
        }
        // Belt-and-braces: confirm the file actually fits under the cap.
        // CURLOPT_MAXFILESIZE/PROGRESSFUNCTION already covers this, but
        // if either is unsupported by the runtime's libcurl version we
        // catch the overshoot here.
        if ($writtenBytes > $maxBytes) {
            Logger::warning('Telegram downloadFile exceeded cap', [
                'written'   => $writtenBytes,
                'max_bytes' => $maxBytes,
            ]);
            @unlink($destPath);
            return false;
        }
        return true;
    }

    /**
     * Register the webhook URL with Telegram. The `secret_token` is sent
     * back to us in the `X-Telegram-Bot-Api-Secret-Token` header on every
     * incoming update; we verify it in the webhook entrypoint as a second
     * line of defence next to the URL secret.
     *
     * @return array<string,mixed>|null
     */
    public function setWebhook(string $url, string $secretToken = ''): ?array
    {
        $payload = [
            'url'             => $url,
            // We only care about messages — skip the noisy update types
            // (edited_channel_post, my_chat_member, …).
            'allowed_updates' => json_encode(['message'], JSON_THROW_ON_ERROR),
            // Drop any pending updates from before the bot was configured.
            'drop_pending_updates' => true,
        ];
        if ($secretToken !== '') {
            $payload['secret_token'] = $secretToken;
        }
        return $this->call('setWebhook', $payload);
    }

    /**
     * Remove the webhook. We don't need to delete pending updates — they're
     * already useless once the URL goes away.
     *
     * @return array<string,mixed>|null
     */
    public function deleteWebhook(): ?array
    {
        return $this->call('deleteWebhook', []);
    }

    /**
     * Returns webhook status (url, has_custom_certificate, pending_update_count, …).
     * Used by the settings UI to show the admin whether the webhook is live.
     *
     * @return array<string,mixed>|null
     */
    public function getWebhookInfo(): ?array
    {
        return $this->call('getWebhookInfo', []);
    }

    // ==================== HTML helpers ====================

    /**
     * Escape user-supplied text for Telegram's HTML parse_mode. Only the
     * five chars that Telegram cares about — anything else (Chinese, emoji,
     * punctuation) passes through untouched.
     */
    public static function escapeHtml(string $s): string
    {
        return strtr($s, [
            '&'  => '&amp;',
            '<'  => '&lt;',
            '>'  => '&gt;',
            '"'  => '&quot;',
            "'"  => '&#39;',
        ]);
    }

    // ==================== internal ====================

    /**
     * One-shot Bot API call.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function call(string $method, array $payload): ?array
    {
        if (!$this->isConfigured()) {
            Logger::error('TelegramApi.call: bot not configured', ['method' => $method]);
            return null;
        }
        $url = self::API_BASE . '/bot' . $this->token . '/' . $method;

        // We POST as application/x-www-form-urlencoded — sufficient for every
        // method we use (no file uploads from our side; the only direction is
        // download). Saves a JSON encoding pass.
        $body = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_USERAGENT      => 'LitePic-Telegram/1.0',
        ]);
        $raw      = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            Logger::error('TelegramApi.call: transport error', [
                'method' => $method, 'curl_err' => $err,
            ]);
            return null;
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            Logger::error('TelegramApi.call: non-JSON reply', [
                'method' => $method, 'http_code' => $httpCode, 'raw' => substr((string)$raw, 0, 200),
            ]);
            return null;
        }
        // Telegram's envelope is { ok: bool, result?: any, description?: string, error_code?: int }
        if (empty($decoded['ok'])) {
            Logger::error('TelegramApi.call: API returned not-ok', [
                'method'      => $method,
                'http_code'   => $httpCode,
                'description' => $decoded['description'] ?? '(none)',
                'error_code'  => $decoded['error_code']  ?? null,
            ]);
            return null;
        }
        $result = $decoded['result'] ?? null;
        // result can be `true` (e.g. setWebhook) or an object — always return as array.
        if ($result === true) return ['ok' => true];
        return is_array($result) ? $result : null;
    }
}
