<?php
declare(strict_types=1);

namespace Dlm\Models;

use Dlm\Support\Db;

final class AdminUser
{
    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM `admin_users` WHERE `id` = :id', [':id' => $id]);
    }

    public static function findByUsername(string $username): ?array
    {
        return Db::one('SELECT * FROM `admin_users` WHERE `username` = :u', [':u' => $username]);
    }

    public static function count(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM `admin_users`');
    }

    public static function create(string $username, string $password): int
    {
        return Db::insert('admin_users', [
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
