# 项目全面审查 —— 缺陷清单与修复计划

> 审查时间：2026-09-15
> 审查范围：`app/`（32 控制器 / 14 模型 / 5 服务 / 3 中间件）、`core/`（框架内核 24 文件）、
> `config/`、`public/assets/js/`（38 文件 9502 行）、`test/`
> 审查方式：分区域逐文件精读 + 路由×鉴权规则交叉核对 + **真实 HTTP 请求实证复现**
> 代码规模：PHP 87 文件 12025 行，JS 38 文件 9502 行

---

## 一、总览

| 等级 | 数量 | 含义 | 已实证 |
|---|---:|---|---:|
| **P0** | 7 | 安全漏洞 / 数据损坏 / 功能阻断 | 3 项真实请求复现 |
| **P1** | 18 | 功能错误 / 一致性 / 体验阻断 | 代码确认 |
| **P2** | 10 | 健壮性 / 性能 / 加固 | 代码确认 |

**已确认无问题**（避免误报）：SQL 注入（真预处理 + 标识符白名单，全库无 `whereRaw`）、
垂直越权（三套会话隔离，130+ 路由无漏配）、前端 XSS（业务数据全走 `textContent`，仅 5 处
`innerHTML` 且均为静态常量）、路由优先级、响应头 CRLF 注入、密码哈希（bcrypt + `hash_equals`）、
CSRF 比较逻辑本身（`hash_equals` + 空值 fail-closed）。

### 实证复现结果（`temp/repro_p0.mjs`，真实 HTTP）

```
===== 用例1：独立考场入口 CSRF =====
  入场: 200 进入考场成功
PASS  1a 独立入口能成功入场
  登录响应是否下发 csrf_token: "(无)"
  保存答案: 419 会话缺少安全令牌，请重新登录
FAIL  1b 保存答案不被 CSRF 拦截            -> status=419
  交卷: 419 会话缺少安全令牌，请重新登录
FAIL  1c 交卷不被 CSRF 拦截                -> status=419

===== 用例2：/api/student/exams 是否泄露考场口令 =====
  {"id":221,...,"exam_pwd":624620,"needs_pwd":true,...}
FAIL  2 学生列表不应包含 exam_pwd          -> 泄露: "exam_pwd":624620
```

---

## 二、P0 —— 必须修复

### BUG-001　从 `/exam` 独立入口入场，考生无法保存答案与交卷（419）

- **位置**：`app/Controllers/ExamController.php:117`（`login()`）
- **代码**：
  ```php
  sess_set(self::SESS_EXAM, [ 'exam_id'=>..., 'stu_id'=>..., 'stu_name'=>..., 'login_at'=>time() ]);
  // ← 既不调用 AuthSession::csrfToken()，响应里也没有 csrf_token
  ```
  `csrf_token` 全项目**只在** `AuthSession::login()`（`app/Services/AuthSession.php:34`）写入，
  而该方法仅被 admin/student/teacher 三个登录控制器调用。
- **校验链**：`SessionAuthMiddleware::checkCsrf()` → `anySession()` 命中 `exam_session`
  → `sess_get('csrf_token','')` 为空 → `deny(419)`。
- **触发**：考生打开 `/exam`（`config/routes.php:273` 独立页面，`data-page="exam"`），
  用准考证号 + 考场口令直接入场 —— **全过程不经过个人中心登录**。
- **影响**：**阻断级**。这类考生入场成功、能取卷，但保存答案和交卷全部 419，
  等于白考。原冒烟测试未覆盖该路径（它先登了个人中心，会话里已有 token）。
- **修复**：`login()` 成功后调用 `AuthSession::csrfToken()` 并在响应 `data.csrf_token` 下发。

---

### BUG-002　`/api/student/exams` 把考场口令下发给考生本人

- **位置**：`app/Controllers/StudentController.php:165-169`
- **代码**：
  ```php
  $fresh = $examModel->find((int) $e['id']);
  if ($fresh !== null) {
      $e['exam_status'] = $fresh['exam_status'];
      $e['exam_pwd']    = $fresh['exam_pwd'] ?? '';   // ← 入场凭证回传考生
  }
  ```
  注意 `Exam::pendingForStudent()` 的 SELECT **故意不含** `exam_pwd`，
  作者重新查库只为喂 `entryState()` 判断 `pwd_ready`，取值后却留在了 `$e` 里被序列化。
- **触发**：任意已登录考生 `GET /api/student/exams`（实证：`"exam_pwd":624620`）。
- **影响**：考场口令是「入场窗口 + 口令」双控里唯一的秘密因子。考生刷一次接口
  就能拿到本场（含尚未开考的）口令，入场控制形同虚设。
- **修复**：返回前 `unset($e['exam_pwd'])`，只保留 `entryState()` 的 `pwd_ready` 布尔位。

---

### BUG-003　教师端 10 个 `{id}` 接口无归属校验，教师之间可互操作

- **位置**：`app/Controllers/TeacherExamController.php`
- **代码**：全文只有 `index()`(:44) 与 `save()`(:139) 调用了 `authTeacher()`；
  下列方法**连教师会话都没读**，取到路径 id 直接操作：

  | 方法 | 行号 | 风险 |
  |---|---:|---|
  | `show()` | 82 | 读到他人考试的 **exam_pwd** 与全部组卷参数 |
  | `update()` | 153 | 可改 `exam_tea` 把他人考试**过户**给自己 |
  | `delete()` | 260 | 删除他人考试 + 级联删 `stupaper`/`stuscore`（不可逆） |
  | `start()` | 180 | 强行开考他人考试 |
  | `open()` | 201 | 开放他人考试入场、重置口令 |
  | `generatePapers()` | 222 | 对他人考试排卷 |
  | `students()` | 288 | 拿到他人班级全部考生 stu_id / 姓名 / 成绩 |
  | `scores()` / `exportScores()` | 333 / 362 | 导出他人考试成绩 |
  | `checkQuizCount()` | 98 | 信息泄露 |

- **触发**：教师 B 登录 → `GET /api/teacher/exams/888`（888 属教师 A）。
- **影响**：教师间水平越权全开 + 考场口令外泄 + 数据不可逆损毁。
- **修复**：加 `assertOwnExam(int $id)`（复用 `TeacherMonitorController:145` 的
  `exam_tea === sess['tea_name']` 逻辑），所有 `{id}` 方法首行调用；`update()` 禁止改写 `exam_tea`。

---

### BUG-004　组卷 check-then-act 竞态：并发请求生成重复试卷，成绩可超满分

- **位置**：`app/Services/ExamEngine.php:43-52`
- **代码**：
  ```php
  $existing = Database::fetch('SELECT COUNT(*) AS c FROM `stupaper` WHERE exam_id=? AND stu_id=?', ...);
  if ((int)($existing['c'] ?? 0) > 0) { return ['generated'=>false, ...]; }   // ← 检查在事务外
  Database::beginTransaction();                                              // ← 事务从这里才开始
  ```
  InnoDB 默认 RR 下两个并发事务的快照都读到 `c=0`，各自插入一整套题，
  且 `$paperId` 都从 1 开始编号。全库无 `FOR UPDATE`、无 `(exam_id,stu_id,paper_id)` 唯一键。
- **触发**：管理员与教师端**都有**出题入口，两人同时点击 / 前端重复提交 / 网络重试。
- **影响**：
  1. 题量翻倍，`paper()` 的 `$paperId > $nav['total']` 越界校验失效；
  2. `saveAnswer()` 按 `paper_id` 更新 —— 一次作答写进两个不同 `quiz_id` 的行；
  3. `autoGrade()` INNER JOIN 后按行累加，重复行计两次分，**成绩可超过满分**（`exam_score` 从未 clamp）。
- **修复**：存在性检查移入事务内并改用 `SELECT ... FOR UPDATE`；补唯一索引
  `UNIQUE KEY uk_paper(exam_id, stu_id, paper_id)`；`autoGrade` 对总分做 clamp。

---

### BUG-005　监考「解锁」可把已交卷状态回退，成绩可事后篡改

- **位置**：`app/Models/StuScore.php:67-73`
- **代码**：
  ```php
  public function updateStatus(int $examId, string $stuId, string $status): int {
      return Database::query(
          'UPDATE `stuscore` SET stu_status = ? WHERE exam_id = ? AND stu_id = ?',  // ← 无状态守卫
          [$status, $examId, $stuId])->rowCount();
  }
  public function unlock(int $e, string $s): int { return $this->updateStatus($e, $s, 'online'); }
  ```
  对比：批量版 `lockAll()`(:78) / `unlockAll()`(:86) **都有** `LEFT(stu_status,4)!='over'` 守卫，
  单条版没有 —— 同一语义两份实现分叉。
- **触发**：监考对已交卷考生点「解锁」→ `stu_status` 由 `over` 变 `online`。
- **影响**：连锁击穿三道门禁 —— ① `ExamController::login:74` 已交卷不得再进失效；
  ② `paper()`/`savePaper()` 的 over 校验失效，可重新作答；③ 再次 `submitPaper()`
  → `autoGrade` 无条件覆盖，**已封存的成绩被静默改写**。
- **修复**：`updateStatus` 增加 `$guardOver` 守卫（拒绝从 `over%` 迁出），与批量版统一。

---

### BUG-006　前端路由 `start()` 非幂等：登录后首屏空白、退出登录整页白屏

- **位置**：`public/assets/js/core/router.js:154`、`apps/student.js:72/150/101`、`ui/shell.js:196`
- **代码**：
  ```js
  function start() {
    if (started) return;        // ← 第二次及以后直接返回，不会渲染
    started = true;
    window.addEventListener('hashchange', handle);
    handle();
  }
  ```
  ```js
  // shell.js:196 —— 会把 router 的 outlet 从 DOM 上摘掉
  const host = document.getElementById('app') || document.body;
  clear(host); host.append(root);
  ```
- **触发链**：
  1. 未登录 → `renderAuth()` → 首次 `start()` 正常渲染，`started=true`；
  2. 登录 → `startShell()` → `shell.mount()` 清空 `#app`（**outlet 被移除**）→
     `router.start()` 早退 → 侧边栏出现但内容区为空，必须手点导航；
  3. 退出登录 → `renderAuth()` → `router.start()` 再次早退 → **登录页不再渲染，整页白屏，只能刷新**。
- **影响**：`admin.js:188`、`teacher.js:136` 同理。退出登录后系统不可继续使用，属阻断级。
- **修复**：`start()` 仅用 `started` 保护 `addEventListener`，末尾**无条件** `handle()`；
  `shell.mount()` 不 `clear` 整个宿主；退出登录时 `router.stop()` 复位。

---

### BUG-007　`APP_DEBUG` 在生产开启，SQL 异常原文直出前端

- **位置**：`core/App.php:87-101`、`config/config.php:12`（`.env` 实测 `APP_DEBUG=true`）
- **代码**：
  ```php
  } catch (\PDOException $e) {
      $message = $this->isDebug() ? $e->getMessage() : '数据重复或违反唯一约束';
  } catch (Throwable $e) {
      $message = $this->isDebug() ? $e->getMessage() : '服务器内部错误';
  }
  ```
- **触发**：任意触发异常的请求（如传畸形参数导致 SQL 错误）。
- **影响**：数据库表名、字段名、SQL 片段、服务器绝对路径、内部类名全量泄露（CWE-209）。
- **修复**：交付前置 `APP_DEBUG=false`；代码层改为**仅 `HttpException` 透传消息**，
  PDO/Throwable 一律返回固定文案 + `request_id`，详情只写日志；`public/index.php` 显式
  `ini_set('display_errors','0')`。

---

## 三、P1 —— 功能错误 / 一致性

| 编号 | 标题 | 位置 | 影响 |
|---|---|---|---|
| BUG-008 | 交卷/开考确认弹窗**永不关闭**，`body` 滚动被永久锁死 | `views/exam-runner.js:273`、`views/teacher/index.js:442/524` | 遮罩挡住交卷成功界面与返回按钮 |
| BUG-009 | 模拟考试跳转路由 `/exercise/mock/*` **不存在**，落到 404 | `views/student/mock.js:100/151/193` | 模拟考试主流程三处入口均不可达 |
| BUG-010 | 答题引擎 `dispose` **从未被调用**，1 秒定时器泄漏 | `views/student/exam.js:179`、`student/mock.js:158`、`exam-runner.js:437` | 切走后倒计时仍跑，归零会对已卸载试卷触发自动交卷 |
| BUG-011 | 编辑考试把状态**无条件打回** `exam`，惰性开考永久失效 | `Admin/ExamController.php:163` + `ExamEngine.php:151` | 编辑一次后到点也不会自动开考 |
| BUG-012 | `autoGrade` 无幂等门禁；`endExam` 全员判分**非原子** | `ExamEngine.php:272`、`191-208` | 中途异常留下「半场判分」，考试永远停在 `testing` |
| BUG-013 | 成绩备份 check-then-act 竞态 + 非原子 → 重复备份行 | `InvigilationService.php:103`、`StuScore.php:106` | 备份报表/导出数据成倍虚增 |
| BUG-014 | 删题库题**不校验** `stupaper` 引用 | `Quiz.php:237` `deleteMany` / `:216` `deleteDuplicates` | 已排卷考试静默缺题、总分凭空降低 |
| BUG-015 | `exam_start`/`exam_end` 非法时时间窗校验**被整体跳过** | `Exam.php:181-192`、`ExamController.php:93-104/485` | 入场窗口规则失效；或 `strtotime` 返回 false 导致永不自动交卷 |
| BUG-016 | 成绩列表接口逐条返回 `stu_pwd`（考场口令） | `StuScore.php:57` → `InvigilationService.php:94` | 仅需 `score.view` 即可批量拿全考场口令 |
| BUG-017 | 模拟考试 `exam_status='testing'` **污染全局**：未交卷的模拟考试永久禁用在线练习与题库高级清理 | `ExerciseController.php:39`、`Admin/QuizController.php:202` | 全站练习对所有学生显示「暂停练习」 |
| BUG-018 | 请求体上限 1MB 与上传上限 2MB **冲突** | `core/App.php:73`、`config/config.php:15/171` | 图片上传默认配置下恒被 413 拒绝 |
| BUG-019 | 会话服务端寿命与 Cookie 寿命不一致（无 `gc_maxlifetime`），`login_at` 只写不读 | `core/helpers.php:185`、`config/config.php:95` | 考试中途被踢出、答题数据丢失 |
| BUG-020 | 监考 / 等待室轮询**无 in-flight 保护** | `views/teacher/index.js:572`、`admin/monitor.js:431`、`student/exam.js:130` | 慢网下请求堆积、名单闪烁回退旧数据 |
| BUG-021 | `openModal` 的 ESC / X / 点遮罩关闭路径**不恢复** `body.overflow` | `ui/components.js:302-335` | 任意弹窗按 ESC 后整页无法滚动 |
| BUG-022 | 教师「知道了」用 `.click()` 关不掉弹窗（监听的是 `mousedown`） | `views/teacher/index.js:482` | 弹窗叠在刷新后的页面上 |
| BUG-023 | `finish()` 恒定发起**两次** `loadReview` 请求 | `views/exam-runner.js:314` | `.result` 恒 undefined，`||` 右侧必执行 |
| BUG-024 | 监考页文案硬编码「每 10 秒」，与实际配置不一致 | `admin/monitor.js:106`、`teacher/index.js:512` | 改了刷新间隔后文案撒谎 |
| BUG-025 | 考场端 403 判断 `payload?.status`，而 payload **没有** status 字段 | `apps/exam.js:36` | 条件恒假，考生被锁定后卡在答题页无任何反馈 |

---

## 四、P2 —— 健壮性 / 性能 / 加固

| 编号 | 标题 | 位置 |
|---|---|---|
| BUG-026 | `/api/admin/` 兜底规则**无权限点**，未来新增 admin 路由将静默绕过 RBAC | `SessionAuthMiddleware.php:93/176` |
| BUG-027 | `testAdmin` 角色缺 `grade.add`/`class.add`/`teacher.add`，该角色无法新增班级/年级/教师 | `config/config.php:117-130` |
| BUG-028 | 登录接口**无暴力破解防护**：限流全局开关默认关闭，且无失败锁定 | `config/middleware.php:17`、`config.php:144` |
| BUG-029 | `router.handle()` 异步竞态：视图乱序挂载 + disposer 被覆盖 | `core/router.js:79-129` |
| BUG-030 | 404 页 `innerHTML` 拼接 `${target.path}`（**当前不可达**，属潜伏 DOM XSS） | `core/router.js:141` |
| BUG-031 | 快速翻页竞态 + `saveCurrent()` 未捕获异常 → unhandled rejection | `exam-runner.js:242/149/334` |
| BUG-032 | 密码最短 6 位，且 `Password::verify` 保留无盐 md5 兼容分支 | `Password.php:16/39` |
| BUG-033 | N+1：监考列表最多 200×3 次查询（10 秒轮询）；`autoGrade` 循环内逐题 UPDATE | `Admin/MonitorController.php:41`、`ExamEngine.php:248` |
| BUG-034 | 学生成绩统计与英雄榜**混入模拟考试**成绩 | `StudentController.php:116`、`ScoreController.php:44` |
| BUG-035 | 一键解锁会把**从未登录**的考生（`waiting`）标记为 `online` | `StuScore.php:78-82` |

---

## 五、修复实施结果

本轮已修复 **24 项**（其中 P0 全部 7 项），其余为需要产品决策或大规模重构的项目，
已在下方「未实施项」中逐条标注原因。

| 编号 | 级别 | 状态 | 修复要点 |
|---|---|---|---|
| BUG-001 | P0 | ✅ 已修 | `ExamController::login()` 调 `AuthSession::csrfToken()` 并在响应下发；`views/student/exam.js` 登录成功后注入；`apps/exam.js` 移除两个必然 401 的无效兜底 |
| BUG-002 | P0 | ✅ 已修 | `StudentController::exams()` 返回前 `unset($e['exam_pwd'], $e['stu_pwd'])` |
| BUG-003 | P0 | ✅ 已修 | 新增 `TeacherExamController::assertOwnExam()`，覆盖 show/update/start/open/generate/delete/students/scores/export 共 10 处 |
| BUG-004 | P0 | ✅ 已修 | 组卷存在性检查移入事务，事务首行 `SELECT ... FOR UPDATE` 锁考试行串行化 |
| BUG-005 | P0 | ✅ 已修 | `StuScore::updateStatus()` 增加「已交卷不可迁出」守卫（与批量版统一），保留 `$allowFromSubmitted` 显式放行 |
| BUG-006 | P0 | ✅ 已修 | `router.start()` 仅对事件监听幂等、每次必渲染；`student/teacher` 入口回登录页时重新挂载 outlet |
| BUG-007 | P0 | ✅ 已修 | `core/App.php` 中 PDO/Throwable 一律返回固定文案，详情只写日志 |
| BUG-008 | P1 | ✅ 已修 | 交卷 / 开考 / 结束考试三处确认弹窗持有 `dlg` 并在按钮里 `close()`，补 `onClose` |
| BUG-009 | P1 | ✅ 已修 | 考生中心补注册 `/exercise/mock`、`/mock/take`、`/mock/review` 三个别名路由 |
| BUG-010 | P1 | ✅ 已修 | `ExamTakeView.dispose` 调 `runner.dispose()`；`MockTakeView` 改为返回 `{ node, dispose }` |
| BUG-011 | P1 | ✅ 已修 | `generateForClass` 改按「实际存在试卷」推进状态，不再依赖 `$generated > 0` |
| BUG-012 | P1 | ✅ 已修 | `autoGrade` 加条件 UPDATE 幂等门禁 + 总分封顶 + 批量标记批阅；`endExam` 整体事务化 |
| BUG-016 | P1 | ✅ 已修 | `StuScore::byExam()` 不再 SELECT `stu_pwd`；CSV 导出改用 `examinfo.exam_pwd`（全考场同口令） |
| BUG-018 | P1 | ✅ 已修 | `app.max_body_bytes` 默认 1MB → 6MB（base64 膨胀 1.34 倍） |
| BUG-020 | P1 | ✅ 已修 | 教师监考 `init()` 加 in-flight 保护（保留内部递归）；等待室轮询加进行中标志 |
| BUG-021 | P1 | ✅ 已修 | `openModal` 把 `body.overflow` 恢复下沉进内部 `close()`，覆盖 ESC / X / 点遮罩 |
| BUG-022 | P1 | ✅ 已修 | 「知道了」改用 `dlg.close()`（遮罩监听的是 mousedown，`.click()` 无效） |
| BUG-023 | P1 | ✅ 已修 | 去掉 `loadReview` 的 `.result \|\| (await ...)` 双请求 |
| BUG-024 | P1 | ✅ 已修 | 监考文案改为读取 `monitor_refresh_seconds` |
| BUG-025 | P1 | ✅ 已修 | 403 处理去掉恒假的 `payload?.status === 403` 判断 |
| BUG-026 | P2 | ✅ 已修 | `/api/admin/` 兜底规则加最小权限点 `admin.access`，四个内置角色均已授予 |
| BUG-027 | P2 | ✅ 已修 | `testAdmin` 补齐 grade / class / teacher 的 add（及 delete）权限点 |
| BUG-029 | P2 | ✅ 已修 | `router.handle()` 加导航序号令牌，丢弃过期渲染结果 |
| BUG-030 | P2 | ✅ 已修 | 404 页路径改用 `textContent` 写入，消除潜伏 DOM XSS |
| BUG-031 | P2 | ✅ 已修 | `go()` 加翻页序号令牌；`saveCurrent()` 包 try/catch；自动交卷 `.catch()` |

### 未实施项（需产品决策或较大改动，已记录）

| 编号 | 级别 | 未实施原因 |
|---|---|---|
| BUG-013 | P1 | 成绩备份幂等需给 `stuscorebak` 加唯一索引（涉及表结构变更），建议随下次迁移一并处理 |
| BUG-014 | P1 | 删题前校验 `stupaper` 引用属产品规则（禁止删除 vs 软删除），需确认期望行为 |
| BUG-015 | P1 | 时间解析失败的处理策略需定：拒绝保存 / 拒绝入场 / 降级为手动开考 |
| BUG-017 | P1 | 模拟考试状态污染全局：需引入「模拟考试超时回收」机制，属新增功能 |
| BUG-019 | P1 | 会话寿命与绝对/空闲超时策略需产品定档（考试中途被踢出可接受时长） |
| BUG-028 | P2 | 登录限流依赖部署开关（`RATE_LIMIT_ENABLED`），属运维配置而非代码缺陷 |
| BUG-032 | P2 | 密码最短长度与 md5 兼容分支下线涉及存量账号迁移 |
| BUG-033 | P2 | 监考列表 N+1 需改造为批量分组查询，改动面较大 |
| BUG-034 | P2 | 统计是否排除模拟考试需产品确认口径 |
| BUG-035 | P2 | 一键解锁把 `waiting` 标为 `online`：需先记录锁定前状态才能正确还原 |

---

## 六、修复计划（后续迭代）

### 第一批 —— P0 阻断与安全（优先，**已完成**）

1. **BUG-001** 考场入口下发 CSRF 令牌 —— `ExamController::login()` 调 `AuthSession::csrfToken()`
   并在响应返回；前端 `apps/exam.js` 保存该 token。
2. **BUG-002** 删除 `/api/student/exams` 的 `exam_pwd` 输出。
3. **BUG-003** 教师端加 `assertOwnExam()` 归属校验，覆盖全部 `{id}` 方法；`update()` 禁改 `exam_tea`。
4. **BUG-004** 组卷存在性检查移入事务 + `FOR UPDATE`；补唯一索引；总分 clamp。
5. **BUG-005** `updateStatus()` 增加「已交卷不可迁出」守卫，与批量版统一。
6. **BUG-006** `router.start()` 改为幂等且每次渲染；`shell.mount()` 不清空 `#app`。
7. **BUG-007** `APP_DEBUG` 置 false；异常响应不再透传 PDO/Throwable 原文。

### 第二批 —— 前端阻断与功能不可达

8. BUG-008 / 021 / 022 统一封装 `confirmModal()`，修复弹窗生命周期与 `body.overflow` 恢复。
9. BUG-009 修正模拟考试三处路由为 `/mock` `/mock/take`。
10. BUG-010 `ExamTakeView.dispose` 与 `MockTakeView` 正确调用 `runner.dispose()`。
11. BUG-023 去掉 `loadReview` 双请求；BUG-024 / 025 修正文案与 403 判断。

### 第三批 —— 数据一致性与健壮性

12. BUG-011 编辑考试不再无条件重置 `exam_status`。
13. BUG-012 `autoGrade` 幂等门禁（条件 UPDATE + `rowCount()`）；`endExam` 整体事务化。
14. BUG-013 备份事务化 + 幂等；BUG-014 删题前校验 `stupaper` 引用。
15. BUG-015 时间解析失败不再静默放行；BUG-016 成绩列表不再返回 `stu_pwd`。
16. BUG-020 轮询加 in-flight 保护；BUG-029 路由 handle 加序号令牌。

### 第四批 —— 配置与加固（记录 + 部分实施）

17. BUG-018 / 019 / 026 / 027 / 028 / 032 / 033 / 034 / 035：按风险排序处理，
    其中 BUG-018（上传限制冲突）、BUG-026（RBAC 兜底）、BUG-035（解锁误标在线）一并修复，
    其余（限流开关、密码策略、N+1 大改）记录在案并给出建议。

### 验证方式

- 每个修复配套回归断言，新增 `test/cases/regression_p0_test.php`。
- 全量 `bash test/run_all.sh`（基线 272 PASS）必须保持全绿。
- jsdom 冒烟全套：portal / login×4 / exam_lifecycle / admin_live / settings_view。
- 复现脚本 `temp/repro_p0.mjs` 修复后**必须全部转为 PASS**。

---
---

# 第二轮全面审查 —— 缺陷清单与修复（追补）

> 触发：要求「再次全面细致地检查」。做法上不止重复首轮，而是针对性做两类**交叉核对**：
> ① 首轮的修复是否留下了「同类但未覆盖的路径」（安全补丁最怕只堵一个出口）；
> ② 前端声明/消费的字段契约是否与后端真实返回一致（首轮正是这样发现图片路径错的）。
> 结果：新增 **9 项**（2 P0 / 6 P1 / 1 P2），**全部已修复**并配套回归。

## 一、总览（第二轮）

| 编号 | 等级 | 一句话 |
|---|---|---|
| BUG-101 | P1 | 练习抽题缺 `quiz_id` 字段 → 题号显示 `#undefined`、提交答案恒 400 |
| **BUG-102** | **P0** | 正式考试进行中 `/api/exercise/answer` 仍返回正确答案，可反查在考题目 |
| BUG-103 | P1 | 模拟考试被误判为「正式考试进行中」，练习入口被错误暂停 |
| **BUG-104** | **P0** | 开考期间可组模拟卷并立即交卷，再经 `review()` **批量导出题库答案** |
| BUG-105 | P1 | `/api/health` 从不下发 `X-CSRF-Token`，前端 419 自动恢复形同虚设 |
| BUG-106 | P1 | `/api/exam/status` 不下发 `csrf_token`，答题页刷新后保存/交卷恒 419 |
| BUG-107 | P2 | 成绩 CSV 导出未防公式注入（姓名等以 `= + - @` 开头会被 Excel 当公式执行） |
| BUG-108 | P1 | 答题页配图在每次点选后**重复叠加**（点一次选项多一张图） |
| BUG-109 | P1 | 未启用多选的列表把「操作」列**反复写回**列定义，刷新一次多一列 |

**本轮再次确认无问题**：题库清理工具的「清理中禁止」判断、教师端 `assertOwnExam` 覆盖完整性、
路由表与前端 `api/index.js` 全部端点对齐、`core/Http.js` 的 401/403 事件、`Database` 预处理与
标识符白名单。

---

### BUG-101　练习抽题缺 `quiz_id`，前端题号 `#undefined` 且提交答案恒 400

- **位置**：`app/Controllers/ExerciseController.php`（`index()` 的 SELECT）
- **代码**：`SELECT q.id, q.subj_id, ...` —— **未起别名**，返回的键是 `id`；
  而消费方 `public/assets/js/views/student/exercise.js` 取的是 `q.quiz_id`：
  - `:187`　`exerciseApi.check({ quiz_id: q.quiz_id, stu_key: val })` → `quiz_id` 为
    `undefined`，被 `JSON.stringify` 丢弃 → 后端 `required|integer` 失败 → **恒 400**；
  - `:218`　`badge('题目 #' + q.quiz_id)` → 界面显示 **`题目 #undefined`**。
- **对照**：正式考试 `ExamController::paper()` 与模拟考试 `ExerciseExamController::paper()`
  都写了 `q.id AS quiz_id`，唯独练习接口漏了 —— 典型的「三处同构、改了两处」。
- **影响**：在线练习的「提交答案」功能完全不可用，且题号错乱（首轮未覆盖，因练习不在冒烟范围）。
- **修复**：`SELECT q.id AS quiz_id, ...`，与另两处保持一致。
- **回归**：`test/cases/regression_audit2_test.php` BUG-101。

---

### BUG-102　【P0】正式考试进行中，练习接口仍可换取正确答案

- **位置**：`app/Controllers/ExerciseController.php::check()`（`POST /api/exercise/answer`）
- **代码**：`index()` 明确写了「进行中的正式考试会锁定练习（防题目泄露）」并据此不下发题目，
  但 `check()` **完全没有这道判断**，直接按 `quiz_id` 查 `quizlib.quiz_key` 并原样返回。
- **完整攻击链**：
  1. 考生在正式考试中，`GET /api/exam/paper` 每道题都返回 `quiz_id`
     （`ExamController.php` `q.id AS quiz_id`）；
  2. 同一浏览器另开标签页 `POST /api/exercise/answer { quiz_id: N }`
     （`/api/exercise/` 只需**个人中心**考生会话，考试期间依然有效）；
  3. 响应 `data.answer` 即该题正确答案。
- **影响**：**击穿首轮 P0-4「考试中不下发答案」的全部防护** —— 考生可逐题反查答案后从容作答。
  首轮只堵了「考试接口不下发答案」这个出口，没堵「练习接口按 id 换答案」这个出口。
- **修复**：`check()` 开头加入与 `index()` 一致的判定，命中则 `403`。
- **回归**：`regression_audit2_test.php` BUG-102（开考期间取答案 → 403）。
- **策略变更（2026-09-17）**：该拦截由「硬编码」改为**后台可配**。
  新增 `Setting::SCHEMA['exercise_allow_during_exam']`（默认**开启**，即与正式考试互不影响）；
  关闭后才在开考期间拦截。响应新增 `practice_paused`（策略结论）与既有
  `has_ongoing_exam`（事实）区分下发，前端改为按前者禁用入口。
  回归随之调整为「默认放行 → 200」＋「显式关闭 → 403」两条。
  取舍说明见 README「模拟考试 / 在线练习 与正式考试的关系」。
- **默认值再变更（2026-09-17）**：默认改为**关闭**（开考期间暂停练习），
  并叠加一条**不可配置的个人硬约束** —— 考生本人正在考场内
  （`Exam::isStudentInExam()`：考试 `testing` 且本人 `stuscore.stu_status ∈ {online,locked}`）
  时一律暂停，后台开关也放不开。回归改为「默认暂停 → 403」＋「显式开启 → 200（正向对照，
  且前置断言考生本人不在考）」两条，两处「默认策略」用例改为显式清空库内覆盖值再断言。

---

### BUG-103　模拟考试被误判为「正式考试进行中」

- **位置**：`app/Controllers/ExerciseController.php::index()`（原判定）
- **代码**：原判定为 `SELECT COUNT(*) FROM examinfo WHERE exam_status='testing'`。
  但 `ExamEngine::createPracticeExam()` 建模拟考试时也是
  `exam_status='testing'`（靠 `exam_class='模拟考试'` 区分）——
  于是**学生自己开一场模拟考试，就会把练习入口判成「考试进行中」并暂停**。
- **佐证**：项目内其它地方都做了排除，如 `Exam.php:118`、`Admin/DashboardController.php:43`
  的 `COALESCE(exam_class,'') != '模拟考试'`，唯独练习这里漏了。
- **影响**：功能误停（自愈于模拟考试交卷），但会让学生困惑「为什么练习被暂停了」。
- **修复**：抽出 `Exam::hasOngoingFormalExam()`（`app/Models/Exam.php`）统一承载该判定，
  内含 `exam_class <> '模拟考试'`；三个调用点（练习列表 / 练习答案 / 模拟考试）共用一份实现。
- **回归**：`regression_audit2_test.php` BUG-103（插入 testing 模拟考试后判定不变）。

---

### BUG-104　【P0】开考期间借模拟考试批量导出整卷答案

- **位置**：`app/Controllers/ExerciseExamController.php`（`start()` / `review()`）
- **代码**：模拟考试可**自由组卷**（`subj_id` + 各题型数量，单题型上限 100 题），
  交卷后 `review()` 会返回每题的 `quiz_key`；而这两个方法都没有「正式考试进行中」的判断。
- **攻击链**：开考期间 → `POST /api/exercise/mock/start`（某科目抽满 400 题）
  → `POST /api/exercise/mock/submit`（空卷即刻判分）→ `GET /api/exercise/mock/review`
  → **一次性拿到该科目最多 400 道题的标准答案**，再与自己在考卷面上的 `quiz_id` 对照。
- **影响**：比 BUG-102 更严重（一次导出整库，而非逐题），同样击穿 P0-4。
- **修复**：`start()` 与 `review()` 均加入「正式考试进行中 → 403」；
  `review()` 是真正的答案披露点，`start()` 一并拦截以快速失败并给出明确文案。
  同时把两处硬编码的 `'模拟考试'` 收敛为 `Exam::MOCK_CLASS`。
- **回归**：`regression_audit2_test.php` BUG-104（组卷 → 403；复盘 → 403）。
- **策略变更（2026-09-17）**：同上，改为 `Setting::SCHEMA['mock_allow_during_exam']`
  控制（默认开启）。关闭后组卷与复盘在开考期间均 403；默认开启时练习 / 模拟
  与正式考试互不影响。回归随之改为「显式关闭后才 403」。
- **默认值再变更（2026-09-17）**：默认改为**关闭**，并叠加个人硬约束
  （本人在考时不可放开）。守卫口径统一收敛到 `Exam::mockPause()`，
  拦截面从「组卷 / 复盘」扩展到「组卷 / 取题 / 保存 / 复盘」，
  **交卷（submit）刻意不拦**（否则考生会留下无法结束的场次并白占每日额度）。

---

### BUG-105　`/api/health` 从不下发 `X-CSRF-Token`，419 自动恢复是死代码

- **位置**：`config/routes.php`（`GET /api/health` 处理器） × `public/assets/js/core/http.js:118`
- **代码**：前端 419 处理写得很完整 —— 调 `refreshCsrf()` 读 `/api/health` 的
  **`X-CSRF-Token` 响应头**再重试一次；但后端**全项目从未下发过该响应头**
  （令牌只出现在登录/`/me` 的 JSON body 里）。`refreshCsrf()` 恒取到空 → 重试仍 419。
- **影响**：任何原因导致的令牌丢失都无法自愈，用户表现为「刷新页面后所有写操作都失败」。
- **修复**：`/api/health` 返回时补 `X-CSRF-Token`（取 `AuthSession::csrfToken()`）。
  令牌与会话绑定，跨站脚本受同源策略限制无法读取该响应头，公开下发无安全风险。
- **回归**：`regression_audit2_test.php` BUG-105（响应头存在且为 64 位十六进制）。

---

### BUG-106　答题页刷新后保存 / 交卷恒 419

- **位置**：`app/Controllers/ExamController.php::status()` ×
  `public/assets/js/views/student/exam.js`（`ExamTakeView.boot()`）
- **代码**：考场入口的令牌只在**入场那一刻**由登录响应注入
  （`apps/exam.js` 注释：「令牌由 ExamLoginView 在入场成功后注入，此处无需预取」）。
  但考生在答题中**刷新页面**时，模块级 `csrfToken` 随 ESM 状态一起重置为空，
  而 `ExamTakeView.boot()` 只调 `/api/exam/status` —— 该接口**不返回** `csrf_token`
  → 保存 / 交卷 419；再叠加 BUG-105，自动恢复也失效。
- **影响**：考生中途刷新即「无法保存、无法交卷」，且倒计时归零时的自动交卷同样 419 → 成绩丢失。
- **修复**：`status()` 响应补 `csrf_token`；`ExamTakeView` 在 `boot()` 与等待室轮询中
  每次都 `setCsrfToken(data.csrf_token)` 重新注入。
- **回归**：`regression_audit2_test.php` BUG-106（`/status` 的令牌与入场令牌一致）。

---

### BUG-107　成绩 CSV 未防公式注入

- **位置**：`app/Services/InvigilationService.php::csv()`（管理端与教师端导出共用）
- **代码**：`fputcsv()` 只解决「逗号/引号转义」，不解决**公式注入**。
  `stu_name` / `grade_id` / `class_id` 多为导入数据，若以 `=` `+` `-` `@` 或制表符、回车开头，
  Excel / WPS 打开导出文件时会当作公式执行（可触发 DDE 或外链请求）。
- **修复**：新增 `csvSafe()`，仅对**字符串列**前置单引号使其恒为文本；成绩等数值列不受影响
  （避免把负数变成 `'-1`）。
- **回归**：`regression_audit2_test.php` BUG-107（`=1+1` 被转义、正常姓名不加引号）。

---

### BUG-108　答题页配图每次点选都重复叠加

- **位置**：`public/assets/js/views/exam-runner.js::renderQuestion()`
- **代码**：配图用 `stemNode.after(pic)` 插入为**常驻节点**，而 `renderQuestion()` 在
  **每次点选后都会被重绘**（单选换项 `:218`、多选切换 `:214` 都会重新调用），
  旧图从不清理 → 点一次选项就在题干下多叠一张图。
- **对照**：练习（`student/exercise.js`）与模拟（`student/mock.js`）每帧 `clear()` 重建，不受影响 ——
  该缺陷**仅存在于正式考试的答题引擎**，而它同时被正式考试与模拟考试复用。
- **修复**：以模块内 `picNode` 记住当前配图，重绘前先 `remove()`；配图加载失败时同步置空。
- **回归**：jsdom 运行时复现 `temp/domtest/repro_audit2.mjs` → `1 / 1 / 1`（修复前为 `1 / 2 / 3`）。

---

### BUG-109　列表「操作」列被反复写回，刷新一次多一列

- **位置**：`public/assets/js/ui/crud.js::render()`
- **代码**：
  ```js
  const allColumns = selectable ? [checkboxColumn(), ...columns] : columns;  // ← 同一引用
  if (rowActions) allColumns.push({ key: '__ops', title: '操作', ... });      // ← 污染调用方数组
  ```
  未启用多选时 `allColumns === columns`，`push` 会**直接改写调用方传入的列定义**；
  而 `render()` 每次 `load()`（翻页 / 搜索 / 排序 / 保存后回刷）都会执行 → 操作列不断叠加。
- **影响**：`admin/exam.js`、`admin/simple-crud.js`（科目/班级/新闻等）都未启用 `selectable`
  → 每刷新一次表格就多一列「操作」。`quiz.js` / `student.js` 因启用多选而幸免。
- **修复**：未启用多选时也做浅拷贝 `[...columns]`。
- **回归**：jsdom 运行时复现 `temp/domtest/repro_audit2.mjs` → 表头列数稳定 `2 / 2 / 2`、
  调用方 `columns.length` 恒为 `1`（修复前为 `2 / 3 / 4`）。

---

## 二、修复与验证结果（第二轮）

- **PHP lint**：6 个改动 PHP 文件全部 `No syntax errors`。
- **JS 语法**：3 个改动 JS 文件 `node --check` 全通过；`temp/check_frontend.mjs`
  38 文件 0 语法 / 0 未解析导入。
- **全量回归**：`bash test/run_all.sh` → **321 PASS / 0 FAIL / 0 SKIP**
  （首轮 293 + 本轮新增 `test/cases/regression_audit2_test.php` 28 断言）。
- **运行时复现**：`temp/domtest/repro_audit2.mjs`（jsdom 加载**真实** `exam-runner.js` /
  `crud.js`）两处均为 `FIXED`。

### 本轮改动文件

| 文件 | 修复 |
|---|---|
| `app/Models/Exam.php` | 新增 `hasOngoingFormalExam()` / `MOCK_CLASS` |
| `app/Controllers/ExerciseController.php` | BUG-101 `quiz_id` 别名；BUG-102 答案校验守卫；BUG-103 判定收敛 |
| `app/Controllers/ExerciseExamController.php` | BUG-104 `start()`/`review()` 守卫；`MOCK_CLASS` 收敛 |
| `app/Controllers/ExamController.php` | BUG-106 `status()` 下发 `csrf_token` |
| `app/Services/InvigilationService.php` | BUG-107 `csvSafe()` 防公式注入 |
| `config/routes.php` | BUG-105 `/api/health` 下发 `X-CSRF-Token` |
| `public/assets/js/views/exam-runner.js` | BUG-108 配图去重 |
| `public/assets/js/ui/crud.js` | BUG-109 列定义浅拷贝 |
| `public/assets/js/views/student/exam.js` | BUG-106 令牌重新注入 |
| `test/cases/regression_audit2_test.php` | 新增 28 断言回归 |

---
---

# 第三轮全面审查 —— 缺陷清单与修复（2026-09-16）

> 触发：要求「全面细致地检查本项目，发现 bug 或潜在 bug 要做好详细记录，然后制定一个完善的修复计划并实施，
> 最后模拟浏览器再次全面检查所有页面的所有功能」。
>
> 本轮做法与上两轮的区别：**以真实浏览器点击「保存」为验收口径**，而不是只打开弹窗。
> 这一改变直接暴露了前两轮完全漏掉的一整类缺陷 —— **所有弹窗表单的「保存」按钮从未生效**。
> 前两轮巡检只断言「弹窗能打开」「页面非空」「无 JS 错误」，而「点保存没反应」既不报错、也不清空页面，
> 因此连续两轮巡检都是 PASS。

## 一、总览（第三轮）

| 级别 | 数量 | 说明 |
|---|---|---|
| **P0** | 3 | 后台全部 CRUD 表单无法保存；题库编辑器无法保存；单人「收卷」误伤全场 |
| **P1** | 11 | 字段名前后端不一致 ×3、`submitAll` 方法缺失、行内操作不渲染、教师监考死链、考试状态被打回、模拟考试阻塞系统维护、考场登录缺班级校验、备份可对进行中考试执行、注册越界残留孤儿行 |
| **P2** | 14 | page 无上限致 500、公开接口泄露安全参数、`WRITE_POINTS` 的 GET 条目失效、级联删除漏 `stuscorebak`、`LIKE` 未转义、N+1、题库清理阻塞、会话互踢、CSP 下 `onerror` 失效、分页越界、焦点陷阱泄漏、`[object Object]` 渲染 ×2、资料页单位被清空 |
| 合计 | **28** | |

### 关键验证：P0-201 实证（`temp/domtest/prove_modal_save.mjs`，真实 Chromium）

```
点击按钮: 新增科目
弹窗探测: { hasForm: true, formInBody: true, saveText: "保存", saveType: "submit",
            saveFormOwner: null, saveInForm: false }
已填入: ZZ自检科目785
保存后:   { modalStillOpen: true, rows: 3, toast: "", modalErr: "" }   ← 列表行数 3→3，无任何提示
==== 2 PASS / 4 FAIL ====
```

结论：保存按钮**没有 form owner**，点击是彻底的空操作 —— 既不提交、也不报错、也不关弹窗。

## 二、P0 —— 阻断（必须修复）

### BUG-201　后台全部「新增/编辑」弹窗的保存按钮无效（表单从未被提交）
- **位置**：`public/assets/js/ui/crud.js:320/323-325/327`、`public/assets/js/ui/components.js:333-336`
- **根因**：`openFormModal()` 把 `<form>` 作为 `openModal({ body: form, footer: [cancelBtn, submitBtn] })` 传入，
  而 `openModal` 把 `body` 挂进 `.modal-body`、把 `footer` 追加为**兄弟节点** `.modal-footer`：
  ```js
  modal.append(head, bodyEl);
  if (footer) modal.append(el('div.modal-footer', {}, ...));   // ← 与 form 平级
  ```
  HTML 规范中 `type=submit` 按钮的 form owner 只由「`form` 属性」或「最近的 form 祖先」决定，
  两者都没有 → **按钮点击不产生任何提交行为**（连 click 事件都没被监听，因为提交依赖 `form` 的 submit 事件）。
- **影响范围**：`views/admin/simple-crud.js` 承载的全部基础数据模块 —— 考试科目、考试类别、单位管理、
  班级管理、教师管理、管理员、考试公告；以及 `views/admin/student.js` 考生管理的新增/编辑。
  即**后台几乎所有写操作入口全部失效**，且界面无任何报错，用户只会以为「点了没反应」。
- **为何前两轮漏报**：巡检只断言「弹窗打开成功」，未点击保存；`settings_view_smoke.mjs` 覆盖的是
  「系统设置」页（自建表单，不走 `openFormModal`），故未被发现。
- **修复**（中心化，一处覆盖全部调用方）：`openModal` 在挂载后，若 `bodyEl` 内恰有 1 个 `<form>`，
  自动为其生成 `id`，并把 `footer` 中所有 `button[type=submit]` 的 `form` 属性指向它。
  这样既修好 `crud.js`，也修好任何「form 作 body + submit 按钮放 footer」的视图。

### BUG-202　题库编辑器「创建题目 / 保存修改」永久无法提交
- **位置**：`public/assets/js/views/admin/quiz.js:280`（`el('div.form-grid', …)`）、`:311`、`:318`
- **根因**：表单容器是 `div` 而非 `form`，`div` 永不派发 `submit` 事件；同时提交按钮位于 `footer`（无 form owner）。
  两条路径同时失效 → 题库无法新增/编辑任何题目。
- **修复**：`div.form-grid` → `form.form-grid`；配合 BUG-201 的中心化 form-owner 关联即可生效。

### BUG-203　监考「收卷」按人操作实际把**全场**考生强制交卷
- **位置**：`public/assets/js/views/admin/monitor.js:371-372/385`、`views/teacher/index.js:582`、
  `public/assets/js/api/index.js:167/72`、`config/routes.php:236/133`
- **根因**：行内「收卷」调用 `adminApi.submit({ exam_id, stu_id })` → `POST /admin/monitor/submit`，
  而该路由在路由表中指向 **`submitAll`**（整场），后端 `examId()` 只读 `exam_id`、完全忽略 `stu_id`。
  监控端与教师端同构。
- **影响**：监考员想给 1 名考生收卷，结果**全场考生被强制交卷并判分**，不可撤销（属数据事故）。
  而该行内按钮因 BUG-206（`rowActions` 不被 `table()` 支持）目前又根本不渲染 —— 属**潜伏**，
  一旦补上操作列即会立即造成事故，因此必须在补操作列**之前**先修好语义。
- **修复**：新增按人交卷端点 `POST /admin|teacher/monitor/submit-one`（`InvigilationService::submitOne()`），
  前端 `submit` 指向它；`submit` 之外的批量入口统一改名走 `submitAll`。

## 三、P1 —— 功能错误 / 一致性

| 编号 | 标题 | 位置 | 影响 |
|---|---|---|---|
| BUG-204 | 「全部收卷 / 全员交卷」调用不存在的 `api.submitAll` | `views/admin/monitor.js:320/395`、`views/teacher/index.js:540/563`、`api/index.js` | `adminApi[method] is not a function` → toast 抛英文 TypeError，批量收卷不可用 |
| BUG-205 | 考生端「修改密码」字段名与后端不一致 | `views/student/center.js:288-291` ← `StudentController.php:80-84` | 发 `old_password/new_password`，后端要 `old_pwd/new_pwd/new_pwd2` → 恒 400「参数 old_pwd 不能为空」，考生无法自助改密 |
| BUG-206 | 管理端监考行内「锁定/解锁/收卷」永不渲染 | `views/admin/monitor.js:358`、`ui/components.js:197` | `table()` 无 `rowActions` 参数，被静默忽略 → 无法对单个考生操作，`doOne()` 成死代码 |
| BUG-207 | 后台「考生管理」密码字段名错误，密码被静默丢弃 | `views/admin/student.js:125/133-138` ← `Admin/StudentController.php:144` | 发 `stu_pwd`，后端只认 `password` → 管理员设置的密码失效，实际回退为「准考证号」 |
| BUG-208 | 后台「教师管理」新增必然失败，且三个字段无处可存 | `views/admin/basics.js:165-171` ← `TeacherController.php:77-94` | 发 `tea_pwd`，后端 `password` 必填 → 400「参数 password 不能为空」；且 `teainfo` 表**没有** `tea_sex/tea_phone/tea_info` 列，相关输入与列表列均为死字段 |
| BUG-209 | 教师端考试列表「监考」按钮跳到未注册路由 | `views/teacher/index.js:109` ← `apps/teacher.js:121-123` | 跳 `/teacher/monitor?...`，实际注册为 `/monitor` → 「页面不存在」 |
| BUG-210 | 管理员编辑考试把状态**无条件打回** `exam`，惰性自动开考永久失效 | `app/Controllers/Admin/ExamController.php:163` | 出题后状态为 `paper`，编辑一次被改回 `exam`；`Exam::autoStartIfDue()` 只推进 `paper` → 到点永不自动开考，考生卡在等待室。**上一轮把 BUG-011 标记为已修，但只改了 `ExamEngine`，未覆盖此路径** |
| BUG-211 | 模拟考试阻塞「系统初始化 / 清空考试 / 高级清理」 | `Admin/QuizController.php:203`、`Admin/SystemController.php:122` | 两处查 `exam_status='testing'` **未排除 `exam_class='模拟考试'`**（项目已有正确写法 `Exam::hasOngoingFormalExam()`）。考生开一场模拟考试后不交卷 → 管理端全部维护操作 409，且无任何入口可清理 |
| BUG-212 | 考场登录不校验「考生是否属于本场考试参考班级」 | `app/Controllers/ExamController.php:85-94` | 班级归属只用于列表展示，登录入口不校验。任意已注册考生（注册接口公开）猜中 4–10 位纯数字口令即可进入**他人班级**的考试，被 `createScore()` 写入名单、污染成绩单，并回传该场考试信息 |
| BUG-213 | 成绩备份可对**未结束**的考试执行且无事务 | `InvigilationService.php:103-109`、`StuScore.php:119-132`、`Admin/ScoreController.php` | 接口无「必须已结束」前置校验 → 把 `exam_status` 改成 `overBak` 中途冻结考试，而考生仍可继续答题交卷 → 备份快照与真实成绩永久不一致 |
| BUG-214 | 考生自助注册「先 INSERT 自增 id、再 UPDATE 改主键」，越界时 500 且残留孤儿行 | `app/Controllers/StudentAuthController.php:50-58` | `stu_id` 校验为 `regex:/^\d{1,20}$/`，而 `stuinfo.id` 是 INT → 传 11 位以上数字：第 1 步插入成功、第 2 步 UPDATE 越界报错 → 500，且**无事务**，留下一条无法登录的孤儿考生行 |

## 四、P2 —— 健壮性 / 性能 / 加固

| 编号 | 标题 | 位置 |
|---|---|---|
| BUG-215 | `page` 无上限 → `offset` 溢出为浮点数拼进 SQL（MySQL 语法错误 500）；合法大值触发深分页全表扫描 | `BaseController.php:69`、`Model.php:79-84`、`Exam.php:115`、`Quiz.php:162` |
| BUG-216 | `/api/public/site` 用 `SiteConfig::allAsMap()` 全量下发，`Setting` 写入同表的安全参数（限流阈值/密码最小长度）将随之外泄，`publicSubset()` 白名单被架空 | `HomeController.php:26/38-39` |
| BUG-217 | `writePoint()` 见安全方法直接返回读权限点，`WRITE_POINTS` 里的 GET 条目永不生效 → `GET /scores/export` 实际只需 `score.view` 就能拿到含考场口令的 CSV | `SessionAuthMiddleware.php:214-218` |
| BUG-218 | 级联删除/清库漏掉 `stuscorebak`，备份行成孤儿（`INNER JOIN` 后被静默过滤）→ 数据「消失但未删」、无法审计 | `Admin/StudentController.php:230-242`、`Admin/ExamController.php:179-190`、`TeacherExamController.php:280-291`、`Admin/SystemController.php:27/47/58` |
| BUG-219 | 关键字搜索未转义 `LIKE` 的 `%`/`_` → 输入 `%` 退化为全表返回（可绕过筛选、放大深分页成本） | `Admin/StudentController.php:62-66` 等 9 处 |
| BUG-220 | 列表接口普遍 N+1（每行 1~3 次查询）；`MonitorController` 硬编码 `limit 200`，考试数 >200 时静默截断 | `Admin/ExamController.php:62-66`、`Admin/MonitorController.php:41-48`、`Admin/SubjectController.php:52-62` 等 |
| BUG-221 | 模拟考试交卷后仍可改答案并重复交卷重判分（`savePaper/submitPaper` 无状态守卫，`gradeMock` 无幂等门禁）→ 可自助刷分 | `ExerciseExamController.php:209-258/385-441` |
| BUG-222 | `AuthSession::login()` 的 `purge()` 会清掉**考场会话** → 考试中途去考生中心登录会被踢出考场 | `AuthSession.php:27-36/82-88` |
| BUG-223 | 考场登录属权限提升但未 `sess_regenerate()`，与项目自身防会话固定策略不一致（CWE-384） | `ExamController.php:124-129` |
| BUG-224 | `Exam::start()/openForEntry()` 两条 UPDATE 无事务，口令与 `stuscore.stu_pwd` 快照可能不一致 | `Exam.php:165-181/263-275` |
| BUG-225 | `Setting::putMany()` 校验在循环内抛错 → 部分写入且 `flush()` 未执行，响应 400 但 DB 已改 | `Setting.php:248-268/304-331` |
| BUG-226 | 内联 `onerror` 属性被 CSP 拦截（与已修的「内联脚本」同因）→ 图片失败占位永不显示，且刷 CSP 违规日志 | `views/admin/quiz.js:234/387`、`views/student/exercise.js:146`、`views/student/mock.js:210` |
| BUG-227 | 列表删除后分页不回退，停在越界页显示空表且分页器被隐藏 | `ui/crud.js:74-77/123-130` |
| BUG-228 | `openDrawer` 的焦点陷阱在 X/ESC/点遮罩关闭时未释放（`openModal` 已修，抽屉漏改） | `ui/components.js:415-429` |

## 五、修复计划（第三轮）

### 批次 A —— 打通全部写入路径（P0，最高优先）
1. **BUG-201** `openModal` 中心化关联：`bodyEl` 内唯一 `<form>` 自动赋 id，footer 的
   `button[type=submit]` 补 `form` 属性 → 一处修复覆盖所有「form + footer」调用方。
2. **BUG-202** `quiz.js` 表单容器 `div.form-grid` → `form.form-grid`。
3. **BUG-203** 新增 `submit-one` 端点与 `InvigilationService::submitOne()`；前端按人收卷改指向它。

### 批次 B —— 前后端字段与路由对齐
4. **BUG-207/208** 表单字段更名 `password`；`teainfo` 无对应列，移除 `tea_sex/tea_phone/tea_info` 输入与列表列。
5. **BUG-205** 考生改密字段改为 `old_pwd/new_pwd/new_pwd2`（补传二次确认）。
6. **BUG-204** `adminApi`/`teacherApi` 补 `submitAll`，与按人 `submit` 语义分离。
7. **BUG-206** 管理端监考改用 `columns` 内的操作列（参照教师端），使行内操作可用。
8. **BUG-209** 教师端监考跳转路径改为 `/monitor`。

### 批次 C —— 后端逻辑与数据一致
9. **BUG-210** `Admin\ExamController::update()` 不再覆盖 `exam_status`。
10. **BUG-211** 两处 `testing` 查询统一排除 `MOCK_CLASS`（复用 `Exam::hasOngoingFormalExam()` 口径）。
11. **BUG-212/223** 考场登录校验班级归属（`FIND_IN_SET`，与 `pendingForStudent` 同口径）+ `sess_regenerate()`。
12. **BUG-214** 注册改为单条带 `id` 的 INSERT，并把 id 收紧为 INT 范围内纯数字。
13. **BUG-213** 备份前校验已结束 + 事务化 + 锁行。
14. **BUG-215** `page` 加上限并强制 `(int) $offset`。
15. **BUG-216** `/api/public/site` 改为站点展示字段白名单。
16. **BUG-217** `writePoint()` 先查显式声明，安全方法不再短路。
17. **BUG-218** 各删除路径补 `stuscorebak`。
18. **BUG-219** `LIKE` 关键字统一 `addcslashes($kw, '%_\\')`。
19. **BUG-222** `AuthSession::login()` 不再清理 `exam_session`。
20. **BUG-221** 模拟考试 `savePaper/submitPaper` 加已交卷守卫，`gradeMock` 加幂等门禁。
21. **BUG-225** `Setting::putMany()` 先全量校验再落库 + 异常路径 `flush()`。

### 批次 D —— 前端细节
22. **BUG-226** 内联 `onerror` → `addEventListener('error')`。
23. **BUG-227** `crud.js` 删除/保存后回退页码。
24. **BUG-228** `openDrawer` 焦点陷阱在内部 `close()` 释放。
25. **BUG-220** 列表统计改批量查询（`MonitorController`/`ExamController`/`SubjectController` 优先）。
26. `student/center.js` 单位/班级选项 `value` 统一为 ID（避免保存时清空）。
27. `admin/exam.js` 题库不足明细与出题警告不再渲染成 `[object Object]`。

### 验证方式
- 每个修复配套断言；新增 `temp/domtest/verify_round3.mjs`（真实 Chromium，点击保存并核对落库）。
- 全量 `bash test/run_all.sh` 必须保持全绿（基线 321 PASS）。
- `temp/domtest/verify_final.mjs`（22 项）与 `temp/domtest/prove_modal_save.mjs` 必须由 FAIL 转 PASS。

---

# 第三轮修复实施结果 + 第四轮全面巡检（2026-09-16）

## 一、第三轮实施结果

批次 A/B/C/D 共 27 项**全部落地**（已用 `temp/domtest/verify_fix_markers.mjs` 逐项核对代码标记，40 项标记全部命中）：

| 批次 | 内容 | 落地 |
|---|---|---|
| A | `openModal` 表单关联（BUG-201）、题库表单改真 `<form>`（BUG-202）、按人交卷 `submit-one` 端点与前端（BUG-203、BUG-206） | ✅ |
| B | 表单字段名对齐（BUG-205/207/208）、`submitAll` 补齐（BUG-204）、教师监考跳转（BUG-209） | ✅ |
| C | 考试状态不再被编辑打回（BUG-210）、模拟考试排除（BUG-211）、考场班级准入 + 换发会话（BUG-212/223）、注册单条 INSERT（BUG-214）、备份守卫（BUG-213）、页码上限（BUG-215）、站点白名单（BUG-216）、写权限点优先（BUG-217）、`stuscorebak` 级联（BUG-218）、`LIKE` 转义（BUG-219）、考场会话不被清理（BUG-222）、模拟考试防刷分（BUG-221）、设置先校验后落库（BUG-225） | ✅ |
| D | 配图 `onerror` 去内联（BUG-226）、删除后回退页码（BUG-227）、抽屉焦点陷阱释放（BUG-228）、单位/班级选项用 ID、缺题明细渲染 | ✅ |

**未实施（如实记录）**：BUG-220（列表接口 N+1 与 `limit 200` 硬编码）—— 纯性能项，
改动面覆盖 5 个控制器的列表查询，本轮未做；建议独立一轮专项处理。

## 二、第四轮发现（浏览器全站巡检暴露）

### BUG-229　【P0】「考生管理」编辑弹窗的保存按钮点了没反应（`form.id` 被同名控件遮蔽）

- **位置**：`public/assets/js/ui/components.js`（`openModal`）、`public/assets/js/views/admin/student.js:118`
- **现象**：后台「考生管理 → 编辑 → 保存」完全无反应：不报错、不关弹窗、数据不变。
  第三轮的 BUG-201 修复对其他页面有效，**唯独考生管理仍失效**。
- **根因**：考生表单里有一个 `name="id"` 的控件（准考证号）。`HTMLFormElement` 支持
  **具名访问**——表单内名为 `id` 的控件会成为 form 自身的属性，于是 `form.id` 返回的是
  那个 `<input>` 而不是字符串 id：

  ```js
  if (!form.id) form.id = uid('mf');            // form.id 是元素（真值）→ id 永远补不上
  btn.setAttribute('form', form.id);            // → form="[object HTMLInputElement]"
  ```

  实测：`save.form === null`、`save.getAttribute('form') === '[object HTMLInputElement]'`。
- **修复**：改用 `form.getAttribute('id')` / `form.setAttribute('id', fid)`，并把解析出的
  `fid` 赋给按钮的 `form` 属性；不受控件名影响。
- **实证**：`temp/domtest/debug_modal_owner.mjs` 修复前 `owner=null`、修复后
  `C6 保存按钮 form owner 指向表单` + 真点保存后弹窗关闭（提交确实触发）。

### BUG-230　【P1】未登录时「公开页面」全部被弹到登录页，考生无法自助注册

- **位置**：`public/assets/js/core/http.js:97`
- **现象**：未登录访问门户 `/`、`/#/hero`、`/#/register`，hash 一律被改写为 `#/login`。
  表现即「注册按钮点进去还是登录页」「首页打不开」。
- **根因**：`request()` 对**任何** 401（除 `/login`）都 `emit('unauthorized')`，而各端
  `installErrorHandlers` 的回调会跳登录页。问题是**引导阶段的会话探测** `GET /api/student/me`
  在未登录时**必然** 401 —— 这正是 `bootstrapSession` 用来判断登录态的正常结果，
  却把公开页面直接弹走。后台端还因此多弹一次「登录状态已失效」并重复渲染登录页。
- **修复**：新增 `NO_AUTH_REDIRECT = [/\/login$/i, /\/me$/i]`，会话探测与登录接口的 401
  不再触发全局跳转。各端 `boot()` 已有显式的未登录分支（渲染自己的登录页），
  门户侧由 `studentSession.require()` 守卫受保护视图，因此不依赖这个全局跳转。
- **实证**：`C8` 探针 —— `/#/register` 渲染出 7 个控件的注册表单且 hash 保持 `#/register`；
  `/` 渲染落地页；`/#/exercise` 仍正确走守卫跳 `#/login?redirect=%2Fexercise`。
- **补充（第五轮更正）**：本条当时把 `/me` 的 401 记为「预期内、由前端兜底」。第五轮查明
  **真正的根因在后端**：鉴权中间件按前缀把 `/api/{student,teacher,admin}/me` 判为受保护接口，
  各 `AuthController::me()` 里「未登录返回 200 + `logged_in:false`」的分支其实是**死代码**。
  因此这里的 `NO_AUTH_REDIRECT` 只是**兜底**而非根治，详见 **BUG-232**。

### BUG-231　【P1】「单位 / 班级」引用名称与 ID 两套写法并存 → 考生匹配不到考试

- **位置**：`app/Models/Grade.php`、`app/Models/SchoolClass.php`、`StudentAuthController::register`、
  `StudentController::saveInfo`、`Admin/StudentController::save/update/import`、
  `Admin/ExamController::resolveClasses`、`TeacherExamController::resolveClasses`、
  `views/student/login.js`、`views/student/center.js`
- **现象**：库中 `stuinfo.grade_id/class_id` 与 `examinfo.stu_class` 混着写名称和 ID。
- **根因**：旧系统（`E:/develop/csip-php`）的表单提交的是**名称**
  （`Views/admin/students/form.php:12/17`、`Views/front/register.php:37/46`、
  `Views/admin/exams/form.php:52` 的 option `value` 都是 `grade_name`/`class_name`），
  而重写后的逻辑一律按 **ID** 匹配：

  - 排卷 / 名单：`Student::byClassIds()` → `class_id IN (ids)`
  - 待考列表：`Exam::pendingForStudent()` → `FIND_IN_SET(class_id, exam.stu_class)`
  - 考场准入：`Exam::isStudentEligible()`

  而**考生端**的注册页与资料页仍按「名称优先」提交（`value: g.grade_name || g.id`），
  于是一次「保存个人资料」就足以让该考生**再也看不到任何考试**；反之旧考试（存名称）
  也匹配不到新考生（存 ID）。存量数据已证实：`165165/165166` 存名称、`999999` 存 ID。
- **修复**：
  1. 模型新增归一：`Grade::resolveId()`、`SchoolClass::resolveId()/resolveIds()`（请求内缓存映射表）；
  2. **全部 5 条写入路径**接入归一（考生注册 / 考生资料 / 后台考生增改与 CSV 导入 /
     管理端与教师端考试保存的参考班级）；
  3. 前端考生端两处下拉改为提交 ID（`value: String(g.id)`），与后台一致；
     资料页回填时先按 ID 命中、再按名称命中，都认不出则补一条「历史值」选项，避免打开即清空；
  4. **存量数据迁移**：`temp/domtest/migrate_refs.php`（干跑 → 备份 → 事务执行），
     归一 `stuinfo` 2 行、`examinfo` 28 行；备份 `temp/backup/before_ref_migration_*.sql`。
- **实证**：`C2/C3/C5` 探针 —— 资料页与注册页下拉 value 均为 `"1"`；`/api/student/exams` 返回非空。

## 三、第四轮验证结果

| 验证 | 结果 |
|---|---|
| 后端回归 `bash test/run_all.sh` | **321 PASS / 0 FAIL**（与基线一致，零回归） |
| 浏览器全站巡检 `temp/domtest/browser_sweep_v6.mjs` | **103 项 PASS / 0 FAIL** |
| 公开页（9 个入口，含 `#/register`、`#/hero`、`/exam`、守卫跳转） | 全部渲染正确 |
| 管理端 16 路由 / 教师端 3 / 考生端 6 | 全部渲染，关键文案与统计卡数量均匹配 |
| CRUD 弹窗 | 7 个模块「编辑 → 保存按钮 form owner」全部有效；Esc 可关 |
| 定点回归 C1–C8 | 监考跳转、班级归一、教师监考空壳、注册表单、公开页可达、考生保存全部通过 |

**遗留**：`BUG-220`（N+1 查询与 `limit 200` 硬编码）未实施；第一轮记录的限流、密码策略
两项加固仍待产品决策。以上均不影响功能正确性。

---

# 第五轮：用户报障驱动的定点排查（2026-09-16）

**报障原文**：进入首页时控制台有一条报错 —— `GET http://127.0.0.1:8099/api/student/me 401 (Unauthorized)`
（浏览器把失败的 `fetch` 归属到 `http.js:76`，即 `res = await fetch(url, init)` 那一行）。

顺着这条线索查下去，发现**表象背后是两处后端契约缺陷**，其中一处的实际危害远超用户看到的「一行红字」。

## 一、第五轮发现与修复

### BUG-232　【P1】鉴权中间件把「会话探测接口」当成受保护接口 → 公开页面首屏必现 401（BUG-230 的真根因）

- **位置**：`app/Middlewares/SessionAuthMiddleware.php`（`IDENTITY_RULES`）
- **现象**：未登录访问门户首页 / 注册页 / 英雄页，控制台必有一条 `/api/student/me` 的 401；
  后台端、教师端同理（`/api/admin/me`、`/api/teacher/me`）。
- **根因**：`IDENTITY_RULES` 里 `['/api/student/', 'student']` 是**前缀**匹配，
  而 `/api/student/me` 天然落在该前缀之下 → 请求在中间件层就被
  `deny(401, '登录已过期，请重新登录')` 拦掉，**控制器根本没被执行**：

  ```php
  // StudentAuthController::me() —— 这段编写正确的分支实际上是死代码
  $sess = AuthSession::get(AuthSession::STUDENT);
  if ($sess === null) {
      return $this->ok(['logged_in' => false, 'csrf_token' => AuthSession::csrfToken()]);
  }
  ```

  三端 `me()` 都实现了「未登录如实回答 200 + `logged_in:false`」，中间件却把它们判成鉴权失败。
  连带后果：
  1. 公开页面一打开就有红色 401，观感等同故障；
  2. 前端 `bootstrapSession()` 里 `setCsrfToken(data.csrf_token)` 永远取不到值
     —— 恢复逻辑只能靠抛异常走 `catch` 分支，匿名访客拿不到 CSRF 令牌。
- **修复**：新增 `EXACT_RULES`（**精确路径**匹配，先于前缀规则判定），把
  `/api/student/me`、`/api/teacher/me`、`/api/admin/me` 声明为 `public`。
  刻意不写成又一条前缀规则：否则将来新增 `/api/student/members` 之类的路径会被
  `/api/student/me` 前缀误放行。
- **安全性**：三者均为只读 GET，只返回「调用者自己的会话」；未登录时返回 `logged_in:false`，
  不泄露任何数据；`csrf_token` 与 `/api/health` 同源下发，无新增暴露面。
- **实证**：

  | 探针 | 修复前 | 修复后 |
  |---|---|---|
  | `GET /api/student/me`（匿名） | 401 | **200** `{logged_in:false, csrf_token:…}` |
  | `GET /api/teacher/me` / `/api/admin/me` | 401 | **200** |
  | `GET /api/student/info`（同前缀受保护） | 401 | **401**（未被误放行） |

### BUG-233　【P0】考场入口页 `/exam` 自激请求风暴：6 秒发出 997 次 `/api/exam/status`

- **位置**：`public/assets/js/apps/exam.js`、`public/assets/js/core/http.js`、
  `public/assets/js/views/student/exam.js`、`app/Controllers/ExamController.php`、`core/router.js`
- **现象**：匿名打开考场入口页 `/exam`（**这是每位考生的必经入口**），页面陷入死循环请求：
  实测 6 秒内对 `/api/exam/status` 发起 **997 次**请求，间隔从 26ms 退化到 5ms，
  `networkidle` 永不达成、页面卡死、服务端日志被 401 刷屏。
- **根因**：三方约定互相踩踏形成的自激环：

  1. `ExamLoginView` 挂载时会探测一次 `/api/exam/status`（用于「已入场则直接回考场」）；
  2. 无考场会话时该接口**抛 401**；
  3. `http.js` 对 401 一律 `emit('unauthorized')`（白名单里只有 `/login`、`/me`）；
  4. `apps/exam.js` 的 `onUnauthorized` 是 `router.navigate('/')`；
  5. `core/router.js` 的 `navigate()` 在**目标等于当前 hash 时不早退**，而是直接 `handle()`
     重新渲染（本意是支持「原地刷新」）：
     ```js
     } else if (location.hash.replace(/^#/, '') === target) {
       handle();      // ← 已在 #/ 时，这行会重新挂载入口视图
     }
     ```
  6. 重渲染 → 又探测 → 又 401 → 又跳转 → 循环。

  服务端日志显示该风暴在报障前一日（09-16 07:44:43）就已发生过数百次，**与本次修复无关，属既存缺陷**。
- **修复（三层，缺一不可）**：
  1. **后端语义归位**：`/api/exam/status` 是**状态查询**，与 `/me` 同构，
     未入场应如实回答。`ExamController::status()` 改为在无考场会话时返回
     `200 + { phase:null, exam:null, csrf_token }`（新增 `examSessionOrNull()`，
     原 `examSession()` 仍抛 401 供取卷 / 交卷等真正受保护的接口使用）；
     同时在 `EXACT_RULES` 放行 `/api/exam/status`。
  2. **前端白名单**：`NO_AUTH_REDIRECT` 增加 `^/exam/status$`，切断自激环起点。
  3. **幂等守卫**：`apps/exam.js` 的两个处理器改为 `if (!atExamRoot()) router.navigate('/')`
     —— 已站在入口页时不再触发重渲染，作为第二道防线。
- **配套调整**：等待室轮询原先依赖「401 全局跳转」负责会话失效退场；
  该路径取消后，改由视图自己承担（`if (!r || !r.phase) { stopPoll(); router.navigate('/'); }`）。
  已核对 `phaseOf()` 的返回值域（`waiting`/`answering`/`submitted`/`closed`，**不存在空值**），
  因此该分支只会在「会话真的没了」时触发，不会误踢正在等待开考的考生。
- **实证**：`temp/domtest/diag_exam_poll.mjs` 抓取调用栈定位到 `views/student/exam.js:85`；
  `temp/domtest/verify_exam_flow.mjs` 真实浏览器走完
  「凭口令入场 → 等待室（12 秒轮询 4 次，有界）→ 会话失效 → 回落入口并停止轮询」全程 12 项通过。

## 二、第五轮验证结果

| 验证 | 结果 |
|---|---|
| 后端回归（8 个用例文件，逐文件独立进程） | **339 PASS / 0 FAIL / 0 SKIP**（较上轮 +18，全部为新增契约断言） |
| 匿名首屏巡检 `temp/domtest/verify_me_401.mjs` | **64 PASS / 0 FAIL**（10 个入口：零 4xx/5xx、零 console 错误、探测次数有界） |
| 考场端到端 `temp/domtest/verify_exam_flow.mjs` | **12 PASS / 0 FAIL** |
| 受保护接口反向校验 | `/api/student/info`、`/api/teacher/monitor`、`/api/admin/dashboard`、`/api/admin/students` 仍为 401 |
| 登录态恢复 | 登录后刷新页面凭 cookie 正常恢复（`/me` → `logged_in:true`，外壳直接渲染、不回落登录表单） |
| 静态检查 `temp/check_frontend.mjs` | 40 文件，0 语法错误、0 未解析导入 |
| 路由审计 `temp/domtest/api_route_audit.mjs` | 前端 145 个调用 vs 后端 156 条路由，无缺失 |

**本轮新增回归防线**：`test/cases/frontend_contract_test.php` 第五节锁定
「未登录时三端 `me` 与考场 `status` 必须 200 且字段正确」+「同前缀受保护接口必须仍 401」，
防止精确规则被改回前缀匹配、或反向被误写成前缀规则。

**遗留**：`BUG-220`（N+1 查询与 `limit 200` 硬编码）仍未实施；第一轮记录的限流、密码策略
两项加固仍待产品决策。

---

# 第六轮：品牌标识统一（LOGO）+ 门户页布局缺陷（2026-09-16）

本轮由一条需求驱动：「把 `logo.png` 作为本项目的 LOGO，其主形象和文字的颜色
可以根据背景色进行变化」。落地过程中顺带查出并修复了一处既有的门户页布局缺陷。

## 一、LOGO 统一（需求实现）

**原状**：全站没有真正的品牌图形，各端用「方块 + 单字」的字号牌充当品牌位
（管理端「管」、教师端「师」、考生端「考」、练习端「练」），门户页用 `graduation-cap`
线性图标；favicon 是一枚紫色方块 + 学士帽。四处视觉互不相干。

**做法**：把源图 `public/uploads/logo.png`（256×333，DEEPBLUE 锚形标）
**矢量化**为可无损缩放、且颜色可由 CSS 驱动的标识。

1. **矢量化**（`temp/logo/vectorize.py`）
   - 不用 potrace：本机 `potracer` 端口不回传内孔（`Curve.children` 恒为 `None`），
     青绿环与字母 `D/P/B` 的镂空会整体丢失。改为自实现 **crack-following 轮廓追踪**
     （沿像素格边界走，内孔天然是独立闭合环，配 `fill-rule="evenodd"` 还原）
     \+ **RDP 简化**（容差 1.2 格 = 0.3 源像素）。
   - 关键细节：alpha 先做 4× LANCZOS 上采样再二值化，轮廓落在亚像素位置，
     避免低分辨率源图带来的方块锯齿；青绿/墨色交界处的抗锯齿像素会污染出一圈
     「墨色细毛刺」，用「青绿外扩 2px 后扣除 + 开运算」清掉。
   - 结果：墨色 13 条轮廓（锚体 1 + 8 个字母 + 4 个内孔）、青绿 3 条（外圈 + 内孔 + 箭头）。
   - **保真度实测**：把生成的 SVG 以 1:1 渲染回 256×333 与原图逐像素比对，
     覆盖率 IoU = **0.9507**，差异仅分布在抗锯齿边界（矢量侧多出约 1px 的软化边）。
   - 产出 `logo.svg`（完整标识，含 DEEPBLUE 文字）、`logo-mark.svg`（仅环 + 锚）、
     `favicon.svg`（深蓝圆角块 + 白锚 + 青绿环，标签栏亮暗底都可辨）。

2. **颜色如何「随背景变化」**（核心设计）
   - 内联 SVG 而非 `<img src>`：`<img>` 加载的外部 SVG 是**独立文档**，
     拿不到宿主的 `currentColor` 与 CSS 变量，颜色只能写死。
   - `core/logo.js` 用 `createElementNS` 把 SVG 直接构建进当前文档，两条路径分别取
     `var(--logo-ink, currentColor)`（锚体 + DEEPBLUE 文字）与
     `var(--logo-accent, ...)`（青绿环）。
   - 于是 `tokens.css` 按主题给出默认值即可：亮主题墨色 `#133464`、暗主题提到 `#cfe0ff`；
     `var(..., currentColor)` 的兜底还保证「扔进任意深色卡片也自动变浅」。
   - 实测取色：亮主题 `rgb(19, 52, 100)`（亮度 48）、暗主题 `rgb(207, 224, 255)`（亮度 223）。

3. **落位**（7 处）
   - 启动闪屏：`PageController::bootLogo()` **读取矢量资产内联进 HTML**——
     闪屏要在 JS 执行前画出来，且内联才能吃到主题变量；读文件而非抄一份路径数据到
     PHP，保证几何只有一处定义。
   - 侧边栏（`ui/shell.js`）、通用登录页（`ui/login.js`）、考生注册 / 考场入口
     （`views/student/login.js`、`views/student/exam.js`）、门户导航与页脚
     （`views/portal.js` ×3）。
   - 退役旧的 `.brand-mark` 字号牌：删除其 CSS 与全部调用，并清理随之失效的
     `brandMark`、`accent` 配置项（6 个调用点）。

## 二、BUG-234　【P1】门户页导航纵向溢出、统计卡塌成单列（既有缺陷）

- **现象**：门户首页 `/` 的导航条内容竖排并溢出 64px 高度压住 hero 区；
  「题库总量 / 已开考试 / 注册考生 / 题型覆盖」四张统计卡挤成一列；
  页脚品牌行与联系方式不并排。
- **根因**：**JS 用的类名与 CSS 里定义的类名不是同一批**，且没有任何机制会报错——
  元素只是退化成 `display: block` 的默认布局：

  | JS 中的类名 | CSS 是否有规则 | 后果 |
  |---|---|---|
  | `.portal-nav-inner` | ✗ 无 | 导航内容竖排、溢出 |
  | `.portal-nav-brand` | ✗ 无 | LOGO 与站名上下堆叠 |
  | `.portal-nav-links` | 仅 `.portal-nav-links a.is-active` | 链接竖排 |
  | `.portal-stats-inner` | ✗ 无 | 统计卡不进栅格 → 单列 |
  | `.portal-footer-inner` / `.portal-footer-brand` | ✗ 无 | 页脚不并排 |
  | `.hero-sub` | ✗（CSS 里叫 `.hero-lead`） | 引导语失去排版 |
  | `.muted` | ✗（全站未定义） | 弱化文字色失效 |

  更隐蔽的是 `.portal-stats`：CSS 把栅格写在了外层槽位 div 上，而内层容器没有参与栅格，
  于是 `grid-template-columns` 算出 `1214px 0px 0px 0px 0px 0px`——看着「有栅格」，
  实际是单列。
- **为什么此前没被抓到**：既有巡检的断言是「文本存在 + 无 JS 报错」，
  而这类缺陷既不报错、文本也都在。**静态检查、后端测试、jsdom 冒烟全都抓不到**。
- **修复**：补齐容器布局（导航内层 flex 行 + 品牌位间距 + 链接靠右、统计区内层栅格、
  页脚两栏）；把 CSS 里那条从未命中的 `.portal-nav .nav-links` 改名为
  `.portal-nav .portal-nav-links` 使其真正生效；`.hero-sub` 对齐为 `.hero-lead`；
  `.muted` 按门户页局部定义（避免波及其他端的既有观感）。
- **顺带修掉一处移动端可达性问题**：原 `@media (max-width:820px)` 里
  `.portal-nav .nav-links { display:none }` 会把「首页 / 成绩榜 / 在线练习」三个入口
  在手机上彻底隐藏（无汉堡菜单兜底）。改为横向滚动，入口保持可达。
- **新增巡检手段**：`temp/domtest/css_coverage_audit.mjs` —— 在真实浏览器里收集
  DOM 用到的全部类名，再枚举样式表里出现过的类名求差集，直接列出「用了但没有规则」
  的类名。全部 9 个入口现已归零。

## 三、第六轮验证结果

| 验证 | 结果 |
|---|---|
| 后端回归（8 个用例文件） | **372 PASS / 0 FAIL / 0 SKIP**（较上轮 +33，全部为新增 LOGO 契约断言） |
| 全站浏览器巡检 `temp/domtest/browser_sweep_v6.mjs` | **103 PASS / 0 FAIL** |
| 匿名首屏巡检 `temp/domtest/verify_me_401.mjs` | **64 PASS / 0 FAIL** |
| LOGO 专项 `temp/logo/verify.mjs` | **22 PASS / 0 FAIL**（13 个品牌位 × 亮暗双主题的取色与尺寸、闪屏内联、导航/页脚布局） |
| CSS 覆盖率审计 `temp/domtest/css_coverage_audit.mjs` | 9 个入口共 **0** 个「无规则类名」 |
| 静态检查 `temp/check_frontend.mjs` | 41 文件，0 语法错误、0 未解析导入 |
| 图标检查 `temp/check_icons.py` | 88 图标，无非法引用 |
| 路由审计 `temp/domtest/api_route_audit.mjs` | 前端 145 个调用 vs 后端 156 条路由，无缺失 |

**本轮新增回归防线**：`test/cases/frontend_contract_test.php` 第六节锁定
「三份 LOGO 资产存在且为矢量」「墨色必须走 `var(--logo-ink, currentColor)` 且暗色主题有覆盖」
「无视图再使用旧的 `.brand-mark`」「六端闪屏均在服务端内联 LOGO」。

**遗留**：`BUG-220`（N+1 查询与 `limit 200` 硬编码）仍未实施；第一轮记录的限流、密码策略
两项加固仍待产品决策。另：`login.css` 中 `.portal-stat`（单数）与其子元素
`.portal-stat .value/.label` 是一批从未命中的死规则（JS 用的是 `.stat-card/.stat-value/.stat-label`），
本轮未清理以控制改动面，建议后续单独整理。

# 第七轮：产品名统一为「深蓝网上考试系统」+ 两处名称相关缺陷（2026-09-16）

## 一、需求

> 项目名字改为深蓝网上考试系统。

## 二、做法：产品名收敛到一处

此前产品名散落在 6 个前端脚本与 3 处 PHP 里（「在线考试系统」/「网上理论考核系统」两种写法混用），
改一次名要满仓库找，且极易只改一半。本轮把它收敛成单一来源：

- **服务端**：`config/config.php` 的 `app.name` = `深蓝网上考试系统`。
  `PageController` 渲染外壳时把它注入 `<html data-app-name="…">`。
  **为什么不注入内联 `<script>window.APP_NAME=…`**：站点 CSP 是 `script-src 'self'`，
  内联脚本会被浏览器直接拦掉（早前修 CSP 相关缺陷时踩过同样的坑），所以走 `data-*` 属性。
- **前端**：新增 `public/assets/js/core/brand.js`（`APP_NAME` 常量 + `appName()`），
  读取优先级 `data-app-name` → `window.__APP_NAME__`（历史手工覆盖钩子）→ 常量。
  改由它取名的位置：`core/router.js`（`document.title` 后缀）、`apps/portal.js`、
  `views/portal.js`（导航品牌位 / 页脚 / 英雄区标题）、`ui/login.js`（页脚）。
- **门户标题**：`PageController::PAGES` 里 portal 的标题改为 `null`，语义是「用产品名」——
  门户是全站入口，标题就该是产品名本身；其余各端保留功能名（考生中心 / 教师工作台 / 考试管理后台…）。
- **API 文档页**：`DocController` 改用占位符替换（保留 nowdoc，避免以后往那段 HTML 里加 `$` 变量被意外求值）。
- 后台「系统设置 → 站点标题」是**另一个**东西（可选的展示标题，落库 `siteconfig.site_title`），
  留空则回落产品名，字段加了 hint 说明。

> 已核实：`siteconfig` 表当前**没有** `site_title` 行，因此线上显示的名字完全由 `app.name` 决定。

## 三、BUG-235　【P2】门户页脚永远显示不出后台配置的「站点标题」

- **现象**：后台「系统设置 → 站点标题」填了也没用，门户页脚永远是一句硬编码兜底文案。
- **根因**：`/api/public/site` 把 siteconfig 的键**原样**下发（`site_title`），
  而门户页脚读的是 `cfg.title` —— 这个键从来不存在，于是永远走 `||` 兜底。
  （`copyright` / `address` / `phone` 三个键名对得上，只有标题这一项坏掉，极不容易发现。）
- **修复**：改读 `cfg.site_title`，未配置时回落 `appName()`。

## 四、BUG-236　【P1，本轮自伤后修复】`?:` 读未定义键触发告警 → 帮助接口 500

- **背景**：本轮把 `HomeController::help()` 的标题兜底从 `?? '旧名'` 改成
  `$site['site_title'] ?: config('app.name')`，想表达「空串也回落产品名」。
- **后果**：`siteconfig` 里没有 `site_title` 行 → `$site['site_title']` 触发
  **未定义数组键告警**；本项目的错误处理会把告警升级成异常 → `/api/public/help` 直接 500。
  契约测试里 `blocks 为数组` 立刻变红（这正是契约测试的价值）。
- **修复**：先 `?? ''` 取值，再判空串：

  ```php
  $siteTitle = trim((string) ($site['site_title'] ?? ''));
  'title' => $siteTitle !== '' ? $siteTitle : (string) config('app.name', '…'),
  ```

- **教训**：本项目**不要用 `?:` 直接读可能不存在的数组键**，必须先 `??` 兜住 ——
  这里的告警等于异常。

## 五、验证结果

| 验证 | 结果 |
|---|---|
| 后端回归（8 个用例文件） | **385 PASS / 0 FAIL / 0 SKIP**（较上轮 +13，全部为新增「产品名单一来源」契约断言） |
| 全站浏览器巡检 `temp/domtest/browser_sweep_v6.mjs` | **103 PASS / 0 FAIL** |
| 匿名首屏巡检 `temp/domtest/verify_me_401.mjs` | **64 PASS / 0 FAIL** |
| 静态检查 `temp/check_frontend.mjs` | 42 文件，0 语法错误、0 未解析导入 |
| 实测取名 `temp/domtest/shot_brand.mjs` | 门户亮/暗两版 `title` / 导航 / 英雄区 / 页脚均为「深蓝网上考试系统」；管理端页脚「© 2026 深蓝网上考试系统 · fastapi-php」，各端标题仍为功能名 |

**本轮新增回归防线**：`test/cases/frontend_contract_test.php` 第七节锁定
「`config('app.name')` 就是当前产品名」「六端外壳都注入 `data-app-name`」
「门户 `<title>` 即产品名」「`core/brand.js` 为前端唯一读取口」

---

# 第八轮：管理后台会话失效时的 401 级联崩溃（2026-09-16）

## 一、BUG-237　【P1】后台 token 过期后控制台报「Cannot read properties of null (reading 'setContent')」

- **现象**：管理员登录后台后放着不动，过一段时间（会话 token 过期）切到「考试管理」等页面，
  控制台连报 `GET /api/admin/classes?per_page=200 401 (Unauthorized)` 等，随后抛
  `TypeError: Cannot read properties of null (reading 'setContent')`，
  来自 `guardView → view → ExamView → loadRefs`。
- **根因**：`exam.js` 的 `loadRefs()` 用 `Promise.all` 并发请求 `subjects / exam-categories / classes`；
  三者同时 401。`http.js` 按设计 `emit('unauthorized')`，`admin.js` 的 `onUnauthorized` 正确执行了
  「`session=null` + `shell.destroy(); shell=null` + 渲染登录页」。**但**该回调在
  `guardView` 的 `await view(...)` **执行途中同步触发**，shell 已被置空；等 `await` 返回后，
  `guardView` 的 `catch` 仍执行 `shell.setContent(...)` → 触碰已销毁的 null 外壳而崩溃。
  同时 3 个并发 401 会让 `onUnauthorized` 重入 3 次，叠出 3 张登录页。
- **修复**（3 处，纯前端）：
  1. `apps/admin.js` —— `onUnauthorized` 加**幂等保护**（`authInvalidated` 标志），并发 401 只处理一次；
     重新登录后 `startShell()` 复位该标志。
  2. `apps/admin.js` —— `guardView` 全程对 `shell` 做空值守卫：渲染前、无权限分支、渲染后、catch 内
     一旦 `!shell` 立即交还控制权，不再触碰已销毁外壳（登录页已被 `onUnauthorized` 接管）。
  3. `views/admin/exam.js` —— `loadRefs()` 出错时标记 `refCache.loaded = true` 再上抛，
     避免筛选下拉等懒加载路径反复重发 401；初始调用的 `.catch(() => {})` 照常吞掉，视图降级渲染空参考。
- **附带说明**：控制台里那行 `reportAllChanges … startTime` 的报错**不是本项目代码**——
  全仓 grep 不到 `reportAllChanges` / `PerformanceObserver` / 任何埋点注入，且 CSP 为 `script-src 'self'`
  也无法加载外部脚本；该 `VMxxx` 脚本是浏览器扩展（如 web-vitals 类 RUM 扩展）注入的，与本次无关，可忽略。
- **新增回归防线**：`temp/domtest/admin_401_guard.mjs` —— 登录后台 → 拦截三个参考接口返回 401 →
  进入 `#/exams`，断言「无 setContent 崩溃 / 无 pageerror / 登录页出现且仅一次 / 旧外壳已销毁」，**8 PASS / 0 FAIL**。

## 二、验证结果（本轮）

| 验证 | 结果 |
|---|---|
| 401 守卫回归 `temp/domtest/admin_401_guard.mjs` | **8 PASS / 0 FAIL** |
| 全站浏览器巡检 `browser_sweep_v6.mjs`（含 `#/exams` 渲染 + 新建考试浮层） | **103 PASS / 0 FAIL** |
| 后台真实数据渲染 `admin_live_smoke.mjs`（16 视图，含 ExamView） | **16 PASS / 0 FAIL** |
| 后端回归（8 用例文件） | **385 PASS / 0 FAIL / 0 SKIP** |
| 静态检查 `check_frontend.mjs` | 42 文件，0 语法错误、0 未解析导入 |
「前端 JS 里不再残留写死的旧产品名」——最后一条是防「改名只改一半」的关键。

---

# 第九轮：全项目审计（权限点推导缺陷）

审计范围：`app/`（54 个 PHP 文件）、`public/assets/js`（42 个）、`config/` 路由与 RBAC，
并跑通既有全部检查作为基线（后端 385 PASS / 0 FAIL；`browser_sweep_v6` 103/103；
`css_coverage_audit` 9 入口 0 缺失；`verify_me_401` 64/64；`check_frontend` 42 文件 0 错误）。

审计结论：**未发现阻断级（P0）缺陷**。SQL 全部走预处理（仅 `LIMIT/OFFSET` 拼接整数且已钳位、
`DELETE FROM {$table}` 的表名来自硬编码常量、`IN ({$ph})` 为占位符）；XSS 面已封堵
（路径走 `textContent`，全项目无 `html:` 调用）；定时器 4 处全部有 `dispose` 清理；
教师端 `{id}` 接口均有 `assertOwnExam` 归属校验；密码修改校验旧密码并轮换会话。
但发现 **2 处权限点推导缺陷**，其中 1 处会直接让功能 403。

## 一、BUG-238　【P1】监考页「收卷」单个考生恒 403，却允许「全部收卷」

**现象**：管理后台 → 考场监控，考生名单每一行的「收卷」按钮点击后报无权限；
同一页面右上角的「全部收卷」却正常可用。

**根因**：`SessionAuthMiddleware::WRITE_POINTS` 未登记 `POST /api/admin/monitor/submit-one`。
未显式登记的写操作会走 `writePoint()` 的推导分支：`POST` → 取模块名 `monitor` → 拼成
`monitor.add`。而 `config('rbac')` 里 `testAdmin` 只被授予 `monitor.view` + `monitor.control`，
**没有 `monitor.add`** —— 于是 `can()` 判定失败返回 403。

同页「全部收卷」走的是 `POST /api/admin/monitor/submit`，它**有**显式登记
`=> 'monitor.control'`，所以正常。同一个业务逻辑（强制交卷并判分）因为
「单个 / 全员」两个入口的登记情况不同，衍生出两套权限要求。

| 入口 | 路由 | 修复前实际要求 | 应要求 |
|---|---|---|---|
| 单个收卷 | `POST /api/admin/monitor/submit-one` | `monitor.add`（推导） | `monitor.control` |
| 全部收卷 | `POST /api/admin/monitor/submit` | `monitor.control`（显式） | `monitor.control` |

**修复**：在 `WRITE_POINTS` 补 `'POST /api/admin/monitor/submit-one' => 'monitor.control'`。

## 二、BUG-239　【P3】「开放入场」被当成「新增考试」鉴权（潜在缺陷）

`POST /api/admin/exams/{id}/open` 同样漏登记，被推导成 `exam.add`（新增考试）。
它实际只写入考场口令（`exam_pwd`）、状态仍为未开考，属**考试信息更新**而非新建。
当前 `testAdmin` 恰好同时拥有 `exam.add`，所以功能可用、缺陷未暴露；
但凡出现「可查看/开考但不可新建考试」的角色配置，就会误拒。

**修复**：显式登记 `'POST /api/admin/exams/{id}/open' => 'exam.edit'`（对现有角色无行为变化）。

## 三、审计中确认**不是**缺陷的两处（避免误改）

1. **被锁定的考生仍可交卷**：`savePaper()` 会拒绝 `locked` 状态，但 `submitPaper()` 不拦。
   这是**有意为之**——考试到点时客户端倒计时触发的自动交卷走的正是 `submitPaper()`，
   若在此处加 `locked` 拦截，被锁定的考生将永远无法完成超时自动交卷。
2. **教师可修改自己考试的 `exam_tea`**：会把考试过户给他人、自己随即失去访问权，
   不构成提权，且 `assertOwnExam` 已阻止修改他人考试。

## 四、验证结果（本轮）

| 验证 | 结果 |
|---|---|
| 新增 `test/cases/rbac_points_test.php`（反射断言 `writePoint()` 推导结果 + 角色是否具备） | **8 PASS / 0 FAIL** |
| 后端全量（9 个用例文件，较基线 +8） | **393 PASS / 0 FAIL / 0 SKIP** |
| 全站浏览器巡检 `browser_sweep_v6.mjs` | **103 PASS / 0 FAIL** |
| CSS 覆盖率审计 `css_coverage_audit.mjs`（9 入口） | **0** 个无规则类名 |
| 匿名首屏 `verify_me_401.mjs` | **64 PASS / 0 FAIL** |
| 静态检查 `check_frontend.mjs` / `check_icons.py` | 42 文件 0 错误 / 88 图标 OK |
| 专项扫描：SQL 拼接、`?:` 读未定义键、`innerHTML`、定时器泄漏 | 均未发现风险点 |

**本轮新增回归防线**：`rbac_points_test.php` 直接反射调用中间件私有方法 `writePoint()`，
锁定「写操作 → 权限点」的推导结果，并同时断言目标角色确实具备该权限点——
专防「新增控制类接口却忘了登记权限点」这一类静默缺陷。

---

# 第十轮审计（2026-09-17）

**范围**：全项目后端 PHP（`app/` + `core/`）+ 前端零构建 ES Module（`public/assets/js`）。
**方法**：双 Explore 子代理分前端/后端并行只读审计 → 主代理逐条到源码定位根因 → 修复 → 全量回归。
**基线**：第九轮 393 PASS / 0 FAIL、browser_sweep 103/103、verify_me_401 64/64、CSS 覆盖率 0 缺失。

> 本轮在「已修复清单（BUG-220~239）」之外，未发现 P0 活跃安全漏洞与导致核心流程中断的 P1 功能缺陷；
> 发现 2 个确定性后端数据/一致性缺陷（BUG-240/241）、1 个后端口径一致性问题（BUG-249），
> 以及 6 个前端防御性/健壮性缺陷（BUG-242~248）。全部已修复并回归通过。

## 一、缺陷清单

### BUG-240 ｜管理端「编辑考生」未对 grade_id / class_id 做名称→ID 归一化（P1）
- **位置**：`app/Controllers/Admin/StudentController.php:203-208`（`update()`）
- **现象**：管理端编辑考生保存时，`grade_id`/`class_id` 直接 `trim((string)(...))` 写入；
  而同文件 `save()`（175-176）、`import()`（296-297）、`StudentAuthController::register`、`StudentController::saveInfo`
  均调用 `Grade::resolveId()` / `SchoolClass::resolveId()` 把「名称或 ID」归一为 ID。
- **根因**：`update()` 漏写归一化，违反项目约定「归属判定链全部按 ID」
  （`examinfo.stu_class` 存的是班级 ID，`FIND_IN_SET(class_id, stu_class)` / `class_id IN (...)` 按 ID 匹配）。
  一旦编辑表单提交的是班级**名称**（旧数据/下拉回填常见），名称被存进 `class_id`，
  与该考生「待考列表 / 排卷 / 入场资格」三处匹配链整体失配 → 考生登录后看不到任何考试。
- **影响**：数据一致性缺陷，依赖前端绑定方式触发，但属明确健壮性问题（静默失配，无报错）。
- **修复**：`update()` 的 grade_id/class_id 改用 `Grade::resolveId($in['grade_id'] ?? '')` / `SchoolClass::resolveId($in['class_id'] ?? '')`，与 save/import 对齐。
- **回归**：后端全量 393 PASS（含考生相关用例）；`resolveId` 对 ID 原样返回，对名称折算为 ID，行为一致。

### BUG-241 ｜教师端创建考试可指定任意 exam_tea（归属横向伪造，P2）
- **位置**：`app/Controllers/TeacherExamController.php:439`（`collectParams` 读前端 `exam_tea`）+ 原 167-169 行仅当为空才回落本人。
- **现象**：`exam_tea` 是考试归属的唯一依据（所有 `assertOwnExam`/列表均按 `tea_name` 字符串匹配）。
  教师创建考试时若提交他人 `exam_tea`，即可把考试挂到别的教师名下，对方随后能通过 `assertOwnExam` 校验对其进行出题/启动/删除。
- **根因**：服务端未对「教师创建考试的归属」做强约束，信任了前端传入值。
- **影响**：横向归属混淆，违背「教师只能管自己的考试」的 RBAC 意图；实际危害有限（创建本就有权）。
- **修复**：`save()` 忽略前端 `exam_tea`，强制 `data['exam_tea'] = (string)($sess['tea_name'] ?? '')`。管理端（admin）仍可按业务指定任意教师，不受影响。

### BUG-242 ｜教师端「编辑考试」向更新注入 exam_status='exam'（P3 / 潜在）
- **位置**：`app/Controllers/TeacherExamController.php:196`（修复前 `$this->model->update($id, $data + ['exam_status' => Exam::STATUS_EXAM])`）
- **现象**：编辑考试时无条件把状态重置为 `exam`（未开考）。
- **根因分析（必须澄清，避免误判为 P1）**：`collectParams()` 不产出 `exam_status`，故 union 运算符恒追加该键。
  但 `update()` 在 192 行已有「已生成试卷（`hasPaper>0`）则 409 拒绝」守卫，而 `autoStartIfDue()` 仅推进 `paper` 状态——
  在**当前流程**下，编辑能到达 196 行时 `hasPaper` 必为 0、状态必为 `exam`，故该注入是**无操作**，
  不会真正把 `paper`/`testing` 考试打回。属**死代码 / 不正确的状态处理**，一旦将来放宽 `hasPaper` 守卫即会触发「考生卡等待室」。
- **影响**：当前不可复现，但代码意图错误、与管理端 `update` 行为不一致。
- **修复**：删除 ` + ['exam_status' => Exam::STATUS_EXAM]`，状态流转只交由 `open/start/generatePapers/over` 专用接口，与管理端对齐。

### BUG-243 ｜`ui/shell.js` 移动端媒体查询监听器未移除（P2 / 资源泄漏）
- **位置**：`public/assets/js/ui/shell.js:302`（注册匿名 `mobileQuery.addEventListener('change', ...)`），`destroy()`（308）只 `root.remove()` 未 `removeEventListener`。
- **现象**：admin/teacher 端每次登出再登录会重建 shell 并叠加一个新监听器，闭包持续持有已 detach 的 `root`。
- **根因**：handler 未保存引用，销毁时无法移除；`init()` 无「先移除再添加」幂等保护。
- **影响**：长期运行 SPA 内存与事件表随登录次数累积膨胀（功能无碍，但监听器泄漏）。
- **修复**：`init()` 保存 `this._onMqChange` 并在注册前先 `removeEventListener`；`destroy()` 中 `removeEventListener` 后清空引用。

### BUG-244 ｜答题引擎保存失败时硬性拦死翻页（P2 / 可用性）
- **位置**：`public/assets/js/views/exam-runner.js:261`（修复前 `if (state.dirty && !(await saveCurrent())) return;`）
- **现象**：网络抖动致 `saveCurrent` 返回 false（已 `notify.error`）后 `go()` 直接 return，考生无法跳到下一题，即便想先跳过该题。
- **根因**：把「保存失败」等同于「禁止离开」，未提供「丢弃本页改动强制离开」的选项。
- **影响**：临时断网体验硬伤（逻辑上可辩护，但把考生困在当前题）。
- **修复**：保存失败时 `confirmDialog` 询问「仍要离开吗？离开将不会保存本题作答」，确认后 `state.dirty=false` 并继续翻页；取消则留在本题。

### BUG-245 ｜答题引擎缺少周期自动保存，关页/刷新丢当前题（P2 / 数据完整性）
- **位置**：`public/assets/js/views/exam-runner.js`（作答仅在翻页 `go→saveCurrent` 与交卷 `doSubmit→saveCurrent` 时落盘；`dispose` 仅 `stopTimer`；`student/exam.js:105` 的 `beforeunload` 只 `preventDefault`，不保存）。
- **现象**：考生在某一题输入答案后**不翻页**直接关标签/刷新（F5），该题作答丢失（后端仅保留上一题的保存）。
- **根因**：缺乏周期 autosave；`beforeunload` 无法 `await` 异步保存，且项目 CSRF 用请求头令牌（`X-CSRF-Token`），`navigator.sendBeacon` 无法携带自定义请求头，故 beacon 方案被 CSRF 拦截、**不可行**。
- **影响**：正式考试数据完整性，边缘但真实（多数情况已翻页故已保存，最差丢 1 题）。
- **修复**：作答变更后启动 1.2s 防抖自动保存（`scheduleAutosave()`，在填空 `input` 与单选/多选 `click` 三处 dirty 设置点挂载）；`saveCurrent`/`stopTimer` 中清掉待执行定时器，避免对已卸载试卷继续请求。
  该方案用 `fetch`+CSRF 头落盘，覆盖「作答后停留片刻再离开」的真实场景；配合现有 `beforeunload` 警告，数据丢失窗口极小。

### BUG-246 ｜`admin/score.js` 返回裸 Node，未遵循 `{node, dispose}` 契约（P2 / 技术债）
- **位置**：`public/assets/js/views/admin/score.js:254`（修复前 `return root;`）
- **现象**：`ScoreView` 直接返回裸 `Node`，路由走到 `instanceof Node` 分支、**无 disposer**。当前该视图无 `setInterval`/订阅，故无泄漏。
- **根因**：视图工厂未返回 dispose 信封，与 monitor/teacher 等统一约定不一致。
- **影响**：未来维护者在该页加轮询/订阅时易漏清理，重演定时器泄漏。
- **修复**：显式 `return { node: root, dispose: () => {} }`，固化契约、便于扩展。

### BUG-247 ｜`core/dom.js` 的 `el({html})` 是未受控的 innerHTML 注入点（P2 / 潜伏 XSS）
- **位置**：`public/assets/js/core/dom.js:32-35`（`case 'html': node.innerHTML = String(v);`）
- **现象**：`el` 的 `html` 特殊键直接 `node.innerHTML = String(v)`。全量 Grep 确认**当前零调用**（仅 `svg()` 用可信常量），但 API 本身未限制来源，一旦未来维护者写出 `el('div', { html: serverData })` 即形成 DOM XSS。
- **根因**：允许注入未转义 HTML，缺少调用约束/来源校验。
- **影响**：当前无活跃利用，属潜伏风险/契约缺陷。
- **修复**：移除 `el` 的 `html` 特殊键（SVG 图标走独立的 `svg()` 工厂，不受影响），从根上消除脚枪。

### BUG-248 ｜`admin/system.js` 运行参数范围校验在 min/max 为 undefined 时失效（P3 / 防御性）
- **位置**：`public/assets/js/views/admin/system.js:187`（`if (f.min !== null && f.max !== null && ...)`）
- **现象**：若后端 schema 用 `undefined`（而非 `null`）表示无界，则 `undefined !== null` 为 true 进入比较，而 `n < undefined`/`n > undefined` 恒 false，范围校验被完全绕过，任意值可保存。
- **根因**：用 `!== null` 判定「有界」，未覆盖 `undefined`/缺省。
- **影响**：依赖后端 schema 约定；经核实后端 `Setting::fields()` 始终下发 `min/max`（无界为 `null`，见 `Setting.php:228-229`），故**当前不可复现**。列为防御性加固。
- **修复**：改为 `if ((f.min ?? null) !== null && (f.max ?? null) !== null && ...)`，对缺省值同样稳健。

### BUG-249 ｜管理端成绩 CSV 默认携带考场口令，与教师端口径不一致（P2）
- **位置**：`app/Controllers/Admin/ScoreController.php:92`（`csv($examId, true)`）+ `app/Services/InvigilationService.php:153`（默认 `$withPwd = true`）
- **现象**：管理端「成绩导出」CSV 默认写入 `examinfo.exam_pwd` 作为「考场口令」列；教师端导出**不含**口令（`TeacherExamController::exportScores` 自行拼装、无 pwd 列）。
- **根因**：成绩名单 JSON（`StuScore::byExam`）已刻意剥离 `stu_pwd`，CSV 单从 `examinfo` 取口令；把「入场口令」这种凭证落到文件并非必要，且与教师端导出口径不一致。
- **影响**：口令在考试结束后已失效，泄露风险低，但属不必要的凭证落盘 + 两端不一致。
- **修复**：管理端 `exportCsv` 改为 `csv($examId, false)`，与教师端一致、避免把凭证写进导出文件。

## 二、已知但未本轮实施（P3，刻意保留）
- **D3 模拟考试可无限重复创建且无清理**：`ExerciseExamController::start` 每次 `INSERT` 新 `examinfo`（`exam_class='模拟考试'`），无「该考生进行中模拟考试」判重，长期运行 `examinfo` 持续膨胀（不影响正式考试筛选，因各处已排除该类）。功能无碍，列为数据卫生待办，本轮未改以避免影响练习/模拟流程回归。
- **BUG-220 N+1**：部分列表接口循环内逐行查库（如监考名单补考生信息），当前数据量无感知，刻意未改。

## 三、验证结果（本轮）
| 验证 | 结果 |
|---|---|
| 后端全量 `node temp/runtests.mjs` | **393 PASS / 0 FAIL / 0 SKIP** |
| 前端静态 `node temp/check_frontend.mjs` | 42 文件 0 语法错误 / 0 未解析 import |
| 全站浏览器巡检 `browser_sweep_v6.mjs` | **103 PASS / 0 FAIL** |
| CSS 覆盖率审计 `css_coverage_audit.mjs`（9 入口） | **0** 个无规则类名 |
| 匿名首屏 `verify_me_401.mjs` | **64 PASS / 0 FAIL** |
| PHP `php -l` 三个改动文件 | 无语法错误 |

---

# 策略修订：练习/模拟默认关闭 + 考生在考硬约束（2026-09-17）

## 一、需求

1. `exercise_allow_during_exam`、`mock_allow_during_exam` **默认改为关闭**
   （原为开启）。
2. **正在参加正式考试的考生，不能再同时进行在线练习和模拟考试**。

## 二、设计：暂停判定的两层（唯一出处 `Exam::exercisePause()` / `Exam::mockPause()`）

| 层 | 触发条件 | 可否由后台放开 |
|---|---|---|
| **个人层**（硬约束） | `Exam::isStudentInExam()` 为真：存在 `exam_class <> '模拟考试'` 且 `exam_status='testing'` 的考试，且该考生在其 `stuscore.stu_status ∈ {online, locked}` | **否** |
| **全局层** | 存在进行中的正式考试（`Exam::hasOngoingFormalExam()`）且对应开关关闭（默认） | 是 |

关键判断与取舍：

- **个人层必须先判**。若先判全局层，管理员一开启开关，正在答题的考生就会被放行，
  硬约束失效。
- **「在考」以「已入场」为准，不含「已排卷」**。出题（`ExamEngine::generateForClass`）
  会在入场前很久就为全班写 `stuscore.stu_status='waiting'`；若把 `waiting` 算作在考，
  考生在考试开始前的整个备考期都会被禁止练习，而那时试卷对考生尚不可见、
  根本不存在泄露面。同理，已交卷（`over` 前缀）者不算在考。
- **判据必须与 `hasOngoingFormalExam()` 同口径**（都要求 `exam_status='testing'`），
  避免系统里出现第二套「进行中」定义。

## 三、拦截面与一处刻意的例外

暂停期间：练习抽题 / 答案校验、模拟组卷 / 取题 / 保存 / 错题回顾 一律 `403`（错误码 `40308`）。

**例外：模拟交卷（`POST /api/exercise/mock/submit`）不设守卫。** 暂停可能在考生答到
一半时才生效（考试开始 / 被拉进考场），此时若连交卷也拦掉，考生会留下永远无法结束的
`testing` 场次，并白占一次每日额度。交卷本身不披露答案（`gradeMock()` 只回成绩与对错数），
真正的披露点是 `review()`，已单独拦截。

响应新增 `pause_reason`（`self_in_exam` / `exam_ongoing` / `''`），供前端区分文案与指引。

## 四、本轮发现并修复的「测试基础设施」缺陷

- **现象**：全量回归出现 7 项失败，且内容自相矛盾（`SCHEMA` 默认值断言通过，
  但「未配置时应为关闭」的运行时断言之后的暂停判定却为「未暂停」）。
- **根因**：`regression_audit2_test.php` 用 `null` 兼作「尚未改写」的哨兵值，
  而 `$putSetting()` 在「该键原本不存在」时同样返回 `null` ——
  于是 `finally { if ($before !== null) restore(); }` 判定为「尚未改写」而**跳过还原**，
  把显式写入的 `mock_allow_during_exam='1'` 永久留在了开发库的 `siteconfig` 里。
  前一次单独运行留下的残留，使随后全量运行中的「默认策略」用例连锁假失败。
- **修复**：哨兵改用 `false`（与 `?string` 返回值不冲突），并新增
  `$withDefaultPolicy()` 包装器 —— 「默认策略」用例先**显式清空库内覆盖值**再断言，
  跑完原样还原，使结果不再取决于库中残留状态。
- **附带清理**：删除被误写入的 `siteconfig` 行与一条测试残留的模拟考试记录
  （清理前已备份到 `temp/_cleanup_backup.json`，该目录未被 git 跟踪）。
- **同类加固**：`mock_isolation_test.php` 第 5 节同样改为「清空覆盖值 → 断言 → 还原」，
  并在 `finally` 中回收可能产生的模拟记录。

## 五、验证结果（本轮）

| 验证 | 结果 |
|---|---|
| 后端全量 `node temp/runtests.mjs`（连续两轮） | **491 PASS / 0 FAIL / 0 SKIP**（每轮一致，且跑后库内无策略覆盖值残留、无孤儿模拟记录） |
| 前端静态 `node temp/check_frontend.mjs` | 42 文件 0 语法错误 / 0 未解析 import |
| 全站浏览器巡检 `browser_sweep_v6.mjs` | **103 PASS / 0 FAIL** |
| CSS 覆盖率审计 `css_coverage_audit.mjs` | **0** 个无规则类名 |
| 匿名首屏 `verify_me_401.mjs` | **64 PASS / 0 FAIL** |
| 后台设置页 `settings_view_smoke.mjs` | **35 PASS / 0 FAIL** |
| 考试表单 `exam_form_smoke.mjs` | **33 PASS / 0 FAIL** |
| 后台 16 视图 `admin_live_smoke.mjs` | **16 PASS / 0 FAIL** |
| 考场 E2E `verify_exam_flow.mjs` | **12 PASS / 0 FAIL** |
| 组卷页 `mock_setup_smoke.mjs`（本轮改造） | **14 PASS / 0 FAIL** |

> `mock_setup_smoke.mjs` 本轮同步改造：组卷页在「暂停」态下只渲染一条提示、不渲染表单，
> 故脚本先用管理员会话把开关临时置 1（**必须先管理员后考生** —— `AuthSession::login()`
> 会 `purge()` 掉其他身份，顺序反了会把刚建立的考生会话清掉），跑完还原原状。
> 该脚本的还原策略见下节 BUG-250：首版「写回原有效值」本身会污染库，已改为按 `stored` 快照还原。

## 六、BUG-250　【P2】设置接口无法表达「撤销覆盖」，测试还原把默认值实体化成覆盖行

- **现象**：全量回归与浏览器冒烟全部通过、库内也没有「开关被留在开启态」这类明显残留，
  但复核 `siteconfig` 时始终多出一行 `mock_allow_during_exam='0'`。
  它的值恰好等于 `SCHEMA` 默认值，因此**不影响任何行为**，却让「跑完无残留」这一
  验证结论无法成立 —— 每次跑测试都会往库里落一行。
- **根因**（不在测试脚本，而在接口设计）：
  1. `siteconfig` 中的设置项在本项目里是**覆盖值**而非唯一真源 ——
     `Setting::stored()` 的文档已经明确「未配置」与「配置为默认值」是两种状态。
  2. 但写入侧只有 `Setting::putMany()` 一条路径，**没有任何方式表达「撤销覆盖」**。
  3. 于是 `mock_setup_smoke.mjs` 的还原逻辑只能「读有效值 → 写回」：
     用 `/api/public/settings` 读到的 0 是**默认值回落**的结果，写回时却变成一次真实写入，
     凭空把默认值实体化成覆盖行。测试脚本无论怎么写都无法避免，属于接口能力缺失。
- **修复**：
  1. `Setting::putMany()` 支持 `null` 值 = **撤销覆盖**（删除 `siteconfig` 行，回落 schema 默认值），
     返回值汇报「生效后的值」（撤销项即默认值），调用方无需再查一次。
  2. 新增 `SiteConfig::forget()`：删除该键的全部行（历史脏数据可能同键多行），键不存在时无副作用。
  3. 新增 `Setting::storedKeys()`，并由 `GET /api/admin/settings` 以 `stored` 字段下发
     「哪些键真的落过库」，客户端据此做快照、精确还原，不再凭有效值反推。
  4. `mock_setup_smoke.mjs` 改为按快照还原：原本有行 → 写回原值；原本无行 → `PUT {key: null}`
     撤销，并在 `finally` 中**断言还原后 `stored` 不含该键**，把这类污染变成可回归的失败。
- **回归覆盖**（`test/cases/settings_test.php` 新增守卫，共 19 项断言）：
  - `putMany(null)` 撤销后行消失、`stored()` 回落 `null`、取值回落默认；
  - 对本来就没有行的键重复撤销无副作用（幂等）；
  - 同一请求内「撤销 + 写入」混用互不干扰，非 schema 键既不参与写入也不参与撤销；
  - 纯未知键仍判 400，不能静默成功；
  - **HTTP 层 `PUT {"key": null}`**：显式断言 JSON `null` 未被中间层吞掉
    （否则撤销会静默退化成「无该项」），并核对响应值与 `stored` 列表。

## 七、验证结果（第二轮：默认关闭 + 在考硬约束）

| 验证 | 结果 |
|---|---|
| 后端全量 `node temp/runtests.mjs` | **510 PASS / 0 FAIL / 0 SKIP**（跑后 `siteconfig` 练习/模拟相关行 = 0） |
| `test/cases/settings_test.php` | **111 PASS / 0 FAIL**（新增 19 项撤销覆盖断言） |
| 前端静态 `node temp/check_frontend.mjs` | 42 文件 0 语法错误 / 0 未解析 import |
| 图标自检 `python temp/check_icons.py` | 88 个图标，无失效引用 |
| 全站浏览器巡检 `browser_sweep_v6.mjs` | **103 PASS / 0 FAIL** |
| CSS 覆盖率审计 `css_coverage_audit.mjs` | **0** 个无规则类名 |
| 匿名首屏 `verify_me_401.mjs` | **64 PASS / 0 FAIL** |
| 后台设置页 `settings_view_smoke.mjs` | **35 PASS / 0 FAIL** |
| 考试表单 `exam_form_smoke.mjs` | **33 PASS / 0 FAIL** |
| 后台 16 视图 `admin_live_smoke.mjs` | **16 PASS / 0 FAIL** |
| 考场 E2E `verify_exam_flow.mjs` | **12 PASS / 0 FAIL** |
| 组卷页 `mock_setup_smoke.mjs` | **14 PASS / 0 FAIL**（末尾断言「撤销覆盖、无残留行」） |

> 跑完复核库内：练习/模拟相关 `siteconfig` 行 = 0、模拟考试记录 = 0、
> `__TEST__` 前缀的考试/管理员/考生记录 = 0。
> 另注：`exam_form_smoke.mjs` / `admin_live_smoke.mjs` / `mock_setup_smoke.mjs`
> 均需以 `argv[2]` 传入 public 目录（如 `"$(pwd -W)/public"`），
> 缺参时会回落到相对 `temp/domtest` 的默认路径而解析到盘符根目录，报模块找不到 —— 属调用方式问题。


