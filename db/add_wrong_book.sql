-- 错题本表（A1）：跨「正式考试 / 模拟考试 / 在线练习」归集考生答错的题目
-- 以 stu_id + quiz_id 为唯一键，重复答错累加 wrong_count 并复位 mastered。
CREATE TABLE IF NOT EXISTS `wrong_book` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stu_id`       VARCHAR(32)  NOT NULL COMMENT '准考证号（与 stuscore.stu_id 一致）',
  `quiz_id`      INT UNSIGNED NOT NULL COMMENT '题目 id（quizlib.id）',
  `exam_type`    VARCHAR(16)  NOT NULL COMMENT 'formal=正式考试, mock=模拟考试, exercise=在线练习',
  `exam_id`      INT UNSIGNED NULL     COMMENT '来源考试 id（formal/mock）；练习为 NULL',
  `paper_id`     INT UNSIGNED NULL     COMMENT 'stupaper.paper_id（formal/mock）；练习为 NULL',
  `wrong_count`  INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '累计答错次数',
  `mastered`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '0 未掌握 / 1 已重练答对',
  `last_wrong_at` DATETIME   NOT NULL COMMENT '最近一次答错时间',
  `created_at`   DATETIME    NOT NULL,
  `updated_at`   DATETIME    NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stu_quiz` (`stu_id`, `quiz_id`),
  KEY `idx_stu_mastered` (`stu_id`, `mastered`),
  KEY `idx_stu_type` (`stu_id`, `exam_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='考生错题本（跨模式归集，按 stu_id+quiz_id 聚合）';
