<?php

declare(strict_types=1);

namespace App\Services\Composition;

/**
 * 组卷建议提供者（C4 AI 组卷）。
 *
 * 与阅卷一样，组卷也有两种本质不同的实现：
 *
 *   LocalComposer（永远可用、零成本） —— 从现有题库按科目 / 难度 / 知识点抽样
 *     ↑ 降级
 *   LlmComposer（需配置、能生成新题、有延迟与费用） —— 调大模型现场出题
 *
 * 抽象出接口后，工厂按设置挑远程，远程不可用或调用失败自动落回本地抽样，
 * 并且**不向调用方抛异常**。
 *
 * 两条硬性边界（勿越界）：
 *  1. 本接口只产出**建议题目列表**，不写库、不改考试。真正落库必须经教师在
 *     组卷页确认「采用」（ExamComposer::apply），AI 不能直接决定一场考试的卷面。
 *  2. 无法生成时必须返回空列表 + truncated=false，而不是抛错或瞎编——宁可
 *     给教师一份空的「建议」，也不能把不可信的内容塞进卷子。
 *
 * 题目形状（每个元素）：
 *   id         ?int   现有题库题的 id；本地抽样有值，远程生成恒为 null
 *   type       string quiz_class（radio1/radio2/checkbox/text/longtext）
 *   stem       string 题干
 *   options    ?array 选项 [{key,text}]（客观题有，主观题为 null）
 *   answer     string 参考答案 / 正确选项字母（客观题）或要点（主观题）
 *   kp         string 知识点
 *   difficulty string Y/Z/N
 *   analysis   ?string 解析（可为空）
 *   new        bool   是否远程新生成（true=尚未入库）
 */
interface ComposerProvider
{
    /** 稳定标识（落日志用，不做展示） */
    public function key(): string;

    /** 展示名（前端标注建议来源） */
    public function label(): string;

    /** 当前是否可用（未配置凭据 / 扩展缺失 → false） */
    public function isAvailable(): bool;

    /**
     * 生成建议题目。
     * @param array{subj_id:int,count:int,easy:int,mid:int,hard:int,kps?:array,types?:array} $spec
     * @return array{questions:array,source:string,truncated:bool}
     */
    public function compose(array $spec): array;
}
