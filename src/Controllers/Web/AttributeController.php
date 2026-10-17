<?php
declare(strict_types=1);

namespace Dlm\Controllers\Web;

use Dlm\Models\Attribute;
use Dlm\Models\Repository;
use Dlm\Support\Flash;
use Dlm\Support\Request;
use Dlm\Support\Response;
use Dlm\Support\View;

final class AttributeController
{
    public function index(Request $request, string $slug): void
    {
        $repository = Repository::findBySlug($slug);
        if ($repository === null) {
            Response::html('<h1>404 存储库不存在</h1>', 404);
            return;
        }
        View::render('attributes/index', [
            'title' => (string) $repository['name'] . ' · 属性',
            'repo' => $repository,
            'attributes' => Attribute::forRepository((int) $repository['id']),
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
        $data = $this->definition($request);

        $errors = Attribute::validateDefinition($data, $repositoryId);
        if ($errors !== []) {
            Flash::error(implode('；', $errors));
            Response::redirect('/repos/' . (string) $repository['slug'] . '/attributes');
            return;
        }

        Attribute::create($repositoryId, $data);
        Flash::success('属性已创建');
        Response::redirect('/repos/' . (string) $repository['slug'] . '/attributes');
    }

    public function update(Request $request, string $slug, string $id): void
    {
        $repository = Repository::findBySlug($slug);
        if ($repository === null) {
            Response::html('<h1>404 存储库不存在</h1>', 404);
            return;
        }
        $repositoryId = (int) $repository['id'];
        if (Attribute::find((int) $id) === null) {
            Flash::error('属性不存在');
            Response::redirect('/repos/' . (string) $repository['slug'] . '/attributes');
            return;
        }
        $data = $this->definition($request);

        $errors = Attribute::validateDefinition($data, $repositoryId, (int) $id);
        if ($errors !== []) {
            Flash::error(implode('；', $errors));
            Response::redirect('/repos/' . (string) $repository['slug'] . '/attributes');
            return;
        }

        Attribute::update((int) $id, $data);
        Flash::success('属性已更新');
        Response::redirect('/repos/' . (string) $repository['slug'] . '/attributes');
    }

    public function destroy(Request $request, string $slug, string $id): void
    {
        $repository = Repository::findBySlug($slug);
        if ($repository === null) {
            Response::html('<h1>404 存储库不存在</h1>', 404);
            return;
        }
        Attribute::delete((int) $id);
        Flash::success('属性已删除');
        Response::redirect('/repos/' . (string) $repository['slug'] . '/attributes');
    }

    private function definition(Request $request): array
    {
        $optionsRaw = (string) $request->input('options', '');
        $options = $optionsRaw === '' ? [] : (preg_split('/\r\n|\r|\n/', $optionsRaw) ?: []);
        $options = array_values(array_filter(
            array_map(static fn ($v): string => trim((string) $v), $options),
            static fn (string $v): bool => $v !== ''
        ));

        return [
            'label' => trim((string) $request->input('label', '')),
            'key' => trim((string) $request->input('key', '')),
            'type' => (string) $request->input('type', Attribute::TYPE_TEXT),
            'required' => $request->boolean('required') ? 1 : 0,
            'constrained' => $request->boolean('constrained') ? 1 : 0,
            'sort_order' => (int) $request->input('sort_order', 0),
            'options' => $options,
        ];
    }
}
