# 同类项目对比与功能提升建议

> 调研对象：exam-ai、exam-system（LzWei-hub）、Examify、优考试、百分考、通如科技、云朵课堂等开源/商用在线考试系统。
> 对比基准：本项目（深蓝网上考试系统）当前路由与代码实际能力。
> 调研日期：2026-09-18

---

## 一、本项目已具备的核心优势（对比中不应削弱）

与同类项目相比，本项目有几项做得比多数开源系统更扎实，应在后续迭代中保留并继续强化：

1. **极致轻量的部署形态**：零依赖 PHP 框架 + 原生 ES Module 前端，无构建工具，单条 `php -S` 即可运行；同类多为 Spring Boot / Node + 重型前端构建链。
2. **三端严格会话隔离 + 「考生在考」个人层硬约束**：从架构层面防止串考、跨身份越权，同类项目少有这种会话级隔离。
3. **完整的考试生命周期状态机 + 惰性自动开考/结束**：无需常驻定时任务，状态收敛可靠（本轮刚修复过期考场冻结缺陷）。
4. **逐人随机卷**：天然降低抄袭面。
5. **工程化质量基线高**：762 项后端回归 + 多套 jsdom 冒烟 + 浏览器巡检 + CSS 覆盖率审计，同类开源项目普遍缺乏这种测试密度。
6. **API 自文档化**：OpenAPI / Swagger / Metrics 齐备。

---

## 二、对比同类项目发现的差距（按教学闭环价值分级）

### A 档：教学闭环必需，强烈建议补充

| # | 功能 | 同类是否标配 | 本项目现状 | 差距说明 |
|---|---|---|---|---|
| A1 | **持久化错题本** | 几乎标配（exam-ai / exam-system / Examify / 优考试） | 仅模拟考试 session 内临时回顾；练习模式不留痕、正式考试错题不可归集重练 | 缺少「跨模式归集 + 错题重练」的个人错题本，练习价值大打折扣 | **✅ 已完成（2026-09-19）** |
| A2 | **班级 / 知识点维度成绩分析报表** | 标配（及格率/优秀率/得分率/错题率/正确率可视化） | 管理端仅有「平均分/得分率」stat 卡片 | 缺班级横向对比、知识点薄弱项定位、分数分布图，无法支撑精准教学 | **✅ 已完成（2026-09-19）** |
| A3 | **组卷多样化（手动固定 + 按知识点/难度比例）** | 标配 | 仅有随机抽题（`ORDER BY RAND()`） | 教师无法精确指定题目或按章节配比，无法出单元测试/章节测验 | **✅ 已完成（2026-09-19）** |
| A4 | **主观题批改闭环** | 标配（关键词自动给分 + 人工复核） | 问答题(longtext)直接记 0 分、无人工批阅入口；填空靠精确匹配 | 问答题形同虚设，考试公平性/实用性受损 | **✅ 已完成（2026-09-19）** |
| A5 | **题库批量导入（Excel/Word）** | 标配 | 仅学生可批量导入；题目只能单条录入 | 题库建设成本极高，教师录题痛苦 |

### B 档：考试公平性与运营必需

| # | 功能 | 同类是否标配 | 本项目现状 | 差距说明 |
|---|---|---|---|---|
| B1 | **防作弊基础能力**（切屏检测/水印/异常记录/选项乱序/多端互踢） | 核心卖点 | 零防作弊（仅会话互斥） | 远程考试公信力弱，难以承接正式考核 | **✅ 已完成（2026-09-19）** |
| B2 | **系统操作审计日志** | 标配 | 仅有 metrics，无关键操作留痕 | 难以追溯「谁改了成绩 / 删了考试 / 导入了数据」 | **✅ 已完成（2026-09-18）** |
| B3 | **移动端适配** | 标配（PC+手机+平板） | 桌面优先 | 移动答题体验未验证，限制随堂/居家场景 | **✅ 已完成（2026-09-19，即交付清单里的 B4）** |
| B4 | **成绩公示与隐私分级** | 常见 | 成绩可查但缺「按班级/个人粒度控制可见性」 | 兼顾公开性与隐私的精细控制不足 |

### C 档：增值 / 可选（视产品定位决定是否做）

| # | 功能 | 说明 | 建议 |
|---|---|---|---|
| C1 | **电子证书** | 考后按阈值自动发放/下载成绩证书 | 教学考核场景加分，成本低 |
| C2 | **补考 / 重考机制** | 针对未通过者开放二次考核入口 | 教学闭环有用，中等成本 |
| C3 | **培训学习模块（课件/学习计划/课程库）** | 偏 LMS，含视频/音频/附件 | 可能超出「理论考核」定位，慎重 |
| C4 | **AI 组卷 / AI 阅卷** | 引入大模型辅助命题与主观题评分 | 成本高、依赖外部 API，远期 |
| C5 | **考后问卷 / 留言互动** | 收集考试难度反馈、师生答疑 | 轻量，可选 |

---

## 三、实施优先级建议

1. **先做 A 档**：补齐教学闭环（错题本、成绩分析、组卷多样化、主观题批改、批量导入）。这些是「能考」到「考得好、练得透」的关键跃迁，且与现有数据模型（stuscore / quizlib / stupaper）契合度高，落地成本相对可控。
2. **再做 B 档**：提升考试公信力与运营可追溯性（防作弊、审计日志、移动端）。
3. **C 档按需**：电子证书、补考机制性价比高可优先考虑；培训学习模块与 AI 能力视产品战略再定。

---

## 四、逐项落地可行性简评（供决策参考）

- **A1 错题本**：✅ 已完成（2026-09-19）。新增 `wrong_book` 表按 `stu_id + quiz_id` 归集，练习/模拟/正式考试三个判分落点旁路沉淀错题，前端「错题本」页（按科目分组、掌握状态过滤、来源筛选、错题重练）已上线。成本：中。
- **A2 成绩分析**：✅ 已完成（2026-09-19）。新增只读服务 `ScoreAnalysis`（均分/中位数/标准差/及格率/优秀率、分数段分布、按题型·难度·科目·知识点正确率、薄弱题 TOP、班级横向对比），教师端与管理端共用视图 `views/analysis.js`（含两端 NAV 入口与路由）。成本：中。
- **A3 组卷多样化**：✅ 已完成（2026-09-19）。`examinfo.paper_mode`（random/manual/by_kp）+ `exam_manual_quiz` / `exam_kp_plan` 两表；`ExamEngine::generatePaper` 按模式分发；教师端组卷弹窗「组卷方式」三选一（手动选题器 / 知识点规划器）；题库检索与知识点清单接口双端开放。成本：中高（涉及组卷算法与前端）。
- **A4 主观题批改**：✅ 已完成（2026-09-19）。问答题此前连「出卷」都不支持（组卷矩阵只有四种客观题），本轮先补齐组卷维度（`examinfo.longtext_*`，`Exam::TYPE_PREFIXES` 纳入 `longtext`），再做教师端批阅闭环（待批总览 → 答题卡含参考答案 → 逐题给分/评语 → `ExamEngine::recomputeScore` 重算总分），并支持撤销批阅。成本：中。
- **A5 批量导入**：✅ 已完成（2026-09-19）。`QuizController#import` 走 CSV/TXT 通道（不引第三方依赖，与项目「零依赖」取向一致；`.xlsx` 可用 Excel 另存为 CSV 再导入）。列顺序 `科目,题型,题干,选项,答案,难度,录题人,知识点`；科目按名或 ID 归一、题型接受中文名或代码、判断题兼容 对/错·√/×·T/F、多选答案自动去重排序、难度接受 易/中/难 或 Y/Z/N；逐行校验逐行写入，单行失败只记错不中断。同时补齐 `quiz_kp` 写入口（此前只读不写，导致「按知识点组卷」无题可选）。成本：低中。
- **B1 防作弊**：✅ 已完成（2026-09-19）。`enable_cheat_guard` 总开关（默认关闭）；开启后：① 组卷时按考生随机打乱选择题选项并落 `stupaper.option_order`（判分按选项字母，与显示顺序无关，改序不影响成绩）；② 考生端水印（姓名+准考证号+时间平铺）；③ `visibilitychange`/`blur` 上报到 `cheat_event` 表并累加 `stuscore.cheat_count`；④ 同账号二次登录签发新 `exam_token`，旧会话取题/存答案被 40902 挤下线。监考端（管理+教师）新增「异常考生」统计卡、「异常次数」列与「异常记录」面板。成本：中。
- **B2 审计日志**：✅ 已完成（2026-09-18），见下方实施记录。成本：低中。
- **B3 移动端**：✅ 已完成（2026-09-19）。统一断点标尺（1280/1024/900/768/480/360）；`@media (pointer: coarse)` 把按钮/表单/选项/导航抬到 44px 触控下限；表格横向滚动 + 惯性 + 去除触屏粘滞悬停；考生端新增固定底部标签栏（我的考试/在线练习/错题本/我的成绩/我的，5 项一步直达，侧栏抽屉保留次要入口）；适配 iPhone 安全区（`viewport-fit=cover` + `env(safe-area-inset-bottom)`）。成本：中。
- **C1/C2/C5**：成本低，可独立小步实施。
- **C3/C4**：成本高、可能偏离定位，建议暂缓。

---

## 五、已落地实施记录

### ✅ B2 系统操作审计日志（2026-09-18 完成）

**设计原则：纯旁路，绝不参与业务事务、绝不打断流程。**
审计只在业务写操作**之后**调用 `Audit::log()`，整段包在 `try/catch` 内；任何写入失败只 `error_log` 不抛、不回滚、不影响主接口返回。已专门写测试验证「审计表故障（RENAME 表名）时，建科目接口仍返回 200」。

**改动清单**
| 层 | 文件 | 内容 |
|---|---|---|
| 数据 | `db/add_admin_log.sql` | 新建 `admin_log` 表（actor_type/actor_id/actor_name/action/target/detail(JSON)/ip/created_at + 3 索引），已执行建表 |
| 服务 | `app/Services/Audit.php` | `log()` 旁路写 + `recent()` 分页过滤查询（action 前缀 / actor_type / actor_id / keyword / since / until）；`currentActor()` 遍历 admin/teacher/student 登录态 |
| 基类 | `app/Controllers/BaseController.php` | 新增 `audit()` 委托方法 |
| 插桩 | 约 14 个控制器 | 登录/登出、考试增删改+开考/开放/出题、成绩备份、设置更新、题库全操作+批量删+清理、学生/教师/管理员增删改+学生导入、监考锁/收卷/全员+教师端监考、教师端考试、系统初始化/清空 |
| 接口 | `config/routes.php` + `SystemController::logs()` | 新增 `GET /api/admin/logs`（分页+过滤） |
| 前端 | `api/index.js` + `views/admin/logs.js` + `apps/admin.js` | 操作审计视图（过滤栏+统计卡+表格，`withLoading` 加载）；NAV 加「操作审计」（`system.manage` 权限） |

**动作命名约定**：`auth.login` / `exam.create` / `monitor.submit` / `score.backup` / `settings.update` / `quiz.*` / `user.*` / `system.*`。

**验证**：`audit_log_test.php` 20 PASS（含旁路熔断）；全量后端回归 782 PASS / 0 FAIL / 1 SKIP（无回归）；`admin_live_smoke` 17/17（logs 视图 22 行正常）；`browser_sweep_v6` 103/103；前端 43 文件 0 错误。

> 说明：A5 题库批量导入在原始表格编号中为 A5，与多选项里的「B3 题库批量导入」为同一项，统一按「题库批量导入」推进。

---

### ✅ A1 错题本（2026-09-19 完成）

**设计原则：与 B2 一致的纯旁路设计——所有错题沉淀包在 `try/catch` 内，失败只 `error_log`，绝不抛异常/回滚/打断判分主流程；并设幂等护栏，避免重复交卷/重复判分重复累加。**

错题在三个判分落点自动沉淀到 `wrong_book` 表（按 `stu_id + quiz_id` 唯一），练习答对即标记掌握、答错归集；正式/模拟交卷判分时沉淀错题，重复判分靠幂等门禁（`autoGrade` 的 `LEFT(stu_status,4)<>'over'` 分支之后、`gradeMock` 的 `$alreadyOver` 预判）保证不重复计数。

**改动清单**
| 层 | 文件 | 内容 |
|---|---|---|
| 数据 | `db/add_wrong_book.sql` | 新建 `wrong_book` 表（stu_id/quiz_id/exam_type/exam_id/paper_id/wrong_count/mastered/时间戳 + 唯一键 `uq_stu_quiz(stu_id,quiz_id)` + 索引）。已通过 `temp/apply_wrongbook_ddl.php` 执行建表 |
| 服务 | `app/Services/WrongBook.php` | `collect/collectMany/markMastered/list/stats/practicePick/clear`；所有写包 try/catch；`list/stats/practicePick` JOIN quizlib/subject 输出题型/难度/科目/选项 |
| 落点1 | `app/Services/ExamEngine.php` | `autoGrade()` SELECT 取 `sp.quiz_id`，答错收集，幂等门禁后沉淀 `collectMany(stuId, wrong, 'formal', examId)` |
| 落点2 | `app/Controllers/ExerciseExamController.php` | `gradeMock()` SELECT 取 `sp.quiz_id`，答错收集，`$alreadyOver` 预判后 `collectMany(stuId, wrong, 'mock', examId)` |
| 落点3 | `app/Controllers/ExerciseController.php` | `check()` 答对 `markMastered`、答错 `collect(stuId, quizId, 'exercise')` |
| 接口 | `config/routes.php` + `StudentWrongBookController.php` | `GET /api/student/wrong-book`（列表+统计+科目+过滤）、`GET /api/student/wrong-book/practice`（抽题不下发答案）、`POST /api/student/wrong-book/check`（重练校验+标记/归集） |
| 前端 | `api/index.js` + `views/student/wrongbook.js` + `apps/student.js` | 错题本视图（统计卡、科目分组列表、掌握状态/来源筛选、单题+批量错提重练会话）；NAV 加「错题本」（`practice` 组，图标 `book-open`） |

**已知设计取舍**：`wrong_book` 唯一键不含 `exam_type`，同一题在多个模式答错会合并为一行、行内 `exam_type` 反映最近一次来源；统计按科目+题型+难度聚合（题库无独立知识点字段）。如需「按来源分布」精确统计可后续把 `exam_type` 纳入唯一键。

**验证**：`wrong_book_test.php` 39 PASS（练习沉淀/重复累加/答对标记掌握、formal 沉淀+幂等、mock 沉淀+重复交卷不重复、列表/统计/过滤/抽题/重练、熔断 RENAME 表仍 200）；全量后端回归 821 PASS / 0 FAIL（无回归）；`check_frontend` 44 文件 0 错误 0 未解析导入；`check_icons` 88 图标无无效引用。

---

### ✅ A2 成绩与学情分析（2026-09-19 完成）

**设计原则：单一只读服务统一口径。** 所有统计在 `ScoreAnalysis` 一处完成，前端只负责呈现，避免前后端各算一套导致统计口径分叉；全部走 `Quiz::isCorrect()` 复算正确性（与判分同一出处），不信任历史列。

**统计口径**
- 只统计 `stu_status` 以 `over` 开头的已交卷考生，未交卷不进分母。
- 未作答按答错计入分母（更能反映掌握度）。
- 问答题（`longtext`）无自动判分，**不纳入正确率**，单独以 `pending_types` 提示需人工批阅。
- 满分优先取 `examinfo.exam_score`；为 0 时用实际最高分兜底，避免得分率除零。
- 及格线/优秀线默认 60%/85%，可经 query `pass_line` / `excellent_line` / `weak_limit` 调整。

**改动清单**
| 层 | 文件 | 内容 |
|---|---|---|
| 服务 | `app/Services/ScoreAnalysis.php`（新增） | `analyze()` 产出 exam/summary/distribution/by_type/by_diff/by_subject/by_kp/pending_types/weak_items/classes |
| 接口 | `config/routes.php` + 两端控制器 | `GET /api/teacher/exams/{id}/analysis`（`assertOwnExam` 归属校验）、`GET /api/admin/exams/{id}/analysis` |
| 前端 | `public/assets/js/views/analysis.js`（新增，工厂 `createAnalysisView`） | 两端复用的共享视图：统计卡 8 张、分数段分布、题型/难度/知识点正确率条、班级横向对比表、薄弱题 TOP 表、待批阅提示、统计口径说明 |
| 注册 | `apps/teacher.js` / `apps/admin.js` | 注入各自数据源（教师 `scores` / 管理端 `exams`），NAV 加「成绩分析」（图标 `bar-chart-2`），路由 `/analysis` |
| 联动 | `views/teacher/index.js` | 考试详情展示组卷方式与题量/满分，并提供「查看成绩分析」直达按钮 |

**验证**：`score_analysis_test.php` 54 PASS（确定性数据下逐项校验均分/中位数/最高最低/及格率/优秀率/分数段/题型正确率/薄弱题升序/班级对比/未交卷排除/阈值可调/双端 404 守卫）；全量后端回归 929 PASS / 0 FAIL / 1 SKIP（无回归）。

---

### ✅ A3 组卷多样化（2026-09-19 完成）

**设计原则：新增模式与旧随机卷互不干扰。** `paper_mode` 默认 `random`，旧考试与旧调用完全不受影响；`manual`/`by_kp` 的明细存独立表而非 `examinfo` 列，避免污染主行。

**三种模式**
| 模式 | 取值 | 抽题依据 | 满分 |
|---|---|---|---|
| 随机抽题 | `random` | `{type}_{easy\|mid\|hard}_sum` 计数列（原有行为） | 矩阵推算（确定值） |
| 手动选题 | `manual` | `exam_manual_quiz`（按 `sort` 顺序，所有考生同卷） | 按所选题目的实际题型 × 每题分值合计 |
| 按知识点 | `by_kp` | `exam_kp_plan`（每知识点按数量随机抽、可限难度） | 组卷时按实际卷面题型回填 `exam_score` |

**改动清单**
| 层 | 文件 | 内容 |
|---|---|---|
| 数据 | `db/add_paper_mode.sql`（新增） | `examinfo.paper_mode` 列；`quizlib.quiz_kp` 知识点列；新建 `exam_manual_quiz`、`exam_kp_plan` 表。已执行建表 |
| 模型 | `app/Models/Exam.php` | `PAPER_MODES` 常量；`paperPlan`（兼容新旧直方图）、新增 `paperMode()`（回读明细）、`totalQuestions()`/`computedTotalScore()` 按模式分支 |
| 引擎 | `app/Services/ExamEngine.php` | `generatePaper()` 按模式分发：manual 取 `exam_manual_quiz`、by_kp 用 `drawQuestionsByKp()`；新增 `scoreOfPaper()`，在 `exam_score` 为空时按真实卷面回填（保证 `autoGrade` 封顶基于真实卷面） |
| 控制器 | `TeacherExamController` / `Admin\ExamController` | `collectParams` 读 `paper_mode` + `manual_ids`/`kp_plan`；`assertValid` 按模式分支（manual 校验非空/存在/归属本考试科目/分值>0）；`persistPaperMode()` 先清后写固化明细；新增 `quizSearch()` / `quizKps()`；管理端 `show`/`start` 的题库容量校验仅对 `random` 生效 |
| 权限 | `app/Middlewares/SessionAuthMiddleware.php` | 新增 `/api/admin/quiz-search`、`/api/admin/quiz-kps` → `quiz.view`（二者为单数路径，不会命中 `quizzes` 前缀，不登记会落到 `admin.access` 兜底而语义过宽） |
| 路由 | `config/routes.php` | 教师端/管理端各 2 条检索路由 |
| 前端 | `api/index.js` + `views/teacher/exam-editor.js` + `views/teacher/index.js` | 组卷弹窗新增「组卷方式」segmented 三选一 + 手动选题器（题型/关键字检索、加入/移除 chip）+ 知识点规划器（知识点清单 + 难度 + 数量）；列表加「组卷」列标识模式 |

**验证**：`paper_mode_test.php` 54 PASS（三模式全链路：创建→落库→出题→卷面校正；手动选题空选择/跨科目 400；题库检索不下发答案；双端检索与清单；管理端保存）；全量后端回归 929 PASS / 0 FAIL / 1 SKIP（无回归）；`api_route_audit` 前端 156 调用全部有对应路由；`admin_401_guard` 8/8、`verify_me_401` 64/64；`check_frontend` 45 文件 0 错误；`check_icons` 88 图标无无效引用；`a2a3_smoke.mjs` 17/17（真实浏览器渲染）。

**修复的真实缺陷**：知识点面板的「加载知识点」按钮原位于 `body` 内，而 `load()` 首行 `clear(body)` 会把按钮一并清掉（按钮凭空消失）——已移出 body 之外。

**已知限制**：题库 `quiz_kp` 当前为空，按知识点组卷需先在题库为题目补填知识点；手动选题为「所有考生同一份卷」（如需逐人随机需改用 `random` 或 `by_kp`）。

---

### ✅ A4 主观题批改闭环（2026-09-19 完成）

**关键前提**：问答题此前**根本不支持出卷** —— `Exam::TYPE_PREFIXES` 只有四种客观题，`examinfo` 也没有 longtext 的组卷列。因此 A4 不是「补一个批阅弹窗」，而是补齐整条链路：

```
组卷维度（examinfo.longtext_* + TYPE_PREFIXES 纳入 longtext）
  → 考生作答（答题引擎本就支持 longtext，无需改动）
  → 交卷只判客观题（autoGrade 对 longtext 仍 continue）
  → 教师逐题给分（SubjectiveGrading）
  → 重算总分（ExamEngine::recomputeScore）
```

**三条口径约定**
1. **未批阅 ≠ 答错**：`quiz_score IS NULL` 的主观题既不计分，也不进任何正确率分母 —— 避免批阅前出现「虚假 0 分」。
2. **得分落在答卷行**：每题一行的 `stupaper` 直接承载 `quiz_score / quiz_comment / grader_name / graded_at`，不另建汇总表，批阅轨迹天然可追溯。`quiz_status` 语义保持不变（0=未作答 / 非 0=已作答），批阅状态由 `quiz_score IS NULL` 判定。
3. **双重封顶**：单题得分不超过 `examinfo.longtext_val`；总分由 `recomputeScore` 按 `exam_score` 封顶。

**幂等设计（与 autoGrade 的关键差异）**：`autoGrade` 在交卷时只跑一次（重复交卷靠 `LEFT(stu_status,4) != 'over'` 挡住），而**人工批阅必然发生在成绩已成 `over` 之后**。若 `recomputeScore` 沿用同一门禁，批阅给分将永远写不进去。因此它刻意不带门禁，每次按「当前卷面应得总分」重写 —— 天然幂等。

**改动清单**
| 层 | 文件 | 内容 |
|---|---|---|
| 数据 | `db/add_subjective_grade.sql`（新增） | `examinfo` +4 列（`longtext_{easy,mid,hard}_sum`、`longtext_val`）；`stupaper` +4 列（`quiz_score` / `quiz_comment` / `grader_name` / `graded_at`）。每列独立 ALTER，配幂等 apply 脚本。已执行 |
| 模型 | `app/Models/Exam.php` | `TYPE_PREFIXES` 纳入 `longtext`（`paperPlan`/`totalQuestions`/`computedTotalScore`/`availableCounts`/`checkStock` 全部自动跟进）；新增 `SUBJECTIVE_TYPES`；`fillable` 补 4 列 |
| 引擎 | `app/Services/ExamEngine.php` | 新增私有 `valueMap()`（收敛每题分值来源，避免「某处漏改导致该题型恒 0 分」）；新增只读 `scoreBreakdown()` 与写入 `recomputeScore()`；`paperWithAnswers()` 补批阅字段与 `is_subjective`/`graded` |
| 服务 | `app/Services/SubjectiveGrading.php`（新增） | `overview()`（只统计 `stu_status` 以 `over` 开头者，与 A2 同口径）、`paper()`（含参考答案）、`grade()`（事务内写分 + 重算）、`revoke()`（清空回落） |
| 控制器 | `app/Controllers/TeacherGradingController.php`（新增） | 4 个接口；归属校验与其它教师端 `{id}` 接口同口径（非本人/模拟考试/不存在一律 **404**） |
| 路由 | `config/routes.php` | `GET /api/teacher/exams/{id}/subjective`、`GET\|POST .../subjective/{stuId}`、`POST .../subjective/{stuId}/revoke` |
| 前端 | `public/assets/js/views/teacher/grading.js`（新增）+ `api/index.js` + `apps/teacher.js` + `views/teacher/exam-editor.js` | 教师端「主观题批改」页（考试选择 / 待批统计 / 待批列表 / 逐题批阅弹窗，含参考答案、评分、评语、实时合计、撤销）；组卷矩阵 `PAPER_TYPES` 纳入 `longtext`（矩阵行与题型筛选下拉自动多出「问答题」） |

**验证**：`subjective_grading_test.php` **72 PASS / 0 FAIL**（组卷出问答题 / 交卷不含主观题分 / 明细口径 / 待批总览 / 答题卡含参考答案 / 提交批阅并落库 / 单题封顶 / 非法 paper_id 被忽略 / 空 items 与非法 stuId 400 / 他人考试与模拟考试 404 / 撤销回落）；全量后端回归 **1001 PASS / 0 FAIL / 1 SKIP**（基线 929 + 72，零回归）；`api_route_audit` 前端 160 调用全部有路由；`admin_401_guard` 8/8；`check_frontend` 46 文件 0 错误；`check_icons` 88 图标无无效引用；`a4_smoke.mjs` 11/11（真实浏览器：批改页渲染 + 组卷矩阵五行题型齐全）；`a2a3_smoke.mjs` 17/17（回归）。

**顺带修正**：全流程演练的「逐人随机卷差异」断言原先按**题型全集**取最小可用量，`TYPE_PREFIXES` 纳入 longtext 后，题库未备问答题的科目会被拉到 0 而误判为「随机性无法验证」（表现为该用例被跳过）。已改为只统计**该场考试实际配置的题型**，恢复为真实断言（206 PASS / 0 SKIP）。

**已知限制**
- 题库现存 **39 道填空题的 `quiz_key` 为空**，这些题作答后永远判错（判分对空标准答案按设计跳过，不误判为对）。属既有数据质量问题，建议在「题库批量导入」或题库管理中补齐。
- 批阅只对**正式考试**开放；模拟考试是考生自助练习，无教师批阅语义，后端显式 404 排除。
- 未做「按关键词自动给分」：主观题分值语义因题而异，自动给分误判风险高于收益，当前仅提供人工批阅 + 参考答案对照。


### ✅ B1 考试防作弊（2026-09-19 完成）

**一个总开关控制全部能力**：`Setting::SCHEMA['enable_cheat_guard']`（`security` 组，`bool`，默认 **0=关闭**，`public:true` 随 `/api/public/settings` 下发）。默认关闭是刻意的 —— 切屏检测对网络卡顿、弹窗提醒等正常行为也会计一次异常，应由考务方按场次性质自行决定是否启用。

**四道防线**

| 能力 | 实现要点 |
|---|---|
| 选项乱序 | `ExamEngine::shuffledOrder()` 在**组卷时**为每张卷子生成一条字母序列写入 `stupaper.option_order`（如 `CABD`）。考生端按序列重排渲染。**判分按选项字母（key）而非显示位置**，因此乱序不改变任何人的对错 |
| 切屏/失焦检测 | 考生端监听 `visibilitychange`（`document.hidden`）与 `window.blur`，经 `POST /api/exam/cheat` 上报 |
| 考试水印 | 考生姓名 + 准考证号 + 时间平铺成 `aria-hidden` 覆盖层，`pointer-events:none` 不拦点击，截图即带身份信息 |
| 多端互踢 | 登录时签发 `exam_token`（`random_bytes(16)`）落 `stuscore`，取题/存答案/状态轮询时比对会话 token，不一致即清会话并返回 **40902** |

**旁路写入（与审计日志同构）**：`app/Services/CheatGuard.php` 的所有写库包在 `try/catch` 内，失败只 `error_log`，绝不打断答题主流程；异常累计（`stuscore.cheat_count`）与明细（`cheat_event` 表）分开存 —— 列表页只需计数、详情页才要明细。

**监考可视化**：管理端与教师端监考页各新增一张「异常考生」统计卡、一列「异常次数」（`cheat_count`）、一个「异常记录」面板（倒序事件流 + 类型中文映射，无异常时不占版面）。

**改动清单**

| 层 | 文件 | 内容 |
|---|---|---|
| 数据 | `db/add_cheat_guard.sql`（新增） | `stupaper.option_order`；`stuscore.cheat_count` / `exam_token`；新建 `cheat_event` 表。配幂等 apply 脚本 `temp/apply_cheat_guard_ddl.php`，已执行 |
| 服务 | `app/Services/CheatGuard.php`（新增） | `report()` / `events()` / `countByExam()` |
| 引擎 | `app/Services/ExamEngine.php` | `optionKeys()` + `shuffledOrder()`（请求内缓存选项字母）；`insertPaperRow()` 增写 `option_order` |
| 控制器 | `app/Controllers/ExamController.php` | 登录签发 token；`paper()`/`savePaper()`/`status()` 三处多端校验；`reportCheat()`；`logout()` 清 token |
| 设置 | `app/Services/Setting.php` | `enable_cheat_guard` 一行 |
| 路由 | `config/routes.php` | `POST /api/exam/cheat`；`GET /api/{admin,teacher}/monitor/cheat-events` |
| 前端 | `views/exam-runner.js`、`views/student/exam.js`、`views/admin/monitor.js`、`views/teacher/index.js`、`core/app-settings.js`、`api/index.js`、`css/portal.css` | 选项重排、水印、上报监听、异常卡/列/面板、FALLBACK 补默认值 |

**验证**：`cheat_guard_test.php` **34 PASS / 0 FAIL**（开关关闭时不下发 token/不上报、开启后选项乱序且答案为字母集合、切屏与失焦上报落库并计数、水印、同账号二次登录 → 旧会话取题 40902 且会话被清、监考端两处 events 接口、非本人考试 404）；`b1b3b4_frontend_test.php` 覆盖前端接线契约；真实浏览器巡检新增 D1/D3 探针。

---

### ✅ A5 题库批量导入（交付清单编号 B3，2026-09-19 完成）

**不引第三方依赖**：走 CSV/TXT 通道（`str_getcsv` 逐行解析），与项目「零依赖」取向一致；`.xlsx` 由 Excel/WPS 另存为 CSV 后导入即可。前端提供「下载 CSV 模板」（含 UTF-8 BOM，Excel 打开不乱码）。

**列顺序**：`科目, 题型, 题干, 选项, 答案, 难度, 录题人, 知识点`

| 列 | 容错设计 |
|---|---|
| 科目 | 科目名**或**科目 ID（新增 `Subject::resolveId()`，与 `Grade::resolveId()` 同构），查不到报该行失败 |
| 题型 | 中文名（判断题/单选题/多选题/填空题/问答题）**或**代码（radio1/radio2/checkbox/text/longtext） |
| 选项 | 选择题用 `\|` 或 `;`/`；` 分隔；**非选择题一律清空该列**（填空/问答不存选项） |
| 答案 | 判断题兼容 对/错、正确/错误、√/×、T/F；多选 `CA` 自动去重排序为 `AC`；填空/问答保留原文 |
| 难度 | 易/中/难 或 Y/Z/N；留空回落「中」 |
| 录题人 | 留空回落当前登录账号 |
| 知识点 | 写入 `quizlib.quiz_kp` —— **补齐 A3 遗留**：此前只有读路径没有写路径，`quiz_kp` 全库为空，「按知识点组卷」实际无题可选 |

**容错策略**：逐行校验、逐行写入，单行失败只记「第 N 行：原因」不中断整批；全行失败才整体 400 并携带 `errors[]` 明细。空行跳过。

**首行表头判定（本轮修出的真实缺陷）**：原判据是「首行含 `科目|题型|题干` 字样」，但这会把**科目名恰好含「科目」二字的数据行**（如「科目一」）当成表头整行丢弃，用户只看到「导入 0 条」且毫无线索。已改为两条同时成立才算表头：① 第 2 列不是任何可识别的题型（真实数据行这里必然是合法题型）② 行内至少命中 2 个表头特征词。测试用首行科目名 `__TEST_IMP__科目` 做了定点回归。

**权限点**：新增 `quiz.import`（与 `student.import` 同构），授予 `testAdmin` 与 `quizAdder`；`quizOperator`（题库运维）刻意不给 —— 该角色只做清理不做录入，保持「能清不能灌」的既有分工。前端「批量导入」按钮同时按 `can('quiz.import')` 显隐。

**改动清单**

| 层 | 文件 | 内容 |
|---|---|---|
| 模型 | `app/Models/Subject.php` | 新增 `idNameMap()` + `resolveId()`；`app/Models/Quiz.php` `fillable` 补 `quiz_kp` |
| 控制器 | `app/Controllers/Admin/QuizController.php` | 新增 `import()` 与 `readImportContent()` / `resolveType()` / `resolveDiff()` / `normalizeImportOptions()` / `normalizeImportKey()` / `looksLikeHeader()`；`buildRow()` 写入 `quiz_kp`；`save()`/`update()` 校验规则补 `quiz_kp` |
| 路由 | `config/routes.php` | `POST /api/admin/quizzes/import`（静态路由，优先于 `/{id}`） |
| 中间件 | `app/Middlewares/SessionAuthMiddleware.php` | `WRITE_POINTS` 登记 `quiz.import` |
| 权限 | `config/config.php` | `testAdmin` / `quizAdder` 授予 `quiz.import` |
| 前端 | `views/admin/quiz.js`、`api/index.js` | 「批量导入」弹窗（格式说明 + 示例 + 上传文件/粘贴文本双 Tab + 模板下载 + 失败明细回显）；题库列表与详情新增「知识点」；单题编辑器新增「知识点」输入 |

**验证**：`quiz_import_test.php` **66 PASS / 0 FAIL**（未登录 401 / 无权限 403 / 空内容 400 / 全失败 400 带 errors / 表头跳过 / 空行跳过 / 中文题型与代码题型 / 科目名与科目 ID / 选项分隔符归一 / 判断题口语答案 / 多选去重排序 / 难度中文与代码与默认 / 录题人回落 / 知识点列 / 部分失败仍 200 且成功行落库 / 单题新增与修改写知识点 / 审计 quiz.import）；真实浏览器 D2 探针（列表有知识点列、按钮能开弹窗、格式说明与模板下载齐备、无 JS 错误）。

---

### ✅ B3 移动端适配（交付清单编号 B4，2026-09-19 完成）

**已有基础**（本轮之前就在）：视口 `width=device-width, initial-scale=1, viewport-fit=cover`；侧栏 ≤900px 抽屉化；考试页 ≤768px 固定底栏 + 答题卡上滑抽屉 + 安全区适配。

**本轮补齐**

| 方向 | 内容 |
|---|---|
| 断点标尺 | 在 `mobile.css` 顶部明确 1280 / 1024 / 900 / 768 / 480 / 360 六个标尺值并声明「新增规则一律取标尺值」，同时说明 640/560 为既有历史值予以保留（避免为统一而引入回归） |
| 触控目标 | 新增 `@media (pointer: coarse)` 分支：按钮 `min-height:44px`（`min-height` 会盖过组件的固定 `height`，无需逐个改尺寸）、纯图标按钮补 `min-width:44px`、表单控件 44px、勾选框整行可点且本体 16→20px、导航项 / 下拉项 / 分段控件 44px、答题选项 48px。**仅作用于粗指针设备，桌面鼠标界面尺寸完全不变** |
| 表格 | 触屏下 `.table-wrap` 横向滚动 + `-webkit-overflow-scrolling:touch` + `overscroll-behavior` 收口（横向不外溢到整页、纵向不误触发下拉刷新）；表头 `nowrap` 防止列被压成竖排文字 |
| 粘滞悬停 | `@media (hover: none)` 关闭表格行 `:hover` 高亮 —— 触屏上鼠标悬停态会「粘住」，导致最后点过的那行一直高亮 |
| 考生端底部标签栏 | `createShell` 新增可选 `mobileTabs` 配置；`shell.js` 渲染 `.mobile-tabbar`，桌面端 `display:none`、≤768px 显示，`setActive()` 与侧栏共用同一 key 同步高亮。考生端启用五项：我的考试 / 在线练习 / 错题本 / 我的成绩 / 我的。内容区自动加底部内边距为固定栏让位，并适配 iPhone 安全区 |
| 管理/教师端 | **不启用**底部标签栏 —— 导航项多且二级入口密集，底部栏塞不下，「汉堡 + 抽屉」更合适。新增能力是可选配置而非全局改造 |

**验证**：`b1b3b4_frontend_test.php`（96 断言，含 6 个端口的视口标签、`mobile.css` 加载顺序在所有样式之后、CSS 大括号配平、断点标尺与触控规则存在）；真实浏览器巡检 `browser_sweep_v6.mjs` 新增 **D1 探针**：在 390×844 移动视口断言标签栏渲染 / 可见 / 恰好 5 项 / 触控高度 ≥44px，并断言**桌面视口下不显示**。
