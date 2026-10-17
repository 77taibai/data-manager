<?php
declare(strict_types=1);

use Dlm\Support\Csrf;
use Dlm\Support\Helpers;

$repo = $repo ?? [];
$slug = (string) ($repo['slug'] ?? '');
$basePath = '/repos/' . $slug;
?>
<div class="page-head">
    <h1>批量导入 CSV · <?= Helpers::e((string) ($repo['name'] ?? '')) ?></h1>
    <div class="spacer"></div>
    <a class="btn" href="<?= Helpers::url($basePath) ?>">返回列表</a>
</div>

<div class="card">
    <p class="muted">首行必须是表头，其中一列名为 <code>url</code>，其余列名对应属性 key。可粘贴 CSV 或上传文件。日期列格式为 YYYY-MM-DD HH:MM，文件大小列可填字节数或如 100MB。</p>
    <form id="import-form" method="post" action="<?= Helpers::url($basePath . '/import') ?>"
          data-preview-action="<?= Helpers::url($basePath . '/import/preview') ?>" class="form-stack">
        <?= Csrf::field() ?>
        <label>粘贴 CSV
            <textarea name="csv" rows="10" placeholder="url,color&#10;https://example.com/a,红"></textarea>
        </label>
        <label>或上传 CSV 文件
            <input type="file" name="file" accept=".csv,text/csv">
        </label>
        <div class="row-actions">
            <button type="button" class="btn" data-preview-btn>预览</button>
            <button type="submit" class="btn btn-primary">导入</button>
            <a class="btn" href="<?= Helpers::url($basePath) ?>">取消</a>
        </div>
    </form>
    <div id="import-preview" class="import-preview"></div>
</div>
