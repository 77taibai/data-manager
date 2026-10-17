<?php
declare(strict_types=1);

$uri = urldecode((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'));
$uri = '/' . ltrim($uri, '/');
$root = realpath(dirname(__DIR__));

if (preg_match('#^/(src|views|docs|logs|tests|\.git)(/|$)#', $uri) === 1 || $uri === '/config.php') {
    http_response_code(403);
    echo '403 Forbidden';
    return true;
}

$target = realpath($root . $uri);
if ($target !== false && is_file($target) && str_starts_with(str_replace('\\', '/', $target), str_replace('\\', '/', $root))) {
    return false;
}

require $root . '/index.php';
return true;
