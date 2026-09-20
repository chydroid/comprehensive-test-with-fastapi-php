<?php

declare(strict_types=1);

namespace App\Services\Composition;

/**
 * 组卷失败（仅「外部模型不可用」这一类）。
 *
 * 与 C4 阅卷的 GraderFailureException 同构：工厂捕获它后降级为本地抽样，
 * 组卷页永远不会因为模型服务挂了而打不开。其它异常（编程错误）照常抛出。
 */
final class ComposerFailureException extends \RuntimeException
{
}
