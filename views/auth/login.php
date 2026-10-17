<?php
declare(strict_types=1);

use Dlm\Support\Csrf;
use Dlm\Support\Helpers;
?>
<div class="auth-card auth-card-sm">
    <h1>登录</h1>

    <?php if (!empty($error)): ?>
        <div class="flash flash-error"><?= Helpers::e((string) $error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= Helpers::url('/login') ?>" class="form-stack">
        <?= Csrf::field() ?>
        <label>用户名
            <input type="text" name="username" value="<?= Helpers::e((string) ($username ?? '')) ?>" required autofocus>
        </label>
        <label>密码
            <input type="password" name="password" required>
        </label>
        <button type="submit" class="btn btn-primary">登录</button>
    </form>
</div>
