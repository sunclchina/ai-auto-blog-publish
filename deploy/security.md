# A-Blog 部署安全说明

> 适用范围: Linux 生产服务器上的 Python 伴生服务 + WP 插件
> 核心原则(架构第 6 节 + 开发原则 2/7):
> **密钥不入库、不入日志、不进 crontab 命令行; 服务仅监听 127.0.0.1:8080; 低权限运行。**

---

## 1. 威胁模型(简要)

本系统对外仅暴露 WordPress(443), Python 服务绑定回环不对外。主要风险:

1. 服务器被入侵 → 窃取 `.env` 中的模型 Key / WP Token（可消耗额度、发垃圾文）
2. 误操作把密钥提交到 git / 贴到聊天 / 写进日志
3. 防火墙误开放 8080 → 任何人可调 `/api/run-task` 消耗 Token
4. `data/ablog.db` 丢失 → 指纹库/额度账本丢失

本文件逐项给出对策。

---

## 2. 密钥管理

### 2.1 密钥清单与注入方式

| 环境变量 | 对应密钥 | 必填 | 来源 |
|---|---|---|---|
| `ABP_DEEPSEEK_KEY` | DeepSeek API Key | provider=deepseek 时必填 | DeepSeek 开放平台 |
| `ABP_WP_TOKEN` | WP 插件 API Token | 发布必填 | 插件设置页生成 |
| `ABP_VOLC_KEY` | 豆包 API Key | 可选 | 火山引擎 |
| `ABP_QWEN_KEY` | 通义 API Key | 可选 | 阿里云百炼 |
| `ABP_IMAGE_KEY` | 生图服务 Key | 可选 | 生图服务商 |

注入链路（唯一通道）:

```
backend/.env (chmod 600, 属主 ablog)
   └─ systemd EnvironmentFile=/opt/ablog/backend/.env
        └─ uvicorn 进程环境 → config.py 读取 ABP_* 变量
```

### 2.2 硬性禁止

- ❌ 把真实密钥写进 `config.yaml`（模板里 secrets 字段必须留空）
- ❌ 把密钥写进任何 `.py` 源码 / `tests/`（开发原则 2: 无硬编码）
- ❌ 在 crontab 命令行内联密钥（`ps` 可看到, 且 cron 日志会记录）
- ❌ 把密钥打进日志（logger 需对 `ABP_*`/`api_key`/`token` 字段脱敏, 后端实现约定）
- ❌ 把 `.env`、`config.yaml` 提交 git（`.gitignore` 应包含 `backend/.env`、`backend/config.yaml`、`data/`）

### 2.3 初始化与轮换

```bash
# 初始化(install.sh 已自动完成): 
sudo chmod 600 /opt/ablog/backend/.env /opt/ablog/backend/config.yaml
sudo chown ablog:ablog /opt/ablog/backend/.env

# 轮换/更新密钥: 编辑 .env → 重启
sudo nano /opt/ablog/backend/.env
sudo systemctl restart ablog
```

### 2.4 缺失即拒绝

`config.py` 对必填密钥（按 `models.provider` 决定）缺失时直接报错退出，**不静默降级**——宁可服务不启动，也不带着空 Key 跑任务。对应架构 3.4：插件侧 `no_model_configured` 时任务在 Python 层拦截，不消耗 Token。

---

## 3. 网络与防火墙

### 3.1 绑定约束

- 默认 `--host 127.0.0.1 --port 8080`（`config.yaml` server 节 / `ablog.service`）；远程访问仅限内网地址或 SSH 隧道，**严禁**改为 `0.0.0.0`——一旦绑定全网卡，8080 即对公网开放。

### 3.2 防火墙兜底（ufw 示例）

```bash
sudo ufw default deny incoming
sudo ufw allow 443/tcp            # 仅放行 WordPress
sudo ufw deny 8080/tcp            # 显式拒绝 8080(防误改绑定后的裸奔)
sudo ufw status                   # 确认 8080 状态为 DENY
sudo ufw enable
```

> 即使误把 host 改成 0.0.0.0，防火墙也会拦截 8080。双保险。

### 3.3 回环调用

插件为纯接收端（v1.4.1 起），不主动连接服务、无服务地址配置。服务侧默认绑定回环 `127.0.0.1:8080`；若服务部署在远程主机，应经 SSH 隧道或内网 VLAN 暴露给 WP，并配合插件 Token 鉴权与 HTTPS，严禁直接开公网端口。

---

## 4. 文件权限清单

| 路径 | 属主 | 权限 | 说明 |
|---|---|---|---|
| `/opt/ablog/backend/.env` | ablog | 600 | 密钥文件, 仅属主可读 |
| `/opt/ablog/backend/config.yaml` | ablog | 600 | 配置(含 WP 地址等) |
| `/opt/ablog/data/` | ablog | 700 | 数据库/日志/图片, 仅服务用户可进 |
| `/opt/ablog/backup/` | ablog | 700 | 备份目录 |
| `/opt/ablog/backend/venv/` | ablog | 755 | 虚拟环境 |
| `wp-config.php`(WP 侧) | www-data | 600 | WP 自带, 勿动 |

install.sh 已自动设置; 复查命令:

```bash
ls -l /opt/ablog/backend/.env /opt/ablog/backend/config.yaml
ls -ld /opt/ablog/data /opt/ablog/backup
```

---

## 5. 备份建议

### 5.1 `data/ablog.db` 每日备份（推荐直接启用）

crontab 追加一行（`%` 在 crontab 中必须转义为 `\%`）:

```
# 每日 03:00 备份数据库, 保留 30 天
0 3 * * *  cp /opt/ablog/data/ablog.db /opt/ablog/backup/ablog-$(date +\%F).db && find /opt/ablog/backup -name 'ablog-*.db' -mtime +30 -delete >> /opt/ablog/data/logs/cron-backup.log 2>&1
```

（此行使 `backend/crontab.txt` 第 4 段已注释版本, 去掉行首 `#` 即可。）

### 5.2 一致性说明

- 个人服务器零压力场景, 直接用 `cp` 足够; SQLite 非写入高峰时复制一致性可接受。
- 更稳妥（可选, 需装 `sqlite3`）:
  ```
  0 3 * * *  sqlite3 /opt/ablog/data/ablog.db ".backup '/opt/ablog/backup/ablog-$(date +\%F).db'" && find /opt/ablog/backup -name 'ablog-*.db' -mtime +30 -delete
  ```

### 5.3 备份内容建议

| 优先级 | 内容 | 频率 |
|---|---|---|
| 高 | `data/ablog.db`（任务/指纹/书目/额度账本） | 每日 |
| 中 | `backend/config.yaml` + `backend/.env`（密钥建议加密后另存, 如 `gpg -c`） | 每次改动 |
| 低 | 整站 WP 数据库（WP 侧自行处理） | 每日 |

恢复演练: `cp backup/ablog-2026-08-03.db data/ablog.db && systemctl restart ablog`

---

## 6. 最小权限与加固

- **专用系统用户**: `ablog`（install.sh 创建, `nologin` 不可登录）, 服务绝不以 root 运行。
- **systemd 沙箱**（`ablog.service` 已启用）: `NoNewPrivileges` / `ProtectSystem=full` / `ProtectHome` / `PrivateTmp`，仅 `data/` 可写。
- **SSH 加固**（服务器通用）: 禁 root 密码登录、仅 key 登录、fail2ban。
- **系统更新**: 每月 `apt update && apt upgrade`（模型 Key 值钱, 系统别裸奔）。
- **密钥泄漏响应**: 立即到 DeepSeek/插件设置页作废旧 Key → 换新 → 改 `.env` → `systemctl restart ablog` → 检查 `quota_daily` 有无异常消耗。

---

## 7. 上线前自检清单

- [ ] `curl http://127.0.0.1:8080/healthz` 正常, 且外网 IP 访问 8080 不通
- [ ] `sudo ufw status` 中 8080 为 DENY
- [ ] `.env` / `config.yaml` 权限 600, 属主 ablog
- [ ] `grep -r "sk-" /opt/ablog/backend --include=*.py` 无真实密钥（源码无硬编码）
- [ ] `git status` 无 `.env` / `config.yaml` / `data/` 被跟踪
- [ ] 备份 crontab 行已生效（`crontab -l` 可见）
- [ ] `systemctl status ablog` 以 User=ablog 运行
- [ ] dry-run 全链路通过（`curl -X POST .../api/run-task -d '{"dry_run":true}'`）
