<?php
declare(strict_types=1);

namespace Dlm\Support;

use Throwable;

/**
 * OIDC 登录异常。reason 用于区分可重试的错误（如公钥轮换）。
 */
final class OidcException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $reason = '')
    {
        parent::__construct($message);
    }
}
