<?php
declare(strict_types=1);

namespace Dlm\Support;

final class Config
{
    private static array $data = [];

    public static function load(string $file): void
    {
        if (is_file($file)) {
            $cfg = require $file;
            if (is_array($cfg)) {
                self::$data = $cfg;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $cursor = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$part];
        }
        return $cursor;
    }

    public static function installed(): bool
    {
        return (bool) self::get('installed', false);
    }

    public static function secret(): string
    {
        return (string) (self::get('app_secret') ?: '');
    }

    public static function all(): array
    {
        return self::$data;
    }
}
