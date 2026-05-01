<?php
declare(strict_types=1);

namespace LitePic\Core;

final class Logger
{
    private static ?string $logDir = null;

    public static function init(string $logDir): void
    {
        self::$logDir = rtrim($logDir, DIRECTORY_SEPARATOR);
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0755, true);
        }
    }

    public static function debug(string $message, $context = null): void
    {
        if (!Config::bool('DEBUG', false)) {
            return;
        }
        self::write('debug', $message, $context);
    }

    public static function info(string $message, $context = null): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, $context = null): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, $context = null): void
    {
        self::write('error', $message, $context);
    }

    private static function write(string $level, string $message, $context): void
    {
        if (self::$logDir === null) {
            return;
        }
        $line = sprintf(
            "[%s] [%s] %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $context === null ? '' : ' ' . self::encodeContext($context)
        );
        @file_put_contents(self::$logDir . DIRECTORY_SEPARATOR . 'app.log', $line, FILE_APPEND | LOCK_EX);
    }

    private static function encodeContext($context): string
    {
        if (is_string($context)) {
            return $context;
        }
        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '<unserializable>' : $json;
    }
}
