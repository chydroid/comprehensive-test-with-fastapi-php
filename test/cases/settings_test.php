<?php

declare(strict_types=1);

/**
 * 系统设置（运行时参数）测试
 *
 * 背景：原先「开考前 15 分钟才可入场」「登录限流 10 次 / 5 分钟」「列表每页 20 条」
 * 等参数写死在代码里。本用例验证它们已收敛为可由后台配置、且配置**真正生效**：
 *
 *   1. 服务层：默认值、类型转换、范围校验、白名单、public 子集
 *   2. 接口层：公开设置只下发非敏感项；管理接口需鉴权 + CSRF + 权限点
 *   3. 行为联动：入场窗口 / 迟到宽限 / 口令位数 / 密码最小长度 / 分页条数
 *      / 交卷是否立即显示成绩 / 交卷后能否看答案解析
 *
 * 用例结束会把 siteconfig 中本次涉及的行删除，恢复出厂默认，不污染运行环境。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use App\Models\Exam;
use App\Services\Password;
use App\Services\Setting;
use Test\Fixture;
use Test\Harness;
use Test\Http;

if (ob_get_level() === 0) {
    ob_start();
}

$base = dirname(__DIR__, 2);
require $base . '/test/lib/Harness.php';
require $base . '/test/lib/Http.php';
require $base . '/test/lib/Fixture.php';

bootstrap();

$t = new Harness();
echo "== 系统设置测试 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('系统设置', '数据库不可用');
    exit($t->finish());
}

/** 受测的全部配置键（与 App\Services\Setting::SCHEMA 对应） */
const S_KEYS = [
    'exam_entry_lead_minutes', 'exam_entry_late_minutes', 'exam_pwd_length',
    'exam_allow_view_answer', 'exam_show_score_immediately',
    'login_max_attempts', 'login_window_minutes', 'password_min_length',
    'rate_limit_enabled', 'rate_limit_max_requests', 'rate_limit_window_seconds',
    'page_size_default', 'waiting_poll_seconds', 'monitor_refresh_seconds',
];

const S_ADMIN = '__TEST__setadmin';
const S_PASS  = 'setAdminPwd123';
const S_LOW   = '__TEST__setlow';
const S_LOWPWD = 'setLowPwd123';

/** 删除本次写入的设置行，回到默认值 */
function settingsReset(): void
{
    \Core\Database::query(
        'DELETE FROM `siteconfig` WHERE config_key IN ('
        . implode(',', array_fill(0, count(S_KEYS), '?')) . ')',
        S_KEYS
    );
    Setting::flush();
}

function settingsAdminCleanup(): void
{
    \Core\Database::query('DELETE FROM `admininfo` WHERE username LIKE ?', ['__TEST__set%']);
}

settingsReset();
settingsAdminCleanup();
Fixture::cleanup();

/* ==================================================================
 * 1. 服务层
 * ================================================================== */

$t->guard('默认值：未配置时读取 schema 默认值', function () use ($t) {
    Setting::flush();
    $all = Setting::all();
    $t->assertSame('考试入场提前量默认 15 分钟', 15, (int) ($all['exam_entry_lead_minutes'] ?? -1));
    $t->assertSame('迟到宽限默认 0（开考后不得入场）', 0, (int) ($all['exam_entry_late_minutes'] ?? -1));
    $t->assertSame('口令位数默认 6', 6, (int) ($all['exam_pwd_length'] ?? -1));
    $t->assertSame('每页条数默认 20', 20, (int) ($all['page_size_default'] ?? -1));
    $t->assertSame('全部 schema 键均已返回', count(S_KEYS), count(array_intersect(S_KEYS, array_keys($all))));
});

$t->guard('类型转换：int 与 bool 各按声明类型返回', function () use ($t) {
    Setting::putMany([
        'exam_entry_lead_minutes'     => 25,
        'exam_show_score_immediately' => 'true',
        'rate_limit_enabled'          => 1,
        'password_min_length'         => 8,
    ]);
    $t->assertSame('int 读取', 25, Setting::int('exam_entry_lead_minutes'));
    $t->assertTrue('bool true 读取', Setting::bool('exam_show_score_immediately') === true);
    $t->assertTrue('bool 1 读取', Setting::bool('rate_limit_enabled') === true);
    $t->assertSame('字符串数字归一化为 int', 8, Setting::int('password_min_length'));

    Setting::putMany(['exam_show_score_immediately' => 'off', 'rate_limit_enabled' => 0]);
    $t->assertTrue('bool off 读取为 false', Setting::bool('exam_show_score_immediately') === false);
    $t->assertTrue('bool 0 读取为 false', Setting::bool('rate_limit_enabled') === false);
});

$t->guard('越界值被拒', function () use ($t) {
    foreach ([
        ['exam_entry_lead_minutes' => -1],
        ['exam_pwd_length' => 99],
        ['page_size_default' => 1],
    ] as $bad) {
        try {
            Setting::putMany($bad);
            $t->assertTrue('越界被拒 ' . json_encode($bad), false);
        } catch (\Core\HttpException $e) {
            $t->assertSame('越界被拒 ' . json_encode($bad), 400, $e->statusCode);
        }
    }
});

$t->guard('非数字与未知键被拒', function () use ($t) {
    try {
        Setting::putMany(['exam_entry_lead_minutes' => 'abc']);
        $t->assertTrue('非数字被拒', false);
    } catch (\Core\HttpException $e) {
        $t->assertSame('非数字被拒', 400, $e->statusCode);
    }
    try {
        Setting::putMany(['evil_key' => 1]);
        $t->assertTrue('未知键被拒', false);
    } catch (\Core\HttpException $e) {
        $t->assertSame('未知键被拒', 400, $e->statusCode);
    }
});

$t->guard('public 子集不包含安全策略', function () use ($t) {
    $pub = Setting::publicSubset();
    $t->assertTrue('含入场窗口', array_key_exists('exam_entry_lead_minutes', $pub));
    $t->assertTrue('含轮询间隔', array_key_exists('waiting_poll_seconds', $pub));
    foreach (['login_max_attempts', 'login_window_minutes', 'rate_limit_enabled',
              'rate_limit_max_requests', 'rate_limit_window_seconds'] as $secret) {
        $t->assertTrue("不含 {$secret}", !array_key_exists($secret, $pub));
    }
});

$t->guard('stored() 区分未配置与配置为默认值', function () use ($t) {
    settingsReset();
    $t->assertTrue('未配置时 stored() 为 null', Setting::stored('exam_entry_lead_minutes') === null);
    Setting::putMany(['exam_entry_lead_minutes' => 15]); // 写的就是默认值
    $t->assertSame('配置后 stored() 返回原值', '15', (string) Setting::stored('exam_entry_lead_minutes'));
});

/* ==================================================================
 * 2. 接口层
 * ================================================================== */

$t->guard('公开接口下发客户端参数', function () use ($t) {
    $res = Http::get('/api/public/settings');
    $t->assertSame('公开设置 -> 200', 200, $res['status']);
    $data = Http::data($res) ?? [];
    $t->assertSame('含入场提前量', 15, (int) ($data['exam_entry_lead_minutes'] ?? -1));
    $t->assertTrue('不含限流阈值', !array_key_exists('rate_limit_max_requests', $data));
});

$t->guard('管理接口未登录被拒', function () use ($t) {
    $t->assertSame('GET 未登录 -> 401', 401, Http::get('/api/admin/settings')['status']);
    $t->assertSame('PUT 未登录 -> 401', 401, Http::put('/api/admin/settings', ['page_size_default' => 30])['status']);
});

/* ---------- 准备管理员账号 ---------- */
\Core\Database::query(
    'INSERT INTO `admininfo` (username, password, admin_power, avatar) VALUES (?, ?, ?, ?)',
    [S_ADMIN, Password::hash(S_PASS), 'systemAdmin', '']
);
\Core\Database::query(
    'INSERT INTO `admininfo` (username, password, admin_power, avatar) VALUES (?, ?, ?, ?)',
    [S_LOW, Password::hash(S_LOWPWD), 'quizAdder', '']
);

$csrf = '';
$t->guard('超级管理员登录', function () use ($t, &$csrf) {
    $res = Http::post('/api/admin/login', ['username' => S_ADMIN, 'password' => S_PASS]);
    $t->assertSame('登录 -> 200', 200, $res['status']);
    $csrf = (string) (Http::data($res)['csrf_token'] ?? '');
    $t->assertTrue('取得 csrf_token', $csrf !== '');
});

$t->guard('管理接口返回 schema 元数据', function () use ($t) {
    $res = Http::get('/api/admin/settings');
    $t->assertSame('GET -> 200', 200, $res['status']);
    $data = Http::data($res) ?? [];
    $t->assertTrue('返回 values', is_array($data['values'] ?? null));
    $t->assertTrue('返回 groups', is_array($data['groups'] ?? null));
    $fields = $data['fields'] ?? [];
    $t->assertSame('返回全部字段定义', count(S_KEYS), count($fields));
    $first = $fields[0] ?? [];
    foreach (['key', 'group', 'type', 'label', 'hint', 'default', 'value'] as $k) {
        $t->assertTrue("字段含 {$k}", array_key_exists($k, $first));
    }
});

$t->guard('低权角色无权读写设置', function () use ($t) {
    // 重新登录为低权角色（会覆盖当前 admin 会话）
    $login = Http::post('/api/admin/login', ['username' => S_LOW, 'password' => S_LOWPWD]);
    $lowCsrf = (string) (Http::data($login)['csrf_token'] ?? '');
    $t->assertSame('低权 GET -> 403', 403, Http::get('/api/admin/settings')['status']);
    $t->assertSame('低权 PUT -> 403', 403,
        Http::put('/api/admin/settings', ['page_size_default' => 30], ['X-CSRF-Token' => $lowCsrf])['status']);
    // 切回超级管理员
    $back = Http::post('/api/admin/login', ['username' => S_ADMIN, 'password' => S_PASS]);
    $t->assertSame('重新登录超管 -> 200', 200, $back['status']);
    $GLOBALS['__set_csrf'] = (string) (Http::data($back)['csrf_token'] ?? '');
});

$csrf = (string) ($GLOBALS['__set_csrf'] ?? $csrf);

$t->guard('写操作缺 CSRF 被拒', function () use ($t) {
    $res = Http::put('/api/admin/settings', ['page_size_default' => 30]);
    $t->assertTrue('缺 token -> 非 200', $res['status'] !== 200);
});

$t->guard('写入合法设置', function () use ($t, $csrf) {
    $res = Http::put('/api/admin/settings', ['page_size_default' => 30, 'exam_entry_lead_minutes' => 20],
        ['X-CSRF-Token' => $csrf]);
    $t->assertSame('PUT -> 200', 200, $res['status']);
    $values = Http::data($res)['values'] ?? [];
    $t->assertSame('返回值已更新', 30, (int) ($values['page_size_default'] ?? -1));
    Setting::flush();
    $t->assertSame('落库生效', 30, Setting::int('page_size_default'));
});

$t->guard('写入越界设置被拒', function () use ($t, $csrf) {
    $res = Http::put('/api/admin/settings', ['exam_pwd_length' => 99], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('越界 -> 400', 400, $res['status']);
});

/* ==================================================================
 * 3. 行为联动
 * ================================================================== */

/* ---------- 3.1 入场窗口 ---------- */

$t->guard('入场窗口：提前量放大后可入场', function () use ($t) {
    Setting::putMany(['exam_entry_lead_minutes' => 15, 'exam_entry_late_minutes' => 0]);
    // 5 分钟后开考 → 提前 15 分钟，窗口已开
    $fx = Fixture::createExam(['exam_start' => date('Y-m-d H:i:s', time() + 300)]);
    if ($fx === null) { $t->skip('入场窗口-可入场', '夹具创建失败'); return; }
    $res = Http::post('/api/exam/login', [
        'exam_id' => $fx['exam_id'], 'stu_id' => $fx['stu_a'],
        'password' => Fixture::PWD, 'exam_pwd' => $fx['exam_pwd'],
    ]);
    $t->assertSame('窗口内入场 -> 200', 200, $res['status']);
    $t->assertSame('进入等待室阶段', 'waiting', (string) (Http::data($res)['phase'] ?? ''));
});

$t->guard('入场窗口：提前量缩小后同一场考试被拒', function () use ($t) {
    Setting::putMany(['exam_entry_lead_minutes' => 2, 'exam_entry_late_minutes' => 0]);
    $fx = Fixture::createExam(['exam_start' => date('Y-m-d H:i:s', time() + 300)]);
    if ($fx === null) { $t->skip('入场窗口-未到时间', '夹具创建失败'); return; }
    $res = Http::post('/api/exam/login', [
        'exam_id' => $fx['exam_id'], 'stu_id' => $fx['stu_a'],
        'password' => Fixture::PWD, 'exam_pwd' => $fx['exam_pwd'],
    ]);
    $t->assertSame('仅提前 2 分钟 -> 403', 403, $res['status']);
    $t->assertTrue('提示开考前 2 分钟', str_contains($res['raw'], '开考前 2 分钟'));
});

$t->guard('迟到宽限：默认 0 时开考后不得入场', function () use ($t) {
    Setting::putMany(['exam_entry_lead_minutes' => 15, 'exam_entry_late_minutes' => 0]);
    $fx = Fixture::createExam([
        'exam_status' => 'testing',
        'exam_start'  => date('Y-m-d H:i:s', time() - 60),
    ]);
    if ($fx === null) { $t->skip('迟到宽限-拒绝', '夹具创建失败'); return; }
    $res = Http::post('/api/exam/login', [
        'exam_id' => $fx['exam_id'], 'stu_id' => $fx['stu_a'],
        'password' => Fixture::PWD, 'exam_pwd' => $fx['exam_pwd'],
    ]);
    $t->assertSame('开考后拒绝入场 -> 403', 403, $res['status']);
    $t->assertTrue('提示已开始无法进入', str_contains($res['raw'], '无法进入考场'));
});

$t->guard('迟到宽限：配置 10 分钟后允许迟到入场', function () use ($t) {
    Setting::putMany(['exam_entry_lead_minutes' => 15, 'exam_entry_late_minutes' => 10]);
    $fx = Fixture::createExam([
        'exam_status' => 'testing',
        'exam_start'  => date('Y-m-d H:i:s', time() - 60),
    ]);
    if ($fx === null) { $t->skip('迟到宽限-放行', '夹具创建失败'); return; }
    $res = Http::post('/api/exam/login', [
        'exam_id' => $fx['exam_id'], 'stu_id' => $fx['stu_a'],
        'password' => Fixture::PWD, 'exam_pwd' => $fx['exam_pwd'],
    ]);
    $t->assertSame('宽限内入场 -> 200', 200, $res['status']);
});

$t->guard('入场状态说明文案由服务端按设置生成', function () use ($t) {
    Setting::putMany(['exam_entry_lead_minutes' => 30, 'exam_entry_late_minutes' => 0]);
    $st = Exam::entryState([
        'exam_start' => date('Y-m-d H:i:s', time() + 600),
        'exam_status' => 'exam', 'exam_pwd' => '123456',
    ], ['stu_status' => 'waiting']);
    $t->assertSame('状态为可入场', 'open', $st['state']);
    $t->assertSame('文案含 30 分钟', true, str_contains($st['hint'], '开考前 30 分钟'));
    $t->assertSame('回传提前量', 30, (int) $st['entry_lead_minutes']);
});

/* ---------- 3.2 考场口令位数 ---------- */

$t->guard('口令位数随设置变化', function () use ($t) {
    foreach ([4, 6, 8, 10] as $len) {
        Setting::putMany(['exam_pwd_length' => $len]);
        Setting::flush();
        $pwd = Exam::generatePwd();
        $t->assertSame("位数 {$len} -> 实际长度", $len, strlen($pwd));
        $t->assertTrue("位数 {$len} -> 纯数字", ctype_digit($pwd));
    }
    $fx = Fixture::createExam();
    if ($fx !== null) {
        Setting::putMany(['exam_pwd_length' => 8]);
        Setting::flush();
        $pwd = (new Exam())->openForEntry((int) $fx['exam_id']);
        $t->assertSame('开放入场按配置位数签发口令', 8, strlen($pwd));
    }
});

/* ---------- 3.3 密码最小长度 ---------- */

$t->guard('密码规则随设置变化', function () use ($t) {
    Setting::putMany(['password_min_length' => 10]);
    $t->assertSame('规则含 minlen:10', true, str_contains(Password::rule(), 'minlen:10'));
    $t->assertTrue('8 位密码被判定为过短', Password::isWeak('abcd1234'));
    $t->assertTrue('10 位且非纯数字通过', !Password::isWeak('abcdefghij'));

    Setting::putMany(['password_min_length' => 6]);
    $t->assertSame('恢复后规则为 minlen:6', true, str_contains(Password::rule(), 'minlen:6'));
});

$t->guard('创建考生时按设置校验密码长度', function () use ($t, $csrf) {
    Setting::putMany(['password_min_length' => 10]);
    $res = Http::post('/api/admin/students', [
        'id' => '9009001', 'stu_name' => '__TEST__短密码考生', 'password' => 'abcd1234',
    ], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('8 位密码 -> 400', 400, $res['status']);

    // 非数字准考证号应被校验拦下（而不是以 SQL 报错 500 暴露细节）
    $bad = Http::post('/api/admin/students', [
        'id' => 'abc123', 'stu_name' => '__TEST__非数字编号', 'password' => 'abcd1234',
    ], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('非数字准考证号 -> 400', 400, $bad['status']);

    Setting::putMany(['password_min_length' => 6]);
    $ok = Http::post('/api/admin/students', [
        'id' => '9009002', 'stu_name' => '__TEST__合规考生', 'password' => 'abcd1234',
    ], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('放宽到 6 位后 -> 200', 200, $ok['status']);
    \Core\Database::query('DELETE FROM `stuinfo` WHERE id IN (9009001, 9009002)');
});

/* ---------- 3.4 分页默认条数 ---------- */

$t->guard('列表默认每页条数随设置变化', function () use ($t) {
    Setting::putMany(['page_size_default' => 5]);
    Setting::flush();
    $res = Http::get('/api/admin/subjects');
    $t->assertSame('列表请求 -> 200', 200, $res['status']);
    $t->assertSame('未指定时使用设置值', 5, (int) (Http::data($res)['per_page'] ?? -1));

    // 显式 per_page 仍优先
    $res2 = Http::get('/api/admin/subjects?per_page=3');
    $t->assertSame('显式参数优先', 3, (int) (Http::data($res2)['per_page'] ?? -1));

    Setting::putMany(['page_size_default' => 20]);
});

/* ---------- 3.5 成绩与答案解析可见性 ---------- */

$t->guard('交卷后是否显示成绩可配置', function () use ($t, $csrf) {
    Setting::putMany([
        'exam_entry_lead_minutes'     => 15,
        'exam_entry_late_minutes'     => 0,
        'exam_show_score_immediately' => 0,
        'exam_allow_view_answer'      => 1,
    ]);
    $fx = Fixture::createExam(['exam_start' => date('Y-m-d H:i:s', time() + 300)]);
    if ($fx === null) { $t->skip('成绩可见性', '夹具创建失败'); return; }

    $login = Http::post('/api/exam/login', [
        'exam_id' => $fx['exam_id'], 'stu_id' => $fx['stu_a'],
        'password' => Fixture::PWD, 'exam_pwd' => $fx['exam_pwd'],
    ]);
    if ($login['status'] !== 200) { $t->assertTrue('前置登录失败：' . $login['raw'], false); return; }

    // 出题并开考
    \App\Services\ExamEngine::generateForClass((int) $fx['exam_id'], (new Exam())->find((int) $fx['exam_id']));
    (new Exam())->start((int) $fx['exam_id']);

    $submit = Http::post('/api/exam/paper/submit', [], ['X-CSRF-Token' => $csrf]);
    $t->assertSame('交卷 -> 200', 200, $submit['status']);
    $sd = Http::data($submit) ?? [];
    // 注意 score 为 null 时 ?? 会误判，故直接比较
    $t->assertTrue('关闭时不下发分数', array_key_exists('score', $sd) && $sd['score'] === null);
    $t->assertTrue('标记 score_visible=false', ($sd['score_visible'] ?? true) === false);

    $over = Http::get('/api/exam/over');
    $od = Http::data($over) ?? [];
    $t->assertTrue('over 接口同样隐藏分数', array_key_exists('score', $od) && $od['score'] === null);

    // 打开后应能看到
    Setting::putMany(['exam_show_score_immediately' => 1]);
    $over2 = Http::get('/api/exam/over');
    $t->assertTrue('打开后可看到分数', is_int(Http::data($over2)['score']));

    Http::post('/api/exam/logout');
});

$t->guard('交卷后能否看答案解析可配置', function () use ($t, $csrf) {
    Setting::putMany([
        'exam_entry_lead_minutes'    => 15,
        'exam_entry_late_minutes'    => 0,
        'exam_show_score_immediately'=> 1,
        'exam_allow_view_answer'     => 0,
    ]);
    $fx = Fixture::createExam(['exam_start' => date('Y-m-d H:i:s', time() + 300)]);
    if ($fx === null) { $t->skip('答案解析可见性', '夹具创建失败'); return; }

    $login = Http::post('/api/exam/login', [
        'exam_id' => $fx['exam_id'], 'stu_id' => $fx['stu_a'],
        'password' => Fixture::PWD, 'exam_pwd' => $fx['exam_pwd'],
    ]);
    if ($login['status'] !== 200) { $t->assertTrue('前置登录失败：' . $login['raw'], false); return; }

    \App\Services\ExamEngine::generateForClass((int) $fx['exam_id'], (new Exam())->find((int) $fx['exam_id']));
    (new Exam())->start((int) $fx['exam_id']);
    Http::post('/api/exam/paper/submit', [], ['X-CSRF-Token' => $csrf]);

    $ans = Http::get('/api/exam/answer');
    $t->assertSame('关闭后查看答案 -> 403', 403, $ans['status']);

    Setting::putMany(['exam_allow_view_answer' => 1]);
    $ans2 = Http::get('/api/exam/answer');
    $t->assertSame('打开后可查看 -> 200', 200, $ans2['status']);
    $t->assertTrue('返回题目列表', is_array(Http::data($ans2)['papers'] ?? null));

    Http::post('/api/exam/logout');
});

/* ==================================================================
 * 收尾：恢复默认并清理
 * ================================================================== */

settingsReset();
settingsAdminCleanup();
Fixture::cleanup();

$t->guard('收尾：设置已恢复默认', function () use ($t) {
    Setting::flush();
    $all = Setting::all();
    $t->assertSame('入场提前量回到 15', 15, (int) $all['exam_entry_lead_minutes']);
    $t->assertSame('每页条数回到 20', 20, (int) $all['page_size_default']);
    $t->assertTrue('未残留任何设置行', Setting::stored('exam_entry_lead_minutes') === null);
});

exit($t->finish());
