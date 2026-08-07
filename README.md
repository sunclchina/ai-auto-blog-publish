# A-Blog · AI 全自动博客内容生产与发布系统

A-Blog 是一套「AI 全自动博客」系统：**调度 → AI 生成 → WordPress 发布** 三层解耦，无人值守日更。
由 WordPress 插件（发布端）+ Python 伴生服务（调度 + AI 生成）组成，支持 A 股复盘、IT 技术、国学诗词、书评、行业综述五个栏目。

> 插件 `ai-auto-blog-publish` v1.4.1（纯发布端：接收服务推送、查重、发布，零服务地址依赖）· 伴生服务 FastAPI（默认 127.0.0.1:8080，`server.host/port` 可调）

## 系统架构

```
┌────────────┐   ┌──────────────────────────┐   ┌──────────────────────┐
│  调度层     │ → │  AI 生成层（7 步流水线）   │ → │  发布层（WP 插件）     │
│ Crontab/   │   │ 选题→标题→大纲→正文→润色   │   │ REST 接收 + SimHash 查重│
│ FastAPI    │   │ →SEO→配图                 │   │ 自动建文/分类/标签/配图 │
└────────────┘   └──────────────────────────┘   └──────────────────────┘
```

- **调度层**（`backend/`）：内置常驻调度（也可用 Crontab 模板，08:00 生成任务/预选题 → 20:30 发布），FastAPI 后台 + 备用选题池；本地管理台 `http://127.0.0.1:8080/admin` 可人工干预今日计划与备用池
- **AI 生成层**（`backend/agents/`）：7 智能体流水线，DeepSeek 驱动，真实数据采集注入（新浪/腾讯/baostock + Tavily 联网搜索），禁止编造行情数字
- **发布层**（`wp-plugin/`）：纯接收端——接收成品文章，SimHash 指纹查重（与 Python 侧算法逐字节一致），自动建文、分类映射、打标、配图、定时发布；后台提供任务日志与 AI 工具箱（摘要/评论/话题）。**不配置、不探测伴生服务地址**，服务侧配置 WP 地址后主动推送

## 栏目与特色

| 栏目 | 内容 | 数据源 |
|---|---|---|
| 股市（A股复盘） | 7 模块专业复盘，标题=日期+A股市场：副标题（正文后取），按复盘日期查重、同日期覆盖重做 | 新浪实时 + baostock 历史 + Tavily 资讯 |
| IT技术 | WordPress/Nginx/服务器实操指南 | 问题池 + RSS |
| 国学 | 诗词原文赏析 | 本地语料库 |
| 书评 | 书籍核心书评 | 图书源 |
| 行业 | 热门行业/概念综述（市场规模/产业链/景气龙头） | Tavily 深挖（数据/公司/政策/动态） |

**AI 工具箱**（后台）：AI 摘要、AI 评论、热门话题（abp_topic 分类法），支持文章多选批量处理。

## 目录结构

```
A-Blog/
├── wp-plugin/          # WordPress 插件 ai-auto-blog-publish
├── backend/            # Python 伴生服务（FastAPI）
│   ├── agents/         # 7 智能体流水线（base/topic/title/outline/content/humanize/seo/image）
│   ├── collectors/     # 数据采集（market/industry/tech_topics/reading/books）
│   ├── prompts/        # 栏目写作规范（stock/industry/tech/reading）
│   ├── scheduler/      # 每日队列、备用池、WP 设置同步、交易日历
│   ├── publishers/     # WP REST / XML-RPC 发布
│   ├── core/           # 数据库、指纹、搜索（Tavily）、风控
│   └── config.yaml     # 配置文件（密钥走环境变量/secret 文件，不入库）
├── docs/               # 架构与模块文档（01-06）
├── deploy/             # 部署套件与说明
├── tests/              # 测试工具（token 生成、端到端、指纹对拍）
└── dist/               # 打包安装文件（zip）
```

## 快速开始

### 1. 安装 WordPress 插件

将 `dist/ai-auto-blog-publish-v1.1.0.zip` 上传到后台「插件 → 安装插件 → 上传」，激活后在「AI 自动博客」页面：
- 确认栏目开关、每日篇数、Token 额度、发布时段
- 生成 API Token 并复制
- 确认「模型探测结果」已探测到可用模型（优先复用青简主题 `qy_ai_api_key`）

### 2. 启动 Python 伴生服务

```bash
cd backend
pip install -r requirements.txt
# 配置 config.yaml：WP 站点地址、API Token（data/wp_token.txt）、模型/Tavily Key（环境变量或 secret 文件）
python -m uvicorn main:app --host 127.0.0.1 --port 8080
```

### 3. 配置定时调度

```bash
# 两阶段（示例 crontab，见 backend/crontab.txt）
08:00  python main.py --generate --topics   # 生成当日任务 + 预选题
20:00  python main.py --column stock --run  # 交易日晚复盘
20:30  python main.py --run                 # 其余栏目发布
```

Linux 部署详见 `deploy/README.md`。

## 复盘规则（翁老定制）

- 标题 = 当日日期 +「A股市场」+「：」+ 副标题；副标题**正文后取**，无备选题目
- 查重只查**标题日期**（不查内容）；同日期已存在 = 对结果不满意 → 自动删旧文覆盖重做
- 行情数据**联网获取**（新浪主源），本地通达信仅「同时间」校对；历史复盘用 baostock 真实历史数据
- 数据精准、禁止编造；关键数据加粗、标注来源；文末「不构成投资建议」

## 文档

- `docs/01-architecture.md` 总体架构
- `docs/05-plugin.md` 插件规范（SimHash 指纹 S1-S7 权威定义）
- `deploy/README.md` Linux 部署

## 安全

- API Token、模型 Key、Tavily Key 仅存 WP option / secret 文件 / 环境变量，**不入日志、不入库**（见 `.gitignore`）
- 前端调用一律走 `?rest_route=` 形式（兼容 IIS 无 URL Rewrite）


## 支持与赞赏

如果 A-Blog 对你有帮助，欢迎赞赏支持：

<img src="assets/赞赏码.jpg" alt="赞赏码" width="200" />

## 许可

GPLv2 or later（插件部分）。


GPLv2 or later（插件部分）。
