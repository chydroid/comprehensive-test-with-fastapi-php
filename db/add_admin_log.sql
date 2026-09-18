-- 系统操作审计日志表（B2 功能，2026-09-18 新增）
-- 用途：记录关键写操作（登录、考试增删改、成绩备份、设置变更、题库与人员增删、
--       监考收卷/锁定、系统初始化等），便于事后追溯「谁改了什么」。
-- 设计原则：审计只写新表，绝不参与业务事务、绝不打断业务流程（写失败仅记日志）。

CREATE TABLE IF NOT EXISTS `admin_log` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_type`  VARCHAR(16)  NOT NULL DEFAULT ''   COMMENT '操作者类型：admin/teacher/student/system',
  `actor_id`    VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '操作者标识（管理员id/教师名/准考证号）',
  `actor_name`  VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '操作者展示名',
  `action`      VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '动作类型，命名空间式，如 exam.create / score.backup',
  `target`      VARCHAR(128) NOT NULL DEFAULT ''   COMMENT '操作对象，如 exam:123 / student:9000001',
  `detail`      TEXT         NULL                  COMMENT '结构化详情（JSON）',
  `ip`          VARCHAR(64)  NOT NULL DEFAULT ''   COMMENT '操作者来源 IP',
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`),
  KEY `idx_actor` (`actor_type`, `actor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='系统操作审计日志';
