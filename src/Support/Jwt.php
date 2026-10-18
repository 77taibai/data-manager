<?php
declare(strict_types=1);

namespace Dlm\Support;

/**
 * 零依赖的 JWT 校验：只做 OIDC id_token 需要的部分。
 * 支持 RS256/384/512 与 ES256/384/512，公钥来自 JWKS（JWK → PEM 由本类手工拼 DER）。
 */
final class Jwt
{
    private const ALGS = [
        'RS256' => ['hash' => 'sha256', 'kty' => 'RSA', 'curve' => null],
        'RS384' => ['hash' => 'sha384', 'kty' => 'RSA', 'curve' => null],
        'RS512' => ['hash' => 'sha512', 'kty' => 'RSA', 'curve' => null],
        'ES256' => ['hash' => 'sha256', 'kty' => 'EC', 'curve' => 'P-256'],
        'ES384' => ['hash' => 'sha384', 'kty' => 'EC', 'curve' => 'P-384'],
        'ES512' => ['hash' => 'sha512', 'kty' => 'EC', 'curve' => 'P-521'],
    ];

    /**
     * 校验签名并返回 claims。失败抛 OidcException。
     */
    public static function verify(string $token, array $jwks): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new OidcException('id_token 格式不正确', 'token');
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = self::decodeJson($encodedHeader);
        $alg = (string) ($header['alg'] ?? '');
        // 白名单比对即可：'none' 与所有对称算法（HS*）都不在表里，一律拒绝
        if (!isset(self::ALGS[$alg])) {
            throw new OidcException('不支持的 id_token 签名算法：' . ($alg === '' ? '(空)' : $alg), 'alg');
        }

        $jwk = self::pickKey($jwks, $header);
        $publicKey = self::jwkToPem($jwk);
        $signature = self::base64UrlDecode($encodedSignature);

        if (self::ALGS[$alg]['kty'] === 'EC') {
            $signature = self::ecdsaRawToDer($signature);
        }

        $ok = openssl_verify(
            $encodedHeader . '.' . $encodedPayload,
            $signature,
            $publicKey,
            self::ALGS[$alg]['hash']
        );
        if ($ok !== 1) {
            throw new OidcException('id_token 签名校验失败', 'signature');
        }

        return self::decodeJson($encodedPayload);
    }

    private static function pickKey(array $jwks, array $header): array
    {
        $keys = $jwks['keys'] ?? [];
        if (!is_array($keys) || $keys === []) {
            throw new OidcException('JWKS 中没有可用公钥', 'jwks');
        }
        $kid = isset($header['kid']) ? (string) $header['kid'] : '';
        $kty = self::ALGS[(string) $header['alg']]['kty'];
        $curve = self::ALGS[(string) $header['alg']]['curve'];

        $candidates = [];
        foreach ($keys as $key) {
            if (!is_array($key) || ($key['kty'] ?? '') !== $kty) {
                continue;
            }
            if ($curve !== null && ($key['crv'] ?? '') !== $curve) {
                continue;
            }
            if (isset($key['use']) && $key['use'] !== 'sig') {
                continue;
            }
            $candidates[] = $key;
        }

        if ($kid !== '') {
            foreach ($candidates as $key) {
                if ((string) ($key['kid'] ?? '') === $kid) {
                    return $key;
                }
            }
            throw new OidcException('JWKS 中找不到 kid=' . $kid . ' 的公钥', 'jwks');
        }
        if (count($candidates) === 1) {
            return $candidates[0];
        }
        if ($candidates === []) {
            throw new OidcException('JWKS 中没有匹配算法的公钥', 'jwks');
        }
        throw new OidcException('id_token 未指明 kid，且 JWKS 有多把候选公钥', 'jwks');
    }

    private static function jwkToPem(array $jwk): string
    {
        $kty = (string) ($jwk['kty'] ?? '');
        if ($kty === 'RSA') {
            $n = self::base64UrlDecode((string) ($jwk['n'] ?? ''));
            $e = self::base64UrlDecode((string) ($jwk['e'] ?? ''));
            if ($n === '' || $e === '') {
                throw new OidcException('RSA 公钥缺少 n/e', 'jwks');
            }
            $rsaKey = self::derSequence(self::derInteger($n) . self::derInteger($e));
            $algId = self::derSequence(self::derOid('1.2.840.113549.1.1.1') . "\x05\x00");
            $spki = self::derSequence($algId . self::derBitString($rsaKey));
            return self::pem('PUBLIC KEY', $spki);
        }

        if ($kty === 'EC') {
            $curve = (string) ($jwk['crv'] ?? '');
            $curveOid = match ($curve) {
                'P-256' => '1.2.840.10045.3.1.7',
                'P-384' => '1.3.132.0.34',
                'P-521' => '1.3.132.0.35',
                default => throw new OidcException('不支持的椭圆曲线：' . $curve, 'jwks'),
            };
            $size = match ($curve) {
                'P-256' => 32,
                'P-384' => 48,
                'P-521' => 66,
            };
            $x = self::base64UrlDecode((string) ($jwk['x'] ?? ''));
            $y = self::base64UrlDecode((string) ($jwk['y'] ?? ''));
            if ($x === '' || $y === '') {
                throw new OidcException('EC 公钥缺少 x/y', 'jwks');
            }
            $point = "\x04" . str_pad($x, $size, "\x00", STR_PAD_LEFT) . str_pad($y, $size, "\x00", STR_PAD_LEFT);
            $algId = self::derSequence(self::derOid('1.2.840.10045.2.1') . self::derOid($curveOid));
            $spki = self::derSequence($algId . self::derBitString($point));
            return self::pem('PUBLIC KEY', $spki);
        }

        throw new OidcException('不支持的公钥类型：' . $kty, 'jwks');
    }

    private static function pem(string $label, string $der): string
    {
        $body = chunk_split(base64_encode($der), 64, "\n");
        return "-----BEGIN {$label}-----\n{$body}-----END {$label}-----\n";
    }

    private static function derSequence(string $content): string
    {
        return "\x30" . self::derLength(strlen($content)) . $content;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    private static function derBitString(string $bytes): string
    {
        $payload = "\x00" . $bytes;
        return "\x03" . self::derLength(strlen($payload)) . $payload;
    }

    private static function derOid(string $oid): string
    {
        $parts = array_map('intval', explode('.', $oid));
        $body = chr($parts[0] * 40 + $parts[1]);
        for ($i = 2, $n = count($parts); $i < $n; $i++) {
            $value = $parts[$i];
            $chunk = chr($value & 0x7f);
            $value >>= 7;
            while ($value > 0) {
                $chunk = chr(0x80 | ($value & 0x7f)) . $chunk;
                $value >>= 7;
            }
            $body .= $chunk;
        }
        return "\x06" . self::derLength(strlen($body)) . $body;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * JWS 里 ECDSA 签名是 R||S 裸拼接，openssl 需要 DER 序列。
     */
    private static function ecdsaRawToDer(string $raw): string
    {
        $length = strlen($raw);
        if ($length === 0 || $length % 2 !== 0) {
            throw new OidcException('ECDSA 签名长度不正确', 'signature');
        }
        $half = intdiv($length, 2);
        return self::derSequence(
            self::derInteger(substr($raw, 0, $half)) . self::derInteger(substr($raw, $half))
        );
    }

    private static function decodeJson(string $encoded): array
    {
        $json = self::base64UrlDecode($encoded);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new OidcException('id_token 内容不是合法 JSON', 'token');
        }
        return $decoded;
    }

    public static function base64UrlDecode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad > 0) {
            $value .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($value, true);
        return $decoded === false ? '' : $decoded;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
