# A-Blog 设计文档 · 06 安全风控

> 依据：`01-architecture.md` v1.0（§5 防重复、§6 安全风控与成本保护）｜版本：v1.0（2026-08-03）｜模块：`backend/core/`（risk.py / db.py / logger.py）+ `data/`（sensitive_words.txt 等）

---

## 1. 职责与边界

风控层（core/risk.py）负责四件事：**敏感词过滤、每日额度记账、熔断状态机、密钥与日志脱敏**。它被调度层（02 §7 前置检查）与生成层（03 §2 记账）调用，自身不产生任务、不调用模型。WP 侧（05）做发布端复核（幂等/指纹），两侧职责不重叠。

## 2. 敏感词过滤流程

### 2.1 词表与加载

- 词表：`data/sensitive_words.txt`（UTF-8，每行一词，`#` 开头为注释，空行忽略），可编辑，进程启动加载进内存（LRU 缓存，文件 mtime 变化自动重载）。
- 词表分两类（映射 blacklist 语义，01 §3.3）：`keyword`（正文/标题命中即处理）与 `topic`（选题命中直接拒）。
- 匹配实现：词表 ≤200 词 → 编译单条正则 alternation（`re.escape`）；更大 → 可选 `ahocorasick` 库，未安装时退化分词表逐词 `in`（性能可接受，v1 明确记录此限制）。

### 2.2 过滤点（三道）

| 时机 | 检查对象 | 命中动作 |
|------|----------|----------|
| ① 入队前（02 §7 #4） | topic + 素材摘要 | topic 类命中 → 任务 skipped(blacklist)，不留生成痕迹 |
| ② 生成后（03 §6 终稿校验） | title / content / excerpt / tags | 命中词替换为 `***`，计数写 meta；标题全命中或总命中数 > `risk_hit_threshold`（默认 5）→ failed(risk) |
| ③ 发布前（WP /articles 内复核，05） | final_title / tags | 插件对明显异常项二次剔除，不阻断正常发布 |

- 命中统计：每任务记录 `meta.risk_hits`，供审计与词表迭代。
- 已知限制（v1 明示）：不做同音/拆字变体识别，靠词表维护。

### 2.3 接口

```python
# core/risk.py
def check_topic(topic: str) -> tuple[bool, str | None]     # (pass?, hit_word)
def sanitize_text(text: str) -> tuple[str, int]            # (替换后文本, 命中数)
def load_wordlist() -> None                                # 启动/重载
```

## 3. 每日额度记账（quota_daily）

### 3.1 表（01 §3.3）与原子更新

```sql
CREATE TABLE quota_daily (
  day TEXT PRIMARY KEY,               -- YYYY-MM-DD
  tokens_used INTEGER DEFAULT 0,
  articles_published INTEGER DEFAULT 0
);
```

```python
# core/risk.py —— 单进程串行 + 事务，双保险
def account_tokens(day: str, delta: int):
    with db.conn:                       # BEGIN IMMEDIATE
        db.execute("INSERT INTO quota_daily(day) VALUES(?) ON CONFLICT(day) DO NOTHING", (day,))
        db.execute("UPDATE quota_daily SET tokens_used = tokens_used + ? WHERE day=?", (delta, day))
def account_published(day: str):
    ... # 同上，articles_published + 1（收到 WP post_id 后调用）
```

### 3.2 记账点（只增不减，回滚由人工处理）

| 事件 | 记账 | 调用方 |
|------|------|--------|
| 每次模型调用成功 | tokens += total_tokens | 03 §2 call_model（成功才记，失败不记） |
| WP 发布成功 | articles_published += 1 | 04 §7 success 分支 |
| 任务失败重试 | 每次实际调用都记（重试也消耗 Token） | call_model 内天然覆盖 |

### 3.3 预检（写文前，0 消耗）

02 §7 #7/#8：`tokens_used + est_cost(task) ≥ daily_tokens` → skipped(quota)；`articles_published + 当日已排队 ≥ daily_limit` → skipped(daily_cap)。est_cost 公式见 02 §7。日切换：按 `date.today()` 取 day 键，天然按天隔离，无需清理。

## 4. 熔断状态机（per-model）

### 4.1 状态定义

| 状态 | 含义 | 进入条件 |
|------|------|----------|
| CLOSED | 正常放行 | 初始化 / HALF_OPEN 探测成功 |
| OPEN | 拒绝一切调用（call_model 直接抛 BreakerOpen，0 消耗） | 连续失败 ≥ `breaker_threshold`（默认 5） |
| HALF_OPEN | 放行 1 次探测请求 | OPEN 后冷却 `breaker_cooldown`（默认 30 分钟）期满 |

### 4.2 规则

- **失败定义**：call_model 内任何异常（网络、超时、5xx、4xx 提供方错误、解析失败）都计入连续失败；**成功（含重试后成功）清零计数器**。
- **隔离粒度**：按模型（如 deepseek-chat）独立熔断，一模型 OPEN 不影响其他模型/栏目。
- **探测**：HALF_OPEN 的 1 次调用成功 → CLOSED（计数清零）；失败 → 回到 OPEN 且冷却期重置（重新计时 30 分钟）。
- **持久化**：内存态 + 落库 `breaker_state`（对 01 §3.3 的 additive 扩展表，变更已记录）：

```sql
CREATE TABLE breaker_state (
  model TEXT PRIMARY KEY,
  state TEXT NOT NULL DEFAULT 'CLOSED',        -- CLOSED|OPEN|HALF_OPEN
  consecutive_failures INTEGER DEFAULT 0,
  opened_at TEXT,                              -- ISO8601（冷却计时基准）
  updated_at TEXT
);
```

### 4.3 接口与决策表

```python
def check(model: str) -> None:          # OPEN → raise BreakerOpen(model)
def record(model: str, ok: bool) -> None
# 调度层 02 §7 #9：生成前 check()；生成中由 call_model 兜底
```

熔断与重试的关系：先重试（2 次退避）后计失败；连续 5 个任务级失败才 OPEN（避免单次抖动误伤）。WP /health 输出各模型 `breaker: "CLOSED|OPEN|HALF_OPEN"` 供后台展示。

## 5. 密钥管理规范

### 5.1 存储与流转

| 密钥 | 存储位置 | 流转路径 |
|------|----------|----------|
| DeepSeek key（主题） | WP option `qy_ai_api_key` | 插件 /health（回环）→ backend 内存缓存（每小时刷新，**不落盘不落库**） |
| DeepSeek key（自身） | WP option `abp_settings.abp_deepseek_api_key`（password 字段） | 同上 |
| 生图 key | WP option `abp_image_key` | 同上 |
| backend 自有 key（可选） | `backend/config.yaml`（chmod 600）或 env `ABP_*_KEY` | config.py 加载，env > yaml > WP 同步 |

**红线**（开发原则 2）：
- key 永不进：git、SQLite、日志、错误信息、REST 响应（非回环）、JS/前端、prompt 文本。
- 探测优先级由插件保证（05 §4），backend 不做二次存储。
- /health 明文 key 仅限回环地址（REMOTE_ADDR ∈ 127.0.0.1/::1）+ Bearer token；非回环一律返回 `has_deepseek_api_key: bool`（05 §4 abp_redact_keys）。
- 服务绑定：FastAPI 仅监听 `127.0.0.1:8080`（原则 7），不对外暴露；WP 插件 `abp_allow_remote` 默认关（05 §2.5）。
- 轮换：后台「重新生成」key 时，同步要求更新 backend config/env，两端失效期 ≤ 1 小时（缓存 TTL）；旧 key 不保留。

### 5.2 脱敏函数（两侧一致）

```python
def redact(text: str) -> str:
    text = re.sub(r'sk-[A-Za-z0-9_-]{8,}', 'sk-***', text)          # API key
    text = re.sub(r'Bearer [A-Za-z0-9._-]+', 'Bearer ***', text)    # 认证头
    text = re.sub(r'data:image/[^;,]+;base64,[A-Za-z0-9+/=]{50,}', 'data:image/***', text)  # 大图
    return text
```

PHP 侧 `abp_redact_keys()` 实现同一套正则（用于 wp_abp_log.error 落库前）。

## 6. 日志脱敏规则

### 6.1 结构化日志（core/logger.py，JSON Lines）

字段白名单：`ts, level, task_id, event, model, provider, tokens, latency_ms, error, step, column`。**error 一律过 `redact()`**；禁止字段：api_key、Bearer 原文、完整 prompt、完整正文。

| 日志内容 | 处理 |
|----------|------|
| prompt/正文 | 仅 DEBUG 级记录，截断 100 字，且过 redact() |
| 模型响应原文 | 不记录（只记 token 数与耗时） |
| task 全文 | 不记录；落库的 tasks 表字段不含 key（schema 天然保证） |
| 图片 base64 | 不记录（见 redact 正则） |

### 6.2 存储与访问

- Python：`data/logs/`（目录 chmod 600）RotatingFileHandler 按日轮转，保留 30 天；FastAPI 不挂静态目录，日志不可经 HTTP 读取。
- WP：wp_abp_log.error 落库前 `abp_redact_keys()`；后台日志页仅管理员可见（current_user_can('manage_options')）。
- 告警：熔断 OPEN、连续发布失败 ≥3、当日额度用尽 → 结构化事件 `alert` 级（v1 落文件 + 可选邮件/Webhook 钩子，接口预留 `on_alert(event: dict)`）。

### 6.3 审计项

每日汇总写 `data/logs/audit-YYYY-MM-DD.log`：任务数、成功/失败/跳过计数、token 消耗、熔断事件、敏感词命中数 —— 供月度成本与质量复盘。

## 7. 可测试性

- `test_risk.py`：词表加载/重载、三道过滤点、命中替换、阈值触发 failed。
- `test_quota.py`：原子记账并发（多线程 UPDATE 不丢）、日切换、预检拦截 0 模型调用。
- `test_breaker.py`：CLOSED→OPEN→HALF_OPEN→CLOSED 全路径、冷却重置、per-model 隔离、持久化重启恢复。
- `test_redact.py`：key/Bearer/base64 全形态脱敏，断言日志无泄漏（扫描 logger 输出）。
