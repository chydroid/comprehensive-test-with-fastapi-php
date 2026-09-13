/**
 * 管理后台 —— 应用入口
 * 职责：会话引导 → 登录页 / 后台外壳 → 路由注册 → 权限守卫
 */

import { applyInitialTheme, bootstrapSession, installErrorHandlers } from '../core/bootstrap.js';
import { createRouter } from '../core/router.js';
import { createShell } from '../ui/shell.js';
import { renderLogin } from '../ui/login.js';
import { mount, clear, appRoot } from '../core/dom.js';
import { notify, button, alertBox, emptyStated } from '../ui/components.js';
import { adminApi } from '../api/index.js';
import { setCsrfToken } from '../core/http.js';

/* ---------- 路由表 ---------- */
import { DashboardView } from '../views/admin/dashboard.js';
import { QuizView } from '../views/admin/quiz.js';
import { ExamView } from '../views/admin/exam.js';
import { StudentView } from '../views/admin/student.js';
import { MonitorView } from '../views/admin/monitor.js';
import { ScoreView } from '../views/admin/score.js';
import { ConfigView, SystemView, ProfileView } from '../views/admin/system.js';
import {
  SubjectView, CategoryView, GradeView, ClassView, TeacherView, AdminView, NewsView,
} from '../views/admin/basics.js';

const APP_NAME = '考试管理后台';

/* ---------- 导航定义（含权限点与分组） ---------- */
const GROUPS = [
  { key: 'overview', label: '总览' },
  { key: 'exam', label: '考务' },
  { key: 'bank', label: '题库' },
  { key: 'people', label: '人员' },
  { key: 'system', label: '系统' },
];

const NAV = [
  { key: 'dashboard', label: '仪表盘', icon: 'dashboard', perm: 'dashboard.view', group: 'overview' },

  { key: 'exams',    label: '考试管理', icon: 'clipboard', perm: 'exam.view', group: 'exam' },
  { key: 'monitor',  label: '在线监考', icon: 'eye',       perm: 'monitor.view', group: 'exam' },
  { key: 'scores',   label: '成绩管理', icon: 'award',     perm: 'score.view', group: 'exam' },
  { key: 'categories', label: '考试类别', icon: 'flag',    perm: 'category.view', group: 'exam' },

  { key: 'quizzes',  label: '题库管理', icon: 'database',  perm: 'quiz.view', group: 'bank' },
  { key: 'subjects', label: '考试科目', icon: 'book',      perm: 'subject.view', group: 'bank' },

  { key: 'students', label: '考生管理', icon: 'users',     perm: 'student.view', group: 'people' },
  { key: 'teachers', label: '教师管理', icon: 'teacher',   perm: 'teacher.view', group: 'people' },
  { key: 'grades',   label: '单位管理', icon: 'school',    perm: 'grade.view', group: 'people' },
  { key: 'classes',  label: '班级管理', icon: 'layers',    perm: 'class.view', group: 'people' },

  { key: 'news',     label: '考试公告', icon: 'bell',      perm: 'news.view', group: 'system' },
  { key: 'admins',   label: '管理员',   icon: 'shield',    perm: 'admin.manage', group: 'system' },
  { key: 'config',   label: '站点配置', icon: 'settings',  perm: 'system.config', group: 'system' },
  { key: 'system',   label: '系统维护', icon: 'sliders',   perm: 'system.manage', group: 'system' },

  { key: 'profile',  label: '个人设置', icon: 'user',      group: 'system', hidden: true },
];

/* ---------- 视图工厂映射 ---------- */
const VIEWS = {
  dashboard: DashboardView,
  quizzes: QuizView,
  exams: ExamView,
  students: StudentView,
  monitor: MonitorView,
  scores: ScoreView,
  config: ConfigView,
  system: SystemView,
  profile: ProfileView,
  subjects: SubjectView,
  categories: CategoryView,
  grades: GradeView,
  classes: ClassView,
  teachers: TeacherView,
  admins: AdminView,
  news: NewsView,
};

/* ============================ 启动 ============================ */
applyInitialTheme();
document.title = APP_NAME;

const router = createRouter({
  routes: [],
  outlet: document.createElement('div'),  // 外壳接管渲染，此处仅作占位
  notFound: (t) => emptyStated('页面不存在', { iconName: 'alert-circle', desc: `路径 ${t.path} 无效` }),
});

let shell = null;
let session = null;

/** 权限判定 */
function can(perm) {
  if (!session) return false;
  const perms = session.permissions || [];
  return perms.includes('*') || perms.includes(perm);
}

installErrorHandlers({
  onUnauthorized: () => {
    // 登录态失效：回到登录页
    session = null;
    if (shell) { shell.destroy(); shell = null; }
    renderLoginPage();
    notify.warning('登录状态已失效，请重新登录');
  },
  onForbidden: (payload) => notify.warning(payload?.message || '没有权限执行该操作'),
});

/* ============================ 登录页 ============================ */
function renderLoginPage() {
  const page = renderLogin({
    title: '考试管理后台',
    subtitle: '请使用管理员账号登录',
    accent: 'shield',
    brandMark: '管',
    fields: [
      { name: 'username', label: '账号', placeholder: '请输入管理员账号', autocomplete: 'username' },
      { name: 'password', label: '密码', type: 'password', placeholder: '请输入密码', autocomplete: 'current-password' },
    ],
    onSubmit: async (values) => {
      const data = await adminApi.login({ username: values.username, password: values.password });
      if (data?.csrf_token) setCsrfToken(data.csrf_token);
      session = data;
      notify.success(`欢迎回来，${data?.admin?.username || ''}`);
      startShell();
    },
  });

  const root = appRoot();
  root.append(page);
}

/* ============================ 后台外壳 ============================ */
function startShell() {

  shell = createShell({
    brandName: APP_NAME,
    brandSub: 'fastapi-php',
    brandMark: '管',
    nav: NAV,
    groups: GROUPS,
    can,
    user: {
      name: session?.admin?.username || '管理员',
      role: ROLE_LABEL(session?.admin?.admin_power),
      avatar: session?.admin?.avatar || '',
    },
    onNavigate: (key) => {
      if (key === 'profile') router.navigate('/profile');
      else router.navigate(`/${key}`);
    },
    onLogout: async () => {
      try { await adminApi.logout(); } catch (_) {}
      session = null;
      if (shell) { shell.destroy(); shell = null; }
      renderLoginPage();
      notify.info('已安全退出');
    },
  }).init();

  shell.mount();

  // 注册全部路由
  router.setRoutes(NAV
    .filter((n) => VIEWS[n.key])
    .map((n) => ({
      path: n.key === 'dashboard' ? '/' : `/${n.key}`,
      view: (ctx) => guardView(n, VIEWS[n.key], ctx),
      meta: { title: n.label, perm: n.perm },
    })));

  router.start();
}

/** 路由守卫：无权限则渲染 403 提示 */
function guardView(item, view, ctx) {
  if (item.perm && !can(item.perm)) {
    return emptyStated('无权访问', {
      iconName: 'lock',
      desc: '当前账号没有访问该模块的权限，请联系超级管理员分配。',
      action: button('返回仪表盘', { variant: 'secondary', onClick: () => router.navigate('/') }),
    });
  }
  shell.setActive(item.key);
  shell.setTitle(item.label);
  shell.setActions([]);
  return view({ router, can, query: ctx.query, params: ctx.params, shell, session, item });
}

function ROLE_LABEL(role) {
  return { systemAdmin: '超级管理员', testAdmin: '考务管理员', quizOperator: '题库维护', quizAdder: '题库录入' }[role] || '管理员';
}

/* ============================ 引导 ============================ */
(async function boot() {
  const restored = await bootstrapSession(() => adminApi.me());
  if (restored?.csrf_token) setCsrfToken(restored.csrf_token);

  if (restored?.logged_in) {
    session = restored;
    startShell();
  } else {
    renderLoginPage();
  }
})();
