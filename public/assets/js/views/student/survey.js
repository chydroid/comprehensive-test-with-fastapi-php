/**
 * 考生端「考后问卷」填写弹窗（C5）。
 *
 * 两个刻意的行为：
 *  1. **已填写过就带出原答案**，而不是给一张空表。允许改主意是自然的，
 *     而服务端「一人一题一答」保证改了不会变成两条记录。
 *  2. **评分题用 1–5 的按钮组而不是输入框**。考后反馈要的是「一秒钟点完」，
 *     让人在输入框里填数字会把回收率打没。
 */

import { el, clear } from '../../core/dom.js';
import {
  button, badge, field, textarea, notify, emptyStated, alertBox, openModal,
} from '../../ui/components.js';
import { withLoading } from '../../core/bootstrap.js';
import { studentApi } from '../../api/index.js';

const TYPE_LABELS = { rating: '评分题', choice: '单选题', text: '文本题' };
const RATING_SCALE = [1, 2, 3, 4, 5];

/**
 * @param {number} examId
 * @param {string} examName
 * @param {Function} [onDone]
 */
export function openSurveyForm(examId, examName = '', onDone = null) {
  const root = el('div.stack');
  /** paperId -> 控件值读取函数 */
  const readers = new Map();

  const modal = openModal({
    title: `考后反馈 · ${examName || `考试 #${examId}`}`,
    size: 'md',
    body: root,
    footer: [button('关闭', { variant: 'secondary', onClick: () => modal.close() })],
  });

  async function load() {
    const res = await withLoading(root, () => studentApi.survey({ exam_id: examId }), { silent: true });
    if (!res.ok) {
      root.replaceChildren(alertBox(res.error?.message || '加载问卷失败', { type: 'danger' }));
      return;
    }
    const data = res.result || {};
    if (!data.has_survey) {
      root.replaceChildren(emptyStated('本场考试没有配置问卷', { iconName: 'help-circle' }));
      return;
    }
    if (!data.finished) {
      root.replaceChildren(alertBox('考试交卷后才能填写反馈。', { type: 'warning' }));
      return;
    }
    render(data.questions || [], data.answers || {});
  }

  function render(questions, answers) {
    clear(root);
    readers.clear();

    if (answers && Object.keys(answers).length > 0) {
      root.append(alertBox('你已填写过本问卷，可在此修改后重新提交。', { type: 'info' }));
    }

    questions.forEach((q, i) => {
      const qid = String(q.id);
      let control;

      if (q.type === 'rating') {
        let value = Number(answers[qid] ?? 0) || 0;
        const btns = RATING_SCALE.map((n) => button(String(n), {
          variant: value === n ? 'primary' : 'secondary',
          size: 'sm',
          onClick: () => {
            value = n;
            btns.forEach((b, idx) => {
              const on = RATING_SCALE[idx] === value;
              b.className = b.className.replace(/\bbtn-(primary|secondary)\b/, `btn-${on ? 'primary' : 'secondary'}`);
            });
          },
        }));
        control = el('div.flex.gap-2', {}, btns);
        readers.set(qid, () => (value > 0 ? String(value) : ''));
      } else if (q.type === 'choice') {
        const opts = Array.isArray(q.options) ? q.options : [];
        let value = String(answers[qid] ?? '');
        const btns = opts.map((opt) => button(opt, {
          variant: value === opt ? 'primary' : 'secondary',
          size: 'sm',
          onClick: () => {
            value = opt;
            btns.forEach((b, idx) => {
              const on = opts[idx] === value;
              b.className = b.className.replace(/\bbtn-(primary|secondary)\b/, `btn-${on ? 'primary' : 'secondary'}`);
            });
          },
        }));
        control = opts.length ? el('div.flex.gap-2', { style: { flexWrap: 'wrap' } }, btns)
          : el('div.fs-sm.c-warning', { text: '该题未配置选项' });
        readers.set(qid, () => value);
      } else {
        const ta = textarea({
          value: String(answers[qid] ?? ''),
          placeholder: '请写下你的建议（选填）',
          rows: 3,
        });
        control = ta;
        readers.set(qid, () => ta.value.trim());
      }

      root.append(el('div.stack', { style: { gap: '6px' } }, [
        el('div.flex.items-center.gap-2', {}, [
          el('span.fw-500', { text: `${i + 1}. ${q.title}` }),
          q.required ? badge('必答', { tone: 'warning' }) : badge(TYPE_LABELS[q.type] || q.type, { tone: '' }),
        ]),
        control,
      ]));
    });

    const submitBtn = button('提交反馈', {
      variant: 'primary',
      iconName: 'send',
      onClick: async (e) => {
        const payload = {};
        for (const [qid, read] of readers) {
          const v = read();
          if (v !== '') payload[qid] = v;
        }
        const r = await withLoading(e.currentTarget, () => studentApi.submitSurvey({
          exam_id: examId,
          answers: payload,
        }), { silent: true });
        if (r.ok) {
          notify.success('感谢你的反馈');
          if (onDone) onDone();
          modal.close();
        } else {
          notify.error(r.error?.message || '提交失败');
        }
      },
    });
    root.append(el('div.flex.justify-end', {}, [submitBtn]));
  }

  load();
  return modal;
}
