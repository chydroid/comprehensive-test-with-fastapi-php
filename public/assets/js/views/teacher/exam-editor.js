/**
 * 教师端 —— 考试编辑（新增 / 修改）与考生名单
 *
 * 字段命名与组卷参数完全对齐后端 TeacherExamController / Admin\ExamController：
 *   exam_name / subj_id / exam_category_id / exam_date + exam_start_time + exam_end_time
 *   exam_tea / stu_class / {type}_{easy|mid|hard}_sum / {type}_val
 */

import { el, clear } from '../../core/dom.js';
import {
  button, card, openModal, notify, alertBox, table, field, input, select,
  emptyStated, confirmDialog, badge, checkboxGroup,
} from '../../ui/components.js';
import { teacherApi } from '../../api/index.js';
import { withLoading } from '../../core/bootstrap.js';
import { fmtScore, fmtNumber, normalizeTime, defaultExamWindow } from '../../core/format.js';

const PAPER_TYPES = ['radio1', 'radio2', 'checkbox', 'text'];
const DIFFS = ['easy', 'mid', 'hard'];
const DIFF_LABELS = { easy: '易', mid: '中', hard: '难' };
/** 难度字段名 → 题库 quiz_diff 代码（与 Exam::checkStock 一致） */
const DIFF_CODES = { easy: 'Y', mid: 'Z', hard: 'N' };
const TYPE_LABELS = {
  radio1: '判断题', radio2: '单选题', checkbox: '多选题', text: '填空题', longtext: '问答题',
};

/** 考生作答状态（与后端 stu_status 取值一致） */
const STU_STATUS = {
  waiting: { label: '待考', tone: '' },
  online: { label: '答题中', tone: 'success' },
  locked: { label: '已锁定', tone: 'warning' },
  over: { label: '已交卷', tone: 'info' },
};

function stuStatusBadge(s) {
  const key = String(s || 'waiting');
  const m = STU_STATUS[key] || (key.startsWith('over') ? STU_STATUS.over : { label: key, tone: '' });
  return badge(m.label, { tone: m.tone, dot: true });
}

function extractTime(dt) {
  const m = /(\d{1,2}:\d{2})/.exec(String(dt ?? ''));
  return m ? m[1] : '';
}

/**
 * 参考班级取值归一为班级 ID 数组。
 * 旧系统（及历史测试数据）把班级「名称」存进 examinfo.stu_class，
 * 而班级匹配走的是 stuinfo.class_id（班级 ID），名称无法命中，
 * 因此这里把名称折算回对应的班级 ID；两边都认不出时原样保留。
 */
function toClassIds(value, classes) {
  return String(value ?? '')
    .split(',')
    .map((s) => s.trim())
    .filter((s) => s !== '')
    .map((token) => {
      const byId = classes.find((c) => String(c.id) === token);
      if (byId) return String(byId.id);
      const byName = classes.find((c) => String(c.class_name) === token);
      return byName ? String(byName.id) : token;
    });
}

/**
 * 打开考试编辑弹窗
 * @param {object}   opts
 * @param {number|null} opts.id       为 null 表示新增
 * @param {object}   opts.options     { subjects, categories, teachers, grades, classes }
 * @param {Function} opts.onSaved     保存成功后回调（用于刷新列表）
 */
export async function openExamEditor({ id = null, options = {}, onSaved } = {}) {
  const isEdit = id !== null && id !== undefined;
  let row = {};
  if (isEdit) {
    const res = await teacherApi.exam(id).catch(() => null);
    row = res || {};
  }

  const subjects = options.subjects || [];
  const categories = options.categories || [];
  const teachers = options.teachers || [];
  const classes = options.classes || [];

  const nameInput = input({ value: row.exam_name || '', placeholder: '如：2026 年秋季驾驶理论考试' });
  const subjSelect = select(subjects.map((s) => ({ value: s.id, label: s.subj_name })),
    { value: String(row.subj_id ?? ''), placeholder: '请选择科目' });
  const catSelect = select(categories.map((c) => ({ value: c.id, label: c.category_name })),
    { value: String(row.exam_category_id ?? ''), placeholder: '请选择类别' });

  // 新建时的时间默认值：开始 = 当前 + 10 分钟，结束 = 开始 + 1 小时；编辑时沿用原有时间
  const def = defaultExamWindow();
  const dateInput = input({ type: 'date', value: (isEdit ? row.exam_start : def.date)?.slice(0, 10) || def.date });
  const startInput = input({ value: extractTime(isEdit ? row.exam_start : '') || def.start, placeholder: '如 17:50' });
  const endInput = input({
    value: extractTime(isEdit ? row.exam_end : '') || (isEdit ? '' : def.end),
    placeholder: '如 19:50（跨天自动识别）',
  });
  const teacherInput = input({ value: row.exam_tea || '', placeholder: '留空默认填自己' });

  // 参考班级：多选（提交班级 ID 列表）。仅有一个班级时默认选中该班级。
  const classCtrl = checkboxGroup(
    classes.map((c) => ({ value: c.id, label: c.class_name })),
    {
      values: isEdit
        ? toClassIds(row.stu_class, classes)
        : (classes.length === 1 ? [classes[0].id] : []),
    },
  );

  /* ---------- 组卷矩阵 ---------- */
  const counts = {};
  const scores = {};
  const matrixRows = [];
  const matrixBody = el('tbody');
  for (const t of PAPER_TYPES) {
    const tds = [el('td', {}, [el('div.fw-500', { text: TYPE_LABELS[t] || t })])];
    const countCtls = [];
    for (const d of DIFFS) {
      const key = `${t}_${d}_sum`;
      // 题目数量只允许数字：text + inputmode 而非 number（number 仍可输入 - . e）
      const ctl = input({ type: 'text', value: row[key] ?? 0, class: 'input-sm', inputmode: 'numeric' });
      ctl.style.width = '72px';
      bindCountControl(ctl, t, d);
      counts[key] = ctl;
      countCtls.push(ctl);
      tds.push(el('td', { align: 'center' }, [ctl]));
    }
    const valKey = `${t}_val`;
    const valCtl = input({ type: 'number', value: row[valKey] ?? 5, class: 'input-sm' });
    valCtl.min = '0';
    valCtl.step = '0.5';
    valCtl.style.width = '72px';
    scores[valKey] = valCtl;
    tds.push(el('td', { align: 'center' }, [valCtl]));
    const subTotal = el('td.col-num.mono.fw-600');
    tds.push(subTotal);
    matrixBody.append(el('tr', {}, tds));
    matrixRows.push({ counts: countCtls, val: valCtl, subTotal });
  }

  const totalQty = el('span.mono.fw-700', { text: '0' });
  const totalScore = el('span.mono.fw-700.c-brand', { text: '0' });
  function recalc() {
    let q = 0; let s = 0;
    for (const r of matrixRows) {
      const n = r.counts.reduce((a, c) => a + (Number(c.value) || 0), 0);
      const per = Number(r.val.value) || 0;
      r.subTotal.textContent = String(n * per);
      q += n;
      s += n * per;
    }
    totalQty.textContent = String(q);
    totalScore.textContent = fmtScore(s);
  }
  for (const r of matrixRows) {
    r.counts.forEach((c) => c.addEventListener('input', recalc));
    r.val.addEventListener('input', recalc);
  }
  recalc();

  const matrixTable = el('table.table.table-sm', { style: { minWidth: '520px' } }, [
    el('thead', {}, [el('tr', {}, [
      el('th', { text: '题型' }),
      ...DIFFS.map((d) => el('th', { text: DIFF_LABELS[d], class: 'text-center', style: { width: '82px' } })),
      el('th', { text: '每题分', class: 'text-center', style: { width: '82px' } }),
      el('th', { text: '小计', class: 'col-num', style: { width: '80px' } }),
    ])]),
    matrixBody,
  ]);

  const stockSlot = el('div');
  function collectMatrix() {
    const out = {};
    for (const [k, ctl] of Object.entries(counts)) out[k] = Number(ctl.value) || 0;
    for (const [k, ctl] of Object.entries(scores)) out[k] = Number(ctl.value) || 0;
    return out;
  }

  /**
   * 数量输入框：① 只允许数字（粘贴/输入法也会被过滤）；② 失焦校验题库存量。
   * 过滤挂在 input 上、recalc 挂在其后注册，故 recalc 读到的总是过滤后的值。
   */
  function bindCountControl(ctl, type, diff) {
    ctl.addEventListener('beforeinput', (e) => {
      if (e.data != null && /\D/.test(String(e.data))) e.preventDefault();
    });
    ctl.addEventListener('input', () => {
      const digits = String(ctl.value).replace(/\D+/g, '').replace(/^0+(?=\d)/, '');
      if (digits !== ctl.value) ctl.value = digits;
      ctl.classList.remove('is-invalid');
    });
    ctl.addEventListener('blur', () => { void checkCellStock(ctl, type, diff); });
  }

  /**
   * 失焦校验：该「题型 × 难度」在所选科目下的题库存量是否够用。
   * 不足时 toast 提醒并把焦点交还该输入框，避免用户误以为数量已生效。
   */
  async function checkCellStock(ctl, type, diff) {
    const need = Number(ctl.value) || 0;
    const subjId = Number(subjSelect.value) || 0;
    // 数量为 0 无需求；未选科目则无从判断（保存时后端仍会兜底校验）
    if (need <= 0 || subjId <= 0) return;

    // 失焦可能连续发生，用序号丢弃过期响应，避免慢请求把焦点抢回来
    const seq = String(Number(ctl.dataset.stockSeq || 0) + 1);
    ctl.dataset.stockSeq = seq;
    const res = await teacherApi.examQuizCount(0, { subj_id: subjId, ...collectMatrix() })
      .catch(() => null);
    if (ctl.dataset.stockSeq !== seq || !ctl.isConnected) return;

    const miss = (res?.shortfall || []).find((s) => s.type === type && s.diff === DIFF_CODES[diff]);
    if (miss === undefined) { ctl.classList.remove('is-invalid'); return; }

    ctl.classList.add('is-invalid');
    notify.warning(
      `${TYPE_LABELS[type] || type}（${DIFF_LABELS[diff]}）题量不足：题库仅 ${miss.have} 道，当前需要 ${miss.need} 道`,
      { title: '题量不足' },
    );
    ctl.focus();
    ctl.select?.();
  }

  const checkBtn = button('检测题库库存', {
    variant: 'secondary', iconName: 'database',
    onClick: (e) => withLoading(e.currentTarget, async () => {
      const matrix = collectMatrix();
      const res = await teacherApi.examQuizCount(0, {
        subj_id: Number(subjSelect.value) || 0, ...matrix,
      }).catch(() => null);
      clear(stockSlot);
      const problems = res?.problems || [];
      if (res?.ok) {
        stockSlot.append(alertBox(
          `题库充足，可组卷 ${fmtNumber(res.total_questions)} 题，满分 ${fmtScore(res.computed_score)} 分`,
          { type: 'success', title: '库存检查通过' },
        ));
      } else if (problems.length) {
        stockSlot.append(alertBox(problems.join('；'), { type: 'danger', title: '题库不足' }));
      } else {
        stockSlot.append(alertBox('库存不足，请调整组卷参数或补充题库', { type: 'warning' }));
      }
    }, { silent: true }),
  });

  const form = el('div.stack', {}, [
    el('div.form-grid', {}, [
      el('div.span-2', {}, [field('考试名称', nameInput, { required: true })]),
      field('科目', subjSelect, { required: true }),
      field('考试类别', catSelect),
      field('考试日期', dateInput, { required: true }),
      field('监考教师', teacherInput, { hint: '留空则默认填当前登录教师' }),
      field('开始时间', startInput, { required: true, hint: '支持 1750 / 17:50 等写法' }),
      field('结束时间', endInput, { required: true, hint: '默认开始后 1 小时；跨天会自动加一天' }),
      el('div.span-2', {}, [field('参考班级', classCtrl, {
        hint: classes.length
          ? '可多选，仅选中的班级考生参加本场考试；不选则考生看不到该考试，也无法出题'
          : '暂无班级数据，请先联系管理员在「班级管理」中添加班级',
      })]),
    ]),
    card({
      title: '组卷参数',
      iconName: 'layers',
      body: el('div.stack', {}, [
        el('div.table-wrap', {}, [matrixTable]),
        el('div.flex.items-center.gap-4', {}, [
          el('span.fs-sm.c-secondary', { text: '合计题量' }), totalQty,
          el('span.fs-sm.c-secondary', { text: '满分' }), totalScore,
        ]),
        stockSlot,
      ]),
    }),
  ]);

  const errSlot = el('div');
  const submitBtn = button(isEdit ? '保存修改' : '创建考试', { variant: 'primary' });
  const dlg = openModal({
    title: isEdit ? `编辑考试 #${id}` : '新建考试',
    body: el('div.stack', {}, [form, el('div.flex.gap-2', {}, [checkBtn]), errSlot]),
    size: 'xl',
    footer: [button('取消', { variant: 'secondary', onClick: () => dlg.close() }), submitBtn],
  });

  submitBtn.addEventListener('click', async () => {
    clear(errSlot);
    const payload = {
      exam_name: nameInput.value.trim(),
      subj_id: Number(subjSelect.value) || 0,
      exam_category_id: Number(catSelect.value) || 0,
      exam_date: dateInput.value,
      exam_start_time: normalizeTime(startInput.value),
      exam_end_time: normalizeTime(endInput.value),
      exam_tea: teacherInput.value.trim(),
      stu_class: classCtrl.values().join(','),
      ...collectMatrix(),
    };
    if (!payload.exam_name) { errSlot.append(alertBox('请填写考试名称', { type: 'warning' })); return; }
    if (!payload.subj_id) { errSlot.append(alertBox('请选择科目', { type: 'warning' })); return; }
    if (!payload.exam_start_time) { errSlot.append(alertBox('请填写开始时间', { type: 'warning' })); return; }

    const { ok, error } = await withLoading(submitBtn, () => (
      isEdit ? teacherApi.updateExam(id, payload) : teacherApi.createExam(payload)
    ), { silent: true });

    if (ok) {
      notify.success(isEdit ? '考试已更新' : '考试已创建');
      dlg.close();
      onSaved?.();
    } else if (error) {
      errSlot.append(alertBox(error.message || '保存失败', { type: 'danger' }));
    }
  });
}

/** 删除考试（仅未开考/未结束可删） */
export async function deleteExam(row, onDone) {
  const ok = await confirmDialog(`确定删除考试「${row.exam_name}」吗？`, {
    title: '删除考试',
    confirmText: '删除',
    tone: 'danger',
    detail: '仅未开考或已组卷但未开考的考试可删除，删除后不可恢复。',
  });
  if (!ok) return;
  const { ok: done, error } = await withLoading(null, () => teacherApi.deleteExam(row.id), { silent: true });
  if (done) {
    notify.success('考试已删除');
    onDone?.();
  } else {
    notify.error(error?.message || '删除失败');
  }
}

/** 查看考试考生名单 */
export async function openExamStudents(row) {
  const data = await teacherApi.examStudents(row.id).catch(() => null);
  const list = data?.list || [];
  const summary = data?.summary || {};

  const dlg = openModal({
    title: `考生名单 · ${row.exam_name}`,
    size: 'lg',
    body: el('div.stack', {}, [
      el('div.flex.gap-3.flex-wrap', {}, [
        badge(`${list.length} 人`, { tone: 'brand' }),
        el('span.fs-sm.c-secondary', {
          text: data?.source === 'paper' ? '来源：已排卷成绩名单' : '来源：参考班级候选考生',
        }),
        el('span.fs-sm.c-secondary', { text: `已交卷 ${summary.over ?? 0} 人` }),
      ]),
      list.length
        ? table({
          size: 'sm',
          columns: [
            { key: 'stu_id', title: '准考证号', render: (r) => el('span.mono', { text: String(r.stu_id ?? r.id ?? '—') }) },
            { key: 'stu_name', title: '姓名', render: (r) => el('span.fw-500', { text: r.stu_name || '—' }) },
            { key: 'stu_sex', title: '性别', align: 'center', render: (r) => el('span', { text: r.stu_sex || '—' }) },
            { key: 'grade_id', title: '单位', render: (r) => el('span.fs-sm', { text: r.grade_id || '—' }) },
            { key: 'class_id', title: '班级', render: (r) => el('span.fs-sm', { text: r.class_id || '—' }) },
            { key: 'stu_status', title: '状态', align: 'center', render: (r) => stuStatusBadge(r.stu_status) },
            {
              key: 'stu_score',
              title: '得分',
              align: 'right',
              render: (r) => el('strong.mono', { text: fmtScore(r.stu_score) }),
            },
          ],
          rows: list,
        })
        : emptyStated('暂无考生', { iconName: 'users', desc: '该考试尚未排卷，且未指定参考班级' }),
    ]),
    footer: [button('关闭', { variant: 'secondary', onClick: () => dlg.close() })],
  });
}
