<?php
declare(strict_types=1);

namespace Dlm\Support;

final class ApiException extends \RuntimeException
{
    public int $apiCode;
    public int $httpStatus;

    public function __construct(string $message, int $apiCode, int $httpStatus)
    {
        parent::__construct($message);
        $this->apiCode = $apiCode;
        $this->httpStatus = $httpStatus;
    }

    public static function badRequest(string $message): self
    {
        return new self($message, 4000, 400);
    }

    public static function unauthorized(string $message = '未认证或凭证无效'): self
    {
        return new self($message, 4010, 401);
    }

    public static function forbidden(string $message = '没有权限执行该操作'): self
    {
        return new self($message, 4030, 403);
    }

    public static function notFound(string $message = '资源不存在'): self
    {
        return new self($message, 4040, 404);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 4090, 409);
    }

    public static function unprocessable(string $message): self
    {
        return new self($message, 4220, 422);
    }
}
