<?php
declare(strict_types=1);

namespace Dlm\Support;

use Throwable;

/**
 * 零依赖 OIDC 客户端：授权码 + PKCE（S256）。
 * 配置来自 config.php 的 oidc 段，见 Config::oidc()。
 */
final class Oidc
{
    private const JWKS_TTL = 3600;

    public static function enabled(): bool
    {
        $cfg = Config::oidc();
        return $cfg['enabled']
            && $cfg['issuer'] !== ''
            && $cfg['client_id'] !== ''
            && $cfg['client_secret'] !== '';
    }

    /**
     * 生成授权跳转地址，并把 state/nonce/verifier 存进会话。
     */
    public static function buildAuthorizeUrl(string $redirectUri): string
    {
        $cfg = Config::oidc();
        $meta = self::discover();

        $state = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));
        $verifier = Jwt::base64UrlEncode(random_bytes(48));
        $challenge = Jwt::base64UrlEncode(hash('sha256', $verifier, true));

        $_SESSION['oidc_state'] = $state;
        $_SESSION['oidc_nonce'] = $nonce;
        $_SESSION['oidc_verifier'] = $verifier;
        $_SESSION['oidc_redirect'] = $redirectUri;

        $params = [
            'response_type' => 'code',
            'client_id' => $cfg['client_id'],
            'redirect_uri' => $redirectUri,
            'scope' => $cfg['scope'],
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        $authorizeEndpoint = (string) ($meta['authorization_endpoint'] ?? '');
        if ($authorizeEndpoint === '') {
            throw new OidcException('OIDC 发现文档缺少 authorization_endpoint', 'discovery');
        }
        $sep = str_contains($authorizeEndpoint, '?') ? '&' : '?';
        return $authorizeEndpoint . $sep . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 处理回调：校验 state → 换取 token → 验签 id_token → 校验 claims。
     * 返回 ['sub' => ..., 'claims' => [...]]。
     */
    public static function handleCallback(string $code, string $state): array
    {
        $expectedState = (string) ($_SESSION['oidc_state'] ?? '');
        $nonce = (string) ($_SESSION['oidc_nonce'] ?? '');
        $verifier = (string) ($_SESSION['oidc_verifier'] ?? '');
        $redirectUri = (string) ($_SESSION['oidc_redirect'] ?? '');

        unset($_SESSION['oidc_state'], $_SESSION['oidc_nonce'], $_SESSION['oidc_verifier'], $_SESSION['oidc_redirect']);

        if ($expectedState === '' || $state === '' || !hash_equals($expectedState, $state)) {
            throw new OidcException('state 校验失败，请重新登录', 'state');
        }
        if ($verifier === '') {
            throw new OidcException('缺少 PKCE verifier，请重新登录', 'state');
        }

        $tokens = self::exchangeCode($code, $redirectUri, $verifier);
        $idToken = (string) ($tokens['id_token'] ?? '');
        if ($idToken === '') {
            throw new OidcException('身份提供方未返回 id_token', 'token');
        }

        $claims = Jwt::verify($idToken, self::jwks());
        self::validateClaims($claims, $nonce);

        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '') {
            throw new OidcException('id_token 缺少 sub', 'claims');
        }

        return ['sub' => $sub, 'claims' => $claims];
    }

    /**
     * sub 是否在允许名单内（精确匹配，大小写敏感）。
     */
    public static function isAllowedSub(string $sub): bool
    {
        foreach (Config::oidcAllowedSubs() as $allowed) {
            if ($allowed !== '' && hash_equals($allowed, $sub)) {
                return true;
            }
        }
        return false;
    }

    private static function exchangeCode(string $code, string $redirectUri, string $verifier): array
    {
        $cfg = Config::oidc();
        $meta = self::discover();
        $tokenEndpoint = (string) ($meta['token_endpoint'] ?? '');
        if ($tokenEndpoint === '') {
            throw new OidcException('OIDC 发现文档缺少 token_endpoint', 'discovery');
        }

        $response = self::postForm($tokenEndpoint, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'code_verifier' => $verifier,
        ]);

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new OidcException('token 端点返回的不是 JSON', 'token');
        }
        if (isset($decoded['error'])) {
            $desc = (string) ($decoded['error_description'] ?? '');
            throw new OidcException('换取 token 失败：' . (string) $decoded['error'] . ($desc !== '' ? '（' . $desc . '）' : ''), 'token');
        }
        return $decoded;
    }

    private static function validateClaims(array $claims, string $nonce): void
    {
        $cfg = Config::oidc();
        $now = time();
        $skew = 120;

        $iss = (string) ($claims['iss'] ?? '');
        $expectedIss = self::expectedIssuer();
        if ($iss === '' || $iss !== $expectedIss) {
            throw new OidcException('id_token 的 iss 不匹配', 'claims');
        }

        $aud = $claims['aud'] ?? null;
        $audList = is_array($aud) ? $aud : [$aud];
        if (!in_array($cfg['client_id'], array_map('strval', $audList), true)) {
            throw new OidcException('id_token 的 aud 不匹配', 'claims');
        }
        if (is_array($aud) && count($aud) > 1) {
            $azp = (string) ($claims['azp'] ?? '');
            if ($azp !== $cfg['client_id']) {
                throw new OidcException('id_token 的 azp 不匹配', 'claims');
            }
        }

        if (!isset($claims['exp']) || !is_numeric($claims['exp'])) {
            throw new OidcException('id_token 缺少 exp', 'claims');
        }
        if ((int) $claims['exp'] + $skew < $now) {
            throw new OidcException('id_token 已过期', 'claims');
        }
        if (isset($claims['iat']) && is_numeric($claims['iat']) && (int) $claims['iat'] - $skew > $now) {
            throw new OidcException('id_token 的 iat 在未来', 'claims');
        }

        $tokenNonce = (string) ($claims['nonce'] ?? '');
        if ($nonce === '' || $tokenNonce === '' || !hash_equals($nonce, $tokenNonce)) {
            throw new OidcException('id_token 的 nonce 不匹配', 'claims');
        }
    }

    /**
     * 发现文档中的 issuer 可能带尾斜杠差异，这里以配置值为准做规范化比较。
     */
    private static function expectedIssuer(): string
    {
        return rtrim(Config::oidc()['issuer'], '/');
    }

    private static function discover(): array
    {
        $cfg = Config::oidc();
        $cacheKey = 'oidc_discovery_' . md5($cfg['issuer']);
        $cached = $_SESSION[$cacheKey] ?? null;
        if (is_array($cached) && (int) ($cached['_at'] ?? 0) + self::JWKS_TTL > time()) {
            return $cached;
        }

        $url = rtrim($cfg['issuer'], '/') . '/.well-known/openid-configuration';
        $body = self::get($url);
        $meta = json_decode($body, true);
        if (!is_array($meta)) {
            throw new OidcException('无法解析 OIDC 发现文档', 'discovery');
        }
        if (!isset($meta['issuer'])) {
            throw new OidcException('OIDC 发现文档缺少 issuer', 'discovery');
        }
        $meta['_at'] = time();
        $_SESSION[$cacheKey] = $meta;
        return $meta;
    }

    /**
     * JWKS 缓存到文件，避免每次登录都请求身份提供方。
     */
    private static function jwks(): array
    {
        $meta = self::discover();
        $jwksUri = (string) ($meta['jwks_uri'] ?? '');
        if ($jwksUri === '') {
            throw new OidcException('OIDC 发现文档缺少 jwks_uri', 'discovery');
        }

        $cacheFile = self::cacheDir() . '/oidc-jwks-' . md5($jwksUri) . '.json';
        if (is_file($cacheFile) && (int) @filemtime($cacheFile) + self::JWKS_TTL > time()) {
            $cached = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['keys'])) {
                return $cached;
            }
        }

        $jwks = json_decode(self::get($jwksUri), true);
        if (!is_array($jwks) || !isset($jwks['keys'])) {
            throw new OidcException('无法解析 JWKS', 'jwks');
        }
        @file_put_contents($cacheFile, json_encode($jwks), LOCK_EX);
        return $jwks;
    }

    private static function cacheDir(): string
    {
        $dir = DLM_ROOT . '/logs/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function get(string $url): string
    {
        [$status, $body] = self::request('GET', $url, null);
        if ($status < 200 || $status >= 300) {
            throw new OidcException('请求 ' . $url . ' 失败（HTTP ' . $status . '）', 'http');
        }
        return $body;
    }

    private static function postForm(string $url, array $fields): string
    {
        [$status, $body] = self::request('POST', $url, http_build_query($fields, '', '&', PHP_QUERY_RFC3986), [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);
        if ($status < 200 || $status >= 300) {
            throw new OidcException('请求 token 端点失败（HTTP ' . $status . '）', 'http');
        }
        return $body;
    }

    /**
     * @return array{0:int,1:string}
     */
    private static function request(string $method, string $url, ?string $body, array $headers = []): array
    {
        $headers = array_merge(['Accept: application/json', 'User-Agent: dlm-oidc/1.0'], $headers);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new OidcException('无法初始化 HTTP 请求', 'http');
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $body);
            }
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($response === false) {
                throw new OidcException('HTTP 请求失败：' . $error, 'http');
            }
            return [$status, (string) $response];
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'timeout' => 20,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        if ($response === false) {
            throw new OidcException('HTTP 请求失败', 'http');
        }
        return [$status, $response];
    }
}
