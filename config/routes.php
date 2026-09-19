<?php

declare(strict_types=1);

/**
 * 路由注册表
 * 格式：[HTTP方法, 路径, 处理器] 或 [HTTP方法, 路径, 处理器, 路由级中间件]
 *
 * 本系统为同源 Web 应用：鉴权由全局 SessionAuthMiddleware 依据
 * `权限点` 与 `登录态` 判定，路由本身只声明「需要哪些权限点」。
 * 权限点通过第 4 项的路由元数据声明（见 App\Middlewares\SessionAuthMiddleware）。
 *
 * 命名约定：
 *   /api/public/**  前台公开（无需登录）
 *   /api/student/** 考生端（需考生登录）
 *   /api/teacher/** 教师端（需教师登录）
 *   /api/admin/**   管理后台（需管理员登录 + 对应权限点）
 */

use App\Controllers\Admin\AdminController;
use App\Controllers\Admin\AuthController as AdminAuthController;
use App\Controllers\Admin\ClassController;
use App\Controllers\Admin\ConfigController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\ExamCategoryController;
use App\Controllers\Admin\ExamController;
use App\Controllers\Admin\GradeController;
use App\Controllers\Admin\MonitorController;
use App\Controllers\Admin\NewsController;
use App\Controllers\Admin\QuizController;
use App\Controllers\Admin\ScoreController;
use App\Controllers\Admin\StudentController;
use App\Controllers\Admin\SubjectController;
use App\Controllers\Admin\SystemController;
use App\Controllers\Admin\TeacherController;
use App\Controllers\Admin\UploadController;
use App\Controllers\ExamController as StudentExamController;
use App\Controllers\ExerciseController;
use App\Controllers\ExerciseExamController;
use App\Controllers\HomeController;
use App\Controllers\NewsController as PublicNewsController;
use App\Controllers\ScoreController as PublicScoreController;
use App\Controllers\StudentAuthController;
use App\Controllers\StudentController as StudentCenterController;
use App\Controllers\StudentWrongBookController;
use App\Controllers\TeacherAuthController;
use App\Controllers\TeacherExamController;
use App\Controllers\TeacherMonitorController;
use Core\Request;
use Core\Response;

return [

    /* ==================== 健康检查 ==================== */
    ['GET', '/api/health', function (Request $request, Response $response) {
        $dbOk = true;
        try {
            \Core\Database::query('SELECT 1');
        } catch (\Throwable $e) {
            $dbOk = false;
        }
        // 前端 http.js 在收到 419 时会请求本接口并读取 X-CSRF-Token 响应头来
        // 刷新令牌后重试一次。此前后端从未下发该响应头（令牌只出现在登录/
        // /me 的 JSON body 里），导致「419 自动恢复」形同虚设——例如考生在
        // 答题中途刷新页面后保存答案，会一直被 419 卡住。
        // 令牌与当前会话绑定，跨站脚本无法读取同源响应头，公开下发无安全风险。
        $token = \App\Services\AuthSession::csrfToken();

        return $response->success([
            'status' => $dbOk ? 'ok' : 'degraded',
            'time'   => date('c'),
            'db'     => $dbOk,
        ])->header('X-CSRF-Token', $token);
    }],

    /* ==================== 前台公开 ==================== */
    ['GET', '/api/public/site',      [HomeController::class, 'site']],
    ['GET', '/api/public/settings',  [HomeController::class, 'settings']],
    ['GET', '/api/public/help',      [HomeController::class, 'help']],
    ['GET', '/api/public/news',      [PublicNewsController::class, 'index']],
    ['GET', '/api/public/news/{id}', [PublicNewsController::class, 'show']],
    ['GET', '/api/public/hero',      [PublicScoreController::class, 'hero']],
    ['GET', '/api/public/subjects',  [HomeController::class, 'subjects']],
    ['GET', '/api/public/categories', [HomeController::class, 'categories']],

    /* ==================== 考生注册 / 登录 ==================== */
    ['POST', '/api/student/register',      [StudentAuthController::class, 'register']],
    ['GET',  '/api/student/register/options', [StudentAuthController::class, 'registerOptions']],
    ['POST', '/api/student/login',         [StudentAuthController::class, 'login']],
    ['POST', '/api/student/logout',        [StudentAuthController::class, 'logout']],
    ['GET',  '/api/student/me',            [StudentAuthController::class, 'me']],

    /* ==================== 考生个人中心 ==================== */
    ['GET',  '/api/student/info',          [StudentCenterController::class, 'info']],
    ['GET',  '/api/student/options',       [StudentCenterController::class, 'options']],
    ['PUT',  '/api/student/info',          [StudentCenterController::class, 'saveInfo']],
    ['PUT',  '/api/student/password',      [StudentCenterController::class, 'savePwd']],
    ['GET',  '/api/student/scores',        [StudentCenterController::class, 'scores']],
    ['GET',  '/api/student/exams',         [StudentCenterController::class, 'exams']],

    /* ==================== 考生错题本（A1） ==================== */
    ['GET',  '/api/student/wrong-book',           [StudentWrongBookController::class, 'index']],
    ['GET',  '/api/student/wrong-book/practice',  [StudentWrongBookController::class, 'practice']],
    ['POST', '/api/student/wrong-book/check',     [StudentWrongBookController::class, 'check']],

    /* ==================== 正式考试 ==================== */
    ['POST', '/api/exam/login',            [StudentExamController::class, 'login']],
    ['GET',  '/api/exam/status',           [StudentExamController::class, 'status']],
    ['POST', '/api/exam/logout',           [StudentExamController::class, 'logout']],
    ['GET',  '/api/exam/paper',            [StudentExamController::class, 'paper']],
    ['POST', '/api/exam/paper/save',       [StudentExamController::class, 'savePaper']],
    ['POST', '/api/exam/paper/submit',     [StudentExamController::class, 'submitPaper']],
    ['GET',  '/api/exam/over',             [StudentExamController::class, 'over']],
    ['GET',  '/api/exam/answer',           [StudentExamController::class, 'viewAnswer']],

    /* ==================== 在线练习 ==================== */
    ['GET',  '/api/exercise',              [ExerciseController::class, 'index']],
    ['POST', '/api/exercise',              [ExerciseController::class, 'index']],
    ['POST', '/api/exercise/answer',       [ExerciseController::class, 'check']],

    /* ==================== 模拟考试 ==================== */
    ['GET',  '/api/exercise/mock/config',  [ExerciseExamController::class, 'config']],
    ['POST', '/api/exercise/mock/start',   [ExerciseExamController::class, 'start']],
    ['GET',  '/api/exercise/mock/counts',  [ExerciseExamController::class, 'counts']],
    ['GET',  '/api/exercise/mock/paper',   [ExerciseExamController::class, 'paper']],
    ['POST', '/api/exercise/mock/save',    [ExerciseExamController::class, 'savePaper']],
    ['POST', '/api/exercise/mock/submit',  [ExerciseExamController::class, 'submitPaper']],
    ['GET',  '/api/exercise/mock/over',    [ExerciseExamController::class, 'over']],
    ['GET',  '/api/exercise/mock/review',  [ExerciseExamController::class, 'review']],
    ['POST', '/api/exercise/mock/logout',  [ExerciseExamController::class, 'logout']],

    /* ==================== 监考教师 ==================== */
    ['POST', '/api/teacher/login',         [TeacherAuthController::class, 'login']],
    ['POST', '/api/teacher/logout',        [TeacherAuthController::class, 'logout']],
    ['GET',  '/api/teacher/me',            [TeacherAuthController::class, 'me']],
    ['GET',  '/api/teacher/monitor',       [TeacherMonitorController::class, 'index']],
    ['POST', '/api/teacher/monitor/lock',  [TeacherMonitorController::class, 'lock']],
    ['POST', '/api/teacher/monitor/unlock', [TeacherMonitorController::class, 'unlock']],
    ['POST', '/api/teacher/monitor/submit', [TeacherMonitorController::class, 'submitAll']],
    // 单个考生收卷：与管理端 /api/admin/monitor/submit-one 对称。
    // 控制器方法早已存在（InvigilationService::submitOne 的注释明确写了「单人与全员
    // 必须是两个不同的入口」），但这里漏了注册 —— 于是教师端监考页行内「交卷」按钮
    // 只能落到上面的全员收卷，监考员想收 1 人却把全场判了分（不可撤销的数据事故）。
    ['POST', '/api/teacher/monitor/submit-one', [TeacherMonitorController::class, 'submitOne']],
    ['POST', '/api/teacher/monitor/lock-all', [TeacherMonitorController::class, 'lockAll']],
    ['POST', '/api/teacher/monitor/unlock-all', [TeacherMonitorController::class, 'unlockAll']],
    ['POST', '/api/teacher/monitor/over-all', [TeacherMonitorController::class, 'overAll']],

    /* ---------- 监考教师 - 考试管理 ---------- */
    ['GET',  '/api/teacher/exams',         [TeacherExamController::class, 'index']],
    ['GET',  '/api/teacher/exams/{id}',    [TeacherExamController::class, 'show']],
    ['POST', '/api/teacher/exams',         [TeacherExamController::class, 'save']],
    ['PUT',  '/api/teacher/exams/{id}',    [TeacherExamController::class, 'update']],
    ['POST', '/api/teacher/exams/{id}/start', [TeacherExamController::class, 'start']],
    ['POST', '/api/teacher/exams/{id}/open', [TeacherExamController::class, 'open']],
    ['POST', '/api/teacher/exams/{id}/generate', [TeacherExamController::class, 'generatePapers']],
    ['DELETE', '/api/teacher/exams/{id}',  [TeacherExamController::class, 'delete']],
    ['GET',  '/api/teacher/exams/{id}/students', [TeacherExamController::class, 'students']],
    ['GET',  '/api/teacher/exams/{id}/quiz-count', [TeacherExamController::class, 'checkQuizCount']],
    // A2 成绩与学情分析（按考试维度）
    ['GET',  '/api/teacher/exams/{id}/analysis', [TeacherExamController::class, 'analysis']],
    // A3 组卷多样化：手动选题题库检索、知识点清单
    ['GET',  '/api/teacher/quiz-search',   [TeacherExamController::class, 'quizSearch']],
    ['GET',  '/api/teacher/quiz-kps',      [TeacherExamController::class, 'quizKps']],
    ['GET',  '/api/teacher/scores',        [TeacherExamController::class, 'scores']],
    ['GET',  '/api/teacher/scores/export', [TeacherExamController::class, 'exportScores']],

    /* ==================== 管理后台 - 认证 ==================== */
    ['POST', '/api/admin/login',           [AdminAuthController::class, 'login']],
    ['POST', '/api/admin/logout',          [AdminAuthController::class, 'logout']],
    ['GET',  '/api/admin/me',              [AdminAuthController::class, 'me']],
    ['POST', '/api/admin/profile/avatar',  [AdminAuthController::class, 'saveAvatar']],
    ['PUT',  '/api/admin/profile/password', [AdminAuthController::class, 'changePassword']],

    /* ==================== 管理后台 - 概览 ==================== */
    ['GET',  '/api/admin/dashboard',       [DashboardController::class, 'index']],

    /* ---------- 管理员管理（systemAdmin） ---------- */
    ['GET',    '/api/admin/admins',        [AdminController::class, 'index']],
    ['GET',    '/api/admin/admins/{id}',   [AdminController::class, 'show']],
    ['POST',   '/api/admin/admins',        [AdminController::class, 'save']],
    ['PUT',    '/api/admin/admins/{id}',   [AdminController::class, 'update']],
    ['DELETE', '/api/admin/admins/{id}',   [AdminController::class, 'delete']],

    /* ---------- 教师管理 ---------- */
    ['GET',    '/api/admin/teachers',      [TeacherController::class, 'index']],
    ['GET',    '/api/admin/teachers/{id}', [TeacherController::class, 'show']],
    ['POST',   '/api/admin/teachers',      [TeacherController::class, 'save']],
    ['PUT',    '/api/admin/teachers/{id}', [TeacherController::class, 'update']],
    ['DELETE', '/api/admin/teachers/{id}', [TeacherController::class, 'delete']],

    /* ---------- 科目管理 ---------- */
    ['GET',    '/api/admin/subjects',      [SubjectController::class, 'index']],
    ['GET',    '/api/admin/subjects/{id}', [SubjectController::class, 'show']],
    ['POST',   '/api/admin/subjects',      [SubjectController::class, 'save']],
    ['PUT',    '/api/admin/subjects/{id}', [SubjectController::class, 'update']],
    ['DELETE', '/api/admin/subjects/{id}', [SubjectController::class, 'delete']],

    /* ---------- 题库管理 ---------- */
    ['GET',    '/api/admin/quizzes',       [QuizController::class, 'index']],
    ['GET',    '/api/admin/quizzes/{id}',  [QuizController::class, 'show']],
    ['POST',   '/api/admin/quizzes',       [QuizController::class, 'save']],
    ['PUT',    '/api/admin/quizzes/{id}',  [QuizController::class, 'update']],
    ['DELETE', '/api/admin/quizzes/{id}',  [QuizController::class, 'delete']],
    ['POST',   '/api/admin/quizzes/batch-delete', [QuizController::class, 'batchDelete']],
    ['GET',    '/api/admin/quizzes/clean/preview', [QuizController::class, 'cleanPreview']],
    ['POST',   '/api/admin/quizzes/clean', [QuizController::class, 'autoClean']],
    ['POST',   '/api/admin/quizzes/advanced-clean', [QuizController::class, 'doAdvancedClean']],

    /* ---------- 考试管理 ---------- */
    ['GET',    '/api/admin/exams',         [ExamController::class, 'index']],
    ['GET',    '/api/admin/exams/{id}',    [ExamController::class, 'show']],
    ['POST',   '/api/admin/exams',         [ExamController::class, 'save']],
    ['PUT',    '/api/admin/exams/{id}',    [ExamController::class, 'update']],
    ['DELETE', '/api/admin/exams/{id}',    [ExamController::class, 'delete']],
    ['GET',    '/api/admin/exams/{id}/quiz-count', [ExamController::class, 'checkQuizCount']],
    ['POST',   '/api/admin/exams/{id}/start', [ExamController::class, 'start']],
    ['POST',   '/api/admin/exams/{id}/open', [ExamController::class, 'open']],
    ['POST',   '/api/admin/exams/{id}/generate', [ExamController::class, 'generatePapers']],
    // A2 成绩与学情分析（按考试维度）
    ['GET',    '/api/admin/exams/{id}/analysis', [ExamController::class, 'analysis']],
    // A3 组卷多样化：手动选题题库检索、知识点清单
    ['GET',    '/api/admin/quiz-search',   [ExamController::class, 'quizSearch']],
    ['GET',    '/api/admin/quiz-kps',      [ExamController::class, 'quizKps']],

    /* ---------- 考试类别管理 ---------- */
    ['GET',    '/api/admin/exam-categories',       [ExamCategoryController::class, 'index']],
    ['POST',   '/api/admin/exam-categories',       [ExamCategoryController::class, 'save']],
    ['PUT',    '/api/admin/exam-categories/{id}',  [ExamCategoryController::class, 'update']],
    ['DELETE', '/api/admin/exam-categories/{id}',  [ExamCategoryController::class, 'delete']],

    /* ---------- 考生管理 ---------- */
    ['GET',    '/api/admin/students',      [StudentController::class, 'index']],
    ['GET',    '/api/admin/students/{id}', [StudentController::class, 'show']],
    ['POST',   '/api/admin/students',      [StudentController::class, 'save']],
    ['PUT',    '/api/admin/students/{id}', [StudentController::class, 'update']],
    ['DELETE', '/api/admin/students/{id}', [StudentController::class, 'delete']],
    ['POST',   '/api/admin/students/import', [StudentController::class, 'import']],
    ['GET',    '/api/admin/students/check-id', [StudentController::class, 'checkId']],

    /* ---------- 单位（年级）管理 ---------- */
    ['GET',    '/api/admin/grades',        [GradeController::class, 'index']],
    ['POST',   '/api/admin/grades',        [GradeController::class, 'save']],
    ['PUT',    '/api/admin/grades/{id}',   [GradeController::class, 'update']],
    ['DELETE', '/api/admin/grades/{id}',   [GradeController::class, 'delete']],

    /* ---------- 班级管理 ---------- */
    ['GET',    '/api/admin/classes',       [ClassController::class, 'index']],
    ['POST',   '/api/admin/classes',       [ClassController::class, 'save']],
    ['PUT',    '/api/admin/classes/{id}',  [ClassController::class, 'update']],
    ['DELETE', '/api/admin/classes/{id}',  [ClassController::class, 'delete']],

    /* ---------- 考场监控 ---------- */
    ['GET',  '/api/admin/monitor',         [MonitorController::class, 'index']],
    ['POST', '/api/admin/monitor/lock',    [MonitorController::class, 'lock']],
    ['POST', '/api/admin/monitor/unlock',  [MonitorController::class, 'unlock']],
    ['POST', '/api/admin/monitor/submit',  [MonitorController::class, 'submitAll']],
    ['POST', '/api/admin/monitor/submit-one', [MonitorController::class, 'submitOne']],
    ['POST', '/api/admin/monitor/lock-all', [MonitorController::class, 'lockAll']],
    ['POST', '/api/admin/monitor/unlock-all', [MonitorController::class, 'unlockAll']],
    ['POST', '/api/admin/monitor/over-all', [MonitorController::class, 'overAll']],

    /* ---------- 成绩管理 ---------- */
    ['GET',  '/api/admin/scores',          [ScoreController::class, 'index']],
    ['GET',  '/api/admin/scores/students', [ScoreController::class, 'listStudents']],
    ['POST', '/api/admin/scores/backup',   [ScoreController::class, 'backup']],
    ['GET',  '/api/admin/scores/export',   [ScoreController::class, 'exportCsv']],
    ['GET',  '/api/admin/scores/backups',  [ScoreController::class, 'backupList']],

    /* ---------- 新闻公告管理 ---------- */
    ['GET',    '/api/admin/news',          [NewsController::class, 'index']],
    ['GET',    '/api/admin/news/{id}',     [NewsController::class, 'show']],
    ['POST',   '/api/admin/news',          [NewsController::class, 'save']],
    ['PUT',    '/api/admin/news/{id}',     [NewsController::class, 'update']],
    ['DELETE', '/api/admin/news/{id}',     [NewsController::class, 'delete']],

    /* ---------- 站点配置（systemAdmin） ---------- */
    ['GET',  '/api/admin/config',          [ConfigController::class, 'index']],
    ['PUT',  '/api/admin/config',          [ConfigController::class, 'update']],

    /* ---------- 系统参数（systemAdmin，schema 驱动） ---------- */
    ['GET',  '/api/admin/settings',        [ConfigController::class, 'settings']],
    ['PUT',  '/api/admin/settings',        [ConfigController::class, 'updateSettings']],

    /* ---------- 上传 ---------- */
    ['POST', '/api/admin/upload/pic',      [UploadController::class, 'picUpload']],

    /* ---------- 系统管理（systemAdmin） ---------- */
    ['GET',  '/api/admin/system',          [SystemController::class, 'index']],
    ['POST', '/api/admin/system/initialize', [SystemController::class, 'initialize']],
    ['POST', '/api/admin/system/clear-exams', [SystemController::class, 'clearExams']],
    ['GET',  '/api/admin/logs', [SystemController::class, 'logs']],

    /* ==================== API 文档 / 指标 ==================== */
    ['GET', '/api/openapi.json', [\App\Controllers\DocController::class, 'openapi']],
    ['GET', '/api/docs',         [\App\Controllers\DocController::class, 'swagger']],
    ['GET', '/api/metrics',      [\App\Controllers\MetricsController::class, 'metrics']],

    /* ==================== 前端页面（SPA 外壳） ==================== */
    ['GET', '/',          [\App\Controllers\PageController::class, 'portal']],
    ['GET', '/portal',    [\App\Controllers\PageController::class, 'portal']],
    ['GET', '/student',   [\App\Controllers\PageController::class, 'student']],
    ['GET', '/exam',      [\App\Controllers\PageController::class, 'exam']],
    ['GET', '/exercise',  [\App\Controllers\PageController::class, 'exercise']],
    ['GET', '/teacher',   [\App\Controllers\PageController::class, 'teacher']],
    ['GET', '/admin',     [\App\Controllers\PageController::class, 'admin']],
];
