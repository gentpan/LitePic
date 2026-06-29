<?php
declare(strict_types=1);

namespace LitePic\Core;

use Throwable;

/**
 * Run a callback AFTER the HTTP response has been flushed to the client.
 *
 * Used by the upload endpoint to enqueue heavy image processing
 * (thumbnail / compress / webp / avif) without making the user wait —
 * the response goes out immediately and the worker drains the queue
 * in the same PHP process while the browser sees the upload as done.
 *
 * On nginx + PHP-FPM, `fastcgi_finish_request()` is the clean detach path:
 * the FastCGI connection closes, PHP keeps running until the deferred
 * worker finishes.
 *
 * Both paths set `ignore_user_abort(true)` and remove the time limit
 * so the worker keeps running even if the browser disconnects.
 *
 * Errors in the deferred callback are swallowed and logged — by the
 * time it runs the response is already out, throwing would just kill
 * the worker silently. Logger writes to data/logs and surfaces in the
 * settings → system tab.
 */
final class ResponseDetacher
{
    /**
     * Send the current response and continue executing $work in the
     * same PHP process after the client has disconnected.
     *
     * Safe to call from CLI (just runs $work synchronously) so cron
     * scripts and tests don't need a special path.
     */
    public static function runAfterResponse(callable $work): void
    {
        // CLI: no HTTP response to flush, just run inline.
        if (PHP_SAPI === 'cli') {
            self::invoke($work);
            return;
        }

        @ignore_user_abort(true);
        @set_time_limit(0);

        if (function_exists('fastcgi_finish_request')) {
            // FPM-clean detach
            try {
                @fastcgi_finish_request();
            } catch (Throwable $e) {
                // Some FPM setups throw on already-finished — ignore
            }
            self::invoke($work);
            return;
        }

        // Generic fallback for unusual SAPIs — manually close the connection.
        self::flushAndCloseConnection();
        self::invoke($work);
    }

    /**
     * Best-effort connection close for non-FPM SAPIs. Sends
     * Content-Length + Connection: close, flushes output buffers,
     * and writes the session so the lock is released.
     *
     * Caveats:
     *   • If the response is already partially flushed (chunked
     *     encoding kicked in), Content-Length is wrong — most
     *     browsers / proxies tolerate this but it's not guaranteed.
     *   • If response_compression is on (gzip mod), browsers may
     *     wait for more bytes than we declare — disable buffering
     *     before calling for upload endpoints.
     */
    private static function flushAndCloseConnection(): void
    {
        // Suppress further header changes
        if (headers_sent()) {
            // Already streamed — just flush and hope for the best
            while (ob_get_level() > 0) @ob_end_flush();
            @flush();
            return;
        }

        // Capture current buffer length so we can declare Content-Length
        $body = '';
        while (ob_get_level() > 0) {
            $chunk = ob_get_clean();
            if (is_string($chunk)) $body .= $chunk;
        }

        @header('Connection: close');
        @header('Content-Length: ' . strlen($body));
        @header('Content-Encoding: identity'); // disable gzip/br for this response

        echo $body;
        @flush();
        @session_write_close();
    }

    /**
     * Invoke the deferred work with hardened error handling.
     * The response is already out — any throw just gets logged.
     */
    private static function invoke(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            try {
                Logger::error('ResponseDetacher deferred work failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            } catch (Throwable $_) {
                // Logger itself broken — nothing we can do at this point
            }
        }
    }
}
