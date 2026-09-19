-- ============================================================================
-- 成绩公示与隐私分级（文档 B4）+ 电子证书（C1）+ 补考/重考（C2）
--
-- 1) examinfo.score_visibility：成绩公示粒度
--      private 仅本人（默认，等价于既有行为）/ class 本班可见 / public 全体考生可见
--    「按班级 / 个人粒度控制可见性」——private 即个人粒度，class 即班级粒度。
-- 2) examinfo.cert_threshold：电子证书达标分（0 = 本场不发放）。用绝对分而非
--    百分比，因为「发证」是考务方按场次性质的自主决定，逐场填写最直观。
-- 3) examinfo.retake_of：补考场次指向的源考试 id（NULL = 非补考）。
-- 4) certificate：电子证书颁发记录（惰性签发、唯一键防重）。
-- 5) exam_retake_stu：补考场次的考生名单（补考只对名单内考生开放）。
--
-- 说明：每列独立一条 ALTER，便于幂等脚本逐条跳过已存在的列。
-- ============================================================================

ALTER TABLE `examinfo`
  ADD COLUMN `score_visibility` varchar(16) NOT NULL DEFAULT 'private' COMMENT '成绩公示粒度：private 仅本人/class 本班可见/public 全体考生可见' AFTER `exam_score`;

ALTER TABLE `examinfo`
  ADD COLUMN `cert_threshold` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '电子证书达标分（0=本场不发放证书）' AFTER `score_visibility`;

ALTER TABLE `examinfo`
  ADD COLUMN `retake_of` int(10) unsigned DEFAULT NULL COMMENT '补考场次指向的源考试 id（NULL=非补考）' AFTER `cert_threshold`;

CREATE TABLE IF NOT EXISTS `certificate` (
  `id`          int(10) unsigned NOT NULL AUTO_INCREMENT,
  `exam_id`     int(10) unsigned NOT NULL DEFAULT 0 COMMENT '考试编号',
  `stu_id`      varchar(20) NOT NULL DEFAULT '' COMMENT '准考证号',
  `stu_name`    varchar(50) NOT NULL DEFAULT '' COMMENT '持证人姓名（签发时快照）',
  `exam_name`   varchar(200) NOT NULL DEFAULT '' COMMENT '考试名称（签发时快照）',
  `subj_name`   varchar(100) NOT NULL DEFAULT '' COMMENT '科目名称（签发时快照）',
  `score`       int(10) unsigned NOT NULL DEFAULT 0 COMMENT '证书上载明的得分',
  `total_score` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '满分',
  `threshold`   int(10) unsigned NOT NULL DEFAULT 0 COMMENT '签发时的达标分',
  `cert_no`     varchar(64) NOT NULL DEFAULT '' COMMENT '证书编号（CT+8位日期+32位随机十六进制，共 42 字符）',
  `issued_at`   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '签发时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cert_exam_stu` (`exam_id`, `stu_id`),
  UNIQUE KEY `uk_cert_no` (`cert_no`),
  KEY `idx_cert_stu` (`stu_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='电子证书颁发记录';

CREATE TABLE IF NOT EXISTS `exam_retake_stu` (
  `id`         int(10) unsigned NOT NULL AUTO_INCREMENT,
  `exam_id`    int(10) unsigned NOT NULL DEFAULT 0 COMMENT '补考场次编号',
  `stu_id`     varchar(20) NOT NULL DEFAULT '' COMMENT '准许参加补考的准考证号',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_retake_exam_stu` (`exam_id`, `stu_id`),
  KEY `idx_retake_stu` (`stu_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='补考场次考生名单';

-- 修正：cert_no 实际为 42 字符（CT + 8 位日期 + 32 位随机十六进制），
-- 首版 DDL 写成 varchar(32) 会导致插入报 1406 Data too long、证书永远签不出来。
-- CREATE TABLE IF NOT EXISTS 不会改动已存在的表，故单独补一条幂等加宽语句。
ALTER TABLE `certificate`
  MODIFY COLUMN `cert_no` varchar(64) NOT NULL DEFAULT '' COMMENT '证书编号（CT+8位日期+32位随机十六进制，共 42 字符）';
