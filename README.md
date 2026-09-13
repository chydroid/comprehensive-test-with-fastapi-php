# comprehensive-test-with-fastapi-php

网上理论考核系统 —— 基于 **fastapi-php** 后端框架 + **现代前端**的整体重建版。

> 本项目由 `comprehensive-php-exam-system`（原生 PHP MVC 版）整体重建而来，
> 目标是在**保留并提升全部现有功能**的前提下，获得更清晰的架构、更严格的安全边界与更现代的交互体验。

## 技术栈

| 层 | 选型 | 说明 |
|---|---|---|
| 后端框架 | [fastapi-php](../fastapi-php) | 零 Composer 依赖的 PHP Web API 框架 |
| 数据库 | MySQL（复用 `csip_exam` 表结构） | 14 张业务表，零数据迁移 |
| 前端 | 原生 ES Module + CSS 变量 | **无构建工具**，拷贝即用 |
| API 风格 | RESTful JSON（`{code, message, data}`） | 统一响应信封 |

## 目录结构（规划）

```
comprehensive-test-with-fastapi-php/
├── core/            # 框架内核（来自 fastapi-php）
├── app/
│   ├── Controllers/ # 前台 API + 后台 API 控制器
│   ├── Middlewares/ # 鉴权 / RBAC / 限流 / CORS
│   └── Models/      # 数据模型（对应 14 张业务表）
├── config/          # config.php / routes.php / middleware.php
├── public/          # 入口 + 静态资源（现代前端）
├── database/        # 表结构与种子数据
├── test/            # 测试套件
└── docs/            # 设计与需求文档
```

## 关键改进（相对旧系统）

旧系统审计中发现的问题将在本项目中逐项修复：

- **鉴权**：后台写操作改为**路由级中间件**统一鉴权（修复旧系统 87 个 admin 方法仅 9 处鉴权的问题）
- **CSRF**：修复「空 token + 空 session 时校验通过」的绕过缺陷
- **配置**：数据库凭据移入 `.env`，**不提交明文密码**
- **答题安全**：正确答案不再随页面下发（改为服务端判卷）
- **口令策略**：移除默认弱口令，强制首次登录改密
- **数据访问**：统一走模型层，消除控制器内散落裸 SQL
- **前端**：单一设计令牌来源、无内联脚本、移动端自适应、答题免整页刷新

## 运行方式

```bash
# 1. 配置环境
cp .env.example .env    # 填入数据库凭据
# 2. 初始化数据库
php bin/setup_db.php
# 3. 启动开发服务器
php bin/server.php
```

## 许可证

MIT
