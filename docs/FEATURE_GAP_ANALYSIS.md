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
| A1 | **持久化错题本** | 几乎标配（exam-ai / exam-system / Examify / 优考试） | 仅模拟考试 session 内临时回顾；练习模式不留痕、正式考试错题不可归集重练 | 缺少「跨模式归集 + 错题重练」的个人错题本，练习价值大打折扣 |
| A2 | **班级 / 知识点维度成绩分析报表** | 标配（及格率/优秀率/得分率/错题率/正确率可视化） | 管理端仅有「平均分/得分率」stat 卡片 | 缺班级横向对比、知识点薄弱项定位、分数分布图，无法支撑精准教学 |
| A3 | **组卷多样化（手动固定 + 按知识点/难度比例）** | 标配 | 仅有随机抽题（`ORDER BY RAND()`） | 教师无法精确指定题目或按章节配比，无法出单元测试/章节测验 |
| A4 | **主观题批改闭环** | 标配（关键词自动给分 + 人工复核） | 问答题(longtext)直接记 0 分、无人工批阅入口；填空靠精确匹配 | 问答题形同虚设，考试公平性/实用性受损 |
| A5 | **题库批量导入（Excel/Word）** | 标配 | 仅学生可批量导入；题目只能单条录入 | 题库建设成本极高，教师录题痛苦 |

### B 档：考试公平性与运营必需

| # | 功能 | 同类是否标配 | 本项目现状 | 差距说明 |
|---|---|---|---|---|
| B1 | **防作弊基础能力**（切屏检测/水印/异常记录/选项乱序/多端互踢） | 核心卖点 | 零防作弊（仅会话互斥） | 远程考试公信力弱，难以承接正式考核 |
| B2 | **系统操作审计日志** | 标配 | 仅有 metrics，无关键操作留痕 | 难以追溯「谁改了成绩 / 删了考试 / 导入了数据」 | **✅ 已完成（2026-09-18）** |
| B3 | **移动端适配** | 标配（PC+手机+平板） | 桌面优先 | 移动答题体验未验证，限制随堂/居家场景 |
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
- **A2 成绩分析**：后端在 `ScoreController` 增加班级维度聚合接口（及格率/优秀率/各分数段人数/知识点正确率），前端用轻量图表（项目已有 SVG/CSS 图表能力，无需引重型库）。成本：中。
- **A3 组卷多样化**：在 `Exam`/`ExamEngine` 组卷入口增加「手动选题」「按知识点比例」「按难度比例」三种模式，前端组卷页增加选题器。成本：中高（涉及组卷算法与前端）。
- **A4 主观题批改**：新增教师批阅入口（针对 longtext 留空的分数），`ExamEngine` 支持「待批改」状态与二次给分，`score` 页支持按题批阅。成本：中。
- **A5 批量导入**：新增 `QuizController#import`，解析 Excel（引 PhpSpreadsheet 或轻量 CSV），与现有学生导入对称。成本：低中。
- **B1 防作弊**：前端切屏/失焦监听 + 考试水印 + 服务端异常行为记录 + 选项乱序（组卷时打乱）。成本：中（需前端+后端协同，且不能破坏现有随机卷机制）。
- **B2 审计日志**：新增 `admin_log` 表 + 中间件/钩子记录关键写操作。成本：低中。
- **B3 移动端**：CSS 响应式增强 + 触控适配。成本：中（取决于当前响应式程度）。
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
