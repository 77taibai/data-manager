<?php
declare(strict_types=1);

use Dlm\Support\Csrf;
use Dlm\Support\Helpers;

$repo = $repo ?? [];
$attributes = $attributes ?? [];
$slug = (string) ($repo['slug'] ?? '');
$basePath = '/repos/' . $slug;
$typeNames = ['text' => '文本', 'date' => '日期时间', 'filesize' => '文件大小'];
?>
<div class="page-head">
    <h1>属性 · <?= Helpers::e((string) ($repo['name'] ?? '')) ?></h1>
    <div class="spacer"></div>
    <button type="button" class="btn btn-primary" data-attr-new>新增属性</button>
    <a class="btn" href="<?= Helpers::url($basePath) ?>">返回链接</a>
</div>

<?php if ($attributes === []): ?>
    <div class="card empty-state">还没有属性，点击“新增属性”开始定义存储库的字段。</div>
<?php else: ?>
    <div class="card">
        <table class="data-table">
            <thead>
            <tr>
                <th>名称</th>
                <th>key</th>
                <th>类型</th>
                <th>必填</th>
                <th>约束</th>
                <th>可选值</th>
                <th>排序</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($attributes as $attr): ?>
                <?php
                $attrId = (int) $attr['id'];
                $payload = Helpers::jsonEncode([
                    'id' => $attrId,
                    'label' => (string) $attr['label'],
                    'key' => (string) $attr['key'],
                    'type' => (string) $attr['type'],
                    'required' => (int) $attr['required'],
                    'constrained' => (int) $attr['constrained'],
                    'sort_order' => (int) $attr['sort_order'],
                    'options' => array_values($attr['options'] ?? []),
                ]);
                ?>
                <tr>
                    <td><?= Helpers::e((string) $attr['label']) ?></td>
                    <td><code><?= Helpers::e((string) $attr['key']) ?></code></td>
                    <td><?= Helpers::e($typeNames[(string) $attr['type']] ?? (string) $attr['type']) ?></td>
                    <td><?= (int) $attr['required'] === 1 ? '是' : '否' ?></td>
                    <td><?= (int) $attr['constrained'] === 1 ? '是' : '否' ?></td>
                    <td>
                        <?php foreach (($attr['options'] ?? []) as $opt): ?>
                            <span class="badge"><?= Helpers::e((string) $opt) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td><?= (int) $attr['sort_order'] ?></td>
                    <td class="row-actions">
                        <button type="button" class="btn btn-sm" data-attr-edit data-attr="<?= Helpers::e($payload) ?>">编辑</button>
                        <form method="post" action="<?= Helpers::url($basePath . '/attributes/' . $attrId . '/delete') ?>" class="inline-form" data-confirm="删除该属性将级联删除其可选值与该属性在所有链接上的取值，确定继续？">
                            <?= Csrf::field() ?>
                            <button type="submit" class="btn btn-sm btn-danger">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="modal-backdrop" id="attr-modal" hidden>
    <div class="modal">
        <div class="modal-head">
            <h2 data-modal-title>新增属性</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-modal-close>×</button>
        </div>
        <form method="post" id="attr-form" class="form-stack"
              action="<?= Helpers::url($basePath . '/attributes') ?>"
              data-action-create="<?= Helpers::url($basePath . '/attributes') ?>"
              data-action-edit="<?= Helpers::url($basePath . '/attributes/') ?>">
            <?= Csrf::field() ?>
            <label>名称 <span class="req">*</span>
                <input type="text" name="label" required>
            </label>
            <label>key <span class="req">*</span>（小写字母开头，仅含小写字母、数字、下划线、连字符）
                <input type="text" name="key" required pattern="[a-z][a-z0-9_-]*" placeholder="例如 color">
            </label>
            <label>类型
                <select name="type">
                    <option value="text">文本</option>
                    <option value="date">日期时间</option>
                    <option value="filesize">文件大小</option>
                </select>
            </label>
            <label class="check-line">
                <input type="checkbox" name="required" value="1"> 必填
            </label>
            <label class="check-line">
                <input type="checkbox" name="constrained" value="1"> 仅允许指定值（仅文本类型有效）
            </label>
            <label>排序
                <input type="number" name="sort_order" value="0">
            </label>
            <label>可选值（每行一个，仅“仅允许指定值”时使用）
                <textarea name="options" rows="5" placeholder="红&#10;蓝&#10;绿"></textarea>
            </label>
            <div class="row-actions">
                <button type="submit" class="btn btn-primary">保存</button>
                <button type="button" class="btn" data-modal-close>取消</button>
            </div>
        </form>
    </div>
</div>
