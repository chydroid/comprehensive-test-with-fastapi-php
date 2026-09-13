/**
 * 考生个人中心入口 —— 需登录，带侧边栏外壳。
 */

import { applyInitialTheme } from '../core/bootstrap.js';
import { createRouter } from '../core/router.js';
import { createShell } from '../ui/shell.js';
import { el, clear } from '../core/dom.js';
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
  const el = appRoot();
  el.append(page);
  router.setRoutes([
    { path: '/login', view: () => page },
    { path: '/register', view: () => StudentRegisterView({ router }) },
  ]);
  router.start();
}

/* ============================ 外壳 ============================ */
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

  const routes = [
    ...NAV.filter((n) => VIEWS[n.key]).map((n) => ({
      path: n.key === 'exams' ? '/' : `/${n.key}`,
      view: (ctx) => {
        shell.setActive(n.key);
        shell.setTitle(n.label);
        shell.setActions([]);
        return VIEWS[n.key]({ router, query: ctx.query, params: ctx.params });
      },
      meta: { title: n.label },
    })),
    // 模拟考试答题页（独立全屏视图）
    {
      path: '/mock/take',
      view: (ctx) => {
        shell.setActive('mock');
        shell.setTitle('模拟考试');
        return MockTakeView({ router, query: ctx.query });
      },
    },
    {
      path: '/mock/review',
      view: (ctx) => {
        shell.setActive('mock');
        shell.setTitle('错题回顾');
        return MockReviewView({ router, query: ctx.query });
      },
    },
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
