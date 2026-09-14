<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SiteConfig;

/**
 * 运行时设置（系统参数）
 *
 * 背景：原先「开考前 15 分钟才可入场」「登录限流 10 次 / 5 分钟」「每页 20 条」
 * 等运行参数被写死在代码里，运维调整必须改代码。本服务把这些参数收敛为
 * 一份 **schema 驱动** 的键值配置，落库复用 siteconfig 表（config_key/config_value），
 * 因此无需变更表结构：
 *
 *  - SCHEMA      ：声明全部可配项（分组 / 类型 / 默认值 / 取值范围 / 标签 / 帮助）
 *  - 读取         ：get()/int()/bool()，缺省值来自 SCHEMA，DB 无记录也能正常工作
 *  - 写入         ：putMany() 按键白名单 + 范围校验，非法值直接拒绝
 *  - 请求级缓存   ：一次请求内只查库一次；写入后自动失效
 *  - 前端可见子集 ：publicSubset() 只暴露无敏感信息的客户端参数
 *
 * 与 SiteConfig 的分工：siteconfig 表同时存放「站点展示信息」（标题/版权/电话），
 * 那部分仍由 ConfigController 的站点信息表单维护；本服务只管理运行时参数，
 * 两者共用一张表但键名不重叠。
 */
final class Setting
{
    /** 分组定义（顺序即前端标签页顺序） */
    public const GROUPS = [
        'exam'     => ['label' => '考试规则', 'desc' => '入场窗口、口令与成绩展示等考试行为', 'icon' => 'clipboard'],
        'security' => ['label' => '安全策略', 'desc' => '登录限流与密码强度要求', 'icon' => 'shield'],
        'ui'       => ['label' => '界面与体验', 'desc' => '分页条数与页面自动刷新频率', 'icon' => 'sliders'],
    ];

    /**
     * 全部可配项定义。
     *
     * 字段说明：
     *   group   分组键（见 GROUPS）
     *   type    int | bool
     *   default 缺省值（DB 无记录时使用）
     *   min/max int 类型的取值范围
     *   label   表单标签
     *   unit    单位（仅用于展示）
     *   hint    帮助文案
     *   public  是否允许通过无鉴权的 /api/public/settings 下发（仅限非敏感参数）
     */
    public const SCHEMA = [
        /* ---------------- 考试规则 ---------------- */
        'exam_entry_lead_minutes' => [
            'group' => 'exam', 'type' => 'int', 'default' => 15, 'min' => 0, 'max' => 1440,
            'label' => '提前入场时间', 'unit' => '分钟', 'public' => true,
            'hint'  => '开考前多久允许考生凭考场口令进入考场等待。0 表示不限制，随时可入场。',
        ],
        'exam_entry_late_minutes' => [
            'group' => 'exam', 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 1440,
            'label' => '开考后迟到入场宽限', 'unit' => '分钟', 'public' => true,
            'hint'  => '开考后仍允许入场的宽限时长。0 表示开考后一律不得入场（推荐）。',
        ],
        'exam_pwd_length' => [
            'group' => 'exam', 'type' => 'int', 'default' => 6, 'min' => 4, 'max' => 10,
            'label' => '考场口令位数', 'unit' => '位', 'public' => true,
            'hint'  => '「开放入场」时自动生成的随机口令长度，4–10 位数字。',
        ],
        'exam_allow_view_answer' => [
            'group' => 'exam', 'type' => 'bool', 'default' => 1, 'public' => true,
            'label' => '交卷后可查看答案解析', 'hint' => '关闭后考生交卷也不能查看答案与解析。',
        ],
        'exam_show_score_immediately' => [
            'group' => 'exam', 'type' => 'bool', 'default' => 1, 'public' => true,
            'label' => '交卷后立即显示成绩', 'hint' => '关闭后考生交卷时只提示已交卷，成绩稍后统一公布。',
        ],

        /* ---------------- 安全策略 ---------------- */
        'login_max_attempts' => [
            'group' => 'security', 'type' => 'int', 'default' => 10, 'min' => 3, 'max' => 100,
            'label' => '登录失败次数上限', 'unit' => '次',
            'hint'  => '同一 IP 在统计窗口内访问登录接口超过该次数将被拒绝，用于防暴力破解。',
        ],
        'login_window_minutes' => [
            'group' => 'security', 'type' => 'int', 'default' => 5, 'min' => 1, 'max' => 1440,
            'label' => '登录限流统计窗口', 'unit' => '分钟',
            'hint'  => '配合上一项使用：窗口内累计的登录请求次数超过上限即触发限流。',
        ],
        'password_min_length' => [
            'group' => 'security', 'type' => 'int', 'default' => 6, 'min' => 6, 'max' => 32,
            'label' => '密码最小长度', 'unit' => '位', 'public' => true,
            'hint'  => '管理员、教师、考生设置或修改密码时的最小长度要求。',
        ],
        'rate_limit_enabled' => [
            'group' => 'security', 'type' => 'bool', 'default' => 0,
            'label' => '启用全站接口限流',
            'hint'  => '对全部接口按 IP + 路径计数限流；登录接口另有更严格的独立配额。',
        ],
        'rate_limit_max_requests' => [
            'group' => 'security', 'type' => 'int', 'default' => 60, 'min' => 10, 'max' => 10000,
            'label' => '全站限流次数上限', 'unit' => '次',
            'hint'  => '仅在「启用全站接口限流」开启时生效。',
        ],
        'rate_limit_window_seconds' => [
            'group' => 'security', 'type' => 'int', 'default' => 60, 'min' => 10, 'max' => 3600,
            'label' => '全站限流统计窗口', 'unit' => '秒',
            'hint'  => '仅在「启用全站接口限流」开启时生效。',
        ],

        /* ---------------- 界面与体验 ---------------- */
        'page_size_default' => [
            'group' => 'ui', 'type' => 'int', 'default' => 20, 'min' => 5, 'max' => 200,
            'label' => '列表默认每页条数', 'unit' => '条',
            'hint'  => '后台各列表（题库、考生、成绩等）未通过参数指定时的每页条数。',
        ],
        'waiting_poll_seconds' => [
            'group' => 'ui', 'type' => 'int', 'default' => 4, 'min' => 2, 'max' => 60,
            'label' => '考场等待室轮询间隔', 'unit' => '秒', 'public' => true,
            'hint'  => '考生在等待室自动检测开考状态的间隔，越小越及时但请求越多。',
        ],
        'monitor_refresh_seconds' => [
            'group' => 'ui', 'type' => 'int', 'default' => 10, 'min' => 3, 'max' => 120,
            'label' => '监考页面自动刷新间隔', 'unit' => '秒', 'public' => true,
            'hint'  => '在线监考页自动拉取在场考生名单的间隔。',
        ],
    ];

    /** 请求级缓存：null = 尚未加载 */
    private static ?array $cache = null;

    /** 原始库值缓存（只含真实存在的行），用于区分「未配置」与「配置为默认值」 */
    private static ?array $rawCache = null;

    /* ==================================================================
     * 读取
     * ================================================================== */

    /** 全部设置（已按 SCHEMA 类型转换并补齐默认值） */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $raw = [];
        try {
            $raw = (new SiteConfig())->allAsMap();
        } catch (\Throwable $e) {
            // 表未就绪 / 数据库异常时退回默认值，避免整站不可用
            log_message('读取系统设置失败，已使用默认值: ' . $e->getMessage(), 'warning');
        }
        self::$rawCache = $raw;

        $out = [];
        foreach (self::SCHEMA as $key => $def) {
            $out[$key] = self::cast($def, $raw[$key] ?? null);
        }
        return self::$cache = $out;
    }

    /**
     * 原始库值；该键在 siteconfig 中没有记录时返回 null。
     *
     * 用于需要区分「管理员从未配置过」与「管理员配置成了默认值」的场景，
     * 例如限流总开关的默认值取自 config 文件，只有后台显式配置过才覆盖它。
     */
    public static function stored(string $key): ?string
    {
        self::all(); // 触发加载
        return self::$rawCache[$key] ?? null;
    }

    /** 取单项设置（已按类型转换） */
    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }
        return $default;
    }

    /** 取整型设置 */
    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    /** 取布尔设置 */
    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, $default);
        if (is_bool($v)) {
            return $v;
        }
        return (bool) $v;
    }

    /**
     * 客户端可见的设置子集（无鉴权接口下发用）。
     * 仅包含 SCHEMA 中标记 public 的键，避免泄露安全策略细节。
     */
    public static function publicSubset(): array
    {
        $all = self::all();
        $out = [];
        foreach (self::SCHEMA as $key => $def) {
            if (!empty($def['public'])) {
                $out[$key] = $all[$key];
            }
        }
        return $out;
    }

    /**
     * 供管理端渲染设置表单的元数据。
     * @return array{groups:array,fields:array<int,array<string,mixed>>}
     */
    public static function adminSchema(): array
    {
        $values = self::all();
        $fields = [];
        foreach (self::SCHEMA as $key => $def) {
            $fields[] = [
                'key'     => $key,
                'group'   => $def['group'],
                'type'    => $def['type'],
                'label'   => $def['label'],
                'unit'    => $def['unit'] ?? '',
                'hint'    => $def['hint'] ?? '',
                'min'     => $def['min'] ?? null,
                'max'     => $def['max'] ?? null,
                'default' => $def['default'],
                'value'   => $values[$key],
            ];
        }
        return ['groups' => self::GROUPS, 'fields' => $fields];
    }

    /* ==================================================================
     * 写入
     * ================================================================== */

    /**
     * 批量写入（仅接受 SCHEMA 白名单键，逐项做类型与范围校验）。
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed> 归一化后的新值
     * @throws \Core\HttpException 非法值 / 无可更新项
     */
    public static function putMany(array $input): array
    {
        $model = new SiteConfig();
        $saved = [];

        foreach (self::SCHEMA as $key => $def) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = self::normalize($key, $def, $input[$key]);
            $model->put($key, (string) $value);
            $saved[$key] = $value;
        }

        if ($saved === []) {
            throw new \Core\HttpException(400, '没有可更新的设置项', 40001);
        }

        self::flush();
        return $saved;
    }

    /** 清空请求级缓存（写入后调用） */
    public static function flush(): void
    {
        self::$cache = null;
        self::$rawCache = null;
    }

    /* ==================================================================
     * 内部
     * ================================================================== */

    /** DB 字符串 → 声明类型；缺失或非法时返回默认值 */
    private static function cast(array $def, mixed $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return $def['default'];
        }
        if ($def['type'] === 'bool') {
            return in_array(strtolower((string) $raw), ['1', 'true', 'on', 'yes'], true);
        }
        if (!is_numeric($raw)) {
            return $def['default'];
        }
        $n = (int) $raw;
        if (isset($def['min']) && $n < $def['min']) {
            return $def['default'];
        }
        if (isset($def['max']) && $n > $def['max']) {
            return $def['default'];
        }
        return $n;
    }

    /** 校验并归一化待写入的值；非法值抛 400 */
    private static function normalize(string $key, array $def, mixed $input): mixed
    {
        if ($def['type'] === 'bool') {
            if (is_bool($input)) {
                return $input ? 1 : 0;
            }
            $s = strtolower(trim((string) $input));
            if (in_array($s, ['1', 'true', 'on', 'yes'], true)) {
                return 1;
            }
            if (in_array($s, ['0', 'false', 'off', 'no', ''], true)) {
                return 0;
            }
            throw new \Core\HttpException(400, "设置项「{$def['label']}」取值无效", 40000);
        }

        // int
        if (is_bool($input) || !is_numeric($input)) {
            throw new \Core\HttpException(400, "设置项「{$def['label']}」必须是数字", 40000);
        }
        $n = (int) $input;
        $min = $def['min'] ?? PHP_INT_MIN;
        $max = $def['max'] ?? PHP_INT_MAX;
        if ($n < $min || $n > $max) {
            throw new \Core\HttpException(400, "设置项「{$def['label']}」需在 {$min}–{$max} 之间", 40000);
        }
        return $n;
    }
}
