/**
 * 题目搜索视图（考生端 · 学习检索）
 *
 * 登录后选择科目，输入题干关键词（≥3 字）即模糊匹配题库，返回题干、选项与
 * 正确答案；正确选项以绿色高亮，供背题 / 查阅。
 *
 * 说明：与考试 / 练习不同，这里是「学习检索」场景，后端刻意下发答案
 * （见 StudentController::quizSearch 注释）——只读，不判分、不写成绩。
 */

import { el, clear } from '../../core/dom.js';
import {
  button, card, badge, field, select, segmented, emptyStated, notify,
} from '../../ui/components.js';
import { withLoading } from '../../core/bootstrap.js';
import { studentApi, siteApi } from '../../api/index.js';

const TYPE_FILTERS = [
  { key: '', label: '全部' },
  { key: 'radio1', label: '判断' },
  { key: 'radio2', label: '单选' },
  { key: 'checkbox', label: '多选' },
];

const PER_PAGE = 20;

export function QuizSearchView() {
  const root = el('div.quiz-search.stack');
  const filterSlot = el('div');
  const metaSlot = el('div');
  const listSlot = el('div');
  const moreSlot = el('div');

  const state = {
    subjects: [],
    subjId: 0,
    keyword: '',
    quizClass: '',
    list: [],
    total: 0,
    page: 1,
    totalPages: 0,
    loading: false,
  };

  root.append(filterSlot, metaSlot, listSlot, moreSlot);

  let debounceTimer = null;

  /* ---------- 顶部筛选：科目 + 关键词 + 题型 ---------- */
  function renderFilters() {
    clear(filterSlot);
    const subjSel = select(
      state.subjects.map((s) => ({ value: String(s.id), label: s.subj_name })),
      { name: 'subj_id', value: String(state.subjId || ''), placeholder: '请选择科目' },
    );
    subjSel.addEventListener('change', () => {
      state.subjId = Number(subjSel.value) || 0;
      search(true);
    });

    const kwInput = el('input', {
      class: 'input',
      type: 'search',
      placeholder: '输入题干关键词（至少 3 个字）',
      value: state.keyword,
    });
    kwInput.addEventListener('input', () => {
      state.keyword = kwInput.value.trim();
      clearTimeout(debounceTimer);
      // 防抖：停止输入 300ms 后触发；少于 3 字不检索
      debounceTimer = setTimeout(() => search(true), 300);
    });

    const typeSeg = segmented(TYPE_FILTERS, state.quizClass, (k) => {
      state.quizClass = k;
      search(true);
    });

    filterSlot.append(card({
      iconName: 'search',
      title: '题目搜索',
      body: el('div.stack', {}, [
        el('div.form-grid', {}, [
          field('科目', subjSel),
          field('题干关键词', kwInput),
          field('题型', typeSeg),
        ]),
        el('p.muted.search-tip', { text: '选择一个科目后，输入题干中任意 3 个以上的字即可匹配，结果显示选项与正确答案（正确项为绿色）。' }),
      ]),
    }));
  }

  /* ---------- 检索 ---------- */
  async function search(reset) {
    clearTimeout(debounceTimer);
    if (reset) {
      state.page = 1;
      state.list = [];
    }

    // 前置校验：未选科目 / 关键词不足 3 字 → 展示提示，不请求
    if (state.subjId <= 0) {
      state.total = 0; state.totalPages = 0;
      renderMeta();
      renderEmpty('请先选择一个科目', 'list');
      clear(moreSlot);
      return;
    }
    if (state.keyword.length < 3) {
      state.total = 0; state.totalPages = 0; state.list = [];
      renderMeta();
      renderEmpty('请输入至少 3 个字的题干关键词', 'search');
      clear(moreSlot);
      return;
    }

    state.loading = true;
    const res = await withLoading(listSlot, () => studentApi.quizSearch({
      subj_id: state.subjId,
      keyword: state.keyword,
      quiz_class: state.quizClass || undefined,
      page: state.page,
      per_page: PER_PAGE,
    }));
    state.loading = false;
    if (!res.ok) return;

    const r = res.result || {};
    const incoming = r.list || [];
    state.total = r.total || 0;
    state.totalPages = r.total_pages || 0;
    state.list = reset ? incoming : state.list.concat(incoming);

    renderMeta();
    renderList();
    renderMore(r.hint);
  }

  /* ---------- 结果元信息 ---------- */
  function renderMeta() {
    clear(metaSlot);
    if (state.subjId <= 0 || state.keyword.length < 3) return;
    const subj = state.subjects.find((s) => Number(s.id) === state.subjId);
    metaSlot.append(el('p.muted', {
      text: `「${subj ? subj.subj_name : ''}」中包含“${state.keyword}”的题目共 ${state.total} 题` +
        (state.list.length ? `，已显示 ${state.list.length} 题` : ''),
    }));
  }

  /* ---------- 结果列表 ---------- */
  function renderList() {
    clear(listSlot);
    if (state.list.length === 0) {
      renderEmpty('没有匹配的题目，换个关键词试试', 'search-x');
      return;
    }
    const wrap = el('div.stack');
    for (const q of state.list) wrap.append(renderQuestion(q));
    listSlot.append(wrap);
  }

  function renderEmpty(text, iconName) {
    clear(listSlot);
    listSlot.append(emptyStated(text, { iconName }));
  }

  function renderQuestion(q) {
    const opts = q.quiz_option_list || [];
    const body = el('div.stack', {}, [
      el('div.question-stem', { text: q.quiz_title }),
    ]);

    if (opts.length) {
      const list = el('div.option-list');
      for (const o of opts) {
        // 正确选项加 is-correct（绿色高亮，见 portal.css）
        list.append(el('div.option-item' + (o.correct ? ' is-correct' : ''), {}, [
          el('span.option-key', { text: o.key }),
          el('span.option-text', { text: o.text }),
        ]));
      }
      body.append(list);
    } else {
      // 填空 / 问答题：无字母选项，直接展示参考答案
      body.append(el('div.answer-line', {}, [
        el('span.answer-line-label', { text: '参考答案：' }),
        el('span.answer-line-text', { text: q.quiz_key || '—' }),
      ]));
    }

    return card({
      iconName: 'help-circle',
      title: q.quiz_type_label || '题目',
      actions: [
        q.quiz_diff_label ? badge(q.quiz_diff_label, { tone: q.quiz_diff_label === '难' ? 'danger' : q.quiz_diff_label === '易' ? 'success' : 'muted' }) : null,
        q.subj_name ? badge(q.subj_name, { tone: 'muted' }) : null,
      ].filter(Boolean),
      body,
      footer: el('div.row.between', {}, [
        el('span.muted', { text: q.quiz_kp ? `章节：${q.quiz_kp}` : '' }),
        opts.length ? el('span.answer-key', { text: `正确答案：${q.quiz_key || '—'}` }) : null,
      ].filter(Boolean)),
    });
  }

  /* ---------- 加载更多 ---------- */
  function renderMore(hint) {
    clear(moreSlot);
    if (hint && state.list.length === 0) {
      moreSlot.append(el('p.muted', { text: hint }));
      return;
    }
    if (state.page < state.totalPages) {
      moreSlot.append(el('div.row', {}, [
        button('加载更多', {
          variant: 'secondary', iconName: 'chevron-down',
          onClick: () => { state.page += 1; search(false); },
        }),
      ]));
    }
  }

  /* ---------- 初始化：拉科目 ---------- */
  (async function init() {
    renderFilters();
    renderEmpty('选择科目并输入题干关键词开始搜索', 'search');
    const res = await withLoading(filterSlot, () => siteApi.subjects());
    if (res.ok) {
      state.subjects = (res.result || []).map((s) => ({ id: s.id, subj_name: s.subj_name }));
    } else {
      notify.error('科目加载失败');
    }
    renderFilters();
  })();

  return root;
}
