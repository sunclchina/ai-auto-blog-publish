=== AI自动博客 A-Blog ===
Contributors: ablogteam
Tags: ai, blog, automation, rest-api, simhash, deepseek
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.5.42
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI 全自动博客插件（A-Blog）：自动选题、AI 写作、自动配图、定时发布，无人值守日更。自足功能插件——数据、调度、素材、生成全部在 WordPress 内完成，任何环境安装即用，不依赖外部服务。

== Description ==

A-Blog 是**自足功能插件**：内置调度（WP-Cron）、动态选题、AI 写作、行情采集、AI 配图、自动发布，支持 A股复盘 / IT技术 / 国学 / 书评 / 行业分析五个栏目。装进 WordPress 即全自动日更，可在本地、云端、任何环境运行。

**核心能力**

* 全自动调度：每日自动建队列 → 到点生成 → 发布；错过任务自动补执行（关机/无访问不丢）
* SimHash 指纹查重：字符 2-gram + crc32 + 64 位权重累加，汉明距离 < 4 判重，发布前自动拦截重复内容
* 自动建文：分类 slug 匹配（a-share-review / it-notes / reading-classics），不存在自动创建；标签智能打标
* SEO Meta 描述：自动适配 Yoast（_yoast_wpseo_metadesc）/ RankMath（rank_math_description）/ 通用 _abp_meta_description
* 封面配图：支持 base64 与 URL 上传媒体库并绑定特色图（1280×720 WebP，适配青简主题）
* 发布控制：草稿 / 发布 / 定时（future + publish_date），后台「发布开关」可强制仅存草稿
* 模型配置自动探测：青简主题（qy_ai_api_key）→ 插件自身配置，DeepSeek 单 key 多模型分发
* 完整后台：开关列表、调度配置、API Token 生成、任务日志（最近 50 条 AJAX 刷新）、模型探测结果展示

**REST API（全部需 Bearer Token）**

* `GET /wp-json/ai-auto-blog/v1/health` — 健康检查 + 模型探测摘要
* `GET /wp-json/ai-auto-blog/v1/categories` — 站点分类列表
* `POST /wp-json/ai-auto-blog/v1/articles` — 提交成品文章
* `POST /wp-json/ai-auto-blog/v1/check` — 指纹查重（{fingerprint} 或 {text}）
* `GET /wp-json/ai-auto-blog/v1/written-books` — 已写书目清单（读书栏目防重复）

== Installation ==

A-Blog 是**自足功能插件**：激活即用，不依赖任何外部服务（可在本地、云端、任何 WordPress 环境运行）。

1. 后台「插件 → 安装插件 → 上传」上传 `ai-auto-blog-publish-v1.5.24.zip`，激活
   - 激活自动建表、注册调度（每日自动选题/生成/发布），无需额外操作
2. 「AI 自动博客」设置页按需配置：
   - **AI 模型**（默认 deepseek-chat）与 **DeepSeek API Key**（或复用青简主题密钥，自动探测）
   - 可选：**图片 API 配置**（AI 配图用，支持阿里百炼/OpenAI 兼容）、**Tavily API Key**（行业分析用）、**站点图书目录**（书评选题用）、**IT RSS 源**（IT 栏目动态选题）
3. 生成 API Token（供外部调用）
4. 后台可人工干预：备用选题池、今日计划任务、日志、AI 工具箱（摘要/评论/话题/AI 配图）

**升级**：上传新 zip 覆盖即可（自动建表自愈）；后台「自动升级」可检查 GitHub Release 更新。

== Frequently Asked Questions ==

= 模型从哪里来？ =

探测顺序：① 青简主题密钥；② 插件自身「DeepSeek API Key」配置。均未配置时任务无法生成（后台显示「无可用模型」）。

= API Token 丢了怎么办？ =

后台「AI 自动博客」→「API Token」→「重新生成」。注意旧 Token 立即失效，需同步更新使用方配置。

= 如何只存草稿不发布？ =

关闭「发布开关」（发布开关 → off），所有提交的文章将以草稿状态保存。

= 重复内容会被发布吗？ =

不会。发布前对正文做 SimHash 指纹查重，与站内历史文章汉明距离 < 4 即拒绝（HTTP 409），任务标记为 skipped。

= 封面图支持什么格式？ =

支持 base64 data URI（data:image/webp;base64,...）与 http(s) URL。推荐 1280×720 WebP（青简主题 banner 尺寸）。

== Changelog ==

= 1.5.42 =
* 工具箱列表显示全部文章（不限 30 篇）

= 1.5.41 =
* AI 评论状态遵循「评论必须经人工批准」设置（开启则进审批，关闭则直接显示）

= 1.5.40 =
* AI 评论默认直接通过（无需人工批准）

= 1.5.39 =
* 工具箱评论数实时统计（修复导入虚高计数）

= 1.5.38 =
* 工具箱兼容星河字段：摘要 `_xhai_excerpt`、话题 `xhai_thread`/`xhai_postparent`

= 1.5.37 =
* 计划任务列表按发布时间排序

= 1.5.36 =
* 重写按钮合并：重写即「重写并立即完成」

= 1.5.35 =
* 计划任务列表显示发布时间（计划/实际）

= 1.5.34 =
* 计划任务列表显示全部任务；清空=清空全部

= 1.5.33 =
* 修复 next_task_id 时区（列入计划后任务列表可见）

= 1.5.32 =
* 新增任务「重写」：published 任务重置排队，发布端覆盖原文章（post_id）

= 1.5.31 =
* 修复任务列表时区（current_time 本地日期）

= 1.5.30 =
* thread 文章类型注册、BOM 清理、目录整理



= 1.5.30 =
* 分类归并：发布分类解析重写——industry/it-notes/IT技术笔记 等别名一律归入博客已有分类（行业/IT/股市/读书），匹配不到回退默认分类，绝不创建新分类

= 1.5.27 =

* **复盘封面特判**：股市/复盘文章生图引导模型画当日热点板块的具象场景（算力机房/白酒酒坛/军工战机…），明确避免 K 线/蜡烛图/货币符号模板——复盘封面不再千篇一律

= 1.5.26 =

* **AI 配图提示词优化**：从标题+正文提炼具象视觉意象词作画面主体（前置、权重最高），内容要点扩至 300 字，风格词弱化为氛围参考——同类栏目文章封面不再雷同
* 附带：readme.txt Stable tag 同步（1.5.24 → 1.5.26）

= 1.5.25 =

* 分类对齐主题已有分类（IT→it、行业→行业、股市→a-share-review、读书→reading-classics），不再新建杂类

= 1.5.24 =

* ★ **插件自足重构**：数据/调度/素材/生成全部在 WordPress 内完成，任何环境安装即用
* 内置 WP-Cron 调度：每日自动建队列、到点生成发布；**错过任务自动补执行**（关机/无访问不丢，复盘用历史行情补写）
* 大模型选题 + 写作：五栏目（复盘/IT技术/国学/书评/行业分析）统一模型
* 动态素材：唐诗三百首/宋词三百首联网获取每日更新、IT 问题池+RSS、站点书目查重、Tavily 行业搜索（可选 key）
* A股复盘：仅交易日选题，新浪/东财实时行情（历史补写用日K），先写正文后定副标题，禁止编造
* AI 配图本地化：阿里百炼（wanx-v1）与 OpenAI 兼容生图，自动上传设特色图
* 后台管理台：备用选题池、今日计划任务、任务日志、AI 工具箱
* 覆盖升级自愈：自动建表，无需停用启用

= 1.4.1 =

* 阿里云百炼（DashScope）生图支持：通义万相 wanx-v1 / wan2.x-t2i 等模型（原生异步接口适配），Provider/Key/Endpoint/Model 可在后台「图片 API 配置」填写
* 修复：WP 后台图片配置保存后未同步到后端（新增 /api/sync 强制同步，AI 配图前自动拉取最新配置）
* 修复：检查更新提示——远端 GitHub Release 低于当前版本时明确提示「Release 未同步」
* AI 评论作者本地 SVG 头像：按昵称确定性生成 100×100 SVG（约 0.6KB，零成本零外部依赖），评论生成时自动挂载，前台替换默认 Gravatar；旧评论渲染时自动补生成
* 工具箱文章列表新增「封面」状态列
* 移除已废弃的「修复旧文站内相关」维护工具
* 后端 APP_VERSION 1.2.0

= 1.4.0 =

* ★ 后端内置调度（免 crontab / 计划任务）：常驻服务自动建队列、按轮次生成（每轮 1 篇）、到期自动发布；配置见 backend/config.yaml 的 scheduler 段，改配置即生效
* 环境变量配置支持 JSON 数组（如 scheduler.run_times）

= 1.3.0 =

* ★ 全家桶：插件 ZIP 内置 Python 伴生服务（backend/），安装/升级一步到位，不再依赖外部部署
* Windows 一键安装脚本 `backend/install-backend.bat`
* 安装文档：`backend/README.md`（Windows / Linux / 密钥 / 调度）

= 1.2.0 =
* 新增 GitHub Release 自动升级：后台配置仓库（默认 sunclchina/ai-auto-blog-publish）+ 开关 + 可选 Token，插件定期检查新版本，后台「插件」页出现标准更新提示，一键升级
* 备用选题池新增「一键清空」（软删全部排队选题，保留已用历史）
* 正文内链占位「站内相关」改为显示文章类别（A股每日复盘 / IT技术笔记 / 读书与国学 / 行业），所有栏目统一生效
* 行业栏目正式接入备选题池与后台开关（A股热门行业/概念成题，Tavily 驱动）
* 修复：备选题池 IT 栏目填充静默失败（采集器返回列表却按字典取值）

= 1.1.0 =
* AI 工具箱：AI 摘要（已有直接覆盖重写）/ AI 评论（条数 1-30、待审核或直接显示）/ 热门话题（自动归档到「话题」分类并生成话题简介）
* AI 工具箱批量：文章多选列表（摘要 / 评论数 / 话题状态一目了然），批量生成摘要 / 评论 / 热门话题，实时进度
* 复盘查重规则：按「要求复盘的日期」（标题日期）查重；同日期已存在 → 自动删除旧文并覆盖重做
* REST 端点支持批量提交（post_ids 数组）；工具箱端点 /toolbox/{summary,comments,topics}
* 修复：IIS 环境下前端 REST 地址改用 ?rest_route= 形式；中文分类名解析（映射表 + 名字匹配 + 自动创建）
* 话题分类法 abp_topic（slug=topic，中文名 + 英文 slug topic-N，兼容 IIS）

= 1.0.0 =
* 首发版本：REST 接收、SimHash 查重、自动建文/分类/标签/配图/定时发布、模型配置探测、后台设置页与任务日志

== Upgrade Notice ==

= 1.2.0 =
* 升级后到「AI 自动博客 → 配置表单 → 自动升级」确认仓库地址（默认已填 sunclchina/ai-auto-blog-publish）；如需从本机 Gitea 或自建源升级，改填对应仓库即可。


= 1.1.0 =
* 升级后建议重新生成 API Token；工具箱与批量功能在后台「AI 自动博客」页面使用。


= 1.0.0 =
* 首个正式版本。
