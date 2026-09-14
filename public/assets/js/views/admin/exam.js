/**
 * 管理后台 —— 考试管理
 * 组卷参数（题型×难度数量 + 每题分值）、生成试卷、启动考试、口令查看
 */

import { el, mount, clear, formData } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import {
  button, badge, card, openModal, confirmDialog, notify, alertBox,
  table, descList, tabs, segmented, emptyStated, field, input, select, checkbox,
  copyWithToast,
} from '../../ui/components.js';
import { createListView, openFormModal, confirmDelete } from '../../ui/crud.js';
import { adminApi } from '../../api/index.js';
import { withLoading } from '../../core/bootstrap.js';
import { fmtDate, fmtDateTime, fmtScore, fmtNumber, normalizeTime, today, nowTime } from '../../core/format.js';
import { QUIZ_TYPE_LABELS } from './quiz.js';

/** 组卷参与的种类（与后端 TYPE_PREFIXES 对应） */
const PAPER_TYPES = ['radio1', 'radio2', 'checkbox', 'text'];
const DIFFS = ['easy', 'mid', 'hard'];
const DIFF_LABELS = { easy: '易', mid: '中', hard: '难' };

export const EXAM_STATUS = {
  testing: { label: '进行中', tone: 'success' },
  exam:    { label: '待开考', tone: 'warning' },
  paper:   { label: '已组卷', tone: 'info' },
  over:    { label: '已结束', tone: '' },
  overBak: { label: '已归档', tone: '' },
};

export function statusBadge(s) {
  const m = EXAM_STATUS[s] || { label: s || '—', tone: '' };
  return badge(m.label, { tone: m.tone, dot: true, pulse: s === 'testing' });
}

export async function ExamView({ router, can }) {
  const refCache = { subjects: [], categories: [], classes: [] };

  const loadRefs = async () => {
    if (refCache.subjects.length) return refCache;
    const [subs, cats, classes] = await Promise.all([
      adminApi.subjects({ per_page: 200 }),
      adminApi.categories({ per_page: 200 }),
      adminApi.classes({ per_page: 200 }),
    ]);
    refCache.subjects = subs?.list || [];
    refCache.categories = cats?.list || [];
    refCache.classes = classes?.list || [];
    return refCache;
  };

  const list = createListView({
    title: '考试管理',
    desc: '创建考试、组卷、启动与口令管理',
    defaultPerPage: 20,

    filters: [
      { type: 'search', name: 'keyword', placeholder: '搜索考试名称…' },
      { type: 'select', name: 'subj_id', placeholder: '全部科目', width: '180px',
        options: async () => (await loadRefs()).subjects.map((s) => ({ value: s.id, label: s.subj_name })) },
      { type: 'select', name: 'status', placeholder: '全部状态', width: '130px',
        options: Object.entries(EXAM_STATUS).map(([value, m]) => ({ value, label: m.label })) },
    ],

    actions: () => [
      button('新建考试', { variant: 'primary', iconName: 'plus', onClick: () => openEditor(null) }),
    ],

    columns: [
      { key: 'id', title: 'ID', width: '70px', sortable: true,
        render: (r) => el('span.mono.fs-sm.c-tertiary', { text: String(r.id) }) },
      { key: 'exam_name', title: '考试名称', sortable: true,
        render: (r) => el('div', {}, [
          el('div.fw-500', { text: r.exam_name }),
          el('div.fs-xs.c-tertiary.flex.items-center.gap-2', {}, [
            el('span', { text: r.subj_name || '—' }),
            r.category_name ? el('span', { text: '·' }) : null,
            r.category_name ? el('span', { text: r.category_name }) : null,
          ].filter(Boolean)),
        ]) },
      { key: 'stu_class', title: '参考班级', width: '140px',
        render: (r) => el('span.fs-sm', { text: r.stu_class || r.exam_class || '—' }) },
      { key: 'exam_start', title: '考试时间', width: '180px',
        render: (r) => el('div.fs-sm.mono', {}, [
          el('div', { text: fmtDateTime(r.exam_start) }),
          el('div.c-tertiary', { text: fmtDateTime(r.exam_end) }),
        ]) },
      { key: 'total_questions', title: '题量', align: 'right', width: '80px',
        render: (r) => el('span.mono', { text: fmtNumber(r.total_questions) }) },
      { key: 'computed_score', title: '满分', align: 'right', width: '80px',
        render: (r) => el('span.mono.fw-600', { text: fmtScore(r.computed_score) }) },
      { key: 'exam_status', title: '状态', align: 'center', width: '100px',
        render: (r) => statusBadge(r.exam_status) },
      { key: 'progress', title: '考生进度', width: '150px',
        render: (r) => {
          const s = r.status_summary || {};
          const total = Number(s.total ?? 0);
          if (!total) return el('span.fs-xs.c-tertiary', { text: '未开考' });
          return el('div', {}, [
            el('div.flex.gap-2.fs-xs.mb-1', {}, [
              s.over ? el('span.c-success', { text: `交卷 ${s.over}` }) : null,
              s.online ? el('span.c-brand', { text: `在线 ${s.online}` }) : null,
              s.locked ? el('span.c-warning', { text: `锁定 ${s.locked}` }) : null,
              s.waiting ? el('span.c-tertiary', { text: `待考 ${s.waiting}` }) : null,
            ].filter(Boolean)),
            el('div.fs-xs.c-tertiary', { text: `共 ${total} 人` }),
          ]);
        } },
    ],

    fetch: (params) => adminApi.exams(params),

    rowActions: (row) => {
      const st = String(row.exam_status || '');
      const notTesting = st === 'exam' || st === 'paper';
      const pwdReady = !!(row.exam_pwd && String(row.exam_pwd) !== '0');

      const btns = [
        button('', { variant: 'ghost', size: 'sm', iconName: 'eye', title: '详情', onClick: () => openDetail(row) }),
        button('', { variant: 'ghost', size: 'sm', iconName: 'edit', title: '编辑', onClick: () => openEditor(row.id) }),
      ];
      if (notTesting) {
        btns.push(button('', {
          variant: pwdReady ? 'ghost' : 'primary', size: 'sm', iconName: 'login',
          title: pwdReady ? '重新生成口令' : '开放入场', onClick: () => doOpen(row),
        }));
        if (st === 'exam') {
          btns.push(button('', { variant: 'ghost', size: 'sm', iconName: 'sparkles', title: '出题',
            onClick: () => openGenerate(row) }));
        }
        btns.push(button('', { variant: 'primary', size: 'sm', iconName: 'play', title: '开考',
          onClick: () => doStart(row) }));
      }
      btns.push(button('', { variant: 'ghost', size: 'sm', iconName: 'monitor', title: '监考',
        onClick: () => router.navigate(`/monitor?exam_id=${row.id}`) }));
      if (notTesting) {
        btns.push(button('', { variant: 'ghost', size: 'sm', iconName: 'trash', title: '删除',
          onClick: () => doDelete(row) }));
      }
      return btns;
    },
  });

  /* ============================ 编辑器 ============================ */
  async function openEditor(id) {
    const refs = await loadRefs();
    const isEdit = id !== null && id !== undefined;
    let row = {};
    if (isEdit) {
      const res = await adminApi.exam(id);
      row = res || {};
    }

    /* 基础信息 */
    const nameInput = input({ value: row.exam_name || '', placeholder: '如：2026 年秋季驾驶理论考试' });
    const subjSelect = select(refs.subjects.map((s) => ({ value: s.id, label: s.subj_name })),
      { value: String(row.subj_id ?? ''), placeholder: '请选择科目' });
    const catSelect = select(refs.categories.map((c) => ({ value: c.id, label: c.category_name })),
      { value: String(row.exam_category_id ?? ''), placeholder: '请选择类别' });
    const dateInput = input({ type: 'date', value: (row.exam_start || today()).slice(0, 10) });
    const startInput = input({ type: 'text', value: extractTime(row.exam_start) || nowTime(), placeholder: '如 17:50' });
    const endInput = input({ type: 'text', value: extractTime(row.exam_end) || '' , placeholder: '如 19:50（跨天自动识别）' });
    const teacherInput = input({ value: row.exam_tea || '', placeholder: '监考教师姓名' });
    const classInput = input({ value: row.stu_class || '', placeholder: '多个班级用逗号分隔，留空表示全部' });

    /* 组卷矩阵 */
    const counts = {};
    const scores = {};
    const matrixRows = [];
    const matrixBody = el('tbody');
    for (const t of PAPER_TYPES) {
      const rowEls = {};
      const tds = [el('td', {}, [el('div.fw-500', { text: QUIZ_TYPE_LABELS[t] || t })])];
      for (const d of DIFFS) {
        const key = `${t}_${d}_sum`;
        const ctl = input({ type: 'number', value: row[key] ?? 0, class: 'input-sm' });
        ctl.min = '0';
        ctl.style.width = '72px';
        counts[key] = ctl;
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
      const tr = el('tr', {}, tds);
      rowEls.ctl = { counts: DIFFS.map((d) => counts[`${t}_${d}_sum`]), val: valCtl, subTotal, type: t };
      matrixRows.push(rowEls);
      matrixBody.append(tr);
    }

    const totalSlot = el('div.flex.items-center.gap-4', {}, [
      el('span.fs-sm.c-secondary', { text: '合计题量' }),
      el('span.mono.fw-700', { id: 'ttcq', text: '0' }),
      el('span.fs-sm.c-secondary', { text: '满分' }),
      el('span.mono.fw-700.c-brand', { id: 'ttsc', text: '0' }),
    ]);

    function recalc() {
      let q = 0, s = 0;
      for (const r of matrixRows) {
        const n = r.ctl.counts.reduce((a, c) => a + (Number(c.value) || 0), 0);
        const per = Number(r.ctl.val.value) || 0;
        const sub = n * per;
        r.ctl.subTotal.textContent = String(sub);
        q += n;
        s += sub;
      }
      totalSlot.querySelector('#ttcq').textContent = String(q);
      totalSlot.querySelector('#ttsc').textContent = fmtScore(s);
    }
    for (const r of matrixRows) {
      r.ctl.counts.forEach((c) => c.addEventListener('input', recalc));
      r.ctl.val.addEventListener('input', recalc);
    }
    recalc();

    const matrixTable = el('table.table.table-sm', {
      style: { minWidth: '520px' },
    }, [
      el('thead', {}, [el('tr', {}, [
        el('th', { text: '题型' }),
        ...DIFFS.map((d) => el('th', { text: DIFF_LABELS[d], class: 'text-center', style: { width: '82px' } })),
        el('th', { text: '每题分', class: 'text-center', style: { width: '82px' } }),
        el('th', { text: '小计', class: 'col-num', style: { width: '80px' } }),
      ])]),
      matrixBody,
    ]);

    const stockSlot = el('div');

    const form = el('div.stack', {}, [
      el('div.form-grid', {}, [
        el('div.span-2', {}, [field('考试名称', nameInput, { required: true })]),
        field('科目', subjSelect, { required: true }),
        field('考试类别', catSelect),
        field('考试日期', dateInput, { required: true }),
        field('监考教师', teacherInput),
        field('开始时间', startInput, {
          required: true,
          hint: '支持 1750 / 17:50 / 17：50 等多种写法',
        }),
        field('结束时间', endInput, { hint: '留空则默认开始后 2 小时；跨天会自动加一天' }),
        el('div.span-2', {}, [field('参考班级', classInput, {
          hint: '留空表示所有班级；也可填写 exam_class 展示名',
        })]),
      ]),
      card({
        title: '组卷参数',
        iconName: 'layers',
        body: el('div.stack', {}, [
          el('div.table-wrap', {}, [matrixTable]),
          totalSlot,
          stockSlot,
        ]),
      }),
    ]);

    /* 库存校验 */
    const checkBtn = button('检测题库库存', {
      variant: 'secondary', iconName: 'database',
      onClick: (e) => withLoading(e.currentTarget, async () => {
        const res = await adminApi.examQuizCount(0, {
          subj_id: Number(subjSelect.value) || 0,
          ...collectMatrix(),
        });
        clear(stockSlot);
        const problems = res?.problems || [];
        if (res?.ok) {
          stockSlot.append(alertBox(
            `题库充足，可组卷 ${fmtNumber(res.total_questions)} 题，满分 ${fmtScore(res.computed_score)} 分`,
            { type: 'success', title: '库存检查通过' }
          ));
        } else if (problems.length) {
          stockSlot.append(alertBox(problems.join('；'), { type: 'danger', title: '题库不足' }));
        } else {
          stockSlot.append(alertBox('库存不足，请调整组卷参数或补充题库', { type: 'warning' }));
        }
      }, { silent: true }),
    });

    function collectMatrix() {
      const out = {};
      for (const [k, ctl] of Object.entries(counts)) out[k] = Number(ctl.value) || 0;
      for (const [k, ctl] of Object.entries(scores)) out[k] = Number(ctl.value) || 0;
      return out;
    }

    const errSlot = el('div');
    const submitBtn = button(isEdit ? '保存修改' : '创建考试', { variant: 'primary' });

    const bodyWrap = el('div.stack', {}, [form, el('div.flex.gap-2', {}, [checkBtn]), errSlot]);
    const dlg = openModal({
      title: isEdit ? `编辑考试 #${id}` : '新建考试',
      body: bodyWrap, size: 'xl',
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
        stu_class: classInput.value.trim(),
        ...collectMatrix(),
      };

      if (!payload.exam_name) { errSlot.append(alertBox('请填写考试名称', { type: 'warning' })); return; }
      if (!payload.subj_id) { errSlot.append(alertBox('请选择科目', { type: 'warning' })); return; }
      if (!payload.exam_start_time) { errSlot.append(alertBox('请填写开始时间', { type: 'warning' })); return; }

      const { ok, error } = await withLoading(submitBtn, () => (
        isEdit ? adminApi.updateExam(id, payload) : adminApi.createExam(payload)
      ), { silent: true });

      if (ok) {
        notify.success(isEdit ? '考试已更新' : '考试已创建');
        dlg.close();
        list.load();
      } else if (error) {
        const detail = error.data?.shortfall ? `缺题：${Object.entries(error.data.shortfall).map(([k, v]) => `${QUIZ_TYPE_LABELS[k.split('_')[0]] || k}缺 ${v}`).join('、')}` : '';
        errSlot.append(alertBox(error.message || '保存失败', { type: 'danger', title: detail || '' }));
      }
    });
  }

  /* ============================ 开放入场 ============================ */
  async function doOpen(row) {
    const pwdReady = !!(row.exam_pwd && String(row.exam_pwd) !== '0');
    const ok = await confirmDialog(
      pwdReady
        ? '重新生成口令后原口令立即失效，已进入考场的考生需重新输入新口令。确定继续？'
        : `确定开放「${row.exam_name}」的入场吗？开放后考生可在开考前 15 分钟内凭口令进入考场。`,
      {
        title: pwdReady ? '重新生成口令' : '开放入场',
        confirmText: pwdReady ? '重新生成' : '开放入场',
        tone: 'warning',
      },
    );
    if (!ok) return;

    const { ok: done, error, result } = await withLoading(null, () => adminApi.openExam(row.id), { silent: true });
    if (!done) { notify.error(error?.message || '开放入场失败'); return; }
    const pwd = result?.exam_pwd ?? result?.pwd ?? '';
    notify.success(result?.message || '已开放入场');
    openModal({
      title: '已开放入场',
      size: 'sm',
      body: el('div.stack', {}, [
        alertBox(`「${row.exam_name}」已开放入场。考生可在开考前 15 分钟内，用准考证号 + 以下口令进入考场。`, { type: 'success' }),
        el('div', {
          style: {
            textAlign: 'center', padding: 'var(--sp-6)',
            background: 'var(--bg-sunken)', border: '1px solid var(--border-subtle)',
            borderRadius: 'var(--radius-md)',
          },
        }, [
          el('div.fs-sm.c-secondary.mb-2', { text: '考场口令' }),
          el('div.mono.fw-700', {
            style: { fontSize: 'var(--fs-4xl)', letterSpacing: '.12em', color: 'var(--brand-600)' },
            text: String(pwd || '—'),
          }),
        ]),
        button('复制口令', { variant: 'secondary', iconName: 'copy', block: true,
          onClick: () => copyWithToast(pwd, '考场口令') }),
      ]),
    });
    list.load();
  }

  /* ============================ 生成试卷（出题） ============================ */
  async function openGenerate(row) {
    const detail = await adminApi.exam(row.id).catch(() => null);
    const studentCount = Number(detail?.stock?.student_count ?? 0);

    const body = el('div.stack');
    body.append(alertBox(`将为符合「${row.stu_class || '全部班级'}」条件的考生逐人生成试卷。生成后考试状态变为「已组卷」。`, { type: 'info' }));

    if (detail?.stock) {
      body.append(descList([
        ['参考班级', row.stu_class || '全部班级'],
        ['题量', `${fmtNumber(row.total_questions)} 题`],
        ['满分', fmtScore(row.computed_score)],
        ['预计考生数', fmtNumber(studentCount)],
      ]));
    }

    if (detail?.warnings?.length) {
      body.append(alertBox(detail.warnings.join('；'), { type: 'warning' }));
    }

    const resultSlot = el('div');
    body.append(resultSlot);

    const genBtn = button('开始生成试卷', { variant: 'primary', iconName: 'sparkles' });
    const dlg = openModal({
      title: `生成试卷 · ${row.exam_name}`,
      body, size: 'lg',
      footer: [button('关闭', { variant: 'secondary', onClick: () => dlg.close() }), genBtn],
    });

    genBtn.addEventListener('click', async () => {
      const ok = await confirmDialog(`确定为 ${studentCount || '全部'} 名考生生成试卷吗？`, {
        title: '生成试卷', confirmText: '生成', tone: 'warning',
        detail: '已存在的试卷不会被覆盖。',
      });
      if (!ok) return;

      const { ok: done, error, result } = await withLoading(genBtn, () => adminApi.generatePapers(row.id, {}), { silent: true });
      clear(resultSlot);
      if (done) {
        const r = result || {};
        resultSlot.append(alertBox(
          `生成完成：成功 ${r.generated ?? r.count ?? 0} 份${r.skipped ? `，跳过 ${r.skipped} 份` : ''}`,
          { type: 'success', title: '试卷生成成功' }
        ));
        if (r.warnings?.length) resultSlot.append(alertBox(r.warnings.join('；'), { type: 'warning' }));
        list.load();
      } else if (error) {
        resultSlot.append(alertBox(error.message || '生成失败', { type: 'danger' }));
      }
    });
  }

  /* ============================ 启动考试（开考） ============================ */
  async function doStart(row) {
    const ok = await confirmDialog(`确定开始考试「${row.exam_name}」吗？`, {
      title: '开始考试', confirmText: '立即开考', tone: 'warning',
      detail: '开考后考生将无法再进入考场，已在场的考生立即进入答题界面。',
    });
    if (!ok) return;

    const { ok: done, error, result } = await withLoading(null, () => adminApi.startExam(row.id), { silent: true });
    if (done) {
      const pwd = result?.exam_pwd ?? result?.pwd ?? '—';
      openModal({
        title: '考试已启动',
        size: 'sm',
        body: el('div.stack', {}, [
          alertBox('请将考试口令告知考生，考试结束前请勿泄露。', { type: 'success' }),
          el('div', {
            style: {
              textAlign: 'center', padding: 'var(--sp-6)',
              background: 'var(--bg-sunken)', border: '1px solid var(--border-subtle)',
              borderRadius: 'var(--radius-md)',
            },
          }, [
            el('div.fs-sm.c-secondary.mb-2', { text: '考试口令' }),
            el('div.mono.fw-700', {
              style: { fontSize: 'var(--fs-4xl)', letterSpacing: '.12em', color: 'var(--brand-600)' },
              text: String(pwd),
            }),
          ]),
          descList([
            ['考试名称', row.exam_name],
            ['开始时间', fmtDateTime(row.exam_start)],
            ['结束时间', fmtDateTime(row.exam_end)],
          ]),
        ]),
      });
      list.load();
    } else if (error) {
      notify.error(error.message || '启动失败');
    }
  }

  /* ============================ 详情 ============================ */
  async function openDetail(row) {
    const detail = await adminApi.exam(row.id).catch(() => null);
    const body = el('div.stack');

    body.append(descList([
      ['考试名称', row.exam_name],
      ['科目', row.subj_name || '—'],
      ['类别', row.category_name || '—'],
      ['参考班级', row.stu_class || row.exam_class || '全部'],
      ['监考教师', row.exam_tea || '—'],
      ['开始时间', fmtDateTime(row.exam_start)],
      ['结束时间', fmtDateTime(row.exam_end)],
      ['状态', statusBadge(row.exam_status)],
      ['口令', row.exam_pwd ? el('span.mono.fw-600', { text: String(row.exam_pwd) }) : '未启动'],
    ]));

    const plan = detail?.paper_plan || {};
    const planRows = PAPER_TYPES.map((t) => {
      const counts3 = DIFFS.map((d) => Number(plan[`${t}_${d}_sum`] ?? row[`${t}_${d}_sum`] ?? 0));
      const per = Number(plan[`${t}_val`] ?? row[`${t}_val`] ?? 0);
      const n = counts3.reduce((a, b) => a + b, 0);
      return el('tr', {}, [
        el('td', { text: QUIZ_TYPE_LABELS[t] || t }),
        ...counts3.map((c) => el('td.col-num', { text: String(c) })),
        el('td.col-num', { text: fmtScore(per) }),
        el('td.col-num.fw-600', { text: String(n) }),
        el('td.col-num.fw-600.c-brand', { text: fmtScore(n * per) }),
      ]);
    });

    body.append(el('hr.divider'));
    body.append(el('div.fs-sm.c-secondary.mb-2', { text: '组卷配置' }));
    body.append(el('div.table-wrap', {}, [el('table.table.table-sm', {}, [
      el('thead', {}, [el('tr', {}, [
        el('th', { text: '题型' }),
        ...DIFFS.map((d) => el('th', { text: DIFF_LABELS[d], class: 'col-num' })),
        el('th', { text: '每题分', class: 'col-num' }),
        el('th', { text: '题量', class: 'col-num' }),
        el('th', { text: '小计', class: 'col-num' }),
      ])]),
      el('tbody', {}, planRows),
    ])]));

    body.append(el('div.flex.gap-4.mt-4', {}, [
      el('div.flex-1', {}, [card({ title: '汇总', body: descList([
        ['总题量', `${fmtNumber(row.total_questions)} 题`],
        ['试卷满分', fmtScore(row.computed_score)],
      ]) })]),
    ]));

    if (detail?.status_summary) {
      const s = detail.status_summary;
      body.append(card({ title: '考生进度', body: el('div.flex.gap-4.flex-wrap', {}, [
        badge(`共 ${s.total ?? 0} 人`, {}),
        badge(`待考 ${s.waiting ?? 0}`, { tone: 'warning' }),
        badge(`在线 ${s.online ?? 0}`, { tone: 'brand' }),
        badge(`锁定 ${s.locked ?? 0}`, { tone: 'danger' }),
        badge(`已交卷 ${s.over ?? 0}`, { tone: 'success' }),
      ]) }));
    }

    openModal({ title: `考试详情 · ${row.exam_name}`, body, size: 'lg' });
  }

  /* ============================ 删除 ============================ */
  async function doDelete(row) {
    await confirmDelete(`确定删除考试「${row.exam_name}」吗？`, {
      detail: '将同时删除该考试的所有试卷与成绩记录，操作不可撤销。',
      onConfirm: () => adminApi.deleteExam(row.id),
    }).then((done) => { if (done) list.load(); });
  }

  return list.root;
}

/** 从 "2026-09-13 17:50:00" 提取 "17:50" */
function extractTime(dt) {
  const s = String(dt ?? '');
  const m = /(\d{1,2}:\d{2})/.exec(s);
  return m ? m[1] : '';
}
