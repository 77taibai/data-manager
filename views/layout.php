<?php
declare(strict_types=1);

use Dlm\Support\Auth;
use Dlm\Support\Csrf;
use Dlm\Support\Flash;
use Dlm\Support\Helpers;

$navPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$isRepoNav = $navPath === '/' || str_starts_with($navPath, '/repos');
$isKeysNav = str_starts_with($navPath, '/keys');
?>
<!doctype html>
<html lang="zh-CN" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Helpers::e($title ?? '下载链接管理系统') ?></title>
    <link rel="stylesheet" href="<?= Helpers::asset('css/app.css') ?>">
    <script>
        (function () {
            try {
                var pref = localStorage.getItem('dlm-theme') || 'auto';
                var mql = window.matchMedia('(prefers-color-scheme: dark)');
                var resolved = pref === 'auto' ? (mql.matches ? 'dark' : 'light') : pref;
                document.documentElement.setAttribute('data-theme', resolved);
                document.documentElement.setAttribute('data-theme-pref', pref);
            } catch (e) {}
        })();
    </script>
</head>
<body>
<header class="app-header">
    <a class="brand" href="<?= Helpers::url('/') ?>">下载链接管理系统</a>
    <nav class="app-nav">
        <a href="<?= Helpers::url('/') ?>" class="<?= $isRepoNav ? 'active' : '' ?>">存储库</a>
        <a href="<?= Helpers::url('/keys') ?>" class="<?= $isKeysNav ? 'active' : '' ?>">APIKEY</a>
    </nav>
    <div class="app-header-right">
        <button type="button" id="theme-toggle" class="btn btn-ghost" title="切换主题">跟随系统</button>
        <?php if (Auth::check()): ?>
            <span class="who"><?= Helpers::e((string) ((Auth::user()['username'] ?? ''))) ?></span>
            <form method="post" action="<?= Helpers::url('/logout') ?>" class="inline-form">
                <?= Csrf::field() ?>
                <button type="submit" class="btn btn-ghost">退出</button>
            </form>
        <?php endif; ?>
    </div>
</header>
<main class="app-main">
    <?php foreach (Flash::pull() as $flash): ?>
        <div class="flash flash-<?= Helpers::e($flash['type']) ?>"><?= Helpers::e($flash['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>

<div class="modal-backdrop" id="confirm-modal" hidden>
    <div class="modal modal-sm" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
        <div class="modal-head">
            <h2 id="confirm-title" data-confirm-title>确认操作</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-confirm-cancel>关</button>
        </div>
        <p class="confirm-message" data-confirm-message></p>
        <div class="form-actions">
            <button type="button" class="btn" data-confirm-cancel>取消</button>
            <button type="button" class="btn btn-danger" data-confirm-ok>确定</button>
        </div>
    </div>
</div>

<script src="<?= Helpers::asset('js/app.js') ?>"></script>
</body>
</html>
