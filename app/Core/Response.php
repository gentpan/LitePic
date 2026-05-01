<?php
declare(strict_types=1);

namespace LitePic\Core;

use Throwable;

final class Response
{
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success(array $data = []): void
    {
        $payload = ['status' => 'success'] + $data;
        self::json($payload);
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        $payload = ['status' => 'error', 'message' => $message] + $extra;
        self::json($payload, $status);
    }

    public static function safeMessage(Throwable $e): string
    {
        if (Config::bool('DEBUG', false)) {
            return $e->getMessage();
        }
        return '操作失败，请稍后重试';
    }
}
