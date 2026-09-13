/**
 * 列表视图通用骨架 —— 统一"工具条 + 表格 + 分页 + 加载态"的编排。
 * 各模块只需提供列定义、取数函数与操作按钮。
 */

import { el, mount, clear, debounce } from '../core/dom.js';
import { icon } from '../core/icons.js';
import {
  table, pagination, button, toolbar, searchBox, select, notify,
  emptyStated, skeletonRows, openModal, confirmDialog, field, input, textarea,
} from './components.js';
import { withLoading } from '../core/bootstrap.js';

/**
 * 创建列表视图控制器
 * @param {object} cfg
 * @param {string} cfg.title
 * @param {string} [cfg.desc]
 * @param {Array} cfg.columns            列定义（见 table）
 * @param {(params:object)=>Promise<{list:any[],total:number}>} cfg.fetch
 * @param {{type:'search'|'select',name:string,label:string,placeholder?:string,options?:()=>Promise<Array>|Array,width?:string}[]} [cfg.filters]
 * @param {Node[]|()=>Node[]} [cfg.actions]         右上角按钮
 * @param {()=>Node[]} [cfg.rowActions]             行操作渲染（返回按钮数组）
 * @param {()=>void} [cfg.onRefreshNeeded]
 * @param {string} [cfg.emptyText]
 * @param {boolean} [cfg.selectable]                是否启用多选
 * @param {(selected:any[])=>Node[]} [cfg.bulkActions]
 * @param {number} [cfg.defaultPerPage]
 */
export function createListView(cfg) {
  const {
    title, desc, columns, fetch, filters = [], actions = [], rowActions,
    emptyText = '暂无数据', selectable = false, bulkActions, defaultPerPage = 20,
  } = cfg;

  const state = {
    page: 1,
    per_page: defaultPerPage,
    keyword: '',
    sort: '',
    order: 'asc',
    filterValues: {},
    total: 0,
    list: [],
    loading: false,
    selected: new Set(),
  };

  const toolSlot = el('div.flex.items-center.gap-3.flex-wrap');
  const bulkSlot = el('div');
  const tableSlot = el('div', { style: { position: 'relative', minHeight: '160px' } });
  const pagerSlot = el('div');
  const countSlot = el('span.fs-sm.c-secondary');

  /* ---------- 取数 ---------- */
  async function load({ silent = false } = {}) {
    state.loading = true;
    if (!silent) mount(tableSlot, skeletonRows(6, Math.min(columns.length, 6)));

    try {
      const params = {
        page: state.page,
        per_page: state.per_page,
        keyword: state.keyword,
        sort: state.sort || undefined,
        order: state.sort ? state.order : undefined,
        ...state.filterValues,
      };
      const data = await fetch(params);
      state.list = data?.list ?? [];
      state.total = data?.total ?? state.list.length;
      state.selected.clear();
      render();
    } catch (e) {
      mount(tableSlot, el('div', { style: { padding: 'var(--sp-4)' } }, [
        el('div.alert.alert-danger', { text: e?.message || '加载失败' }),
      ]));
      pagerSlot.replaceChildren();
      countSlot.textContent = '';
    } finally {
      state.loading = false;
      renderBulk();
    }
  }

  /* ---------- 渲染 ---------- */
  function render() {
    const allColumns = selectable ? [checkboxColumn(), ...columns] : columns;

    if (rowActions) {
      allColumns.push({
        key: '__ops', title: '操作', align: 'right', width: '1%',
        render: (row) => {
          const btns = rowActions(row);
          return el('div.flex.items-center.gap-1.justify-end', {}, [].concat(btns).filter(Boolean));
        },
      });
    }

    mount(tableSlot, table({
      columns: allColumns,
      rows: state.list,
      emptyText,
      sort: state.sort,
      order: state.order,
      onSort: (key) => {
        if (state.sort === key) {
          state.order = state.order === 'asc' ? 'desc' : 'asc';
        } else {
          state.sort = key;
          state.order = 'asc';
        }
        load();
      },
      isSelected: selectable ? (row) => state.selected.has(row.id) : undefined,
    }));

    countSlot.textContent = state.total ? `共 ${state.total} 条` : '';

    if (state.total > state.per_page) {
      mount(pagerSlot, pagination({
        page: state.page, total: state.total, per_page: state.per_page,
        onPage: (p) => { state.page = p; load(); },
      }));
    } else {
      pagerSlot.replaceChildren();
    }
  }

  function checkboxColumn() {
    return {
      key: '__sel', title: '', width: '40px', align: 'center',
      render: (row) => {
        const cb = el('input', { type: 'checkbox', checked: state.selected.has(row.id) });
        cb.addEventListener('click', (e) => e.stopPropagation());
        cb.addEventListener('change', () => {
          if (cb.checked) state.selected.add(row.id);
          else state.selected.delete(row.id);
          renderBulk();
        });
        return cb;
      },
    };
  }

  function renderBulk() {
    clear(bulkSlot);
    if (!selectable || state.selected.size === 0) return;
    const nodes = [el('span.fs-sm', { text: `已选 ${state.selected.size} 项` })];
    if (bulkActions) nodes.push(...bulkActions(getSelected()));
    nodes.push(button('取消选择', {
      variant: 'ghost', size: 'sm',
      onClick: () => { state.selected.clear(); render(); renderBulk(); },
    }));
    bulkSlot.append(el('div.alert.alert-info', {}, [el('div.flex.items-center.gap-3.flex-wrap', {}, nodes)]));
  }

  function getSelected() {
    return state.list.filter((row) => state.selected.has(row.id));
  }

  /* ---------- 工具条 ---------- */
  function buildToolbar() {
    clear(toolSlot);
    for (const f of filters) {
      if (f.type === 'search') {
        toolSlot.append(searchBox({
          placeholder: f.placeholder || '搜索…',
          value: state.keyword,
          onInput: debounce((v) => { state.keyword = v; state.page = 1; load(); }, 350),
        }));
      } else if (f.type === 'select') {
        const build = (opts) => {
          const s = select(
            [{ value: '', label: f.placeholder || '全部' }, ...opts],
            { value: state.filterValues[f.name] ?? '', class: 'input-sm' }
          );
          s.style.minWidth = f.width || '130px';
          s.addEventListener('change', () => {
            state.filterValues[f.name] = s.value;
            state.page = 1;
            load();
          });
          return s;
        };
        const opts = typeof f.options === 'function' ? [] : (f.options || []);
        const holder = el('span');
        mount(holder, build(opts));
        if (typeof f.options === 'function') {
          Promise.resolve(f.options()).then((resolved) => mount(holder, build(resolved || []))).catch(() => {});
        }
        toolSlot.append(holder);
      }
    }
    toolSlot.append(el('div.toolbar-spacer'));
    const actNodes = typeof actions === 'function' ? actions() : actions;
    for (const n of [].concat(actNodes || [])) if (n) toolSlot.append(n);
  }

  /* ---------- 页面 ---------- */
  const root = el('div.stack');
  const head = el('div.page-head', {}, [
    el('div', {}, [
      el('h2.page-title', { text: title }),
      desc ? el('div.page-desc', { text: desc }) : null,
    ].filter(Boolean)),
    countSlot,
  ]);

  const toolbarWrap = el('div', { style: { display: 'flex', flexDirection: 'column', gap: 'var(--sp-3)' } }, [toolSlot, bulkSlot]);

  root.append(head, toolbarWrap, tableSlot, pagerSlot);

  // 启动：构建工具条（搜索/筛选/操作按钮）并立即加载第一页数据。
  // 缺少这两步时页面只会渲染出页头标题，表格区永远空白。
  buildToolbar();
  load();

  return {
    root,
    state,
    load,
    reload: () => load({ silent: true }),
    refreshToolbar: () => { buildToolbar(); },
    getSelected,
    resetToFirstPage: () => { state.page = 1; },
    setFilter: (name, value) => { state.filterValues[name] = value; buildToolbar(); load(); },
    /** 供弹窗保存后回刷 */
    afterSave: () => load({ silent: true }),
  };
}

/* ==========================================================================
   通用表单弹窗 —— 按字段声明生成增删改查表单
   ========================================================================== */

/**
 * @param {object} cfg
 * @param {string} cfg.title
 * @param {{name:string,label:string,type?:string,required?:boolean,placeholder?:string,
 *          options?:Array|(()=>Promise<Array>),hint?:string,colSpan?:number,
 *          rows?:number,min?:number,max?:number,disabled?:boolean,value?:any}} cfg.fields
 * @param {object} [cfg.values]        编辑时的初始值
 * @param {(payload:object)=>Promise<any>} cfg.onSubmit
 * @param {string} [cfg.submitText]
 * @param {string} [cfg.size]
 * @param {string} [cfg.successMessage]
 * @param {(values:object)=>object} [cfg.transform]
 */
export function openFormModal(cfg) {
  const { title, fields, values = {}, onSubmit, submitText = '保存', size = '', successMessage = '保存成功', transform } = cfg;

  const form = el('form', { class: 'form-grid' });
  const controls = {};
  let saved = false;

  for (const f of fields) {
    const current = f.value !== undefined ? f.value : (values[f.name] ?? '');
    let ctl;

    switch (f.type) {
      case 'select': {
        ctl = select([], { name: f.name, value: String(current ?? ''), placeholder: f.placeholder || '请选择', disabled: f.disabled });
        const fill = (opts) => {
          clear(ctl);
          if (f.placeholder !== null) ctl.append(el('option', { value: '', text: f.placeholder || '请选择' }));
          for (const o of opts || []) ctl.append(el('option', {
            value: String(o.value), text: o.label,
            selected: String(o.value) === String(current ?? ''),
          }));
        };
        if (typeof f.options === 'function') fill(f.options() || []);
        else fill(f.options || []);
        if (typeof f.options === 'function') {
          Promise.resolve(f.options()).then((r) => fill(r)).catch(() => {});
        }
        ctl.value = String(current ?? '');
        break;
      }
      case 'textarea': {
        ctl = textarea({ name: f.name, value: current, placeholder: f.placeholder || '', rows: f.rows || 4, disabled: f.disabled });
        break;
      }
      case 'number': {
        ctl = input({ name: f.name, type: 'number', value: current, placeholder: f.placeholder || '', disabled: f.disabled });
        if (f.min !== undefined) ctl.min = String(f.min);
        if (f.max !== undefined) ctl.max = String(f.max);
        break;
      }
      case 'checkbox': {
        const id = `f_${f.name}`;
        const cb = el('input', { type: 'checkbox', id, name: f.name, checked: !!current, value: '1', disabled: f.disabled });
        ctl = el('label.checkbox', { for: id }, [cb, el('span', { text: f.checkboxLabel || f.label })]);
        ctl._input = cb;
        break;
      }
      default: {
        ctl = input({
          name: f.name, type: f.type || 'text', value: current,
          placeholder: f.placeholder || '', disabled: f.disabled,
          maxlength: f.maxlength || '',
        });
      }
    }

    controls[f.name] = ctl?._input || ctl;

    const label = f.type === 'checkbox' ? '' : f.label;
    const wrap = field(label, ctl, { required: f.required, hint: f.hint || '' });
    if (f.colSpan === 2) wrap.classList.add('span-2');
    form.append(wrap);
  }

  const errSlot = el('div.span-2');
  form.append(errSlot);

  const submitBtn = button(submitText, { variant: 'primary', type: 'submit' });
  const cancelBtn = button('取消', { variant: 'secondary', onClick: () => dlg.close() });

  const dlg = openModal({
    title, body: form, size, footer: [cancelBtn, submitBtn],
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clear(errSlot);

    const payload = {};
    for (const f of fields) {
      const ctl = controls[f.name];
      let v;
      if (f.type === 'checkbox') v = ctl.checked;
      else v = typeof ctl.value === 'string' ? ctl.value.trim() : ctl.value;
      payload[f.name] = v;
    }

    // 必填校验
    const missing = fields.filter((f) => f.required && (payload[f.name] === '' || payload[f.name] === null || payload[f.name] === undefined));
    if (missing.length) {
      errSlot.append(el('div.alert.alert-warning', { text: `请填写：${missing.map((f) => f.label).join('、')}` }));
      return;
    }

    const body = transform ? transform(payload) : payload;
    const { ok, error } = await withLoading(submitBtn, () => onSubmit(body), { silent: true });
    if (ok) {
      notify.success(successMessage);
      saved = true;
      dlg.close();
      dlg.onSaved?.();
    } else if (error) {
      errSlot.append(el('div.alert.alert-danger', { text: error.message || '保存失败' }));
    }
  });

  dlg.onSaved = cfg.onSaved || null;
  return dlg;
}

/** 通用的"删除确认 + 执行"流程 */
export async function confirmDelete(message, { detail = '', onConfirm, successMessage = '删除成功' } = {}) {
  const ok = await confirmDialog(message, {
    title: '删除确认', confirmText: '删除', tone: 'danger', detail,
  });
  if (!ok) return false;
  const { ok: done, error } = await withLoading(null, () => onConfirm(), { silent: true });
  if (done) notify.success(successMessage);
  else if (error) notify.error(error.message || '删除失败');
  return done;
}
