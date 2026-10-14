# 部署手册：宝塔面板 + Linux（Nginx + PHP-FPM + MySQL）

适用路径：**宝塔面板建站** + **git clone 拉代码** + **从现有系统迁移真实数据**。
项目本身零 Composer 依赖、前端无构建步骤，因此部署动作比常规 PHP 项目少得多——真正的风险几乎全在
「根目录指错」「权限不对」「伪静态没配」这三件事上。

---

## 0. 五条硬约束（先看这个，能省掉 90% 的返工）

| # | 约束 | 值 | 违反后的症状 |
|---|---|---|---|
| 1 | **PHP 版本** | **≥ 8.1**（硬性） | `core/Controller.php` 等用了 `readonly`，低版本直接 Parse error，全站 500 |
| 2 | **站点根目录** | 必须是 **`public/`** | 指到项目根 → `.env` / `core/` / `app/` 可被浏览器直接下载（**严重泄密**） |
| 3 | **伪静态** | 必须把不存在的路径重写到 `index.php` | 门户能开，`/api/*`、`/manage`、`/exam` 全 404 |
| 4 | **可写目录** | `storage/cache`、`storage/logs`、`public/uploads` | 这三个目录 `.gitkeep` 没入仓，clone 后**不存在**，需手工建并给 `www` 属主 |
| 5 | **`.env`** | 不入仓，服务器手工创建 | 连不上库，`/api/health` 返回 `db:false` |

补充：MySQL 时区**不用改**——`core/Database.php` 在每次建连接时强制 `SET time_zone='+08:00'`
（用偏移量，不依赖时区表）。服务器 `date` 显示 UTC 也不影响业务。

---

## 1. 服务器软件准备（宝塔面板）

软件商店安装（版本选 ≥ 以下）：

- **Nginx** 任意稳定版
- **MySQL 5.7 / 8.0** 或 MariaDB 10.5+
- **PHP 8.1 / 8.2 / 8.3**（本机开发用 8.3.8，建议对齐 8.3）

装完 PHP 后：**软件商店 → PHP-8.x → 设置**

| 位置 | 操作 |
|---|---|
| 安装扩展 | 确认 `pdo_mysql` 已装；安装 `mbstring`（建议）、`opcache`（建议，性能） |
| 上传限制 | `post_max_size = 25M`、`upload_max_filesize = 20M`、`memory_limit = 256M` |
| 禁用函数 | 默认即可（本项目不依赖 `exec`/`shell_exec`/`putenv`） |

> 为什么 `post_max_size` 要给到 25M：图片走 base64 放进 JSON，2MB 图片实际请求体约 2.8MB；
> 课件资料上限 20MB。PHP 层先放行，再由应用层（`MAX_BODY_BYTES`）按业务上限拦截 413。

---

## 2. 建站（根目录必须指到 public）

**网站 → 添加站点**

- 域名：填你的域名（或先填 IP）
- 根目录：改成 **`/www/wwwroot/<你的站点>/public`**
  （先让它自动建出 `/www/wwwroot/<站点>`，再把 `public` 补上；目录稍后由 git clone 产生）
- 数据库：**选「不创建」**（第 6 节用面板或脚本建）
- PHP 版本：**8.1 以上**

建站后依次处理：

### 2.1 删掉宝塔自动生成的默认页（必做）

宝塔建站会在根目录生成 `index.html` / `404.html`。若根目录是 `public/`，这个
`index.html` 会抢在 `index.php` 前面被 nginx 的 `index` 指令命中——**门户会变成
"Welcome to nginx!"**。（本仓 `.gitignore` 里专门排除了这两个文件，就是这个坑留下的痕迹。）

```bash
rm -f /www/wwwroot/<站点>/public/index.html /www/wwwroot/<站点>/public/404.html
```

### 2.2 配置伪静态（必做）

**站点设置 → 伪静态** → 粘贴 `docs/nginx-rewrite.conf` 的内容：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

### 2.3 处理 open_basedir（必做，否则 500）

宝塔默认开「防跨站攻击(open_basedir)」，限制 PHP 只能访问站点根目录。
但本项目的 `public/index.php` 必须 `require ../core/helpers.php`——
被限制后会报 `require(): open_basedir restriction in effect`，**全站 500**。

二选一：

- **推荐**：站点设置 → 网站目录 → **取消勾选「防跨站攻击(open_basedir)」**
- 或者手工改 `public/.user.ini`（该文件按机器不同，已 gitignore），把项目根加进去：
  `open_basedir=/www/wwwroot/<站点>/:/tmp/:/proc/`

> 注意：即便根目录是 `public/`，代码目录之外的 `.env` 也**不会被 Web 读到**
> （nginx root 在 `public/`，上级目录不在服务范围内），所以关掉 open_basedir 是安全的。

---

## 3. 拉取代码

宝塔**终端**（或 SSH）执行：

```bash
cd /www/wwwroot/<站点>
git clone https://github.com/chydroid/comprehensive-test-with-fastapi-php.git tmpclone
mv tmpclone/* tmpclone/.[!.]* . 2>/dev/null; rmdir tmpclone
# 或者想把站点目录整个作为仓库：
# cd /www/wwwroot && git clone <仓库地址> <站点>
```

确认根目录结构：

```bash
ls /www/wwwroot/<站点>          # 应看到 app/ core/ config/ db/ public/ storage/ deploy/ docs/
ls /www/wwwroot/<站点>/public   # 应看到 index.php router.php assets/
```

---

## 4. 目录与权限

`storage/cache`、`storage/logs`、`public/uploads` 三个目录不在版本库里，必须手工创建并交给
PHP 运行用户（宝塔通常是 `www`）：

```bash
cd /www/wwwroot/<站点>
bash deploy/init_dirs.sh            # 默认 WEB_USER=www；若宝塔用的是其他用户：WEB_USER=nginx bash deploy/init_dirs.sh
```

脚本等价于：

```bash
mkdir -p storage/cache storage/logs public/uploads
chown -R www:www storage public/uploads
chmod -R ug+rwX storage public/uploads
```

---

## 5. 创建 `.env`

```bash
cd /www/wwwroot/<站点>
cp .env.example .env
vi .env
```

至少改这几项：

```ini
APP_DEBUG=false                 # 生产必须 false，否则异常会带堆栈外泄
DB_HOST=127.0.0.1
DB_NAME=comprehensive_test
DB_USER=<第 6 节建的应用账号>
DB_PASS=<对应密码>
SESSION_SECURE=true             # 配好 HTTPS 后再改，否则浏览器收不到会话 Cookie 会登不上
```

### ⚠️ 一个真实陷阱：不要把 `MAX_BODY_BYTES` 设成 2097152

`.env.example` 里 `MAX_BODY_BYTES=2097152`（2MB），而 `config/config.php` 的默认值是
`6291456`（6MB）。图片上传走 base64，2MB 的图实际请求体接近 **2.8MB**——
若照抄 2MB，**所有 2MB 图片上传都会被 413 拒绝**，而且报的是"请求体过大"，很难联想到是这里。

```ini
MAX_BODY_BYTES=6291456          # 或直接删掉这一行，回落 config.php 的 6MB 默认值
```

改完 `.env` 无需重启 PHP-FPM（每次请求读取），但改 **PHP 代码**后要重启（opcache）。

---

## 6. 数据库：建库 → 导结构 → 迁移数据

### 6.1 建库与账号（推荐用面板）

**面板 → 数据库 → 添加数据库**：

- 数据库名：`comprehensive_test`
- 用户名 / 密码：自定义（记下来填进 `.env`）
- 字符集：`utf8mb4`

面板会自动建库并授权。或用脚本：

```bash
bash deploy/db_init.sh --create --root-pass=<MySQL root 密码> \
  --db=comprehensive_test --user=<应用账号> --pass=<应用密码>
```

### 6.2 导入表结构（27 张表）

```bash
bash deploy/db_init.sh --db=comprehensive_test --user=<应用账号> --pass=<应用密码>
```

脚本做的事：`db/ci_bootstrap.sql` 去掉开头 `CREATE DATABASE` / `USE` 两行后导入，
结束时报告表数量。**结构应当是 27 张表**，不是 27 就说明导入没走完，看报错重来。

### 6.3 清空种子数据（迁移路径必做）

`ci_bootstrap.sql` 自带最小种子（1194 道题 + `admin`/`teacher1` 等测试账号）。
你要迁移真实数据，这些种子主键会和真实数据冲突，必须清掉：

```bash
bash deploy/db_init.sh --db=comprehensive_test --user=<应用账号> --pass=<应用密码> --empty
```

### 6.4 在源服务器导出真实数据

在**现有系统所在机器**上：

```bash
bash deploy/db_migrate.sh dump --host=127.0.0.1 --user=root --db=csip_exam --out=/root/exam_data.sql
# 日志表太大可以排除：
#   ... --ignore=admin_log
```

导出用 `--single-transaction`（不锁表，业务不停）+ `--no-create-info`（只要数据，结构已在 6.2 建好）。

### 6.5 传到新服务器并导入

```bash
scp /root/exam_data.sql root@<新服务器>:/root/

# 新服务器上：
bash deploy/db_migrate.sh import --db=comprehensive_test --user=<应用账号> --pass=<应用密码> --file=/root/exam_data.sql
```

### 6.6 行数校验（两边各跑一次，diff 应为空）

```bash
# 源服务器
bash deploy/db_migrate.sh verify --host=127.0.0.1 --user=root --db=csip_exam > /tmp/src.txt
# 新服务器
bash deploy/db_migrate.sh verify --db=comprehensive_test --user=<应用账号> --pass=<应用密码> > /tmp/dst.txt

diff /tmp/src.txt /tmp/dst.txt && echo "✅ 数据一致"
```

> 差异只应出现在**新项目新增而旧库没有**的表（如 `ai_config`、`wrong_book` 等）——
> 这些表在 `/tmp/src.txt` 里没有对应行，属于正常。

### 6.7 关于旧密码

不用担心：`app/Services/Password.php` 内置了**旧 md5 哈希兼容**，
旧系统 32 位 md5 密码可以直接登录，且验证成功时会自动升级为 bcrypt 落库。

---

## 7. 上线自检

### 7.1 环境自检脚本（先跑这个）

```bash
/www/server/php/83/bin/php deploy/precheck.php
```

它会逐项检查 PHP 版本、扩展、目录可写性、`.env`、数据库连通性、会话时区、
`post_max_size` 与 `MAX_BODY_BYTES` 的一致性、默认页残留、open_basedir，
并给出 PASS / WARN / FAIL。**有 FAIL 时退出码非 0**，先修完再往下走。

### 7.2 健康检查

```bash
curl -i https://<你的域名>/api/health
```

期望：`{"code":0,"data":{"status":"ok","db":true,...}}`。
`db:false` = 数据库没连上，回去查 `.env`。

### 7.3 功能走查

| 项 | 操作 | 期望 |
|---|---|---|
| 门户 | 打开 `/` | 有内容，无 404 |
| 管理入口 | 打开 `/manage` | 登录表单（门户不展示该入口，是隐藏直链） |
| 教师端 | `/teacher` | 登录表单 |
| 考场 | `/exam` | 登录表单 |
| 冒烟 | 建一场考试 → 入场 → 出题 → 开考 → 交卷 → 看成绩 | 全流程通过 |
| 资源 | F12 看 Console / Network | 无 404、无 500 |

---

## 8. HTTPS 与会话安全

1. **站点设置 → SSL → Let's Encrypt** 申请证书，开启「强制 HTTPS」
2. 确认全站 HTTPS 可用后，把 `.env` 里 `SESSION_SECURE=true`
   （改早了会导致浏览器不回传会话 Cookie，表现为「登录成功但一跳就掉」）

---

## 9. 日常运维

### 9.1 更新代码

```bash
cd /www/wwwroot/<站点>
git pull
# PHP 代码变了要重启 PHP-FPM 刷新 opcache（脚本/静态资源无需重启）
# 宝塔：软件商店 → PHP-8.x → 服务 → 重启；或 /etc/init.d/php-fpm-83 restart
```

> 不需要清任何缓存：路由表每次请求从 `config/routes.php` 构建，
> APCu 只缓存「路径→正则」的编译结果（默认 `APCU_ENABLED=false`，未启用）。

### 9.2 回滚

```bash
git log --oneline -5          # 找到要回到的版本
git checkout <commit>         # 或 git revert <commit>
# 重启 PHP-FPM；若涉及数据库结构变更，需同时还原对应 SQL
```

### 9.3 备份（宝塔计划任务）

- **数据库**：面板 → 计划任务 → 备份数据库 → 选 `comprehensive_test`，每天一次
- **文件**：备份 `/public/uploads`（上传的题目图片与课件，数据库里只有文件名）

等价命令行：

```bash
mysqldump --single-transaction --default-character-set=utf8mb4 comprehensive_test > /backup/exam_$(date +%F).sql
```

### 9.4 不需要配的东西

**本项目没有常驻定时任务**——考试状态流转（自动出题 / 自动开考 / 自动结束）
都是「有人访问时顺带触发」的惰性机制，靠 `Exam::autoGenerateIfDue()` /
`autoStartIfDue()` / `autoEndIfDue()` 实现。**不要**为了它去配 cron。

---

## 10. 故障排查速查表

| 症状 | 最可能的原因 | 查法 |
|---|---|---|
| 全站 500，日志有 `open_basedir restriction` | 宝塔防跨站开着 | 2.3：关掉或改 `.user.ini` |
| 门户显示 "Welcome to nginx!" | `public/index.html` 没删 | 2.1：`rm` 掉 |
| 首页正常，`/api/*`、`/manage` 404 | 伪静态没配 | 2.2：配 `try_files` |
| `Parse error: syntax error ... readonly` | PHP < 8.1 | 面板换 PHP 8.1+ |
| `/api/health` 返回 `db:false` | `.env` 凭据错 / 库没建 / 账号没授权 | 跑 `deploy/precheck.php` |
| 登录后一跳就掉线 | HTTPS 未生效但 `SESSION_SECURE=true` | 先置 false，配好 HTTPS 再改回 |
| 2MB 图片上传报 413 | `.env` 里 `MAX_BODY_BYTES=2097152` | 第 5 节：改成 6MB 或删该行 |
| 上传/写缓存报权限错误 | 目录属主不是 `www` | 跑 `deploy/init_dirs.sh` |
| 改了代码没生效 | opcache 未刷新 | 重启 PHP-FPM |
| 导入数据报 `Column count doesn't match` | 源表结构与本仓结构不一致 | 见下方说明 |

**关于最后一行的结构不一致**：本仓 `db/ci_bootstrap.sql` 是最新全量结构（27 表）。
若旧系统的表字段数/顺序与它不同，`INSERT` 会失败。定位：

```bash
bash deploy/db_migrate.sh verify --db=csip_exam > /tmp/src.txt   # 源库实际有哪些表
# 对报错的表，对比两边结构：
mysql -e "SHOW CREATE TABLE <表名>\G" csip_exam
mysql -e "SHOW CREATE TABLE <表名>\G" comprehensive_test
```

处理：只导结构一致的表（`--ignore=表名` 排除），不一致的表先人工对齐字段再导。

---

## 附录：配套脚本

| 脚本 | 用途 |
|---|---|
| `deploy/init_dirs.sh` | 创建可写目录 + 设属主权限（幂等） |
| `deploy/db_init.sh` | 建库建账号 / 导入 27 张表结构 / 清空种子数据 |
| `deploy/db_migrate.sh` | `dump`（源机导出）/ `import`（新机导入）/ `verify`（逐表行数） |
| `deploy/precheck.php` | 上线前环境自检，输出 PASS/WARN/FAIL |

所有脚本都支持 `--help`，且**不会写入任何凭据到仓库**（密码走参数或环境变量，实时读取）。
