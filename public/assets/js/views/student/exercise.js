/**
 * 在线练习视图：按「科目 + 题型」随机抽题，逐题作答并即时校验。
 *
 * 注意：练习与正式考试默认互不影响；仅当后台「系统设置 → 模拟考试与练习」
 * 关闭「正式考试期间开放在线练习」时，后端才会在开考期间锁定练习，
 * 此时 practice_paused = true，前端据此给出明确提示而非静默失败。
 */

import { el, clear, mount } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import {
  button, card, badge, field, select, notify, emptyStated, alertBox, segmented,
} from '../../ui/components.js';
import { withLoading } from '../../core/bootstrap.js';
import { exerciseApi } from '../../api/index.js';
import { QTYPE } from '../exam-runner.js';

export function ExerciseView() {
  const root = el('div.stack');
  const headSlot = el('div.page-head');
  // 练习进行中的「顶部折叠条」：点击可展开/收起科目与题型选择
  const compactBar = el('div.ex-compact', { role: 'button', tabindex: '0', 'aria-expanded': 'false' });
  const pickerSlot = el('div');
  const noticeSlot = el('div');
  const questionSlot = el('div');

  const state = {
    subjects: [],
    subjId: 0,
    quizClass: '',
    types: {},
    question: null,
    counts: {},
    pickerExpanded: false,
    // 服务端下发的暂停结论与原因（'' / 'self_in_exam' / 'exam_ongoing'）
    paused: false,
    pauseReason: '',
  };

  root.append(headSlot, compactBar, pickerSlot, noticeSlot, questionSlot);

  /* ---------- 顶部折叠条：折叠 / 展开逻辑 ---------- */
  function subjectName() {
    const s = state.subjects.find((x) => x.id === state.subjId);
    return s ? s.subj_name : '未选择科目';
  }
  function typeLabel() {
    return (QTYPE[state.quizClass] && QTYPE[state.quizClass].label) || state.quizClass || '未选择题型';
  }
  function updateCompactText() {
    clear(compactBar);
    compactBar.append(
      el('span.ex-compact-info', { text: `科目：${subjectName()}　题型：${typeLabel()}` }),
      el('span.ex-compact-toggle', {}, [
        icon('chevronDown', { size: 16 }),
        el('span.ex-compact-label', { text: state.pickerExpanded ? '收起' : '展开' }),
      ]),
    );
  }
  function updateLayout() {
    const practicing = !!state.question;
    compactBar.style.display = practicing ? '' : 'none';
    pickerSlot.style.display = (practicing && !state.pickerExpanded) ? 'none' : '';
    compactBar.classList.toggle('is-open', state.pickerExpanded);
    compactBar.setAttribute('aria-expanded', String(state.pickerExpanded));
    updateCompactText();
  }
  function togglePicker() {
    if (!state.question) return;
    state.pickerExpanded = !state.pickerExpanded;
    updateLayout();
  }
  // 进入练习（已抽题）：自动把选择区折叠成顶部一行
  function enterPractice() {
    state.pickerExpanded = false;
    updateLayout();
  }
  // 自动跳转计时器（跨题目共享，避免上一题的定时器在换题后再次触发 draw）
  let advanceTimer = null;
  function cancelAdvance() {
    if (advanceTimer) { clearTimeout(advanceTimer); advanceTimer = null; }
  }
  compactBar.addEventListener('click', togglePicker);
  compactBar.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); togglePicker(); }
  });

  /* ---------- 头部 ---------- */
  mount(headSlot, [
    el('div', {}, [
      el('h1.page-title', { text: '在线练习' }),
      el('p.page-sub', { text: '随机抽题、即时判分、反复练习' }),
    ]),
    el('div.page-head-actions', {}, [
      button('换一题', { variant: 'primary', size: 'sm', iconName: 'shuffle', onClick: () => draw() }),
    ]),
  ]);

  /* ---------- 异步初始化 ---------- */
  (async () => {
    const res = await withLoading(pickerSlot, () => exerciseApi.list({}));
    if (!res.ok) return;
    state.subjects = res.result.subjects || [];
    // practice_paused 是服务端给出的「策略结论」，
    // has_ongoing_exam 只是「当前有正式考试在进行」的事实 —— 二者并不等价
    // （本人在考、或开关关闭时才真正暂停），必须用前者决定是否禁用练习。
    state.paused = !!res.result.practice_paused;
    state.pauseReason = String(res.result.pause_reason || '');
    renderPicker();
    renderNotice();
    if (state.paused) return;
    if (state.subjects.length) {
      state.subjId = state.subjects[0].id;
      await loadTypes();
      renderPicker();
    }
  })();

  async function loadTypes() {
    const res = await exerciseApi.list({ subj_id: state.subjId }).catch(() => null);
    if (res && res.types) state.types = res.types;
  }

  function renderPicker() {
    clear(pickerSlot);
    const subjSel = select(
      state.subjects.map((s) => ({ value: String(s.id), label: s.subj_name })),
      { name: 'subj_id', value: String(state.subjId || ''), class: 'subject-select' },
    );
    subjSel.disabled = state.paused;
    subjSel.addEventListener('change', async () => {
      state.subjId = Number(subjSel.value);
      state.quizClass = '';
      await loadTypes();
      renderPicker();
      renderQuestionEmpty();
    });

    const typeItems = Object.keys(state.types).map((t) => ({
      key: t,
      label: `${QTYPE[t]?.label || t} (${state.types[t]})`,
    }));

    pickerSlot.append(card({
      iconName: 'filter',
      body: el('div.stack', {}, [
        el('div.form-grid', {}, [
          field('科目', subjSel),
          typeItems.length
            ? field('题型', segmented(typeItems, state.quizClass, (k) => { state.quizClass = k; draw(); }))
            : field('题型', el('div.muted', { text: '该科目暂无可用题型' })),
        ]),
      ]),
    }));
    updateCompactText();
  }

  function renderNotice() {
    clear(noticeSlot);
    if (state.paused) {
      noticeSlot.append(alertBox('当前有正在进行的正式考试，按考场纪律要求已暂停练习（防止题目泄露）。', {
        type: 'warning',
        title: '练习已暂停',
      }));
    }
  }

  function renderQuestionEmpty() {
    clear(questionSlot);
    questionSlot.append(emptyStated('请选择科目与题型后点击「换一题」', { iconName: 'help-circle' }));
  }

  async function draw() {
    cancelAdvance();
    if (state.paused) {
      notify.warning(state.pauseReason === 'self_in_exam'
        ? '你正在参加正式考试，不能同时进行在线练习'
        : '正式考试进行中，按考场纪律要求已暂停练习');
      return;
    }
    if (!state.subjId) { notify.warning('请先选择科目'); return; }
    if (!state.quizClass) { notify.warning('请先选择题型'); return; }
    const res = await withLoading(questionSlot, () => exerciseApi.list({ subj_id: state.subjId, quiz_class: state.quizClass }));
    if (!res.ok) {
      clear(questionSlot);
      questionSlot.append(alertBox(res.error?.message || '抽题失败', { type: 'danger' }));
      return;
    }
    if (!res.result.question) {
      clear(questionSlot);
      questionSlot.append(emptyStated('该题型暂无题目', { iconName: 'inbox' }));
      return;
    }
    state.question = res.result.question;
    renderQuestion();
    enterPractice();
  }

  function renderQuestion(result) {
    const q = state.question;
    clear(questionSlot);
    const meta = QTYPE[q.quiz_class] || { kind: 'single' };
    const opts = q.quiz_option_list || [];
    const isFill = meta.kind === 'fill' || meta.kind === 'text';

    let answer = null;
    const optionNodes = [];
    let answeredCorrect = false;
    const resultSlot = el('div');

    const body = el('div.stack');
    if (q.quiz_pic_name) {
      // 只保留 addEventListener：内联 onerror 属性在 script-src 'self' 的 CSP 下
      // 不会执行，只会产生控制台违规日志。
      const img = el('img.question-pic', { src: `/uploads/${q.quiz_pic_name}`, alt: '题目配图' });
      img.addEventListener('error', () => img.remove());
      body.append(img);
    }
    body.append(el('div.question-stem', { text: q.quiz_title }));

    if (isFill) {
      const inp = el('input', { class: 'input blank-input', placeholder: '请输入答案' });
      inp.addEventListener('input', () => {
        answer = inp.value.trim();
        if (!answeredCorrect && resultSlot.childElementCount) {
          clear(resultSlot);
          answeredCorrect = false;
          submitBtn.disabled = false;
          cancelAdvance();
        }
      });
      body.append(inp);
    } else {
      const list = el('div.option-list');
      const items = opts.length ? opts : [{ key: 'A', text: '正确' }, { key: 'B', text: '错误' }];
      for (const o of items) {
        const node = el('div.option-item', {}, [
          el('span.option-key', { text: o.key }),
          el('span.option-text', { text: o.text }),
        ]);
        node.addEventListener('click', () => {
          if (answeredCorrect) return;
          // 作答后修改选项：清掉上一次对错标记与反馈，允许重新提交
          if (optionNodes.some((n) => n.classList.contains('is-correct') || n.classList.contains('is-wrong'))) {
            for (const n of optionNodes) n.classList.remove('is-correct', 'is-wrong');
            clear(resultSlot);
            answeredCorrect = false;
            submitBtn.disabled = false;
            cancelAdvance();
          }
          if (meta.kind === 'multi') {
            node.classList.toggle('is-selected');
            const sel = [...list.querySelectorAll('.option-item.is-selected')].map((n) => n.dataset.key).sort().join('');
            answer = sel;
          } else {
            for (const n of list.querySelectorAll('.option-item')) n.classList.remove('is-selected');
            node.classList.add('is-selected');
            answer = o.key;
          }
        });
        node.dataset.key = o.key;
        optionNodes.push(node);
        list.append(node);
      }
      body.append(list);
    }

    const submitBtn = button('提交答案', {
      variant: 'primary', iconName: 'check', class: 'ex-submit',
      onClick: async () => {
        if (answeredCorrect) return;
        const val = answer == null ? '' : String(answer);
        const r = await withLoading(submitBtn, () => exerciseApi.check({ quiz_id: q.quiz_id, stu_key: val }));
        if (!r.ok) return;
        renderResult(r.result);
        if (r.result.correct) {
          // 答对：锁定并 1.5 秒后自动跳到下一题
          answeredCorrect = true;
          submitBtn.disabled = true;
          advanceTimer = setTimeout(() => { advanceTimer = null; draw(); }, 1500);
        }
        // 答错：停留当前题，等待用户修改答案后重新提交（答对即跳转）。
      },
    });

    function renderResult(r) {
      clear(resultSlot);
      const correct = r.correct;
      resultSlot.append(el('div.alert.' + (correct ? 'alert-success' : 'alert-danger'), {}, [
        icon(correct ? 'check-circle' : 'x-circle', { size: 17 }),
        el('div', {}, [
          el('strong', { text: correct ? '回答正确' : '回答错误' }),
          el('div.muted', { text: `正确答案：${r.answer || '—'}　你的答案：${r.user_answer || '未作答'}` }),
        ]),
      ]));
      if (optionNodes.length) {
        const correctKeys = String(r.answer || '').toUpperCase().replace(/[^A-Z]/g, '').split('');
        const mineKeys = String(r.user_answer || '').toUpperCase().replace(/[^A-Z]/g, '').split('');
        for (const n of optionNodes) {
          n.classList.remove('is-selected');
          const k = n.dataset.key;
          if (correctKeys.includes(k)) n.classList.add('is-correct');
          else if (mineKeys.includes(k)) n.classList.add('is-wrong');
        }
      }
    }

    questionSlot.append(card({
      iconName: 'help-circle',
      title: q.quiz_type_label || '练习题',
      actions: [badge(`题目 #${q.quiz_id}`)],
      body,
        footer: el('div.row.between.ex-footer', {}, [
        submitBtn,
        button('下一题', {
          variant: 'secondary', iconName: 'arrowRight', class: 'ex-next',
          onClick: () => { cancelAdvance(); draw(); },
        }),
      ]),
    }));
    questionSlot.append(resultSlot);
  }

  renderQuestionEmpty();
  updateLayout();
  return root;
}
