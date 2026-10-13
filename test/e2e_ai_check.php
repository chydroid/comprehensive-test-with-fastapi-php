<?php
declare(strict_types=1);

/**
 * AI 功能端到端实测（走真实 HTTP）
 *
 * 存在意义：单元/接口测试全绿 ≠ 浏览器里能用。本脚本用 curl 真实登录教师、
 * 真实调用两个 AI 接口，检查**响应体里有没有前端真正依赖的字段**。
 *
 * ⚠️ 本机 php.ini 禁用了 exec()/shell_exec/putenv，不能用 PHP 起停 server，
 *    须由 bash 在同一次调用里起 php -S、跑本脚本、再 pkill 收尾。
 * 用法: bash temp/run_e2e_ai.sh
 */

$root = dirname(__DIR__);
chdir($root);

$base = 'http://127.0.0.1:8099';
$ck   = '/tmp/e2e_ck.txt';
$ck2  = '/tmp/e2e_ck2.txt';
@unlink($ck);
@unlink($ck2);

/** 裸 curl：返回 [headers, status, body] */
function http(string $method, string $url, array $opts = []): array
{
    $ch = curl_init($url);
    $headers = [];
    if (isset($opts['json'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json'], JSON_UNESCAPED_UNICODE));
        $headers[] = 'Content-Type: application/json';
    }
    if (!empty($opts['csrf'])) {
        $headers[] = 'X-CSRF-Token: ' . $opts['csrf'];
    }
    $jar = $opts['cookie'] ?? '/tmp/e2e_ck.txt';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [substr($raw, 0, $hSize), $status, substr($raw, $hSize)];
}

$data = static function (string $body): array {
    $d = json_decode($body, true);
    return is_array($d) ? $d : [];
};

$fail = 0;
$pass = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$fail, &$pass): void {
    if ($ok) {
        $pass++;
        echo "  PASS  {$name}\n";
    } else {
        $fail++;
        echo "  FAIL  {$name}" . ($detail !== '' ? "  → {$detail}" : '') . "\n";
    }
};

echo "════ 1. 健康检查 & CSRF ════\n";
[$h, $st, $b] = http('GET', "{$base}/api/health");
$health = $data($b);
$check('health 200', $st === 200, "got {$st}");
$check('db 正常', ($health['data']['db'] ?? false) === true, mb_substr($b, 0, 160));
preg_match('/X-CSRF-Token:\s*(\S+)/i', $h, $m);
$check('响应头带 X-CSRF-Token（419 自动恢复依赖）', !empty($m[1]));

echo "\n════ 2. 教师登录 ════\n";
// 凭据与 test/cases/ai_compose_test.php 的常量保持一致
const TEA_PWD = 'teacher@2026';
$login = null;
foreach (['teacher1', 'teacher2'] as $name) {
    [, $st, $b] = http('POST', "{$base}/api/teacher/login", [
        'cookie' => $ck, 'json' => ['username' => $name, 'password' => TEA_PWD],
    ]);
    $d = $data($b);
    if (($d['code'] ?? -1) === 0) {
        $login = $d['data'];
        echo "  （教师 {$name} 登录成功）\n";
        break;
    }
}
if ($login === null) {
    echo "  登录响应样例: " . mb_substr($b, 0, 200) . "\n";
    echo "\n{$pass} PASS / " . ($fail + 1) . " FAIL（登录失败，无法继续）\n";
    exit(1);
}
$csrf = (string) ($login['csrf_token'] ?? '');
$check('登录返回 csrf_token', $csrf !== '');

echo "\n════ 3. 定位可操作的考试 ════\n";
require $root . '/core/helpers.php';
bootstrap();
$cfg = config('database');
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], (int) $cfg['port'], $cfg['name']),
    $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$teaName = (string) ($login['username'] ?? 'teacher1');
// 自包含：确保该教师名下有一场正式考试（CI 最小种子不含 examinfo 数据，否则
// 「定位可操作的考试」会因查无此教师的正式考试而失败）。用 __E2E__ 前缀，脚本
// 末尾统一回收，绝不污染真实数据。
$pdo->prepare('DELETE FROM `examinfo` WHERE exam_name = ?')->execute(['__E2E__teacher1正式考试']);
$pdo->prepare(
    'INSERT INTO `examinfo` (exam_name, subj_id, exam_tea, exam_class, exam_status, exam_start, exam_end, stu_class, paper_mode)
     VALUES (?, ?, ?, "正式", "over", NOW(), NOW(), 0, "random")'
)->execute(['__E2E__teacher1正式考试', 158, $teaName]);

// 必须挑该教师自己的**正式考试**：模拟考试 exam_tea 为空，assertOwnExam 会正确地 404。
$stExam = $pdo->prepare(
    'SELECT id, exam_name, subj_id, exam_tea, exam_class, exam_status FROM examinfo
     WHERE exam_tea = ? AND COALESCE(exam_class, "") <> ?
     ORDER BY id DESC LIMIT 1'
);
$stExam->execute([$teaName, \App\Models\Exam::MOCK_CLASS]);
$exam = $stExam->fetch();
$check('存在该教师的正式考试', $exam !== false, "教师 {$teaName} 名下无正式考试");
if ($exam === false) {
    echo "\n{$pass} PASS / " . ($fail + 1) . " FAIL\n";
    exit(1);
}
echo "  考试 #{$exam['id']} {$exam['exam_name']}（科目 {$exam['subj_id']} 教师 {$exam['exam_tea']} 状态 {$exam['exam_status']}）\n";

echo "\n════ 4. AI 组卷 compose ════\n";
[, $st, $b] = http('POST', "{$base}/api/teacher/exams/{$exam['id']}/compose", [
    'csrf' => $csrf, 'json' => ['count' => 3, 'easy' => 1, 'mid' => 1, 'hard' => 1],
]);
$d = $data($b);
$check('compose 200', $st === 200, "got {$st}: " . mb_substr($b, 0, 200));
$check('code=0', ($d['code'] ?? -1) === 0, mb_substr($b, 0, 200));
$comp = $d['data'] ?? [];
foreach (['questions', 'provider', 'degraded'] as $f) {
    $check("响应含 {$f}（前端 composer.js 依赖）", array_key_exists($f, $comp), '实际字段: ' . implode(',', array_keys($comp)));
}
$check('questions 是数组', is_array($comp['questions'] ?? null));
$check('题数 > 0', count($comp['questions'] ?? []) > 0, '返回 0 题');
// provider 是**嵌套对象** {'key','label','enabled'}，不是字符串
$provKey = (string) ($comp['provider']['key'] ?? '');
$check('provider.key 存在（嵌套对象）', $provKey !== '', 'provider=' . json_encode($comp['provider'] ?? null, JSON_UNESCAPED_UNICODE));
$check('degraded 是 bool', is_bool($comp['degraded'] ?? null), '实际类型: ' . gettype($comp['degraded'] ?? null));
echo "  provider.key={$provKey}  degraded=" . var_export($comp['degraded'] ?? null, true)
   . "  题数=" . count($comp['questions'] ?? []) . "  composable=" . var_export($comp['composable'] ?? null, true) . "\n";
if (!empty($comp['questions'][0])) {
    $q0 = $comp['questions'][0];
    // 建议题字段名是 stem/options/answer/kp/difficulty，不是 quiz_* （前端 composer.js 按这套渲染）
    echo "  首题: id=" . ($q0['id'] ?? '无') . " type=" . ($q0['type'] ?? '?')
       . " stem=" . mb_substr((string) ($q0['stem'] ?? ''), 0, 26) . "\n";
    $check('建议题带真实 id（不得编造）', !empty($q0['id']));
    $check('建议题带 stem', trim((string) ($q0['stem'] ?? '')) !== '');
    $check('建议题带 options 数组', is_array($q0['options'] ?? null) && count($q0['options'] ?? []) > 0);
    $check('建议题带 answer（apply 需要）', trim((string) ($q0['answer'] ?? '')) !== '', 'answer=' . var_export($q0['answer'] ?? null, true));
    $check('建议题带 kp 字段', array_key_exists('kp', $q0));
    $check('建议题带 difficulty', array_key_exists('difficulty', $q0));
}

echo "\n════ 5. AI 组卷 apply ════\n";
// apply 只在考试**未开始**时允许（已开始返回 409 是正确保护）。
// 库里 teacher1 的考试都是 over，故临时建一场「online」状态的考试来验证正向路径。
$ownExamId = (int) $exam['id'];
$ownSubjId = (int) $exam['subj_id'];
// examinfo 的时间列是 exam_start / exam_end（没有 exam_time）
$stNew = $pdo->prepare(
    'INSERT INTO examinfo (exam_name, subj_id, exam_tea, exam_class, exam_status, exam_start, exam_end, stu_class, paper_mode)
     VALUES (?, ?, ?, "", "online", NOW(), NULL, 0, "random")'
);
$stNew->execute(['__E2E__AI组卷测试', $ownSubjId, $teaName]);
$freshId = (int) $pdo->lastInsertId();
// ⚠️ 变量后紧跟中文/全角字符时必须用 {$var}，否则 PHP 会把后半个词吞进变量名
echo "  临时新建考试 #{$freshId}（online 状态，可组卷）\n";

// apply 入参就是 compose 返回的题目对象**原样透传**（前端 composer.js 的 doApply 直接传 chosen），
// 字段名为 stem/type/options/answer/difficulty/kp —— 不是 quiz_*，也不是 title。
$payload = array_slice($comp['questions'] ?? [], 0, 2);

[, $st, $b] = http('POST', "{$base}/api/teacher/exams/{$freshId}/apply-composition", [
    'csrf' => $csrf, 'json' => ['questions' => $payload],
]);
$d = $data($b);
$check('apply 200（online 状态）', $st === 200, "got {$st}: " . mb_substr($b, 0, 200));
$check('apply code=0', ($d['code'] ?? -1) === 0, mb_substr($b, 0, 200));
if (($d['code'] ?? -1) === 0) {
    echo "  applied={$d['data']['applied']} new={$d['data']['new']} skipped={$d['data']['skipped']}\n";
} else {
    echo '  ' . mb_substr($b, 0, 220) . "\n";
}

// 反向验证：已开始（testing）必须拒 409 —— 这是防「考试中偷换试卷」的保护，不能失效
$pdo->prepare('UPDATE examinfo SET exam_status = "testing" WHERE id = ?')->execute([$freshId]);
[, $st, $b] = http('POST', "{$base}/api/teacher/exams/{$freshId}/apply-composition", [
    'csrf' => $csrf, 'json' => ['questions' => $payload],
]);
$d = $data($b);
$check('已开考的考试 apply 被拒 409（保护生效）', $st === 409, "got {$st}: " . mb_substr($b, 0, 160));

// 清理临时考试
$pdo->prepare('DELETE FROM examinfo WHERE id = ?')->execute([$freshId]);
$pdo->prepare('DELETE FROM exam_manual_quiz WHERE exam_id = ?')->execute([$freshId]);
echo "  （已删除临时考试 #{$freshId}）\n";
echo "  参考：原考试 #{$ownExamId} 状态 " . $exam['exam_status'] . "，对它 apply 会正确返回 409\n";

echo "\n════ 6. AI 判卷 suggest 端点（造真实 longtext 数据）════\n";
// 库中 longtext 记录数为 0 —— 这条链路从未在真实数据上跑通，测试用例是自己造数据的。
// 故这里现造：一场 online 考试 + 一名考生 + 一道 longtext 试卷，验证 suggest 真能出建议。
$stuName = (string) ($pdo->query('SELECT stu_name FROM stuinfo ORDER BY id LIMIT 1')->fetchColumn());
if ($stuName === '' || $stuName === false) {
    echo "  （无考生账号，跳过）\n";
} else {
    // 找一道 longtext 题
    $lt = $pdo->query('SELECT id, subj_id, quiz_title, quiz_key FROM quizlib WHERE quiz_class = "longtext" LIMIT 1')->fetch();
    if ($lt === false) {
        echo "  （题库无 longtext 题，跳过）\n";
    } else {
        $pdo->prepare(
            'INSERT INTO examinfo (exam_name, subj_id, exam_tea, exam_class, exam_status, exam_start, exam_end, stu_class, longtext_val, longtext_easy_sum, longtext_mid_sum, longtext_hard_sum, paper_mode)
             VALUES (?, ?, ?, "", "over", NOW(), NOW(), 0, 10, 1, 0, 0, "random")'
        )->execute(['__E2E__AI判卷测试', (int) $lt['subj_id'], $teaName]);
        $gExamId = (int) $pdo->lastInsertId();

        // 取该考生在本场考试的成绩记录（stu_id 存准考证号，不是登录名）
        $srow = $pdo->prepare('SELECT id, stu_id FROM stuscore WHERE exam_id = ? ORDER BY id DESC LIMIT 1');
        $srow->execute([$gExamId]);
        $srow = $srow->fetch();
        if ($srow === false) {
            // 借一场有成绩的考试拿准考证号
            $srow = $pdo->query('SELECT id, stu_id FROM stuscore ORDER BY id DESC LIMIT 1')->fetch();
        }
        $stuId = (string) ($srow['stu_id'] ?? '');
        if ($stuId === '') {
            echo "  （无成绩记录，取不到准考证号，跳过）\n";
        } else {
            // 直接造一条 longtext 试卷
            $pdo->prepare(
                'INSERT INTO stupaper (exam_id, stu_id, paper_id, quiz_id, quiz_class, stu_key, quiz_status)
                 VALUES (?, ?, 0, ?, "longtext", ?, 0)'
            )->execute([$gExamId, $stuId, (int) $lt['id'], '网络安全很重要，必须防范。']);

            [, $st, $b] = http('POST', "{$base}/api/teacher/exams/{$gExamId}/subjective/{$stuId}/suggest", [
                'csrf' => $csrf, 'json' => [],
            ]);
            $d = $data($b);
            $check('suggest 200', $st === 200, "got {$st}: " . mb_substr($b, 0, 220));
            $check('suggest code=0', ($d['code'] ?? -1) === 0, mb_substr($b, 0, 220));
            $g = $d['data'] ?? [];
            if ($st === 200) {
                echo "  provider=" . json_encode($g['provider'] ?? null, JSON_UNESCAPED_UNICODE)
                   . " 适用=" . ($g['applicable'] ?? '?') . " 满分=" . ($g['full_score'] ?? '?') . "\n";
                $check('返回 provider 块', isset($g['provider']['key']));
                $check('applicable > 0（真的找到了主观题）', (int) ($g['applicable'] ?? 0) > 0, 'applicable=' . var_export($g['applicable'] ?? null, true));
                $items = (array) ($g['items'] ?? []);
                if (!empty($items[0])) {
                    $it = $items[0];
                    echo "  建议: score=" . var_export($it['score'] ?? null, true)
                       . " confidence=" . var_export($it['confidence'] ?? null, true)
                       . " reason=" . mb_substr((string) ($it['reason'] ?? ''), 0, 40) . "\n";
                    $check('建议分不超满分', (int) ($it['score'] ?? 0) <= (int) ($g['full_score'] ?? 0));
                    $check('含置信度', isset($it['confidence']));
                    $check('含理由文本', trim((string) ($it['reason'] ?? '')) !== '');
                }
                // 关键安全约束：suggest 绝不落分
                $sc = $pdo->prepare('SELECT quiz_score, graded_at FROM stupaper WHERE exam_id = ? AND quiz_id = ?');
                $sc->execute([$gExamId, (int) $lt['id']]);
                $sc = $sc->fetch();
                $check('suggest 未落分（quiz_score 仍 NULL）', $sc['quiz_score'] === null, 'score=' . var_export($sc['quiz_score'] ?? null, true));
                $check('suggest 未写批阅时间（graded_at 仍 NULL）', $sc['graded_at'] === null);
            }

            $pdo->prepare('DELETE FROM stupaper WHERE exam_id = ?')->execute([$gExamId]);
            $pdo->prepare('DELETE FROM examinfo WHERE id = ?')->execute([$gExamId]);
            echo "  （已清理临时判卷数据）\n";
        }
    }
}

echo "\n════ 7. 设置页 AI 分组 ════\n";
// 库中只有 admin 且密码未知（bcrypt 不可逆）→ 建一个临时管理员，测完删除。
// 与 test/cases/admin_test.php 的 __TEST__sysadmin 同一思路，避免污染真实数据。
$tmpAdmin = '__E2E__aiadmin';
$tmpPass  = 'e2eAiPwd123';
$created  = false;
try {
    $pdo->prepare('DELETE FROM admininfo WHERE username = ?')->execute([$tmpAdmin]);
    $hash = password_hash($tmpPass, PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO admininfo (username, password, admin_power, avatar) VALUES (?, ?, ?, ?)')
        ->execute([$tmpAdmin, $hash, 'systemAdmin', '']);
    $created = true;
} catch (Throwable $e) {
    echo '  ⚠️ 临时管理员创建失败: ' . $e->getMessage() . "\n";
}

[, $st, $b] = http('POST', "{$base}/api/admin/login", [
    'cookie' => $ck2, 'json' => ['username' => $tmpAdmin, 'password' => $tmpPass],
]);
$adm = $data($b)['data'] ?? null;
if (!is_array($adm)) {
    echo "  ⚠️ 管理端登录失败，跳过。响应: " . mb_substr($b, 0, 160) . "\n";
} else {
    echo "  （临时管理员 {$tmpAdmin} 登录成功）\n";
    $acsrf = (string) ($adm['csrf_token'] ?? '');
    $check('管理端登录拿到 csrf', $acsrf !== '');

    [, $st, $b] = http('GET', "{$base}/api/admin/settings", ['cookie' => $ck2]);
    $d = $data($b);
    $check('settings 200', $st === 200, "got {$st}");
    // 结构：groups 是关联数组 key=分组名；配置项在 fields（扁平列表，每项含 group）
    $groups = (array) ($d['data']['groups'] ?? []);
    $fields = (array) ($d['data']['fields'] ?? []);
    $check('返回 groups', $groups !== [], '实际: ' . mb_substr($b, 0, 200));
    $check('返回 fields', $fields !== [], '实际: ' . mb_substr($b, 0, 200));
    $check('存在 ai 分组', array_key_exists('ai', $groups), '现有分组: ' . implode(',', array_keys($groups)));
    if (isset($groups['ai'])) {
        echo "  ai 分组标题: {$groups['ai']['label']} —— {$groups['ai']['desc']}\n";
    }

    $aiFields = array_values(array_filter($fields, static fn ($f) => ($f['group'] ?? '') === 'ai'));
    $keys = array_map(static fn ($i) => $i['key'] ?? '?', $aiFields);
    echo "  ai 分组字段: " . implode(', ', $keys) . "\n";
    foreach (['ai_grading_enabled', 'ai_grading_endpoint', 'ai_grading_model', 'ai_grading_api_key', 'ai_grading_timeout', 'ai_grading_max_items'] as $need) {
        $check("含 {$need}", in_array($need, $keys, true));
    }
    foreach ($aiFields as $f) {
        if (($f['key'] ?? '') === 'ai_grading_api_key') {
            $check('api_key 标记为 secret', (bool) ($f['secret'] ?? false));
            $check('api_key 回显已脱敏（不回明文）', !str_starts_with((string) ($f['value'] ?? ''), 'sk-'), 'value=' . var_export($f['value'] ?? null, true));
        }
    }

    // ── 核心怀疑点：开关写进去后，后端到底读不读得到？（「AI 启用了没反应」的最常见根因）
    echo "\n  ── 开关连通性（PUT 写 → GET 回读）──\n";
    // 注意：设置写入是 **PUT** /api/admin/settings（POST 会 405）
    [$hs, $ss, $bb] = http('PUT', "{$base}/api/admin/settings", [
        'cookie' => $ck2, 'csrf' => $acsrf,
        'json' => ['ai_grading_enabled' => 1],
    ]);
    $wr = $data($bb);
    $check('PUT 写 ai_grading_enabled 200', $ss === 200 && ($wr['code'] ?? -1) === 0, "got {$ss}: " . mb_substr($bb, 0, 200));

    [, $st, $b] = http('GET', "{$base}/api/admin/settings", ['cookie' => $ck2]);
    $found = null;
    foreach ((array) ($data($b)['data']['fields'] ?? []) as $f) {
        if (($f['key'] ?? '') === 'ai_grading_enabled') {
            $found = $f['value'] ?? null;
        }
    }
    $check('回读 ai_grading_enabled = 1', (string) $found === '1', '回读到: ' . var_export($found, true));

    // 关闭开关，还原现场
    http('PUT', "{$base}/api/admin/settings", [
        'cookie' => $ck2, 'csrf' => $acsrf,
        'json' => ['ai_grading_enabled' => 0],
    ]);
}

// 清理临时管理员
if ($created) {
    try {
        $pdo->prepare('DELETE FROM admininfo WHERE username = ?')->execute([$tmpAdmin]);
        echo "\n  （已删除临时管理员 {$tmpAdmin}）\n";
    } catch (Throwable $e) {
        echo "\n  ⚠️ 清理临时管理员失败: " . $e->getMessage() . "\n";
    }
}

// 回收本脚本自建的 teacher1 正式考试（__E2E__ 前缀）
try {
    $pdo->prepare('DELETE FROM `examinfo` WHERE exam_name = ?')->execute(['__E2E__teacher1正式考试']);
} catch (Throwable $e) {
    echo "\n  ⚠️ 清理 teacher1 正式考试失败: " . $e->getMessage() . "\n";
}

echo "\n════ 汇总 ════\n";
echo "{$pass} PASS / {$fail} FAIL\n";
exit($fail > 0 ? 1 : 0);
