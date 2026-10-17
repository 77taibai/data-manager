<?php
declare(strict_types=1);

namespace Dlm\Support;

final class Router
{
    private array $routes = [];

    public function get(string $pattern, array $handler, array $opts = []): void
    {
        $this->add('GET', $pattern, $handler, $opts);
    }

    public function post(string $pattern, array $handler, array $opts = []): void
    {
        $this->add('POST', $pattern, $handler, $opts);
    }

    public function put(string $pattern, array $handler, array $opts = []): void
    {
        $this->add('PUT', $pattern, $handler, $opts);
    }

    public function patch(string $pattern, array $handler, array $opts = []): void
    {
        $this->add('PATCH', $pattern, $handler, $opts);
    }

    public function delete(string $pattern, array $handler, array $opts = []): void
    {
        $this->add('DELETE', $pattern, $handler, $opts);
    }

    private function add(string $method, string $pattern, array $handler, array $opts): void
    {
        $pattern = '/' . trim($pattern, '/');
        if ($pattern === '/') {
            $pattern = '/';
        }
        $paramNames = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function (array $m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $regex = '#^' . str_replace('#', '\#', $regex) . '$#';

        $this->routes[] = [
            'method' => $method,
            'pattern' => $pattern,
            'regex' => $regex,
            'params' => $paramNames,
            'handler' => $handler,
            'opts' => $opts,
        ];
    }

    public function dispatch(Request $request): void
    {
        if (!Config::installed()
            && $request->path !== '/install'
            && !str_starts_with($request->path, '/install/')
        ) {
            if (Request::isApiRequest()) {
                Response::apiError('系统尚未安装完成', 5030, 503);
            } else {
                Response::redirect('/install');
            }
            return;
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            if (preg_match($route['regex'], $request->path, $m) !== 1) {
                continue;
            }
            array_shift($m);
            $args = [];
            foreach ($route['params'] as $index => $name) {
                $args[$name] = $m[$index] ?? null;
            }
            $request->params = $args;

            $this->runMiddleware($route['opts'], $request);

            [$class, $method] = $route['handler'];
            $controller = new $class();
            $controller->{$method}($request, ...array_values($args));
            return;
        }

        if (Request::isApiRequest()) {
            Response::apiError('接口不存在', 4040, 404);
        } else {
            Response::html('<h1>404 Not Found</h1>', 404);
        }
    }

    private function runMiddleware(array $opts, Request $request): void
    {
        $middleware = $opts['middleware'] ?? [];
        if (is_string($middleware)) {
            $middleware = [$middleware];
        }
        foreach ($middleware as $name) {
            match ($name) {
                'admin' => Auth::requireAdmin(),
                'csrf' => Csrf::assertOrFail($request),
                'api' => ApiAuth::authenticate($request),
                default => null,
            };
        }
    }
}
