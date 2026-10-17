<?php
declare(strict_types=1);

use Dlm\Support\Csrf;
use Dlm\Support\Helpers;

$repositories = $repositories ?? [];
?>
<div class="page-head">
    <h1>存储库</h1>
    <div class="spacer"></div>
    <a class="btn btn-primary" href="<?= Helpers::url('/repos/new') ?>">新建存储库</a>
</div>

<?php if ($repositories === []): ?>
    <div class="card empty-state">还没有存储库，点击右上角“新建存储库”开始。</div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($repositories as $repo): ?>
            <?php $slug = (string) $repo['slug']; ?>
            <div class="card repo-card">
                <h3><?= Helpers::e((string) $repo['name']) ?></h3>
                <?php if (trim((string) $repo['description']) !== ''): ?>
                    <p class="muted"><?= Helpers::e((string) $repo['description']) ?></p>
                <?php endif; ?>
                <div class="repo-meta">
                    <span class="badge">链接 <?= (int) $repo['link_count'] ?></span>
                    <span class="badge">属性 <?= (int) $repo['attribute_count'] ?></span>
                    <span class="badge">KEY <?= (int) $repo['key_count'] ?></span>
                </div>
                <div class="row-actions">
                    <a class="btn btn-sm" href="<?= Helpers::url('/repos/' . $slug) ?>">管理链接</a>
                    <a class="btn btn-sm" href="<?= Helpers::url('/repos/' . $slug . '/attributes') ?>">属性</a>
                    <a class="btn btn-sm" href="<?= Helpers::url('/repos/' . $slug . '/edit') ?>">编辑</a>
                    <form method="post" action="<?= Helpers::url('/repos/' . $slug . '/delete') ?>" class="inline-form" data-confirm="删除该存储库将级联删除其链接、属性与 KEY，确定继续？">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn-sm btn-danger">删除</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
