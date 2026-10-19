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
        $route = $this->findRoute($request);

        // 未安装时统一跳转安装向导；带 allow_uninstalled 的路由（如 /healthz）不受此限制，
        // 由控制器自行报告真实状态，避免探针拿到 302 而误判为健康。
        if (!Config::installed()
            && ($route === null || empty($route['opts']['allow_uninstalled']))
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

        if ($route === null) {
            if (Request::isApiRequest()) {
                Response::apiError('接口不存在', 4040, 404);
            } else {
                Response::html('<h1>404 Not Found</h1>', 404);
            }
            return;
        }

        $args = [];
        foreach ($route['params'] as $index => $name) {
            $args[$name] = $route['matches'][$index] ?? null;
        }
        $request->params = $args;

        $this->runMiddleware($route['opts'], $request);

        [$class, $method] = $route['handler'];
        $controller = new $class();
        $controller->{$method}($request, ...array_values($args));
    }

    /**
     * 按请求方法与路径匹配路由，命中时把路径参数写入 matches。
     */
    private function findRoute(Request $request): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            if (preg_match($route['regex'], $request->path, $m) !== 1) {
                continue;
            }
            array_shift($m);
            $route['matches'] = $m;
            return $route;
        }
        return null;
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
