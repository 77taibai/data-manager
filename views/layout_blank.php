<?php
declare(strict_types=1);

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
                var pref = localStorage.getItem('dlm-theme') || 'auto';
                var mql = window.matchMedia('(prefers-color-scheme: dark)');
                var resolved = pref === 'auto' ? (mql.matches ? 'dark' : 'light') : pref;
                document.documentElement.setAttribute('data-theme', resolved);
                document.documentElement.setAttribute('data-theme-pref', pref);
            } catch (e) {}
        })();
    </script>
</head>
<body class="layout-blank">
<div class="blank-wrap">
    <?= $content ?>
</div>
<script src="<?= Helpers::asset('js/app.js') ?>"></script>
</body>
</html>
