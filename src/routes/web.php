<?php
declare(strict_types=1);

use Dlm\Controllers\Web\ApiKeyController;
use Dlm\Controllers\Web\AttributeController;
use Dlm\Controllers\Web\AuthController;
use Dlm\Controllers\Web\InstallController;
use Dlm\Controllers\Web\LinkController;
use Dlm\Controllers\Web\RepositoryController;

$router->get('/install', [InstallController::class, 'show']);
$router->post('/install', [InstallController::class, 'run'], ['middleware' => 'csrf']);

$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login'], ['middleware' => 'csrf']);
$router->post('/logout', [AuthController::class, 'logout'], ['middleware' => 'csrf']);

// WebUI 管理界面路由
$router->get('/', [RepositoryController::class, 'index']);
$router->get('/repos/new', [RepositoryController::class, 'create']);
$router->post('/repos', [RepositoryController::class, 'store'], ['middleware' => ['admin', 'csrf']]);
$router->get('/repos/{slug}', [LinkController::class, 'index']);
$router->get('/repos/{slug}/edit', [RepositoryController::class, 'edit']);
$router->post('/repos/{slug}', [RepositoryController::class, 'update'], ['middleware' => ['admin', 'csrf']]);
$router->post('/repos/{slug}/delete', [RepositoryController::class, 'destroy'], ['middleware' => ['admin', 'csrf']]);
$router->post('/repos/{slug}/links', [LinkController::class, 'store'], ['middleware' => ['admin', 'csrf']]);
$router->post('/repos/{slug}/links/bulk', [LinkController::class, 'bulk'], ['middleware' => ['admin', 'csrf']]);
$router->post('/repos/{slug}/links/{id}', [LinkController::class, 'update'], ['middleware' => ['admin', 'csrf']]);
$router->post('/repos/{slug}/links/{id}/delete', [LinkController::class, 'destroy'], ['middleware' => ['admin', 'csrf']]);
$router->get('/repos/{slug}/export', [LinkController::class, 'export'], ['middleware' => 'admin']);
$router->get('/repos/{slug}/import', [LinkController::class, 'importForm'], ['middleware' => 'admin']);
$router->post('/repos/{slug}/import/preview', [LinkController::class, 'importPreview'], ['middleware' => ['admin', 'csrf']]);
$router->post('/repos/{slug}/import', [LinkController::class, 'import'], ['middleware' => ['admin', 'csrf']]);
$router->get('/repos/{slug}/attributes', [AttributeController::class, 'index'], ['middleware' => 'admin']);
$router->post('/repos/{slug}/attributes', [AttributeController::class, 'store'], ['middleware' => ['admin', 'csrf']]);
$router->post('/repos/{slug}/attributes/{id}', [AttributeController::class, 'update'], ['middleware' => ['admin', 'csrf']]);
$router->post('/repos/{slug}/attributes/{id}/delete', [AttributeController::class, 'destroy'], ['middleware' => ['admin', 'csrf']]);
$router->get('/keys', [ApiKeyController::class, 'index'], ['middleware' => 'admin']);
$router->post('/keys', [ApiKeyController::class, 'store'], ['middleware' => ['admin', 'csrf']]);
$router->post('/keys/{id}/delete', [ApiKeyController::class, 'destroy'], ['middleware' => ['admin', 'csrf']]);
