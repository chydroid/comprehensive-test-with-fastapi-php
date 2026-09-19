-- ============================================================================
-- A4 主观题批改
--
-- 1) examinfo：补齐问答题（longtext）的组卷维度。
--    此前 Exam::TYPE_PREFIXES 只有 radio1/radio2/checkbox/text 四种客观题，
--    试卷矩阵里根本没有问答题的位置，因此主观题「出不了卷」也就无从批阅。
--    这里补上 longtext 的难度分布与分值列，组卷能力与其它题型对齐。
-- 2) stupaper：保存人工批阅结果（每题一行，故得分落在行上而非新表）。
--    quiz_status 语义保持「0=未作答 / 非 0=已作答」，不挪作批阅标记，
--    批阅状态由 quiz_score IS NULL 判定，避免与既有的答题卡逻辑打架。
--
-- 说明：每列独立一条 ALTER，便于幂等脚本逐条跳过已存在的列。
-- ============================================================================

ALTER TABLE `examinfo`
  ADD COLUMN `longtext_easy_sum` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '问答题·易 抽题数' AFTER `text_val`;

ALTER TABLE `examinfo`
  ADD COLUMN `longtext_mid_sum` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '问答题·中 抽题数' AFTER `longtext_easy_sum`;

ALTER TABLE `examinfo`
  ADD COLUMN `longtext_hard_sum` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '问答题·难 抽题数' AFTER `longtext_mid_sum`;

ALTER TABLE `examinfo`
  ADD COLUMN `longtext_val` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '问答题每题分值' AFTER `longtext_hard_sum`;

ALTER TABLE `stupaper`
  ADD COLUMN `quiz_score` int(10) unsigned NULL DEFAULT NULL COMMENT '主观题得分（NULL=未批阅）' AFTER `quiz_status`;

ALTER TABLE `stupaper`
  ADD COLUMN `quiz_comment` varchar(500) NOT NULL DEFAULT '' COMMENT '批阅评语' AFTER `quiz_score`;

ALTER TABLE `stupaper`
  ADD COLUMN `grader_name` varchar(64) NOT NULL DEFAULT '' COMMENT '批阅人（教师名/管理员）' AFTER `quiz_comment`;

ALTER TABLE `stupaper`
  ADD COLUMN `graded_at` datetime NULL DEFAULT NULL COMMENT '批阅时间' AFTER `grader_name`;
