<?php
declare(strict_types=1);

use Dlm\Support\Csrf;
use Dlm\Support\Helpers;

$old = $old ?? [];
$val = static fn (string $key, string $default = ''): string => Helpers::e((string) ($old[$key] ?? $default));
?>
<div class="auth-card">
    <h1>安装下载链接管理系统</h1>
    <p class="muted">首次使用请填写数据库信息与管理员账号，系统会自动创建数据表并生成配置文件。</p>

    <?php if (!empty($error)): ?>
        <div class="flash flash-error"><?= Helpers::e((string) $error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= Helpers::url('/install') ?>" class="form-stack">
        <?= Csrf::field() ?>
        <h2>数据库连接</h2>
        <label>数据库主机
            <input type="text" name="db_host" value="<?= $val('db_host', '127.0.0.1') ?>" required>
        </label>
        <label>端口
            <input type="number" name="db_port" value="<?= $val('db_port', '3306') ?>" required>
        </label>
        <label>数据库名
            <input type="text" name="db_name" value="<?= $val('db_name') ?>" required>
        </label>
        <label>数据库用户
            <input type="text" name="db_user" value="<?= $val('db_user') ?>" required>
        </label>
        <label>数据库密码
            <input type="password" name="db_pass" value="">
        </label>

        <h2>管理员账号</h2>
        <label>管理员用户名
            <input type="text" name="admin_user" value="<?= $val('admin_user') ?>" required>
        </label>
        <label>管理员密码（至少 6 位）
            <input type="password" name="admin_pass" required>
        </label>
        <label>确认密码
            <input type="password" name="admin_pass2" required>
        </label>

        <button type="submit" class="btn btn-primary">开始安装</button>
    </form>
</div>
