# -*- coding: utf-8 -*-
"""
agents/pipeline.py — 流水线编排器（Step1-7）

顺序执行 7 步，任务对象流转（docs/01-architecture.md §3.1）：
  Step1 选题 → Step2 标题 → Step3 大纲 → Step4 正文 → Step5 去AI润色 → Step6 SEO → Step7 配图

失败中断策略（对齐总纲 §6 与任务要求）：
  - Step1/2/3 失败 → 整任务 skipped（不消耗后续 Token）
  - Step4/5/6 失败 → 重试 1 次 → 仍失败则任务 failed
  - Step7 失败 → 降级纯文字继续（featured_image=None）

dry_run：全程不调真实模型（内置 MockLLM），所有输出带 MOCK 标注（原则2）；
        配图步骤在 dry_run 下直接跳过。
"""

import os
import sys
import json
import logging
import re
import datetime as dt

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agents.base import (  # noqa: E402
    CoreAdapter, LLMError, build_core_adapter, model_for_column,
    COLUMN_CATEGORIES, COLUMN_DEFAULT_TAGS, PROMPT_VERSION,
    resolve_column, COLUMN_ALIASES,
)
from agents.topic import TopicAgent  # noqa: E402
from agents.title import TitleAgent  # noqa: E402
from agents.outline import OutlineAgent  # noqa: E402
from agents.content import ContentAgent  # noqa: E402
from agents.humanize import HumanizeAgent  # noqa: E402
from agents.seo import SEOAgent  # noqa: E402
from agents.image import ImageAgent  # noqa: E402

logger = logging.getLogger("ablog.pipeline")

# 失败即整任务失败（需重试）的步骤
RETRYABLE_STEPS = {4, 5, 6}
# 失败即整任务 skipped（不重试）的步骤
SKIP_STEPS = {1, 2, 3}


class PipelineAgent:
    """AI 生成流水线编排器。构造：PipelineAgent(config, core=None, dry_run=False)"""

    def __init__(self, config=None, core=None, dry_run=False):
        self.config = config or {}
        self.dry_run = bool(dry_run)
        self.core = core if isinstance(core, CoreAdapter) else build_core_adapter(self.config, core)
        self.topic_agent = TopicAgent(self.config, self.core, dry_run)
        self.title_agent = TitleAgent(self.config, self.core, dry_run)
        self.outline_agent = OutlineAgent(self.config, self.core, dry_run)
        self.content_agent = ContentAgent(self.config, self.core, dry_run)
        self.humanize_agent = HumanizeAgent(self.config, self.core, dry_run)
        self.seo_agent = SEOAgent(self.config, self.core, dry_run)
        self.image_agent = ImageAgent(self.config, self.core, dry_run)

    # ------------------------------------------------------------------
    def run(self, column, material=None, task_id=None, publish_date=None, topic=None):
        """执行完整流水线，返回任务对象（总纲 §3.1 字段 + 过程字段）。

        column: stock | tech | reading | book（亦兼容中文栏目名/分类名，见 resolve_column）
        material: 采集素材 dict（可含 topic 字段指定选题）
        topic: 可选，直接指定选题（等价于 material.topic）
        """
        raw_column = str(column or "").strip()
        material = self._normalize_material(raw_column, material)
        if topic:
            material["topic"] = topic
        column = resolve_column(raw_column)
        if column == "generic":
            # 未知栏目（如用户自定义分类）暂不支持自动生成，安全跳过
            return self._fail_task(task_id, raw_column, f"栏目「{raw_column or '空'}」暂不支持自动生成", material)
        if column not in COLUMN_CATEGORIES:
            return self._fail_task(task_id, raw_column, "未知栏目", material)

        task = self._new_task(column, material, task_id, publish_date)
        # 分类：优先用原始栏目值（分类名，如“股市”），插件端按名/slug 解析
        if raw_column:
            task["category"] = raw_column
        # 原始栏目名（DB 持久化用，避免覆盖成流水线 code）
        task["column_name"] = raw_column or column
        task["dry_run"] = self.dry_run
        task["steps"] = []

        # ---- 配额预检（总纲 §6 成本保护） ----
        daily_limit = (self.config.get("quota", {}) or {}).get("daily_token_limit", 200000)
        if not self.core.quota_ok(20000, daily_limit=daily_limit):
            task["status"] = "skipped"
            task["error"] = "每日 Token 额度不足（quota_exceeded）"
            task["steps"].append({"step": 0, "name": "precheck", "ok": False, "note": task["error"]})
            return task

        # ---- Step1 选题 ----
        if column == "stock":
            # 复盘栏目特殊规则（翁老）：标题=日期+A股市场+：+副标题，副标题正文后取，
            # 不生成备选候选、不入备用池（不能事先取）；topic 默认日期占位。
            day = (publish_date or dt.datetime.now().astimezone().isoformat())[:10]
            manual_topic = str(material.get("topic") or "").strip()
            default_topic = f"{day} A股每日复盘"
            # 人工指定了复盘标题（含“A股市场”且非默认占位）→ 尊重人工指定
            if manual_topic and manual_topic != default_topic and "A股市场" in manual_topic:
                task["topic"] = manual_topic
                task["manual_title"] = manual_topic
                task["steps"].append({"step": 1, "name": "topic", "ok": True,
                                      "note": f"使用人工指定复盘标题：{manual_topic[:40]}"})
            else:
                task["topic"] = default_topic
                task["steps"].append({"step": 1, "name": "topic", "ok": True,
                                      "note": "复盘栏目：日期选题，副标题待正文后生成"})
            task["topic_candidates"] = []
            task["dedup_report"] = [{"channel": "review-date", "duplicate": False,
                                      "note": "复盘按标题日期查重，不查内容（插件端执行）"}]
        else:
            r = self._safe_step(task, 1, "topic",
                                lambda: self.topic_agent.generate(column, material))
            if not r.ok:
                return self._finalize_failure(task, r, material)
            task["topic"] = r.data["candidates"][0]["topic"]
            task["topic_candidates"] = r.data["candidates"]
            task["dedup_report"] = r.data.get("duplicate_filtered")
            if material.get("topic"):
                task["steps"][-1]["note"] = "使用显式指定选题"
            material = dict(material)
            material["topic"] = task["topic"]

        # ---- Step2 标题（stock 延后到正文后生成） ----
        if column != "stock":
            r = self._safe_step(task, 2, "title",
                                lambda: self.title_agent.generate(task["topic"], column, material))
            if not r.ok:
                return self._finalize_failure(task, r, material)
            task["final_title"] = r.data["final_title"]
            task["title_candidates"] = r.data["candidates"]
        else:
            task["steps"].append({"step": 2, "name": "title", "ok": True,
                                  "note": "复盘标题延后：正文生成后按内容特点取副标题"})
            task["title_candidates"] = []

        # ---- Step3 大纲 ----
        r = self._safe_step(task, 3, "outline",
                            lambda: self.outline_agent.generate(column, task["topic"], material))
        if not r.ok:
            return self._finalize_failure(task, r, material)
        task["outline"] = r.data["sections"]

        # ---- Step4 正文（可重试 1 次） ----
        r = self._safe_step(task, 4, "content",
                            lambda: self.content_agent.generate(column, task["topic"], task["outline"], material, task["final_title"]))
        if not r.ok:
            return self._finalize_failure(task, r, material)
        task["content_html"] = r.data["content_html"]
        task["char_count"] = r.data["char_count"]
        task["length_ok"] = r.data["length_ok"]
        task["excerpt"] = self._make_excerpt(task["content_html"])
        task["tokens_used"] = r.data.get("tokens_used", 0)

        # ---- Step5 去AI润色 ----
        r = self._safe_step(task, 5, "humanize",
                            lambda: self.humanize_agent.improve(column, task["content_html"], task["topic"]))
        if not r.ok:
            return self._finalize_failure(task, r, material)
        task["content_html"] = r.data["content_html"]
        task["humanize_transforms"] = r.data.get("transforms", [])

        # ---- Step6 SEO 优化 ----
        r = self._safe_step(task, 6, "seo",
                            lambda: self.seo_agent.optimize(column, task["topic"], task["content_html"], task["final_title"]))
        if not r.ok:
            return self._finalize_failure(task, r, material)
        task["meta_description"] = r.data["meta_description"]
        task["seo_keywords"] = r.data["keywords"]
        task["seo_longtail"] = r.data["longtail"]
        task["seo_notes"] = r.data.get("notes", [])
        task["tags"] = r.data.get("tags") or COLUMN_DEFAULT_TAGS.get(column, [])
        task["content_html"] = r.data["content_html"]

        # ---- Step2b 复盘标题（stock：正文/润色/SEO 完成后，按内容特点取副标题） ----
        if column == "stock":
            day = (publish_date or dt.datetime.now().astimezone().isoformat())[:10]
            # 人工指定标题优先；但若人工标题只有冒号没有副标题（如“2026年8月3日 A股市场：”），
            # 仍按翁老规则自动生成副标题补全（副标题正文后取）
            manual_missing_sub = False
            if task.get("manual_title") and re.search(r"[：:][\s　]*$", task["manual_title"]):
                manual_missing_sub = True
            if task.get("manual_title") and not manual_missing_sub:
                task["final_title"] = task["manual_title"]
                task["review_subtitle"] = "（人工指定）"
                task["steps"].append({"step": 2, "name": "title-final", "ok": True,
                                      "note": "使用人工指定标题，跳过自动副标题"})
            else:
                r = self._safe_step(task, 2, "title-final",
                                    lambda: self.title_agent.generate_review_title(day, task["content_html"], task["topic"]))
                if r.ok:
                    sub = r.data.get("subtitle", "")
                    if manual_missing_sub:
                        # 人工基础标题 + 自动副标题
                        base = re.sub(r"[：:][\s　]*$", "", task["manual_title"])
                        task["final_title"] = f"{base}：{sub}" if sub else task["manual_title"]
                        task["review_subtitle"] = sub
                        task["steps"][-1]["note"] = f"人工标题补副标题：{sub}"
                    else:
                        task["final_title"] = r.data["final_title"]
                        task["review_subtitle"] = sub
                        task["steps"][-1]["note"] = f"副标题按正文内容生成：{sub}"
                else:
                    # 标题失败不终止整篇：用日期兜底标题，正文照常发布
                    if manual_missing_sub:
                        task["final_title"] = f"{day} A股市场：收盘综述"
                    else:
                        task["final_title"] = f"{day} A股市场：收盘综述"
                    task["review_subtitle"] = "收盘综述"
                    task["steps"].append({"step": 2, "name": "title-fallback", "ok": True,
                                          "note": "副标题生成失败，用兜底标题"})

        # ---- Step7 配图（失败降级纯文字，不失败任务） ----
        r = self._safe_step(task, 7, "image",
                            lambda: self.image_agent.generate_image(column, task["topic"], task["task_id"], task["final_title"]))
        task["image_note"] = r.data.get("note") if r.ok else "配图失败，降级纯文字发布"
        task["featured_image"] = r.data.get("local_path") if r.ok else None

        # ---- 收尾 ----
        task["status"] = "ready"
        task["source"] = {
            "model": model_for_column(self.config, column),
            "prompt_version": PROMPT_VERSION,
            "dry_run": self.dry_run,
            "image_provider": self.image_agent.provider_name(),
        }
        return task

    # ------------------------------------------------------------------
    def _safe_step(self, task, step_no, name, fn):
        """执行单步并记录；失败时按策略返回。ok=False 表示终止。"""
        try:
            data = fn()
            task["steps"].append({"step": step_no, "name": name, "ok": True})
            return _StepResult(True, data)
        except Exception as e:
            return self._handle_step_error(task, step_no, name, fn, e)

    def _handle_step_error(self, task, step_no, name, fn, e):
        err = f"{name} 失败: {e}"
        task["steps"].append({"step": step_no, "name": name, "ok": False, "error": str(e)[:300]})
        if step_no in RETRYABLE_STEPS and not self.dry_run:
            # Step4/5/6 失败 → 重试 1 次
            logger.warning("%s 首次失败，重试 1 次: %s", name, e)
            try:
                data = fn()
                task["steps"][-1] = {"step": step_no, "name": name, "ok": True, "retried": True}
                return _StepResult(True, data)
            except Exception as e2:
                err = f"{name} 重试仍失败: {e2}"
                task["steps"].append({"step": step_no, "name": name, "ok": False, "error": str(e2)[:300], "retried": True})
                return _StepResult(False, None, err)
        return _StepResult(False, None, err)

    # ------------------------------------------------------------------
    def _finalize_failure(self, task, r, material):
        if task.get("status") != "failed":
            step_no = task["steps"][-1]["step"] if task["steps"] else 0
            task["status"] = "failed" if step_no in RETRYABLE_STEPS else "skipped"
        task["error"] = r.error
        if task["status"] == "skipped":
            task["steps"].append({"step": 0, "name": "abort", "ok": False,
                                  "note": "Step1/2/3 失败 → 整任务 skipped（不消耗后续 Token）"})
        return task

    def _fail_task(self, task_id, column, reason, material):
        return {
            "task_id": task_id or self._gen_task_id(column),
            "column": column, "topic": None, "final_title": None,
            "content_html": None, "excerpt": None, "meta_description": None,
            "tags": [], "category": COLUMN_CATEGORIES.get(column),
            "featured_image": None, "status": "failed", "publish_date": None,
            "source": {"prompt_version": PROMPT_VERSION, "dry_run": self.dry_run},
            "material": material, "error": reason, "steps": [],
        }

    def _normalize_material(self, column, material):
        """素材归一化：dict 直接返回；list 按栏目包一层（容错采集器返回类型）。"""
        if isinstance(material, dict):
            return dict(material)
        if isinstance(material, list):
            if column == "tech":
                return {"questions": material}
            if column == "reading":
                return {"poems": material}
            if column == "book" and material and isinstance(material[0], dict):
                return {"book": material[0]}
            return {"items": material}
        return dict(material or {})

    def _new_task(self, column, material, task_id, publish_date):
        return {
            "task_id": task_id or self._gen_task_id(column),
            "column": column,
            "topic": None,
            "final_title": None,
            "content_html": None,
            "excerpt": None,
            "meta_description": None,
            "tags": [],
            "category": COLUMN_CATEGORIES[column],
            "featured_image": None,
            "status": "generating",
            "publish_date": publish_date or dt.datetime.now().astimezone().isoformat(),
            "source": {"prompt_version": PROMPT_VERSION},
            "material": material,
            "tokens_used": 0,
        }

    def _gen_task_id(self, column):
        day = dt.date.today().strftime("%Y%m%d")
        seq = dt.datetime.now().strftime("%H%M%S")[-3:]
        return f"{day}-{column}-{seq}"

    def _make_excerpt(self, html, limit=110):
        plain = self.content_agent.strip_html(html)
        return plain[:limit]

    # ------------------------------------------------------------------
    def demo_run(self, column, use_real_collectors=True):
        """dry_run 演示：真实采集器 + Mock 流水线（不消耗 Token）。

        use_real_collectors=True 时调用真实 collectors（本地数据优先，网络失败自动兜底）；
        False 时使用内置演示素材（标注 MOCK）。
        """
        assert self.dry_run, "demo_run 仅支持 dry_run=True"
        material = None
        collector_note = ""
        if use_real_collectors:
            material, collector_note = self._collect_real(column)
        if not material:
            material = self._demo_material(column)
            collector_note += "（采集失败/不可用 → 演示素材标注 MOCK）"
        task = self.run(column, material)
        task["collector_note"] = collector_note
        return task

    def _collect_real(self, column):
        """调用真实采集器（异常必须兜底，绝不中断）。"""
        try:
            if column == "stock":
                from collectors.market import MarketCollector
                m = MarketCollector(self.config).collect()
                return (m or None), "采集器: MarketCollector(通达信→新浪→东财)"
            if column == "tech":
                from collectors.tech_topics import TechTopicCollector
                m = TechTopicCollector(self.config).collect()
                return (m or None), "采集器: TechTopicCollector(问题池+RSS)"
            if column == "reading":
                from collectors.reading import ReadingCollector
                m = ReadingCollector(self.config).collect(n=3)
                return ({"poems": m} if m else None), "采集器: ReadingCollector(chinese-poetry)"
            if column == "book":
                from collectors.books import BooksCollector
                m = BooksCollector(self.config).collect()
                return ({"book": m} if m else None), "采集器: BooksCollector(站点书目库)"
        except Exception as e:
            return None, f"采集异常已兜底: {e}"
        return None, "未知栏目，跳过真实采集"

    def _demo_material(self, column):
        """演示素材（仅 dry_run 用，标注 MOCK）。"""
        from agents.base import MockLLM
        tag = MockLLM.MOCK_TAG
        if column == "stock":
            return {"indices": [{"name": "上证指数", "close": 3867.03, "change_pct": -0.42, "amount_yi": 10258}],
                    "breadth": {"up": 2100, "down": 2800, "flat": 120},
                    "sectors": [{"name": "银行", "change_pct": 1.2}], "note": tag + " 演示素材"}
        if column == "tech":
            return {"questions": [{"question": "Nginx 配置反向代理报 502 如何排查", "source": "demo"}], "note": tag + " 演示素材"}
        if column == "reading":
            return {"poems": [{"title": "静夜思", "author": "李白", "source": "demo", "paragraphs": ["床前明月光，疑是地上霜。", "举头望明月，低头思故乡。"]}], "note": tag + " 演示素材"}
        if column == "book":
            return {"book": {"title": "股票大作手回忆录", "author": "埃德温·勒菲弗", "source_url": "https://sunclnas.cn"}, "note": tag + " 演示素材"}
        return {}


class _StepResult:
    def __init__(self, ok, data=None, error=None):
        self.ok = ok
        self.data = data
        self.error = error
