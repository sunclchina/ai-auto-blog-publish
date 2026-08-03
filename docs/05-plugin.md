# A-Blog 设计文档 · 05 WordPress 插件

> 依据：`01-architecture.md` v1.0（§2 目录、§3.2/3.4 契约、§5 防重复、§7 青简适配）｜版本：v1.0（2026-08-03）｜模块：`wp-plugin/ai-auto-blog-publish/`

---

## 1. 文件清单与职责

| 文件 | 职责 | 关键类/函数 |
|------|------|-------------|
| `ai-auto-blog-publish.php` | 插件头（Name/Version 1.0.0）、常量（`ABP_VERSION`、`ABP_OPTION='abp_settings'`、`ABP_PREFIX='ai-auto-blog/v1'`）、加载 includes、注册 activation/deactivation 钩子、单例初始化 | `abp_init()`、`register_activation_hook`（建表+默认配置）、`register_deactivation_hook`（清缓存不删数据） |
| `readme.txt` | 标准 WP 插件说明（安装/配置/权限说明） | — |
| `includes/class-abp-settings.php` | 后台菜单「AI 自动博客」（add_menu_page，原则6 统一入口）、全部设置字段 render/sanitize、Token 生成、保存后清 transient、后台日志查看页 | `abp_settings_page()`、`abp_render_field()`、`abp_sanitize()`、`abp_generate_token()` |
| `includes/class-abp-rest.php` | REST 路由注册（§3）、Bearer 鉴权、请求统一记日志、响应统一 `{ok:...}` 包装 | `abp_register_routes()`、`abp_verify_token()`、`rest_pre_dispatch` 钩子 |
| `includes/class-abp-publish.php` | 发布主流程：校验 payload → 幂等 → 指纹查重 → 传图 → 分类 → 标签 → wp_insert_post → 特色图 → 日志 | `abp_publish_article($payload)`、`abp_upload_image()`、`abp_resolve_category()`、`abp_set_tags()` |
| `includes/class-abp-models.php` | 模型配置探测（§4，契约 3.4 返回）、transient 缓存、/health 输出（脱敏） | `abp_get_model_config()`、`abp_redact_keys()` |
| `includes/class-abp-fingerprint.php` | 指纹查重（§6，与 Python core/fingerprint.py 同算法）：归一化/SimHash/汉明距离；发布时写 `abp_fingerprint` post meta；/check 端点实现 | `abp_simhash()`、`abp_hamming()`、`abp_check_duplicate()` |
| `includes/class-abp-log.php` | `wp_abp_log` 建表（dbDelta）、`log_task()`、`get_logs()`、后台 WP_List_Table 列表页、留存清理 | `abp_create_table()`、`abp_log_task()`、`abp_cleanup_logs()` |
| `assets/js/admin.js` | 设置页交互：开关联动、复制 Token、测试连接（GET /health 自测） | — |
| `assets/css/admin.css` | 设置页样式 | — |

类间依赖单向：rest → publish → fingerprint/models/log；settings 独立。禁止跨层直接访问（原则 4）。

## 2. 设置页字段清单（option `abp_settings`，单数组序列化）

### 2.1 总开关与栏目开关

| 字段键 | 类型 | 默认 | 说明 |
|--------|------|------|------|
| `abp_enabled` | checkbox | 1 | **AI写文总开关**；关 → REST 返回 403 disabled，Python 侧全部拦截 |
| `abp_enable_stock` | checkbox | 1 | A股复盘栏目开关 |
| `abp_enable_tech` | checkbox | 1 | IT技术笔记栏目开关 |
| `abp_enable_reading` | checkbox | 1 | 读书与国学栏目开关 |
| `abp_enable_image` | checkbox | 1 | **配图开关**；关 → models.image 视为未配置，Python 走纯文字 |
| `abp_enable_publish` | checkbox | 1 | **发布开关**；关 → Python 只发 draft（02 §8 语义） |

### 2.2 配额与篇数

| 字段键 | 类型 | 默认 | 校验 |
|--------|------|------|------|
| `abp_daily_limit` | number | 3 | 1-10，每日发文上限 |
| `abp_daily_tokens` | number | 200000 | 每日 Token 额度 |
| `abp_ratio_stock/tech/reading` | number | 40/30/30 | 栏目轮换比例 %，求和≤100 校验 |
| `abp_ratio_book` | number | 50 | 读书栏目内书评占比 % |
| `abp_weekly_cap_tech/reading` | number | 3/3 | 每周篇数上限（1-3） |

### 2.3 时段

| 字段键 | 类型 | 默认 | 说明 |
|--------|------|------|------|
| `abp_stock_time` | text | `20:00` | 复盘固定发布时间 |
| `abp_tech_window` | text | `09:00-21:00` | 技术文随机时段 |
| `abp_reading_window` | text | `07:00-22:00` | 国学/书评随机时段 |
| `abp_gap_minutes` | number | 30 | 相邻发布最小间隔 |

### 2.4 模型与密钥

| 字段键 | 类型 | 默认 | 说明 |
|--------|------|------|------|
| `abp_prefer_self_key` | checkbox | 0 | 勾选后跳过主题探测，强制用插件自身 key（探测顺序 §4） |
| `abp_deepseek_api_key` | password | 空 | 插件自身 DeepSeek key（留空则探测主题 qy_ai_api_key） |
| `abp_model_stock/tech/reading` | text | `deepseek-chat` | 三栏目模型名（单 key 多模型） |
| `abp_image_provider` | select | 空 | `''`(关)/`dalle3`/`openai-compatible` |
| `abp_image_key` | password | 空 | 生图服务 key |
| `abp_image_endpoint` | text | 空 | 开源兼容接口基址 |
| `abp_image_model` | text | 空 | 生图模型名 |

### 2.5 发布与安全

| 字段键 | 类型 | 默认 | 说明 |
|--------|------|------|------|
| `abp_api_token` | text（只读+生成按钮） | 随机 32 字符 | REST Bearer 认证；`wp_generate_password(32,false)` 生成，hash 存 option，仅生成时明文展示一次 |
| `abp_category_map` | textarea(JSON) | 三个固定 slug 映射 | slug→中文名映射（§5/04 §5） |
| `abp_max_image_kb` | number | 2048 | 单图体积上限 |
| `abp_breaker_threshold` | number | 5 | 熔断连续失败阈值（Python 侧风控参数同步展示） |
| `abp_breaker_cooldown` | number | 30 | 熔断冷却分钟 |
| `abp_log_retention_days` | number | 30 | wp_abp_log 留存天数 |
| `abp_allow_remote` | checkbox | 0 | 允许非回环地址调用 REST（勾选需自担风险，配合 HTTPS） |
| `abp_debug` | checkbox | 0 | 调试模式（更多脱敏日志） |

## 3. REST 路由注册（class-abp-rest.php）

```php
add_action('rest_api_init', function () {
    register_rest_route(ABP_PREFIX, '/articles', [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => 'abp_rest_publish',
        'permission_callback' => 'abp_verify_token',
        'args' => ['task_id' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                   'column' => ['required' => true, 'sanitize_callback' => 'sanitize_key'],
                   'content_html' => ['required' => true, 'sanitize_callback' => 'wp_kses_post'],
                   'final_title' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field']],
    ]);
    register_rest_route(ABP_PREFIX, '/health',    ['methods' => 'GET', 'callback' => 'abp_rest_health',   'permission_callback' => 'abp_verify_token']);
    register_rest_route(ABP_PREFIX, '/categories',['methods' => 'GET', 'callback' => 'abp_rest_categories','permission_callback' => 'abp_verify_token']);
    register_rest_route(ABP_PREFIX, '/check',     ['methods' => 'POST','callback' => 'abp_rest_check',    'permission_callback' => 'abp_verify_token',
        'args' => ['fingerprint' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field']]]);
    register_rest_route(ABP_PREFIX, '/written-books', ['methods' => 'GET', 'callback' => 'abp_rest_written_books', 'permission_callback' => 'abp_verify_token']);
});
```

**鉴权**：`abp_verify_token()` 取 `Authorization: Bearer`，`hash_equals($stored_hash, hash('sha256', $token))` 比对（存 hash 不存明文）；失败 401。除 `abp_allow_remote` 外，回环地址（`$_SERVER['REMOTE_ADDR']` ∈ 127.0.0.1/::1）才放行，其余 403 remote_forbidden。

**请求日志**：每请求先 `abp_log_task()` 记 received，成功更新为 published(post_id)，失败记 failed(error)（脱敏后，06 §6）。所有响应统一 `rest_ensure_response(['ok' => ...])`。

## 4. 模型配置探测（class-abp-models.php）

探测顺序硬性规定（总纲 3.4），`abp_prefer_self_key` 勾选时跳过 1-2 直接执行 3：

```php
function abp_get_model_config(): ?array {
    $cached = get_transient('abp_model_config');
    if ($cached !== false) return $cached;

    // 1. 青简主题：option qy_ai_api_key + theme_mod qy_ai_model
    if (!get_option('abp_settings')['abp_prefer_self_key'] ?? false) {
        $key = get_option('qy_ai_api_key', '');
        $m   = get_theme_mod('qy_ai_model', '');
        if ($key !== '' && $m !== '') {
            return cache([ 'provider' => 'theme', 'source' => 'qingya',
                'deepseek_api_key' => $key,
                'models' => ['stock'=>$m,'tech'=>$m,'reading'=>$m,'image'=>''],
                'image_api' => ['provider'=>'','key'=>'','endpoint'=>'','model'=>''] ]);
        }
    }
    // 2. 其他已知插件探测表（option abp_model_probe_plugins，默认空，可配 key/option 名）
    foreach ((array) get_option('abp_model_probe_plugins', []) as $probe) {
        $k = get_option($probe['key_option'], '');
        if ($k !== '') return cache([ 'provider' => 'plugin', 'source' => $probe['id'], ... ]);
    }
    // 3. 插件自身 abp_settings
    $s = get_option('abp_settings', []);
    if (!empty($s['abp_deepseek_api_key'])) {
        return cache([ 'provider' => 'self', 'source' => 'abp',
            'deepseek_api_key' => $s['abp_deepseek_api_key'],
            'models' => ['stock'=>$s['abp_model_stock']?:'deepseek-chat',
                         'tech'  =>$s['abp_model_tech']  ?:'deepseek-chat',
                         'reading'=>$s['abp_model_reading'] ?: 'deepseek-chat',
                         'image' => $s['abp_image_provider'] ?: ''],
            'image_api' => ['provider'=>$s['abp_image_provider'],'key'=>$s['abp_image_key'],
                            'endpoint'=>$s['abp_image_endpoint'],'model'=>$s['abp_image_model']] ]);
    }
    // 4. 都没有
    return null;   // /health → models=null；Python 侧生成前拦截，不消耗 Token
}
```

- transient 缓存 300s；设置保存（abp_sanitize）时 `delete_transient('abp_model_config')`。
- `/health` 输出前 `abp_redact_keys()`：非回环请求把 `deepseek_api_key` 换成 `has_deepseek_api_key: true/false`（06 §5）。
- 单 key 多模型：同 key + 按栏目传不同 model 字段（deepseek-chat/coder/reasoner 均可配，总纲 3.4）。

## 5. wp_abp_log 任务日志表（class-abp-log.php）

激活时 `dbDelta` 建表（`$wpdb->prefix` 前缀）：

```sql
CREATE TABLE {prefix}abp_log (
  id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id     VARCHAR(64)  NOT NULL,                -- 幂等键（唯一）
  column_name VARCHAR(20)  NOT NULL DEFAULT '',
  post_id     BIGINT(20) UNSIGNED DEFAULT NULL,
  status      VARCHAR(20)  NOT NULL DEFAULT 'received',  -- received|published|failed|duplicate|rejected
  fingerprint VARCHAR(16)  DEFAULT NULL,            -- SimHash 64bit → 16 位 hex
  model       VARCHAR(64)  DEFAULT NULL,            -- 实际模型（source.model）
  tokens_used INT UNSIGNED NOT NULL DEFAULT 0,
  error       TEXT,                                 -- 脱敏后错误信息
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_task_id (task_id),
  KEY idx_status (status),
  KEY idx_created (created_at),
  KEY idx_fingerprint (fingerprint)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

- 用途：幂等（task_id 已存在 → 直接返回旧 post_id，`reused:true`）、/check 指纹比对、后台日志页（WP_List_Table：task_id/栏目/状态/post_id/tokens/时间/错误）、留存清理（`abp_cleanup_logs()` 每日删超 `abp_log_retention_days`）。
- 同时每篇已建文章写 post meta `abp_fingerprint`（指纹）与 `abp_is_book_review`（书评标记），供 /check 与 /written-books 检索（索引走 wp_postmeta 键）。

## 6. 指纹查重 PHP 实现（class-abp-fingerprint.php）

### 6.1 算法规范（**Python core/fingerprint.py 与 PHP 必须逐字节一致**，本规范为唯一权威）

```
输入：文章全文文本（UTF-8）
S1 归一化
   a. mb_strtolower（英文字母小写）
   b. 删除全部 Unicode 标点/符号/空白：正则 [\p{P}\p{S}\p{Z}]+（覆盖全半角、中文标点）
   c. 删除停用词（固定表，两侧完全一致，共 25 词）：
      「的 了 是 在 和 与 及 就 都 而 或 我 你 他 她 它 们 有 也 着 一个 之 以 为 等」
      实现：对停用词逐词 mb_str_replace 一次（停用词互相不得包含，保证结果确定）
S2 特征提取（2-gram，字符级）
   归一化文本长度 L：L==0 → 空集；L==1 → [文本]；否则 [substr(i,2) for i in 0..L-2]
S3 特征哈希（64bit，确定性）
   h64(f) = (crc32(utf8(f) . "\x01") << 32) | crc32(utf8(f) . "\x02")
   —— Python zlib.crc32 与 PHP crc32() 均为标准 CRC-32/IEEE（多项式 0xEDB88320），结果一致
S4 加权累加
   v[0..63] 初始 0；对每个特征 f，权重 w = 该特征在归一化文本中的出现频次：
   v[b] += ((h64(f) >> b) & 1) ? +w : -w     （b = 0..63）
S5 收敛
   hash 第 b 位 = (v[b] > 0) ? 1 : 0（v[b]==0 记 0）
S6 输出 16 位小写 hex（64bit → %016x）
S7 判重
   汉明距离 popcount(a xor b) < 4 → 重复
   比对范围：本地 fingerprints 表 + WP wp_postmeta(abp_fingerprint) 全量（发布前）
```

### 6.2 PHP 骨架（与上述规范一一对应）

```php
const ABP_STOPWORDS = ['的','了','是','在','和','与','及','就','都','而','或',
                       '我','你','他','她','它','们','有','也','着','一个','之','以','为','等'];

function abp_normalize(string $s): string {
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/[\p{P}\p{S}\p{Z}]+/u', '', $s);
    foreach (ABP_STOPWORDS as $w) { $s = str_replace($w, '', $s); }
    return $s;
}
function abp_features(string $n): array {          // 2-gram
    $len = mb_strlen($n, 'UTF-8');
    if ($len === 0) return [];
    if ($len === 1) return [$n];
    $out = [];
    for ($i = 0; $i < $len - 1; $i++) { $out[] = mb_substr($n, $i, 2, 'UTF-8'); }
    return $out;
}
function abp_hash64(string $f): int {              // 要求 PHP 64-bit
    $h1 = crc32($f . "\x01");                      // crc32 返回 unsigned 32bit
    $h2 = crc32($f . "\x02");
    return ($h1 << 32) | $h2;                      // 64-bit int
}
function abp_simhash(string $text): string {
    $n = abp_normalize($text);
    $v = array_fill(0, 64, 0);
    foreach (array_count_values(abp_features($n)) as $f => $w) {
        $h = abp_hash64($f);
        for ($b = 0; $b < 64; $b++) { $v[$b] += (($h >> $b) & 1) ? $w : -$w; }
    }
    $hash = 0;
    for ($b = 0; $b < 64; $b++) { if ($v[$b] > 0) { $hash |= (1 << $b); } }  // v==0 → 0
    return sprintf('%016x', $hash);
}
function abp_hamming(string $a, string $b): int {
    $x = hexdec($a) ^ hexdec($b);
    $c = 0;
    while ($x) { $x &= $x - 1; $c++; }             // Kernighan popcount
    return $c;
}
function abp_check_duplicate(string $text): array {
    $fp = abp_simhash($text);
    global $wpdb;
    foreach ($wpdb->get_col("SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key='abp_fingerprint'") as $old) {
        if (abp_hamming($fp, $old) < 4) return ['duplicate' => true, 'fingerprint' => $fp, 'matched' => $old];
    }
    return ['duplicate' => false, 'fingerprint' => $fp];
}
```

### 6.3 一致性保障

- 发布成功/草稿入库时写 `abp_fingerprint` post meta（用于站内查重，总纲 §5.4）。
- `/check` 端点 = `abp_check_duplicate($_POST['fingerprint'])`（Python 侧先算好指纹传入，或传全文由插件计算，两种均支持）。
- 交叉验证：`tests/` 内置固定文本样例 3 条（含中文标点/大小写/停用词/重复段），Python 与 PHP 各算一遍断言同值；CI 跑 PHP smoke（`php -r` + 直连 WP）。
- 平台要求：PHP 7.4+ **64-bit**（32-bit 下 `<<32`/`hexdec` 会变 float，插件激活时检查 `PHP_INT_SIZE===8` 并提示）。

## 7. 激活 / 卸载

- **激活**：dbDelta 建 wp_abp_log；`add_option('abp_settings', 默认值数组)`（含生成 abp_api_token）；注册每日清理 `wp_schedule_event`（可选）。
- **停用**：清 transient、清定时事件；**不删任何数据**（日志/设置保留，安全删除需手动，文档说明）。
- 发布成功钩子：`do_action('abp_after_publish', $post_id)`，主题/缓存插件可挂 `wp_cache_flush` 等（总纲 §7，预留）。

## 8. 可测试性

- `php -l` 全文件零错误（验收项）；`tests/wp_smoke.php`：health/categories/check/articles(draft) 五端点冒烟。
- 单元断言：abp_simhash 对空串/单字/长文、abp_hamming 边界（0/3/4）、分类创建幂等、task_id 幂等。
