=== AI自动博客 A-Blog ===
Contributors: ablogteam
Tags: ai, blog, automation, rest-api, simhash, deepseek
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI 全自动博客内容生产与发布插件（A-Blog）：接收 Python 伴生服务产出的成品文章，SimHash 指纹查重后自动建文、分类、打标、配图并发布。

== Description ==

A-Blog 是「AI 全自动博客」系统的 WordPress 发布端插件，配合 Python 伴生服务（调度 + AI 生成）实现无人值守日更。

**核心能力**

* 接收成品文章：`POST /wp-json/ai-auto-blog/v1/articles`（Bearer Token 认证）
* SimHash 指纹查重：与 Python 侧同算法（字符 2-gram + crc32 + 64 位权重累加），汉明距离 < 4 判重，发布前自动拦截重复内容
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

A-Blog 为全家桶：**插件（PHP）+ 伴生服务（Python）**。ZIP 内已含 `backend/` 目录，安装两步：

1. 将 `ai-auto-blog-publish` 文件夹上传到 `/wp-content/plugins/` 目录（或后台直接上传 ZIP），激活「AI自动博客 A-Blog」
2. 安装并启动 Python 伴生服务（127.0.0.1:8080）：
   - **Windows**：执行插件目录 `backend/install-backend.bat`，再运行 `backend/start-backend.bat`
   - **Linux**：`cd backend && sudo bash install.sh && sudo systemctl start ablog`
3. 配置密钥（`backend/config.yaml`，首次由安装脚本从模板生成）：
   - `ABP_MODEL_API_KEY`（DeepSeek Key，AI 生成必需）
   - `ABP_API_TOKEN`（后台「AI 自动博客」→「API Token」生成，发布必需）
4. 后台「AI 自动博客」页：确认开关与调度配置（每日篇数、Token 额度、发布时段、栏目比例）

后台若显示红色横幅「伴生服务未运行」，按提示执行安装脚本即可。详见 `backend/README.md`。

== Frequently Asked Questions ==

= 模型从哪里来？ =

探测顺序：① 青简主题 `qy_ai_api_key` option + `qy_ai_model` theme_mod；② 插件自身「DeepSeek API Key」配置。均未配置时 `/health` 返回 `no_model_configured`，Python 侧将拦截任务不消耗 Token。

= API Token 丢了怎么办？ =

后台「AI 自动博客」→「API Token」→「重新生成」。注意旧 Token 立即失效，需同步更新 Python 侧配置。

= 如何只存草稿不发布？ =

关闭「发布开关」（发布开关 → off），所有提交的文章将以草稿状态保存。

= 重复内容会被发布吗？ =

不会。发布前对正文做 SimHash 指纹查重，与站内历史文章汉明距离 < 4 即拒绝（HTTP 409），Python 侧标记任务为 skipped。

= 封面图支持什么格式？ =

支持 base64 data URI（data:image/webp;base64,...）与 http(s) URL。推荐 1280×720 WebP（青简主题 banner 尺寸）。

== Changelog ==

= 1.4.1 =

* AI 配图：工具箱新增「AI 配图」按钮，勾选文章后由后端 AI 自动生成封面（按标题+正文要点+栏目风格出提示词），异步任务 + 轮询进度，生成后自动上传媒体库并设为特色图
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
* 后台新增伴生服务健康检测横幅（未运行时醒目提示安装步骤）
* Windows 一键安装脚本 `backend/install-backend.bat`
* 安装文档：`backend/README.md`（Windows / Linux / 密钥 / 调度）

= 1.2.1 =
* 修复：备用选题池「一键清空」按钮报 REST 路由 404（补上 /pool/clear 代理路由）

= 1.2.0 =
* 新增 GitHub Release 自动升级：后台配置仓库（默认 sunclchina/ai-auto-blog-publish）+ 开关 + 可选 Token，插件定期检查新版本，后台「插件」页出现标准更新提示，一键升级
* 备用选题池新增「一键清空」（软删全部排队选题，保留已用历史）
* 正文内链占位「站内相关」改为显示文章类别（A股每日复盘 / IT技术笔记 / 读书与国学 / 行业），所有栏目统一生效
* 行业栏目正式接入备选题池与后台开关（A股热门行业/概念成题，Tavily 驱动）
* 修复：备选题池 IT 栏目填充静默失败（采集器返回列表却按字典取值）

= 1.1.0 =
* AI 工具箱：AI 摘要（已有直接覆盖重写）/ AI 评论（条数 1-30、待审核或直接显示）/ 热门话题（自动归档到「话题」分类并生成话题简介）
* AI 工具箱批量：文章多选列表（摘要 / 评论数 / 话题状态一目了然），批量生成摘要 / 评论 / 热门话题，实时进度
* 备选题池栏目改为从现有文章分类中选择（支持中文分类名，如「股市」「行业」），任务 ID 使用英文栏目码
* 复盘查重规则：按「要求复盘的日期」（标题日期）查重；同日期已存在 → 自动删除旧文并覆盖重做
* REST 端点支持批量提交（post_ids 数组）；工具箱端点 /toolbox/{summary,comments,topics}
* 修复：IIS 环境下前端 REST 地址改用 ?rest_route= 形式；中文分类名解析（映射表 + 名字匹配 + 自动创建）
* 话题分类法 abp_topic（slug=topic，中文名 + 英文 slug topic-N，兼容 IIS）

= 1.0.0 =
* 首发版本：REST 接收、SimHash 查重、自动建文/分类/标签/配图/定时发布、模型配置探测、后台设置页与任务日志

== Upgrade Notice ==

= 1.2.1 =
* 修复一键清空按钮路由 404，升级后刷新后台即可使用。


= 1.2.0 =
* 升级后到「AI 自动博客 → 配置表单 → 自动升级」确认仓库地址（默认已填 sunclchina/ai-auto-blog-publish）；如需从本机 Gitea 或自建源升级，改填对应仓库即可。


= 1.1.0 =
* 升级后建议重新生成 API Token；工具箱与批量功能在后台「AI 自动博客」页面使用。


= 1.0.0 =
* 首个正式版本。
