<?php
declare(strict_types=1);

namespace Dlm\Support;

use Dlm\Models\ApiKey;

final class ApiAuth
{
    private static ?array $key = null;
    private static ?array $repository = null;

    public static function authenticate(Request $request): void
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            throw ApiException::unauthorized('缺少 APIKEY，请使用 Authorization: Bearer <key>');
        }

        $key = ApiKey::findByToken($token);
        if ($key === null) {
            throw ApiException::unauthorized('APIKEY 无效或已过期');
        }

        self::$key = $key;
        self::$repository = [
            'id' => (int) $key['repository_id'],
            'name' => $key['repository_name'] ?? null,
            'slug' => $key['repository_slug'] ?? null,
        ];
    }

    public static function key(): array
    {
        if (self::$key === null) {
            throw ApiException::unauthorized();
        }
        return self::$key;
    }

    public static function repository(): array
    {
        if (self::$repository === null) {
            throw ApiException::unauthorized();
        }
        return self::$repository;
    }

    public static function repositoryId(): int
    {
        return (int) self::repository()['id'];
    }

    public static function canWrite(): bool
    {
        return self::key()['permissions'] === 'write';
    }

    public static function requireWrite(): void
    {
        if (!self::canWrite()) {
            throw ApiException::forbidden('该 APIKEY 仅有只读权限');
        }
    }
}
