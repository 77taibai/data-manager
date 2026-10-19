<?php
declare(strict_types=1);

namespace Dlm\Controllers\Web;

use Dlm\Support\Auth;
use Dlm\Support\Config;
use Dlm\Support\Flash;
use Dlm\Support\Helpers;
use Dlm\Support\Oidc;
use Dlm\Support\OidcException;
use Dlm\Support\Request;
use Dlm\Support\Response;
use Dlm\Support\View;
use Throwable;

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
        // 只配置了 OIDC 时没有密码是正常状态，不需要提示
        $error = null;
        if (!Auth::hasPassword() && !Oidc::enabled()) {
            $error = '配置文件 config.php 中尚未设置管理密码';
        }
        $this->renderLogin(['error' => $error]);
    }

    public function login(Request $request): void
    {
        if (!Config::installed()) {
            Response::redirect('/install');
            return;
        }
        if (!Auth::hasPassword()) {
            // 只配置了 OIDC 时密码登录不可用，直接回到登录页（页面上只有 OIDC 按钮）
            if (Oidc::enabled()) {
                Response::redirect('/login');
                return;
            }
            $this->renderLogin(['error' => '配置文件 config.php 中尚未设置管理密码']);
            return;
        }

        $password = (string) $request->input('password', '');

        if ($password === '' || !Auth::attempt($password)) {
            $this->renderLogin(['error' => '密码错误']);
            return;
        }

        $this->redirectIntended();
    }

    /**
     * 跳转到身份提供方的授权端点。
     */
    public function oidcStart(Request $request): void
    {
        if (!Config::installed()) {
            Response::redirect('/install');
            return;
        }
        if (!Oidc::enabled()) {
            Flash::error('未启用 OIDC 登录');
            Response::redirect('/login');
            return;
        }
        if (Auth::check()) {
            Response::redirect('/');
            return;
        }

        try {
            $url = Oidc::buildAuthorizeUrl($this->callbackUrl());
        } catch (Throwable $e) {
            $this->logOidcError($e);
            $this->renderLogin(['error' => '无法连接身份提供方：' . $e->getMessage()]);
            return;
        }
        Response::redirect($url);
    }

    /**
     * 身份提供方回调。
     */
    public function oidcCallback(Request $request): void
    {
        if (!Config::installed()) {
            Response::redirect('/install');
            return;
        }
        if (!Oidc::enabled()) {
            Flash::error('未启用 OIDC 登录');
            Response::redirect('/login');
            return;
        }

        $error = (string) $request->query('error', '');
        if ($error !== '') {
            $desc = (string) $request->query('error_description', '');
            $this->renderLogin(['error' => '身份提供方返回错误：' . $error . ($desc !== '' ? '（' . $desc . '）' : '')]);
            return;
        }

        $code = (string) $request->query('code', '');
        $state = (string) $request->query('state', '');
        if ($code === '') {
            $this->renderLogin(['error' => '回调缺少 code 参数']);
            return;
        }

        // 会话里记的 redirect_uri 可能在回调前丢失，这里先补上再交给 Oidc 处理
        if (empty($_SESSION['oidc_redirect'])) {
            $_SESSION['oidc_redirect'] = $this->callbackUrl();
        }

        try {
            $result = Oidc::handleCallback($code, $state);
        } catch (Throwable $e) {
            $this->logOidcError($e);
            $this->renderLogin(['error' => 'OIDC 登录失败：' . $e->getMessage()]);
            return;
        }

        if (!Oidc::isAllowedSub($result['sub'])) {
            $this->logOidcError(new OidcException('sub ' . $result['sub'] . ' 不在白名单内', 'acl'));
            $this->renderLogin([
                'error' => '该账号（sub=' . $result['sub'] . '）未被授权访问本系统',
            ]);
            return;
        }

        Auth::loginViaOidc($result['sub']);
        Flash::success('欢迎回来');
        $this->redirectIntended();
    }

    public function logout(Request $request): void
    {
        Auth::logout();
        Flash::success('已退出登录');
        Response::redirect('/login');
    }

    private function renderLogin(array $data = []): void
    {
        View::render('auth/login', [
            'title' => '登录',
            'error' => $data['error'] ?? null,
            'hasPassword' => Auth::hasPassword(),
            'oidcEnabled' => Oidc::enabled(),
        ], 'layout_blank');
    }

    private function redirectIntended(): void
    {
        $intended = (string) ($_SESSION['intended'] ?? '/');
        unset($_SESSION['intended']);
        if ($intended === '' || str_starts_with($intended, '/login')) {
            $intended = '/';
        }
        Response::redirect($intended);
    }

    /**
     * 回调地址必须与身份提供方登记的完全一致。
     * 优先使用 oidc.redirect_uri 配置（反向代理/多域名场景），否则按当前请求推导。
     */
    private function callbackUrl(): string
    {
        $configured = (string) (Config::get('oidc.redirect_uri', '') ?: '');
        return $configured !== '' ? $configured : Helpers::absolute('/oidc/callback');
    }

    private function logOidcError(Throwable $e): void
    {
        error_log('[DLM][oidc] ' . $e->getMessage()
            . ($e instanceof OidcException && $e->reason !== '' ? ' (' . $e->reason . ')' : ''));
    }
}
