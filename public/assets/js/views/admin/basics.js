/**
 * 管理后台 —— 基础数据模块（科目 / 考试类别 / 单位 / 班级 / 教师 / 管理员）
 * 均基于 createSimpleCrudView，仅声明字段与列。
 */

import { el } from '../../core/dom.js';
import { icon } from '../../core/icons.js';
import { badge, button, openModal, descList, alertBox, notify, field, input } from '../../ui/components.js';
import { createSimpleCrudView, simpleCols } from './simple-crud.js';
import { adminApi } from '../../api/index.js';
import { withLoading } from '../../core/bootstrap.js';
import { fmtDateTime, initials, hashTone } from '../../core/format.js';
import { passwordHintText } from '../../core/app-settings.js';

const ROLE_LABELS = {
  systemAdmin: '超级管理员',
  testAdmin: '考务管理员',
  quizOperator: '题库维护',
  quizAdder: '题库录入',
};
const ROLE_TONES = {
  systemAdmin: 'danger', testAdmin: 'brand', quizOperator: 'warning', quizAdder: 'info',
};

/* ============================ 科目 ============================ */
export function SubjectView() {
  return createSimpleCrudView({
    title: '考试科目',
    desc: '科目是题库与考试的归属维度',
    entityName: '科目',
    createLabel: '新增科目',
    api: {
      list: (p) => adminApi.subjects(p),
      create: (b) => adminApi.createSubject(b),
      update: (id, b) => adminApi.updateSubject(id, b),
      remove: (id) => adminApi.deleteSubject(id),
    },
    columns: [
      simpleCols.id,
      simpleCols.name('subj_name', '科目名称', { sub: (r) => r.subj_info || '暂无说明' }),
      { key: 'quiz_count', title: '题目数', align: 'center', width: '100px',
        render: (r) => badge(String(r.quiz_count ?? 0), { tone: (r.quiz_count ?? 0) > 0 ? 'brand' : '' }) },
      { key: 'exam_count', title: '关联考试', align: 'center', width: '100px',
        render: (r) => badge(String(r.exam_count ?? 0), { tone: (r.exam_count ?? 0) > 0 ? 'warning' : '' }) },
    ],
    formFields: () => [
      { name: 'subj_name', label: '科目名称', required: true, placeholder: '如：驾驶员考试科目一', maxlength: 100 },
      { name: 'subj_info', label: '科目说明', type: 'textarea', rows: 3, placeholder: '简要说明该科目的范围与用途', colSpan: 2 },
    ],
    deleteMessage: (r) => `确定删除科目「${r.subj_name}」吗？`,
  });
}

/* ============================ 考试类别 ============================ */
export function CategoryView() {
  return createSimpleCrudView({
    title: '考试类别',
    desc: '用于对考试进行分类归档',
    entityName: '类别',
    createLabel: '新增类别',
    api: {
      list: (p) => adminApi.categories(p),
      create: (b) => adminApi.createCategory(b),
      update: (id, b) => adminApi.updateCategory(id, b),
      remove: (id) => adminApi.deleteCategory(id),
    },
    columns: [
      simpleCols.id,
      simpleCols.name('category_name', '类别名称'),
      { key: 'sort_order', title: '排序', align: 'center', width: '80px', sortable: true,
        render: (r) => el('span.mono.fs-sm', { text: String(r.sort_order ?? 0) }) },
      { key: 'exam_count', title: '关联考试', align: 'center', width: '100px',
        render: (r) => badge(String(r.exam_count ?? 0), { tone: (r.exam_count ?? 0) > 0 ? 'warning' : '' }) },
    ],
    formFields: () => [
      { name: 'category_name', label: '类别名称', required: true, placeholder: '如：摸底考试', maxlength: 50 },
      { name: 'sort_order', label: '排序值', type: 'number', min: 0, max: 9999, hint: '数值越小越靠前' },
    ],
    deleteMessage: (r) => `确定删除类别「${r.category_name}」吗？`,
  });
}

/* ============================ 单位（gradeinfo） ============================ */
export function GradeView() {
  return createSimpleCrudView({
    title: '单位管理',
    desc: '考生所属单位 / 部门（对应原系统"单位"）',
    entityName: '单位',
    createLabel: '新增单位',
    api: {
      list: (p) => adminApi.grades(p),
      create: (b) => adminApi.createGrade(b),
      update: (id, b) => adminApi.updateGrade(id, b),
      remove: (id) => adminApi.deleteGrade(id),
    },
    columns: [
      simpleCols.id,
      simpleCols.name('grade_name', '单位名称', { sub: (r) => r.grade_info || '暂无说明' }),
      { key: 'stu_count', title: '考生数', align: 'center', width: '100px',
        render: (r) => badge(String(r.stu_count ?? 0), { tone: (r.stu_count ?? 0) > 0 ? 'info' : '' }) },
    ],
    formFields: () => [
      { name: 'grade_name', label: '单位名称', required: true, placeholder: '如：XX 海事局', maxlength: 100 },
      { name: 'grade_info', label: '备注', type: 'textarea', rows: 3, colSpan: 2 },
    ],
    deleteMessage: (r) => `确定删除单位「${r.grade_name}」吗？`,
  });
}

/* ============================ 班级（classinfo） ============================ */
export function ClassView() {
  return createSimpleCrudView({
    title: '班级管理',
    desc: '考生所属班级，考试按班级指派',
    entityName: '班级',
    createLabel: '新增班级',
    api: {
      list: (p) => adminApi.classes(p),
      create: (b) => adminApi.createClass(b),
      update: (id, b) => adminApi.updateClass(id, b),
      remove: (id) => adminApi.deleteClass(id),
    },
    columns: [
      simpleCols.id,
      simpleCols.name('class_name', '班级名称', { sub: (r) => r.class_info || '暂无说明' }),
      { key: 'stu_count', title: '考生数', align: 'center', width: '100px',
        render: (r) => badge(String(r.stu_count ?? 0), { tone: (r.stu_count ?? 0) > 0 ? 'info' : '' }) },
    ],
    formFields: () => [
      { name: 'class_name', label: '班级名称', required: true, placeholder: '如：2026 级驾驶一班', maxlength: 100 },
      { name: 'class_info', label: '备注', type: 'textarea', rows: 3, colSpan: 2 },
    ],
    deleteMessage: (r) => `确定删除班级「${r.class_name}」吗？`,
  });
}

/* ============================ 教师 ============================ */
export function TeacherView() {
  return createSimpleCrudView({
    title: '教师管理',
    desc: '教师可创建并管理自己名下的考试',
    entityName: '教师',
    createLabel: '新增教师',
    api: {
      list: (p) => adminApi.teachers(p),
      create: (b) => adminApi.createTeacher(b),
      update: (id, b) => adminApi.updateTeacher(id, b),
      remove: (id) => adminApi.deleteTeacher(id),
    },
    columns: [
      simpleCols.id,
      { key: 'tea_name', title: '姓名', sortable: true,
        render: (r) => el('div.flex.items-center.gap-3', {}, [
          el('div.avatar.avatar-sm', { style: { background: hashTone(r.tea_name || '', TONES) }, text: initials(r.tea_name) }),
          el('span.fw-500', { text: r.tea_name || '—' }),
        ]) },
      // 说明：teainfo 表只有 id / tea_name / tea_pwd / avatar 四列，
      // 不存在 tea_sex / tea_phone / tea_info，故不再展示这些「永远为空」的列与输入项。
    ],
    formFields: (row) => {
      const isEdit = !!row;
      return [
        { name: 'tea_name', label: '姓名', required: true, maxlength: 50, placeholder: '教师姓名' },
        // 字段名必须是 password：后端 TeacherController 校验的是 'password'。
        // 此前发 tea_pwd，后端按「password 不能为空」直接 400，新增教师必然失败。
        { name: 'password', label: '登录密码', type: 'password', required: !isEdit,
          placeholder: isEdit ? '留空表示不修改' : `${passwordHintText()}，不能为纯数字`, hint: isEdit ? '不修改请留空' : '' },
      ];
    },
    transform: (v) => {
      // 编辑时空密码不提交
      const out = { ...v };
      if (!out.password) delete out.password;
      return out;
    },
    deleteMessage: (r) => `确定删除教师「${r.tea_name}」吗？`,
  });
}

/* ============================ 管理员 ============================ */
export function AdminView() {
  return createSimpleCrudView({
    title: '管理员账号',
    desc: '按角色分配权限；后端对每个请求强校验，前端菜单仅作引导',
    entityName: '管理员',
    createLabel: '新增管理员',
    api: {
      list: (p) => adminApi.admins(p),
      create: (b) => adminApi.createAdmin(b),
      update: (id, b) => adminApi.updateAdmin(id, b),
      remove: (id) => adminApi.deleteAdmin(id),
    },
    columns: [
      simpleCols.id,
      { key: 'username', title: '账号', sortable: true,
        render: (r) => el('div.flex.items-center.gap-3', {}, [
          el('div.avatar.avatar-sm', { style: { background: hashTone(r.username || '', TONES) }, text: initials(r.username) }),
          el('span.fw-500.mono', { text: r.username || '—' }),
        ]) },
      { key: 'admin_power', title: '角色', width: '130px',
        render: (r) => badge(ROLE_LABELS[r.admin_power] || r.admin_power || '—', { tone: ROLE_TONES[r.admin_power] || '' }) },
      { key: 'created_at', title: '创建时间', width: '170px',
        render: (r) => el('span.fs-sm.c-secondary', { text: fmtDateTime(r.created_at) }) },
    ],
    formFields: (row) => {
      const isEdit = !!row;
      return [
        { name: 'username', label: '登录账号', required: true, maxlength: 50, disabled: isEdit,
          placeholder: '字母或数字组合', hint: isEdit ? '账号创建后不可修改' : '' },
        { name: 'admin_power', label: '角色', type: 'select', required: true,
          options: Object.entries(ROLE_LABELS).map(([value, label]) => ({ value, label })) },
        { name: 'password', label: '登录密码', type: 'password', required: !isEdit, colSpan: 2,
          placeholder: isEdit ? '留空表示不修改密码' : `${passwordHintText()}，且不能为纯数字`,
          hint: isEdit ? '出于安全考虑，留空则不修改' : '弱密码会被拒绝（如 admin、123456）' },
      ];
    },
    transform: (v) => {
      const out = { ...v };
      if (!out.password) delete out.password;
      return out;
    },
    deleteMessage: (r) => `确定删除管理员「${r.username}」吗？`,
  });
}

const TONES = [
  'linear-gradient(135deg,#6366f1,#4338ca)',
  'linear-gradient(135deg,#06b6d4,#0e7490)',
  'linear-gradient(135deg,#10b981,#047857)',
  'linear-gradient(135deg,#f59e0b,#b45309)',
  'linear-gradient(135deg,#ef4444,#b91c1c)',
  'linear-gradient(135deg,#8b5cf6,#6d28d9)',
];

/* ============================ 公告 ============================ */
export function NewsView() {
  return createSimpleCrudView({
    title: '考试公告',
    desc: '公告将展示在门户首页与考生端',
    entityName: '公告',
    createLabel: '发布公告',
    api: {
      list: (p) => adminApi.news(p),
      create: (b) => adminApi.createNews(b),
      update: (id, b) => adminApi.updateNews(id, b),
      remove: (id) => adminApi.deleteNews(id),
    },
    columns: [
      simpleCols.id,
      simpleCols.name('news_title', '标题', { sub: (r) => truncate(r.news_info, 80) }),
      { key: 'news_writer', title: '发布人', width: '120px', render: (r) => el('span.fs-sm', { text: r.news_writer || '—' }) },
      { key: 'news_time', title: '发布时间', width: '170px',
        render: (r) => el('span.fs-sm.c-secondary', { text: fmtDateTime(r.news_time) }) },
    ],
    formFields: () => [
      { name: 'news_title', label: '公告标题', required: true, maxlength: 200, colSpan: 2, placeholder: '请输入公告标题' },
      { name: 'news_info', label: '公告内容', type: 'textarea', rows: 8, required: true, colSpan: 2, placeholder: '请输入公告正文' },
    ],
    deleteMessage: (r) => `确定删除公告「${r.news_title}」吗？`,
  });
}

function truncate(s, n) {
  const str = String(s ?? '').replace(/\s+/g, ' ');
  return str.length > n ? str.slice(0, n) + '…' : str;
}
