# comprehensive-test-with-fastapi-php

**深蓝网上考试系统** —— 基于 **fastapi-php** 后端框架 + **现代前端**的整体重建版。

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

## 产品名

产品名（**深蓝网上考试系统**）只有一处定义：`config/config.php` 的 `app.name`。

服务端渲染 SPA 外壳时把它写进 `<html data-app-name="…">`（不能用内联 `<script>`——
站点 CSP 是 `script-src 'self'`，内联脚本会被拦掉），前端统一从
`core/brand.js#appName()` 读取，用于各端 `document.title`、门户导航品牌位、
页脚与登录页页脚。

改名只需改那一行，**不要**再往视图/脚本里写死产品名。
后台「系统设置 → 站点标题」是另一回事（可选的展示标题，落库 `siteconfig.site_title`，
留空则回落产品名）。

## 品牌标识（LOGO）

全站 LOGO 为 **DEEPBLUE** 标识（锚 + 青绿环 + 字标），几何取自
`public/uploads/logo.png` 的矢量化结果，路径数据见 `temp/logo/vectorize.py`。

**它的主形象与文字颜色会随背景自动变化**，这是靠 `currentColor` 实现的：

- `core/logo.js` 把 SVG **内联**进文档（不用 `<img src>`——外部 SVG 拿不到
  `currentColor` 与 CSS 变量，颜色只能写死），两条路径分别取
  `var(--logo-ink, currentColor)` 与 `var(--logo-accent, ...)`；
- `tokens.css` 按主题给出默认墨色（亮底 `#133464` / 暗底 `#cfe0ff`），
  未定义变量时回落到 `currentColor`，跟随所在上下文的文字色。

因此同一份图形放在白底卡片、深色侧栏、启动闪屏上都自动取到合适的颜色。
需要临时改色时，在祖先元素上设 `color`，或覆盖 `--logo-ink` / `--logo-accent`。

```js
import { logoMark, logoLockup } from '../core/logo.js';

logoMark({ height: 30 });    // 环 + 锚，用于已有文字品牌名的位置
logoLockup({ height: 56 });  // 含 DEEPBLUE 字标，用于独立展示品牌
```

更换源图后重新生成资产：

```bash
temp/logo/vectorize.py   # 追踪轮廓 -> temp/logo/trace_raw.json
temp/logo/gen.py         # -> public/assets/img/{logo,logo-mark,favicon}.svg
temp/logo/gen_logo_js.mjs # -> public/assets/js/core/logo.js
```

## 许可证

MIT
