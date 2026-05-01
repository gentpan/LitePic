<?php
declare(strict_types=1);

namespace LitePic\Core;

/**
 * Reads/writes the application's `.env` file and exposes typed accessors.
 *
 * Constants defined in `config.php` remain authoritative for read paths that
 * still depend on them; `Config::write()` updates `.env` and refreshes the
 * runtime environment so subsequent reads see the new value.
 */
final class Config
{
    private static ?string $envPath = null;

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

    public static function get(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? (function_exists('getenv') ? getenv($key) : false);
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
     * Quote a value for safe insertion into the `.env` file.
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
     * Update one or more keys in `.env`. Existing keys are replaced in-place;
     * new keys are appended. Values are written verbatim — the caller is
     * expected to call `Config::quote()` if needed.
     */
    public static function write(array $updates): bool
    {
        if ($updates === []) {
            return true;
        }
        $path = self::envPath();
        $lines = [];
        if (is_file($path)) {
            $existing = file($path, FILE_IGNORE_NEW_LINES);
            if ($existing !== false) {
                $lines = $existing;
            }
        }

        $remaining = $updates;
        foreach ($lines as $i => $line) {
            if (!is_string($line)) continue;
            if (!preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $m)) continue;
            $key = $m[1];
            if (!array_key_exists($key, $remaining)) continue;
            $lines[$i] = $key . '=' . (string)$remaining[$key];
            unset($remaining[$key]);
        }

        if ($remaining !== []) {
            if ($lines !== [] && trim((string)end($lines)) !== '') {
                $lines[] = '';
            }
            foreach ($remaining as $key => $value) {
                $lines[] = $key . '=' . (string)$value;
            }
        }

        $content = implode(PHP_EOL, $lines);
        if ($content !== '' && !str_ends_with($content, PHP_EOL)) {
            $content .= PHP_EOL;
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        // Refresh in-process env so subsequent reads see new values.
        foreach ($updates as $key => $raw) {
            $clean = self::stripQuotes(trim((string)$raw));
            if (function_exists('putenv')) {
                @putenv($key . '=' . $clean);
            }
            $_ENV[$key] = $clean;
            $_SERVER[$key] = $clean;
        }
        return true;
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
