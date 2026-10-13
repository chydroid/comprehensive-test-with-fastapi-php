/**
 * 题目搜索视图（考生端 · 学习检索）
 *
 * 登录后选择科目，输入题干关键词（≥3 字）即显示该关键词的**全部**匹配题目
 *（题干 / 选项 / 正确答案，正确选项绿色高亮），供背题 / 查阅。
 *
 * 交互（按用户要求，去掉结果分页）：
 *  - 首次达到 3 字：向服务端取「当前关键词」的完整命中集（all=1，不分页）；
 *  - 继续输入（在当前关键词基础上追加，即"已加载关键词"仍是其子串）：
 *    只在**已加载结果**上本地精化筛选，不再发请求，越输越精确、越快；
 *  - 仅当关键词变宽 / 改变（不再是已加载关键词的子串）或换科目时，才重新取。
 *
 * 说明：与考试 / 练习不同，这里是「学习检索」场景，后端刻意下发答案
 * （见 StudentController::quizSearch 注释）——只读，不判分、不写成绩。
 */

import { el, clear } from '../../core/dom.js';
import {
  card, badge, field, select, segmented, emptyStated, notify,
} from '../../ui/components.js';
import { withLoading } from '../../core/bootstrap.js';
import { studentApi, siteApi } from '../../api/index.js';

const TYPE_FILTERS = [
  { key: '', label: '全部' },
  { key: 'radio1', label: '判断' },
  { key: 'radio2', label: '单选' },
  { key: 'checkbox', label: '多选' },
];

// 大小写不敏感包含（与服务端 utf8mb4_*_ci 的 LIKE 语义保持一致）
const hasCI = (hay, needle) => String(hay).toLowerCase().includes(String(needle).toLowerCase());

export function QuizSearchView() {
  const root = el('div.quiz-search.stack');
  const filterSlot = el('div');
  const metaSlot = el('div');
  const listSlot = el('div');

  const state = {
    subjects: [],
    subjId: 0,
    keyword: '',
    quizClass: '',
    loadedKeyword: null,   // 已完整加载（全部命中）的关键词
    loadedList: [],        // 该关键词的全部命中题（含 correct 标记）
  };

  root.append(filterSlot, metaSlot, listSlot);

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
      // 换科目：已加载结果作废，按当前关键词重新取
      state.loadedKeyword = null;
      state.loadedList = [];
      runSearch();
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
      debounceTimer = setTimeout(runSearch, 300);
    });

    const typeSeg = segmented(TYPE_FILTERS, state.quizClass, (k) => {
      state.quizClass = k;
      // 题型筛选纯本地：直接在已加载结果上过滤
      renderResults();
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
        el('p.muted.search-tip', { text: '选择科目后输入题干中任意 3 个以上的字，即显示全部匹配题目；继续输入会在已有结果里进一步筛选，越输越精确，无需翻页。正确选项为绿色。' }),
      ]),
    }));
  }

  /* ---------- 当前应显示的结果（本地精化：关键词 + 题型） ---------- */
  function currentResults() {
    const kw = state.keyword;
    return state.loadedList.filter((q) =>
      hasCI(q.quiz_title, kw) && (state.quizClass === '' || q.quiz_class === state.quizClass)
    );
  }

  // 已加载关键词是否是当前关键词的子串 → 已加载集是当前关键词命中的超集，可本地精化
  function canRefine() {
    return state.loadedKeyword !== null && hasCI(state.keyword, state.loadedKeyword);
  }

  /* ---------- 检索 ---------- */
  async function runSearch() {
    clearTimeout(debounceTimer);
    if (state.subjId <= 0 || state.keyword.length < 3) {
      // 前置条件不满足：清空已加载集，仅展示提示
      state.loadedKeyword = null;
      state.loadedList = [];
      renderResults();
      return;
    }
    // 能在已加载结果内精化 → 本地过滤，不发请求
    if (canRefine()) {
      renderResults();
      return;
    }
    // 否则向服务端取「当前关键词」的完整命中集（all=1，不分页）
    const res = await withLoading(listSlot, () => studentApi.quizSearch({
      subj_id: state.subjId,
      keyword: state.keyword,
      all: 1,
    }));
    if (!res.ok) return;
    state.loadedKeyword = state.keyword;
    state.loadedList = (res.result && res.result.list) || [];
    renderResults();
  }

  /* ---------- 元信息 ---------- */
  function renderMeta() {
    clear(metaSlot);
    if (state.subjId <= 0 || state.keyword.length < 3) return;
    const subj = state.subjects.find((s) => Number(s.id) === state.subjId);
    metaSlot.append(el('p.muted', {
      text: `「${subj ? subj.subj_name : ''}」中包含“${state.keyword}”的题目：${currentResults().length} 题`,
    }));
  }

  /* ---------- 结果列表 ---------- */
  function renderResults() {
    renderMeta();
    clear(listSlot);
    if (state.subjId <= 0) { listSlot.append(emptyStated('请先选择一个科目', { iconName: 'list' })); return; }
    if (state.keyword.length < 3) { listSlot.append(emptyStated('请输入至少 3 个字的题干关键词', { iconName: 'search' })); return; }
    const items = currentResults();
    if (items.length === 0) { listSlot.append(emptyStated('没有匹配的题目，换个关键词试试', { iconName: 'search-x' })); return; }
    const wrap = el('div.stack');
    for (const q of items) wrap.append(renderQuestion(q));
    listSlot.append(wrap);
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

  /* ---------- 初始化：拉科目 ---------- */
  (async function init() {
    renderFilters();
    renderResults();
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
