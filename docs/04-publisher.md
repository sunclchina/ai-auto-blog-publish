# A-Blog 设计文档 · 04 WP 发布层

> 依据：`01-architecture.md` v1.0（§3.2 REST 契约、§7 青简适配）｜版本：v1.0（2026-08-03）｜模块：`backend/publishers/`（wp_rest.py / wp_xmlrpc.py / image.py）↔ `wp-plugin/includes/class-abp-*.php`

---

## 1. 职责与边界

发布层把 ready 任务（含成品 HTML、可选配图、分类标签、发布参数）通过 WP REST API 落成文章。Python 侧负责协议与重试（wp_rest.py，主）+ XML-RPC（wp_xmlrpc.py，兜底）；WP 插件侧负责接收、建文、传图、查重（class-abp-publish.php 等）。**Python 不直接写数据库**，一切经 REST。

## 2. REST 契约明细

统一前缀 `/wp-json/ai-auto-blog/v1`，统一鉴权 `Authorization: Bearer <ABP_API_TOKEN>`（hash_equals 比对，见 05 §3）。

### 2.1 端点总表

| 方法 | 路径 | 鉴权 | 用途 |
|------|------|------|------|
| POST | /articles | 必须 | 创建/发布文章（幂等，按 task_id） |
| GET | /health | 必须* | 插件版本、时区、模型配置、开关快照 |
| GET | /categories | 必须 | 站点分类列表（slug/id/name） |
| POST | /check | 必须 | 指纹查重 |
| GET | /written-books | 必须 | 已写书评书目清单 |

\* 均要求 token；密钥字段仅当请求方为回环地址（127.0.0.1/::1）时返回明文，否则只返回 `has_*` 布尔（见 06 §5）。

### 2.2 POST /articles

请求体 = 总纲 3.1 任务对象 JSON（含增量字段 `meta`）。示例：

```json
{
  "task_id": "20260803-stock-001",
  "column": "stock",
  "topic": "8月3日A股复盘",
  "final_title": "2026-08-03 A股复盘：成交额站上万亿，券商领涨",
  "content_html": "<h2>盘面综述</h2><p>...</p><pre><code>...</code></pre>",
  "excerpt": "…",
  "meta_description": "…",
  "tags": ["A股复盘", "券商板块", "万亿成交"],
  "category": "A股每日复盘",
  "featured_image": "data:image/webp;base64,...",   // 或本地路径/URL（Python 侧已统一转 base64，见 §4）
  "status": "publish",                               // draft | publish | future
  "publish_date": "2026-08-03T20:00:00+08:00",       // status=future 时必填（站点时区，带偏移）
  "source": {"model": "deepseek-chat", "prompt_version": "v1.0"},
  "meta": {"subtype": "stock", "humanize_failed": false, "image_failed": false}
}
```

响应（统一包一层 `ok`）：

```json
// 200 成功（含幂等复用）
{"ok": true, "post_id": 123, "permalink": "https://sunclnas.cn/archives/123",
 "status": "publish", "duplicate": false, "reused": false}
// 409 指纹重复
{"ok": false, "error": "duplicate", "fingerprint": "3f9a...", "matched_task_id": "20260801-tech-002"}
```

### 2.3 错误码表（Python 侧据此决定是否重试）

| HTTP | error | 含义 | 重试? |
|------|-------|------|-------|
| 400 | invalid_payload | 缺 task_id/column/content_html 或 JSON 非法 | 否 |
| 401 | unauthorized | token 无效 | 否 |
| 403 | disabled | 插件总开关关闭（abp_enabled=false） | 否 |
| 409 | duplicate | 指纹重复 | 否（→ 任务 skipped） |
| 500 | internal_error | 插件内部异常 | 是（5xx 策略） |
| 502/503/504 | upstream_error | WP/缓存层异常 | 是（5xx 策略） |
| 网络异常 | connect_timeout / read_timeout | 连接失败、读超时 | 是（网络策略） |

### 2.4 GET /health 响应（Python 启动与每小时同步一次，缓存）

```json
{"ok": true, "version": "1.0.0",
 "timezone": {"string": "Asia/Shanghai", "gmt_offset": 8},
 "settings": {"abp_enabled": true, "abp_enable_publish": true, "abp_daily_limit": 3,
              "abp_category_map": {"a-share-review": "A股每日复盘", "it-notes": "IT技术笔记", "reading-classics": "读书与国学"}},
 "models": { /* 契约 3.4，回环含 deepseek_api_key 明文，非回环仅 has_* */ }}
```

## 3. 发布主流程（wp_rest.py → 插件 class-abp-publish.php）

```
publish_with_retry(task)                    # Python：鉴权头 + 重试（§7）
  └─ POST /articles
      └─ class-abp-publish::handle()
         1 校验 payload + task_id 幂等检查（wp_abp_log 已有 → 返回旧 post_id, reused=true）
         2 指纹查重（§6，class-abp-fingerprint）→ 命中返回 409
         3 媒体上传（§4，featured_image → attachment_id；失败降级继续）
         4 分类解析（§5，slug 匹配→不存在则创建）
         5 标签处理（§6 规则）
         6 wp_insert_post（status 映射见 §8）+ set_post_thumbnail
         7 写 wp_abp_log + 可选缓存清理钩子（do_action('abp_after_publish', $post_id)）
         8 返回 {ok, post_id, permalink, status}
```

## 4. 媒体上传流程（先传图拿 attachment_id，再建文绑 featured_media）

顺序硬性要求：**attachment 先于 post 创建**，成功后 `set_post_thumbnail($post_id, $attachment_id)`（即 REST 语义的 featured_media → `_thumbnail_id`）。

1. Python 侧 `publishers/image.py`：Pillow 打开本地 WebP → 居中裁切/缩放 1280×720 → quality 82 重压缩，目标 ≤1.5MB（> 则降 quality 到 60）→ base64（`data:image/webp;base64,...`）放入 `featured_image`。
2. 插件侧解析：校验 mime 为 `image/webp`、解码后大小 ≤ `abp_max_image_kb`（默认 2048KB）、扩展名白名单（webp/jpg/png）→ `wp_upload_bits()` 落盘。
3. `wp_insert_attachment(['post_mime_type'=>'image/webp','post_title'=>sanitize_title(final_title),'post_excerpt'=>final_title(作 alt),'post_status'=>'inherit'], $file)` → attachment_id。
4. `wp_generate_attachment_metadata()` + `wp_update_attachment_metadata()`（生成缩略图尺寸，青简 banner 取原图）。
5. 建文后 `set_post_thumbnail($post_id, $attachment_id)`。
6. **降级规则**：1-5 任一步失败（含图片 URL 下载超时）→ 记录警告、无特色图继续建文，绝不因图失败而丢文章（与 03 §7 降级一致）。
7. 若 `featured_image` 为 URL：插件 `wp_remote_get` 下载（超时 15s），失败同降级。

## 5. 分类匹配策略（slug 匹配 → 不存在则创建）

- 固定 slug 映射（可配 `abp_category_map`）：`a-share-review`→A股每日复盘、`it-notes`→IT技术笔记、`reading-classics`→读书与国学（总纲 §7）。
- 流程：`get_category_by_slug($slug)` 命中 → 用其 id；未命中 → `wp_insert_category(['cat_name'=>$name,'category_nicename'=>$slug])` → 再查一次（防并发重复创建）→ 更新缓存 option `abp_category_ids`（slug→id 映射）。
- Python 侧可先 `GET /categories` 预热缓存，但**权威创建逻辑在插件**，Python 不自行建分类。
- 任务 `category` 为中文名时：插件先按 map 反查 slug，查不到再按名称 `category_exists($name)` 兜底。

## 6. 标签自动生成规则

1. 来源：SEO 智能体 `tags`（≤5 个，03 §5）+ 栏目固定标签（stock 加「A股复盘」）。
2. 清洗：strip_tags、trim、`mb_substr ≤ 30` 字、去重（mb_strtolower 比较）、剔除敏感词命中项（Python 已滤，插件对明显异常项二次剔除）。
3. 落库：`wp_set_post_terms($post_id, $tags, 'post_tag', false)`；同名已存在标签自动合并（WP 原生行为）。
4. 书评文章额外打 `书评` 标签 → 插件据此写 `abp_is_book_review=1` post meta（供 /written-books 检索，§2.1）。
5. tags 为空 → 跳过该步，不影响建文。

## 7. 发布失败重试策略（wp_rest.py）

```python
RETRY_STATUS = {500, 502, 503, 504}      # 5xx 重试 2 次
def publish_with_retry(payload):
    for attempt in range(3):             # 首次 + 2 次重试
        try:
            r = httpx.post(URL_ARTICLES, json=payload,
                           headers={"Authorization": f"Bearer {token}"},
                           timeout=(5, 30))          # connect 5s / read 30s
        except (httpx.ConnectError, httpx.ReadTimeout, httpx.ConnectTimeout) as e:
            if attempt < 2: time.sleep(1 * 2 ** attempt); continue   # 1s, 4s 指数退避
            return fail_task("network", str(e))
        if r.status_code in RETRY_STATUS:
            if attempt < 2: time.sleep(1 * 2 ** attempt); continue
            return fail_task(f"http_{r.status_code}", r.text[:200])
        if 400 <= r.status_code < 500:
            return reject_or_skip(payload, r)        # 4xx 不重试（409 → skipped(duplicate)）
        return success(r.json())                     # 200 → published
```

- **幂等防重**：网络超时重试可能导致服务端已建文 → 插件按 `task_id` 唯一键（wp_abp_log 唯一索引）拦截，返回既有 post_id + `reused: true`，Python 视为成功。
- 重试耗尽 → 任务 failed(publish_failed, error)，由调度层日志/告警，人工可重试。
- XML-RPC 兜底（wp_xmlrpc.py）：REST 连续 3 次 5xx 后切换 `wp.newPost`（需单独配置的 app password），失败不重试直接报错。

## 8. 草稿 / 定时发布处理

| task.status | 插件 post_status | post_date 处理 |
|-------------|------------------|----------------|
| draft | draft | 立即入库为草稿，post_date=now |
| publish | publish | 立即发布（发布开关关闭时由 Python 改发 draft） |
| future | future | `post_date` = publish_date（站点时区）；若时间已过 → 自动改 publish 并告警 |

- **时区协议**：`publish_date` 由 Python 按站点时区计算并携带偏移（如 `+08:00`，站点时区取自 /health.timezone）；插件 `strtotime()` → `wp_date('Y-m-d H:i:s', $ts)` 设 post_date，`gmdate('Y-m-d H:i:s', $ts)` 设 post_date_gmt（避免 WP 时区换算二次偏移）。
- WP 的 future 自动发布依赖 WP-Cron；部署时建议系统 cron 定期 `wp cron event run --due-now`（运维项，写入部署 README）。
- 发布开关关闭（abp_enable_publish=false）时：Python 一律发 `status=draft`；任务状态保持 ready（终态，02 §2.1），不产生 published。

## 9. 可测试性

- `test_wp_rest.py`：契约单测（mock httpx）——5xx 重试次数与退避、4xx 不重试、409 转 skipped、幂等 reused 分支、超时重试。
- `test_media.py`：1280×720 裁切、WebP 压缩体积、超限降质、base64 往返。
- 插件侧 `tests/wp_smoke.php`：直连本机 WP 发 draft 任务 → 断言分类/标签/特色图/日志行（联调脚本，见总纲 §8）。
