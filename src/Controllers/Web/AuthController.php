<?php
declare(strict_types=1);

namespace Dlm\Controllers\Web;

use Dlm\Support\Auth;
use Dlm\Support\Config;
use Dlm\Support\Flash;
use Dlm\Support\Request;
use Dlm\Support\Response;
use Dlm\Support\View;

final class AuthController
{
    public function showLogin(Request $request): void
    {
        if (!Config::installed()) {
            Response::redirect('/install');
            return;
        }
        if (Auth::check()) {
            Response::redirect('/');
            return;
        }
        View::render('auth/login', [
            'title' => '登录',
            'error' => null,
            'username' => Flash::old('username'),
        ], 'layout_blank');
    }

    public function login(Request $request): void
    {
        if (!Config::installed()) {
            Response::redirect('/install');
            return;
        }
        $username = trim((string) $request->input('username', ''));
        $password = (string) $request->input('password', '');

        if ($username === '' || $password === '' || !Auth::attempt($username, $password)) {
            Flash::keepOld(['username' => $username]);
            View::render('auth/login', [
                'title' => '登录',
                'error' => '账号或密码错误',
                'username' => $username,
            ], 'layout_blank');
            return;
        }

        $intended = (string) ($_SESSION['intended'] ?? '/');
        unset($_SESSION['intended']);
        if ($intended === '' || str_starts_with($intended, '/login')) {
            $intended = '/';
        }
        Flash::success('欢迎回来');
        Response::redirect($intended);
    }

    public function logout(Request $request): void
    {
        Auth::logout();
        Flash::success('已退出登录');
        Response::redirect('/login');
    }
}
