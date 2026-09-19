-- ============================================================================
-- B1 防作弊基础能力
--
-- 1) stupaper：新增 option_order（选项乱序），存储考生本卷该题选项的展示顺序
--    （字母序列，如 "CABD"）。选项仍按「字母=正确答案键」判分，乱序只影响展示，
--    因此不影响判分口径。
-- 2) stuscore：新增 cheat_count（异常次数）与 exam_token（多端互踢设备令牌）。
-- 3) cheat_event：考试异常行为记录表（切屏 / 窗口失焦 / 多端互踢 等）。
--
-- 说明：每列独立一条 ALTER，便于幂等脚本逐条跳过已存在的列。
-- ============================================================================

ALTER TABLE `stupaper`
  ADD COLUMN `option_order` varchar(255) NOT NULL DEFAULT '' COMMENT '选项乱序：考生本卷该题选项展示顺序（字母序列）' AFTER `quiz_status`;

ALTER TABLE `stuscore`
  ADD COLUMN `cheat_count` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '异常行为次数（切屏/换设备）' AFTER `stu_pwd`;

ALTER TABLE `stuscore`
  ADD COLUMN `exam_token` varchar(64) NOT NULL DEFAULT '' COMMENT '考场登录设备令牌（多端互踢）' AFTER `cheat_count`;

CREATE TABLE IF NOT EXISTS `cheat_event` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `exam_id`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '考试编号',
  `stu_id`     varchar(20) NOT NULL DEFAULT '' COMMENT '准考证号',
  `event_type` varchar(32) NOT NULL DEFAULT '' COMMENT '异常类型：tab_hidden/blur/other_device',
  `detail`     varchar(500) NOT NULL DEFAULT '' COMMENT '明细',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '发生时间',
  PRIMARY KEY (`id`),
  KEY `idx_cheat_exam_stu` (`exam_id`, `stu_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='考试异常行为记录';
