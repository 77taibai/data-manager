<?php
declare(strict_types=1);

use Dlm\Support\Csrf;
use Dlm\Support\Helpers;

$keys = $keys ?? [];
$repositories = $repositories ?? [];
$newToken = $newToken ?? null;
$newRepoName = $newRepoName ?? null;
$permNames = ['read' => '只读', 'write' => '读写'];
?>
<div class="page-narrow">
    <div class="page-head">
        <h1>APIKEY</h1>
        <div class="spacer"></div>
    </div>

    <?php if (!empty($newToken)): ?>
        <div class="token-box">
            <p>已为存储库「<?= Helpers::e((string) $newRepoName) ?>」创建 APIKEY，请立即复制保存，此明文仅显示一次。</p>
            <div class="token-line">
                <code><?= Helpers::e((string) $newToken) ?></code>
                <button type="button" class="btn btn-sm" data-copy-token="<?= Helpers::e((string) $newToken) ?>">复制</button>
            </div>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>创建 APIKEY</h2>
        <?php if ($repositories === []): ?>
            <p class="muted">请先创建存储库。</p>
        <?php else: ?>
            <form method="post" action="<?= Helpers::url('/keys') ?>" class="form-grid">
                <?= Csrf::field() ?>
                <label>存储库
                    <select name="repository_id" required>
                        <?php foreach ($repositories as $repo): ?>
                            <option value="<?= (int) $repo['id'] ?>"><?= Helpers::e((string) $repo['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>名称（选填）
                    <input type="text" name="name" placeholder="例如 上传脚本">
                </label>
                <label>权限
                    <select name="permissions">
                        <option value="read">只读</option>
                        <option value="write">读写</option>
                    </select>
                </label>
                <label>过期时间（选填）
                    <input type="datetime-local" name="expires_at">
                </label>
                <div class="form-actions form-grid-span">
                    <button type="submit" class="btn btn-primary">创建</button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($keys === []): ?>
        <div class="card empty-state">还没有 APIKEY。</div>
    <?php else: ?>
        <div class="card">
            <div class="table-scroll">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>存储库</th>
                        <th>名称</th>
                        <th>前缀</th>
                        <th>权限</th>
                        <th>过期时间</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($keys as $key): ?>
                        <?php $keyId = (int) $key['id']; ?>
                        <tr>
                            <td><?= Helpers::e((string) ($key['repository_name'] ?? '')) ?></td>
                            <td><?= Helpers::e((string) ($key['name'] ?? '')) ?></td>
                            <td><code><?= Helpers::e((string) $key['key_prefix']) ?>…</code></td>
                            <td><?= Helpers::e($permNames[(string) $key['permissions']] ?? (string) $key['permissions']) ?></td>
                            <td><?= Helpers::e((string) ($key['expires_at'] ?? '')) ?></td>
                            <td><?= Helpers::e((string) ($key['created_at'] ?? '')) ?></td>
                            <td>
                                <form method="post" action="<?= Helpers::url('/keys/' . $keyId . '/delete') ?>" class="inline-form" data-confirm="吊销后该 KEY 将立即失效，确定继续？">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="btn btn-sm btn-danger">吊销</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
