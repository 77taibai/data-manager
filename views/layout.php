<?php
declare(strict_types=1);

use Dlm\Support\Auth;
use Dlm\Support\Csrf;
use Dlm\Support\Flash;
use Dlm\Support\Helpers;
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
                var t = localStorage.getItem('dlm-theme');
                if (!t) {
                    t = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                }
                document.documentElement.setAttribute('data-theme', t);
            } catch (e) {}
        })();
    </script>
</head>
<body>
<header class="app-header">
    <a class="brand" href="<?= Helpers::url('/') ?>">下载链接管理系统</a>
    <nav class="app-nav">
        <a href="<?= Helpers::url('/') ?>">存储库</a>
        <a href="<?= Helpers::url('/keys') ?>">APIKEY</a>
    </nav>
    <div class="app-header-right">
        <button type="button" id="theme-toggle" class="btn btn-ghost" title="切换深色/浅色">主题</button>
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
<script src="<?= Helpers::asset('js/app.js') ?>"></script>
</body>
</html>
