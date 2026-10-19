<?php
declare(strict_types=1);

namespace Dlm\Support;

final class Helpers
{
    /** 下载链接支持的协议 */
    public const URL_SCHEMES = ['http', 'https', 'ed2k', 'magnet'];

    /** links.url 列的最大长度（字符） */
    public const URL_MAX_LENGTH = 1500;

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

    public static function urlScheme(string $url): string
    {
        $url = trim($url);
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $url, $matches) !== 1) {
            return '';
        }
        return strtolower($matches[1]);
    }

    /**
     * 仅允许 http / https，用于 OIDC 等非下载场景。
     */
    public static function isHttpUrl(string $url): bool
    {
        $url = trim($url);
        $scheme = self::urlScheme($url);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * 下载链接支持 http / https / ed2k / magnet。
     */
    public static function isDownloadUrl(string $url): bool
    {
        return self::downloadUrlError($url) === null;
    }

    public static function downloadUrlError(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return '下载 URL 为必填项';
        }
        if (mb_strlen($url) > self::URL_MAX_LENGTH) {
            return '下载 URL 过长（最多 ' . self::URL_MAX_LENGTH . ' 个字符）';
        }
        $scheme = self::urlScheme($url);
        if ($scheme === '') {
            return '下载 URL 无效，必须以 http://、https://、ed2k:// 或 magnet:? 开头';
        }
        if (in_array($scheme, ['http', 'https'], true)) {
            return self::isHttpUrl($url) ? null : '下载 URL 无效，请检查 http/https 地址格式';
        }
        if ($scheme === 'ed2k') {
            return self::isEd2kUrl($url) ? null : 'ed2k 链接格式无效，应为 ed2k://|file|文件名|大小|哈希|/';
        }
        if ($scheme === 'magnet') {
            return self::isMagnetUrl($url) ? null : '磁力链接格式无效，需包含合法的 xt=urn:btih:... 参数';
        }
        return '不支持的 URL 协议：' . $scheme . '（仅支持 ' . implode('、', self::URL_SCHEMES) . '）';
    }

    private static function isEd2kUrl(string $url): bool
    {
        $body = substr(trim($url), strlen('ed2k:'));
        // 文件链接：ed2k://|file|文件名|字节数|32 位哈希|[/]，可选 AICH 哈希（|h=...|）
        if (preg_match('#^//\|file\|[^|\r\n]+\|\d+\|[0-9a-f]{32}\|(?:h=[0-9a-z]{32}\|)?/?$#i', $body) === 1) {
            return true;
        }
        // 服务器链接：ed2k://|server|地址|端口|/
        return preg_match('#^//\|server\|[^|\r\n]+\|\d{1,5}\|/?$#i', $body) === 1;
    }

    private static function isMagnetUrl(string $url): bool
    {
        $url = trim($url);
        $queryStart = strpos($url, '?');
        if ($queryStart === false) {
            return false;
        }
        foreach (explode('&', substr($url, $queryStart + 1)) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            if (strtolower(rawurldecode($parts[0])) !== 'xt' || !isset($parts[1])) {
                continue;
            }
            $urn = strtolower(rawurldecode($parts[1]));
            if (preg_match('#^urn:btih:(?:[0-9a-f]{40}|[a-z2-7]{32})$#', $urn) === 1) {
                return true;
            }
            if (preg_match('#^urn:btmh:[0-9a-z]+$#', $urn) === 1) {
                return true;
            }
            if (preg_match('#^urn:(?:sha1|md5|ed2k):[0-9a-z]{32}$#', $urn) === 1) {
                return true;
            }
        }
        return false;
    }

    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        $scheme = self::urlScheme($url);
        if ($scheme === 'ed2k') {
            // ed2k 整串小写：哈希相同即同一文件，文件名大小写差异不应产生重复记录
            return 'ed2k:' . strtolower(substr($url, strlen('ed2k:')));
        }
        if ($scheme === 'magnet') {
            return self::normalizeMagnetUrl($url);
        }
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $url;
        }
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return $url;
        }
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

    private static function normalizeMagnetUrl(string $url): string
    {
        $queryStart = strpos($url, '?');
        if ($queryStart === false) {
            return 'magnet:' . strtolower(substr($url, strlen('magnet:')));
        }
        $pairs = [];
        foreach (explode('&', substr($url, $queryStart + 1)) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $key = strtolower(rawurldecode($parts[0]));
            $value = isset($parts[1]) ? rawurldecode($parts[1]) : '';
            if ($key === 'xt') {
                // btih 哈希不区分大小写
                $value = strtolower($value);
            }
            $pairs[] = [$key, $value];
        }
        // 参数顺序不应影响去重
        usort($pairs, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $normalized = [];
        foreach ($pairs as $pair) {
            $normalized[] = rawurlencode($pair[0]) . '=' . rawurlencode($pair[1]);
        }
        return 'magnet:?' . implode('&', $normalized);
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
