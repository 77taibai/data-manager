<?php
declare(strict_types=1);

namespace Dlm\Support;

final class Flash
{
    public static function set(string $type, string $message): void
    {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function success(string $message): void
    {
        self::set('success', $message);
    }

    public static function error(string $message): void
    {
        self::set('error', $message);
    }

    public static function pull(): array
    {
        $items = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($items) ? $items : [];
    }

    public static function old(string $key): string
    {
        return (string) ($_SESSION['_old'][$key] ?? '');
    }

    public static function keepOld(array $data): void
    {
        $_SESSION['_old'] = $data;
    }

    public static function pullOld(): array
    {
        $old = $_SESSION['_old'] ?? [];
        unset($_SESSION['_old']);
        return is_array($old) ? $old : [];
    }
}
