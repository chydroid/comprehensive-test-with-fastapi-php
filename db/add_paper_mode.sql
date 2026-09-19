-- A3 组卷多样化：组卷模式 + 知识点维度
-- 应用于 csip_exam 库（与 add_wrong_book.sql / add_admin_log.sql 同机制）

-- 1) 考试增加「组卷模式」列
ALTER TABLE `examinfo`
  ADD COLUMN `paper_mode` VARCHAR(16) NOT NULL DEFAULT 'random'
  COMMENT '组卷模式：random 按题型×难度随机 / manual 手动选题 / by_kp 按知识点比例'
  AFTER `exam_class`;

-- 2) 题库增加「知识点/章节」列（此前无该字段，按知识点组卷/分析依赖它）
ALTER TABLE `quizlib`
  ADD COLUMN `quiz_kp` VARCHAR(120) NOT NULL DEFAULT ''
  COMMENT '知识点 / 章节，用于按知识点组卷与薄弱项定位'
  AFTER `subj_id`;

-- 3) 手动选题模式：考试绑定的具体题目
CREATE TABLE IF NOT EXISTS `exam_manual_quiz` (
  `exam_id` INT UNSIGNED NOT NULL,
  `quiz_id` INT UNSIGNED NOT NULL,
  `sort`    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`exam_id`, `quiz_id`),
  KEY `idx_emq_quiz` (`quiz_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='手动选题模式：考试绑定的具体题目（sort 控制卷面顺序）';

-- 4) 按知识点比例组卷：每知识点的抽题计划
CREATE TABLE IF NOT EXISTS `exam_kp_plan` (
  `exam_id` INT UNSIGNED NOT NULL,
  `kp`      VARCHAR(120) NOT NULL DEFAULT '' COMMENT '知识点',
  `diff`    CHAR(1) NOT NULL DEFAULT '' COMMENT '难度 Y/Z/N，空串表示不限难度',
  `cnt`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '该知识点抽题数量',
  `sort`    INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`exam_id`, `kp`, `diff`),
  KEY `idx_ekp_kp` (`kp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='按知识点比例组卷：每个知识点的抽题计划';
