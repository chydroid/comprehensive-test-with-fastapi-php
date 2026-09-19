<?php

declare(strict_types=1);

namespace App\Services\Grading;

/**
 * 阅卷建议提供者（C4）。
 *
 * 为什么要有这层抽象：主观题评分这件事实质上有两种截然不同的实现——
 * 不联网的「关键词/相似度启发式」与调用外部大模型的「语义评分」。如果把它们
 * 写死在 SubjectiveGrading 里，就会出现「没配 API 就用不了 AI 建议」「配了 API
 * 但服务挂了整页报错」两种窘境。抽象出一个接口后：
 *
 *   HeuristicGrader（永远可用、零成本、可解释）
 *     ↑ 降级
 *   RemoteGrader（需配置、更准、有延迟与费用）
 *
 * 工厂按设置挑选；远程不可用或调用失败时自动落回启发式，且**不向调用方抛异常**。
 *
 * 两条硬性边界（勿越界）：
 *  1. 本接口只产出**建议分**，不写库。写入必须经由教师在批阅页确认保存
 *     （SubjectiveGrading::grade），AI 永远不能直接改成绩。
 *  2. 无法判断时必须返回 score = null，而不是猜一个分数。宁可不给建议，
 *     也不能给一个看似权威的错误分数。
 */
interface GraderProvider
{
    /** 稳定标识（落日志/审计用，不做展示） */
    public function key(): string;

    /** 展示名（前端标注建议分来源） */
    public function label(): string;

    /** 当前是否可用（未配置凭据 / 扩展缺失 → false） */
    public function isAvailable(): bool;

    /**
     * 为单道主观题给出建议分。
     *
     * @param array{
     *   paper_id?:int, title?:string, reference?:string,
     *   answer?:string, full_score?:int, type?:string
     * } $item
     * @return array{
     *   score:?int, confidence:float, reason:string,
     *   hits:string[], missing:string[], provider:string, provider_label:string
     * }
     */
    public function suggest(array $item): array;
}
