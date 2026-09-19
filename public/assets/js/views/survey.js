/**
 * 教师端「考后问卷」弹窗（C5）。
 *
 * 一个弹窗里同时做两件事：**配置题目**与**查看统计**。拆成两个入口反而更难用 ——
 * 教师改完题就想立刻看到回收情况，来回切换会丢掉上下文。
 *
 * 统计区的呈现按题型分流，这是刻意的：
 *   评分题 → 均值 + 1–5 分分布条（看「整体打几分」）
 *   单选题 → 各选项占比（看「多数人选哪个」）
 *   文本题 → 逐条列出原文（看「具体说了什么」，聚合没有意义）
 */

import { el, clear } from '../core/dom.js';
import {
  button, card, badge, field, input, textarea, select, notify, emptyStated,
  openModal, alertBox, confirmDialog, progressBar,
} from '../ui/components.js';
import { withLoading } from '../core/bootstrap.js';
import { teacherApi } from '../api/index.js';

const TYPE_LABELS = { rating: '评分题', choice: '单选题', text: '文本题' };
const RATING_SCALE = [1, 2, 3, 4, 5];

/**
 * @param {number} examId
 * @param {string} examName
 * @param {Function} [onSaved]
 */
export function openSurveyDialog(examId, examName = '', onSaved = null) {
  const root = el('div.stack');
  let questions = [];      // 编辑中的题目（前端态）
  let stats = null;
  let exam = null;

  const modal = openModal({
    title: `考后问卷 · ${examName || `考试 #${examId}`}`,
    size: 'lg',
    body: root,
    footer: [button('关闭', { variant: 'secondary', onClick: () => modal.close() })],
  });

  async function load() {
    const res = await withLoading(root, () => teacherApi.surveyShow(examId), { silent: true });
    if (!res.ok) {
      root.replaceChildren(alertBox(res.error?.message || '加载问卷失败', { type: 'danger' }));
      return;
    }
    exam = res.result.exam || {};
    questions = (res.result.questions || []).map(normalizeQ);
    stats = res.result.stats || null;
    render();
  }

  /** 后端行 → 编辑态结构（options 统一为数组） */
  function normalizeQ(q) {
    return {
      id: Number(q.id ?? 0),
      title: String(q.title ?? ''),
      type: String(q.type ?? 'rating'),
      options: Array.isArray(q.options) ? q.options.slice() : [],
      required: !!q.required,
    };
  }

  function render() {
    clear(root);

    root.append(alertBox(
      '问卷只在考生**交卷后**可见，且一人一题一答（重复提交算修改，不会重复计数）。'
      + '保存题目会清空本场已有的作答记录——题都换了，旧答案也不该留着。',
      { type: 'info' },
    ));

    root.append(card({
      title: '题目配置',
      iconName: 'help-circle',
      body: el('div.stack', {}, [
        ...questions.map((q, i) => questionCard(q, i)),
        questions.length === 0
          ? el('div.fs-sm.c-tertiary', { text: '还没有题目。点击下方「添加题目」开始。' })
          : null,
        el('div.flex.gap-2', {}, [
          button('添加题目', {
            variant: 'secondary', size: 'sm', iconName: 'plus',
            onClick: () => {
              questions.push({ id: 0, title: '', type: 'rating', options: [], required: false });
              render();
            },
          }),
        ]),
      ].filter(Boolean)),
      footer: el('div.flex.justify-end.gap-2', {}, [
        button('保存问卷', { variant: 'primary', iconName: 'save', onClick: save }),
      ]),
    }));

    if (stats) root.append(statsCard());
  }

  function questionCard(q, index) {
    const titleCtl = input({ value: q.title, placeholder: '例如：本次考试难度如何？', maxlength: '200' });
    titleCtl.addEventListener('input', () => { q.title = titleCtl.value; });

    const typeCtl = select(
      Object.entries(TYPE_LABELS).map(([value, label]) => ({ value, label })),
      { value: q.type },
    );
    typeCtl.addEventListener('change', () => { q.type = typeCtl.value; render(); });

    const reqCtl = input({ type: 'checkbox', checked: q.required });
    reqCtl.addEventListener('change', () => { q.required = reqCtl.checked; });

    const optCtl = textarea({
      value: q.options.join('\n'),
      placeholder: '每行一个选项，例如：\n充足\n刚好\n不够',
      rows: 3,
    });
    optCtl.addEventListener('input', () => {
      q.options = optCtl.value.split('\n').map((s) => s.trim()).filter(Boolean);
    });

    return card({
      title: `第 ${index + 1} 题`,
      iconName: 'edit-3',
      body: el('div.stack', {}, [
        field('题目', titleCtl),
        el('div.flex.gap-3', {}, [
          el('div', { style: { minWidth: '160px' } }, [field('题型', typeCtl)]),
          el('div.flex.items-center.gap-2', { style: { height: '38px', marginTop: '22px' } }, [
            reqCtl, el('span.fs-sm', { text: '必答' }),
          ]),
        ]),
        q.type === 'choice' ? field('选项（每行一个）', optCtl) : null,
        q.type === 'rating' ? el('div.fs-sm.c-tertiary', { text: '考生按 1–5 星评分，统计给出均值与分布。' }) : null,
        q.type === 'text' ? el('div.fs-sm.c-tertiary', { text: '考生自由填写，统计逐条列出原文。' }) : null,
      ].filter(Boolean)),
      footer: el('div.flex.justify-end', {}, [
        button('删除', {
          variant: 'ghost', size: 'sm', iconName: 'trash',
          onClick: () => { questions.splice(index, 1); render(); },
        }),
      ]),
    });
  }

  async function save() {
    const payload = questions
      .filter((q) => q.title.trim() !== '')
      .map((q) => ({
        title: q.title.trim(),
        type: q.type,
        options: q.type === 'choice' ? q.options : [],
        required: !!q.required,
      }));

    if (payload.length === 0) {
      const yes = await confirmDialog('没有填写任何题目，保存将清空本场问卷。', {
        confirmText: '清空并保存',
      });
      if (!yes) return;
    }

    const res = await withLoading(root, () => teacherApi.surveySave(examId, { questions: payload }), { silent: true });
    if (!res.ok) {
      notify.error(res.error?.message || '保存失败');
      return;
    }
    notify.success(`已保存 ${res.result?.count ?? 0} 道题目`);
    if (onSaved) onSaved();
    await load();
  }

  function statsCard() {
    const qs = stats.questions || [];
    const total = Number(stats.total_students) || 0;
    const answered = Number(stats.answered_students) || 0;
    const rate = total > 0 ? Math.round((answered / total) * 100) : 0;

    const body = el('div.stack', {}, [
      el('div.flex.items-center.gap-3', {}, [
        el('span.fs-sm.c-secondary', { text: `回收 ${answered} / ${total} 人` }),
        badge(`${rate}%`, { tone: rate >= 50 ? 'success' : 'warning', dot: true }),
      ]),
      ...qs.map(statBlock),
    ]);

    return card({ title: '回收统计', iconName: 'bar-chart-2', body });
  }

  function statBlock(q) {
    const answered = Number(q.answered) || 0;
    const head = el('div.flex.items-center.gap-3', {}, [
      el('div.fw-500', { text: q.title }),
      el('span', { style: { flex: '1' } }),
      badge(`${TYPE_LABELS[q.type] || q.type} · ${answered} 份`, { tone: '' }),
    ]);

    if (answered === 0) {
      return el('div.stack', {}, [head, el('div.fs-sm.c-tertiary', { text: '暂无作答' })]);
    }

    let detail;
    if (q.type === 'rating') {
      detail = el('div.stack', {}, [
        el('div.fs-sm.c-secondary', { text: `平均 ${q.avg ?? '—'} 分（满分 5）` }),
        ...RATING_SCALE.map((n) => {
          const c = Number(q.distribution?.[n] ?? 0);
          const pct = answered > 0 ? Math.round((c / answered) * 100) : 0;
          return el('div.flex.items-center.gap-3', {}, [
            el('span.fs-sm', { text: `${n} 分`, style: { width: '40px' } }),
            el('div', { style: { flex: '1' } }, [progressBar(pct)]),
            el('span.fs-sm.c-tertiary', { text: `${c} 人` }),
          ]);
        }),
      ]);
    } else if (q.type === 'choice') {
      const dist = q.distribution || {};
      detail = el('div.stack', {}, Object.entries(dist).map(([opt, c]) => {
        const pct = answered > 0 ? Math.round((Number(c) / answered) * 100) : 0;
        return el('div.flex.items-center.gap-3', {}, [
          el('span.fs-sm', { text: opt, style: { minWidth: '80px' } }),
          el('div', { style: { flex: '1' } }, [progressBar(pct)]),
          el('span.fs-sm.c-tertiary', { text: `${c} 人` }),
        ]);
      }));
    } else {
      detail = el('div.stack', {}, (q.texts || []).slice(0, 50).map((t) => el('div.fs-sm.pre-wrap', {
        text: t,
        style: { padding: '6px 10px', borderLeft: '3px solid var(--border-subtle)' },
      })));
      if ((q.texts || []).length > 50) {
        detail.append(el('div.fs-xs.c-tertiary', { text: `仅显示前 50 条，共 ${q.texts.length} 条` }));
      }
    }

    return el('div.stack', {}, [head, detail]);
  }

  load();
  return modal;
}
