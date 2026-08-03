# A-Blog 设计文档 · 03 AI 生成层（多智能体流水线）

> 依据：`01-architecture.md` v1.0｜版本：v1.0（2026-08-03）｜模块：`backend/agents/`（base.py + topic/title/outline/content/humanize/seo/image.py + pipeline.py）

---

## 1. 职责与边界

生成层把"一篇成品文章"拆成 7 个可独立测试的智能体步骤，由 `pipeline.py` 编排。只产 JSON 与图片文件，不碰调度（02）与发布（04）。所有模型调用统一走 `base.py` 的 `call_model()` —— 重试、熔断、Token 记账、日志脱敏在此收敛，**禁止智能体自行发 HTTP**。

## 2. base.py：智能体基类

### 2.1 核心接口（唯一模型调用入口）

```python
@dataclass
class ModelResult:
    text: str                  # 模型返回文本（已 strip）
    model: str                 # 实际模型名（如 deepseek-chat）
    provider: str              # deepseek | doubao | qwen
    prompt_tokens: int
    completion_tokens: int
    total_tokens: int
    latency_ms: int
    raw: dict | None = None    # 原始响应（脱敏后入库，日志不落）

class Agent(ABC):
    name: str                                            # "topic"/"title"/...
    step: int                                            # 1..7 流水线序号

    def call_model(self, messages: list[dict], *,
                   model: str | None = None,             # None → 按模型分发表取默认
                   max_tokens: int = 2048,
                   temperature: float = 0.7,
                   task: dict | None = None) -> ModelResult:
        """唯一模型入口：熔断检查→分发→(网络/5xx 重试 2 次指数退避)→Token 记账→脱敏日志"""
        breaker.check(model)                              # OPEN → raise BreakerOpen
        for attempt in range(3):                          # 0,1,2 共 2 次重试
            try:
                r = provider_factory(cfg).chat(messages, model=model,
                                               max_tokens=max_tokens,
                                               temperature=temperature)
                break
            except (NetworkError, HTTP5xxError) as e:
                if attempt < 2: time.sleep(1 * 2 ** attempt); continue
                raise ModelCallFailed(model=model, last_error=e)
        quota.account(task, r.total_tokens)               # 06 §3，原子记账
        logger.event("model_call", task_id=task.get("task_id"), model=model,
                     tokens=r.total_tokens, latency_ms=r.latency_ms)   # 脱敏，见 06 §6
        return r

    @abstractmethod
    def run(self, task: dict) -> dict:
        """子类实现：读 task 输入 → 拼 prompt → call_model → 解析/校验 → 返回要合并进 task 的字段
        失败时 raise StepError(step=self.name, fatal=True|False, degrade=None|...)"""
```

失败分类（全局约定，供 pipeline 决策）：

| 异常类型 | 含义 | 触发 |
|----------|------|------|
| RetryableError（NetworkError/Timeout/HTTP5xx） | call_model 内已重试 2 次，仍失败 | 网络/服务端 |
| StepFatalError | 内容类失败，重试无意义 | 输出空、解析失败、质量校验不达标 |
| BreakerOpen | 熔断中 | 直接失败不消耗 Token |

### 2.2 子类契约

- `run()` 返回的 dict 只会把**自身产出字段**合并进 task（如 title 返回 `{"title_candidates": [...], "final_title": "..."}`）。
- 每个智能体的 prompt 必须引用固定 Prompt 文件（`prompts/stock.md / tech.md / reading.md`），含 `prompt_version` 写进 task.meta.source（总纲 3.1）。
- 校验规则不达标 → 抛 StepFatalError（内容类不重试或按 §6 中断点表执行）。

## 3. 模型提供方适配层（backend/agents/providers/）

三家模型全部走 **OpenAI 兼容 chat/completions** 接口，统一 `Provider.chat(messages, model, max_tokens, temperature) -> ModelResult`：

| provider | 基址 | 默认模型 | 配置键（env 优先） |
|----------|------|----------|---------------------|
| deepseek | `https://api.deepseek.com` | deepseek-chat | `ABP_DEEPSEEK_API_KEY` |
| doubao（豆包） | `https://ark.cn-beijing.volces.com/api/v3` | doubao-pro-32k | `ABP_DOUBAO_API_KEY` |
| qwen（通义） | `https://dashscope.aliyuncs.com/compatible-mode/v1` | qwen-plus | `ABP_QWEN_API_KEY` |

key 解析顺序：环境变量 → `backend/config.yaml`（chmod 600）→ WP /health 同步（回环，见 06 §5）。**key 永不落库、永不进日志**。

## 4. 模型分发规则表（栏目 → 步骤 → 模型 → 配置项）

优先级：任务级显式 model > 栏目级（WP 契约 3.4 `models.stock/tech/reading`）> 步骤默认。WP 配置项名与后端 config 映射：

```
models.stock  → 栏目 stock 的默认模型（如 deepseek-chat）
models.tech   → 栏目 tech 的默认模型
models.reading→ 栏目 reading 与 book 共用
models.image  → 配图模型（DALL·E3 / 开源，见 §7）
```

| 栏目 | 步骤 | 默认模型 | max_tokens | temperature | 说明 |
|------|------|----------|-----------|-------------|------|
| 全栏目 | 1 topic | models.<col> | 512 | 0.8 | 输出选题，须带素材引用 |
| 全栏目 | 2 title | models.<col> | 512 | 0.9 | 3-5 候选择优 |
| 全栏目 | 3 outline | models.<col> | 1024 | 0.6 | 分栏目模板强制结构 |
| stock | 4 content | models.stock | 4096 | 0.7 | 复盘文：数据强约束 |
| tech | 4 content | models.tech | 4096 | 0.7 | 技术文：代码块完整 |
| reading/book | 4 content | models.reading | 3072 | 0.6 | 原文释义 + 个人解读 |
| 全栏目 | 5 humanize | models.<col> | 2048 | 0.9 | 句式重组 |
| 全栏目 | 6 seo | models.<col> | 1024 | 0.5 | Meta/标签/内链 |
| 全栏目 | 7 image | models.image | — | — | 不走 chat 接口，见 §7 |

以上每格均可被 `config.yaml agents.<step>.{model,max_tokens,temperature}` 覆盖；DeepSeek 单 key 多模型靠同一 key + 不同 `model` 字段实现（总纲 3.4）。

## 5. 七智能体输入/输出规格

| # | 智能体 | 输入（task 字段 + 采集数据） | 输出（合并字段） | 质量校验 | 失败语义 |
|---|--------|------------------------------|------------------|----------|----------|
| 1 | topic | column, subtype, date, 素材（market 数据/tech 热点/国学素材/书目库）、blacklist、written_books | `topic`(≤60字), `source_refs` | 非空、黑名单、与近期标题相似度 <0.7 | 致命：整任务 skipped |
| 2 | title | column, topic | `title_candidates[3-5]{title,reason}`, `final_title`, `title_score` | 长度 10-30、候选互异、含主题词 | 重试 2 次 → failed |
| 3 | outline | column, topic, final_title | `outline`(JSON，按栏目模板：stock=数据段/盘面/板块/情绪/观点；tech=问题/方案/代码/实践；reading=原文/释义/解读/延伸) | ≥3 节、每节有主题句 | 重试 2 次 → failed |
| 4 | content | column, outline, 素材 | `content`(HTML: h2/h3/p/blockquote/pre/code/<strong>)、`excerpt` | 字数 ≥800（stock ≥1500 且含关键数据）；h2/h3 结构；pre/code 闭合 | 网络重试 2 次；质量不达标重试 1 次 → failed |
| 5 | humanize | content | `content`(覆盖), `meta.humanize_failed` | 输出非空 | 重试 2 次 → **降级用原稿**（不致命） |
| 6 | seo | content, final_title | `meta_description`(≤120字), `tags`(≤5), `seo_report`(密度/长尾词/内链建议) | meta 非空 | 重试 2 次 → **降级自动生成** meta/tags（不致命） |
| 7 | image | column, final_title, content 前 200 字 | `featured_image`(本地 WebP 路径) | 文件存在且 >10KB | 重试 1 次 → **降级 None 纯文字**（不致命） |

降级路径必须写 `task.meta.humanize_failed/image_failed/seo_degraded=true` 留痕，发布不受影响（总纲 1.2 第 7 步允许无图）。

## 6. pipeline.py：多智能体编排器

### 6.1 顺序与状态落库

```python
STEPS = [TopicAgent, TitleAgent, OutlineAgent, ContentAgent, HumanizeAgent, SeoAgent, ImageAgent]

def run(task: dict) -> None:
    for cls in STEPS:
        if step_done(task, cls.step):            # 崩溃恢复：按 tasks.step + 产出字段判跳过
            continue
        agent = cls(cfg)
        try:
            out = agent.run(task)
        except BreakerOpen:
            db.set_task(task["task_id"], status="skipped", reason="breaker_open"); return
        except StepFatalError as e:
            finish_failure(task, e)              # §6.2 中断点表决定 failed/skipped
            return
        task.update(out)
        db.update_task(task, step=agent.name)    # 每步落库，崩溃可续
    task = final_validation(task)                # 终稿校验：敏感词/全文指纹（06 §2 / 01 §5.4）
    db.set_task(task["task_id"], status="ready", step="done")
```

### 6.2 失败中断点表（哪步可重试 / 哪步整任务跳过）

| 步骤 | 可重试 | 重试耗尽后任务去向 | 是否整任务跳过 |
|------|--------|--------------------|----------------|
| 1 topic | 否（选题失败无意义） | **skipped**（topic_failed） | 是（skipped） |
| 2 title | 是（2 次） | **failed**（可人工重试） | 是（failed） |
| 3 outline | 是（2 次） | **failed** | 是（failed） |
| 4 content | 网络 2 次 + 质量 1 次 | **failed** | 是（failed） |
| 5 humanize | 是（2 次） | **降级**：原稿发布，meta.humanize_failed=true | 否 |
| 6 seo | 是（2 次） | **降级**：自动 meta/tags | 否 |
| 7 image | 是（1 次） | **降级**：featured_image=None 纯文字 | 否 |
| 终稿校验 | — | 敏感词超阈值 → failed(risk)；指纹重复 → **skipped(duplicate)** | 是 |

原则：**内容主干（1-4）失败才终止任务；锦上添花步骤（5-7）一律降级不终止**。pipeline 本身不发布文章，只把任务推到 ready。

### 6.3 崩溃恢复

`step_done(task, n)`：`tasks.step` 记录已完成步骤名；进程重启后从该步骤之后继续（步骤间无状态依赖，重跑安全）。任务卡在 generating 超过 30 分钟 → 由调度层置 failed(stale) 并告警。

## 7. 配图智能体与 ImageProvider 抽象

### 7.1 统一接口（models.image 未配置 → NullProvider → None → 纯文字）

```python
@dataclass
class ImageResult:
    path: str                 # 本地文件（1280×720 WebP）
    provider: str             # dalle3 | openai-compatible | none
    cost: float | None

class ImageProvider(ABC):
    name: str
    @abstractmethod
    def generate(self, prompt: str, size: tuple[int, int] = (1280, 720)) -> ImageResult | None: ...

class Dalle3Provider(ImageProvider):      # OpenAI images API（gpt-image-1 / dall-e-3）
class OpenSourceProvider(ImageProvider):  # 兼容 OpenAI images 协议的本地/云开源接口（SD WebUI / Flux / 硅基流动）
class NullProvider(ImageProvider):        # generate() -> None

def get_image_provider(cfg) -> ImageProvider:
    """工厂：配图总开关关 或 image_api.provider 为空 → NullProvider"""
    return registry[cfg.image_api.provider](cfg.image_api) if cfg.image_api.provider else NullProvider()
```

### 7.2 ImageAgent.run 流程

1. 读 `models.image` 配置（来自插件 `image_api` 对象或 config.yaml），取 NullProvider → 直接返回 `{"featured_image": None}`（0 消耗）。
2. 按栏目拼英文 prompt 模板（中文进 DALL·E 易出乱码）：`{style} of {subject}, no text, no watermark`；style 映射：tech=扁平插画/flat illustration、stock=简洁数据风/abstract data chart、reading/book=水墨/ink wash 或抽象书封。
3. `provider.generate(prompt)` → 本地临时 WebP → `publishers/image.py` 统一压缩裁切为 1280×720（quality 82，≤1.5MB）。
4. 失败（超时/HTTP 错误/文件无效）→ 重试 1 次 → 仍失败返回 None 并写 meta.image_failed。
5. 产物只落到本地 `data/images/`，由发布层 04 §4 转 base64 上传，pipeline 不直接调 WP。

## 8. 记账与 source 元数据

- 每次成功 call_model 立即 `quota.account()`（06 §3）；`tokens_used` 累加到 tasks 行。
- task.meta.source = `{"model": 实际模型, "provider": ..., "prompt_version": "v1.0"}`，随任务 JSON 下发 WP（总纲 3.1 source 字段），插件写入 wp_abp_log.model 便于审计。

## 9. 可测试性

- `test_agents.py`：7 个智能体各自输入→输出契约（mock provider 返回固定文本），校验规则逐条断言。
- `test_pipeline.py`：每个中断点注入失败 → 断言任务最终状态与降级标记；崩溃恢复（模拟 step 中断后重跑）。
- `test_image.py`：NullProvider/Dalle3/开源 provider 工厂选择、降级路径、格式尺寸校验。
- 全部模型调用 mock，测试 0 真实 Token 消耗（开发原则 2）。
