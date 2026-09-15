/**
 * 管理后台 —— 题库管理
 * 含：列表筛选、增删改、批量删除、重复清理、一键规范化
 */

import { el, mount, clear } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import {
  button, badge, card, notify, openModal, confirmDialog, alertBox,
  tabs, segmented, descList, codeBlock, emptyStated, field, input, select,
} from '../../ui/components.js';
import { createListView, openFormModal, confirmDelete } from '../../ui/crud.js';
import { adminApi } from '../../api/index.js';
import { withLoading } from '../../core/bootstrap.js';
import { fmtDate, indexToLetter } from '../../core/format.js';

/** quiz_class 枚举 → 语义（注意：radio1 实为判断题，radio2 为单选题） */
export const QUIZ_TYPES = [
  { value: 'radio1', label: '判断题' },
  { value: 'radio2', label: '单选题' },
  { value: 'checkbox', label: '多选题' },
  { value: 'text', label: '填空题' },
  { value: 'longtext', label: '问答题' },
];
export const QUIZ_TYPE_LABELS = Object.fromEntries(QUIZ_TYPES.map((t) => [t.value, t.label]));

export const QUIZ_DIFFS = [
  { value: 'Y', label: '易' },
  { value: 'Z', label: '中' },
  { value: 'N', label: '难' },
];
export const DIFF_LABELS = Object.fromEntries(QUIZ_DIFFS.map((d) => [d.value, d.label]));

const OPTION_TYPES = ['radio1', 'radio2', 'checkbox'];

/** 供其它视图复用的题型徽标 */
export function typeBadge(t) {
  const map = { radio1: 'info', radio2: 'brand', checkbox: 'warning', text: 'success', longtext: '' };
  return badge(QUIZ_TYPE_LABELS[t] || t, { tone: map[t] ?? '' });
}

export async function QuizView({ router, can }) {
  /** 缓存科目/类别列表，避免重复请求 */
  const refCache = { subjects: [] };
  const loadSubjects = async () => {
    if (refCache.subjects.length) return refCache.subjects;
    const data = await adminApi.subjects({ per_page: 200 });
    refCache.subjects = data?.list || [];
    return refCache.subjects;
  };

  const list = createListView({
    title: '题库管理',
    desc: '维护所有考试题目；支持批量删除与重复项清理',
    defaultPerPage: 20,
    selectable: true,

    filters: [
      { type: 'search', name: 'keyword', placeholder: '搜索题干关键字…' },
      { type: 'select', name: 'subj_id', placeholder: '全部科目', width: '170px',
        options: async () => (await loadSubjects()).map((s) => ({ value: s.id, label: s.subj_name })) },
      { type: 'select', name: 'quiz_class', placeholder: '全部题型', width: '120px',
        options: QUIZ_TYPES.map((t) => ({ value: t.value, label: t.label })) },
      { type: 'select', name: 'quiz_diff', placeholder: '全部难度', width: '110px',
        options: QUIZ_DIFFS.map((d) => ({ value: d.value, label: d.label })) },
    ],

    actions: () => [
      button('新增题目', { variant: 'primary', iconName: 'plus', onClick: () => openEditor(null) }),
      button('清理工具', { variant: 'secondary', iconName: 'sparkles', onClick: () => openCleanTools() }),
    ],

    bulkActions: (selected) => [
      button(`批量删除`, {
        variant: 'danger', size: 'sm', iconName: 'trash',
        onClick: async () => {
          const ids = selected.map((r) => r.id);
          const ok = await confirmDialog(`确定删除选中的 ${ids.length} 道题目吗？`, {
            title: '批量删除', confirmText: '删除', tone: 'danger',
            detail: '此操作不可撤销。若题目已被用于考试试卷，请谨慎操作。',
          });
          if (!ok) return;
          const { ok: done, error } = await withLoading(null, () => adminApi.batchDeleteQuizzes(ids), { silent: true });
          if (done) { notify.success(`已删除 ${ids.length} 道题目`); list.load(); }
          else notify.error(error?.message || '批量删除失败');
        },
      }),
    ],

    columns: [
      { key: 'id', title: 'ID', width: '72px', sortable: true,
        render: (r) => el('span.mono.fs-sm.c-tertiary', { text: String(r.id) }) },
      { key: 'quiz_title', title: '题干', render: (r) => el('div', {}, [
          el('div', { style: { whiteSpace: 'normal', wordBreak: 'break-word' }, text: r.quiz_title }),
          r.quiz_pic_name ? el('div.fs-xs.c-brand.flex.items-center.gap-1.mt-1', {}, [
            icon('file', { size: 11 }), el('span', { text: '含配图' }),
          ]) : null,
        ].filter(Boolean)) },
      { key: 'subj_name', title: '科目', width: '140px', render: (r) => el('span.fs-sm', { text: r.subj_name || '—' }) },
      { key: 'quiz_class', title: '题型', width: '90px', align: 'center', render: (r) => typeBadge(r.quiz_class) },
      { key: 'quiz_diff', title: '难度', width: '70px', align: 'center',
        render: (r) => badge(DIFF_LABELS[r.quiz_diff] || r.quiz_diff || '—', { tone: r.quiz_diff === 'N' ? 'danger' : r.quiz_diff === 'Y' ? 'success' : '' }) },
      { key: 'quiz_key', title: '答案', width: '88px', align: 'center',
        render: (r) => el('span.mono.fs-sm.fw-600', { text: r.quiz_key || '—' }) },
    ],

    fetch: (params) => adminApi.quizzes(params),

    rowActions: (row) => [
      button('', { variant: 'ghost', size: 'sm', iconName: 'eye', title: '查看', onClick: () => openDetail(row) }),
      button('', { variant: 'ghost', size: 'sm', iconName: 'edit', title: '编辑', onClick: () => openEditor(row.id) }),
      button('', { variant: 'ghost', size: 'sm', iconName: 'trash', title: '删除',
        onClick: () => confirmDelete(`确定删除题目 #${row.id} 吗？`, {
          detail: row.quiz_title, onConfirm: () => adminApi.deleteQuiz(row.id),
        }).then((done) => { if (done) list.load(); }) }),
    ],
  });

  /* ============================ 编辑器 ============================ */
  function openEditor(id) {
    const isEdit = id !== null && id !== undefined;
    const loader = isEdit ? adminApi.quiz(id) : Promise.resolve(null);

    loader.then((row) => {
      const values = row || {};
      const type0 = values.quiz_class || 'radio2';

      /** 选项编辑区（随题型切换显隐） */
      const optionWrap = el('div.span-2.stack-sm');
      let optionInputs = [];

      function renderOptions(type, options, key) {
        clear(optionWrap);
        optionInputs = [];
        if (!OPTION_TYPES.includes(type)) {
          if (type === 'text') {
            optionWrap.append(alertBox('填空题无需选项；题干中用下划线表示填空位置，答案填在「答案」字段。', { type: 'info' }));
          }
          return;
        }
        const label = el('div.field-label', {}, [el('span', { text: '选项与答案' })]);
        const grid = el('div.stack-sm');

        const count = type === 'radio1' ? 2 : Math.max(2, options.length || 4);
        const opts = type === 'radio1'
          ? ['对', '错']
          : Array.from({ length: count }, (_, i) => options[i] ?? '');

        opts.forEach((text, i) => {
          const letter = indexToLetter(i);
          const cb = el('input', {
            type: type === 'checkbox' ? 'checkbox' : 'radio',
            name: 'opt_key', value: letter,
            checked: type === 'checkbox' ? String(key || '').includes(letter) : String(key || '') === letter,
            style: { marginTop: '10px', accentColor: 'var(--brand-600)', cursor: 'pointer' },
          });
          const txt = input({ value: text, placeholder: `选项 ${letter} 内容`, class: 'input-sm' });
          txt.readOnly = type === 'radio1';
          optionInputs.push({ letter, cb, txt });
          grid.append(el('div.flex.items-center.gap-3', {}, [
            el('label.flex.items-center.gap-1.shrink-0', {
              style: { width: '56px', cursor: 'pointer' },
              title: '勾选作为正确答案',
            }, [cb, el('span.mono.fw-600.fs-sm', { text: letter })]),
            txt,
          ]));
        });

        optionWrap.append(label, grid, el('div.field-hint', {
          text: type === 'checkbox'
            ? '可勾选多个正确答案；多选题的选项至少 3 个。'
            : '单选/判断题只能勾选一个正确答案。',
        }));
      }

      const typeSelect = select(QUIZ_TYPES, { name: 'quiz_class', value: type0 });
      const subjSelect = select([], { name: 'subj_id', value: String(values.subj_id ?? ''), placeholder: '请选择科目' });

      loadSubjects().then((subs) => {
        clear(subjSelect);
        subjSelect.append(el('option', { value: '', text: '请选择科目' }));
        for (const s of subs) {
          subjSelect.append(el('option', {
            value: String(s.id), text: s.subj_name,
            selected: String(s.id) === String(values.subj_id),
          }));
        }
        subjSelect.value = String(values.subj_id ?? '');
      });

      const parsedOptions = parseOptions(values.quiz_option, values.quiz_class);
      renderOptions(type0, parsedOptions, values.quiz_key);

      // 题型切换时重建选项区
      const origType = type0;
      typeSelect.addEventListener('change', () => {
        const t = typeSelect.value;
        const prev = collectOptions();
        const base = t === 'radio1' ? ['对', '错'] : prev;
        renderOptions(t, base, '');
      });

      function collectOptions() {
        return optionInputs.map((o) => o.txt.value);
      }
      function collectKey() {
        const type = typeSelect.value;
        if (!OPTION_TYPES.includes(type)) return '';
        const checked = optionInputs.filter((o) => o.cb.checked);
        const raw = checked.map((o) => o.letter).sort();
        return type === 'checkbox' ? raw.join('') : (raw[0] || '');
      }

      const titleInput = el('textarea', {
        class: 'textarea', name: 'quiz_title', rows: 3,
        placeholder: '请输入题干内容',
      });
      titleInput.value = values.quiz_title || '';

      /* -------------------- 配图上传 -------------------- */
      let picName = values.quiz_pic_name || '';
      const picPreview = el('div.pic-upload-preview');
      const picNameText = el('span.fs-sm.c-secondary', { text: picName || '未选择图片' });
      const fileInput = el('input', {
        type: 'file', accept: 'image/*',
        style: { display: 'none' },
      });

      function renderPic(url) {
        clear(picPreview);
        if (!url) return;
        picPreview.append(el('img', {
          src: url, alt: '配图预览',
          onerror: function () { clear(picPreview); picPreview.append(el('span.fs-sm.c-danger', { text: '图片加载失败' })); },
        }));
      }
      renderPic(picName ? `/uploads/${picName}` : '');

      fileInput.addEventListener('change', async () => {
        const file = fileInput.files?.[0];
        if (!file) return;
        const fd = new FormData();
        fd.append('file', file);
        const { ok, result, error } = await withLoading(null, () => adminApi.uploadPic(fd), { silent: true });
        if (!ok) { notify.error(error?.message || '图片上传失败'); return; }
        picName = result?.filename || '';
        picNameText.textContent = picName || '未选择图片';
        renderPic(result?.url || `/uploads/${picName}`);
        notify.success('配图已上传，保存后生效');
      });

      const picBox = el('div.stack-sm', {}, [
        el('div.flex.items-center.gap-2.flex-wrap', {}, [
          button('选择图片', { variant: 'secondary', size: 'sm', iconName: 'upload', onClick: () => fileInput.click() }),
          button('移除配图', { variant: 'ghost', size: 'sm', iconName: 'trash', onClick: () => {
            picName = '';
            picNameText.textContent = '未选择图片';
            clear(picPreview);
          } }),
          picNameText,
          fileInput,
        ]),
        picPreview,
        el('div.field-hint', { text: '支持 JPG / PNG / GIF / WEBP，大小不超过 2MB；上传后需点击保存才会写入题目。' }),
      ]);

      const keyInput = input({
        name: 'quiz_key', value: values.quiz_key || '',
        placeholder: '填空题答案（多个空用 | 分隔）',
      });
      const keyField = field('答案', keyInput, {
        required: true,
        hint: '选择题会自动根据勾选生成，无需手填',
      });

      const diffSelect = select(QUIZ_DIFFS, { name: 'quiz_diff', value: values.quiz_diff || 'Z' });
      const writerInput = input({ name: 'quiz_writer', value: values.quiz_writer || '', placeholder: '录题人' });
      const timeInput = input({ name: 'quiz_time', type: 'date', value: values.quiz_time || new Date().toISOString().slice(0, 10) });

      const form = el('div.form-grid', {}, [
        field('科目', subjSelect, { required: true }),
        field('题型', typeSelect, { required: true }),
        el('div.span-2', {}, [field('题干', titleInput, { required: true })]),
        el('div.span-2', {}, [field('配图（可选）', picBox)]),
        optionWrap,
        keyField,
        field('难度', diffSelect),
        field('录题人', writerInput),
        field('日期', timeInput),
      ]);

      // 选择题时答案字段只读展示
      function syncKeyField() {
        const t = typeSelect.value;
        if (OPTION_TYPES.includes(t)) {
          keyInput.readOnly = true;
          keyInput.placeholder = '由选项勾选自动生成';
          keyInput.value = collectKey();
        } else {
          keyInput.readOnly = false;
          keyInput.placeholder = '填空题答案（多个空用 | 分隔）';
        }
      }
      typeSelect.addEventListener('change', syncKeyField);
      optionWrap.addEventListener('change', syncKeyField);
      syncKeyField();

      const errSlot = el('div.span-2');
      form.append(errSlot);

      const submitBtn = button(isEdit ? '保存修改' : '创建题目', { variant: 'primary', type: 'submit' });
      const dlg = openModal({
        title: isEdit ? `编辑题目 #${id}` : '新增题目',
        body: form, size: 'lg',
        footer: [button('取消', { variant: 'secondary', onClick: () => dlg.close() }), submitBtn],
      });

      form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clear(errSlot);

        const type = typeSelect.value;
        const payload = {
          subj_id: Number(subjSelect.value) || 0,
          quiz_class: type,
          quiz_title: titleInput.value.trim(),
          quiz_diff: diffSelect.value,
          quiz_writer: writerInput.value.trim(),
          quiz_time: timeInput.value,
          quiz_pic_name: picName,
          quiz_key: OPTION_TYPES.includes(type) ? collectKey() : keyInput.value.trim(),
        };

        if (!payload.subj_id) { errSlot.append(alertBox('请选择科目', { type: 'warning' })); return; }
        if (!payload.quiz_title) { errSlot.append(alertBox('请填写题干', { type: 'warning' })); return; }
        if (!payload.quiz_key) { errSlot.append(alertBox('请设置正确答案', { type: 'warning' })); return; }

        if (OPTION_TYPES.includes(type)) {
          const opts = collectOptions();
          const filled = opts.filter((x) => x.trim() !== '');
          if (type === 'checkbox' && filled.length < 3) {
            errSlot.append(alertBox('多选题至少需要 3 个有效选项', { type: 'warning' }));
            return;
          }
          if (type !== 'radio1' && filled.length < 2) {
            errSlot.append(alertBox('至少需要 2 个有效选项', { type: 'warning' }));
            return;
          }
          payload.quiz_option = opts.join('|');
        } else {
          payload.quiz_option = '';
        }

        const { ok, error } = await withLoading(submitBtn, () => (
          isEdit ? adminApi.updateQuiz(id, payload) : adminApi.createQuiz(payload)
        ), { silent: true });

        if (ok) {
          notify.success(isEdit ? '题目已更新' : '题目已创建');
          dlg.close();
          list.load();
        } else if (error) {
          errSlot.append(alertBox(error.message || '保存失败', { type: 'danger' }));
        }
      });
    }).catch((e) => notify.error(e?.message || '加载题目失败'));
  }

  /* ============================ 详情 ============================ */
  function openDetail(row) {
    const opts = parseOptions(row.quiz_option, row.quiz_class);
    const key = String(row.quiz_key || '');
    const body = el('div.stack');

    body.append(el('div', {}, [
      el('div.fs-sm.c-secondary.mb-1', { text: '题干' }),
      el('div.pre-wrap.fw-500', { text: row.quiz_title }),
    ]));

    if (row.quiz_pic_name) {
      body.append(el('div', {}, [
        el('div.fs-sm.c-secondary.mb-2', { text: '配图' }),
        el('img', {
          src: `/uploads/${row.quiz_pic_name}`,
          style: { maxHeight: '220px', borderRadius: 'var(--radius-sm)', border: '1px solid var(--border-subtle)' },
          alt: '题目配图',
          onerror: function () {
            this.replaceWith(el('span.fs-sm.c-danger', { text: '图片缺失（文件未找到：/uploads/' + row.quiz_pic_name + '）' }));
          },
        }),
      ]));
    }

    if (opts.length) {
      const listEl = el('div.stack-sm');
      opts.forEach((text, i) => {
        const letter = indexToLetter(i);
        const isKey = key.includes(letter);
        listEl.append(el('div.flex.items-start.gap-3', {}, [
          el('span.mono.fw-700', { style: { width: '18px', color: isKey ? 'var(--success-600)' : 'var(--text-tertiary)' }, text: letter }),
          el('span.flex-1', { text: text }),
          isKey ? badge('正确答案', { tone: 'success', dot: true }) : null,
        ].filter(Boolean)));
      });
      body.append(el('div', {}, [el('div.fs-sm.c-secondary.mb-2', { text: '选项' }), listEl]));
    } else {
      body.append(descList([['答案', key || '—']]));
    }

    body.append(el('hr.divider'));
    body.append(descList([
      ['ID', String(row.id)],
      ['科目', row.subj_name || '—'],
      ['题型', `${QUIZ_TYPE_LABELS[row.quiz_class] || row.quiz_class}（${row.quiz_class}）`],
      ['难度', DIFF_LABELS[row.quiz_diff] || row.quiz_diff || '—'],
      ['录题人', row.quiz_writer || '—'],
      ['录入日期', fmtDate(row.quiz_time)],
      ['被作答次数', String(row.quiz_hits ?? 0)],
    ]));

    openModal({ title: '题目详情', body, size: 'lg' });
  }

  /* ============================ 清理工具 ============================ */
  function openCleanTools() {
    const body = el('div.stack');
    const previewSlot = el('div');
    const resultSlot = el('div');

    body.append(alertBox('清理工具会检测重复题目并规范答案格式。执行前请确认没有正在进行的考试。', { type: 'warning' }));

    body.append(card({
      title: '重复题目检测',
      iconName: 'copy',
      body: el('div.stack-sm', {}, [
        el('p.fs-sm.c-secondary', { text: '同一科目下题干与选项完全相同的题目视为重复，保留最早录入的一条。' }),
        el('div.flex.gap-2', {}, [
          button('检测重复项', { variant: 'secondary', iconName: 'search',
            onClick: (e) => withLoading(e.currentTarget, async () => {
              const data = await adminApi.cleanPreview();
              clear(previewSlot);
              const groups = data?.groups || [];
              if (!groups.length) {
                previewSlot.append(alertBox('未发现重复题目，题库很干净', { type: 'success' }));
                return;
              }
              previewSlot.append(el('div', {}, [
                el('div.fw-600.mb-2', {
                  text: `发现 ${data.group_count ?? groups.length} 组重复，可清理 ${data.remove_count ?? 0} 条`,
                }),
                tableSimple(groups),
                el('div.mt-3', {}, [
                  button('立即清理重复项', {
                    variant: 'danger', size: 'sm', iconName: 'trash',
                    onClick: async () => {
                      const ok = await confirmDialog(`确定删除 ${data.remove_count ?? 0} 条重复题目吗？`, {
                        title: '清理重复题', confirmText: '清理', tone: 'danger',
                        detail: '每组重复仅保留最早录入的一条，操作不可撤销。',
                      });
                      if (!ok) return;
                      const { ok: done, error, result } = await withLoading(null, () => adminApi.cleanQuizzes(), { silent: true });
                      clear(previewSlot);
                      if (done) {
                        notify.success(`已清理 ${result?.deleted ?? 0} 条重复题目`);
                        previewSlot.append(alertBox('重复题已清理完毕', { type: 'success' }));
                        list.load();
                      } else if (error) {
                        previewSlot.append(alertBox(error.message || '清理失败', { type: 'danger' }));
                      }
                    },
                  }),
                ]),
              ]));
            }, { silent: true }) }),
        ]),
        previewSlot,
      ]),
    }));

    body.append(card({
      title: '一键规范化',
      iconName: 'sparkles',
      body: el('div.stack-sm', {}, [
        el('div.fs-sm.c-secondary', { text: '将依次执行：① 删除重复题 ② 答案字母大写化 ③ 多选答案字母排序 ④ 多选题题型纠正（单选答案却含多个字母的自动改为多选）' }),
        el('div.flex.gap-2.flex-wrap', {}, [
          button('执行规范化', { variant: 'primary', iconName: 'sparkles',
            onClick: async (e) => {
              const ok = await confirmDialog('确定执行一键规范化吗？', {
                title: '执行规范化', confirmText: '执行', tone: 'warning',
                detail: '将修改题库中的答案格式、部分题型并删除重复题，操作不可撤销。',
              });
              if (!ok) return;
              const btn = e.currentTarget;
              const { ok: done, error, result } = await withLoading(btn, () => adminApi.advancedClean(), { silent: true });
              clear(resultSlot);
              if (done) {
                const r = result || {};
                resultSlot.append(alertBox(
                  `规范化完成 —— 删除重复 ${r.duplicate_removed ?? 0} 条（${r.duplicate_groups ?? 0} 组）、答案大写化 ${r.upper_cased ?? 0} 条、多选答案排序 ${r.multi_sorted ?? 0} 条、题型纠正 ${r.single_to_multi ?? 0} 条`,
                  { type: 'success', title: '执行成功' }
                ));
                list.load();
              } else if (error) {
                resultSlot.append(alertBox(error.message || '执行失败', { type: 'danger' }));
              }
            } }),
        ]),
        resultSlot,
      ]),
    }));

    openModal({ title: '题库清理工具', body, size: 'lg' });
  }

  return list.root;
}

/* ============================ 工具函数 ============================ */

/** 解析 `|` 分隔的选项，仅选择题有值 */
export function parseOptions(optionStr, quizClass) {
  if (!OPTION_TYPES.includes(quizClass)) return [];
  const s = String(optionStr ?? '').trim();
  if (!s) return [];
  return s.split('|').map((x) => x.trim());
}

/** 轻量表格（清理预览用） */
function tableSimple(groups) {
  const wrap = el('div.table-wrap', { style: { maxHeight: '260px', overflowY: 'auto' } });
  const t = el('table.table.table-sm');
  t.append(el('thead', {}, [el('tr', {}, [
    el('th', { text: '题干' }),
    el('th', { text: '重复数', style: { width: '80px' } }),
    el('th', { text: '保留 ID', style: { width: '110px' } }),
  ])]));
  const tb = el('tbody');
  for (const g of groups) {
    tb.append(el('tr', {}, [
      el('td', {}, [
        el('div.truncate', { style: { maxWidth: '380px' }, text: g.quiz_title || '—' }),
        el('div.fs-xs.c-tertiary', { text: `科目 ${g.subj_id ?? '—'} · 题型 ${QUIZ_TYPE_LABELS[g.quiz_class] || g.quiz_class || '—'}` }),
      ]),
      el('td', { text: String(g.cnt ?? '—') }),
      el('td', {}, [el('span.mono.fs-xs', { text: String(g.keep_id ?? '—') })]),
    ]));
  }
  t.append(tb);
  wrap.append(t);
  return wrap;
}
