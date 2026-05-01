<?php
declare(strict_types=1);

namespace LitePic\Core;

/**
 * Tiny formatter helpers used in templates / logs.
 */
final class Format
{
    /**
     * "1024 -> '1 KB'", with K/M/G/T/P units and a trailing-zero trim.
     * Matches the legacy `format_filesize()` exactly.
     */
    public static function filesize($bytes): string
    {
        $size = (float)$bytes;
        if (!is_finite($size) || $size <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $base = 1024;
        $i = (int)floor(log($size, $base));
        $i = max(0, min($i, count($units) - 1));
        $value = $size / pow($base, $i);
        $formatted = number_format($value, 1);
        $formatted = rtrim(rtrim($formatted, '0'), '.');
        return $formatted . ' ' . $units[$i];
    }

    /**
     * Quote a value for direct insertion into the .env file. Wraps the
     * value in double quotes and escapes backslash, quote, CR and LF.
     */
    public static function envQuote(string $value): string
    {
        return '"' . str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value) . '"';
    }
}
