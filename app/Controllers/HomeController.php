<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Exam;
use App\Models\ExamCategory;
use App\Models\ExamNews;
use App\Models\Grade;
use App\Models\SchoolClass;
use App\Models\SiteConfig;
use App\Models\Subject;
use App\Services\AuthSession;
use Core\Database;
use Core\Response;

/**
 * 前台门户：站点信息、帮助、科目/类别、单位班级、首页考试公告
 */
class HomeController extends BaseController
{
    /**
     * /api/public/site 允许对外暴露的站点配置键（纯展示信息，无安全语义）。
     * siteconfig 表同时存放 App\Services\Setting 的运行参数，故必须白名单过滤。
     */
    private const PUBLIC_SITE_KEYS = [
        'site_title', 'title', 'site_desc', 'copyright', 'icp', 'phone', 'address',
    ];

    /** GET /api/public/site —— 站点配置（标题/版权/联系方式）+ 基础统计 */
    public function site(): Response
    {
        // 只下发站点展示类字段。此前直接把整张 siteconfig 表 allAsMap() 抛出去，
        // 而 App\Services\Setting 把运行参数写在**同一张表**里 —— 管理员一保存
        // 「系统设置」，限流阈值 / 密码最小长度等安全参数就会从这个无鉴权接口泄露，
        // Setting::publicSubset() 的白名单设计也被架空。
        $all = (new SiteConfig())->allAsMap();
        $config = [];
        foreach (self::PUBLIC_SITE_KEYS as $key) {
            if (array_key_exists($key, $all)) {
                $config[$key] = $all[$key];
            }
        }
        $quizStats = Database::fetch(
            "SELECT
                SUM(CASE WHEN quiz_class='radio1'   THEN 1 ELSE 0 END) AS radio1,
                SUM(CASE WHEN quiz_class='radio2'   THEN 1 ELSE 0 END) AS radio2,
                SUM(CASE WHEN quiz_class='checkbox' THEN 1 ELSE 0 END) AS checkbox,
                SUM(CASE WHEN quiz_class='text'     THEN 1 ELSE 0 END) AS text,
                SUM(CASE WHEN quiz_class='longtext' THEN 1 ELSE 0 END) AS `longtext`,
                COUNT(*) AS total
             FROM `quizlib`"
        ) ?? [];

        return $this->ok([
            'config'     => $config,
            'quiz_stats' => [
                'total'    => (int) ($quizStats['total'] ?? 0),
                'radio1'   => (int) ($quizStats['radio1'] ?? 0),
                'radio2'   => (int) ($quizStats['radio2'] ?? 0),
                'checkbox' => (int) ($quizStats['checkbox'] ?? 0),
                'text'     => (int) ($quizStats['text'] ?? 0),
                'longtext' => (int) ($quizStats['longtext'] ?? 0),
            ],
            // 门户「考试总数」只统计正式考试：模拟考试是考生自主生成的临时记录，
            // 计入会让公开统计被个人练习行为稀释（与仪表盘口径保持一致）。
            'exam_count'     => (int) (Database::fetch(
                "SELECT COUNT(*) c FROM `examinfo` WHERE COALESCE(exam_class, '') <> ?",
                [\App\Models\Exam::MOCK_CLASS]
            )['c'] ?? 0),
            'student_count'  => (int) (Database::fetch('SELECT COUNT(*) c FROM `stuinfo`')['c'] ?? 0),
        ]);
    }

    /**
     * GET /api/public/settings —— 客户端可见的运行参数（无鉴权）
     *
     * 只下发 Setting::publicSubset() 中标记 public 的项（入场窗口、口令位数、
     * 轮询间隔等），供考生端/考场/监考页渲染准确文案与轮询节奏，
     * 避免把「开考前 15 分钟」之类的参数在多个前端里各写死一遍。
     * 安全策略类参数（限流阈值等）不在其中，不会泄露。
     */
    public function settings(): Response
    {
        return $this->ok(\App\Services\Setting::publicSubset());
    }

    /** GET /api/public/help —— 帮助页静态内容 */
    public function help(): Response
    {
        $site = (new SiteConfig())->allAsMap();
        // 站点标题优先取后台「系统设置 → 站点标题」，未配置（或空串）则回落产品名。
        // 注意必须用 ?? 取键：siteconfig 里可能压根没有 site_title 行，
        // 而本项目的错误处理会把「未定义数组键」告警升级成异常，直接用 ?: 会 500。
        $siteTitle = trim((string) ($site['site_title'] ?? ''));
        return $this->ok([
            'title'  => $siteTitle !== '' ? $siteTitle : (string) config('app.name', '深蓝网上考试系统'),
            'blocks' => [
                [
                    'heading' => '考试流程',
                    'items'   => [
                        '使用准考证号与密码登录，未注册的考生请先完成注册。',
                        '在考试列表中选择班级对应的考试，输入监考教师公布的考试口令进入考场。',
                        '答题过程中系统会自动保存，请勿关闭浏览器。',
                        '提交试卷后当日即可在“成绩查询”查看成绩。',
                    ],
                ],
                [
                    'heading' => '在线练习',
                    'items'   => [
                        '练习模式不限制次数，可随时开始，逐题查看答案解析。',
                        '模拟考试按真实考试规则组卷与计时，用于考前自测。',
                    ],
                ],
                [
                    'heading' => '成绩与证书',
                    'items'   => [
                        '成绩以交卷时系统判定为准，客观题自动判分。',
                        '如需成绩证明，请联系监考教师或管理员。',
                    ],
                ],
            ],
        ]);
    }

    /** GET /api/public/subjects */
    public function subjects(): Response
    {
        return $this->ok((new Subject())->all('id ASC'));
    }

    /** GET /api/public/categories */
    public function categories(): Response
    {
        return $this->ok((new ExamCategory())->all('sort_order ASC'));
    }

    /**
     * GET /api/public/exams —— 首页考试公告
     * 未登录考生：全部进行中考试；已登录考生：该考生尚未结束的考试。
     */
    public function exams(): Response
    {
        $student = AuthSession::get(AuthSession::STUDENT);
        if ($student !== null) {
            return $this->ok([
                'scope' => 'mine',
                'list'  => $this->pendingForClass((string) ($student['class_id'] ?? ''), (string) $student['id']),
            ]);
        }
        return $this->ok([
            'scope' => 'all',
            'list'  => Exam::activeWithSubject(),
        ]);
    }

    /** GET /api/public/options —— 注册/查询页所需下拉数据（单位、班级） */
    public function options(): Response
    {
        return $this->ok([
            'grades'  => (new Grade())->all('id ASC'),
            'classes' => (new SchoolClass())->all('id ASC'),
        ]);
    }

    /** GET /api/public/news —— 首页公告（最多 5 条） */
    public function news(): Response
    {
        return $this->ok(array_slice((new ExamNews())->all('id DESC'), 0, 5));
    }

    /**
     * 按班级匹配的待考列表（含该考生交卷状态）。
     *
     * 直接委托 Exam::pendingForStudent() —— 此处原先另写了一份几乎相同的 SQL，
     * 是「待考」定义的第三份拷贝：口径一变（例如新增排除模拟考试、排除已过
     * exam_end 的考场）就得三处同改，必然漏。（BUG-251 收敛）
     */
    private function pendingForClass(string $classId, string $stuId): array
    {
        return Exam::pendingForStudent($stuId, $classId);
    }
}
