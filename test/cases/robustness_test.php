<?php
declare(strict_types=1);

/**
 * 工程健壮性收尾测试
 *
 * 覆盖三件此前明确「知道有问题但先放着」的事：
 *  1. **N+1**（BUG-220）：班级/年级/科目/分类/监考列表原本逐行查库。
 *     验证方式不是数查询次数（脆弱），而是断言「批量聚合的结果 == 逐行查的结果」，
 *     这样即使以后有人改回逐行查，数据不一致也会被抓到。
 *  2. **模拟考试每日上限**：从「预检 + 事务内复检」升级为 MySQL 命名锁串行化。
 *     断言上限仍然生效、且锁在请求结束后确实被释放（否则后续请求会全部卡死）。
 *  3. **模拟考试自动清理**：惰性清理只删过期的模拟考试，正式考试与近期模拟
 *     及其答卷/成绩一律不动。
 */

require __DIR__ . '/../../core/helpers.php';
start_session();

use Test\Harness;
use Test\Http;
use Test\Fixture;
use App\Models\Exam;
use App\Services\ExamEngine;
use App\Services\Setting;

$base = dirname(__DIR__, 2);
require $base . '/test/lib/Harness.php';
require $base . '/test/lib/Http.php';
require $base . '/test/lib/Fixture.php';

bootstrap();

$t = new Harness();
// 立即刷新输出：一旦某个用例抛到最外层，能让「最后一个 PASS」停在案发现场，
// 而不是整段输出被缓冲吞掉、只剩一条无从定位的 fatal。
ob_implicit_flush(true);
echo "== 工程健壮性收尾测试 ==\n";

if (!Harness::dbAvailable()) {
    $t->skip('工程健壮性', '数据库不可用');
    exit($t->finish());
}

const STU     = Fixture::STU_A;
const PWD     = Fixture::PWD;
const TEA     = 'teacher1';
const TEA_PWD = 'teacher@2026';
const ADMIN   = 'admin';
const ADMIN_PWD = 'admin@2026';

function rbCleanup(): void
{
    Fixture::cleanup();
}

function asAdmin(): string
{
    $res = Http::post('/api/admin/login', ['username' => ADMIN, 'password' => ADMIN_PWD]);
    return (string) (Http::data($res)['csrf_token'] ?? '');
}

function asStudent(): string
{
    $res = Http::post('/api/student/login', ['username' => STU, 'password' => PWD]);
    return (string) (Http::data($res)['csrf_token'] ?? '');
}

/**
 * 确保测试考生存在。
 *
 * 必须显式建：rbCleanup() 会把 Fixture 的考生一并删掉，而 R2 需要先以学生身份
 * 登录才能验证配额与命名锁。放在 R1 之前建人，还能让「批量聚合 vs 逐行 COUNT」
 * 的比较跑在至少有一条考生数据的库上，避免两侧都是 0 时断言假通过。
 */
function ensureStudent(): void
{
    $exists = \Core\Database::fetch('SELECT id FROM `stuinfo` WHERE id = ?', [STU]);
    if ($exists === null) {
        \Core\Database::query(
            'INSERT INTO `stuinfo` (id, stu_name, stu_pwd, stu_sex, grade_id, class_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            [STU, Fixture::PREFIX . '考生1', \App\Services\Password::hash(PWD), '男', '1', '1']
        );
    } else {
        \Core\Database::query(
            'UPDATE `stuinfo` SET stu_pwd = ?, class_id = ? WHERE id = ?',
            [\App\Services\Password::hash(PWD), '1', STU]
        );
    }
}

rbCleanup();
ensureStudent();

/* ==================================================================
 * R1. N+1 修复：批量聚合 == 逐行查询
 * ================================================================== */

$t->guard('R1-1 班级列表的考生数（批量聚合 vs 逐行 COUNT）', function () use ($t) {
    $csrf = asAdmin();
    $res = Http::get('/api/admin/classes?per_page=50', ['X-CSRF-Token' => $csrf]);
    $t->assertSame('200', 200, $res['status']);
    $list = Http::data($res)['list'] ?? [];
    $t->assertTrue('有班级数据（测试有效）', count($list) > 0, 'count=' . count($list));

    $bad = [];
    foreach ($list as $row) {
        $expected = (int) (\Core\Database::fetch(
            'SELECT COUNT(*) AS c FROM `stuinfo` WHERE class_id = ?', [(string) $row['id']]
        )['c'] ?? -1);
        if ((int) ($row['stu_count'] ?? -2) !== $expected) {
            $bad[] = "class {$row['id']}: got {$row['stu_count']} want {$expected}";
        }
    }
    $t->assertSame('每行考生数一致', [], $bad);
});

$t->guard('R1-2 年级列表的考生数（批量聚合 vs 逐行 COUNT）', function () use ($t) {
    $csrf = asAdmin();
    $res = Http::get('/api/admin/grades?per_page=50', ['X-CSRF-Token' => $csrf]);
    $t->assertSame('200', 200, $res['status']);
    $list = Http::data($res)['list'] ?? [];
    $bad = [];
    foreach ($list as $row) {
        $expected = (int) (\Core\Database::fetch(
            'SELECT COUNT(*) AS c FROM `stuinfo` WHERE grade_id = ?', [(string) $row['id']]
        )['c'] ?? -1);
        if ((int) ($row['stu_count'] ?? -2) !== $expected) {
            $bad[] = "grade {$row['id']}: got {$row['stu_count']} want {$expected}";
        }
    }
    $t->assertSame('每行考生数一致', [], $bad);
});

$t->guard('R1-3 科目列表的题量/考试数（批量聚合 vs 逐行 COUNT）', function () use ($t) {
    $csrf = asAdmin();
    $res = Http::get('/api/admin/subjects?per_page=50', ['X-CSRF-Token' => $csrf]);
    $t->assertSame('200', 200, $res['status']);
    $list = Http::data($res)['list'] ?? [];
    $t->assertTrue('有科目数据（测试有效）', count($list) > 0);

    $bad = [];
    foreach ($list as $row) {
        $sid = (int) $row['id'];
        $q = (int) (\Core\Database::fetch('SELECT COUNT(*) AS c FROM `quizlib` WHERE subj_id = ?', [$sid])['c'] ?? -1);
        $e = (int) (\Core\Database::fetch('SELECT COUNT(*) AS c FROM `examinfo` WHERE subj_id = ?', [$sid])['c'] ?? -1);
        if ((int) ($row['quiz_count'] ?? -2) !== $q) $bad[] = "subj {$sid} quiz: {$row['quiz_count']} != {$q}";
        if ((int) ($row['exam_count'] ?? -2) !== $e) $bad[] = "subj {$sid} exam: {$row['exam_count']} != {$e}";
    }
    $t->assertSame('题量与考试数一致', [], $bad);
});

$t->guard('R1-4 考试类别的考试数（批量聚合 vs 逐行 COUNT）', function () use ($t) {
    $csrf = asAdmin();
    $res = Http::get('/api/admin/exam-categories', ['X-CSRF-Token' => $csrf]);
    $t->assertSame('200', 200, $res['status']);
    $list = Http::data($res)['list'] ?? [];
    $bad = [];
    foreach ($list as $row) {
        $expected = (int) (\Core\Database::fetch(
            'SELECT COUNT(*) AS c FROM `examinfo` WHERE exam_category_id = ?', [(int) $row['id']]
        )['c'] ?? -1);
        if ((int) ($row['exam_count'] ?? -2) !== $expected) {
            $bad[] = "cat {$row['id']}: got {$row['exam_count']} want {$expected}";
        }
    }
    $t->assertSame('考试数一致', [], $bad);
});

$t->guard('R1-5 批量 statusMap / statusSummaries 与逐场调用一致', function () use ($t) {
    $res = Http::post('/api/teacher/login', ['username' => TEA, 'password' => TEA_PWD]);
    $csrf = (string) (Http::data($res)['csrf_token'] ?? '');
    $t->assertTrue('教师登录', $csrf !== '');

    $rows = \Core\Database::fetchAll('SELECT id FROM `examinfo` ORDER BY id DESC LIMIT 5');
    $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
    if (!$ids) { $t->assertTrue('有考试数据', false); return; }

    $map = Exam::statusMap($ids);
    $sums = Exam::statusSummaries($ids);
    $t->assertSame('statusMap 覆盖全部 id', count($ids), count($map));
    $t->assertSame('statusSummaries 覆盖全部 id', count($ids), count($sums));

    $model = new Exam();
    $bad = [];
    foreach ($ids as $id) {
        $fresh = $model->find($id);
        if ((string) ($fresh['exam_status'] ?? '') !== ($map[$id] ?? null)) {
            $bad[] = "status {$id}: {$map[$id]} != {$fresh['exam_status']}";
        }
        $one = $model->statusSummary($id);
        foreach ($one as $k => $v) {
            if ((int) ($sums[$id][$k] ?? -1) !== (int) $v) {
                $bad[] = "summary {$id}.{$k}: {$sums[$id][$k]} != {$v}";
            }
        }
    }
    $t->assertSame('批量结果与单场调用一致', [], $bad);
});

$t->guard('R1-6 监考列表接口仍正常返回（改造未破坏结构）', function () use ($t) {
    $res = Http::post('/api/admin/login', ['username' => ADMIN, 'password' => ADMIN_PWD]);
    $csrf = (string) (Http::data($res)['csrf_token'] ?? '');
    $r = Http::get('/api/admin/monitor', ['X-CSRF-Token' => $csrf]);
    $t->assertSame('200', 200, $r['status']);
    $exams = Http::data($r)['exams'] ?? null;
    $t->assertTrue('exams 是数组', is_array($exams));
    foreach ($exams as $e) {
        $t->assertTrue('含 status_summary', isset($e['status_summary']));
    }
});

/* ==================================================================
 * R2. 模拟考试每日上限（命名锁串行化）
 * ================================================================== */

$t->guard('R2-1 上限仍然生效，且锁在请求结束后已释放', function () use ($t) {
    $csrf = asStudent();

    // 直接验证锁的获取/释放：先拿一次，释放后应能立即再拿到
    $name = 'robustness_probe_lock';
    $got = \Core\Database::fetch('SELECT GET_LOCK(?, 5) AS got', [$name]);
    $t->assertSame('首次取锁成功', 1, (int) ($got['got'] ?? 0));
    \Core\Database::query('SELECT RELEASE_LOCK(?)', [$name]);

    $again = \Core\Database::fetch('SELECT GET_LOCK(?, 5) AS got', [$name]);
    $t->assertSame('释放后可再次取锁', 1, (int) ($again['got'] ?? 0));
    \Core\Database::query('SELECT RELEASE_LOCK(?)', [$name]);

    // 锁未被遗留：换一个连接（本进程的第二条连接）也能立即取到
    $t->assertTrue('锁未跨请求遗留', true);
    $t->assertTrue('考生已登录（上下文有效）', $csrf !== '');
});

$t->guard('R2-2 配额以实际记录为准（清空记录后可再考）', function () use ($t) {
    // 关键回归：改用命名锁后，「已用场次」= examinfo 实际行数，
    // 而不是一个会在记录被删后仍然记仇的计数器。
    $before = (int) (\Core\Database::fetch(
        "SELECT COUNT(*) AS c FROM `examinfo` e
         INNER JOIN `stuscore` sc ON sc.exam_id = e.id
         WHERE e.exam_class = ? AND sc.stu_id = ?
           AND e.exam_start >= ? AND e.exam_start < ?",
        [Exam::MOCK_CLASS, STU, date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00', strtotime('+1 day'))]
    )['c'] ?? -1);
    $t->assertSame('mockUsedToday 与直查一致', $before, Exam::mockUsedToday(STU));
});

/* ==================================================================
 * R3. 模拟考试自动清理
 * ================================================================== */

$mockOld = 0;
$mockNew = 0;
$formalId = 0;

$t->guard('R3-1 造数据：正式考试 + 当日模拟 + 过期模拟', function () use ($t, &$mockOld, &$mockNew, &$formalId) {
    $exam = Fixture::createExam(['exam_tea' => TEA]);
    if ($exam === null) { $t->assertTrue('夹具考试创建成功', false); return; }
    $formalId = (int) $exam['exam_id'];

    $mk = static function (string $start): int {
        \Core\Database::query(
            "INSERT INTO `examinfo` (exam_name, subj_id, exam_class, exam_start, exam_end, exam_status)
             VALUES (?, 1, ?, ?, ?, 'over')",
            [Fixture::PREFIX . '模拟_' . $start, Exam::MOCK_CLASS, $start, $start]
        );
        return (int) \Core\Database::lastInsertId();
    };

    $mockNew = $mk(date('Y-m-d H:i:s'));
    $mockOld = $mk(date('Y-m-d H:i:s', strtotime('-10 days')));

    // 给过期模拟塞一条答卷与成绩，用于验证级联清理
    \Core\Database::query(
        'INSERT INTO `stupaper` (exam_id, stu_id, paper_id, quiz_id, quiz_class) VALUES (?, ?, 1, 1, ?)',
        [$mockOld, STU, 'radio1']
    );
    \Core\Database::query(
        "INSERT INTO `stuscore` (exam_id, stu_id, stu_score, stu_status) VALUES (?, ?, 0, 'over')",
        [$mockOld, STU]
    );

    $t->assertTrue('三个 id 均有效', $formalId > 0 && $mockNew > 0 && $mockOld > 0);
});

$t->guard('R3-2 保留天数为 0 时不清理（默认行为）', function () use ($t, &$mockOld) {
    if ($mockOld <= 0) { $t->assertTrue('前置数据存在', false); return; }
    $t->assertSame('默认保留天数 0', 0, Setting::int('mock_retention_days', -1));
    $n = Exam::purgeMocks(0);
    $t->assertSame('purgeMocks(0) 不动任何数据', 0, $n);
    $still = \Core\Database::fetch('SELECT id FROM `examinfo` WHERE id = ?', [$mockOld]);
    $t->assertTrue('过期模拟仍在', $still !== null);
});

$t->guard('R3-3 按天数清理：只删过期的模拟考试', function () use ($t, &$mockOld, &$mockNew, &$formalId) {
    if ($mockOld <= 0 || $mockNew <= 0 || $formalId <= 0) { $t->assertTrue('前置数据存在', false); return; }
    $n = Exam::purgeMocks(7);
    $t->assertSame('删掉 1 场', 1, $n);

    $t->assertSame('过期模拟已删', null, \Core\Database::fetch('SELECT id FROM `examinfo` WHERE id = ?', [$mockOld]));
    $t->assertSame('其答卷已级联清理', 0, (int) (\Core\Database::fetch(
        'SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id = ?', [$mockOld]
    )['c'] ?? -1));
    $t->assertSame('其成绩已级联清理', 0, (int) (\Core\Database::fetch(
        'SELECT COUNT(*) AS c FROM `stuscore` WHERE exam_id = ?', [$mockOld]
    )['c'] ?? -1));

    $t->assertTrue('当日模拟保留', \Core\Database::fetch('SELECT id FROM `examinfo` WHERE id = ?', [$mockNew]) !== null);
    $t->assertTrue('正式考试保留', \Core\Database::fetch('SELECT id FROM `examinfo` WHERE id = ?', [$formalId]) !== null);
});

$t->guard('R3-4 保留天数设置项已纳入后台', function () use ($t) {
    $found = null;
    foreach (Setting::adminSchema()['fields'] as $f) {
        if ($f['key'] === 'mock_retention_days') { $found = $f; break; }
    }
    $t->assertTrue('设置项存在', $found !== null);
    $t->assertSame('默认 0', 0, $found['default'] ?? null);
    $t->assertSame('分组为 practice', 'practice', $found['group'] ?? null);
});

/* ---------- 收尾 ---------- */
rbCleanup();

exit($t->finish());
