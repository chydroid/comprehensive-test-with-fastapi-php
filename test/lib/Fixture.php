<?php

declare(strict_types=1);

namespace Test;

/**
 * 测试夹具：创建/清理自包含的考试数据，使测试不依赖库中既有业务数据。
 *
 * 使用独立的前缀（exam_name 以 __TEST__ 开头）与保留考生 id 段，
 * 保证 cleanup() 能精确回收，绝不误删真实数据。
 */
final class Fixture
{
    public const PREFIX = '__TEST__';
    public const STU_A  = '9000001';
    public const STU_B  = '9000002';
    public const PWD    = 'testPwd123';
    /** 夹具考生所在班级（= 考试的 stu_class，供 generateForClass 匹配） */
    public const CLASS_ID = self::PREFIX . '班';

    /* ------------------------------------------------------------------
     * 「三人同场」夹具：独立班级 + 独立考生 id 段。
     *
     * 为什么要与 A/B 分开一个班级：createExam()/createExam3() 都会用
     * generateForClass() 按 stu_class 拉全班考生，若共用班级，两者会互相
     * 把对方的考生算进「本场考生数」，让「新生成 N 份试卷」这类断言随
     * 调用顺序漂移。独立班级后两套夹具互不可见。
     * ------------------------------------------------------------------ */
    public const CLASS_ID3 = self::PREFIX . '三人班';
    public const STU_1 = '9000003';
    public const STU_2 = '9000004';
    public const STU_3 = '9000005';

    public static function selfTestWithPwd(): string
    {
        return self::PWD;
    }

    /**
     * 建立一场最低限度的可考考试：
     * - 科目取题库中该科目各题型题量最充足者
     * - 组卷参数取「每题型中难度 1 题」，保证必然有题
     * - 排入考生 A/B，并写入 stuscore（含考场口令）
     *
     * @param array $overrides 覆盖 examinfo 字段（如 exam_status / exam_pwd / exam_start）
     * @return array{exam_id:int, stu_a:string, stu_b:string, exam_pwd:string}|null
     */
    public static function createExam(array $overrides = []): ?array
    {
        $r = self::build([self::STU_A, self::STU_B], self::CLASS_ID, $overrides);
        if ($r === null) {
            return null;
        }
        return ['exam_id' => $r['exam_id'], 'stu_a' => self::STU_A, 'stu_b' => self::STU_B, 'exam_pwd' => $r['exam_pwd']];
    }

    /**
     * 建立一场「三人同场」的考试（独立班级 __TEST__三人班，考生 STU_1/2/3）。
     *
     * 用于「管理员 + 教师 + 三名学生」的全流程端到端演练：需要至少两名考生
     * 才能验证「逐人随机卷互不相同」、以及「对单人收卷不影响其他人」。
     *
     * @param array $overrides 覆盖 examinfo 字段
     * @return array{exam_id:int, students:array<int,string>, class_id:string, exam_pwd:string}|null
     */
    public static function createExam3(array $overrides = []): ?array
    {
        $r = self::build([self::STU_1, self::STU_2, self::STU_3], self::CLASS_ID3, $overrides);
        if ($r === null) {
            return null;
        }
        return [
            'exam_id'   => $r['exam_id'],
            'students'  => [self::STU_1, self::STU_2, self::STU_3],
            'class_id'  => self::CLASS_ID3,
            'exam_pwd'  => $r['exam_pwd'],
        ];
    }

    /**
     * 建场地的公共实现。
     *
     * @param array<int,string> $studentIds
     */
    private static function build(array $studentIds, string $classId, array $overrides = []): ?array
    {
        // 选出一个「四种题型在同一难度下都有题」的科目+难度组合，
        // 保证组卷不会因缺题而产生警告。
        $pick = \Core\Database::fetch(
            "SELECT subj_id, quiz_diff
             FROM `quizlib`
             WHERE quiz_class IN ('radio1','radio2','checkbox','text')
             GROUP BY subj_id, quiz_diff
             HAVING COUNT(DISTINCT quiz_class) = 4
             ORDER BY COUNT(*) DESC
             LIMIT 1"
        );
        if ($pick === null) {
            return null;
        }
        $subjId = (int) $pick['subj_id'];
        $diff = (string) $pick['quiz_diff']; // Y/Z/N

        $field = match ($diff) {
            'Y' => 'easy',
            'Z' => 'mid',
            'N' => 'hard',
            default => 'mid',
        };

        // 每种题型各 1 题，每题 5 分
        $params = [
            'exam_name'        => self::PREFIX . '自动化考试',
            'exam_class'       => self::PREFIX,
            'exam_category_id' => 0,
            'subj_id'          => $subjId,
            // 默认：5 分钟后开考 —— 入场窗口（开考前 15 分钟）此刻已开启
            'exam_start'       => date('Y-m-d H:i:s', time() + 300),
            'exam_end'         => date('Y-m-d H:i:s', time() + 3900),
            'exam_tea'         => 'auto',
            'stu_class'        => $classId,
            'exam_status'      => 'exam',
            'exam_pwd'         => (string) random_int(100000, 999999),
            'exam_score'       => 20,
        ];
        foreach (['radio1', 'radio2', 'checkbox', 'text'] as $type) {
            $params["{$type}_{$field}_sum"] = 1;
            $params["{$type}_val"] = 5;
        }

        // 覆盖项只允许 examinfo 上的字段
        foreach ($overrides as $k => $v) {
            if (array_key_exists($k, $params)) {
                $params[$k] = $v;
            }
        }

        $cols = array_keys($params);
        $sql = 'INSERT INTO `examinfo` (' . implode(',', array_map(fn ($c) => "`$c`", $cols)) . ') VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')';
        \Core\Database::query($sql, array_values($params));
        $examId = \Core\Database::lastInsertId();

        // 确保测试考生存在（班级与考试 stu_class 对齐，使 generateForClass 能匹配到）
        foreach ($studentIds as $i => $id) {
            $name = self::PREFIX . '考生' . ($i + 1);
            $exists = \Core\Database::fetch('SELECT id FROM `stuinfo` WHERE id = ?', [$id]);
            if ($exists === null) {
                \Core\Database::query(
                    'INSERT INTO `stuinfo` (id, stu_name, stu_pwd, stu_sex, grade_id, class_id)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$id, $name, \App\Services\Password::hash(self::PWD), '男', '1', $classId]
                );
            } else {
                \Core\Database::query(
                    'UPDATE `stuinfo` SET stu_pwd = ?, class_id = ? WHERE id = ?',
                    [\App\Services\Password::hash(self::PWD), $classId, $id]
                );
            }
        }

        // 排卷：为每位考生创建 stuscore（含考场口令）
        $pwd = (string) $params['exam_pwd'];
        foreach ($studentIds as $id) {
            \Core\Database::query(
                "INSERT INTO `stuscore` (exam_id, stu_id, stu_score, stu_status, stu_pwd)
                 VALUES (?, ?, 0, 'waiting', ?)",
                [$examId, $id, $pwd]
            );
        }

        return ['exam_id' => $examId, 'students' => $studentIds, 'exam_pwd' => $pwd];
    }

    /** 清理本次夹具创建的全部数据（按前缀与保留考生 id 精确回收） */
    public static function cleanup(): void
    {
        try {
            $exams = \Core\Database::fetchAll(
                'SELECT id FROM `examinfo` WHERE exam_name LIKE ?',
                [self::PREFIX . '%']
            );
            foreach ($exams as $e) {
                $id = (int) $e['id'];
                \Core\Database::query('DELETE FROM `stupaper`  WHERE exam_id = ?', [$id]);
                \Core\Database::query('DELETE FROM `stuscore`  WHERE exam_id = ?', [$id]);
                \Core\Database::query('DELETE FROM `stuscorebak` WHERE exam_id = ?', [$id]);
                // 补考名单与电子证书同样按 exam_id 关联，不清理会在库里越积越多，
                // 并让「按名单开考 / 是否已发证」的断言在后续测试中互相干扰。
                \Core\Database::query('DELETE FROM `exam_retake_stu` WHERE exam_id = ?', [$id]);
                \Core\Database::query('DELETE FROM `certificate` WHERE exam_id = ?', [$id]);
                \Core\Database::query('DELETE FROM `examinfo` WHERE id = ?', [$id]);
            }
            foreach ([self::STU_A, self::STU_B, self::STU_1, self::STU_2, self::STU_3] as $id) {
                \Core\Database::query('DELETE FROM `stupaper` WHERE stu_id = ?', [$id]);
                \Core\Database::query('DELETE FROM `stuscore` WHERE stu_id = ?', [$id]);
                \Core\Database::query('DELETE FROM `exam_retake_stu` WHERE stu_id = ?', [$id]);
                \Core\Database::query('DELETE FROM `certificate` WHERE stu_id = ?', [$id]);
                \Core\Database::query('DELETE FROM `stuinfo`  WHERE id = ?', [$id]);
            }
        } catch (\Throwable $e) {
            echo '  [fixture cleanup warning] ' . $e->getMessage() . "\n";
        }
    }
}
