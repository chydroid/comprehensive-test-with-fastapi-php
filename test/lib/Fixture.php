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
     * @return array{exam_id:int, stu_a:string, stu_b:string, exam_pwd:string}|null
     */
    public static function createExam(): ?array
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
            'exam_start'       => date('Y-m-d H:i:s'),
            'exam_end'         => date('Y-m-d H:i:s', time() + 3600),
            'exam_tea'         => 'auto',
            'stu_class'        => self::PREFIX . '班',
            'exam_status'      => 'testing',
            'exam_pwd'         => random_int(100000, 999999),
            'exam_score'       => 20,
        ];
        foreach (['radio1', 'radio2', 'checkbox', 'text'] as $type) {
            $params["{$type}_{$field}_sum"] = 1;
            $params["{$type}_val"] = 5;
        }

        $cols = array_keys($params);
        $sql = 'INSERT INTO `examinfo` (' . implode(',', array_map(fn ($c) => "`$c`", $cols)) . ') VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')';
        \Core\Database::query($sql, array_values($params));
        $examId = \Core\Database::lastInsertId();

        // 确保测试考生存在
        foreach ([self::STU_A => '自动测试甲', self::STU_B => '自动测试乙'] as $id => $name) {
            $exists = \Core\Database::fetch('SELECT id FROM `stuinfo` WHERE id = ?', [$id]);
            if ($exists === null) {
                \Core\Database::query(
                    'INSERT INTO `stuinfo` (id, stu_name, stu_pwd, stu_sex, grade_id, class_id)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$id, $name, \App\Services\Password::hash(self::PWD), '男', '1', '1']
                );
            } else {
                \Core\Database::query(
                    'UPDATE `stuinfo` SET stu_pwd = ? WHERE id = ?',
                    [\App\Services\Password::hash(self::PWD), $id]
                );
            }
        }

        // 排卷：为考生 A 创建 stuscore（含考场口令），B 仅建成绩行用于教师端测试
        $pwd = (string) $params['exam_pwd'];
        foreach ([self::STU_A, self::STU_B] as $id) {
            \Core\Database::query(
                "INSERT INTO `stuscore` (exam_id, stu_id, stu_score, stu_status, stu_pwd)
                 VALUES (?, ?, 0, 'waiting', ?)",
                [$examId, $id, $pwd]
            );
        }

        return [
            'exam_id'  => $examId,
            'stu_a'    => self::STU_A,
            'stu_b'    => self::STU_B,
            'exam_pwd' => $pwd,
        ];
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
                \Core\Database::query('DELETE FROM `examinfo` WHERE id = ?', [$id]);
            }
            foreach ([self::STU_A, self::STU_B] as $id) {
                \Core\Database::query('DELETE FROM `stupaper` WHERE stu_id = ?', [$id]);
                \Core\Database::query('DELETE FROM `stuscore` WHERE stu_id = ?', [$id]);
                \Core\Database::query('DELETE FROM `stuinfo`  WHERE id = ?', [$id]);
            }
        } catch (\Throwable $e) {
            echo '  [fixture cleanup warning] ' . $e->getMessage() . "\n";
        }
    }
}
