<?php
declare(strict_types=1);

namespace Dlm\Controllers\Web;

use Dlm\Models\Repository;
use Dlm\Support\Flash;
use Dlm\Support\Request;
use Dlm\Support\Response;
use Dlm\Support\View;

final class RepositoryController
{
    public function index(Request $request): void
    {
        View::render('repositories/index', [
            'title' => '存储库',
            'repositories' => Repository::all(),
        ]);
    }

    public function create(Request $request): void
    {
        View::render('repositories/form', [
            'title' => '新建存储库',
            'repository' => null,
        ]);
    }

    public function store(Request $request): void
    {
        $name = trim((string) $request->input('name', ''));
        $description = trim((string) $request->input('description', ''));
        $slug = trim((string) $request->input('slug', ''));

        if ($name === '') {
            Flash::error('存储库名称不能为空');
            Response::redirect('/repos/new');
            return;
        }

        Repository::create($name, $description, $slug !== '' ? $slug : null);
        Flash::success('存储库已创建');
        Response::redirect('/');
    }

    public function edit(Request $request, string $slug): void
    {
        $repository = Repository::findBySlug($slug);
        if ($repository === null) {
            Response::html('<h1>404 存储库不存在</h1>', 404);
            return;
        }
        View::render('repositories/form', [
            'title' => '编辑存储库',
            'repository' => $repository,
        ]);
    }

    public function update(Request $request, string $slug): void
    {
        $repository = Repository::findBySlug($slug);
        if ($repository === null) {
            Response::html('<h1>404 存储库不存在</h1>', 404);
            return;
        }

        $name = trim((string) $request->input('name', ''));
        $description = trim((string) $request->input('description', ''));
        $newSlug = trim((string) $request->input('slug', ''));

        if ($name === '') {
            Flash::error('存储库名称不能为空');
            Response::redirect('/repos/' . (string) $repository['slug'] . '/edit');
            return;
        }

        Repository::update((int) $repository['id'], $name, $description, $newSlug !== '' ? $newSlug : null);
        Flash::success('存储库已更新');
        Response::redirect('/');
    }

    public function destroy(Request $request, string $slug): void
    {
        $repository = Repository::findBySlug($slug);
        if ($repository === null) {
            Response::html('<h1>404 存储库不存在</h1>', 404);
            return;
        }
        Repository::delete((int) $repository['id']);
        Flash::success('存储库已删除');
        Response::redirect('/');
    }
}
