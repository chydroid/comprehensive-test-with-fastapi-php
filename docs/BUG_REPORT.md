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
