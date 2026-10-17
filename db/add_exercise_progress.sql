-- 在线练习进度表（顺序练习续练 / 随机练习计次）
-- 适用：已部署环境增量加表；全新部署可直接用 db/ci_bootstrap.sql（已含本表）。
CREATE TABLE IF NOT EXISTS `exercise_progress` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stu_id`       VARCHAR(32)  NOT NULL COMMENT '准考证号（与 stuscore.stu_id 一致）',
  `subj_id`      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '科目 id（subject.id）',
  `quiz_class`   VARCHAR(16)  NOT NULL DEFAULT '' COMMENT '题型 radio1/radio2/checkbox/text/longtext',
  `mode`         VARCHAR(16)  NOT NULL DEFAULT 'random' COMMENT 'sequence=顺序练习, random=随机练习',
  `last_quiz_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '最近一次练习的题目 id（顺序续练 / 随机去重）',
  `practiced`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '随机模式：该用户已练习题数',
  `updated_at`   DATETIME    NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stu_subj_mode` (`stu_id`, `subj_id`, `quiz_class`, `mode`),
  KEY `idx_stu` (`stu_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='在线练习进度（考生+科目+题型+模式）';
