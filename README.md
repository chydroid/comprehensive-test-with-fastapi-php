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

## 模拟考试 / 在线练习 与正式考试的关系

**默认隔离**：两个策略开关默认关闭 —— 只要存在进行中的正式考试，在线练习与模拟考试
一律暂停。在此之上还有一条**不可配置的硬约束**：考生本人正在考场内时，无论开关如何
设置，都不得同时进行在线练习或模拟考试。

### 暂停判定的两层（唯一出处 `Exam::exercisePause()` / `Exam::mockPause()`）

| 层 | 触发条件 | 可否由后台放开 |
|---|---|---|
| **个人层**（硬约束） | 考生本人正在参加正式考试：该考试 `exam_status = 'testing'` **且未过 `exam_end`** 且其 `stuscore.stu_status ∈ {online, locked}` | **否** |
| **全局层** | 存在进行中的正式考试（同样要求**未过 `exam_end`**）且 对应开关为关闭（默认） | 是 |

- **个人层必须先判**：顺序反过来的话，管理员一开启开关，正在答题的考生就会被放行，
  硬约束形同虚设。
- **「进行中」必须同时看状态与时间**：`exam_end` 已过的考场在业务上已经结束，
  即便 `exam_status` 还没来得及流转，也不得计入「进行中 / 在考 / 待考」
  （唯一出处 `Exam::SQL_NOT_EXPIRED`）。否则**任何一场忘记点「结束」的考试都会
  永久冻结全站的练习与模拟**，并把在场考生永久钉死在硬约束上（BUG-251）。
- 「在考」以**已入场**为准（`online` 由入场与答题心跳写入，`locked` 为监考锁定），
  **不含**仅「已排卷」的 `waiting` —— 出题会在入场前很久就为全班写入 `waiting`，
  若算作在考，考生整个备考期都无法练习，而那时试卷对考生尚不可见、不存在泄露面。
  已交卷（`over` 前缀）者不算在考。
- 暂停期间的拦截范围：练习抽题与答案校验、模拟组卷 / 取题 / 保存 / 错题回顾 一律 403；
  **模拟交卷（`submit`）刻意不拦** —— 暂停可能在考生答到一半时生效，若连交卷也拦掉，
  考生会留下永远无法结束的场次并无谓消耗一次每日额度（交卷只回成绩与对错数，不含答案）。
- 暂停原因经 `pause_reason` 下发：`self_in_exam`（本人在考）/ `exam_ongoing`（全局策略）/
  `''`（未暂停）。前端据此给出不同指引（去处理考试 vs 只能等考试结束）。

### 考试生命周期：两个「惰性」自动流转

本系统**无常驻定时任务**，状态流转一律在有人访问时顺带触发（均幂等）：

| 方法 | 触发条件 | 结果 |
|---|---|---|
| `Exam::autoStartIfDue()` | `paper` 且已到 `exam_start` | → `testing` |
| `Exam::autoEndIfDue()` | `testing` 且已过 `exam_end` | → `over`（`ExamEngine::endExam()`：全员判分 + 状态流转，事务内完成） |

- 调用点：考生答题页轮询 `/api/exam/status`、取题 `/api/exam/paper`、考场入场、
  监考列表与监考详情（管理端与教师端共用）。
- 读取侧（`hasOngoingFormalExam` / `isStudentInExam` / `activeWithSubject` /
  `pendingForStudent`）用 `SQL_NOT_EXPIRED` **独立兜底**，不依赖状态是否已收敛 ——
  一场没人访问的过期考场同样不会再冻结任何功能。
- `exam_end` 未配置（`NULL` / 零值日期 / 空串）一律视为**不过期**，
  避免历史脏数据把考场整体判死。
- 管理端 / 教师端的**考试列表不受**过期过滤影响：教师仍需要看到并清理它。

### 数据隔离（约定）

模拟考试在 `examinfo` 中以 `exam_class = '模拟考试'`（`Exam::MOCK_CLASS`）标记，
它是考生自主生成的临时记录。**除考生本人的答题链路外，其它模块一律排除它**：

| 位置 | 行为 |
|---|---|
| `Exam::adminList()` | 管理端「考试管理」列表与 `total` 均排除（`Admin\MonitorController` 的监考选择页复用同一查询） |
| `TeacherExamController::index()` | 教师端「考试管理」列表与 `total` 均排除 |
| `TeacherExamController::assertOwnExam()` | 教师端全部 `{id}` 接口统一 404，杜绝「列表不显示但直连 id 可操作」 |
| `TeacherMonitorController::index()` | 教师端监考列表排除 |
| `Exam::activeWithSubject()` | 门户 / 仪表盘「进行中的考试」排除 |
| `Exam::pendingForStudent()` | 考生「待考考试」排除 |
| `Exam::finishedList()` | 成绩模块考试下拉排除 |
| `Subject::hasExams()` / `ExamCategory::inUse()` | 孤儿模拟数据不得锁住科目 / 类别的删除 |
| `Exam::hasOngoingFormalExam()` / `Exam::isStudentInExam()` | 模拟考试不计入「进行中的正式考试」，也不算「本人在考」 |

反过来说，只有 **`/api/exercise/mock/*` 与 `/api/exercise*` 这一组接口**会读写模拟考试。

### 后台可配置项（系统设置 → 模拟考试与练习）

| 配置键 | 默认值 | 说明 |
|---|---|---|
| `exercise_allow_during_exam` | **关闭** | 正式考试期间是否开放在线练习 |
| `mock_allow_during_exam` | **关闭** | 正式考试期间是否开放模拟考试 |
| `mock_daily_limit` | 5 场 | 每人每日模拟考试场次上限（含未交卷的） |
| `mock_max_questions` | 100 题 | 单场模拟考试题目总数上限 |

**为什么默认关闭**：练习接口按 `quiz_id` 即可换取任意题目答案，模拟考试的错题回顾会
整卷下发 `quiz_key`，而开考期间 `/api/exam/paper` 会向考生下发其所考题目的 `quiz_id`。
因此「开考期间开放练习 / 模拟」客观上存在反查答案的路径。仅当业务上明确需要
「练习/模拟与正式考试互不影响」时，才由管理员显式开启 —— 即便如此，
**正在考场内的考生仍被个人层硬约束挡住**。

### 运行参数的写入契约（新增设置项 / 写脚本时要记住）

运行参数落库 `siteconfig`，是**覆盖值**而非唯一真源：`Setting::stored()` 专门区分
「从未配置」与「配置成了默认值」。由此产生三条约束：

- `PUT /api/admin/settings` 中 **`null` 表示「撤销覆盖」** —— 删除该行，取值回落
  `Setting::SCHEMA[键]['default']`；响应 `saved` 汇报「生效后的值」（撤销项即默认值）。
- `GET /api/admin/settings` 额外下发 `stored`（**已落库的键名列表**），客户端据此做快照。
  **不要读「有效值」再写回去**：读到的可能是默认值回落的结果，写回就等于把默认值
  实体化成一行覆盖，永久留在库里（测试脚本还原配置时最容易踩）。
- 只是想「恢复默认」时用 `null`，不要把默认值写进去。

### 上限的落点

- 每日场次：`Exam::mockUsedToday()` 按 `examinfo.exam_start`（即发起时刻）统计当日
  已创建的模拟考试数（**含未交卷**，否则可用「只组卷不交卷」绕过上限）；
  `POST /api/exercise/mock/start` 在两个位置复检（预检 + 事务内），超限返回 **429**。
- 单场题数：各题型数量之和超过 `mock_max_questions` 时返回 **400**（错误码 `40002`，
  `data` 回传 `max` / `requested`）；前端组卷页同时就地钳制输入。
- 组卷页所需配额（剩余场次 / 单场上限 / 是否暂停）由
  `GET /api/exercise/mock/config` 的 `limits` 下发，避免考生配好整卷才被拒。

> 并发说明：每日上限用的是「预检 + 事务内复检」而非行锁，极端并发（同一考生
> 同时发多个组卷请求）下仍可能多出 1 场；前端按钮在请求期间禁用已覆盖常见场景，
> 若需强一致需引入独立的配额表或 `SELECT … FOR UPDATE`。

## 许可证

MIT
