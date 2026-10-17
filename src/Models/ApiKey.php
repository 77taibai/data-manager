<?php
declare(strict_types=1);

namespace Dlm\Models;

use Dlm\Support\Db;

final class ApiKey
{
    public const PERMISSIONS = ['read', 'write'];

    public static function generateToken(): string
    {
        return 'dlm_' . bin2hex(random_bytes(20));
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function prefix(string $token): string
    {
        return substr($token, 0, 12);
    }

    public static function create(int $repositoryId, ?string $name, string $permissions, ?string $expiresAt): array
    {
        $token = self::generateToken();
        $id = Db::insert('api_keys', [
            'repository_id' => $repositoryId,
            'name' => $name,
            'key_hash' => self::hash($token),
            'key_prefix' => self::prefix($token),
            'permissions' => $permissions,
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['id' => $id, 'token' => $token, 'prefix' => self::prefix($token)];
    }

    public static function all(): array
    {
        return Db::all(
            'SELECT k.*, r.name AS repository_name, r.slug AS repository_slug '
            . 'FROM `api_keys` k LEFT JOIN `repositories` r ON r.id = k.repository_id '
            . 'ORDER BY k.id DESC'
        );
    }

    public static function forRepository(int $repositoryId): array
    {
        return Db::all(
            'SELECT * FROM `api_keys` WHERE `repository_id` = :r ORDER BY `id` DESC',
            [':r' => $repositoryId]
        );
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM `api_keys` WHERE `id` = :id', [':id' => $id]);
    }

    public static function revoke(int $id): void
    {
        Db::delete('api_keys', '`id` = :id', [':id' => $id]);
    }

    public static function findByToken(string $token): ?array
    {
        $row = Db::one(
            'SELECT k.*, r.name AS repository_name, r.slug AS repository_slug '
            . 'FROM `api_keys` k LEFT JOIN `repositories` r ON r.id = k.repository_id '
            . 'WHERE k.`key_hash` = :h',
            [':h' => self::hash($token)]
        );
        if ($row === null) {
            return null;
        }
        if ($row['expires_at'] !== null && strtotime((string) $row['expires_at']) < time()) {
            return null;
        }
        return $row;
    }
}
