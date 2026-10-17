<?php
declare(strict_types=1);

namespace Dlm\Models;

use Dlm\Support\Db;
use Throwable;

final class Setting
{
    public static function get(string $key, ?string $default = null): ?string
    {
        try {
            $value = Db::value('SELECT `value` FROM `settings` WHERE `key` = :k', [':k' => $key]);
        } catch (Throwable) {
            return $default;
        }
        return $value === null ? $default : (string) $value;
    }

    public static function set(string $key, string $value): void
    {
        Db::run(
            'INSERT INTO `settings` (`key`, `value`) VALUES (:k, :v) '
            . 'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [':k' => $key, ':v' => $value]
        );
    }
}
