<?php
declare(strict_types=1);

namespace Dlm\Support;

/**
 * 单用户系统：只有一个管理员密码，密码保存在 config.php 中，不落数据库。
 *
 * config.php 中支持两种写法：
 *   'admin' => ['password_hash' => '<password_hash() 结果>']  // 推荐
 *   'admin' => ['password' => '明文密码']                      // 便捷，登录时自动比对
 */
final class Auth
{
    public static function check(): bool
    {
        if (empty($_SESSION['admin_authed'])) {
            return false;
        }
        $sub = self::oidcSub();
        if ($sub !== null && !Oidc::isAllowedSub($sub)) {
            // 白名单已被改动（或 OIDC 被关闭），立即失效
            self::logout();
            return false;
        }
        return true;
    }

    /**
     * 配置文件中是否已设置管理密码。
     */
    public static function hasPassword(): bool
    {
        return Config::passwordHash() !== '' || Config::plainPassword() !== '';
    }

    public static function attempt(string $password): bool
    {
        if ($password === '' || !self::verify($password)) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['admin_authed'] = true;
        unset($_SESSION['admin_oidc_sub']);
        return true;
    }

    /**
     * OIDC 登录成功后建立会话。调用前必须已确认 sub 在白名单内。
     */
    public static function loginViaOidc(string $sub): void
    {
        session_regenerate_id(true);
        $_SESSION['admin_authed'] = true;
        $_SESSION['admin_oidc_sub'] = $sub;
    }

    /**
     * 当前会话是否由 OIDC 建立；返回登录时的 sub，密码登录返回 null。
     */
    public static function oidcSub(): ?string
    {
        $sub = $_SESSION['admin_oidc_sub'] ?? null;
        return is_string($sub) && $sub !== '' ? $sub : null;
    }

    public static function logout(): void
    {
        unset($_SESSION['admin_authed'], $_SESSION['admin_oidc_sub']);
    }

    public static function requireAdmin(): void
    {
        if (self::check()) {
            return;
        }
        $target = '/login';
        if (!Request::isApiRequest()) {
            $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? '/';
        }
        Response::redirect($target);
    }

    private static function verify(string $password): bool
    {
        $hash = Config::passwordHash();
        if ($hash !== '') {
            return password_verify($password, $hash);
        }
        $plain = Config::plainPassword();
        return $plain !== '' && hash_equals($plain, $password);
    }
}
