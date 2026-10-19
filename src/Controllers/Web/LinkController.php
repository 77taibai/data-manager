<?php
declare(strict_types=1);

namespace Dlm\Controllers\Web;

use Dlm\Models\Attribute;
use Dlm\Models\Link;
use Dlm\Models\Repository;
use Dlm\Support\Flash;
use Dlm\Support\Helpers;
use Dlm\Support\Request;
use Dlm\Support\Response;
use Dlm\Support\View;

final class LinkController
{
    /** 预置的每页条数选项（不支持用户自定义输入） */
    public const PAGE_SIZE_OPTIONS = [10, 20, 50, 100];
    public const DEFAULT_PAGE_SIZE = 20;

    private const FILTER_OPERATORS = [
        '_contains' => 'contains',
        '_gte' => 'gte',
        '_lte' => 'lte',
        '_gt' => 'gt',
        '_lt' => 'lt',
    ];

    public function index(Request $request, string $slug): void
    {
        $repository = Repository::findBySlug($slug);
        if ($repository === null) {
            Response::html('<h1>404 存储库不存在</h1>', 404);
            return;
        }
        $repositoryId = (int) $repository['id'];
        $attributes = Attribute::forRepository($repositoryId);

        $rawFilters = $request->query('filter', []);
        if (!is_array($rawFilters)) {
            $rawFilters = [];
        }
        $filters = $this->parseFilters($attributes, $rawFilters);

        $q = trim((string) $request->query('q', ''));
        $sort = trim((string) $request->query('sort', ''));
        if ($sort === '') {
            $sort = '-created_at';
        }
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = (int) $request->query('page_size', self::DEFAULT_PAGE_SIZE);
        if (!in_array($pageSize, self::PAGE_SIZE_OPTIONS, true)) {
            $pageSize = self::DEFAULT_PAGE_SIZE;
        }

        $result = Link::query($repositoryId, $filters, $q, $sort, $page, $pageSize);

        View::render('links/index', [
            'title' => (string) $repository['name'] . ' · 链接',
            'repo' => $repository,
            'attributes' => $attributes,
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $result['page'],
            'pageSize' => $result['page_size'],
            'pageSizeOptions' => self::PAGE_SIZE_OPTIONS,
            'q' => $q,
            'sort' => $sort,
            'rawFilters' => $rawFilters,
        ]);
    }

    public function store(Request $request, string $slug): void
    {
        $repository = Repository::findBySlug($slug);
        if ($repository === null) {
            Response::html('<h1>404 存储库不存在</h1>', 404);
            return;
        }
        $repositoryId = (int) $repository['id'];
        $url = (string) $request->input('url', '');
        $values = $this->valuesFromRequest($request, $repositoryId);

        $result = Link::create($repositoryId, $url, $values);
        if ($result['errors'] !== []) {
            Flash::error(implode('；', $result['errors']));
        } else {
            Flash::success('链接已创建');
        }
        Response::redirect('/repos/' . (string) $repository['slug']);
    }

    public function update(Request $request, string $slug, string $id): void
    {
        $repository = Repository::findBySlug($slug);
        if ($repository === null) {
            Response::html('<h1>404 存储库不存在</h1>', 404);
            return;
        }
        $repositoryId = (int) $repository['id'];
        $url = (string) $request->input('url', '');
        $values = $this->valuesFromRequest($request, $repositoryId);

        $result = Link::update((int) $id, $repositoryId, $url, $values);
        if ($result['errors'] !== []) {
            Flash::error(implode('；', $result['errors']));
        } else {
            Flash::success('链接已更新');
        }
        Response::redirect('/repos/' . (string) $repository['slug']);
    }

    public function destroy(Request $request, string $slug, string $id): void
    {
        $repository = Repository::findBySlug($slug);
        if ($repository === null) {
            Response::html('<h1>404 存储库不存在</h1>', 404);
            return;
        }
        $deleted = Link::delete((int) $id, (int) $repository['id']);
        if ($deleted) {
            Flash::success('链接已删除');
        } else {
            Flash::error('链接不存在或已被删除');
        }
        Response::redirect('/repos/' . (string) $repository['slug']);
    }

    public function bulk(Request $request, string $slug): void
    {
        $repository = Repository::findBySlug($slug);
        if ($repository === null) {
            Response::html('<h1>404 存储库不存在</h1>', 404);
            return;
        }
        $repositoryId = (int) $repository['id'];
        $action = (string) $request->input('action', '');
        $idsRaw = $request->input('ids', []);
        $ids = is_array($idsRaw) ? array_map('intval', $idsRaw) : [];

        if ($action === 'delete') {
            $count = Link::deleteMany($repositoryId, $ids);
            Flash::success('已删除 ' . $count . ' 条链接');
        } elseif ($action === 'set_attr') {
            $attrKey = trim((string) $request->input('attr_key', ''));
            $rawValue = (string) $request->input('attr_value', '');
            $bulkAttr = Attribute::findByKey($repositoryId, $attrKey);
            if ($bulkAttr !== null && (string) $bulkAttr['type'] === Attribute::TYPE_DATE) {
                $rawValue = str_replace('T', ' ', $rawValue);
            }
            $value = trim($rawValue) === '' ? null : $rawValue;
            $res = Link::bulkSetAttribute($repositoryId, $ids, $attrKey, $value);
            if ($res['errors'] !== []) {
                Flash::error(implode('；', $res['errors']));
            } else {
                Flash::success('已更新 ' . $res['updated'] . ' 条链接');
            }
        } elseif ($action === 'export') {
            $links = Link::findManyByIds($repositoryId, $ids);
            if ($links === []) {
                Flash::error('请先选择要导出的链接');
                Response::redirect('/repos/' . (string) $repository['slug']);
                return;
            }
            $this->sendCsv(
                (string) $repository['slug'],
                Attribute::forRepository($repositoryId),
                $links,
                true
            );
            return;
        } else {
            Flash::error('未知的批量操作');
        }
        Response::redirect('/repos/' . (string) $repository['slug']);
    }

    public function export(Request $request, string $slug): void
    {
        $repository = Repository::findBySlug($slug);
        if ($repository === null) {
            Response::html('<h1>404 存储库不存在</h1>', 404);
            return;
        }
        $repositoryId = (int) $repository['id'];
        $attributes = Attribute::forRepository($repositoryId);

        $idsRaw = trim((string) $request->query('ids', ''));
        if ($idsRaw !== '') {
            $ids = array_values(array_filter(
                array_map('intval', explode(',', $idsRaw)),
                static fn (int $i): bool => $i > 0
            ));
            $links = Link::findManyByIds($repositoryId, $ids);
            $this->sendCsv($slug, $attributes, $links, true);
            return;
        }

        $links = [];
        $page = 1;
        $total = null;
        do {
            $res = Link::query($repositoryId, [], '', '-created_at', $page, 200);
            if ($total === null) {
                $total = (int) $res['total'];
            }
            $links = array_merge($links, $res['items']);
            $page++;
        } while ($res['items'] !== [] && count($links) < (int) $total);

        $this->sendCsv($slug, $attributes, $links, false);
    }

    private function sendCsv(string $slug, array $attributes, array $links, bool $selectedOnly): void
    {
        $rows = [];
        $header = ['url'];
        foreach ($attributes as $attr) {
            $header[] = (string) $attr['key'];
        }
        $rows[] = $header;

        foreach ($links as $link) {
            $values = $link['values'] ?? [];
            $row = [(string) $link['url']];
            foreach ($attributes as $attr) {
                $key = (string) $attr['key'];
                $row[] = $this->csvValue($attr, $values[$key] ?? null);
            }
            $rows[] = $row;
        }

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            Response::html('<h1>500 导出失败</h1>', 500);
            return;
        }
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($fh, $row, ',', '"', '');
        }
        rewind($fh);
        $content = (string) stream_get_contents($fh);
        fclose($fh);

        $filename = $slug . ($selectedOnly ? '-links-selected.csv' : '-links.csv');
        Response::download($filename, $content);
    }

    public function importForm(Request $request, string $slug): void
    {
        $repository = Repository::findBySlug($slug);
        if ($repository === null) {
            Response::html('<h1>404 存储库不存在</h1>', 404);
            return;
        }
        View::render('links/import', [
            'title' => (string) $repository['name'] . ' · 批量导入',
            'repo' => $repository,
            'attributes' => Attribute::forRepository((int) $repository['id']),
        ]);
    }

    public function importPreview(Request $request, string $slug): void
    {
        $repository = Repository::findBySlug($slug);
        if ($repository === null) {
            Response::json(['code' => 4040, 'message' => '存储库不存在', 'data' => null], 404);
            return;
        }
        $repositoryId = (int) $repository['id'];
        $attributes = Attribute::forRepository($repositoryId);
        $attrKeys = [];
        foreach ($attributes as $attr) {
            $attrKeys[(string) $attr['key']] = $attr;
        }

        $rows = $this->parseCsv($this->readCsvInput($request));
        if ($rows === []) {
            Response::json([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'columns' => [],
                    'has_url' => false,
                    'known_columns' => [],
                    'unknown_columns' => [],
                    'total' => 0,
                    'preview' => [],
                ],
            ]);
            return;
        }

        $header = array_shift($rows);
        $columns = array_map(static fn ($c): string => trim((string) $c), $header);

        $urlIndex = null;
        foreach ($columns as $i => $name) {
            if (strtolower($name) === 'url') {
                $urlIndex = $i;
                break;
            }
        }

        $known = [];
        $unknown = [];
        foreach ($columns as $name) {
            if (strtolower($name) === 'url' || $name === '') {
                continue;
            }
            if (isset($attrKeys[$name])) {
                $known[] = $name;
            } else {
                $unknown[] = $name;
            }
        }

        $preview = [];
        foreach (array_slice($rows, 0, 5) as $row) {
            $url = $urlIndex !== null ? trim((string) ($row[$urlIndex] ?? '')) : '';
            $valuesByKey = [];
            foreach ($known as $key) {
                $colIndex = array_search($key, $columns, true);
                if ($colIndex === false) {
                    continue;
                }
                $valuesByKey[$key] = (string) ($row[$colIndex] ?? '');
            }

            $errors = [];
            $urlError = Helpers::downloadUrlError($url);
            if ($urlError !== null) {
                $errors[] = $urlError;
            } elseif (Link::existsUrl($repositoryId, $url)) {
                $errors[] = '该 URL 已存在';
            }
            $validated = Link::validateValues($repositoryId, $valuesByKey, false);
            $errors = array_merge($errors, $validated['errors']);

            $display = [];
            foreach ($valuesByKey as $key => $value) {
                $attr = $attrKeys[$key];
                if ((string) $attr['type'] === Attribute::TYPE_FILESIZE) {
                    $bytes = Helpers::parseSizeToBytes($value);
                    $display[$key] = $bytes !== null ? Helpers::formatBytes($bytes) : $value;
                } else {
                    $display[$key] = $value;
                }
            }

            $preview[] = [
                'url' => $url,
                'values' => $display,
                'errors' => $errors,
            ];
        }

        Response::json([
            'code' => 0,
            'message' => 'ok',
            'data' => [
                'columns' => $columns,
                'has_url' => $urlIndex !== null,
                'known_columns' => $known,
                'unknown_columns' => $unknown,
                'total' => count($rows),
                'preview' => $preview,
            ],
        ]);
    }

    public function import(Request $request, string $slug): void
    {
        $repository = Repository::findBySlug($slug);
        if ($repository === null) {
            Response::html('<h1>404 存储库不存在</h1>', 404);
            return;
        }
        $repositoryId = (int) $repository['id'];
        $attributes = Attribute::forRepository($repositoryId);
        $attrKeys = [];
        foreach ($attributes as $attr) {
            $attrKeys[(string) $attr['key']] = $attr;
        }

        $rows = $this->parseCsv($this->readCsvInput($request));
        if ($rows === []) {
            Flash::error('CSV 内容为空或无法解析');
            Response::redirect('/repos/' . (string) $repository['slug'] . '/import');
            return;
        }

        $header = array_shift($rows);
        $columns = array_map(static fn ($c): string => trim((string) $c), $header);

        $urlIndex = null;
        foreach ($columns as $i => $name) {
            if (strtolower($name) === 'url') {
                $urlIndex = $i;
                break;
            }
        }
        if ($urlIndex === null) {
            Flash::error('CSV 缺少 url 列');
            Response::redirect('/repos/' . (string) $repository['slug'] . '/import');
            return;
        }

        $known = [];
        foreach ($columns as $name) {
            if ($name !== '' && strtolower($name) !== 'url' && isset($attrKeys[$name])) {
                $known[] = $name;
            }
        }

        $created = 0;
        $skipped = 0;
        $failed = 0;
        $reasons = [];

        foreach ($rows as $row) {
            $url = trim((string) ($row[$urlIndex] ?? ''));
            $valuesByKey = [];
            foreach ($known as $key) {
                $colIndex = array_search($key, $columns, true);
                if ($colIndex === false) {
                    continue;
                }
                $valuesByKey[$key] = (string) ($row[$colIndex] ?? '');
            }

            $result = Link::create($repositoryId, $url, $valuesByKey);
            if ($result['id'] !== null) {
                $created++;
                continue;
            }

            $reason = implode('；', $result['errors']);
            $isSkip = Helpers::downloadUrlError($url) !== null || str_contains($reason, '已存在');
            if ($isSkip) {
                $skipped++;
            } else {
                $failed++;
            }
            if (count($reasons) < 5) {
                $reasons[] = ($url !== '' ? $url : '(空 URL)') . '：' . $reason;
            }
        }

        Flash::success('导入完成：成功 ' . $created . ' 条，跳过 ' . $skipped . ' 条，失败 ' . $failed . ' 条');
        if ($reasons !== []) {
            Flash::error('部分行未导入：' . implode('；', $reasons));
        }
        Response::redirect('/repos/' . (string) $repository['slug']);
    }

    private function parseFilters(array $attributes, array $raw): array
    {
        $byKey = [];
        foreach ($attributes as $attr) {
            $byKey[(string) $attr['key']] = $attr;
        }

        $filters = [];
        foreach ($raw as $rawKey => $value) {
            $key = (string) $rawKey;
            $op = 'eq';
            foreach (self::FILTER_OPERATORS as $suffix => $candidate) {
                if (str_ends_with($key, $suffix)) {
                    $op = $candidate;
                    $key = substr($key, 0, -strlen($suffix));
                    break;
                }
            }
            $attr = $byKey[$key] ?? null;
            if ($attr === null || !is_scalar($value)) {
                continue;
            }
            $value = (string) $value;
            if (trim($value) === '') {
                continue;
            }
            if ((string) $attr['type'] === Attribute::TYPE_DATE) {
                $value = str_replace('T', ' ', $value);
            }
            $filters[] = ['attr' => $attr, 'op' => $op, 'value' => $value];
        }
        return $filters;
    }

    private function valuesFromRequest(Request $request, int $repositoryId): array
    {
        $raw = $request->input('attributes', []);
        if (!is_array($raw)) {
            return [];
        }
        $typesByKey = [];
        foreach (Attribute::forRepository($repositoryId) as $attr) {
            $typesByKey[(string) $attr['key']] = (string) $attr['type'];
        }

        $values = [];
        foreach ($raw as $key => $value) {
            $key = (string) $key;
            if ($value === null) {
                $values[$key] = null;
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            $string = (string) $value;
            if (($typesByKey[$key] ?? '') === Attribute::TYPE_DATE) {
                $string = str_replace('T', ' ', $string);
            }
            $values[$key] = $string;
        }
        return $values;
    }

    private function csvValue(array $attr, mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }
        $type = (string) $attr['type'];
        if ($type === Attribute::TYPE_DATE) {
            $raw = $value['raw'] ?? null;
            return $raw !== null ? date('Y-m-d H:i', strtotime((string) $raw)) : '';
        }
        if ($type === Attribute::TYPE_FILESIZE) {
            $raw = $value['raw'] ?? null;
            return $raw !== null ? (string) (int) $raw : '';
        }
        return (string) ($value['display'] ?? $value['raw'] ?? '');
    }

    private function readCsvInput(Request $request): string
    {
        $csv = (string) $request->input('csv', '');
        if (trim($csv) !== '') {
            return $csv;
        }
        $file = $request->files['file'] ?? null;
        if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmp = (string) ($file['tmp_name'] ?? '');
            if ($tmp !== '' && is_uploaded_file($tmp)) {
                return (string) file_get_contents($tmp);
            }
        }
        return $csv;
    }

    private function parseCsv(string $text): array
    {
        $text = str_replace("\xEF\xBB\xBF", '', $text);
        if (trim($text) === '') {
            return [];
        }
        $lines = preg_split('/\r\n|\n|\r/', $text);
        if ($lines === false) {
            return [];
        }
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = str_getcsv($line, ',', '"', '');
        }
        return $rows;
    }
}
