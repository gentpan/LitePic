<?php
declare(strict_types=1);

/**
 * Telegram bot integration — settings + per-user state.
 *
 * What this migration does:
 *   1. Seed default values for the TELEGRAM_* config keys into the `settings`
 *      table. If a key already exists (e.g. somebody re-ran migrations after
 *      configuring the bot), we leave it alone — INSERT OR IGNORE.
 *   2. Create the `telegram_user_state` table — one row per Telegram user_id,
 *      holding the user's currently-selected default album destination plus
 *      bookkeeping timestamps. This is how `/use <album>` "sticks" between
 *      messages.
 *
 * The settings keys themselves (TOKEN, SECRET, ALLOWED_USER_IDS, etc.) live
 * in the `settings` table under env-style names so the rest of the app can
 * read them via `Config::get()` — same access pattern as every other knob.
 *
 * Idempotent: every INSERT uses OR IGNORE; CREATE TABLE uses IF NOT EXISTS.
 */
return function (PDO $pdo): void {
    // ---- settings defaults ----------------------------------------------------
    // Insert the default values only if the row doesn't already exist. We use
    // the same `settings(key, value)` schema as migration 009; that table is
    // the durable backing store that .env file loading + Config::get() check.
    $defaults = [
        'TELEGRAM_ENABLED'          => 'false',
        'TELEGRAM_BOT_TOKEN'        => '',
        'TELEGRAM_WEBHOOK_SECRET'   => '', // auto-generated when admin clicks "register webhook"
        'TELEGRAM_ALLOWED_USER_IDS' => '', // comma-separated whitelist; empty = nobody allowed
        'TELEGRAM_DEFAULT_ALBUM_KEY' => '', // optional global default; per-user overrides win
    ];
    $stmt = $pdo->prepare(
        'INSERT OR IGNORE INTO settings (key, value, updated_at) VALUES (:k, :v, :t)'
    );
    $now = time();
    foreach ($defaults as $key => $value) {
        $stmt->execute([':k' => $key, ':v' => $value, ':t' => $now]);
    }

    // ---- per-user state -------------------------------------------------------
    // user_id is Telegram's numeric user id (int64-shaped, but well within
    // PHP int range on 64-bit platforms). We store the URL key (slug-or-id)
    // for the user's chosen album so we don't have to resolve it on every
    // incoming message; resolved at write time and only when the user runs
    // `/use <album>`.
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS telegram_user_state (
            user_id           INTEGER PRIMARY KEY,
            default_album_key TEXT,
            last_seen_at      INTEGER NOT NULL,
            created_at        INTEGER NOT NULL
        )
    SQL);
};
