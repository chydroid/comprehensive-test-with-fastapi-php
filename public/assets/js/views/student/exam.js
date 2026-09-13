/**
 * 考场（正式考试）视图：登录入场 + 答题（复用 exam-runner）。
 */

import { el, clear, mount } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import { button, card, field, input, notify, alertBox } from '../../ui/components.js';
import { withLoading } from '../../core/bootstrap.js';
import { examApi } from '../../api/index.js';
import { createExamRunner } from '../exam-runner.js';

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
    const warn = (res.result.warnings || []).filter(Boolean);
    if (warn.length) warn.forEach((w) => notify.warning(String(w)));
    notify.success(`欢迎你，${res.result.stu_name}`);
    router.navigate(`/exam/take?exam_id=${res.result.exam.id}`);
  }

  root.append(el('div.login-aurora'));
  root.append(el('div.login-panel', {}, [
    el('div.login-brand', {}, [
      el('div.brand-mark', {}, [icon('shield-check', { size: 22 })]),
      el('div', {}, [
        el('h2', { text: '进入考场' }),
        el('p.muted', { text: '请确认考试信息后凭考场口令入场' }),
      ]),
    ]),
    form,
    el('div.login-foot', {}, [
      button('返回个人中心', { variant: 'link', size: 'sm', iconName: 'arrow-left', onClick: () => router.navigate('/student') }),
    ]),
  ]));
  return root;
}

/* ============================ 答题中 ============================ */
export function ExamTakeView({ router, query }) {
  const examId = Number(query?.exam_id || 0);

  const runner = createExamRunner({
    mode: 'exam',
    title: '正式考试',
    exitUrl: '/student',
    loadPaper: async (paperId) => {
      const res = await examApi.paper({ paper_id: paperId });
      return res;
    },
    saveAnswer: async (paperId, answer) => examApi.save({ paper_id: paperId, stu_key: answer }),
    submit: async () => examApi.submit({}),
    loadReview: async () => {
      const res = await examApi.answer();
      return res;
    },
    // 退出考场时清除服务端考场会话，防止他人复用该终端直接进入
    onExit: () => examApi.logout(),
  });

  // 启动时先拿第一题，以补全标题与结束时间
  const host = el('div.stack');
  (async () => {
    const res = await examApi.paper({ paper_id: 1 }).catch(() => null);
    if (res && res.exam_end) runner.examEnd = res.exam_end;
    mount(host, runner.node);
    runner.start();
  })();

  window.addEventListener('beforeunload', (e) => {
    if (!runner.getState().finished) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  void examId;
  return host;
}
