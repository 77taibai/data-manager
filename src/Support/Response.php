<?php
declare(strict_types=1);

namespace Dlm\Support;

final class Response
{
    public static function json(mixed $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function apiOk(mixed $data = null, string $message = 'ok', int $status = 200): void
    {
        self::json([
            'code' => 0,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function apiError(string $message, int $code = 4000, int $status = 400, mixed $data = null): void
    {
        self::json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function html(string $html, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo $html;
    }

    public static function redirect(string $to, int $status = 302): void
    {
        if (!headers_sent()) {
            header('Location: ' . $to, true, $status);
        }
        exit;
    }

    public static function download(string $filename, string $content, string $contentType = 'text/csv; charset=utf-8'): void
    {
        if (!headers_sent()) {
            header('Content-Type: ' . $contentType);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($content));
            header('Cache-Control: no-store');
        }
        echo $content;
        exit;
    }
}
