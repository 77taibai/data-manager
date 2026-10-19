<?php
declare(strict_types=1);

use Dlm\Support\Csrf;
use Dlm\Support\Helpers;

$repo = $repo ?? [];
$attributes = $attributes ?? [];
$items = $items ?? [];
$total = (int) ($total ?? 0);
$page = (int) ($page ?? 1);
$pageSize = (int) ($pageSize ?? 20);
$pageSizeOptions = $pageSizeOptions ?? [10, 20, 50, 100];
$defaultPageSize = 20;
$q = (string) ($q ?? '');
$sort = (string) ($sort ?? '-created_at');
$rawFilters = $rawFilters ?? [];

$slug = (string) ($repo['slug'] ?? '');
$basePath = '/repos/' . $slug;
$typeNames = ['text' => '文本', 'date' => '日期时间', 'filesize' => '文件大小'];
$totalPages = max(1, (int) ceil($total / max(1, $pageSize)));

$buildQuery = static function (array $overrides) use ($rawFilters, $q, $sort, $pageSize, $defaultPageSize, $basePath): string {
    $params = [];
    if ($rawFilters !== []) {
        $params['filter'] = $rawFilters;
    }
    if ($q !== '') {
        $params['q'] = $q;
    }
    if ($sort !== '') {
        $params['sort'] = $sort;
    }
    if ((int) $pageSize !== (int) $defaultPageSize) {
        $params['page_size'] = $pageSize;
    }
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    if (isset($params['page_size']) && (int) $params['page_size'] === (int) $defaultPageSize) {
        unset($params['page_size']);
    }
    $qs = http_build_query($params);
    return $basePath . ($qs !== '' ? '?' . $qs : '');
};

$dateInputValue = static function (string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = str_replace(' ', 'T', $value);
    return strlen($value) > 16 ? substr($value, 0, 16) : $value;
};

$activeFilterCount = 0;
foreach ($rawFilters as $rawValue) {
    if (is_array($rawValue)) {
        continue;
    }
    if (trim((string) $rawValue) !== '') {
        $activeFilterCount++;
    }
}
if ($q !== '') {
    $activeFilterCount++;
}
?>
<div class="page-head">
    <h1><?= Helpers::e((string) ($repo['name'] ?? '')) ?></h1>
    <span class="badge">共 <?= $total ?> 条</span>
    <div class="page-actions">
        <button type="button" class="btn btn-primary" data-link-new>新增链接</button>
        <a class="btn" href="<?= Helpers::url($basePath . '/import') ?>">批量导入</a>
        <a class="btn" href="<?= Helpers::url($basePath . '/export') ?>">全部导出</a>
        <a class="btn" href="<?= Helpers::url($basePath . '/attributes') ?>">属性</a>
        <form id="bulk-form" class="bulk-bar" method="post" action="<?= Helpers::url($basePath . '/links/bulk') ?>"
              data-export-url="<?= Helpers::url($basePath . '/export') ?>">
            <?= Csrf::field() ?>
            <span class="bulk-count">已选 <strong data-bulk-count>0</strong> 项</span>
            <select name="action" data-bulk-action>
                <option value="delete">批量删除</option>
                <option value="set_attr">批量修改属性</option>
                <option value="export">批量导出选中（CSV）</option>
            </select>
            <span data-bulk-attr hidden class="bulk-attr">
                <select name="attr_key">
                    <?php foreach ($attributes as $attr): ?>
                        <option value="<?= Helpers::e((string) $attr['key']) ?>"><?= Helpers::e((string) $attr['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="attr_value" placeholder="新值（留空清除）">
            </span>
            <button type="submit" class="btn btn-danger" data-bulk-submit
                    data-label-run="执行" data-label-export="导出 CSV" disabled>执行</button>
        </form>
    </div>
</div>

<details class="filters-panel card" data-filters-panel>
    <summary class="filters-summary">
        <span class="filters-summary-title">筛选条件</span>
        <?php if ($activeFilterCount > 0): ?>
            <span class="badge badge-active">已启用 <?= $activeFilterCount ?> 项</span>
        <?php else: ?>
            <span class="badge">未启用</span>
        <?php endif; ?>
        <span class="filters-summary-right">
            <span class="filters-summary-hint" data-filters-hint-expand>展开</span>
            <span class="filters-summary-hint" data-filters-hint-collapse>收起</span>
        </span>
    </summary>
    <form method="get" action="<?= Helpers::url($basePath) ?>" class="filters">
        <?php foreach ($attributes as $attr): ?>
            <?php
            $key = (string) $attr['key'];
            $type = (string) $attr['type'];
            ?>
            <div class="field">
                <label><?= Helpers::e((string) $attr['label']) ?></label>
                <?php if ($type === 'date'): ?>
                    <input type="datetime-local" name="filter[<?= Helpers::e($key) ?>]" value="<?= Helpers::e($dateInputValue((string) ($rawFilters[$key] ?? ''))) ?>">
                <?php elseif ($type === 'filesize'): ?>
                    <div class="size-range">
                        <input type="text" name="filter[<?= Helpers::e($key) ?>_gte]" placeholder="最小，如 100MB" value="<?= Helpers::e((string) ($rawFilters[$key . '_gte'] ?? '')) ?>">
                        <input type="text" name="filter[<?= Helpers::e($key) ?>_lte]" placeholder="最大，如 5GB" value="<?= Helpers::e((string) ($rawFilters[$key . '_lte'] ?? '')) ?>">
                    </div>
                <?php elseif ((int) $attr['constrained'] === 1): ?>
                    <select name="filter[<?= Helpers::e($key) ?>]">
                        <option value="">全部</option>
                        <?php foreach (($attr['options'] ?? []) as $opt): ?>
                            <option value="<?= Helpers::e((string) $opt) ?>" <?= (string) ($rawFilters[$key] ?? '') === (string) $opt ? 'selected' : '' ?>><?= Helpers::e((string) $opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" name="filter[<?= Helpers::e($key) ?>]" value="<?= Helpers::e((string) ($rawFilters[$key] ?? '')) ?>">
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div class="field">
            <label>关键词</label>
            <input type="search" name="q" value="<?= Helpers::e($q) ?>" placeholder="URL 或文本属性">
        </div>
        <input type="hidden" name="sort" value="<?= Helpers::e($sort) ?>">
        <?php if ($pageSize !== (int) $defaultPageSize): ?>
            <input type="hidden" name="page_size" value="<?= $pageSize ?>">
        <?php endif; ?>
        <div class="field field-actions">
            <button type="submit" class="btn btn-primary">筛选</button>
            <a class="btn" href="<?= Helpers::url($basePath) ?>">重置</a>
        </div>
    </form>
</details>

<?php if ($items === []): ?>
    <div class="card empty-state">没有符合条件的链接。</div>
<?php else: ?>
    <div class="table-wrap links-table-wrap">
        <table class="data-table links-table">
            <thead>
            <tr>
                <th class="col-check"><input type="checkbox" data-check-all form="bulk-form"></th>
                <th class="col-url">URL</th>
                <?php foreach ($attributes as $attr): ?>
                    <th class="col-attr"><?= Helpers::e((string) $attr['label']) ?></th>
                <?php endforeach; ?>
                <th class="col-actions">操作</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $link): ?>
                <?php
                $linkId = (int) $link['id'];
                $values = $link['values'] ?? [];
                $payload = ['id' => $linkId, 'url' => (string) $link['url'], 'values' => []];
                foreach ($attributes as $attr) {
                    $k = (string) $attr['key'];
                    $raw = $values[$k]['raw'] ?? null;
                    if ((string) $attr['type'] === 'date' && $raw !== null) {
                        $payload['values'][$k] = $dateInputValue((string) $raw);
                    } elseif ($raw === null) {
                        $payload['values'][$k] = '';
                    } else {
                        $payload['values'][$k] = (string) $raw;
                    }
                }
                ?>
                <tr>
                    <td class="col-check"><input type="checkbox" name="ids[]" value="<?= $linkId ?>" data-row-check form="bulk-form"></td>
                    <td class="col-url url-cell"><a href="<?= Helpers::e((string) $link['url']) ?>"<?= Helpers::isHttpUrl((string) $link['url']) ? ' target="_blank" rel="noopener"' : '' ?> title="<?= Helpers::e((string) $link['url']) ?>"><?= Helpers::e((string) $link['url']) ?></a></td>
                    <?php foreach ($attributes as $attr): ?>
                        <?php
                        $k = (string) $attr['key'];
                        $display = (string) ($values[$k]['display'] ?? '');
                        ?>
                        <td class="col-attr"><span class="cell-clip" title="<?= Helpers::e($display) ?>"><?= Helpers::e($display) ?></span></td>
                    <?php endforeach; ?>
                    <td class="col-actions">
                        <div class="row-actions">
                            <button type="button" class="btn btn-sm" data-link-edit data-link="<?= Helpers::e(Helpers::jsonEncode($payload)) ?>">编辑</button>
                            <form method="post" action="<?= Helpers::url($basePath . '/links/' . $linkId . '/delete') ?>" class="inline-form" data-confirm="确定删除该链接？">
                                <?= Csrf::field() ?>
                                <button type="submit" class="btn btn-sm btn-danger">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="table-cards">
        <div class="cards-toolbar">
            <label><input type="checkbox" data-check-all> 全选</label>
        </div>
        <?php foreach ($items as $link): ?>
            <?php
            $linkId = (int) $link['id'];
            $values = $link['values'] ?? [];
            $payload = ['id' => $linkId, 'url' => (string) $link['url'], 'values' => []];
            foreach ($attributes as $attr) {
                $k = (string) $attr['key'];
                $raw = $values[$k]['raw'] ?? null;
                if ((string) $attr['type'] === 'date' && $raw !== null) {
                    $payload['values'][$k] = $dateInputValue((string) $raw);
                } elseif ($raw === null) {
                    $payload['values'][$k] = '';
                } else {
                    $payload['values'][$k] = (string) $raw;
                }
            }
            ?>
            <div class="link-card">
                <div class="link-card-head">
                    <input type="checkbox" name="ids[]" value="<?= $linkId ?>" data-row-check form="bulk-form">
                    <a class="url-cell" href="<?= Helpers::e((string) $link['url']) ?>"<?= Helpers::isHttpUrl((string) $link['url']) ? ' target="_blank" rel="noopener"' : '' ?>><?= Helpers::e((string) $link['url']) ?></a>
                </div>
                <dl>
                    <?php foreach ($attributes as $attr): ?>
                        <?php $k = (string) $attr['key']; ?>
                        <dt><?= Helpers::e((string) $attr['label']) ?></dt>
                        <dd><?= Helpers::e((string) ($values[$k]['display'] ?? '')) ?></dd>
                    <?php endforeach; ?>
                </dl>
                <div class="row-actions">
                    <button type="button" class="btn btn-sm" data-link-edit data-link="<?= Helpers::e(Helpers::jsonEncode($payload)) ?>">编辑</button>
                    <form method="post" action="<?= Helpers::url($basePath . '/links/' . $linkId . '/delete') ?>" class="inline-form" data-confirm="确定删除该链接？">
                        <?= Csrf::field() ?>
                        <button type="submit" class="btn btn-sm btn-danger">删除</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="pagination">
        <?php if ($page > 1): ?>
            <a class="btn btn-sm" href="<?= Helpers::url($buildQuery(['page' => $page - 1])) ?>">上一页</a>
        <?php endif; ?>
        <span class="muted">第 <?= $page ?> / <?= $totalPages ?> 页</span>
        <?php if ($page < $totalPages): ?>
            <a class="btn btn-sm" href="<?= Helpers::url($buildQuery(['page' => $page + 1])) ?>">下一页</a>
        <?php endif; ?>
        <label class="page-size">
            <span class="muted">每页</span>
            <select data-page-size aria-label="每页显示条数">
                <?php foreach ($pageSizeOptions as $option): ?>
                    <?php $option = (int) $option; ?>
                    <option value="<?= $option ?>" data-url="<?= Helpers::e(Helpers::url($buildQuery(['page' => 1, 'page_size' => $option]))) ?>"<?= $option === $pageSize ? ' selected' : '' ?>><?= $option ?> 条</option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>
<?php endif; ?>

<div class="modal-backdrop" id="link-modal" hidden>
    <div class="modal">
        <div class="modal-head">
            <h2 data-modal-title>新增链接</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-modal-close>×</button>
        </div>
        <form method="post" id="link-form" class="form-stack"
              action="<?= Helpers::url($basePath . '/links') ?>"
              data-action-create="<?= Helpers::url($basePath . '/links') ?>"
              data-action-edit="<?= Helpers::url($basePath . '/links/') ?>">
            <?= Csrf::field() ?>
            <label>下载 URL <span class="req">*</span>
                <input type="text" name="url" required placeholder="https://example.com/file.zip 或 ed2k://|file|...|/ 或 magnet:?xt=urn:btih:...">
            </label>
            <?php foreach ($attributes as $attr): ?>
                <?php
                $key = (string) $attr['key'];
                $type = (string) $attr['type'];
                $req = (int) $attr['required'] === 1;
                ?>
                <label><?= Helpers::e((string) $attr['label']) ?><?= $req ? ' <span class="req">*</span>' : '' ?>
                    <?php if ($type === 'date'): ?>
                        <input type="datetime-local" name="attributes[<?= Helpers::e($key) ?>]" data-attr="<?= Helpers::e($key) ?>"<?= $req ? ' required' : '' ?>>
                    <?php elseif ($type === 'filesize'): ?>
                        <input type="text" name="attributes[<?= Helpers::e($key) ?>]" data-attr="<?= Helpers::e($key) ?>" placeholder="如 100MB"<?= $req ? ' required' : '' ?>>
                    <?php elseif ((int) $attr['constrained'] === 1): ?>
                        <select name="attributes[<?= Helpers::e($key) ?>]" data-attr="<?= Helpers::e($key) ?>"<?= $req ? ' required' : '' ?>>
                            <option value=""><?= $req ? '（请选择）' : '（空）' ?></option>
                            <?php foreach (($attr['options'] ?? []) as $opt): ?>
                                <option value="<?= Helpers::e((string) $opt) ?>"><?= Helpers::e((string) $opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" name="attributes[<?= Helpers::e($key) ?>]" data-attr="<?= Helpers::e($key) ?>"<?= $req ? ' required' : '' ?>>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
            <div class="row-actions">
                <button type="submit" class="btn btn-primary">保存</button>
                <button type="button" class="btn" data-modal-close>取消</button>
            </div>
        </form>
    </div>
</div>
