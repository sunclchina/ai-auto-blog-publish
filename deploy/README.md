# A-Blog 部署手册

> Python 伴生服务 (FastAPI, 127.0.0.1:8080) + WordPress 插件 (ai-auto-blog-publish)
> 目标环境: Linux 个人服务器 + Crontab 调度 (零压力场景)
> 开发环境: Windows (本机) — 本文第 3 节
> 配套文档: `docs/01-architecture.md`(总纲) · `deploy/security.md`(安全) · `backend/crontab.txt`(调度模板)

---

## 0. 拓扑与端口约定

```
Crontab (Asia/Shanghai)
   │  08:00 队列+预选题 / 20:00 复盘 / 20:30 执行发布 / (可选)5min 健康检查
   ▼
Python 伴生服务  FastAPI 127.0.0.1:8080   ◀── 仅回环, 不对外
   │  POST /wp-json/ai-auto-blog/v1/articles (Bearer Token)
   ▼
WordPress (https://sunclnas.cn)  ← 插件 ai-auto-blog-publish (纯接收端, 不依赖服务地址)
```

- **默认约定**：Python 服务默认监听 `127.0.0.1:8080`（`config.yaml` 的 `server.host/port` 可调整）；禁止绑定 0.0.0.0 暴露公网。
- **插件为纯接收端（v1.4.1 起）**：不配置、不探测任何服务地址，后台无健康横幅；服务侧在 `config.yaml` 配置 WP 地址并主动推送（`wordpress.base_url` + Token）。插件可在任意 WordPress 环境安装即用，与服务是否同机无关。

---

## 1. 目录结构（部署后）

```
/opt/ablog/
├── backend/
│   ├── main.py  config.py  config.yaml  requirements.txt
│   ├── scheduler/  agents/  collectors/  publishers/  core/  prompts/
│   ├── crontab.txt              # 调度模板(已就位)
│   ├── venv/                    # Python 虚拟环境(install.sh 创建)
│   └── .env                     # 密钥文件(chmod 600, systemd 注入)
├── data/
│   ├── ablog.db                 # SQLite 主库(任务/指纹/书目/额度)
│   ├── logs/                    # 结构化日志 + cron 输出
│   ├── images/                  # 图片缓存
│   └── sensitive_words.txt      # 敏感词表(可编辑)
├── backup/                      # ablog.db 每日备份(chmod 700)
└── deploy/
    ├── install.sh  ablog.service  .env.example  README.md  security.md
```

---

## 2. 前置条件

| 项 | 要求 |
|---|---|
| 服务器 | Linux (Debian/Ubuntu 示例, CentOS 命令略有差异) |
| Python | 3.10+ (`python3 --version`) |
| 工具 | curl、rsync/scp（上传代码用） |
| 时区 | Asia/Shanghai（必须, 否则定时全错） |
| WordPress | 已安装插件 ai-auto-blog-publish（联调见第 5 节） |

---

## 3. Windows 开发环境（本机）

> 目标: 本地能起服务、能跑测试、能调接口，开发阶段用 `draft` 状态发到测试站。

### 3.1 准备虚拟环境

```powershell
cd E:\my-project\A-Blog\backend
python -m venv .venv
.\.venv\Scripts\Activate.ps1          # 激活(如被策略拦截: Set-ExecutionPolicy -Scope Process Bypass)
pip install -r requirements.txt
```

### 3.2 配置

```powershell
Copy-Item config.yaml.example config.yaml
# 编辑 config.yaml: 栏目开关 / 额度 / WP base_url / publish_status: "draft"
```

密钥用环境变量注入（临时会话, 关终端即失效; 长期用 `setx` 但注意勿提交）：

```powershell
$env:ABP_DEEPSEEK_KEY = "sk-xxxxxxxx"
$env:ABP_WP_TOKEN     = "xxxxx"      # 插件设置页生成, 见第 5 节
```

### 3.3 启动与验证

```powershell
uvicorn main:app --host 127.0.0.1 --port 8080 --reload
```

另开终端验证（注意 PowerShell 里用 `curl.exe` 才是真 curl）：

```powershell
curl.exe http://127.0.0.1:8080/healthz
# 期望: {"status":"ok",...}

# dry-run 验证(不消耗真实 Token 的全链路演练):
curl.exe -X POST http://127.0.0.1:8080/api/run-task -H "Content-Type: application/json" -d "{\"column\":\"stock\",\"dry_run\":true}"
# 期望: {"ok":true,"dry_run":true,...}  (不会真正调模型/发布)
```

### 3.4 跑测试

```powershell
pytest ..\tests -v        # 核心单测: 日历/指纹/风控/配置/agent mock
```

---

## 4. Linux 生产部署

### 4.1 上传代码到服务器

本机（Windows）用 scp，或 WSL 里用 rsync：

```bash
# 示例: 把整个项目推送到服务器 /opt/ablog (排除 venv/data 等本地产物)
rsync -av --exclude 'venv' --exclude '.venv' --exclude 'data' \
  /mnt/e/my-project/A-Blog/ user@your-server:/opt/ablog/
```

### 4.2 一键安装

```bash
cd /opt/ablog
sudo bash deploy/install.sh /opt/ablog
```

脚本完成: 系统用户 `ablog`(nologin) · venv · 依赖安装 · `data/` 初始化 · `config.yaml`/`.env` 生成(不覆盖已有) · chmod 600 密钥文件 · 安装 systemd 单元。

### 4.3 填写配置与密钥

```bash
sudo nano /opt/ablog/backend/config.yaml   # 栏目开关/篇数/额度/时段/模型映射/WP 地址
sudo nano /opt/ablog/backend/.env          # ABP_DEEPSEEK_KEY / ABP_WP_TOKEN / ...
```

### 4.4 启动服务

```bash
sudo systemctl enable --now ablog
systemctl status ablog                     # active (running)
```

### 4.5 验证

```bash
curl http://127.0.0.1:8080/healthz                             # 服务健康
curl -X POST http://127.0.0.1:8080/api/run-task \
     -H 'Content-Type: application/json' \
     -d '{"column":"stock","dry_run":true}'                    # dry-run 全链路
tail -f /opt/ablog/data/logs/ablog.log                         # 实时日志
```

> 注意: 首次真实运行前请确认第 5 节联调通过；`publish_status` 保持 `draft` 观察几篇再改 `publish`。

---

## 5. 与 WordPress 插件联调

1. **装插件**: 将 `wp-plugin/` 上传到 WP 的 `wp-content/plugins/ai-auto-blog-publish/`，后台「插件」启用。
2. **配模型**: 后台「AI 自动博客」设置页配置 DeepSeek key（或复用青简主题的 `qy_ai_*` 配置，插件自动探测: 主题 → 其他插件 → 插件自身）。
3. **生成 Token**: 设置页点击生成 `ABP_API_TOKEN`，填入服务器 `backend/.env` 的 `ABP_WP_TOKEN`。
4. **验证插件健康**:
   ```bash
   curl https://sunclnas.cn/wp-json/ai-auto-blog/v1/health
   # 期望: {"ok":true,"version":"1.0.0","models":{...}}
   ```
5. **Python 侧对齐**: `config.yaml` 的 `wordpress.base_url` 指向站点，`publish_status: "draft"`。
6. **dry-run 演练**（第 4.5 节命令）→ 确认队列、额度、栏目轮换逻辑通过，不消耗 Token。
7. **真实试发一篇**:
   ```bash
   curl -X POST http://127.0.0.1:8080/api/run-task \
        -H 'Content-Type: application/json' \
        -d '{"column":"tech"}'
   ```
   到 WP 后台检查: 文章(草稿) + 特色图(WebP) + 分类 + 标签。
8. **查重验证**: 再次提交同一题目，应被指纹库/插件 `check` 端点拦截（`duplicate: true`）。
9. 确认无误后把 `publish_status` 改为 `publish`。

---

## 6. Crontab 安装

```bash
crontab -e
```

粘贴 `backend/crontab.txt` 的完整内容（或直接 `cat backend/crontab.txt >> 你的 crontab`）。核心三行（--generate --topics 两阶段调度: 先出候选列表→人工干预窗口→到点自动继续）:

| 时间 | 任务 | 说明 |
|---|---|---|
| `0 20 * * *` | A股复盘 | 仅交易日（Python 侧日历二次校验，节假日自动跳过） |
| `0 8 * * *` | 当日队列生成+执行 | IT/读书与国学，随机时段发布 |
| `*/5 * * * *` | /healthz 探测 | 可选（默认注释，systemd 已负责拉起） |

校验与运维:

```bash
crontab -l                                  # 查看已安装
grep CRON /var/log/syslog | tail -20        # 确认触发(日志位置因发行版而异)
date                                        # 确认服务器时区 = CST (Asia/Shanghai)
sudo timedatectl set-timezone Asia/Shanghai # 时区不对时执行
```

> 若改用 `/etc/cron.d/ablog`：每条命令前加用户字段（如 `ablog`），文件属主必须是 root。

---

## 7. 日志查看

| 位置 | 内容 |
|---|---|
| `journalctl -u ablog -f` | systemd 服务标准输出/错误（uvicorn 启动与异常） |
| `/opt/ablog/data/logs/ablog.log` | 业务结构化日志（任务状态/Token 记账/错误） |
| `/opt/ablog/data/logs/cron-stock.log` | 每日 20:00 复盘任务输出 |
| `/opt/ablog/data/logs/cron-daily.log` | 每日 08:00 队列任务输出 |
| `/opt/ablog/data/logs/cron-health.log` | 健康检查告警（启用后） |

```bash
tail -f /opt/ablog/data/logs/ablog.log
journalctl -u ablog -n 100 --no-pager
```

---

## 8. 故障排查表

| 现象 | 可能原因 | 处理 |
|---|---|---|
| `curl /healthz` 连接拒绝 | uvicorn 未运行 / 端口被占 | `systemctl status ablog`; `lsof -i:8080`; 查 journalctl |
| systemd 反复重启(StartLimitBurst) | 配置错误启动即崩 | `journalctl -u ablog -n 50`; 检查 `config.yaml` 语法(`python -c "import yaml;yaml.safe_load(open('config.yaml'))"`) |
| 服务起了但 /healthz 404 | 启动目录/模块不对 | 确认 `WorkingDirectory=backend`、`main:app` 存在 |
| 任务到点不执行 | cron 时区错误 / 行被注释 / MAILTO 空 | `date` 看时区; `crontab -l`; `grep CRON /var/log/syslog` |
| 复盘日没发文 | 非交易日(节假日) / 数据源失败 / 栏目开关关 | 看 `cron-stock.log` + `ablog.log` 的 skip 原因 |
| 报 `no_model_configured` | 插件侧模型探测失败或 key 缺失 | 插件设置页检查; `curl .../v1/health` 看 models |
| WP 发布 401 | `ABP_WP_TOKEN` 错误/插件未启用 | 重新生成 Token; `wp plugin list` |
| 当日 Token 额度超限 | 200k 默认额度用尽 | 看 `quota_daily` 表; 次日自动恢复或调大 `quota.daily_token_quota` |
| 指纹查重拦截 | 选题与历史文章重复 | 属正常防护; 换选题或在 `data/ablog.db` blacklist 表加黑名单 |
| 日志无输出 | data/logs 权限不足 | `ls -l /opt/ablog/data`; 确认属主 `ablog` |
| 图片未上传 | 未配生图 key / `featured_image: false` | 未配置时纯文字发布为预期行为 |

---

## 9. 验证命令速查

```bash
# 健康
curl -s http://127.0.0.1:8080/healthz

# 任务 dry-run（不消耗 Token）
curl -s -X POST http://127.0.0.1:8080/api/run-task \
     -H 'Content-Type: application/json' \
     -d '{"column":"stock","dry_run":true}'

# 插件健康
curl -s https://sunclnas.cn/wp-json/ai-auto-blog/v1/health

# 服务状态
systemctl status ablog
journalctl -u ablog -n 50 --no-pager
```

---

## 10. 变更与回滚

- 改配置: 编辑 `config.yaml` / `.env` → `sudo systemctl restart ablog`
- 回滚版本: 备份 `data/ablog.db`（见 security.md）后替换代码目录，重启服务
- 停用整套自动发文: `sudo systemctl disable --now ablog` + `crontab -r`（或注释调度行）
