/**
 * API 资源层 —— 按业务域聚合接口调用，视图只依赖此处，不直接拼 URL。
 */

import { http, download } from '../core/http.js';

/* ============================ 通用 / 门户 ============================ */
export const siteApi = {
  site:     () => http.get('/public/site'),
  // 运行参数（入场窗口、轮询间隔等），供各端渲染准确文案与节奏
  settings: () => http.get('/public/settings'),
  help:     () => http.get('/public/help'),
  hero:     (params) => http.get('/public/hero', { query: params }),
  subjects: () => http.get('/public/subjects'),
  categories:() => http.get('/public/categories'),
  news:     (params) => http.get('/public/news', { query: params }),
  newsItem: (id) => http.get(`/public/news/${id}`),
  // C1 电子证书公开核验：只需证书编号，姓名在服务端脱敏
  verifyCertificate: (certNo) => http.get('/public/certificates/verify', { query: { cert_no: certNo } }),
  health:   () => http.get('/health'),
};

/* ============================ 考生端 ============================ */
export const studentApi = {
  register: (body) => http.post('/student/register', body),
  registerOptions: () => http.get('/student/register/options'),
  login:    (body) => http.post('/student/login', body),
  logout:   () => http.post('/student/logout'),
  me:       () => http.get('/student/me'),
  info:     () => http.get('/student/info'),
  options:  () => http.get('/student/options'),
  updateInfo: (body) => http.put('/student/info', body),
  updatePassword: (body) => http.put('/student/password', body),
  scores:   (params) => http.get('/student/scores', { query: params }),
  exams:    () => http.get('/student/exams'),
  // B4 成绩公示：本场成绩榜（后端按逐场 score_visibility 决定可见范围）
  scoreBoard: (params) => http.get('/student/score-board', { query: params }),
  // C1 电子证书：我的证书（会顺带惰性签发新达标场次）
  certificates: () => http.get('/student/certificates'),
  certificate:  (examId) => http.get(`/student/certificates/${examId}`),
  wrongBookList:     (params) => http.get('/student/wrong-book', { query: params }),
  wrongBookPractice: (params) => http.get('/student/wrong-book/practice', { query: params }),
  wrongBookCheck:    (body) => http.post('/student/wrong-book/check', body),
  // C5 考后问卷：读取本场题目（含本人已作答）+ 提交反馈
  survey:        (params) => http.get('/student/survey', { query: params }),
  submitSurvey:  (body) => http.post('/student/survey', body),
  // C3 学习资料库：考生端只读浏览 + 记一次浏览/下载
  materialList: (params) => http.get('/student/materials', { query: params }),
  materialHit:  (id) => http.post(`/student/materials/${id}/hit`),
};

/* ============================ 考场（正式考试） ============================ */
export const examApi = {
  login:  (body) => http.post('/exam/login', body),
  status: () => http.get('/exam/status'),
  logout: () => http.post('/exam/logout'),
  paper:  (params) => http.get('/exam/paper', { query: params }),
  save:   (body) => http.post('/exam/paper/save', body),
  submit: (body) => http.post('/exam/paper/submit', body),
  over:   () => http.get('/exam/over'),
  answer: () => http.get('/exam/answer'),
  // B1 防作弊：上报切屏 / 失焦等异常
  reportCheat: (body) => http.post('/exam/cheat', body),
};

/* ============================ 练习 / 模拟考试 ============================ */
export const exerciseApi = {
  list:   (params) => http.get('/exercise', { query: params }),
  check:  (body) => http.post('/exercise/answer', body),
  mockConfig: (params) => http.get('/exercise/mock/config', { query: params }),
  mockStart:  (body) => http.post('/exercise/mock/start', body),
  mockCounts: (params) => http.get('/exercise/mock/counts', { query: params }),
  mockPaper:  (params) => http.get('/exercise/mock/paper', { query: params }),
  mockSave:   (body) => http.post('/exercise/mock/save', body),
  mockSubmit: (body) => http.post('/exercise/mock/submit', body),
  mockOver:   (params) => http.get('/exercise/mock/over', { query: params }),
  mockReview: (params) => http.get('/exercise/mock/review', { query: params }),
  mockLogout: (body) => http.post('/exercise/mock/logout', body),
};

/* ============================ 教师端 ============================ */
export const teacherApi = {
  login:  (body) => http.post('/teacher/login', body),
  logout: () => http.post('/teacher/logout'),
  me:     () => http.get('/teacher/me'),

  monitor:   (params) => http.get('/teacher/monitor', { query: params }),
  lock:      (body) => http.post('/teacher/monitor/lock', body),
  unlock:    (body) => http.post('/teacher/monitor/unlock', body),
  // 单人与全员是两个不同的入口：行内「交卷」必须走 submit-one，
  // 否则监考员想收 1 人却把全场判了分（与管理端 adminApi.submit 同构）。
  submitOne: (body) => http.post('/teacher/monitor/submit-one', body),
  submit:    (body) => http.post('/teacher/monitor/submit', body),
  lockAll:   (body) => http.post('/teacher/monitor/lock-all', body),
  unlockAll: (body) => http.post('/teacher/monitor/unlock-all', body),
  overAll:   (body) => http.post('/teacher/monitor/over-all', body),

  exams:        (params) => http.get('/teacher/exams', { query: params }),
  exam:         (id) => http.get(`/teacher/exams/${id}`),
  createExam:   (body) => http.post('/teacher/exams', body),
  updateExam:   (id, body) => http.put(`/teacher/exams/${id}`, body),
  deleteExam:   (id) => http.del(`/teacher/exams/${id}`),
  startExam:    (id) => http.post(`/teacher/exams/${id}/start`),
  openExam:     (id) => http.post(`/teacher/exams/${id}/open`),
  generatePapers: (id, body) => http.post(`/teacher/exams/${id}/generate`, body),
  examStudents: (id, params) => http.get(`/teacher/exams/${id}/students`, { query: params }),
  examQuizCount:(id, params) => http.get(`/teacher/exams/${id}/quiz-count`, { query: params }),
  // A2 成绩与学情分析（按考试）
  examAnalysis: (id, params) => http.get(`/teacher/exams/${id}/analysis`, { query: params }),
  // A3 组卷多样化：手动选题检索 / 知识点清单
  quizSearch:   (params) => http.get('/teacher/quiz-search', { query: params }),
  quizKps:      (params) => http.get('/teacher/quiz-kps', { query: params }),

  // A4 主观题批改：待批总览 / 考生答题卡 / 提交批阅 / 撤销批阅
  subjectiveList:   (id, params) => http.get(`/teacher/exams/${id}/subjective`, { query: params }),
  subjectivePaper:  (id, stuId) => http.get(`/teacher/exams/${id}/subjective/${stuId}`),
  subjectiveGrade:  (id, stuId, body) => http.post(`/teacher/exams/${id}/subjective/${stuId}`, body),
  subjectiveRevoke: (id, stuId) => http.post(`/teacher/exams/${id}/subjective/${stuId}/revoke`),
  // C4 AI 智能组卷：生成建议 / 采用选中题
  composeSuggest: (id, body) => http.post(`/teacher/exams/${id}/compose`, body),
  composeApply:   (id, body) => http.post(`/teacher/exams/${id}/apply-composition`, body),
  // C5 考后问卷：教师端配置与统计
  surveyShow: (id) => http.get(`/teacher/exams/${id}/survey`),
  surveySave: (id, body) => http.put(`/teacher/exams/${id}/survey`, body),

  scores:    (params) => http.get('/teacher/scores', { query: params }),
  exportScores: (params) => download('/teacher/scores/export', { query: params }),
  // B1 防作弊：监考端查看本场异常行为记录
  cheatEvents: (params) => http.get('/teacher/monitor/cheat-events', { query: params }),
  // C2 补考：候选名单（未通过/缺考/已通过）与生成补考场次
  retakeCandidates: (id) => http.get(`/teacher/exams/${id}/retake-candidates`),
  createRetake:     (id, body) => http.post(`/teacher/exams/${id}/retake`, body),
};

/* ============================ 管理后台 ============================ */
export const adminApi = {
  login:  (body) => http.post('/admin/login', body),
  logout: () => http.post('/admin/logout'),
  me:     () => http.get('/admin/me'),
  // 先由 adminApi.uploadPic 拿到 url，再回填到 admininfo.avatar
  uploadAvatar: (body) => http.post('/admin/profile/avatar', body),
  updatePassword: (body) => http.put('/admin/profile/password', body),

  dashboard: () => http.get('/admin/dashboard'),

  admins:       (params) => http.get('/admin/admins', { query: params }),
  admin:        (id) => http.get(`/admin/admins/${id}`),
  createAdmin:  (body) => http.post('/admin/admins', body),
  updateAdmin:  (id, body) => http.put(`/admin/admins/${id}`, body),
  deleteAdmin:  (id) => http.del(`/admin/admins/${id}`),

  teachers:      (params) => http.get('/admin/teachers', { query: params }),
  teacher:       (id) => http.get(`/admin/teachers/${id}`),
  createTeacher: (body) => http.post('/admin/teachers', body),
  updateTeacher: (id, body) => http.put(`/admin/teachers/${id}`, body),
  deleteTeacher: (id) => http.del(`/admin/teachers/${id}`),

  subjects:      (params) => http.get('/admin/subjects', { query: params }),
  subject:       (id) => http.get(`/admin/subjects/${id}`),
  createSubject: (body) => http.post('/admin/subjects', body),
  updateSubject: (id, body) => http.put(`/admin/subjects/${id}`, body),
  deleteSubject: (id) => http.del(`/admin/subjects/${id}`),

  quizzes:      (params) => http.get('/admin/quizzes', { query: params }),
  quiz:         (id) => http.get(`/admin/quizzes/${id}`),
  createQuiz:   (body) => http.post('/admin/quizzes', body),
  updateQuiz:   (id, body) => http.put(`/admin/quizzes/${id}`, body),
  deleteQuiz:   (id) => http.del(`/admin/quizzes/${id}`),
  batchDeleteQuizzes: (ids) => http.post('/admin/quizzes/batch-delete', { ids }),
  // 批量导入：传 { content } 或 FormData（file）
  importQuizzes: (body) => http.post('/admin/quizzes/import', body),
  cleanPreview: () => http.get('/admin/quizzes/clean/preview'),
  cleanQuizzes: () => http.post('/admin/quizzes/clean'),
  advancedClean: () => http.post('/admin/quizzes/advanced-clean'),

  exams:        (params) => http.get('/admin/exams', { query: params }),
  exam:         (id) => http.get(`/admin/exams/${id}`),
  createExam:   (body) => http.post('/admin/exams', body),
  updateExam:   (id, body) => http.put(`/admin/exams/${id}`, body),
  deleteExam:   (id) => http.del(`/admin/exams/${id}`),
  startExam:    (id) => http.post(`/admin/exams/${id}/start`),
  openExam:     (id) => http.post(`/admin/exams/${id}/open`),
  generatePapers: (id, body) => http.post(`/admin/exams/${id}/generate`, body),
  examQuizCount:(id, params) => http.get(`/admin/exams/${id}/quiz-count`, { query: params }),
  // A2 成绩与学情分析（按考试）
  examAnalysis: (id, params) => http.get(`/admin/exams/${id}/analysis`, { query: params }),
  // C2 补考：候选名单（未通过/缺考/已通过）与生成补考场次
  retakeCandidates: (id) => http.get(`/admin/exams/${id}/retake-candidates`),
  createRetake:     (id, body) => http.post(`/admin/exams/${id}/retake`, body),
  // A3 组卷多样化：手动选题检索 / 知识点清单
  quizSearch:   (params) => http.get('/admin/quiz-search', { query: params }),
  quizKps:      (params) => http.get('/admin/quiz-kps', { query: params }),

  categories:      (params) => http.get('/admin/exam-categories', { query: params }),
  createCategory:  (body) => http.post('/admin/exam-categories', body),
  updateCategory:  (id, body) => http.put(`/admin/exam-categories/${id}`, body),
  deleteCategory:  (id) => http.del(`/admin/exam-categories/${id}`),

  students:      (params) => http.get('/admin/students', { query: params }),
  student:       (id) => http.get(`/admin/students/${id}`),
  createStudent: (body) => http.post('/admin/students', body),
  updateStudent: (id, body) => http.put(`/admin/students/${id}`, body),
  deleteStudent: (id) => http.del(`/admin/students/${id}`),
  importStudents:(body) => http.post('/admin/students/import', body),
  checkStudentId:(id) => http.get('/admin/students/check-id', { query: { id } }),

  grades:        (params) => http.get('/admin/grades', { query: params }),
  createGrade:   (body) => http.post('/admin/grades', body),
  updateGrade:   (id, body) => http.put(`/admin/grades/${id}`, body),
  deleteGrade:   (id) => http.del(`/admin/grades/${id}`),

  classes:       (params) => http.get('/admin/classes', { query: params }),
  createClass:   (body) => http.post('/admin/classes', body),
  updateClass:   (id, body) => http.put(`/admin/classes/${id}`, body),
  deleteClass:   (id) => http.del(`/admin/classes/${id}`),

    monitor:   (params) => http.get('/admin/monitor', { query: params }),
    lock:      (body) => http.post('/admin/monitor/lock', body),
    unlock:    (body) => http.post('/admin/monitor/unlock', body),
    // submit = 单个考生收卷；submitAll = 全员收卷。两者语义不同，不可混用。
    submit:    (body) => http.post('/admin/monitor/submit-one', body),
    submitAll: (body) => http.post('/admin/monitor/submit', body),
    lockAll:   (body) => http.post('/admin/monitor/lock-all', body),
    unlockAll: (body) => http.post('/admin/monitor/unlock-all', body),
    overAll:   (body) => http.post('/admin/monitor/over-all', body),

  scores:        (params) => http.get('/admin/scores', { query: params }),
  scoreStudents: (params) => http.get('/admin/scores/students', { query: params }),
  backupScores:  (body) => http.post('/admin/scores/backup', body),
  exportScores:  (params) => download('/admin/scores/export', { query: params }),
  backups:       (params) => http.get('/admin/scores/backups', { query: params }),

  news:        (params) => http.get('/admin/news', { query: params }),
  newsItem:    (id) => http.get(`/admin/news/${id}`),
  createNews:  (body) => http.post('/admin/news', body),
  updateNews:  (id, body) => http.put(`/admin/news/${id}`, body),
  deleteNews:  (id) => http.del(`/admin/news/${id}`),

  config:    () => http.get('/admin/config'),
  saveConfig:(body) => http.put('/admin/config', body),

  // 系统参数（schema 驱动：分组 / 类型 / 范围随响应下发）
  settings:     () => http.get('/admin/settings'),
  saveSettings: (body) => http.put('/admin/settings', body),
  uploadPic: (formData) => http.post('/admin/upload/pic', formData),

  system:        () => http.get('/admin/system'),
  initialize:    (body) => http.post('/admin/system/initialize', body),
  clearExams:    (body) => http.post('/admin/system/clear-exams', body),
  logs:          (params) => http.get('/admin/logs', { query: params }),
  // C3 学习资料库（管理端：元数据 CRUD + 文档上传）
  materials:      (params) => http.get('/admin/materials', { query: params }),
  createMaterial: (body) => http.post('/admin/materials', body),
  deleteMaterial: (id) => http.del(`/admin/materials/${id}`),
  // 传 FormData 时 http.js 会自动走 multipart 并带上 CSRF 头
  uploadMaterial: (formData) => http.post('/admin/materials/upload', formData),
  // B1 防作弊：监考端查看本场异常行为记录
  cheatEvents: (params) => http.get('/admin/monitor/cheat-events', { query: params }),
};
