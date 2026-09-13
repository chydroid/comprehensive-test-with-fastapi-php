<?php
declare(strict_types=1);

namespace Core;

/**
 * HTTP 业务异常
 * 携带 HTTP 状态码与业务错误码，被全局异常处理器转为统一 JSON
 */
class HttpException extends \RuntimeException
{
    /** 405 时允许的方法列表，用于响应 Allow 头 */
    public array $allowedMethods = [];

    public function __construct(
        public readonly int $statusCode,
        string $message,
        public readonly int $errorCode = 0,
    ) {
        parent::__construct($message);
    }

    public function setAllowedMethods(array $methods): static
    {
        $this->allowedMethods = $methods;
        return $this;
    }
}
