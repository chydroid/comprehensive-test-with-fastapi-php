/**
 * API 资源层 —— 按业务域聚合接口调用，视图只依赖此处，不直接拼 URL。
 */

import { http, download } from '../core/http.js';

/* ============================ 通用 / 门户 ============================ */
export const siteApi = {
  site:     () => http.get('/api/public/site'),
  // 运行参数（入场窗口、轮询间隔等），供各端渲染准确文案与节奏
  settings: () => http.get('/api/public/settings'),
  help:     () => http.get('/api/public/help'),
  hero:     (params) => http.get('/api/public/hero', { query: params }),
  subjects: () => http.get('/api/public/subjects'),
  categories:() => http.get('/api/public/categories'),
  news:     (params) => http.get('/api/public/news', { query: params }),
  newsItem: (id) => http.get(`/api/public/news/${id}`),
  // C1 电子证书公开核验：只需证书编号，姓名在服务端脱敏
  verifyCertificate: (certNo) => http.get('/api/public/certificates/verify', { query: { cert_no: certNo } }),
  health:   () => http.get('/health'),
};

/* ============================ 考生端 ============================ */
export const studentApi = {
  register: (body) => http.post('/api/student/register', body),
  registerOptions: () => http.get('/api/student/register/options'),
  login:    (body) => http.post('/api/student/login', body),
  logout:   () => http.post('/api/student/logout'),
  me:       () => http.get('/api/student/me'),
  info:     () => http.get('/api/student/info'),
  options:  () => http.get('/api/student/options'),
  updateInfo: (body) => http.put('/api/student/info', body),
  updatePassword: (body) => http.put('/api/student/password', body),
  scores:   (params) => http.get('/api/student/scores', { query: params }),
  exams:    () => http.get('/api/student/exams'),
  // B4 成绩公示：本场成绩榜（后端按逐场 score_visibility 决定可见范围）
  scoreBoard: (params) => http.get('/api/student/score-board', { query: params }),
  // C1 电子证书：我的证书（会顺带惰性签发新达标场次）
  certificates: () => http.get('/api/student/certificates'),
  certificate:  (examId) => http.get(`/api/student/certificates/${examId}`),
  wrongBookList:     (params) => http.get('/api/student/wrong-book', { query: params }),
  wrongBookPractice: (params) => http.get('/api/student/wrong-book/practice', { query: params }),
  wrongBookCheck:    (body) => http.post('/api/student/wrong-book/check', body),
  // C5 考后问卷：读取本场题目（含本人已作答）+ 提交反馈
  survey:        (params) => http.get('/api/student/survey', { query: params }),
  submitSurvey:  (body) => http.post('/api/student/survey', body),
  // C3 学习资料库：考生端只读浏览 + 记一次浏览/下载
  materialList: (params) => http.get('/api/student/materials', { query: params }),
  materialHit:  (id) => http.post(`/api/student/materials/${id}/hit`),
};

/* ============================ 考场（正式考试） ============================ */
export const examApi = {
  login:  (body) => http.post('/api/exam/login', body),
  status: () => http.get('/api/exam/status'),
  logout: () => http.post('/api/exam/logout'),
  paper:  (params) => http.get('/api/exam/paper', { query: params }),
  save:   (body) => http.post('/api/exam/paper/save', body),
  submit: (body) => http.post('/api/exam/paper/submit', body),
  over:   () => http.get('/api/exam/over'),
  answer: () => http.get('/api/exam/answer'),
  // B1 防作弊：上报切屏 / 失焦等异常
  reportCheat: (body) => http.post('/api/exam/cheat', body),
};

/* ============================ 练习 / 模拟考试 ============================ */
export const exerciseApi = {
  list:   (params) => http.get('/api/exercise', { query: params }),
  check:  (body) => http.post('/api/exercise/answer', body),
  mockConfig: (params) => http.get('/api/exercise/mock/config', { query: params }),
  mockStart:  (body) => http.post('/api/exercise/mock/start', body),
  mockCounts: (params) => http.get('/api/exercise/mock/counts', { query: params }),
  mockPaper:  (params) => http.get('/api/exercise/mock/paper', { query: params }),
  mockSave:   (body) => http.post('/api/exercise/mock/save', body),
  mockSubmit: (body) => http.post('/api/exercise/mock/submit', body),
  mockOver:   (params) => http.get('/api/exercise/mock/over', { query: params }),
  mockReview: (params) => http.get('/api/exercise/mock/review', { query: params }),
  mockLogout: (body) => http.post('/api/exercise/mock/logout', body),
};

/* ============================ 教师端 ============================ */
export const teacherApi = {
  login:  (body) => http.post('/api/teacher/login', body),
  logout: () => http.post('/api/teacher/logout'),
  me:     () => http.get('/api/teacher/me'),

  monitor:   (params) => http.get('/api/teacher/monitor', { query: params }),
  lock:      (body) => http.post('/api/teacher/monitor/lock', body),
  unlock:    (body) => http.post('/api/teacher/monitor/unlock', body),
  // 单人与全员是两个不同的入口：行内「交卷」必须走 submit-one，
  // 否则监考员想收 1 人却把全场判了分（与管理端 adminApi.submit 同构）。
  submitOne: (body) => http.post('/api/teacher/monitor/submit-one', body),
  submit:    (body) => http.post('/api/teacher/monitor/submit', body),
  lockAll:   (body) => http.post('/api/teacher/monitor/lock-all', body),
  unlockAll: (body) => http.post('/api/teacher/monitor/unlock-all', body),
  overAll:   (body) => http.post('/api/teacher/monitor/over-all', body),

  exams:        (params) => http.get('/api/teacher/exams', { query: params }),
  exam:         (id) => http.get(`/api/teacher/exams/${id}`),
  createExam:   (body) => http.post('/api/teacher/exams', body),
  updateExam:   (id, body) => http.put(`/api/teacher/exams/${id}`, body),
  deleteExam:   (id) => http.del(`/api/teacher/exams/${id}`),
  startExam:    (id) => http.post(`/api/teacher/exams/${id}/start`),
  openExam:     (id) => http.post(`/api/teacher/exams/${id}/open`),
  generatePapers: (id, body) => http.post(`/api/teacher/exams/${id}/generate`, body),
  examStudents: (id, params) => http.get(`/api/teacher/exams/${id}/students`, { query: params }),
  examQuizCount:(id, params) => http.get(`/api/teacher/exams/${id}/quiz-count`, { query: params }),
  // A2 成绩与学情分析（按考试）
  examAnalysis: (id, params) => http.get(`/api/teacher/exams/${id}/analysis`, { query: params }),
  // A3 组卷多样化：手动选题检索 / 知识点清单
  quizSearch:   (params) => http.get('/api/teacher/quiz-search', { query: params }),
  quizKps:      (params) => http.get('/api/teacher/quiz-kps', { query: params }),

  // A4 主观题批改：待批总览 / 考生答题卡 / 提交批阅 / 撤销批阅
  subjectiveList:   (id, params) => http.get(`/api/teacher/exams/${id}/subjective`, { query: params }),
  subjectivePaper:  (id, stuId) => http.get(`/api/teacher/exams/${id}/subjective/${stuId}`),
  subjectiveGrade:  (id, stuId, body) => http.post(`/api/teacher/exams/${id}/subjective/${stuId}`, body),
  subjectiveRevoke: (id, stuId) => http.post(`/api/teacher/exams/${id}/subjective/${stuId}/revoke`),
  // C4 AI 智能组卷：生成建议 / 采用选中题
  composeSuggest: (id, body) => http.post(`/api/teacher/exams/${id}/compose`, body),
  composeApply:   (id, body) => http.post(`/api/teacher/exams/${id}/apply-composition`, body),
  // C5 考后问卷：教师端配置与统计
  surveyShow: (id) => http.get(`/api/teacher/exams/${id}/survey`),
  surveySave: (id, body) => http.put(`/api/teacher/exams/${id}/survey`, body),

  scores:    (params) => http.get('/api/teacher/scores', { query: params }),
  exportScores: (params) => download('/api/teacher/scores/export', { query: params }),
  // B1 防作弊：监考端查看本场异常行为记录
  cheatEvents: (params) => http.get('/api/teacher/monitor/cheat-events', { query: params }),
  // C2 补考：候选名单（未通过/缺考/已通过）与生成补考场次
  retakeCandidates: (id) => http.get(`/api/teacher/exams/${id}/retake-candidates`),
  createRetake:     (id, body) => http.post(`/api/teacher/exams/${id}/retake`, body),
};

/* ============================ 管理后台 ============================ */
export const adminApi = {
  login:  (body) => http.post('/api/admin/login', body),
  logout: () => http.post('/api/admin/logout'),
  me:     () => http.get('/api/admin/me'),
  // 先由 adminApi.uploadPic 拿到 url，再回填到 admininfo.avatar
  uploadAvatar: (body) => http.post('/api/admin/profile/avatar', body),
  updatePassword: (body) => http.put('/api/admin/profile/password', body),

  dashboard: () => http.get('/api/admin/dashboard'),

  admins:       (params) => http.get('/api/admin/admins', { query: params }),
  admin:        (id) => http.get(`/api/admin/admins/${id}`),
  createAdmin:  (body) => http.post('/api/admin/admins', body),
  updateAdmin:  (id, body) => http.put(`/api/admin/admins/${id}`, body),
  deleteAdmin:  (id) => http.del(`/api/admin/admins/${id}`),

  teachers:      (params) => http.get('/api/admin/teachers', { query: params }),
  teacher:       (id) => http.get(`/api/admin/teachers/${id}`),
  createTeacher: (body) => http.post('/api/admin/teachers', body),
  updateTeacher: (id, body) => http.put(`/api/admin/teachers/${id}`, body),
  deleteTeacher: (id) => http.del(`/api/admin/teachers/${id}`),

  subjects:      (params) => http.get('/api/admin/subjects', { query: params }),
  subject:       (id) => http.get(`/api/admin/subjects/${id}`),
  createSubject: (body) => http.post('/api/admin/subjects', body),
  updateSubject: (id, body) => http.put(`/api/admin/subjects/${id}`, body),
  deleteSubject: (id) => http.del(`/api/admin/subjects/${id}`),

  quizzes:      (params) => http.get('/api/admin/quizzes', { query: params }),
  quiz:         (id) => http.get(`/api/admin/quizzes/${id}`),
  createQuiz:   (body) => http.post('/api/admin/quizzes', body),
  updateQuiz:   (id, body) => http.put(`/api/admin/quizzes/${id}`, body),
  deleteQuiz:   (id) => http.del(`/api/admin/quizzes/${id}`),
  batchDeleteQuizzes: (ids) => http.post('/api/admin/quizzes/batch-delete', { ids }),
  // 批量导入：传 { content } 或 FormData（file）
  importQuizzes: (body) => http.post('/api/admin/quizzes/import', body),
  cleanPreview: () => http.get('/api/admin/quizzes/clean/preview'),
  cleanQuizzes: () => http.post('/api/admin/quizzes/clean'),
  advancedClean: () => http.post('/api/admin/quizzes/advanced-clean'),

  exams:        (params) => http.get('/api/admin/exams', { query: params }),
  exam:         (id) => http.get(`/api/admin/exams/${id}`),
  createExam:   (body) => http.post('/api/admin/exams', body),
  updateExam:   (id, body) => http.put(`/api/admin/exams/${id}`, body),
  deleteExam:   (id) => http.del(`/api/admin/exams/${id}`),
  startExam:    (id) => http.post(`/api/admin/exams/${id}/start`),
  openExam:     (id) => http.post(`/api/admin/exams/${id}/open`),
  generatePapers: (id, body) => http.post(`/api/admin/exams/${id}/generate`, body),
  examQuizCount:(id, params) => http.get(`/api/admin/exams/${id}/quiz-count`, { query: params }),
  // A2 成绩与学情分析（按考试）
  examAnalysis: (id, params) => http.get(`/api/admin/exams/${id}/analysis`, { query: params }),
  // C2 补考：候选名单（未通过/缺考/已通过）与生成补考场次
  retakeCandidates: (id) => http.get(`/api/admin/exams/${id}/retake-candidates`),
  createRetake:     (id, body) => http.post(`/api/admin/exams/${id}/retake`, body),
  // A3 组卷多样化：手动选题检索 / 知识点清单
  quizSearch:   (params) => http.get('/api/admin/quiz-search', { query: params }),
  quizKps:      (params) => http.get('/api/admin/quiz-kps', { query: params }),

  categories:      (params) => http.get('/api/admin/exam-categories', { query: params }),
  createCategory:  (body) => http.post('/api/admin/exam-categories', body),
  updateCategory:  (id, body) => http.put(`/api/admin/exam-categories/${id}`, body),
  deleteCategory:  (id) => http.del(`/api/admin/exam-categories/${id}`),

  students:      (params) => http.get('/api/admin/students', { query: params }),
  student:       (id) => http.get(`/api/admin/students/${id}`),
  createStudent: (body) => http.post('/api/admin/students', body),
  updateStudent: (id, body) => http.put(`/api/admin/students/${id}`, body),
  deleteStudent: (id) => http.del(`/api/admin/students/${id}`),
  importStudents:(body) => http.post('/api/admin/students/import', body),
  checkStudentId:(id) => http.get('/api/admin/students/check-id', { query: { id } }),

  grades:        (params) => http.get('/api/admin/grades', { query: params }),
  createGrade:   (body) => http.post('/api/admin/grades', body),
  updateGrade:   (id, body) => http.put(`/api/admin/grades/${id}`, body),
  deleteGrade:   (id) => http.del(`/api/admin/grades/${id}`),

  classes:       (params) => http.get('/api/admin/classes', { query: params }),
  createClass:   (body) => http.post('/api/admin/classes', body),
  updateClass:   (id, body) => http.put(`/api/admin/classes/${id}`, body),
  deleteClass:   (id) => http.del(`/api/admin/classes/${id}`),

    monitor:   (params) => http.get('/api/admin/monitor', { query: params }),
    lock:      (body) => http.post('/api/admin/monitor/lock', body),
    unlock:    (body) => http.post('/api/admin/monitor/unlock', body),
    // submit = 单个考生收卷；submitAll = 全员收卷。两者语义不同，不可混用。
    submit:    (body) => http.post('/api/admin/monitor/submit-one', body),
    submitAll: (body) => http.post('/api/admin/monitor/submit', body),
    lockAll:   (body) => http.post('/api/admin/monitor/lock-all', body),
    unlockAll: (body) => http.post('/api/admin/monitor/unlock-all', body),
    overAll:   (body) => http.post('/api/admin/monitor/over-all', body),

  scores:        (params) => http.get('/api/admin/scores', { query: params }),
  scoreStudents: (params) => http.get('/api/admin/scores/students', { query: params }),
  backupScores:  (body) => http.post('/api/admin/scores/backup', body),
  exportScores:  (params) => download('/api/admin/scores/export', { query: params }),
  backups:       (params) => http.get('/api/admin/scores/backups', { query: params }),

  news:        (params) => http.get('/api/admin/news', { query: params }),
  newsItem:    (id) => http.get(`/api/admin/news/${id}`),
  createNews:  (body) => http.post('/api/admin/news', body),
  updateNews:  (id, body) => http.put(`/api/admin/news/${id}`, body),
  deleteNews:  (id) => http.del(`/api/admin/news/${id}`),

  config:    () => http.get('/api/admin/config'),
  saveConfig:(body) => http.put('/api/admin/config', body),

  // 系统参数（schema 驱动：分组 / 类型 / 范围随响应下发）
  settings:     () => http.get('/api/admin/settings'),
  saveSettings: (body) => http.put('/api/admin/settings', body),
  uploadPic: (formData) => http.post('/api/admin/upload/pic', formData),

  system:        () => http.get('/api/admin/system'),
  initialize:    (body) => http.post('/api/admin/system/initialize', body),
  clearExams:    (body) => http.post('/api/admin/system/clear-exams', body),
  logs:          (params) => http.get('/api/admin/logs', { query: params }),
  // C3 学习资料库（管理端：元数据 CRUD + 文档上传）
  materials:      (params) => http.get('/api/admin/materials', { query: params }),
  createMaterial: (body) => http.post('/api/admin/materials', body),
  deleteMaterial: (id) => http.del(`/api/admin/materials/${id}`),
  // 传 FormData 时 http.js 会自动走 multipart 并带上 CSRF 头
  uploadMaterial: (formData) => http.post('/api/admin/materials/upload', formData),
  // B1 防作弊：监考端查看本场异常行为记录
  cheatEvents: (params) => http.get('/api/admin/monitor/cheat-events', { query: params }),
};
