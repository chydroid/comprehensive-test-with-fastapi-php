/**
 * 考生个人中心入口 —— 需登录，带侧边栏外壳。
 */

import { applyInitialTheme } from '../core/bootstrap.js';
import { createRouter } from '../core/router.js';
import { createShell } from '../ui/shell.js';
import { appRoot } from '../core/dom.js';
import { emptyStated, notify } from '../ui/components.js';
import { studentSession } from '../core/student-session.js';

import { StudentLoginView, StudentRegisterView } from '../views/student/login.js';
import {
  StudentScoresView, StudentExamsView, StudentInfoView, StudentPasswordView,
} from '../views/student/center.js';
import { ExerciseView } from '../views/student/exercise.js';
import { MockSetupView, MockTakeView, MockReviewView } from '../views/student/mock.js';

const APP_NAME = '考生中心';

applyInitialTheme();
document.title = APP_NAME;

const NAV = [
  { key: 'exams',    label: '我的考试', icon: 'clipboard', group: 'exam' },
  { key: 'scores',   label: '我的成绩', icon: 'award', group: 'exam' },
  { key: 'exercise', label: '在线练习', icon: 'edit-3', group: 'practice' },
  { key: 'mock',     label: '模拟考试', icon: 'target', group: 'practice' },
  { key: 'info',     label: '个人资料', icon: 'user', group: 'account' },
  { key: 'password', label: '修改密码', icon: 'key', group: 'account' },
];

const GROUPS = [
  { key: 'exam', label: '考试' },
  { key: 'practice', label: '练习' },
  { key: 'account', label: '账号' },
];

const VIEWS = {
  exams: StudentExamsView,
  scores: StudentScoresView,
  exercise: ExerciseView,
  mock: MockSetupView,
  info: StudentInfoView,
  password: StudentPasswordView,
};

const outlet = document.createElement('div');
appRoot().append(outlet);
const router = createRouter({
  routes: [],
  outlet,
  notFound: (ctx) => emptyStated('页面不存在', { iconName: 'alert-circle', desc: `路径 ${ctx.path} 无效` }),
});

let shell = null;

studentSession.installHandlers();

/* ============================ 登录页 ============================ */
function renderAuth() {
  if (shell) { shell.destroy(); shell = null; }
  const page = StudentLoginView({
    router,
    onDone: () => startShell(),
  });
  router.setRoutes([
    { path: '/', view: () => page },
    { path: '/login', view: () => page },
    { path: '/register', view: () => StudentRegisterView({ router }) },
  ]);
  // shell.mount() 会清空 #app，把 router 的 outlet 从 DOM 上摘掉。
  // 回到登录页时必须重新挂回，否则路由会渲染进游离节点，整页空白。
  if (!outlet.isConnected) appRoot().append(outlet);
  router.start();
}

/* ============================ 外壳 ============================ */
function renderErrorNode(err, label) {
  const s = document.createElement('section');
  s.className = 'alert alert-danger';
  s.style.margin = 'var(--sp-6)';
  s.textContent = `「${label || '页面'}」加载失败：${err?.message || err}`;
  return s;
}

function startShell() {
  if (!studentSession.isLoggedIn) return renderAuth();

  const s = studentSession.student || {};
  shell = createShell({
    brandName: APP_NAME,
    brandSub: '考生',
    brandMark: '考',
    nav: NAV,
    groups: GROUPS,
    can: () => true,
    user: { name: s.stu_name || '考生', role: s.grade_id || '考生', avatar: '' },
    profileKey: 'info',
    onNavigate: (key) => router.navigate(key === 'exams' ? '/' : `/${key}`),
    onLogout: async () => {
      await studentSession.logout();
      notify.info('已退出登录');
      renderAuth();
    },
  }).init();

  shell.mount();

  // 模拟考试三个视图是门户入口（/portal）与考生中心（/student）共用的，
  // 它们内部统一跳转到 /exercise/mock*。门户注册的就是这组路径，考生中心
  // 若只注册 /mock*，跳转会落到「页面不存在」。这里补注册同义别名。
  const mockRoute = (path, title, make) => ({
    path,
    view: async (ctx) => {
      shell.setActive('mock');
      shell.setTitle(title);
      try { shell.setContent(await make({ router, query: ctx.query })); } catch (e) { shell.setContent(renderErrorNode(e, title)); }
      return undefined;
    },
  });

  const routes = [
    ...NAV.filter((n) => VIEWS[n.key]).map((n) => ({
      path: n.key === 'exams' ? '/' : `/${n.key}`,
      view: async (ctx) => {
        shell.setActive(n.key);
        shell.setTitle(n.label);
        shell.setActions([]);
        try {
          const node = await VIEWS[n.key]({ router, query: ctx.query, params: ctx.params });
          shell.setContent(node);
        } catch (e) {
          console.error('[student] view render failed:', n.key, e);
          shell.setContent(renderErrorNode(e, n.label));
        }
        // 返回 undefined：视图已自行挂载到 shell.content，勿塞进游离 outlet
        return undefined;
      },
      meta: { title: n.label },
    })),
    // 模拟考试答题页（独立全屏视图）
    {
      path: '/mock/take',
      view: async (ctx) => {
        shell.setActive('mock');
        shell.setTitle('模拟考试');
        try { shell.setContent(await MockTakeView({ router, query: ctx.query })); }
        catch (e) { shell.setContent(renderErrorNode(e, '模拟考试')); }
        return undefined;
      },
    },
    {
      path: '/mock/review',
      view: async (ctx) => {
        shell.setActive('mock');
        shell.setTitle('错题回顾');
        try { shell.setContent(await MockReviewView({ router, query: ctx.query })); }
        catch (e) { shell.setContent(renderErrorNode(e, '错题回顾')); }
        return undefined;
      },
    },
    mockRoute('/exercise/mock', '模拟考试', MockSetupView),
    mockRoute('/exercise/mock/take', '模拟考试', MockTakeView),
    mockRoute('/exercise/mock/review', '错题回顾', MockReviewView),
  ];

  router.setRoutes(routes);
  router.start();
}

/* ============================ 引导 ============================ */
(async function boot() {
  await studentSession.boot(router);
  if (studentSession.isLoggedIn) startShell();
  else renderAuth();
})();
