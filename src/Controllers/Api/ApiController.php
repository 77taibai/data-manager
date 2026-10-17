<?php
declare(strict_types=1);

namespace Dlm\Controllers\Api;

use Dlm\Models\Attribute;
use Dlm\Models\Link;
use Dlm\Support\ApiAuth;
use Dlm\Support\ApiException;
use Dlm\Support\Request;
use Dlm\Support\Response;

final class ApiController
{
    private const FILTER_OPERATORS = [
        '_contains' => 'contains',
        '_gte' => 'gte',
        '_lte' => 'lte',
        '_gt' => 'gt',
        '_lt' => 'lt',
    ];

    public function schema(Request $request): void
    {
        $repository = ApiAuth::repository();
        $attributes = [];
        foreach ($this->attributes((int) $repository['id']) as $attr) {
            $attributes[] = $this->serializeAttribute($attr);
        }
        Response::apiOk([
            'repository' => [
                'id' => (int) $repository['id'],
                'name' => $repository['name'],
                'slug' => $repository['slug'],
            ],
            'attributes' => $attributes,
        ]);
    }

    public function index(Request $request): void
    {
        $repositoryId = ApiAuth::repositoryId();
        $filters = $this->parseFilters($repositoryId, $request->query['filter'] ?? []);
        $q = (string) $request->query('q', '');
        $sort = (string) $request->query('sort', '');
        if ($sort === '') {
            $sort = '-created_at';
        }
        $page = (int) $request->query('page', 1);
        $pageSize = (int) $request->query('page_size', 20);

        $result = Link::query($repositoryId, $filters, $q, $sort, $page, $pageSize);
        $attributes = $this->attributes($repositoryId);

        $items = [];
        foreach ($result['items'] as $item) {
            $items[] = $this->serializeLink($item, $attributes);
        }

        Response::apiOk([
            'items' => $items,
            'total' => $result['total'],
            'page' => $result['page'],
            'page_size' => $result['page_size'],
        ]);
    }

    public function store(Request $request): void
    {
        ApiAuth::requireWrite();
        $repositoryId = ApiAuth::repositoryId();

        $body = $request->json;
        if (!is_array($body)) {
            throw ApiException::badRequest('请求体必须是 JSON 对象或数组');
        }

        if (array_is_list($body)) {
            $this->storeBatch($repositoryId, $body);
            return;
        }

        $url = (string) ($body['url'] ?? '');
        $values = $this->normalizeValues($body['attributes'] ?? []);
        $result = Link::create($repositoryId, $url, $values);
        if ($result['id'] === null) {
            throw ApiException::unprocessable($this->joinErrors($result['errors']));
        }

        $link = Link::findWithValues((int) $result['id'], $repositoryId);
        Response::apiOk($this->serializeLink($link, $this->attributes($repositoryId)), 'ok', 201);
    }

    public function show(Request $request, string $id): void
    {
        $repositoryId = ApiAuth::repositoryId();
        $link = Link::findWithValues((int) $id, $repositoryId);
        if ($link === null) {
            throw ApiException::notFound('链接不存在');
        }
        Response::apiOk($this->serializeLink($link, $this->attributes($repositoryId)));
    }

    public function update(Request $request, string $id): void
    {
        ApiAuth::requireWrite();
        $repositoryId = ApiAuth::repositoryId();

        if (Link::find((int) $id, $repositoryId) === null) {
            throw ApiException::notFound('链接不存在');
        }

        $body = $request->body();
        $url = null;
        if (array_key_exists('url', $body) && $body['url'] !== null) {
            $url = (string) $body['url'];
        }
        $values = array_key_exists('attributes', $body)
            ? $this->normalizeValues($body['attributes'])
            : [];

        $result = Link::update((int) $id, $repositoryId, $url, $values);
        if ($result['errors'] !== []) {
            throw ApiException::unprocessable($this->joinErrors($result['errors']));
        }

        $link = Link::findWithValues((int) $id, $repositoryId);
        Response::apiOk($this->serializeLink($link, $this->attributes($repositoryId)));
    }

    public function destroy(Request $request, string $id): void
    {
        ApiAuth::requireWrite();
        $repositoryId = ApiAuth::repositoryId();

        if (!Link::delete((int) $id, $repositoryId)) {
            throw ApiException::notFound('链接不存在');
        }
        Response::apiOk(null, 'ok');
    }

    private function storeBatch(int $repositoryId, array $items): void
    {
        $created = 0;
        $failed = 0;
        $results = [];

        foreach ($items as $index => $item) {
            $url = '';
            if (is_array($item)) {
                $url = (string) ($item['url'] ?? '');
                $values = $this->normalizeValues($item['attributes'] ?? []);
                $result = Link::create($repositoryId, $url, $values);
            } else {
                $result = ['id' => null, 'errors' => ['请求项必须是对象']];
            }

            if ($result['id'] === null) {
                $failed++;
                $results[] = [
                    'index' => (int) $index,
                    'ok' => false,
                    'url' => $url,
                    'errors' => $this->errorsOrFail($result['errors']),
                ];
                continue;
            }

            $created++;
            $results[] = [
                'index' => (int) $index,
                'ok' => true,
                'id' => (int) $result['id'],
                'url' => $url,
                'errors' => [],
            ];
        }

        Response::apiOk([
            'created' => $created,
            'failed' => $failed,
            'results' => $results,
        ]);
    }

    private function parseFilters(int $repositoryId, mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
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

            $attr = Attribute::findByKey($repositoryId, $key);
            if ($attr === null) {
                throw ApiException::unprocessable('未知属性：' . $key);
            }

            $filters[] = ['attr' => $attr, 'op' => $op, 'value' => $value];
        }
        return $filters;
    }

    private function attributes(int $repositoryId): array
    {
        return Attribute::forRepository($repositoryId);
    }

    private function serializeAttribute(array $attr): array
    {
        return [
            'key' => (string) $attr['key'],
            'label' => (string) $attr['label'],
            'type' => (string) $attr['type'],
            'required' => (int) $attr['required'] === 1,
            'constrained' => (int) $attr['constrained'] === 1,
            'options' => array_values($attr['options'] ?? []),
        ];
    }

    private function serializeLink(array $link, array $attributes): array
    {
        $values = $link['values'] ?? [];
        $out = [];
        foreach ($attributes as $attr) {
            $key = (string) $attr['key'];
            $out[$key] = array_key_exists($key, $values) ? $values[$key]['raw'] : null;
        }

        return [
            'id' => (int) $link['id'],
            'url' => (string) $link['url'],
            'created_at' => $link['created_at'] ?? null,
            'updated_at' => $link['updated_at'] ?? null,
            'attributes' => $out,
        ];
    }

    private function normalizeValues(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }
        $values = [];
        foreach ($input as $key => $value) {
            $values[(string) $key] = $value;
        }
        return $values;
    }

    private function joinErrors(array $errors): string
    {
        return $errors === [] ? '创建失败' : implode('；', $errors);
    }

    private function errorsOrFail(array $errors): array
    {
        return $errors === [] ? ['操作失败'] : array_values($errors);
    }
}
