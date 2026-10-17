<?php
declare(strict_types=1);

namespace Dlm\Support;

final class Request
{
    public string $method = 'GET';
    public string $path = '/';
    public array $query = [];
    public array $post = [];
    public array $headers = [];
    public mixed $json = null;
    public array $params = [];
    public array $files = [];
    public string $rawBody = '';

    public static function capture(): self
    {
        $req = new self();
        $req->method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $req->query = $_GET;
        $req->post = $_POST;
        $req->files = $_FILES;
        $req->headers = self::collectHeaders();
        $req->path = self::resolvePath();

        $req->rawBody = (string) file_get_contents('php://input');
        $contentType = strtolower((string) ($req->headers['content-type'] ?? ''));
        if ($req->rawBody !== '' && (str_contains($contentType, 'application/json') || str_starts_with(ltrim($req->rawBody), '{') || str_starts_with(ltrim($req->rawBody), '['))) {
            $decoded = json_decode($req->rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $req->json = $decoded;
            }
        }
        return $req;
    }

    private static function collectHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = strtolower(str_replace('_', '-', $key));
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    private static function resolvePath(): string
    {
        if (!empty($_SERVER['PATH_INFO'])) {
            return self::normalize((string) $_SERVER['PATH_INFO']);
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $uri = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');

        $scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
        if ($scriptDir !== '' && str_starts_with($uri, $scriptDir)) {
            $uri = substr($uri, strlen($scriptDir));
        }
        if (str_starts_with($uri, '/index.php')) {
            $uri = substr($uri, strlen('/index.php'));
        }

        if (isset($_GET['r']) && is_string($_GET['r']) && $_GET['r'] !== '') {
            $uri = '/' . ltrim($_GET['r'], '/');
        }

        return self::normalize($uri);
    }

    private static function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public static function isApiRequest(): bool
    {
        $path = self::resolvePath();
        return $path === '/v1' || str_starts_with($path, '/v1/');
    }

    public function isMethod(string ...$methods): bool
    {
        return in_array($this->method, array_map('strtoupper', $methods), true);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization', '');
        if ($auth !== null && preg_match('/^Bearer\s+(.+)$/i', trim($auth), $m) === 1) {
            return trim($m[1]);
        }
        return null;
    }

    public function hasJson(): bool
    {
        return $this->json !== null;
    }

    public function body(): array
    {
        if (is_array($this->json)) {
            return $this->json;
        }
        return $this->post;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $body = $this->body();
        return $body[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $body = $this->body();
        if (!array_key_exists($key, $body)) {
            return $default;
        }
        $v = $body[$key];
        if (is_bool($v)) {
            return $v;
        }
        return in_array((string) $v, ['1', 'true', 'on', 'yes'], true);
    }
}
