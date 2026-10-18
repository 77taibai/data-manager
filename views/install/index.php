<?php
declare(strict_types=1);

use Dlm\Support\Csrf;
use Dlm\Support\Helpers;

$old = $old ?? [];
$val = static fn (string $key, string $default = ''): string => Helpers::e((string) ($old[$key] ?? $default));
$oidcOn = ($old['oidc_enabled'] ?? '') === '1';
?>
<div class="auth-card">
    <h1>安装下载链接管理系统</h1>
    <p class="muted">首次使用请填写数据库信息与登录方式，系统会自动创建数据表并生成配置文件。管理密码以哈希形式保存在 config.php 中，不入库。</p>

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

        <h2>登录方式</h2>
        <label class="check-line">
            <input type="checkbox" name="oidc_enabled" value="1" data-oidc-toggle <?= $oidcOn ? 'checked' : '' ?>>
            启用 OIDC 单点登录
        </label>

        <h2>管理密码</h2>
        <p class="muted" data-password-hint>启用 OIDC 后管理密码可留空，此时只能通过 OIDC 登录。</p>
        <label>管理密码（至少 6 位）
            <input type="password" name="admin_pass" autocomplete="new-password" data-password-input>
        </label>
        <label>确认密码
            <input type="password" name="admin_pass2" autocomplete="new-password" data-password-input>
        </label>

        <div data-oidc-fields <?= $oidcOn ? '' : 'hidden' ?>>
            <h2>OIDC 配置</h2>
            <label>Issuer
                <input type="url" name="oidc_issuer" value="<?= $val('oidc_issuer') ?>" placeholder="https://auth.example.com">
            </label>
            <label>Client ID
                <input type="text" name="oidc_client_id" value="<?= $val('oidc_client_id') ?>" autocomplete="off">
            </label>
            <label>Client Secret
                <input type="password" name="oidc_client_secret" value="" autocomplete="new-password">
            </label>
            <label>回调地址（留空按当前域名推导）
                <input type="url" name="oidc_redirect_uri" value="<?= $val('oidc_redirect_uri') ?>" placeholder="<?= Helpers::e(Helpers::absolute('/oidc/callback')) ?>">
            </label>
            <label>Scope
                <input type="text" name="oidc_scope" value="<?= $val('oidc_scope', 'openid email profile') ?>">
            </label>
            <label>允许登录的 sub（每行一个，必须精确匹配）
                <textarea name="oidc_allowed_subs" rows="3" placeholder="248289761001"><?= $val('oidc_allowed_subs') ?></textarea>
            </label>
            <p class="muted">sub 是身份提供方对用户的唯一标识，可在其管理后台或 id_token 中查看。只有列表内的 sub 能登录本系统。</p>
        </div>

        <button type="submit" class="btn btn-primary">开始安装</button>
    </form>
</div>
<script>
(function () {
    var toggle = document.querySelector('[data-oidc-toggle]');
    var fields = document.querySelector('[data-oidc-fields]');
    var inputs = document.querySelectorAll('[data-password-input]');
    var hint = document.querySelector('[data-password-hint]');
    if (!toggle || !fields) { return; }

    function sync() {
        var on = toggle.checked;
        fields.hidden = !on;
        for (var i = 0; i < inputs.length; i++) {
            inputs[i].required = !on;
        }
        if (hint) { hint.hidden = !on; }
    }

    toggle.addEventListener('change', sync);
    sync();
})();
</script>
