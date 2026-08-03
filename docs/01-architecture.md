# A-Blog 系统设计文档 · 总纲

> 项目：AI全自动博客内容生产与发布【ai-auto-blog-publish】
> 版本：v1.0（2026-08-03）
> 本文件是唯一权威设计依据。各模块实现必须与本文件一致，冲突时以本文件为准并记录变更。

---

## 1. 系统总览

独立 WordPress 自定义插件 + Python 伴生服务。无人值守全自动日更，适配青简主题。

### 1.1 三层架构

```
┌─────────────────────────────────────────────────────────┐
│  调度层 (Python + Crontab)                               │
│  scheduler/ ── 每日任务队列、栏目轮换、交易日历、配额风控  │
│  collectors/ ── 大盘数据 / IT热点 / 国学素材 / 书目库     │
└────────────────────────┬────────────────────────────────┘
                         │ 任务对象（JSON）
┌────────────────────────▼────────────────────────────────┐
│  AI生成层 (Python 多智能体流水线)                        │
│  agents/ 选题→标题→大纲→正文→去AI润色→SEO优化→配图       │
│  模型分发：DeepSeek / 豆包 / 通义 / 生图接口              │
└────────────────────────┬────────────────────────────────┘
                         │ 成品文章（JSON + 图片WebP）
┌────────────────────────▼────────────────────────────────┐
│  WP发布层 (WordPress 插件 ai-auto-blog-publish)          │
│  REST API 接收 → 查重 → 传图 → 建文 → 分类标签 → 发布     │
│  模型配置探测：主题 qy_ai_* → 其他插件 → 插件自身配置      │
└─────────────────────────────────────────────────────────┘
```

### 1.2 数据流（7步闭环）

1. **选题**：collectors 采集素材 → 前置查重/风控/配额 → 候选列表（可人工改）
2. **标题**：一次生成 3-5 条 SEO 标题 → 按长度/关键词/重复度自动择优
3. **大纲**：强制结构化大纲（分栏目模板）
4. **正文**：分栏目专业填充（技术文代码/复盘文数据/国学文原文释义/书评五段式）
5. **去AI润色**：句式重组、节奏变化、个人视角注入
6. **SEO优化**：Meta 描述、关键词密度、长尾词、站内分类标签、内链
7. **配图发布**：1280×720 封面 → WebP → 上传 WP 媒体库 → 绑特色图 → 草稿/发布

---

## 2. 目录结构（开发原则5：路径规范）

```
E:\my-project\A-Blog\
├── backend\                 # Python 伴生服务（FastAPI, 127.0.0.1:8080）
│   ├── main.py              # FastAPI 入口 + 健康检查 /healthz
│   ├── config.py            # 配置加载（config.yaml + 环境变量 + WP 同步）
│   ├── config.yaml          # 默认配置（不含密钥）
│   ├── requirements.txt
│   ├── scheduler\
│   │   ├── daily_queue.py   # 每日任务队列、栏目轮换、时段模拟
│   │   ├── calendar.py      # A股交易日历（含节假日跳过）
│   │   └── crontab.txt      # Linux Crontab 模板
│   ├── agents\
│   │   ├── base.py          # 智能体基类：模型调用/重试/熔断/记账
│   │   ├── topic.py         # Step1 选题（分栏目差异化）
│   │   ├── title.py         # Step2 SEO 标题（3-5选1）
│   │   ├── outline.py       # Step3 大纲
│   │   ├── content.py       # Step4 正文填充
│   │   ├── humanize.py      # Step5 去AI润色
│   │   ├── seo.py           # Step6 SEO 优化
│   │   └── image.py         # Step7 配图（DALL·E3/开源接口，可关）
│   ├── publishers\
│   │   ├── wp_rest.py       # WP REST API 发布（主）
│   │   ├── wp_xmlrpc.py     # XML-RPC 兜底
│   │   └── image.py         # 图片处理：构图/压缩/WebP 转换
│   ├── collectors\
│   │   ├── market.py        # A股大盘/板块/资金/情绪（通达信本地+新浪+东财）
│   │   ├── tech_topics.py   # IT 技术热点/高频问题
│   │   ├── reading.py       # 国学素材（chinese-poetry 语料库）
│   │   └── books.py         # 站点书目库抓取 https://sunclnas.cn/藏书阁书目
│   ├── core\
│   │   ├── db.py            # SQLite（data/ablog.db）
│   │   ├── fingerprint.py   # 文章指纹库（SimHash + 归一化）
│   │   ├── risk.py          # 敏感词/黑名单/每日额度/熔断
│   │   ├── seo.py           # 关键词密度/长尾词/Meta 生成
│   │   └── logger.py        # 结构化日志（data/logs/）
│   └── prompts\
│       ├── stock.md         # A股复盘固定 Prompt 规范
│       ├── tech.md          # IT 技术笔记固定 Prompt 规范
│       └── reading.md       # 读书国学固定 Prompt 规范
├── wp-plugin\               # WordPress 插件本体（同名目录 ai-auto-blog-publish）
│   ├── ai-auto-blog-publish.php   # 主文件（插件头 + 常量 + 加载）
│   ├── readme.txt
│   ├── includes\
│   │   ├── class-abp-settings.php    # 设置页（导航菜单「AI 自动博客」）
│   │   ├── class-abp-rest.php        # REST 端点 /wp-json/ai-auto-blog/v1/*
│   │   ├── class-abp-publish.php     # 建文/传图/分类/标签/特色图
│   │   ├── class-abp-models.php      # 模型配置探测（主题→插件→自身）
│   │   ├── class-abp-fingerprint.php # 指纹查重（与 Python 侧同算法）
│   │   └── class-abp-log.php         # 任务日志表 + 后台查看
│   └── assets\
│       ├── js\admin.js
│       └── css\admin.css
├── docs\                    # 设计文档（本文件 + 分模块文档）
├── tests\                   # pytest + WP 插件冒烟脚本
└── data\                    # SQLite + 日志 + 图片缓存（gitignore）
```

---

## 3. 接口契约（并行开发关键）

### 3.1 Python 内部：任务对象（JSON Schema 核心字段）

```json
{
  "task_id": "20260803-stock-001",
  "column": "stock|tech|reading|book",
  "topic": "题目/选题描述",
  "final_title": "发布标题",
  "content_html": "正文 HTML（青简主题兼容）",
  "excerpt": "摘要",
  "meta_description": "Meta 描述",
  "tags": ["标签1", "标签2"],
  "category": "A股每日复盘|IT技术笔记|读书与国学",
  "featured_image": "base64 或本地路径或URL",
  "status": "draft|publish|future",
  "publish_date": "ISO8601（定时发布用）",
  "source": {"model": "deepseek-chat", "prompt_version": "v1.0"}
}
```

### 3.2 Python ↔ WP 插件 REST 契约（核心！）

- 端点：`POST /wp-json/ai-auto-blog/v1/articles`
- 认证：`Authorization: Bearer <ABP_API_TOKEN>`（插件设置页生成，WP option 存储）
- 请求体：见 3.1 任务对象 JSON
- 响应：`{"ok": true, "post_id": 123, "permalink": "..."}` 或 `{"ok": false, "error": "..."}`
- 附加端点：
  - `GET /wp-json/ai-auto-blog/v1/health` → `{"ok": true, "version": "1.0.0", "models": {...}}`
  - `GET /wp-json/ai-auto-blog/v1/categories` → 站点分类列表（供 Python 匹配）
  - `POST /wp-json/ai-auto-blog/v1/check` → 指纹查重 `{"fingerprint": "...", "duplicate": bool}`
  - `GET /wp-json/ai-auto-blog/v1/written-books` → 已写书目清单（读书栏目防重复）

### 3.3 SQLite Schema（data/ablog.db，Python 侧）

```sql
CREATE TABLE tasks (
  task_id TEXT PRIMARY KEY,
  column_name TEXT NOT NULL,        -- stock/tech/reading/book
  topic TEXT, title TEXT, outline TEXT, content TEXT, excerpt TEXT,
  status TEXT DEFAULT 'queued',     -- queued|generating|humanize|ready|published|failed|skipped
  model TEXT, tokens_used INTEGER DEFAULT 0,
  error TEXT, created_at TEXT, updated_at TEXT, published_at TEXT
);
CREATE TABLE fingerprints (
  fhash TEXT PRIMARY KEY,           -- SimHash 64bit hex
  task_id TEXT, title TEXT, column_name TEXT, created_at TEXT
);
CREATE TABLE written_books (
  book_title TEXT PRIMARY KEY,      -- 书目防重复（读书栏目）
  task_id TEXT, created_at TEXT
);
CREATE TABLE quota_daily (
  day TEXT PRIMARY KEY,             -- YYYY-MM-DD
  tokens_used INTEGER DEFAULT 0,
  articles_published INTEGER DEFAULT 0
);
CREATE TABLE blacklist (
  word TEXT PRIMARY KEY, kind TEXT  -- keyword|topic 黑名单
);
```

### 3.4 模型配置契约（插件 class-abp-models.php 返回）

```json
{
  "provider": "theme|plugin|self",
  "source": "qingya",               // 来源标识
  "deepseek_api_key": "sk-...",
  "models": {
    "stock": "deepseek-chat",
    "tech": "deepseek-chat",
    "reading": "deepseek-chat",
    "image": ""
  },
  "image_api": {"provider": "", "key": "", "endpoint": "", "model": ""}
}
```

探测顺序（硬性规定）：
1. 青简主题：`get_option('qy_ai_api_key')` + `get_theme_mod('qy_ai_model')` 非空 → provider=theme
2. 其他已知插件（可配置探测表，默认空）
3. 插件自身 `abp_settings` option → provider=self
4. 都没有 → API 返回 `{"ok": false, "error": "no_model_configured"}`，Python 层任务拦截不消耗 Token

DeepSeek 单 key 多模型：同一 key，按栏目传不同 `model` 字段（deepseek-chat / deepseek-coder / deepseek-reasoner，均可配置）。

---

## 4. 调度规则

| 栏目 | 调度 | 说明 |
|------|------|------|
| A股复盘 | 仅交易日 20:00 | 交易日历（春节/国庆等法定节假日+周末跳过），数据源失败则该日跳过 |
| IT技术笔记 | 每周 1-3 篇，随机时段 09:00-21:00 | 选题来源：热点+问题库 |
| 读书与国学 | 每周 1-3 篇，随机时段 | 国学选题优先唐诗三百首/宋词三百首/古文观止；书评从书目库随机抽未写书目 |

- 每日发文总量：1-10 篇可配（默认 3）
- 栏目轮换比例：默认 复盘40% / 技术30% / 国学+书评30%（可配）
- 模拟人工时段：发布时间在配置时段内随机分钟
- 全部开关：AI写文总开关、单栏目开关、配图开关、发布开关（关闭则存草稿或仅生成不推送）

---

## 5. 防重复体系

1. **SimHash 指纹**：正文归一化（去标点/空白/停用词）→ 64bit SimHash → 汉明距离 < 4 判定重复（Python 与 PHP 同算法）
2. **书目防重复**：`written_books` 表，书目写过后永不二次生成
3. **选题黑名单**：`blacklist` 表 keyword/topic 双黑名单
4. **站内查重**：发布前调 WP `check` 端点比对全站历史文章
5. **标题相似度**：标题向量相似度阈值

---

## 6. 安全风控与成本保护

- 每日 Token 额度：默认 200k（可配），超额当日拦截
- 每日发文上限：1-10 可配，超额拦截
- 敏感词过滤：`core/risk.py` 词表（本地文件 data/sensitive_words.txt 可编辑）
- 熔断：同一模型连续 5 次失败 → 暂停该模型 30 分钟；接口异常自动暂停 + 日志告警
- 失败重试：网络类错误重试 2 次（指数退避），内容类错误不重试
- 密钥安全：API Key 只存 WP option（插件）和 backend/config.yaml（chmod 600），不入库不入日志

---

## 7. 青简主题适配要点

- 正文 HTML：h2/h3 标题层级、p 段落、blockquote 引用、pre/code 代码块（主题自带代码高亮）
- 封面：1280×720 WebP（主题 banner 尺寸），`<picture>`/懒加载由主题处理
- 关键数据 `<strong>` 加粗（复盘文）
- 分类 slug：`a-share-review` / `it-notes` / `reading-classics`（插件自动匹配，不存在则创建，可配）
- 适配缓存插件：发布后可选调 `wp_cache_flush` / 相关插件 purge（钩子预留）

---

## 8. 交付验收清单

- [ ] Python 侧 `pytest` 全绿（核心单测：日历、指纹、风控、配置、智能体调用 mock）
- [ ] 插件 `php -l` 语法零错误；WP 后台设置页可用
- [ ] REST 契约联调：Python 提交 → 插件建文（草稿模式）→ 指纹查重拦截验证
- [ ] 三栏目固定 Prompt 落盘 `backend/prompts/`
- [ ] crontab 模板 + 部署 README
- [ ] 全链路 dry-run 模式：不消耗真实 Token 可跑通流程
