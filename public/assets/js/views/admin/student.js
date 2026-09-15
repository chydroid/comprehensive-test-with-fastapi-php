/**
 * 管理后台 —— 考生管理（含批量导入）
 */

import { el, mount, clear } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import {
  button, badge, card, openModal, confirmDialog, notify, alertBox,
  table, descList, emptyStated, field, input, textarea, select, tabs, codeBlock,
} from '../../ui/components.js';
import { createListView, openFormModal, confirmDelete } from '../../ui/crud.js';
import { adminApi } from '../../api/index.js';
import { withLoading } from '../../core/bootstrap.js';
import { fmtDate, fmtDateTime, fmtScore, initials, hashTone } from '../../core/format.js';

const TONES = [
  'linear-gradient(135deg,#6366f1,#4338ca)',
  'linear-gradient(135deg,#06b6d4,#0e7490)',
  'linear-gradient(135deg,#10b981,#047857)',
  'linear-gradient(135deg,#f59e0b,#b45309)',
  'linear-gradient(135deg,#ef4444,#b91c1c)',
  'linear-gradient(135deg,#8b5cf6,#6d28d9)',
];

export async function StudentView() {
  const refCache = { grades: [], classes: [] };
  const loadRefs = async () => {
    if (refCache.grades.length) return refCache;
    const [g, c] = await Promise.all([
      adminApi.grades({ per_page: 200 }),
      adminApi.classes({ per_page: 200 }),
    ]);
    refCache.grades = g?.list || [];
    refCache.classes = c?.list || [];
    return refCache;
  };

  const list = createListView({
    title: '考生管理',
    desc: '考生账号即准考证号，登录后可参加考试与查询成绩',
    defaultPerPage: 20,
    selectable: true,

    filters: [
      { type: 'search', name: 'keyword', placeholder: '搜索姓名或准考证号…' },
      { type: 'select', name: 'grade_id', placeholder: '全部单位', width: '160px',
        options: async () => (await loadRefs()).grades.map((g) => ({ value: g.id, label: g.grade_name })) },
      { type: 'select', name: 'class_id', placeholder: '全部班级', width: '160px',
        options: async () => (await loadRefs()).classes.map((c) => ({ value: c.id, label: c.class_name })) },
    ],

    actions: () => [
      button('新增考生', { variant: 'primary', iconName: 'plus', onClick: () => openEditor(null) }),
      button('批量导入', { variant: 'secondary', iconName: 'upload', onClick: () => openImport() }),
    ],

    bulkActions: (selected) => [
      button('批量删除', {
        variant: 'danger', size: 'sm', iconName: 'trash',
        onClick: async () => {
          const ok = await confirmDialog(`确定删除选中的 ${selected.length} 名考生吗？`, {
            title: '批量删除', confirmText: '删除', tone: 'danger',
            detail: '将同时删除其试卷与成绩记录，操作不可撤销。',
          });
          if (!ok) return;
          let okCount = 0, failCount = 0;
          for (const s of selected) {
            try { await adminApi.deleteStudent(s.id); okCount++; }
            catch (_) { failCount++; }
          }
          if (okCount) notify.success(`已删除 ${okCount} 名考生${failCount ? `，${failCount} 名失败` : ''}`);
          else notify.error('删除失败');
          list.load();
        },
      }),
    ],

    columns: [
      { key: 'id', title: '准考证号', width: '120px', sortable: true,
        render: (r) => el('span.mono.fw-500', { text: String(r.id) }) },
      { key: 'stu_name', title: '姓名', sortable: true,
        render: (r) => el('div.flex.items-center.gap-3', {}, [
          el('div.avatar.avatar-sm', { style: { background: hashTone(r.stu_name || '', TONES) }, text: initials(r.stu_name) }),
          el('span.fw-500', { text: r.stu_name || '—' }),
        ]) },
      { key: 'stu_sex', title: '性别', width: '70px', align: 'center', render: (r) => r.stu_sex || '—' },
      { key: 'grade_id', title: '单位', width: '140px',
        render: (r) => el('span.fs-sm', { text: r.grade_name || r.grade_id || '—' }) },
      { key: 'class_id', title: '班级', width: '140px',
        render: (r) => el('span.fs-sm', { text: r.class_name || r.class_id || '—' }) },
      { key: 'exam_count', title: '考试次数', align: 'center', width: '100px',
        render: (r) => badge(String(r.exam_count ?? 0), { tone: (r.exam_count ?? 0) > 0 ? 'brand' : '' }) },
    ],

    fetch: (params) => adminApi.students(params),

    rowActions: (row) => [
      button('', { variant: 'ghost', size: 'sm', iconName: 'eye', title: '成绩明细', onClick: () => openDetail(row) }),
      button('', { variant: 'ghost', size: 'sm', iconName: 'edit', title: '编辑', onClick: () => openEditor(row) }),
      button('', { variant: 'ghost', size: 'sm', iconName: 'trash', title: '删除',
        onClick: () => confirmDelete(`确定删除考生「${row.stu_name}」吗？`, {
          detail: `准考证号 ${row.id}。将同时删除其试卷与成绩记录。`,
          onConfirm: () => adminApi.deleteStudent(row.id),
        }).then((done) => { if (done) list.load(); }) }),
    ],
  });

  const refs = await loadRefs();
  const gradeOptions = refs.grades.map((g) => ({ value: g.id, label: g.grade_name }));
  const classOptions = refs.classes.map((c) => ({ value: c.id, label: c.class_name }));

  /* ============================ 编辑器 ============================ */
  function openEditor(row) {
    const isEdit = row !== null;
    openFormModal({
      title: isEdit ? `编辑考生 · ${row.stu_name}` : '新增考生',
      fields: [
        { name: 'id', label: '准考证号', required: !isEdit, disabled: isEdit, maxlength: 20,
          placeholder: '仅数字，如 20260001', hint: isEdit ? '准考证号不可修改' : '作为登录账号，必须唯一' },
        { name: 'stu_name', label: '姓名', required: true, maxlength: 50, placeholder: '考生姓名' },
        { name: 'stu_sex', label: '性别', type: 'select', placeholder: '请选择',
          options: [{ value: '男', label: '男' }, { value: '女', label: '女' }] },
        { name: 'grade_id', label: '单位', type: 'select', placeholder: '请选择单位', options: gradeOptions },
        { name: 'class_id', label: '班级', type: 'select', placeholder: '请选择班级', options: classOptions },
          // 字段名必须是 password：后端 Admin\StudentController 校验/读取的是 'password'。
          // 此前发 stu_pwd，后端读不到 → 管理员设置的密码被静默丢弃、回退成「准考证号」。
          { name: 'password', label: '登录密码', type: 'password', required: !isEdit,
            placeholder: isEdit ? '留空表示不修改' : '留空则默认为准考证号',
            hint: isEdit ? '不修改请留空' : '建议使用强密码' },
        ],
        values: isEdit ? {
          id: row.id, stu_name: row.stu_name, stu_sex: row.stu_sex,
          grade_id: row.grade_id, class_id: row.class_id,
        } : {},
        transform: (v) => {
          const out = { ...v };
          if (!out.password) delete out.password;
          if (isEdit) out.id = row.id;
          return out;
        },
      onSubmit: (payload) => (isEdit ? adminApi.updateStudent(row.id, payload) : adminApi.createStudent(payload)),
      onSaved: () => list.load(),
      size: 'lg',
    });
  }

  /* ============================ 详情 ============================ */
  async function openDetail(row) {
    const detail = await adminApi.student(row.id).catch(() => null);
    const scores = detail?.scores || detail?.list || [];
    const body = el('div.stack');

    body.append(descList([
      ['准考证号', el('span.mono.fw-500', { text: String(row.id) })],
      ['姓名', row.stu_name],
      ['性别', row.stu_sex || '—'],
      ['单位', row.grade_name || row.grade_id || '—'],
      ['班级', row.class_name || row.class_id || '—'],
    ]));

    body.append(el('hr.divider'));
    body.append(el('div.fs-sm.c-secondary.mb-2', { text: `考试成绩（${scores.length} 条）` }));

    if (scores.length) {
      const scored = scores.map((s) => Number(s.stu_score ?? s.score ?? 0)).filter((n) => !Number.isNaN(n));
      const avg = scored.length ? scored.reduce((a, b) => a + b, 0) / scored.length : 0;
      const max = scored.length ? Math.max(...scored) : 0;

      body.append(el('div.grid-3.mb-4', {}, [
        el('div.card.stat-card', {}, [
          el('div.stat-icon', {}, [icon('clipboard', { size: 20 })]),
          el('div', {}, [el('div.stat-value', { text: String(scores.length) }), el('div.stat-label', { text: '考试次数' })]),
        ]),
        el('div.card.stat-card', {}, [
          el('div.stat-icon.tone-info', {}, [icon('trending', { size: 20 })]),
          el('div', {}, [el('div.stat-value', { text: fmtScore(avg) }), el('div.stat-label', { text: '平均分' })]),
        ]),
        el('div.card.stat-card', {}, [
          el('div.stat-icon.tone-success', {}, [icon('award', { size: 20 })]),
          el('div', {}, [el('div.stat-value', { text: fmtScore(max) }), el('div.stat-label', { text: '最高分' })]),
        ]),
      ]));

      body.append(table({
        size: 'sm',
        columns: [
          { key: 'exam_name', title: '考试名称', render: (r) => r.exam_name || '—' },
          { key: 'exam_start', title: '考试时间', width: '160px', render: (r) => fmtDateTime(r.exam_start || r.exam_date) },
          { key: 'stu_score', title: '得分', align: 'right', width: '90px',
            render: (r) => el('span.mono.fw-600', { text: fmtScore(r.stu_score ?? r.score) }) },
        ],
        rows: scores,
      }));
    } else {
      body.append(emptyStated('该考生暂无考试成绩', { iconName: 'clipboard' }));
    }

    openModal({ title: `考生详情 · ${row.stu_name}`, body, size: 'lg' });
  }

  /* ============================ 批量导入 ============================ */
  function openImport() {
    const body = el('div.stack');
    const resultSlot = el('div');

    body.append(alertBox(
      '支持 CSV / TXT 文本。每行一条记录，字段顺序：准考证号,姓名,密码,性别,单位,班级（后四项可留空，密码留空则默认为准考证号）。首行若为表头会自动跳过。',
      { type: 'info', title: '导入格式说明' }
    ));

    body.append(codeBlock(
      '准考证号,姓名,密码,性别,单位,班级\n' +
      '20260001,张三,Abc@1234,男,XX海事局,2026级一班\n' +
      '20260002,李四,,女,XX海事局,2026级一班'
    ));

    const fileInput = el('input', { type: 'file', accept: '.csv,.txt', class: 'input' });
    const textArea = textarea({ rows: 10, placeholder: '也可以直接粘贴文本内容…' });

    const modeTabs = tabs([
      { key: 'file', label: '上传文件' },
      { key: 'text', label: '粘贴文本' },
    ], 'file', (k) => {
      mode = k;
      fileWrap.style.display = k === 'file' ? '' : 'none';
      textWrap.style.display = k === 'text' ? '' : 'none';
    });
    let mode = 'file';

    const fileWrap = el('div', {}, [field('选择文件', fileInput, { hint: '支持 .csv / .txt，UTF-8 编码' })]);
    const textWrap = el('div', { style: { display: 'none' } }, [field('粘贴内容', textArea)]);

    // 读取文件到 textarea 便于预览
    fileInput.addEventListener('change', async () => {
      const f = fileInput.files?.[0];
      if (!f) return;
      if (f.size > 2 * 1024 * 1024) { notify.error('文件过大（上限 2MB）'); fileInput.value = ''; return; }
      textArea.value = await f.text();
    });

    body.append(modeTabs, fileWrap, textWrap, resultSlot);

    const importBtn = button('开始导入', { variant: 'primary', iconName: 'upload' });
    const dlg = openModal({
      title: '批量导入考生',
      body, size: 'lg',
      footer: [button('关闭', { variant: 'secondary', onClick: () => dlg.close() }), importBtn],
    });

    importBtn.addEventListener('click', async () => {
      const content = textArea.value.trim();
      if (!content) { notify.warning('请先选择文件或粘贴内容'); return; }
      if (mode === 'file' && !fileInput.files?.[0] && !content) { notify.warning('请选择文件'); return; }

      const { ok, error, result } = await withLoading(importBtn, () => adminApi.importStudents({ content }), { silent: true });
      clear(resultSlot);
      if (ok) {
        const r = result || {};
        const errs = r.errors || [];
        resultSlot.append(alertBox(
          `导入完成：成功 ${r.imported ?? 0} 条${r.failed ? `，失败 ${r.failed} 条` : ''}`,
          { type: r.failed ? 'warning' : 'success', title: '导入结果' }
        ));
        if (errs.length) {
          resultSlot.append(el('div', {
            style: {
              maxHeight: '220px', overflowY: 'auto', padding: 'var(--sp-3)',
              background: 'var(--warning-50)', border: '1px solid var(--warning-500)',
              borderRadius: 'var(--radius-sm)', fontSize: 'var(--fs-sm)',
            },
          }, errs.map((msg) => el('div', { text: String(msg) }))));
        }
        list.load();
      } else if (error) {
        resultSlot.append(alertBox(error.message || '导入失败', { type: 'danger' }));
        const errs = error.data?.errors || [];
        if (errs.length) {
          resultSlot.append(el('div', {
            style: {
              maxHeight: '220px', overflowY: 'auto', padding: 'var(--sp-3)',
              background: 'var(--danger-50)', border: '1px solid var(--danger-500)',
              borderRadius: 'var(--radius-sm)', fontSize: 'var(--fs-sm)',
            },
          }, errs.map((msg) => el('div', { text: String(msg) }))));
        }
      }
    });
  }

  return list.root;
}
