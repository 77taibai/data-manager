<?php
declare(strict_types=1);

namespace Dlm\Support;

use Dlm\Models\AdminUser;

final class Auth
{
    public static function user(): ?array
    {
        if (empty($_SESSION['admin_id'])) {
            return null;
        }
        $user = AdminUser::find((int) $_SESSION['admin_id']);
        if ($user === null) {
            self::logout();
            return null;
        }
        return $user;
    }

    public static function check(): bool
    {
        return !empty($_SESSION['admin_id']);
    }

    public static function attempt(string $username, string $password): bool
    {
        $user = AdminUser::findByUsername($username);
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $user['id'];
        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION['admin_id']);
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
}
