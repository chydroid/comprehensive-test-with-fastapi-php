/**
 * 考场（正式考试）视图：登录入场 + 等待开考 + 答题（复用 exam-runner）。
 *
 * 流程：入场（准考证号 + 密码 + 考场口令，入场窗口由后台「系统设置 → 考试规则」控制）
 *   → 等待室（轮询 /api/exam/status，到点/监考开考后自动进入答题）
 *   → 答题（exam-runner）。
 */

import { el, clear, mount } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import { logoMark } from '../../core/logo.js';
import { button, card, field, input, notify, alertBox } from '../../ui/components.js';
import { withLoading } from '../../core/bootstrap.js';
import { examApi } from '../../api/index.js';
import { setCsrfToken } from '../../core/http.js';
import { createExamRunner } from '../exam-runner.js';
import { fmtDateTime } from '../../core/format.js';
import {
  loadAppSettings, appSettingInt, entryWindowText,
} from '../../core/app-settings.js';

/**
 * 考场入口：准考证号 + 密码 + 考场口令。
 * query.exam_id 由个人中心「进入考场」带入。
 */
export function ExamLoginView({ router, query }) {
  const root = el('div.login-page');
  const errSlot = el('div');

  const examIdIn = input({ name: 'exam_id', value: query?.exam_id || '', type: 'number', required: true, placeholder: '考试编号' });
  const stuIdIn = input({ name: 'stu_id', required: true, placeholder: '准考证号', autocomplete: 'username' });
  const pwdIn = input({ name: 'password', type: 'password', required: true, autocomplete: 'current-password' });
  const examPwdIn = input({ name: 'exam_pwd', required: true, placeholder: '由监考教师提供', maxlength: 20 });

  const form = el('form', { class: 'login-form', on: { submit: (ev) => { ev.preventDefault(); submit(); } } }, [
    field('考试编号', examIdIn, { required: true }),
    field('准考证号', stuIdIn, { required: true }),
    field('登录密码', pwdIn, { required: true }),
    field('考场口令', examPwdIn, { required: true }),
    errSlot,
    button('进入考场', { variant: 'primary', type: 'submit', block: true, iconName: 'log-in' }),
  ]);

  async function submit() {
    clear(errSlot);
    const res = await withLoading(form, () => examApi.login({
      exam_id: Number(examIdIn.value),
      stu_id: stuIdIn.value.trim(),
      password: pwdIn.value,
      exam_pwd: examPwdIn.value.trim(),
    }));
    if (!res.ok) {
      errSlot.append(alertBox(res.error?.message || '进入考场失败', { type: 'danger' }));
      return;
    }
    // 独立考场入口（/exam）不经过个人中心登录，会话里原本没有安全令牌，
    // 后端在登录响应中补发，这里必须立刻注入，否则保存答案 / 交卷会被 419 拦截。
    if (res.result.csrf_token) setCsrfToken(res.result.csrf_token);
    const warn = (res.result.warnings || []).filter(Boolean);
    if (warn.length) warn.forEach((w) => notify.warning(String(w)));
    notify.success(`欢迎你，${res.result.stu_name}`);
    router.navigate(`/take?exam_id=${res.result.exam.id}`);
  }

  // 入场窗口文案来自后台设置：先用默认值渲染，取到配置后校正
  const windowHint = el('p.muted', { text: entryWindowText() });
  loadAppSettings().then(() => { windowHint.textContent = entryWindowText(); });

  root.append(el('div.login-aurora'));
  root.append(el('div.login-panel', {}, [
    el('div.login-brand', {}, [
      logoMark({ height: 38 }),
      el('div', {}, [
        el('h2', { text: '进入考场' }),
        windowHint,
      ]),
    ]),
    form,
    el('div.login-foot', {}, [
      button('返回个人中心', { variant: 'link', size: 'sm', iconName: 'arrow-left', onClick: () => location.assign('/student') }),
    ]),
  ]));

  // 已入场（会话有效）→ 直接回到考场，避免重复登录
  (async () => {
    const st = await examApi.status().catch(() => null);
    if (st && st.phase) router.navigate(`/take?exam_id=${examIdIn.value || ''}`);
  })();

  return root;
}

/* ============================ 等待室 + 答题中 ============================ */
export function ExamTakeView({ router, query }) {
  const examId = Number(query?.exam_id || 0);
  const host = el('div.stack');

  let runner = null;
  let pollTimer = null;
  let started = false;
  let beforeUnload = null;

  const stopPoll = () => { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } };

  const onBeforeUnload = (e) => {
    // 仅在答题进行中阻止误关闭；等待室与已结束不拦截
    if (runner && !runner.getState().finished) { e.preventDefault(); e.returnValue = ''; }
  };

  async function boot() {
    // 注意：http 层已解包信封——成功直接返回 data，失败抛错
    const data = await examApi.status().catch(() => null);
    if (!data || !data.phase) {
      // 未入场 / 会话失效 → 回考场入口
      router.navigate('/');
      return;
    }
    // 刷新答题页后模块内的 csrfToken 会重置为空（不像登录那样由登录响应注入），
    // 而本接口每次都随响应带回令牌——不注入的话保存 / 交卷会被 419 拦截。
    if (data.csrf_token) setCsrfToken(data.csrf_token);
    if (data.phase === 'answering') { startRunner(); return; }
    if (data.phase === 'submitted' || data.phase === 'closed') { renderClosed(data); return; }
    renderWaiting(data.exam);
  }

  function renderWaiting(exam) {
    mount(host, el('div', {}, el('div.card', {}, el('div.card-body.text-center', {}, [
      el('div.mb-2', {}, [icon('clock', { size: 34 })]),
      el('h2', { text: exam?.exam_name || '考场' }),
      el('p.muted.mt-1', { text: '你已进入考场，正在等待开考…' }),
      el('p.fs-sm.c-secondary', { text: exam?.exam_start ? `开考时间：${fmtDateTime(exam.exam_start)}` : '' }),
      el('p.fs-sm.c-secondary.mt-1', { text: '开考后本页会自动进入答题界面，请保持本页开启。' }),
      el('div.mt-3', {}, [button('刷新状态', { variant: 'secondary', size: 'sm', iconName: 'refresh-cw', onClick: () => boot() })]),
    ]))));

    stopPoll();
    const pollSeconds = Math.max(2, appSettingInt('waiting_poll_seconds', 4));
    let polling = false;
    pollTimer = setInterval(async () => {
      // 上一次请求还没回来就跳过本次 tick，避免慢网下请求堆积
      if (polling) return;
      polling = true;
      try {
        const r = await examApi.status().catch(() => null);
        // 会话已失效 / 被清理（phase 为空）：结束空转并回考场入口。
        // 以前这里靠 http.js 的 401 全局跳转负责，但该接口已从全局跳转白名单
        // 中排除（否则入口页会因「重渲染→再探测→再跳转」自激成请求风暴），
        // 因此退场逻辑必须由本视图自己承担。
        if (!r || !r.phase) { stopPoll(); router.navigate('/'); return; }
        if (r.csrf_token) setCsrfToken(r.csrf_token);
        if (r.phase === 'answering') { stopPoll(); startRunner(); }
        else if (r.phase === 'submitted' || r.phase === 'closed') { stopPoll(); renderClosed(r); }
      } finally {
        polling = false;
      }
    }, pollSeconds * 1000);
  }

  function renderClosed(data) {
    stopPoll();
    mount(host, el('div', {}, el('div.card', {}, el('div.card-body.text-center', {}, [
      el('div.mb-2', {}, [icon('check-circle', { size: 34 })]),
      el('h2', { text: data?.exam?.exam_name || '考试' }),
      el('p.muted.mt-1', { text: data?.phase === 'submitted' ? '你已交卷，本场考试结束。' : '本场考试已结束。' }),
      el('div.mt-3', {}, [button('返回个人中心', { variant: 'primary', iconName: 'arrow-left', onClick: () => location.assign('/student') })]),
    ]))));
  }

  function startRunner() {
    if (started) return;
    started = true;
    stopPoll();

    runner = createExamRunner({
      mode: 'exam',
      title: '正式考试',
      exitUrl: '/student',
      loadPaper: async (paperId) => examApi.paper({ paper_id: paperId }),
      saveAnswer: async (paperId, answer) => examApi.save({ paper_id: paperId, stu_key: answer }),
      submit: async () => examApi.submit({}),
      loadReview: async () => examApi.answer(),
      onExit: () => examApi.logout(),
    });

    (async () => {
      const res = await examApi.paper({ paper_id: 1 }).catch(() => null);
      if (res && res.exam_end) runner.examEnd = res.exam_end;
      mount(host, runner.node);
      runner.start();
    })();
  }

  beforeUnload = onBeforeUnload;
  window.addEventListener('beforeunload', beforeUnload);

  void examId;
  boot();

  // 视图卸载时清理：轮询、beforeunload，以及答题引擎内部的 1 秒倒计时。
  // 漏掉 runner.dispose() 会让倒计时定时器在离开答题页后继续运行，
  // 归零时还会对已卸载的试卷触发一次自动交卷请求。
  return {
    node: host,
    dispose: () => {
      stopPoll();
      window.removeEventListener('beforeunload', beforeUnload);
      runner?.dispose?.();
      runner = null;
    },
  };
}
