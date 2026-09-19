<?php

declare(strict_types=1);

namespace App\Services\Grading;

/**
 * 远程阅卷失败（凭据缺失、网络不通、超时、返回不可解析……）。
 *
 * 单独定义一个异常类型而不是复用 \RuntimeException，是为了让调用方能**精确**
 * 区分「模型服务不可用」（可降级）与「参数错误」（不该静默吞掉）。
 */
final class GraderFailureException extends \RuntimeException
{
}
