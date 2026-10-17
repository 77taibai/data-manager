<?php
declare(strict_types=1);

namespace Dlm\Support;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_token" value="' . Helpers::e(self::token()) . '">';
    }

    public static function isValid(Request $request): bool
    {
        $expected = (string) ($_SESSION['_csrf'] ?? '');
        if ($expected === '') {
            return false;
        }
        $given = (string) ($request->input('_token') ?? $request->header('x-csrf-token', ''));
        return $given !== '' && hash_equals($expected, $given);
    }

    public static function assertOrFail(Request $request): void
    {
        if (!self::isValid($request)) {
            http_response_code(419);
            Response::html('<h1>419 页面已过期</h1><p>请返回并重新提交。</p>', 419);
            exit;
        }
    }
}
