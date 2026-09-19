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
import { createAnalysisView } from '../views/analysis.js';

const APP_NAME = '教师工作台';

/** A2 成绩与学情分析：复用共享视图，注入教师端数据源 */
const TeacherAnalysisView = createAnalysisView({
  fetchExams: (params) => teacherApi.scores(params),
  fetchAnalysis: (id) => teacherApi.examAnalysis(id),
  title: '成绩分析',
  subtitle: '班级、题型、难度与知识点多维度学情诊断',
});

const NAV = [
  { key: 'exams',   label: '考试管理', icon: 'clipboard', group: 'exam' },
  { key: 'monitor', label: '监考中心', icon: 'eye',       group: 'exam' },
  { key: 'scores',  label: '成绩查询', icon: 'award',     group: 'exam' },
  { key: 'analysis', label: '成绩分析', icon: 'bar-chart-2', group: 'exam' },
];

const GROUPS = [{ key: 'exam', label: '考务' }];

const VIEWS = {
  exams: TeacherExamsView,
  monitor: TeacherMonitorView,
  scores: TeacherScoresView,
  analysis: TeacherAnalysisView,
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
  // shell.mount() 会清空 #app，把 router 的 outlet 从 DOM 上摘掉。
  // 回到登录页时必须重新挂回，否则路由会渲染进游离节点，整页空白。
  if (!outlet.isConnected) appRoot().append(outlet);
  router.start();
}

/** 视图渲染失败兜底（渲染到可见内容区，避免错误被塞进游离 outlet 而看不见） */
function renderErrorNode(err, label) {
  const s = document.createElement('section');
  s.className = 'alert alert-danger';
  s.style.margin = 'var(--sp-6)';
  s.textContent = `「${label || '页面'}」加载失败：${err?.message || err}`;
  return s;
}

function startShell() {
  const t = session?.teacher || {};
  shell = createShell({
    brandName: APP_NAME,
    brandSub: '教师',
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
    view: async (ctx) => {
      shell.setActive(n.key);
      shell.setTitle(n.label);
      shell.setActions([]);
      try {
        const node = await VIEWS[n.key]({ router, query: ctx.query, params: ctx.params });
        shell.setContent(node);
      } catch (e) {
        console.error('[teacher] view render failed:', n.key, e);
        shell.setContent(renderErrorNode(e, n.label));
      }
      // 返回 undefined：告知 router 视图已自行挂载到 shell.content，勿塞进游离 outlet
      return undefined;
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
