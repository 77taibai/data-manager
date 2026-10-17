<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$router = new Dlm\Support\Router();
require DLM_SRC . '/routes.php';

$router->dispatch(Dlm\Support\Request::capture());
