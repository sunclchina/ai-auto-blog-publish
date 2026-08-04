# -*- coding: utf-8 -*-
"""
agents/base.py — 智能体基类（B组：AI 生成层）

职责（对照 docs/01-architecture.md §3.4 / §6）：
  1. 统一模型调用 call_llm(messages, model, max_tokens=2048, temperature=0.7) → (text, tokens_used)
  2. OpenAI 兼容 HTTP 调用（httpx POST {base_url}/chat/completions，Authorization: Bearer key）
  3. 支持 DeepSeek（api.deepseek.com）、豆包/通义（base_url 可配）
  4. 错误分类：网络错误 / HTTP错误 / 内容错误
  5. 重试：网络类错误重试 2 次（指数退避 2s/4s）
  6. 失败计数上报（core/risk.py 熔断器）
  7. Token 记账（quota_daily）
  8. dry_run 模式：全程不调真实模型，输出内置 mock 生成器结果并标注 MOCK

密钥规则（原则2）：从 config 读取，禁止硬编码；支持环境变量兜底。
core/ 由并行小组开发，本模块通过 CoreAdapter 适配：
  若 core 模块已就绪则直接使用；未就绪时使用内置降级实现并打 [CORE_FALLBACK] 标记。
"""

import os
import re
import sys
import time
import json
import random
import logging
import datetime as dt

# ---- sys.path 引导：保证 backend/ 根目录在 path 上（支持脚本直跑与包导入） ----
_BACKEND_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _BACKEND_ROOT not in sys.path:
    sys.path.insert(0, _BACKEND_ROOT)

try:
    import httpx
except ImportError:  # pragma: no cover
    httpx = None

logger = logging.getLogger("ablog.agents")

# 模型基址默认值（可被 config 覆盖，密钥绝不写死在这里）
DEFAULT_BASE_URL = "https://api.deepseek.com/v1"   # DeepSeek；豆包/通义可配 base_url 指向各自 OpenAI 兼容端点
DEFAULT_MODEL = "deepseek-chat"

# 栏目 → 默认模型（可被 config.models 覆盖）
COLUMN_DEFAULT_MODELS = {
    "stock": "deepseek-chat",
    "tech": "deepseek-chat",
    "reading": "deepseek-chat",
    "book": "deepseek-chat",
}

# 栏目 → 分类名（WP 侧分类，总纲 §7）
COLUMN_CATEGORIES = {
    "stock": "A股每日复盘",
    "tech": "IT技术笔记",
    "reading": "读书与国学",
    "book": "读书与国学",
    "industry": "行业",
}

# 栏目 → 默认标签
COLUMN_DEFAULT_TAGS = {
    "stock": ["A股", "每日复盘", "股市"],
    "tech": ["IT", "技术笔记", "WordPress"],
    "reading": ["国学", "诗词", "读书"],
    "book": ["读书", "书评", "书单"],
    "industry": ["A股", "行业", "产业链", "上市公司"],
}

PROMPT_VERSION = "v1.0"


# =====================================================================
# 错误分类（网络 / HTTP / 内容）
# =====================================================================
class LLMError(Exception):
    """LLM 调用异常基类。kind ∈ {network, http, content}"""

    def __init__(self, kind, message, status_code=None, model=None):
        super().__init__(message)
        self.kind = kind          # network | http | content
        self.message = message
        self.status_code = status_code
        self.model = model

    @property
    def retryable(self):
        """网络类错误可重试；HTTP 5xx/429 视为可重试；4xx 与内容错误不重试。"""
        if self.kind == "network":
            return True
        if self.kind == "http" and self.status_code is not None:
            return self.status_code in (429, 500, 502, 503, 504)
        return False

    def __repr__(self):
        return f"LLMError(kind={self.kind}, status={self.status_code}, model={self.model}, msg={self.message[:80]})"


def classify_exception(exc, model=None):
    """把任意异常归类为 LLMError。"""
    if isinstance(exc, LLMError):
        return exc
    if isinstance(exc, (httpx.TimeoutException, httpx.ConnectError, httpx.ConnectTimeout,
                        httpx.ReadTimeout, httpx.TransportError, OSError)):
        return LLMError("network", f"网络错误: {exc}", model=model)
    if isinstance(exc, httpx.HTTPStatusError):
        return LLMError("http", f"HTTP {exc.response.status_code}: {exc}", status_code=exc.response.status_code, model=model)
    if isinstance(exc, (json.JSONDecodeError, KeyError, TypeError, ValueError)):
        return LLMError("content", f"响应内容解析失败: {exc}", model=model)
    return LLMError("content", f"未知错误: {exc!r}", model=model)


# =====================================================================
# CoreAdapter：对 core/ 的稳定适配层（core 由并行小组开发）
# =====================================================================
class CoreAdapter:
    """统一封装 core/* 依赖（core 由并行小组开发，本类按实际落库 API 适配）。

    实际 core API（backend/core/*.py）：
      - risk.get_breaker().record_failure(model) / record_success(model) / is_open(model)
      - risk.is_blacklisted(text, kind=None) -> (bool, hits)
      - risk.check_token_quota(tokens, day=None) -> (bool, remaining)
      - risk.add_token_usage(tokens, day=None) / get_daily_quota(day)
      - fingerprint.simhash/fingerprint_hex/hamming_distance/is_duplicate(a, b)
      - db.query / db.execute（fingerprints 表比对、written_books 表读写）
      - seo.keyword_density(text, keyword) -> 百分比；density_ok(d, min, max)
      - seo.generate_meta_description(title, excerpt, keywords, max_len)
      - seo.extract_long_tail(text, seed) -> list[str]
    若 core 未就绪/缺失函数，则用内置降级实现（[CORE_FALLBACK]），并在日志标注。
    """

    def __init__(self, core=None):
        self.core = core  # dict{risk,db,fingerprint,seo} 或 None
        self._detect()
        self._local_failures = {}
        self._local_fail_at = {}
        self._local_tokens = {}
        self.core_ready = core is not None

    # ---- 探测 ----
    def _mod(self, name):
        if self.core is None:
            return None
        return self.core.get(name) if isinstance(self.core, dict) else getattr(self.core, name, None)

    def _detect(self):
        risk = self._mod("risk")
        db = self._mod("db")
        fp = self._mod("fingerprint")
        seo = self._mod("seo")
        # 熔断器
        breaker = None
        if risk is not None and callable(getattr(risk, "get_breaker", None)):
            try:
                breaker = risk.get_breaker()
            except Exception as e:
                logger.warning("core.risk.get_breaker 异常: %s", e)
        self.breaker = breaker
        self.risk_blacklist = getattr(risk, "is_blacklisted", None) if risk else None
        self.risk_quota_ok = getattr(risk, "check_token_quota", None) if risk else None
        self.risk_quota_add = getattr(risk, "add_token_usage", None) if risk else None
        self.risk_quota_get = getattr(risk, "get_daily_quota", None) if risk else None
        self.db_query = getattr(db, "query", None) if db else None
        self.db_execute = getattr(db, "execute", None) if db else None
        self.fp_hex = getattr(fp, "fingerprint_hex", None) if fp else None
        self.fp_dist = getattr(fp, "hamming_distance", None) if fp else None
        self.seo_density = getattr(seo, "keyword_density", None) if seo else None
        self.seo_meta = getattr(seo, "generate_meta_description", None) if seo else None
        self.seo_longtail = getattr(seo, "extract_long_tail", None) if seo else None
        self._risk_fallback = self.breaker is None
        self._quota_fallback = self.risk_quota_add is None

    # ---- 熔断上报 ----
    def report_failure(self, model, error_msg):
        if self.breaker is not None:
            try:
                self.breaker.record_failure(model)
                return
            except Exception as e:
                logger.warning("core.risk 熔断上报异常，降级本地记录: %s", e)
        self._local_failures[model] = self._local_failures.get(model, 0) + 1
        self._local_fail_at.setdefault(model, time.time())

    def report_success(self, model):
        if self.breaker is not None:
            try:
                self.breaker.record_success(model)
                return
            except Exception as e:
                logger.warning("core.risk 熔断复位异常: %s", e)
        self._local_failures[model] = 0

    def circuit_is_open(self, model):
        if self.breaker is not None:
            try:
                return bool(self.breaker.is_open(model))
            except Exception as e:
                logger.warning("core.risk.circuit_is_open 异常，视为未熔断: %s", e)
        # 本地降级熔断：连续 5 次失败 -> 开 30 分钟（对齐总纲 搂6）
        n = self._local_failures.get(model, 0)
        if n >= 5:
            last = self._local_fail_at.get(model)
            if last is not None and (time.time() - last) > 1800:
                self._local_failures[model] = 0  # 30 分钟窗口过期，自动复位
                return False
            return True
        return False

    # ---- token 记账 ----
    def quota_add(self, tokens, day=None):
        if self.risk_quota_add:
            try:
                self.risk_quota_add(int(tokens), day)
                return
            except Exception as e:
                logger.warning("core.risk.add_token_usage 异常，降级本地记账: %s", e)
        self._local_tokens[day or dt.date.today().isoformat()] = \
            self._local_tokens.get(day or dt.date.today().isoformat(), 0) + int(tokens)

    def quota_used(self, day=None):
        if self.risk_quota_get:
            try:
                return int(self.risk_quota_get(day).get("tokens_used") or 0)
            except Exception as e:
                logger.warning("core.risk.get_daily_quota 异常，降级本地计数: %s", e)
        return self._local_tokens.get(day or dt.date.today().isoformat(), 0)

    def quota_ok(self, need_tokens, day=None, daily_limit=200000):
        if self.risk_quota_ok:
            try:
                ok, _remaining = self.risk_quota_ok(int(need_tokens), day)
                return bool(ok)
            except Exception as e:
                logger.warning("core.risk.check_token_quota 异常，降级本地校验: %s", e)
        used = self.quota_used(day)
        return (used + int(need_tokens)) <= daily_limit

    # ---- 指纹查重（SimHash 同算法，总纲 搂5） ----
    def fingerprint_check(self, text):
        """正文/选题查重：指纹 hex + 全库汉明距离 < 4。"""
        if self.fp_hex is not None and self.fp_dist is not None and self.db_query is not None:
            try:
                fh = self.fp_hex(text)
                rows = self.db_query("SELECT fhash FROM fingerprints")
                for r in rows:
                    if self.fp_dist(fh, r["fhash"]) < 4:
                        return True
                return False
            except Exception as e:
                logger.warning("core 指纹查重异常，视为未重复: %s", e)
        return False  # [CORE_FALLBACK] 无 core 时不误杀

    def fingerprint_add(self, text, task_id="", title=""):
        """指纹入库（fingerprints 表）。"""
        if self.fp_hex is not None and self.db_execute is not None:
            try:
                fh = self.fp_hex(text)
                import datetime as _dt
                self.db_execute(
                    "INSERT OR IGNORE INTO fingerprints (fhash, task_id, title, column_name, created_at) "
                    "VALUES (?, ?, ?, ?, ?)",
                    (fh, task_id, title, "", _dt.datetime.now().astimezone().isoformat(timespec="seconds")),
                )
                return
            except Exception as e:
                logger.warning("core 指纹入库异常: %s", e)

    # ---- 黑名单 ----
    def is_blacklisted(self, text):
        if self.risk_blacklist:
            try:
                hit, _hits = self.risk_blacklist(text)
                return bool(hit)
            except Exception as e:
                logger.warning("core.risk.is_blacklisted 异常，视为不命中: %s", e)
        return False

    # ---- core/seo.py 工具 ----
    def keyword_density(self, text, keyword):
        """关键词密度（百分比口径，与 core.seo 一致）。"""
        if self.seo_density:
            try:
                return float(self.seo_density(text, keyword))
            except Exception:
                pass
        return _fallback_keyword_density(text, keyword)

    def build_meta(self, title="", excerpt="", keywords=None, max_len=120):
        if self.seo_meta:
            try:
                return str(self.seo_meta(title=title, excerpt=excerpt, keywords=keywords, max_len=max_len))
            except Exception:
                pass
        return _fallback_build_meta(excerpt or title, max_len)

    def longtail_keywords(self, text, seed):
        """从正文提取含种子词的长尾候选。"""
        if self.seo_longtail:
            try:
                return list(self.seo_longtail(text, seed))
            except Exception:
                pass
        return _fallback_longtail(seed)

    def internal_link_placeholder(self, keyword, category=None):
        """内链占位：显示"站内相关：{类别}"（WP 侧发布时替换为真实站内链接）。"""
        return _fallback_internal_link(keyword, category)

    # ---- 书目防重复（written_books 表） ----
    def written_books(self):
        if self.db_query:
            try:
                rows = self.db_query("SELECT book_title FROM written_books")
                return {r["book_title"] for r in rows}
            except Exception as e:
                logger.warning("core.db.written_books 异常: %s", e)
        return set()

    def add_written_book(self, title, task_id=""):
        if self.db_execute:
            try:
                import datetime as _dt
                self.db_execute(
                    "INSERT OR IGNORE INTO written_books (book_title, task_id, created_at) VALUES (?, ?, ?)",
                    (title, task_id, _dt.datetime.now().astimezone().isoformat(timespec="seconds")),
                )
                return
            except Exception as e:
                logger.warning("core.db.add_written_book 异常: %s", e)


def report_failure_with_lockout(adapter, model, err_msg):
    """上报失败并维护本地熔断窗口（配合 CoreAdapter 降级逻辑）。"""
    adapter.report_failure(model, err_msg)
    if not adapter._risk_fallback:
        return
    if adapter._local_failures.get(model, 0) >= 5 and adapter._local_fail_at.get(model) is None:
        adapter._local_fail_at[model] = time.time()


def _fallback_keyword_density(text, keyword, norm=None):
    """[CORE_FALLBACK] 简易关键词密度（百分比口径）。"""
    if not keyword:
        return 0.0
    text2 = "".join(text.split())
    kw2 = "".join(keyword.split())
    if not text2:
        return 0.0
    return text2.count(kw2) / len(text2) * 100


def _fallback_build_meta(text, max_len=120):
    """[CORE_FALLBACK] 取纯文本前 max_len 字作 Meta 描述。"""
    import re
    plain = re.sub(r"<[^>]+>", "", text)
    plain = re.sub(r"\s+", " ", plain).strip()
    return plain[:max_len]


def _fallback_longtail(keyword):
    """[CORE_FALLBACK] 长尾词模板。"""
    return [f"{keyword}教程", f"{keyword}常见问题", f"{keyword}实操指南", f"{keyword}经验分享"]


def _fallback_internal_link(keyword, category=None):
    """[CORE_FALLBACK] 内链占位：显示"站内相关：{类别}"（无类别时退化为关键词），WP 侧发布时替换为真实站内链接。"""
    label = (category or keyword or "").strip()
    return f'<a href="/?s={keyword}" title="站内相关：{label}">站内相关：{label}</a>'


# =====================================================================
# dry_run Mock：内置模拟生成器（原则2：输出必须带 MOCK 标注）
# =====================================================================
class MockLLM:
    """dry_run 模式的模型替身：不产生任何网络调用，输出确定性伪内容并标注 MOCK。

    设计要点：mock 输出结构必须能被各 Step 的真实解析器解析（用于验证流水线结构），
    但内容明确标注 MOCK，绝不冒充真实模型结果（原则2）。
    """

    MOCK_TAG = "【MOCK】"
    _titles = {
        "stock": ["A股缩量震荡蓄势，后市关注哪些关键信号", "三大指数收盘涨跌互现，资金面释放新动向", "大盘回踩支撑后企稳，盘面风格切换进行时"],
        "tech": ["Nginx 反向代理配置实战：从入门到常见报错排查", "WordPress 提速优化完整指南：缓存与 CDN 双管齐下", "服务器安全加固清单：SSH 与防火墙配置详解"],
        "reading": ["读《静夜思》：举头望月，低头思乡的千年共鸣", "《论语》学而篇新解：君子务本的人生智慧", "宋词里的秋：一叶落而知天下秋"],
        "book": ["读《股票大作手回忆录》：投机之王留给普通人的四堂课", "《曾国藩》三部曲书评：拙诚二字，足以立身", "《失控》读书笔记：机器与生物的新生物文明"],
    }
    _topic_seeds = {
        "stock": ["大盘量价变化与后市观察要点", "板块轮动与资金流向解读", "市场情绪修复的关键信号"],
        "tech": ["高频问题：配置报错排查实战", "高频问题：性能优化实操指南", "高频问题：安全加固清单"],
        "reading": ["所选诗词的意境解读与当下共鸣", "所选诗词背后的创作背景与人生况味", "所选诗词的经典名句赏析"],
        "book": ["所选书目的核心观点拆解", "所选书目的名句与启示", "所选书目适合谁读、怎么读"],
    }

    def __init__(self, config=None):
        self.config = config or {}
        self.rng = random.Random(20260803)  # 固定种子，dry_run 输出可复现

    def chat(self, messages, model=None, max_tokens=2048, temperature=0.7):
        """返回 (text, tokens_used)，text 必含 MOCK 标注。"""
        last = messages[-1]["content"] if messages else ""
        role = self._role_of(last)
        text = self._render(role, last)
        tokens = max(8, len(text) // 2)
        return text, tokens

    @staticmethod
    def _role_of(prompt):
        """按提示词中最特定指令判断步骤（避免“选题：”等公共字样误判）。

        判定优先级（更特定 → 更通用）：
        润色 > SEO > 大纲 > 标题 > 选题 > 正文 > 通用
        """
        if any(k in prompt for k in ("去AI化润色", "润色后的 HTML", "只输出润色后的")):
            return "润色"
        if any(k in prompt for k in ("SEO 元数据", "meta_description", "Meta 描述")):
            return "SEO"
        if any(k in prompt for k in ("固定章节", "输出大纲", "仅输出 JSON")):
            return "大纲"
        if any(k in prompt for k in ("3-5 条 SEO 标题", "每行一条标题")):
            return "标题"
        if any(k in prompt for k in ("3-5 个选题候选", "选题要求")):
            return "选题"
        if any(k in prompt for k in ("逐节填充", "写作要求", "扩写重写")):
            return "正文"
        return "通用"

    def _column_of(self, prompt):
        m = re.search(r"栏目[:：]\s*([a-z]+)", prompt)
        return m.group(1) if m else "stock"

    def _topic_of(self, prompt):
        m = re.search(r"选题[:：]\s*([^\n]+)", prompt)
        return m.group(1).strip()[:30] if m else "选题"

    def _sections_of(self, prompt):
        """从大纲提示词中提取固定章节名列表（JSON 数组）。"""
        m = re.search(r"\[[\"\'][^\[\]]*[\"\']]\]", prompt)
        if not m:
            m = re.search(r"(?<=\n)\[.*?\](?=\n|$)", prompt, re.S)
        if m:
            try:
                data = json.loads(m.group(0))
                if isinstance(data, list):
                    return [str(x) for x in data]
            except Exception:
                pass
        return ["背景", "主体", "总结"]

    def _render(self, role, prompt):
        tag = self.MOCK_TAG
        col = self._column_of(prompt)
        topic = self._topic_of(prompt)
        if role == "选题":
            seeds = self._topic_seeds.get(col, self._topic_seeds["stock"])
            lines = []
            for i, s in enumerate(seeds, 1):
                lines.append(f"{i}. {topic}·{s}\n   理由：dry_run 模拟理由（结合素材、差异化角度），非真实模型输出")
            return tag + " 选题候选（dry_run 模拟，未调用真实模型）：\n" + "\n".join(lines)
        if role == "标题":
            titles = self._titles.get(col, self._titles["stock"])
            return tag + " 标题候选（dry_run 模拟）：\n" + "\n".join(f"- {t}" for t in titles)
        if role == "大纲":
            secs = self._sections_of(prompt)
            items = [{"section": s, "points": [f"模拟要点1：{s}相关铺垫（MOCK）", f"模拟要点2：{s}核心内容（MOCK）"]} for s in secs]
            return tag + " 大纲（dry_run 模拟）：\n" + json.dumps(items, ensure_ascii=False)
        if role == "正文":
            return (tag + " 正文（dry_run 模拟，非真实内容）：\n"
                    "<h2>一、背景</h2>\n<p>这是 dry_run 模式生成的模拟段落，仅用于验证流水线结构与输出格式，不代表真实内容。</p>\n"
                    "<h2>二、主体</h2>\n<p>模拟段落二：此处应当由真实模型按栏目规范填充正文。</p>\n"
                    "<blockquote>模拟引用：用于验证 blockquote 样式渲染。</blockquote>\n"
                    "<h2>三、总结</h2>\n<p>模拟总结段落：dry_run 全程未调用任何真实模型，不消耗 Token。</p>")
        if role == "润色":
            return tag + " 已执行去AI润色（dry_run 模拟）：句式重组+同义词替换+插入个人视角，MOCK 标注保留。"
        if role == "SEO":
            return (tag + " SEO 优化（dry_run 模拟）：\n"
                    '{"meta_description": "这是模拟生成的 Meta 描述，长度符合要求", '
                    '"keywords": ["关键词A","关键词B"], "longtail": ["关键词A教程","关键词A常见问题"], '
                    '"tags": ["标签1","标签2"]}')
        return tag + " dry_run 模拟输出，未调用真实模型。"


# =====================================================================
# 配置与客户端构建
# =====================================================================
def cfg_get(config, dotted, default=None):
    """统一配置读取：兼容项目 Config 对象（点路径）与普通 dict（嵌套点路径）。"""
    if config is None:
        return default
    if hasattr(config, "_data"):          # 项目 config.Config（点路径访问）
        try:
            return config.get(dotted, default)
        except Exception:
            return default
    node = config
    for part in dotted.split("."):
        if isinstance(node, dict) and part in node:
            node = node[part]
        else:
            return default
    return node


def resolve_api_key(config):
    """密钥解析：项目配置 models.api_key（config.yaml+env+secret 文件）→ llm.api_key → 环境变量。
    禁止硬编码（原则2）。"""
    key = cfg_get(config, "models.api_key", "") or cfg_get(config, "llm.api_key", "") \
        or cfg_get(config, "deepseek_api_key", "") \
        or os.environ.get("ABLOG_LLM_API_KEY") or os.environ.get("DEEPSEEK_API_KEY") \
        or os.environ.get("ABP_MODEL_API_KEY")
    return (key or "").strip()


def resolve_base_url(config):
    """模型端点：config.models.api_base 可能为完整 URL（含 /chat/completions）或 base_url。"""
    url = (cfg_get(config, "models.api_base", "") or cfg_get(config, "llm.base_url", "") or DEFAULT_BASE_URL).rstrip("/")
    return url


def chat_completions_url(base_url):
    """拼 /chat/completions；已带则原样返回。"""
    if base_url.endswith("/chat/completions"):
        return base_url
    return f"{base_url}/chat/completions"


def model_for_column(config, column):
    models = cfg_get(config, f"models.mapping.{column}", "") or cfg_get(config, f"models.{column}", "")
    return models or COLUMN_DEFAULT_MODELS.get(column, DEFAULT_MODEL)


def model_default(config):
    return cfg_get(config, "models.default", "") or DEFAULT_MODEL


def prompts_dir(config):
    return cfg_get(config, "prompts_dir", "") or None


def build_core_adapter(config=None, core=None):
    """构造 CoreAdapter：优先使用调用方传入的 core 模块集；否则尝试真实 core 包。"""
    if core is not None:
        return CoreAdapter(core)
    try:
        import core.risk
        import core.db
        import core.fingerprint
        import core.seo
        core_mod = {"risk": core.risk, "db": core.db, "fingerprint": core.fingerprint, "seo": core.seo}
        return CoreAdapter(core_mod)
    except Exception:
        logger.info("[CORE_FALLBACK] core 包未就绪，智能体使用内置降级实现（熔断/记账/查重/SEO 工具）")
        return CoreAdapter(None)


# =====================================================================
# BaseAgent
# =====================================================================
class BaseAgent:
    """智能体基类：所有 Step 智能体继承。

    构造：BaseAgent(config, core=None, dry_run=False)
      - config: dict，含 llm/models/prompts_dir 等配置（密钥不硬编码）
      - core: CoreAdapter 实例或 core 模块集（None 时自动构建）
      - dry_run: True 时 call_llm 走 MockLLM，不产生真实调用
    """

    step = 0
    step_name = "base"

    def __init__(self, config=None, core=None, dry_run=False):
        self.config = config or {}
        self.dry_run = bool(dry_run)
        self.core = core if isinstance(core, CoreAdapter) else build_core_adapter(self.config, core)
        self.mock = MockLLM(self.config)
        self.base_url = resolve_base_url(self.config)
        self.api_key = resolve_api_key(self.config)
        self.model = model_default(self.config)
        self._http = None

    # ---- 模型调用 ----
    def call_llm(self, messages, model=None, max_tokens=2048, temperature=0.7):
        """统一模型调用 → (text, tokens_used)。

        错误分类：network/http/content；网络类重试 2 次（指数退避 2s/4s）；
        失败计数上报 core/risk 熔断器；成功后 token 记账 quota_daily。
        """
        model = model or self.model
        if self.dry_run:
            text, tokens = self.mock.chat(messages, model=model, max_tokens=max_tokens, temperature=temperature)
            return text, tokens

        if not self.api_key:
            raise LLMError("content", "未配置 LLM API Key（config.llm.api_key / 环境变量 ABLOG_LLM_API_KEY）", model=model)

        if self.core.circuit_is_open(model):
            raise LLMError("http", f"模型 {model} 熔断中（连续失败过多），任务拦截", status_code=503, model=model)

        max_attempts = 3  # 1 次 + 2 次重试
        last_err = None
        for attempt in range(1, max_attempts + 1):
            try:
                text, tokens = self._post_chat(messages, model, max_tokens, temperature)
                # token 记账 + 熔断成功复位
                self.core.quota_add(tokens)
                self.core.report_success(model)
                return text, tokens
            except LLMError as e:
                last_err = e
                self.core.report_failure(model, str(e)[:200])
                if not e.retryable or attempt >= max_attempts:
                    break
                backoff = 2 ** (attempt - 1)  # 2s, 4s
                logger.warning("LLM 调用第 %d 次失败(%s)，%.0fs 后重试: %s", attempt, e.kind, backoff, e)
                time.sleep(backoff)
            except Exception as e:
                last_err = classify_exception(e, model)
                self.core.report_failure(model, str(e)[:200])
                if not last_err.retryable or attempt >= max_attempts:
                    break
                time.sleep(2 ** (attempt - 1))
        raise last_err if last_err else LLMError("content", "LLM 调用失败", model=model)

    def _post_chat(self, messages, model, max_tokens, temperature):
        """OpenAI 兼容 /chat/completions 请求。"""
        if httpx is None:
            raise LLMError("content", "缺少 httpx 依赖，无法调用模型", model=model)
        client = httpx.Client(timeout=120)
        try:
            url = chat_completions_url(self.base_url)
            resp = client.post(
                url,
                headers={"Authorization": f"Bearer {self.api_key}", "Content-Type": "application/json"},
                json={
                    "model": model,
                    "messages": messages,
                    "max_tokens": max_tokens,
                    "temperature": temperature,
                    # V4 直接调用默认开思考模式（content 为空、思考在 reasoning_content），
                    # 显式关闭以保持与旧 deepseek-chat 行为一致（content 直接输出正文）
                    "thinking": {"type": "disabled"},
                },
            )
            resp.raise_for_status()
            data = resp.json()
            if "choices" not in data or not data["choices"]:
                raise LLMError("content", f"响应缺少 choices: {str(data)[:200]}", model=model)
            text = data["choices"][0].get("message", {}).get("content", "").strip()
            if not text:
                # 兜底：思考模式下 content 可能为空，取 reasoning_content 尾部（不理想但可用）
                text = data["choices"][0].get("message", {}).get("reasoning_content", "").strip()
            usage = data.get("usage") or {}
            tokens = int(usage.get("total_tokens") or 0) or (len(text) // 2 + 1)
            return text, tokens
        except httpx.HTTPStatusError as e:
            raise classify_exception(e, model)
        except (httpx.TimeoutException, httpx.TransportError, OSError) as e:
            raise classify_exception(e, model)
        except (json.JSONDecodeError, KeyError, TypeError, ValueError) as e:
            raise classify_exception(e, model)
        finally:
            client.close()

    # ---- 便捷工具 ----
    def load_prompt(self, name):
        """从 prompts 目录读取固定 Prompt 规范（backend/prompts/*.md）。"""
        base = prompts_dir(self.config)
        if not base:
            base = os.path.join(_BACKEND_ROOT, "prompts")
        path = os.path.join(base, name)
        if os.path.exists(path):
            with open(path, "r", encoding="utf-8") as f:
                return f.read()
        return ""

    def strip_html(self, html):
        import re
        return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", "", html)).strip()

    def zh_len(self, text):
        """统计中文字符数（长度验收口径）。"""
        return sum(1 for ch in text if "\u4e00" <= ch <= "\u9fff")

# ---- 栏目解析（翁老：备用选题栏目从现有文章分类中选，支持中文分类名）----

COLUMN_ALIASES = {
    "stock": "stock", "a股复盘": "stock", "a股每日复盘": "stock", "股市": "stock", "股票": "stock", "复盘": "stock",
    "tech": "tech", "it技术": "tech", "it技术笔记": "tech", "it": "tech",
    "reading": "reading", "国学": "reading", "诗词": "reading",
    "book": "book", "书评": "book", "读书": "book", "读后感": "book",
    "industry": "industry", "行业": "industry", "行业综述": "industry",
}


def resolve_column(raw: str) -> str:
    """把栏目原始值（code 或中文分类名）解析为流水线 code；无法识别返回 generic。"""
    key = str(raw or "").strip().lower()
    if not key:
        return "generic"
    return COLUMN_ALIASES.get(key, "generic")
