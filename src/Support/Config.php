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

    /**
     * 管理员密码哈希（config.php 中的 admin.password_hash）。
     */
    public static function passwordHash(): string
    {
        return (string) (self::get('admin.password_hash') ?: '');
    }

    /**
     * 管理员明文密码（config.php 中的 admin.password，仅用于手工快速设置）。
     */
    public static function plainPassword(): string
    {
        return (string) (self::get('admin.password') ?: '');
    }

    public static function all(): array
    {
        return self::$data;
    }

    /**
     * OIDC 配置（config.php 中的 oidc 段），缺省值齐全，便于直接调用。
     *
     * @return array{enabled:bool, issuer:string, client_id:string, client_secret:string, scope:string, allowed_subs:string[]}
     */
    public static function oidc(): array
    {
        $raw = self::get('oidc', []);
        if (!is_array($raw)) {
            $raw = [];
        }
        $scope = $raw['scope'] ?? 'openid email profile';
        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'issuer' => trim((string) ($raw['issuer'] ?? '')),
            'client_id' => trim((string) ($raw['client_id'] ?? '')),
            'client_secret' => (string) ($raw['client_secret'] ?? ''),
            'scope' => trim((string) $scope) !== '' ? trim((string) $scope) : 'openid email profile',
            'allowed_subs' => self::oidcAllowedSubs(),
        ];
    }

    /**
     * 允许登录的 sub 名单（config.php 中的 oidc.allowed_subs，也兼容单个 oidc.allowed_sub）。
     *
     * @return string[]
     */
    public static function oidcAllowedSubs(): array
    {
        $raw = self::get('oidc.allowed_subs', null);
        if ($raw === null) {
            $single = (string) (self::get('oidc.allowed_sub', '') ?: '');
            return $single !== '' ? [$single] : [];
        }
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $subs = [];
        foreach ($raw as $item) {
            $sub = trim((string) $item);
            if ($sub !== '') {
                $subs[] = $sub;
            }
        }
        return $subs;
    }
}
