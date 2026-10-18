<?php
declare(strict_types=1);

namespace Dlm\Support;

final class Helpers
{
    private static ?string $base = null;

    public static function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function base(): string
    {
        if (self::$base === null) {
            $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
            self::$base = $dir === '/' ? '' : $dir;
        }
        return self::$base;
    }

    public static function url(string $path = '/'): string
    {
        if ($path === '' || $path === '/') {
            return self::base() . '/';
        }
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }
        return self::base() . '/' . ltrim($path, '/');
    }

    public static function asset(string $path): string
    {
        return self::base() . '/public/' . ltrim($path, '/');
    }

    /**
     * 把站内路径拼成绝对 URL（用于 OIDC 回调等外部回跳地址）。
     */
    public static function absolute(string $path = '/'): string
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $scheme = $isHttps ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
        if ($host === '') {
            $host = 'localhost';
        }
        return $scheme . '://' . $host . self::url($path);
    }

    public static function slugify(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '';
        $value = strtolower(trim($value, '-'));
        return $value;
    }

    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
        return $scheme . '://' . $host . $port . $path . $query . $fragment;
    }

    public static function urlHash(string $url): string
    {
        return hash('sha256', self::normalizeUrl($url));
    }

    public static function isValidUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    public static function parseSizeToBytes(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_float($value)) {
            return $value >= 0 ? (int) round($value) : null;
        }
        $str = trim((string) $value);
        if ($str === '') {
            return null;
        }
        if (preg_match('/^(\d+)$/', $str) === 1) {
            return (int) $str;
        }
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*(B|KB|MB|GB|TB)$/i', $str, $m) === 1) {
            $units = ['B' => 1, 'KB' => 1024, 'MB' => 1048576, 'GB' => 1073741824, 'TB' => 1099511627776];
            $unit = strtoupper($m[2]);
            return (int) round((float) $m[1] * $units[$unit]);
        }
        return null;
    }

    public static function formatBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '';
        }
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $units[$i];
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function jsonEncode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
