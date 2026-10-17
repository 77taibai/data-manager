<?php
declare(strict_types=1);

use Dlm\Controllers\Api\ApiController;

$router->get('/v1/schema', [ApiController::class, 'schema'], ['middleware' => 'api']);
$router->get('/v1/links', [ApiController::class, 'index'], ['middleware' => 'api']);
$router->post('/v1/links', [ApiController::class, 'store'], ['middleware' => 'api']);
$router->get('/v1/links/{id}', [ApiController::class, 'show'], ['middleware' => 'api']);
$router->patch('/v1/links/{id}', [ApiController::class, 'update'], ['middleware' => 'api']);
$router->delete('/v1/links/{id}', [ApiController::class, 'destroy'], ['middleware' => 'api']);
