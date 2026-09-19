/**
 * 错题本视图（A1）
 *
 * - 顶部统计卡：错题总数 / 未掌握 / 已掌握 / 累计答错次数
 * - 筛选：科目、掌握状态（全部 / 未掌握 / 已掌握）、来源（正式 / 模拟 / 练习）
 * - 列表：按科目展示错题，含题型 / 难度 / 答错次数，可单题「重练」
 * - 「开始重练」：抽取待重练题目（优先未掌握），逐题作答，答对自动标记掌握
 *
 * 错题来源：正式考试 / 模拟考试 / 在线练习 三个判分落点自动沉淀（见后端 WrongBook）。
 */

import { el, clear, mount } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import {
  button, card, badge, field, select, notify, emptyStated, alertBox, segmented,
} from '../../ui/components.js';
import { withLoading } from '../../core/bootstrap.js';
import { studentApi } from '../../api/index.js';
import { QTYPE } from '../exam-runner.js';

const EXAM_TYPE_LABEL = { formal: '正式考试', mock: '模拟考试', exercise: '在线练习' };

export function WrongBookView() {
  const root = el('div.stack');
  const headSlot = el('div.page-head');
  const statSlot = el('div.stat-grid');
  const filterSlot = el('div');
  const listSlot = el('div');

  const state = {
    subjects: [],
    subjId: 0,
    mastered: '',      // '' 全部 / '0' 未掌握 / '1' 已掌握
    examType: '',
    list: [],
    total: 0,
    stats: null,
    // 重练会话
    practicing: false,
    session: null,
  };

  root.append(headSlot, statSlot, filterSlot, listSlot);

  /* ---------- 头部 ---------- */
  mount(headSlot, [
    el('div', {}, [
      el('h1.page-title', { text: '错题本' }),
      el('p.page-sub', { text: '正式考试、模拟考试与在线练习的错题会自动归集到这里，可针对性重练' }),
    ]),
    el('div.page-head-actions', {}, [
      button('开始重练', { variant: 'primary', size: 'sm', iconName: 'refresh-cw', onClick: () => startBatchPractice() }),
    ]),
  ]);

  /* ---------- 加载列表 ---------- */
  async function load() {
    const res = await withLoading(listSlot, () => studentApi.wrongBookList({
      subj_id: state.subjId || undefined,
      mastered: state.mastered === '' ? undefined : Number(state.mastered),
      exam_type: state.examType || undefined,
      per_page: 50,
    }));
    if (!res.ok) return;
    state.list = res.result.list || [];
    state.total = res.result.total || 0;
    state.stats = res.result.stats || null;
    state.subjects = res.result.subjects || [];
    renderStats();
    renderFilters();
    renderList();
  }

  function renderStats() {
    clear(statSlot);
    const s = state.stats;
    if (!s) return;
    const cards = [
      { label: '错题总数', value: s.total, tone: 'primary' },
      { label: '未掌握', value: s.unmastered, tone: 'danger' },
      { label: '已掌握', value: s.mastered, tone: 'success' },
      { label: '累计答错', value: s.wrong_times, tone: 'muted' },
    ];
    statSlot.append(...cards.map((c) => card({
      body: el('div.stat-cell', {}, [
        el('div.stat-value.' + (c.tone === 'muted' ? 'muted' : 'text-' + c.tone), { text: String(c.value) }),
        el('div.stat-label', { text: c.label }),
      ]),
    })));
  }

  function renderFilters() {
    clear(filterSlot);
    const subjSel = select(
      [{ value: '', label: '全部科目' }, ...state.subjects.map((s) => ({ value: String(s.id), label: s.subj_name }))],
      { name: 'subj_id', value: String(state.subjId || '') },
    );
    subjSel.addEventListener('change', () => { state.subjId = Number(subjSel.value); load(); });

    const masterSeg = segmented([
      { key: '', label: '全部' },
      { key: '0', label: '未掌握' },
      { key: '1', label: '已掌握' },
    ], state.mastered, (k) => { state.mastered = k; load(); });

    const typeSeg = segmented([
      { key: '', label: '全部' },
      { key: 'formal', label: '正式考试' },
      { key: 'mock', label: '模拟考试' },
      { key: 'exercise', label: '在线练习' },
    ], state.examType, (k) => { state.examType = k; load(); });

    filterSlot.append(card({
      iconName: 'filter',
      body: el('div.stack', {}, [
        el('div.form-grid', {}, [
          field('科目', subjSel),
          field('掌握状态', masterSeg),
          field('来源', typeSeg),
        ]),
      ]),
    }));
  }

  function renderList() {
    clear(listSlot);
    if (state.list.length === 0) {
      listSlot.append(emptyStated('还没有错题，继续加油！', { iconName: 'check-circle' }));
      return;
    }
    const groups = {};
    for (const it of state.list) {
      const key = it.subj_name || '其他';
      (groups[key] = groups[key] || []).push(it);
    }
    for (const [subj, items] of Object.entries(groups)) {
      listSlot.append(el('h3.section-title', { text: subj }));
      const wrap = el('div.stack');
      for (const it of items) wrap.append(renderItem(it));
      listSlot.append(wrap);
    }
  }

  function renderItem(it) {
    const meta = QTYPE[it.quiz_class] || { kind: 'single' };
    const c = card({
      iconName: it.mastered ? 'check-circle' : 'alert-circle',
      title: it.quiz_type_label || '题目',
      actions: [
        badge(it.quiz_diff_label || '', { tone: it.quiz_diff_label === '难' ? 'danger' : it.quiz_diff_label === '易' ? 'success' : 'muted' }),
        badge(`错 ${it.wrong_count} 次`, { tone: 'warning' }),
        badge(EXAM_TYPE_LABEL[it.exam_type] || it.exam_type, { tone: 'muted' }),
        it.mastered ? badge('已掌握', { tone: 'success' }) : null,
      ].filter(Boolean),
      body: el('div.stack', {}, [
        it.quiz_pic_name ? el('img.question-pic', { src: `/uploads/${it.quiz_pic_name}`, alt: '题目配图' }) : null,
        el('div.question-stem', { text: it.quiz_title }),
      ].filter(Boolean)),
      footer: el('div.row.between', {}, [
        el('span.muted', { text: `最近答错：${(it.last_wrong_at || '').replace('T', ' ')}` }),
        button('重练', { variant: 'secondary', size: 'sm', iconName: 'refresh-cw', onClick: () => startSinglePractice(it) }),
      ]),
    });
    return c;
  }

  /* ---------- 重练会话 ---------- */

  // 单题重练：直接用列表行已有的题目内容（不含答案）
  function startSinglePractice(it) {
    const q = {
      quiz_id: it.quiz_id,
      quiz_class: it.quiz_class,
      quiz_title: it.quiz_title,
      quiz_option_list: it.quiz_option_list || [],
      quiz_pic_name: it.quiz_pic_name || '',
      quiz_type_label: it.quiz_type_label || '',
      subj_name: it.subj_name || '',
    };
    runSession([q]);
  }

  async function startBatchPractice() {
    const res = await withLoading(listSlot, () => studentApi.wrongBookPractice({
      subj_id: state.subjId || undefined,
      limit: 20,
    }));
    if (!res.ok) return;
    if (!res.result.questions || res.result.questions.length === 0) {
      notify.info('当前筛选下没有可重练的错题');
      return;
    }
    runSession(res.result.questions);
  }

  function runSession(questions) {
    state.practicing = true;
    state.session = { questions, idx: 0, right: 0, wrong: 0 };
    clear(listSlot);
    clear(filterSlot);
    renderSession();
  }

  function renderSession() {
    const sess = state.session;
    if (!sess) return;
    clear(listSlot);
    if (sess.idx >= sess.questions.length) {
      // 结束：汇总
      listSlot.append(card({
        iconName: 'check-circle',
        title: '重练完成',
        body: el('div.stack', {}, [
          el('p', { text: `共 ${sess.questions.length} 题，答对 ${sess.right} 题，答错 ${sess.wrong} 题。` }),
          el('p.muted', { text: '答对的题目已自动标记为「已掌握」。' }),
        ]),
        footer: button('返回错题本', { variant: 'primary', iconName: 'arrow-left', onClick: () => { state.practicing = false; state.session = null; load(); } }),
      }));
      return;
    }
    const q = sess.questions[sess.idx];
    const total = sess.questions.length;
    const meta = QTYPE[q.quiz_class] || { kind: 'single' };
    const opts = q.quiz_option_list || [];
    const isFill = meta.kind === 'fill' || meta.kind === 'text';

    let answer = null;
    const body = el('div.stack');
    if (q.quiz_pic_name) {
      const img = el('img.question-pic', { src: `/uploads/${q.quiz_pic_name}`, alt: '题目配图' });
      img.addEventListener('error', () => img.remove());
      body.append(img);
    }
    body.append(el('div.question-stem', { text: q.quiz_title }));

    const optionNodes = [];
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
            answer = [...list.querySelectorAll('.option-item.is-selected')].map((n) => n.dataset.key).sort().join('');
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
        const r = await withLoading(submitBtn, () => studentApi.wrongBookCheck({ quiz_id: q.quiz_id, stu_key: val }));
        if (!r.ok) return;
        renderResult(r.result, optionNodes);
        if (r.result.correct) sess.right++; else sess.wrong++;
      },
    });

    function renderResult(r, nodes) {
      clear(resultSlot);
      const correct = r.correct;
      resultSlot.append(el('div.alert.' + (correct ? 'alert-success' : 'alert-danger'), {}, [
        icon(correct ? 'check-circle' : 'x-circle', { size: 17 }),
        el('div', {}, [
          el('strong', { text: correct ? '回答正确' : '回答错误' }),
          el('div.muted', { text: `正确答案：${r.answer || '—'}　你的答案：${r.user_answer || '未作答'}` }),
        ]),
      ]));
      if (nodes.length) {
        const correctKeys = String(r.answer || '').toUpperCase().replace(/[^A-Z]/g, '').split('');
        const mineKeys = String(r.user_answer || '').toUpperCase().replace(/[^A-Z]/g, '').split('');
        for (const n of nodes) {
          n.classList.remove('is-selected');
          const k = n.dataset.key;
          if (correctKeys.includes(k)) n.classList.add('is-correct');
          else if (mineKeys.includes(k)) n.classList.add('is-wrong');
        }
      }
    }

    listSlot.append(card({
      iconName: q.quiz_type_label ? 'help-circle' : 'help-circle',
      title: `${q.quiz_type_label || '题目'}　(${sess.idx + 1}/${total})`,
      actions: [badge(q.subj_name || '')],
      body,
      footer: el('div.row.between', {}, [
        submitBtn,
        button('下一题', { variant: 'secondary', iconName: 'chevron-right', onClick: () => { sess.idx++; renderSession(); } }),
      ]),
    }));
    listSlot.append(resultSlot);
  }

  load();
  return root;
}
