-- C5 考后问卷 + C3 学习资料库（轻量版）
--
-- 幂等：每条 ALTER 单独一条，建表用 IF NOT EXISTS，可重复执行。
-- 用法：php temp/apply_survey_material_ddl.php

-- ============================ C5 考后问卷 ============================

CREATE TABLE IF NOT EXISTS `exam_survey` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `exam_id`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '所属考试编号',
  `sort_no`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '题号（越小越靠前）',
  `title`      varchar(200) NOT NULL DEFAULT '' COMMENT '题目',
  `quiz_type`  varchar(16)  NOT NULL DEFAULT 'rating' COMMENT '题型：rating评分 / choice单选 / text文本',
  `options`    varchar(500) NOT NULL DEFAULT '' COMMENT '选项，按 | 分隔（仅 choice 用）',
  `required`   tinyint(1)   NOT NULL DEFAULT 0 COMMENT '是否必答',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_survey_exam` (`exam_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='考后问卷题目';

CREATE TABLE IF NOT EXISTS `exam_survey_answer` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `qid`        int(10) unsigned NOT NULL DEFAULT 0 COMMENT '题目编号',
  `exam_id`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '冗余考试编号（便于按场统计）',
  `stu_id`     varchar(20) NOT NULL DEFAULT '' COMMENT '作答考生准考证号',
  `answer`     text COMMENT '作答内容（评分题为数字、单选为选项、文本题为文本）',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- 一人一题一答：重复提交走更新而不是插第二行，否则统计会被刷高
  UNIQUE KEY `uk_survey_q_stu` (`qid`, `stu_id`),
  KEY `idx_survey_answer_exam` (`exam_id`),
  KEY `idx_survey_answer_stu` (`stu_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='考后问卷作答';

-- ============================ C3 学习资料库 ============================

CREATE TABLE IF NOT EXISTS `material` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title`      varchar(200) NOT NULL DEFAULT '' COMMENT '资料标题',
  `subj_id`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '所属科目（0=不限）',
  `category`   varchar(60)  NOT NULL DEFAULT '' COMMENT '分类（课件/习题/法规/操作手册…）',
  `summary`    varchar(500) NOT NULL DEFAULT '' COMMENT '简介',
  `file_name`  varchar(255) NOT NULL DEFAULT '' COMMENT '原始文件名',
  `file_url`   varchar(255) NOT NULL DEFAULT '' COMMENT '访问地址（/uploads/xxx 或外链）',
  `file_ext`   varchar(16)  NOT NULL DEFAULT '' COMMENT '扩展名（用于前端选图标）',
  `file_size`  int(10) unsigned NOT NULL DEFAULT 0 COMMENT '字节数',
  `uploader`   varchar(64)  NOT NULL DEFAULT '' COMMENT '上传人',
  `hits`       int(10) unsigned NOT NULL DEFAULT 0 COMMENT '浏览量',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_material_subj` (`subj_id`),
  KEY `idx_material_cat` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='学习资料库（轻量）';
