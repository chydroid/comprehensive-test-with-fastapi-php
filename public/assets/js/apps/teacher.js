/**
 * 教师端入口 —— 登录 → 考试管理 / 监考 / 成绩。
 */

import { applyInitialTheme, bootstrapSession, installErrorHandlers } from '../core/bootstrap.js';
import { createRouter } from '../core/router.js';
import { createShell } from '../ui/shell.js';
import { el, clear, appRoot } from '../core/dom.js';
import { renderLogin } from '../ui/login.js';
import { emptyStated, notify } from '../ui/components.js';
import { teacherApi } from '../api/index.js';
import { setCsrfToken } from '../core/http.js';

import {
  TeacherExamsView, TeacherMonitorView, TeacherScoresView,
} from '../views/teacher/index.js';

const APP_NAME = '教师工作台';

const NAV = [
  { key: 'exams',   label: '考试管理', icon: 'clipboard', group: 'exam' },
  { key: 'monitor', label: '监考中心', icon: 'eye',       group: 'exam' },
  { key: 'scores',  label: '成绩查询', icon: 'award',     group: 'exam' },
];

const GROUPS = [{ key: 'exam', label: '考务' }];

const VIEWS = {
  exams: TeacherExamsView,
  monitor: TeacherMonitorView,
  scores: TeacherScoresView,
};

applyInitialTheme();
document.title = APP_NAME;

const outlet = document.createElement('div');
appRoot().append(outlet);
const router = createRouter({
  routes: [],
  outlet,
  notFound: (ctx) => emptyStated('页面不存在', { iconName: 'alert-circle', desc: `路径 ${ctx.path} 无效` }),
});

let shell = null;
let session = null;

installErrorHandlers({
  onUnauthorized: () => {
    session = null;
    if (shell) { shell.destroy(); shell = null; }
    renderLoginPage();
    notify.warning('登录状态已失效，请重新登录');
  },
  onForbidden: (p) => notify.warning(p?.message || '没有权限执行该操作'),
});

function renderLoginPage() {
  const page = renderLogin({
    title: '教师工作台',
    subtitle: '请使用教师账号登录',
    accent: 'teacher',
    brandMark: '师',
    fields: [
      { name: 'username', label: '教师姓名', placeholder: '请输入教师姓名', autocomplete: 'username' },
      { name: 'password', label: '密码', type: 'password', placeholder: '请输入密码', autocomplete: 'current-password' },
    ],
    onSubmit: async (values) => {
      try {
        const data = await teacherApi.login({ username: values.username, password: values.password });
        if (data?.csrf_token) setCsrfToken(data.csrf_token);
        session = data;
        notify.success(`欢迎，${data?.teacher?.tea_name || ''}`);
        startShell();
      } catch (e) {
        notify.error(e?.message || '登录失败，请检查账号或密码后重试');
      }
    },
  });
  router.setRoutes([
    { path: '/', view: () => page },
    { path: '/login', view: () => page },
  ]);
  router.start();
}

function startShell() {
  const t = session?.teacher || {};
  shell = createShell({
    brandName: APP_NAME,
    brandSub: '教师',
    brandMark: '师',
    nav: NAV,
    groups: GROUPS,
    can: () => true,
    user: { name: t.tea_name || '教师', role: '监考教师', avatar: t.avatar || '' },
    profileKey: null,
    onNavigate: (key) => router.navigate(key === 'exams' ? '/' : `/${key}`),
    onLogout: async () => {
      try { await teacherApi.logout(); } catch (_) {}
      session = null;
      if (shell) { shell.destroy(); shell = null; }
      renderLoginPage();
      notify.info('已退出登录');
    },
  }).init();
  shell.mount();

  router.setRoutes(NAV.filter((n) => VIEWS[n.key]).map((n) => ({
    path: n.key === 'exams' ? '/' : `/${n.key}`,
    view: (ctx) => {
      shell.setActive(n.key);
      shell.setTitle(n.label);
      shell.setActions([]);
      return VIEWS[n.key]({ router, query: ctx.query, params: ctx.params });
    },
    meta: { title: n.label },
  })));
  router.start();
}

(async function boot() {
  const restored = await bootstrapSession(() => teacherApi.me());
  if (restored?.csrf_token) setCsrfToken(restored.csrf_token);
  if (restored?.logged_in) { session = restored; startShell(); }
  else renderLoginPage();
})();
