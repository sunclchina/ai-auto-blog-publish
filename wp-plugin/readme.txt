=== AI自动博客 A-Blog ===
Contributors: ablogteam
Tags: ai, blog, automation, rest-api, simhash, deepseek
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
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
* 模型配置自动探测：青简主题（qy_ai_api_key）→ 其他插件探测表 → 插件自身配置，DeepSeek 单 key 多模型分发
* 完整后台：开关列表、调度配置、API Token 生成、任务日志（最近 50 条 AJAX 刷新）、模型探测结果展示

**REST API（全部需 Bearer Token）**

* `GET /wp-json/ai-auto-blog/v1/health` — 健康检查 + 模型探测摘要
* `GET /wp-json/ai-auto-blog/v1/categories` — 站点分类列表
* `POST /wp-json/ai-auto-blog/v1/articles` — 提交成品文章
* `POST /wp-json/ai-auto-blog/v1/check` — 指纹查重（{fingerprint} 或 {text}）
* `GET /wp-json/ai-auto-blog/v1/written-books` — 已写书目清单（读书栏目防重复）

== Installation ==

1. 将 `ai-auto-blog-publish` 文件夹上传到 `/wp-content/plugins/` 目录
2. 在 WordPress 后台「插件」页面激活「AI自动博客 A-Blog」
3. 进入后台菜单「AI 自动博客」：
   - 确认各开关与调度配置（每日篇数、Token 额度、发布时段、栏目比例）
   - 生成 API Token 并复制
   - 查看「模型探测结果」确认已探测到可用模型（青简主题 qy_ai_api_key 优先）
4. 在 Python 侧 `backend/config.yaml` 中配置 WP 站点地址、API Token，启动 FastAPI 服务（127.0.0.1:8080）

== Frequently Asked Questions ==

= 模型从哪里来？ =

探测顺序：① 青简主题 `qy_ai_api_key` option + `qy_ai_model` theme_mod；② 后台「其他插件探测表」配置的插件；③ 插件自身「DeepSeek API Key」配置。均未配置时 `/health` 返回 `no_model_configured`，Python 侧将拦截任务不消耗 Token。

= API Token 丢了怎么办？ =

后台「AI 自动博客」→「API Token」→「重新生成」。注意旧 Token 立即失效，需同步更新 Python 侧配置。

= 如何只存草稿不发布？ =

关闭「发布开关」（发布开关 → off），所有提交的文章将以草稿状态保存。

= 重复内容会被发布吗？ =

不会。发布前对正文做 SimHash 指纹查重，与站内历史文章汉明距离 < 4 即拒绝（HTTP 409），Python 侧标记任务为 skipped。

= 封面图支持什么格式？ =

支持 base64 data URI（data:image/webp;base64,...）与 http(s) URL。推荐 1280×720 WebP（青简主题 banner 尺寸）。

== Changelog ==

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

= 1.1.0 =
* 升级后建议重新生成 API Token；工具箱与批量功能在后台「AI 自动博客」页面使用。


= 1.0.0 =
* 首个正式版本。
