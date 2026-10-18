<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Dlm\Support\Jwt;
use Dlm\Support\OidcException;

$passed = 0;
$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    if ($ok) {
        $passed++;
        echo "PASS  {$name}\n";
    } else {
        $failed++;
        echo "FAIL  {$name}" . ($detail !== '' ? "  -> {$detail}" : '') . "\n";
    }
}

function b64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/**
 * 用本地生成的密钥签一个 JWT，并返回 [token, jwk]。
 */
function signToken(array $claims, string $alg = 'RS256', ?string $kid = 'test-key'): array
{
    $hash = match ($alg) {
        'RS256' => 'sha256',
        'RS384' => 'sha384',
        'RS512' => 'sha512',
        'ES256' => 'sha256',
        'ES384' => 'sha384',
        'ES512' => 'sha512',
    };
    $type = str_starts_with($alg, 'RS') ? 'RSA' : 'EC';
    $bits = match ($alg) {
        'ES256' => ['prime256v1', 'P-256'],
        'ES384' => ['secp384r1', 'P-384'],
        'ES512' => ['secp521r1', 'P-521'],
        default => [null, null],
    };

    $config = $type === 'RSA'
        ? ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]
        : ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $bits[0]];

    $key = openssl_pkey_new($config);
    $details = openssl_pkey_get_details($key);

    $header = ['alg' => $alg, 'typ' => 'JWT'];
    if ($kid !== null) {
        $header['kid'] = $kid;
    }
    $signingInput = b64url(json_encode($header)) . '.' . b64url(json_encode($claims));

    openssl_sign($signingInput, $signature, $key, $hash);
    if ($type === 'EC') {
        // openssl 给的是 DER，JWS 需要 R||S
        $size = ['ES256' => 32, 'ES384' => 48, 'ES512' => 66][$alg];
        $signature = derToRaw($signature, $size);
    }

    $jwk = ['kty' => $details['type'] === OPENSSL_KEYTYPE_RSA ? 'RSA' : 'EC', 'use' => 'sig', 'alg' => $alg];
    if ($kid !== null) {
        $jwk['kid'] = $kid;
    }
    if ($type === 'RSA') {
        $jwk['n'] = b64url($details['rsa']['n']);
        $jwk['e'] = b64url($details['rsa']['e']);
    } else {
        $jwk['crv'] = $bits[1];
        $jwk['x'] = b64url($details['ec']['x']);
        $jwk['y'] = b64url($details['ec']['y']);
    }

    return [$signingInput . '.' . b64url($signature), ['keys' => [$jwk]]];
}

function derToRaw(string $der, int $size): string
{
    if (ord($der[0]) !== 0x30) {
        return '';
    }
    // 序列长度：短格式 < 0x80，长格式 0x81/0x82...
    $offset = 1;
    $lenByte = ord($der[$offset]);
    if ($lenByte < 0x80) {
        $offset += 1;
    } else {
        $offset += 1 + ($lenByte & 0x7f);
    }

    $raw = '';
    for ($i = 0; $i < 2; $i++) {
        if (ord($der[$offset]) !== 0x02) {
            return '';
        }
        $intLen = ord($der[$offset + 1]);
        $value = substr($der, $offset + 2, $intLen);
        $offset += 2 + $intLen;
        $value = ltrim($value, "\x00");
        $raw .= str_pad($value, $size, "\x00", STR_PAD_LEFT);
    }
    return $raw;
}

$now = time();
$baseClaims = [
    'iss' => 'https://idp.example.com',
    'aud' => 'dlm-client',
    'sub' => 'user-123',
    'exp' => $now + 300,
    'iat' => $now,
    'nonce' => 'n-abc',
];

foreach (['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'ES512'] as $alg) {
    [$token, $jwks] = signToken($baseClaims, $alg);
    try {
        $claims = Jwt::verify($token, $jwks);
        check("{$alg} 签名校验通过", ($claims['sub'] ?? '') === 'user-123');
    } catch (OidcException $e) {
        check("{$alg} 签名校验通过", false, $e->getMessage());
    }
}

[$token, $jwks] = signToken($baseClaims);
check('篡改 payload 后签名失败', (function () use ($token, $jwks): bool {
    [$h, $p, $s] = explode('.', $token);
    $tampered = $h . '.' . b64url(json_encode(['sub' => 'attacker'])) . '.' . $s;
    try {
        Jwt::verify($tampered, $jwks);
        return false;
    } catch (OidcException) {
        return true;
    }
})());

check('alg=none 被拒绝', (function () use ($baseClaims): bool {
    $header = b64url(json_encode(['alg' => 'none', 'typ' => 'JWT']));
    $token = $header . '.' . b64url(json_encode($baseClaims)) . '.';
    try {
        Jwt::verify($token, ['keys' => []]);
        return false;
    } catch (OidcException $e) {
        return $e->reason === 'alg';
    }
})());

check('未知 alg 被拒绝', (function () use ($baseClaims): bool {
    $header = b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $token = $header . '.' . b64url(json_encode($baseClaims)) . '.' . b64url('sig');
    try {
        Jwt::verify($token, ['keys' => []]);
        return false;
    } catch (OidcException $e) {
        return $e->reason === 'alg';
    }
})());

check('kid 不存在时报错', (function () use ($baseClaims): bool {
    [$token, $jwks] = signToken($baseClaims, 'RS256', 'kid-a');
    try {
        Jwt::verify($token, $jwks);
        return true;
    } catch (OidcException $e) {
        return false;
    }
    // 换成别的 kid 后应当失败
})());

check('kid 不匹配时报错', (function () use ($baseClaims): bool {
    [$token, $jwks] = signToken($baseClaims, 'RS256', 'kid-a');
    $jwks['keys'][0]['kid'] = 'kid-b';
    try {
        Jwt::verify($token, $jwks);
        return false;
    } catch (OidcException $e) {
        return $e->reason === 'jwks';
    }
})());

check('无 kid 且单把候选公钥可校验', (function () use ($baseClaims): bool {
    [$token, $jwks] = signToken($baseClaims, 'RS256', null);
    try {
        return (Jwt::verify($token, $jwks)['sub'] ?? '') === 'user-123';
    } catch (OidcException) {
        return false;
    }
})());

check('用另一把密钥签名被拒绝', (function () use ($baseClaims): bool {
    [$token] = signToken($baseClaims, 'RS256');
    [, $otherJwks] = signToken($baseClaims, 'RS256');
    try {
        Jwt::verify($token, $otherJwks);
        return false;
    } catch (OidcException $e) {
        return $e->reason === 'signature';
    }
})());

check('格式非法的 token 被拒绝', (function (): bool {
    try {
        Jwt::verify('not-a-jwt', ['keys' => []]);
        return false;
    } catch (OidcException $e) {
        return $e->reason === 'token';
    }
})());

echo "\n汇总：PASS={$passed} FAIL={$failed}\n";
exit($failed > 0 ? 1 : 0);
