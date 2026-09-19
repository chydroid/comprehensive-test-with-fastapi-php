<?php

declare(strict_types=1);

namespace App\Services\Grading;

/**
 * 阅卷建议提供者工厂（C4）。
 *
 * 选择逻辑很简单，但**降级必须发生在这里而不是调用处**：如果让每个调用点自己
 * try/catch，迟早有一处忘记，届时模型服务一挂教师就打不开批阅页。
 *
 *   未启用 / 未配凭据 / 无 curl  →  本地启发式
 *   远程可用                     →  远程模型
 *   远程调用抛 GraderFailureException →  本次降级为启发式，并标记 degraded
 *
 * 注意「降级」是**逐题**判定的：一场考试里前几题用模型、中途服务超时，
 * 后面的题目自动改用启发式，教师看到的是同一份完整建议，只是来源标注不同。
 */
final class GraderFactory
{
    private static ?GraderProvider $fallback = null;

    /** 当前应当使用的提供者（未配置时即启发式） */
    public static function make(): GraderProvider
    {
        $remote = new RemoteGrader();
        return $remote->isAvailable() ? $remote : self::fallback();
    }

    private static function fallback(): GraderProvider
    {
        return self::$fallback ??= new HeuristicGrader();
    }

    /**
     * 为单题取建议分，远程失败时自动降级。
     *
     * @param array{paper_id?:int,title?:string,reference?:string,answer?:string,full_score?:int} $item
     * @return array{score:?int,confidence:float,reason:string,hits:string[],missing:string[],provider:string,provider_label:string,degraded:bool}
     */
    public static function suggest(array $item): array
    {
        $provider = self::make();

        if ($provider instanceof HeuristicGrader) {
            return $provider->suggest($item) + ['degraded' => false];
        }

        try {
            return $provider->suggest($item) + ['degraded' => false];
        } catch (GraderFailureException $e) {
            // 只吞「服务不可用」这一类；其它异常（编程错误）照常抛出，便于排查
            $local = self::fallback()->suggest($item);
            $local['degraded'] = true;
            $local['provider_label'] = '本地启发式（远程模型不可用，已自动降级）';
            if ($local['reason'] !== '') {
                $local['reason'] .= '（远程模型调用失败：' . $e->getMessage() . '）';
            }
            return $local;
        }
    }

    /** 供前端标注「当前建议分来自哪里」 */
    public static function providerInfo(): array
    {
        $p = self::make();
        return [
            'key'     => $p->key(),
            'label'   => $p->label(),
            'enabled' => true,   // 启发式永远可用，因此「AI 建议」功能总是可点
        ];
    }
}
