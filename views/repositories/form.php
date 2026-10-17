<?php
declare(strict_types=1);

use Dlm\Support\Csrf;
use Dlm\Support\Helpers;

$repository = $repository ?? null;
$isEdit = $repository !== null;
$action = $isEdit ? '/repos/' . (string) $repository['slug'] : '/repos';
$name = $isEdit ? (string) $repository['name'] : '';
$slug = $isEdit ? (string) $repository['slug'] : '';
$description = $isEdit ? (string) $repository['description'] : '';
?>
<div class="page-head">
    <h1><?= $isEdit ? '编辑存储库' : '新建存储库' ?></h1>
</div>

<div class="card form-card">
    <form method="post" action="<?= Helpers::url($action) ?>" class="form-stack">
        <?= Csrf::field() ?>
        <label>名称 <span class="req">*</span>
            <input type="text" name="name" value="<?= Helpers::e($name) ?>" required autofocus>
        </label>
        <label>标识 slug（选填，留空则按名称自动生成）
            <input type="text" name="slug" value="<?= Helpers::e($slug) ?>" placeholder="例如 my-repo">
        </label>
        <label>描述（选填）
            <textarea name="description"><?= Helpers::e($description) ?></textarea>
        </label>
        <div class="row-actions">
            <button type="submit" class="btn btn-primary">保存</button>
            <a class="btn" href="<?= Helpers::url('/') ?>">取消</a>
        </div>
    </form>
</div>
