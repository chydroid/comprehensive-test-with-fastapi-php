/**
 * 教师端 —— C4 AI 智能组卷弹窗。
 *
 * 与后端同一哲学：**AI 只给建议，绝不直接成卷**。
 *   ① composeSuggest  —— 生成建议题目（只读，不改考试）。
 *   ② 教师在弹窗里勾选要采用的题（可取消勾选、可只看本地抽样结果）。
 *   ③ composeApply     —— 采用选中题，落库为 manual 组卷（远程新题先入库）。
 *
 * 若远程模型不可用，后端会自动降级为本地抽样（degraded=true），弹窗会明确提示，
 * 题目仍可用；本地抽样永远可用，不会让组卷功能整体不可用。
 */

import { el, clear } from '../../core/dom.js';
import {
  button, card, openModal, notify, alertBox, badge, loadingOverlay, emptyStated,
  field, input, select,
} from '../../ui/components.js';
import { teacherApi } from '../../api/index.js';
import { withLoading } from '../../core/bootstrap.js';

const TYPE_LABELS = {
  radio1: '判断题', radio2: '单选题', checkbox: '多选题', text: '填空题', longtext: '问答题',
};

const TYPE_OPTIONS = [
  { value: '', label: '全部题型' },
  ...['radio1', 'radio2', 'checkbox', 'text', 'longtext'].map((t) => ({ value: t, label: TYPE_LABELS[t] })),
];
const DIFF_TONE = { Y: '', Z: 'info', N: 'warning' };

/** 难度代码（Y/Z/N）转中文标签 */
function diffLabel(code) {
  if (code === 'Y') return '易';
  if (code === 'Z') return '中';
  if (code === 'N') return '难';
  return DIFF_LABELS[code] || code || '—';
}

/** 单题摘要（题干 + 选项/要点） */
function questionRow(q) {
  const type = String(q.type || '');
  const isObjective = ['radio1', 'radio2', 'checkbox', 'text'].includes(type);
  const lines = [];
  lines.push(el('div.fs-sm.fw-500.pre-wrap', { text: q.stem || '（无题干）' }));
  if (isObjective && Array.isArray(q.options) && q.options.length) {
    lines.push(el('div.fs-xs.c-tertiary', {
      text: (q.options || []).map((o) => `${o.key || ''}. ${o.text || ''}`).join('  '),
    }));
  }
  return lines;
}

/**
 * 打开 AI 智能组卷弹窗。
 * @param {object} opts
 * @param {number} opts.examId   考试 id（必须已存在、且未开考）
 * @param {number} opts.subjId   科目 id（仅用于展示，后端以考试科目为准）
 * @param {string} opts.subjName 科目名（展示用）
 * @param {function(object):void} [opts.onApplied] 采用成功后回调，参数为后端 apply 结果
 */
export function openComposerModal({ examId, subjId = 0, subjName = '', onApplied } = {}) {
  const countInput = input({ type: 'number', value: '10', min: '1', max: '200', class: 'input-sm' });
  countInput.style.width = '84px';
  const easyInput = input({ type: 'number', value: '3', min: '0', class: 'input-sm' });
  const midInput = input({ type: 'number', value: '4', min: '0', class: 'input-sm' });
  const hardInput = input({ type: 'number', value: '3', min: '0', class: 'input-sm' });
  [easyInput, midInput, hardInput].forEach((c) => { c.style.width = '60px'; });
  const typeSel = select(TYPE_OPTIONS, { value: '' });
  const kpInput = input({ value: '', placeholder: '可选，逗号分隔，如：函数,导数' });

  const resultSlot = el('div.stack');
  const statusSlot = el('div');
  const applyBtn = button('采用选中题', { variant: 'primary', disabled: true });

  function selectedQuestions() {
    return [...resultSlot.querySelectorAll('input[data-qidx]:checked')]
      .map((cb) => currentQuestions[Number(cb.getAttribute('data-qidx'))])
      .filter(Boolean);
  }

  let currentQuestions = [];

  async function doSuggest(btn) {
    clear(resultSlot);
    clear(statusSlot);
    resultSlot.append(loadingOverlay('正在生成建议题目…'));
    const body = {
      count: Math.max(1, Math.min(200, parseInt(countInput.value, 10) || 10)),
      easy: Math.max(0, parseInt(easyInput.value, 10) || 0),
      mid: Math.max(0, parseInt(midInput.value, 10) || 0),
      hard: Math.max(0, parseInt(hardInput.value, 10) || 0),
    };
    if (typeSel.value) body.types = [typeSel.value];
    const kps = kpInput.value.split(/[,，]/).map((s) => s.trim()).filter(Boolean);
    if (kps.length) body.kps = kps;

    const res = await teacherApi.composeSuggest(examId, body).catch((e) => {
      const msg = e?.message || '生成建议失败';
      resultSlot.replaceChildren(alertBox(msg, { type: 'danger' }));
      return null;
    });
    if (!res) { applyBtn.disabled = true; return; }

    currentQuestions = Array.isArray(res.questions) ? res.questions : [];
    renderStatus(res);
    renderList(res);
  }

  function renderStatus(res) {
    clear(statusSlot);
    const prov = res.provider || { key: 'local', label: '本地抽样' };
    const chips = [
      badge(`引擎：${prov.label || prov.key}`, { tone: 'brand' }),
    ];
    if (res.degraded) {
      chips.push(badge('已降级为本地抽样', { tone: 'warning' }));
    }
    if (res.truncated) {
      chips.push(badge('题库题量不足，已截断', { tone: 'warning' }));
    }
    if (res.composable === false) {
      chips.push(badge('考试已开始，无法组卷', { tone: 'danger' }));
    }
    statusSlot.append(el('div.flex.flex-wrap.gap-2.items-center', {}, chips));
    if (res.degraded && res.degrade_reason) {
      statusSlot.append(el('div.fs-xs.c-tertiary.mt-1', { text: `降级原因：${res.degrade_reason}` }));
    }
    if (res.composable === false) {
      statusSlot.append(alertBox('本场考试已开始或已结束，组卷已锁定。如需调整，请在开考前完成。', { type: 'warning' }));
    }
  }

  function renderList(res) {
    clear(resultSlot);
    const list = currentQuestions;
    if (!list.length) {
      resultSlot.append(emptyStated('没有可用的建议题目', {
        iconName: 'sparkles',
        desc: '可调整题量或难度分布后重试；本地抽样需要该科目在题库中有对应题目。',
      }));
      applyBtn.disabled = true;
      return;
    }
    const allChecked = list.every((q) => !q.new || true); // 新题也默认勾选，但提示需审核
    const wrap = el('div.stack', { style: { gap: '8px' } });
    list.forEach((q, i) => {
      const type = String(q.type || '');
      const head = el('div.flex.items-center.gap-2.flex-wrap', {}, [
        badge(TYPE_LABELS[type] || type, { tone: 'info' }),
        badge(diffLabel(q.difficulty), { tone: DIFF_TONE[q.difficulty] || '' }),
        q.kp ? el('span.fs-xs.c-tertiary', { text: `知识点：${q.kp}` }) : null,
        q.new ? badge('AI 新题·需审核', { tone: 'warning' }) : badge('题库已有', { tone: '' }),
      ].filter(Boolean));

      const box = el('input', { type: 'checkbox', 'data-qidx': String(i) });
      box.checked = true;
      box.addEventListener('change', () => {
        applyBtn.disabled = selectedQuestions().length === 0;
      });

      const card_ = card({
        iconName: 'file',
        body: el('label.flex.gap-3.items-start', { style: { cursor: 'pointer' }, for: '' }, [
          el('div.pt-1', {}, [box]),
          el('div.flex-1.stack', { style: { gap: '4px' } }, [head, ...questionRow(q)]),
        ]),
      });
      wrap.append(card_);
    });
    resultSlot.append(wrap);
    applyBtn.disabled = res.composable === false || selectedQuestions().length === 0;
  }

  async function doApply(btn) {
    const chosen = selectedQuestions();
    if (!chosen.length) {
      notify('请至少勾选一道题目', { tone: 'warning' });
      return;
    }
    const res = await teacherApi.composeApply(examId, { questions: chosen }).catch((e) => {
      notify(e?.message || '采用失败', { tone: 'danger' });
      return null;
    });
    if (!res) return;
    notify(`已采用 ${res.applied} 道题（新增 ${res.new} 道，满分 ${res.total_score} 分）`, { tone: 'success' });
    try { onApplied?.(res); } catch (_) { /* 回调失败不影响已落库结果 */ }
    modal.close();
  }

  applyBtn.addEventListener('click', (e) => withLoading(e.currentTarget, () => doApply(e.currentTarget), { silent: true }));

  const body = el('div.stack', {}, [
    el('div.fs-sm.c-secondary', {
      text: subjName
        ? `科目：${subjName}。AI 会按以下参数推荐题目，您勾选后再「采用」才会真正成卷。`
        : 'AI 会按以下参数推荐题目，您勾选后再「采用」才会真正成卷。',
    }),
    el('div.form-grid', { style: { gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))' } }, [
      field('总题量', countInput),
      el('div.flex.gap-2.items-end', {}, [
        field('易', easyInput), field('中', midInput), field('难', hardInput),
      ].filter(Boolean)),
      field('题型', typeSel),
      el('div.span-2', {}, [field('知识点（可选）', kpInput)]),
    ]),
    el('div.flex.items-center.gap-2', {}, [
      button('生成建议', {
        variant: 'secondary', iconName: 'sparkles',
        onClick: (e) => withLoading(e.currentTarget, () => doSuggest(e.currentTarget), { silent: true }),
      }),
      el('span.fs-xs.c-tertiary', { text: '默认 易3/中4/难3；远程模型不可用时自动降级为本地抽样。' }),
    ]),
    statusSlot,
    resultSlot,
  ]);

  const modal = openModal({
    title: 'AI 智能组卷',
    size: 'lg',
    body,
    footer: [el('div.flex-1'), applyBtn, button('关闭', { variant: 'ghost', onClick: () => modal.close() })],
  });

  return modal;
}
