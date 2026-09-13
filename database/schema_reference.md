# csip_exam 库表结构参考（从生产库导出）

> 生成时间：2026-09-13
> 用途：新项目 `comprehensive-test-with-fastapi-php` 的唯一 schema 契约（复用旧库，不做迁移）。
> 所有控制器/模型字段命名必须以此为准。

## admininfo（管理员，1 行）
| 列 | 类型 | 说明 |
|---|---|---|
| id | int PK AI | |
| username | varchar(50) | 登录名 |
| password | varchar(255) | 密码哈希 |
| avatar | varchar(255) | 头像路径，可空 |
| admin_power | varchar(20) | systemAdmin / testAdmin / quizOperator / quizAdder |

## stuinfo（考生，3 行）
| 列 | 类型 | 说明 |
|---|---|---|
| id | int PK AI | |
| stu_name | varchar(50) | 姓名（登录名，唯一语义） |
| stu_pwd | varchar(255) | 密码哈希 |
| stu_sex | varchar(4) | 性别 |
| grade_id | int | 单位/年级 id → gradeinfo.id |
| class_id | int | 班级 id → classinfo.id |

## teainfo（监考教师，2 行）
| 列 | 类型 | 说明 |
|---|---|---|
| id | int PK AI | |
| tea_name | varchar(50) | 登录名 |
| tea_pwd | varchar(255) | 密码 |
| avatar | varchar(255) | 头像 |

## gradeinfo（单位/年级，1 行）
| 列 | 类型 | 说明 |
|---|---|---|
| id | int PK AI | |
| grade_name | varchar(50) | 单位名称 |
| grade_info | varchar(255) | 备注 |

## classinfo（班级，1 行）
| 列 | 类型 | 说明 |
|---|---|---|
| id | int PK AI | |
| class_name | varchar(50) | 班级名称 |
| class_info | varchar(255) | 备注 |

## subject（科目，3 行）
| 列 | 类型 | 说明 |
|---|---|---|
| id | int PK AI | |
| subj_name | varchar(50) | 科目名称 |
| subj_info | varchar(255) | 说明 |

## exam_category（考试类别，12 行）
| 列 | 类型 | 说明 |
|---|---|---|
| id | int PK AI | |
| category_name | varchar(50) | 类别名称 |
| sort_order | int | 排序 |

## quizlib（题库，994 行）
| 列 | 类型 | 说明 |
|---|---|---|
| id | int PK AI | |
| subj_id | int | 科目 id |
| quiz_title | text | 题干 |
| quiz_class | enum | radio1 单选 / radio2 多选 / checkbox 判断 / text 填空 / longtext 问答 |
| quiz_option | text | 选项原文（换行或 \|\|\| 分隔） |
| quiz_key | varchar(255) | 正确答案 —— **禁止下发考生端** |
| quiz_diff | enum('Y','Z','N') | Y 易 / Z 中 / N 难 |
| quiz_writer | varchar(50) | 录入人 |
| quiz_time | **date** | 录入日期（注意：是 date 不是 datetime） |
| quiz_pic_name | varchar(255) | 配图文件名，可空 |
| quiz_hits | int | 被答次数 |
| quiz_key_ok | int | 答对次数（成功率统计） |

## examinfo（考试，35 行，28 列）
试卷由「按题型 + 难度从题库抽题」组成。**每种题型有 3 个难度抽题数 + 1 个每题分值**：

| 列 | 说明 |
|---|---|
| id | PK |
| exam_name | varchar(200) 考试名称 |
| exam_class | varchar(100) 考试分类标签 |
| exam_category_id | int → exam_category.id |
| subj_id | int → subject.id |
| exam_start / exam_end | datetime 考试开放区间 |
| exam_tea | varchar(50) 监考教师名 |
| stu_class | varchar(100) 参考班级（逗号分隔或标识） |
| **抽题数量（易/中/难）** | |
| radio1_easy_sum / radio1_mid_sum / radio1_hard_sum | 单选题各难度抽题数 |
| radio2_easy_sum / radio2_mid_sum / radio2_hard_sum | 多选题各难度抽题数 |
| checkbox_easy_sum / checkbox_mid_sum / checkbox_hard_sum | 判断题各难度抽题数 |
| text_easy_sum / text_mid_sum / text_hard_sum | 填空题各难度抽题数 |
| **每题分值** | |
| radio1_val / radio2_val / checkbox_val / text_val | 该题型每题分（注意是 `_val` 不是 `_score`） |
| exam_status | varchar(20) 考试状态 |
| exam_pwd | int 考试口令 |
| exam_score | int 试卷满分 |

> 组卷校验：某题型的「某难度抽题数」不得超过该题型该难度在题库中的实际题量，
> 否则生成试卷时该题型会缺题。见 `App\Models\Exam::checkStock()`。

## stupaper（考生答卷，477 行）
| 列 | 说明 |
|---|---|
| id | PK |
| exam_id | int 考试 id |
| stu_id | varchar(20) 考生 id |
| paper_id | int 试卷号（同场考试内按考生生成） |
| quiz_id | int 题目 id |
| quiz_class | varchar(20) 题型快照 |
| stu_key | text **考生作答** |
| quiz_status | tinyint 0 未答 / 1 已答 |

## stuscore（成绩，59 行）
| 列 | 说明 |
|---|---|
| id | PK |
| exam_id | int 考试 id |
| stu_id | varchar(20) 考生 id |
| stu_score | int 得分 |
| stu_status | **varchar(20)** 交卷状态（'0' 未交卷 / '1' 已交卷） |
| stu_pwd | varchar(20) 考试口令快照 |

## stuscorebak（成绩备份，50 行）
| 列 | 说明 |
|---|---|
| id | PK |
| stu_id / stu_name / grade_id / stu_score / exam_id | 备份字段 |
| backup_time | 备份时间 |

## siteconfig（站点配置，4 行，key-value）
| 列 | 说明 |
|---|---|
| id | PK |
| config_key | varchar(100) 配置键 |
| config_value | varchar(500) 配置值 |

## examnews（新闻公告，1 行）
| 列 | 说明 |
|---|---|
| id | PK |
| news_title | varchar(200) 标题 |
| news_info | text 正文 |
| news_writer | varchar(50) 作者 |
| news_time | datetime 发布时间 |

## stuinfo 补充（真实类型）
| 列 | 类型 | 说明 |
|---|---|---|
| stu_pwd | varchar(64) | 密码哈希（注意 64 位，适合 sha256/bcrypt 截断） |
| stu_sex | varchar(10) | 性别 |
| grade_id | **varchar(100)** | 单位 id（字符串，非 int） |
| class_id | **varchar(100)** | 班级 id（字符串，非 int） |

