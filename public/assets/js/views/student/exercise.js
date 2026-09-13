/**
 * 在线练习视图：按「科目 + 题型」随机抽题，逐题作答并即时校验。
 *
 * 注意：后端在存在 exam_status='testing' 的正式考试时会锁定练习
 * （has_ongoing_exam = true），前端据此给出明确提示而非静默失败。
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
  };

  root.append(headSlot, pickerSlot, noticeSlot, questionSlot);

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
    state.hasOngoing = res.result.has_ongoing_exam;
    renderPicker();
    renderNotice();
    if (state.hasOngoing) return;
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
      { name: 'subj_id', value: String(state.subjId || '') },
    );
    subjSel.disabled = state.hasOngoing;
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
  }

  function renderNotice() {
    clear(noticeSlot);
    if (state.hasOngoing) {
      noticeSlot.append(alertBox('当前有正在进行的正式考试，练习功能已临时暂停（防止题目泄露）。', {
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
    if (state.hasOngoing) { notify.warning('正式考试进行中，练习已暂停'); return; }
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
  }

  function renderQuestion(result) {
    const q = state.question;
    clear(questionSlot);
    const meta = QTYPE[q.quiz_class] || { kind: 'single' };
    const opts = q.quiz_option_list || [];
    const isFill = meta.kind === 'fill' || meta.kind === 'text';

    let answer = null;
    const optionNodes = [];

    const body = el('div.stack');
    if (q.quiz_pic_name) {
      const img = el('img.question-pic', { src: `/uploads/pic/${q.quiz_pic_name}`, alt: '题目配图' });
      img.addEventListener('error', () => img.remove());
      body.append(img);
    }
    body.append(el('div.question-stem', { text: q.quiz_title }));

    if (isFill) {
      const inp = el('input', { class: 'input blank-input', placeholder: '请输入答案' });
      inp.addEventListener('input', () => { answer = inp.value.trim(); });
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

    const resultSlot = el('div');
    const submitBtn = button('提交答案', {
      variant: 'primary', iconName: 'check',
      onClick: async () => {
        const val = answer == null ? '' : String(answer);
        const r = await withLoading(submitBtn, () => exerciseApi.check({ quiz_id: q.quiz_id, stu_key: val }));
        if (!r.ok) return;
        renderResult(r.result);
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
      footer: el('div.row.between', {}, [submitBtn, button('换一题', { variant: 'secondary', iconName: 'shuffle', onClick: () => draw() })]),
    }));
    questionSlot.append(resultSlot);
  }

  renderQuestionEmpty();
  return root;
}
