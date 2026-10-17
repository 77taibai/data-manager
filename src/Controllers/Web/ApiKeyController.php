<?php
declare(strict_types=1);

namespace Dlm\Controllers\Web;

use Dlm\Models\ApiKey;
use Dlm\Models\Repository;
use Dlm\Support\Flash;
use Dlm\Support\Request;
use Dlm\Support\Response;
use Dlm\Support\View;

final class ApiKeyController
{
    public function index(Request $request): void
    {
        View::render('keys/index', [
            'title' => 'APIKEY',
            'keys' => ApiKey::all(),
            'repositories' => Repository::all(),
            'newToken' => null,
            'newRepoName' => null,
            'newKeyName' => null,
        ]);
    }

    public function store(Request $request): void
    {
        $repositoryId = (int) $request->input('repository_id', 0);
        $repository = $repositoryId > 0 ? Repository::find($repositoryId) : null;
        if ($repository === null) {
            Flash::error('请选择有效的存储库');
            Response::redirect('/keys');
            return;
        }

        $name = trim((string) $request->input('name', ''));
        $name = $name === '' ? null : $name;

        $permissions = (string) $request->input('permissions', 'read');
        if (!in_array($permissions, ApiKey::PERMISSIONS, true)) {
            $permissions = 'read';
        }

        $expiresAt = trim((string) $request->input('expires_at', ''));
        if ($expiresAt === '') {
            $expiresAt = null;
        } else {
            $expiresAt = str_replace('T', ' ', $expiresAt);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $expiresAt) === 1) {
                $expiresAt .= ':00';
            }
        }

        $result = ApiKey::create($repositoryId, $name, $permissions, $expiresAt);

        View::render('keys/index', [
            'title' => 'APIKEY',
            'keys' => ApiKey::all(),
            'repositories' => Repository::all(),
            'newToken' => $result['token'],
            'newRepoName' => (string) $repository['name'],
            'newKeyName' => $name,
        ]);
    }

    public function destroy(Request $request, string $id): void
    {
        ApiKey::revoke((int) $id);
        Flash::success('APIKEY 已吊销');
        Response::redirect('/keys');
    }
}
