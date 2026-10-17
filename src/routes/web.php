<?php
declare(strict_types=1);

use Dlm\Controllers\Web\AuthController;
use Dlm\Controllers\Web\InstallController;

$router->get('/install', [InstallController::class, 'show']);
$router->post('/install', [InstallController::class, 'run'], ['middleware' => 'csrf']);

$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login'], ['middleware' => 'csrf']);
$router->post('/logout', [AuthController::class, 'logout'], ['middleware' => 'csrf']);

// 其余页面路由见下方追加区（由 WebUI 模块补充）
