<?php
declare(strict_types=1);

use Dlm\Support\Csrf;
use Dlm\Support\Helpers;

$hasPassword = $hasPassword ?? true;
$oidcEnabled = $oidcEnabled ?? false;
?>
<div class="auth-card auth-card-sm">
    <h1>登录</h1>

    <?php if (!empty($error)): ?>
        <div class="flash flash-error"><?= Helpers::e((string) $error) ?></div>
    <?php endif; ?>

    <?php if ($oidcEnabled): ?>
        <a class="btn btn-primary btn-block" href="<?= Helpers::url('/oidc/start') ?>">使用 OIDC 登录</a>
    <?php endif; ?>

    <?php if ($hasPassword): ?>
        <?php if ($oidcEnabled): ?>
            <p class="auth-divider"><span>或使用密码</span></p>
        <?php endif; ?>
        <form method="post" action="<?= Helpers::url('/login') ?>" class="form-stack">
            <?= Csrf::field() ?>
            <label>密码
                <input type="password" name="password" required autofocus autocomplete="current-password">
            </label>
            <button type="submit" class="btn btn-primary">登录</button>
        </form>
    <?php endif; ?>
</div>
