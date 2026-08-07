# A-Blog Python 伴生服务（backend）

A-Blog 是「WP 插件 + Python 伴生服务」双组件架构。**本目录是伴生服务**：负责数据采集（行情/新闻）、AI 生成正文（DeepSeek）、批量调度；WP 插件（上级目录）负责收稿、查重、建文、发布与后台界面。

插件 ZIP 为全家桶：上传安装插件后，本目录随插件一同就位。**安装 = 插件 + 本服务**，两步完成。

---

## 一、Windows 安装

前置：已装 [Python 3.10+](https://www.python.org/downloads/)（勾选 *Add to PATH*）。

```bat
:: 1. 安装依赖 + 生成配置（首次）
install-backend.bat

:: 2. 启动服务（127.0.0.1:8080）
start-backend.bat
```

验证：浏览器打开 http://127.0.0.1:8080/healthz 应返回 `{"ok":true,...}`。
管理台：浏览器打开 http://127.0.0.1:8080/admin —— 今日计划任务与备用选题池的本地操作界面（仅回环可访问）。

## 二、Linux 安装（部署机）

```bash
sudo bash install.sh            # 建 venv + 装依赖 + 生成配置 + 注册 systemd
sudo systemctl start ablog      # 启动
curl http://127.0.0.1:8080/healthz
```

## 三、密钥配置（不随包分发，安装后填写）

编辑 `config.yaml`（首次由 install 脚本从 `config.yaml.example` 生成）：

| 项 | 环境变量 | 说明 |
|----|----------|------|
| 模型 API Key | `ABP_MODEL_API_KEY` | DeepSeek Key（AI 生成必需） |
| WP Token | `ABP_API_TOKEN` | 与插件后台 Token 一致（发布必需） |
| Tavily Key | `TAVILY_API_KEY` | 联网搜索素材（可选） |

密钥也可写入 `data/` 下的 `*.txt` 文件或环境变量，见 `config.yaml` 注释。

## 四、调度（三选一）

1. **内置调度（推荐，v1.4+）**：后端常驻时自动建队列/生成/发布，无需外部定时器。
2. **crontab（Linux）**：见 `crontab.txt` 模板。
3. **手动**：WP 后台「今日计划任务」操作，或命令行：
   ```bash
   python -m scheduler.daily_queue --build-today   # 建当日队列
   python -m scheduler.daily_queue --run           # 生成正文（每轮 1 篇，可重复执行）
   python -m scheduler.daily_queue --publish-due   # 发布到期任务
   ```

## 五、数据目录（data/）

安装后生成：`ablog.db`（任务/选题/指纹库）、`wp_token.txt`、`tavily_key.txt`、`sensitive_words.txt`、`logs/`。**勿提交到版本库**。

## 六、升级

插件 ZIP 为全家桶——WP 后台更新插件时，本目录随插件一起升级，无需单独操作。
