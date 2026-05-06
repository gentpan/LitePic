<?php
declare(strict_types=1);

namespace LitePic\Core;

use LitePic\Repository\SettingsRepository;

/**
 * Config facade. The canonical store is now the SQLite `settings` table
 * (see SettingsRepository); `.env` is honoured only as a first-boot
 * fallback for keys that haven't been written yet.
 *
 * Bootstrap order:
 *   1. `Config::init('.env')` → load .env into $_ENV (cheap, no DB).
 *   2. `Database::init()` + migrations.
 *   3. `Config::warmSettings()` → snapshot the settings table into a
 *      static in-process cache. Subsequent reads (env_value, env_bool,
 *      env_csv, Config::get) consult the cache first, $_ENV second.
 *   4. `config.php` runs all `define('FOO', env_value('FOO', …))` —
 *      now reading from the DB cache without any caller changes.
 *
 * Writes (`Config::write([...])`) go straight to the DB and refresh
 * both the static cache and $_ENV for the current request.
 */
final class Config
{
    private static ?string $envPath = null;

    /** @var array<string,string>|null */
    private static ?array $settingsCache = null;

    private static bool $settingsAvailable = false;

    public static function init(string $envPath): void
    {
        self::$envPath = $envPath;
        self::load();
    }

    public static function envPath(): string
    {
        if (self::$envPath === null) {
            throw new \RuntimeException('Config::init() not called yet.');
        }
        return self::$envPath;
    }

    public static function load(): void
    {
        $path = self::envPath();
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $pos = strpos($line, '=');
            if ($pos === false || $pos === 0) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($key === '') {
                continue;
            }

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            $value = str_replace(['\\n', '\\r', '\\"', "\\'"], ["\n", "\r", '"', "'"], $value);

            if (function_exists('putenv')) {
                @putenv($key . '=' . $value);
            }
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /**
     * Snapshot the `settings` table into the in-process cache. Safe to
     * call multiple times (re-warm); cheap (one SELECT, returns the
     * whole row set as the table only ever holds the few dozen rows
     * the settings UI writes).
     *
     * Called from bootstrap.php after Database::init(). If the DB isn't
     * ready (e.g. a CLI script that runs before migrations on a fresh
     * install), warming silently no-ops and we fall back to .env-only.
     */
    public static function warmSettings(): void
    {
        try {
            $repo = new SettingsRepository();
            self::$settingsCache = $repo->all();
            self::$settingsAvailable = true;
        } catch (\Throwable $e) {
            self::$settingsCache = [];
            self::$settingsAvailable = false;
        }
    }

    /**
     * True once warmSettings() has successfully read from the DB.
     * Read paths can use this to decide whether to bother with cache
     * lookups vs going straight to $_ENV.
     */
    public static function settingsAvailable(): bool
    {
        return self::$settingsAvailable;
    }

    /**
     * Look up a settings cache entry. Returns null when the key is
     * missing — callers fall through to $_ENV.
     */
    public static function settingsLookup(string $key): ?string
    {
        if (self::$settingsCache === null) return null;
        if (!array_key_exists($key, self::$settingsCache)) return null;
        return self::$settingsCache[$key];
    }

    public static function get(string $key, $default = null)
    {
        if (self::$settingsCache !== null && array_key_exists($key, self::$settingsCache)) {
            $value = self::$settingsCache[$key];
        } else {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? (function_exists('getenv') ? getenv($key) : false);
        }
        return ($value === false || $value === '') ? $default : $value;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);
        return is_string($value) ? $value : (string)$value;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, null);
        return $value === null ? $default : (int)$value;
    }

    public static function bool(string $key, bool $default): bool
    {
        $value = self::get($key, null);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function csv(string $key, array $default = []): array
    {
        $value = self::get($key, null);
        if ($value === null) {
            return $default;
        }
        $items = array_map('trim', explode(',', (string)$value));
        $items = array_values(array_filter($items, static fn($v) => $v !== ''));
        return $items === [] ? $default : $items;
    }

    /**
     * Quote a value the way the legacy .env writer did. Kept on the
     * Config class for back-compat — call sites that build the update
     * map can keep using `Config::quote()` (and the `Format::envQuote`
     * alias) without knowing the underlying store changed.
     *
     * The DB writer doesn't actually need quoting (TEXT round-trips
     * any string), so on the DB write path we strip quotes back off
     * before persisting. This way old call sites that pre-quote keep
     * working, and DB rows stay clean (no leading/trailing quotes).
     */
    public static function quote(string $value): string
    {
        if ($value === '') {
            return '""';
        }
        $needsQuotes = preg_match('/[\s"\'#=\\\\]/', $value) === 1;
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
        $escaped = str_replace(["\r", "\n"], ['\\r', '\\n'], $escaped);
        return $needsQuotes ? '"' . $escaped . '"' : $value;
    }

    /**
     * Persist one or more settings to the DB. Updates the in-process
     * cache and $_ENV so subsequent reads in this request see the new
     * value (matches the legacy .env-write contract).
     *
     * Values that arrive pre-quoted (legacy callers route through
     * `Config::quote()` / `Format::envQuote()`) are unquoted before
     * persisting — DB rows always hold the raw string.
     *
     * Returns true on success, false on DB error.
     */
    public static function write(array $updates): bool
    {
        if ($updates === []) {
            return true;
        }

        $cleaned = [];
        foreach ($updates as $key => $raw) {
            $cleaned[(string)$key] = self::stripQuotes(trim((string)$raw));
        }

        try {
            (new SettingsRepository())->setMany($cleaned);
        } catch (\Throwable $e) {
            Logger::error('Settings write failed', ['error' => $e->getMessage()]);
            return false;
        }

        // Refresh in-process cache + $_ENV so the rest of this request
        // observes the new values without a re-warm round-trip.
        if (self::$settingsCache === null) {
            self::$settingsCache = [];
        }
        foreach ($cleaned as $key => $value) {
            self::$settingsCache[$key] = $value;
            if (function_exists('putenv')) {
                @putenv($key . '=' . $value);
            }
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
        self::$settingsAvailable = true;
        return true;
    }

    /**
     * One-shot import of every key present in $_ENV into the DB. Used
     * by the install/upgrade path on the first request after a fresh
     * SQLite migration: any value that came in via .env gets copied to
     * the `settings` table so future writes (which now go to DB) don't
     * silently shadow a stale .env value.
     *
     * Skips keys that already exist in the settings table — re-running
     * this is safe and idempotent.
     */
    public static function seedFromEnvIfEmpty(): int
    {
        try {
            $repo = new SettingsRepository();
            $existing = $repo->all();
        } catch (\Throwable $e) {
            return 0;
        }

        // Only seed when the table is genuinely empty — otherwise the
        // DB is the source of truth and .env is just first-boot debris.
        if ($existing !== []) {
            return 0;
        }

        $candidates = self::candidateEnvKeys();
        $payload = [];
        foreach ($candidates as $key) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
            if (!is_string($value) || $value === '') continue;
            $payload[$key] = self::stripQuotes($value);
        }

        if ($payload === []) {
            return 0;
        }

        try {
            $repo->setMany($payload);
        } catch (\Throwable $e) {
            Logger::error('Settings seed-from-env failed', ['error' => $e->getMessage()]);
            return 0;
        }

        // Re-warm so this request sees the seeded values.
        self::warmSettings();
        return count($payload);
    }

    /**
     * Allowlist of UPPERCASE_SNAKE keys we know are settings (vs. PHP
     * locals or unrelated env vars). Used by seedFromEnvIfEmpty() to
     * avoid copying random shell-level $_ENV junk into the DB.
     *
     * The list mirrors what config.php reads — when you add a new
     * `env_value('FOO', …)` call there, add FOO here too.
     *
     * @return array<int,string>
     */
    private static function candidateEnvKeys(): array
    {
        return [
            'SITE_NAME', 'SITE_DESCRIPTION', 'SITE_URL', 'SITE_VERSION',
            'ITEMS_PER_PAGE', 'GALLERY_COLUMNS',
            'MAX_FILE_SIZE_MB', 'UPLOAD_ALLOWED_TYPES',
            'COOKIE_SECURE', 'ADMIN_API_KEY', 'THIRD_PARTY_API_KEYS',
            'AUTO_COMPRESS_ON_UPLOAD',
            'AUTO_CONVERT_ON_UPLOAD', 'AUTO_CONVERT_WEBP_ON_UPLOAD', 'AUTO_CONVERT_AVIF_ON_UPLOAD',
            'CONVERT_PREFERRED_FORMAT', 'KEEP_ORIGINAL_AFTER_PROCESS',
            'COMPRESSION_MODE', 'CONVERSION_ENGINE',
            'WEBP_QUALITY', 'AVIF_QUALITY', 'IMAGE_PROCESS_MAX_PIXELS',
            'WATERMARK_ENABLED', 'WATERMARK_TYPE', 'WATERMARK_TEXT',
            'WATERMARK_POSITION', 'WATERMARK_OPACITY', 'WATERMARK_FONT_SIZE',
            'WATERMARK_MARGIN', 'WATERMARK_COLOR', 'WATERMARK_FONT_PATH',
            'WATERMARK_IMAGE_PATH', 'WATERMARK_IMAGE_WIDTH',
            'WATERMARK_PANEL_ENABLED', 'WATERMARK_PANEL_OPACITY',
            'WATERMARK_PANEL_PADDING', 'WATERMARK_PANEL_RADIUS',
            'HOTLINK_PROTECTION_ENABLED', 'HOTLINK_ALLOWED_DOMAINS',
            'HOTLINK_ALLOW_EMPTY_REFERER',
            'IMAGE_VIEW_COUNTER_ENABLED', 'URL_PREFIX',
            'DB_BACKUP_ENABLED', 'DB_BACKUP_INTERVAL_HOURS',
            'DB_BACKUP_KEEP_COUNT', 'DB_BACKUP_TO_REMOTE',
            'REMOTE_STORAGE_USAGE', 'REMOTE_STORAGE_MODE',
            'S3_PROVIDER', 'S3_BUCKET', 'S3_REGION', 'S3_ENDPOINT',
            'S3_KEY', 'S3_SECRET', 'S3_PATH_PREFIX', 'S3_PUBLIC_BASE_URL',
            'MANAGED_API_TOKENS_JSON',
            'DEBUG',
        ];
    }

    private static function stripQuotes(string $value): string
    {
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        return str_replace(['\\n', '\\r', '\\"', "\\'"], ["\n", "\r", '"', "'"], $value);
    }
}
