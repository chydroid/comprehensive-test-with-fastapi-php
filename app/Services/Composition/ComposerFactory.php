<?php

declare(strict_types=1);

namespace App\Services\Composition;

/**
 * 组卷建议提供者工厂（C4）。
 *
 * 选择逻辑与阅卷工厂一致，但**降级必须发生在这里而不是调用处**：如果让每个调用点
 * 自己 try/catch，迟早有一处忘记，届时模型服务一挂教师就打不开组卷页。
 *
 *   未启用 / 未配凭据 / 无 curl  →  本地抽样
 *   远程可用                     →  远程模型
 *   远程调用抛 ComposerFailureException →  本次降级为本地抽样，并标记 degraded
 *
 * 注意「降级」是**整次**判定的（一场组卷一个请求，要么全远程要么全本地），
 * 不像阅卷那样逐题——因为组卷本身就是一次批量生成，远程失败重来成本可接受，
 * 没必要把两套结果混在一份卷子里。
 */
final class ComposerFactory
{
    private static ?LocalComposer $fallback = null;

    /** 当前应当使用的提供者（未配置时即本地抽样） */
    public static function make(): ComposerProvider
    {
        $remote = new LlmComposer();
        return $remote->isAvailable() ? $remote : self::fallback();
    }

    private static function fallback(): LocalComposer
    {
        return self::$fallback ??= new LocalComposer();
    }

    /**
     * 生成建议题目，远程失败时自动降级为本地抽样。
     * @param array{subj_id:int,count:int,easy:int,mid:int,hard:int,kps?:array,types?:array} $spec
     * @return array{questions:array,source:string,truncated:bool,degraded:bool,provider:string,degrade_reason:string}
     */
    public static function compose(array $spec): array
    {
        $provider = self::make();

        if ($provider instanceof LocalComposer) {
            $r = $provider->compose($spec);
            $r['degraded'] = false;
            $r['provider'] = $provider->key();
            $r['degrade_reason'] = '';
            return $r;
        }

        try {
            $r = $provider->compose($spec);
            $r['degraded'] = false;
            $r['provider'] = $provider->key();
            $r['degrade_reason'] = '';
            return $r;
        } catch (ComposerFailureException $e) {
            $local = self::fallback()->compose($spec);
            $local['degraded'] = true;
            $local['provider'] = 'local';
            $local['degrade_reason'] = $e->getMessage();
            return $local;
        }
    }

    /** 供前端标注「当前建议来自哪里」 */
    public static function providerInfo(): array
    {
        $p = self::make();
        return [
            'key'     => $p->key(),
            'label'   => $p->label(),
            'enabled' => true,   // 本地抽样永远可用，因此「AI 智能组卷」功能总是可点
        ];
    }
}
