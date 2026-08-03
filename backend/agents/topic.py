# -*- coding: utf-8 -*-
"""
agents/topic.py — Step1 选题智能体

输入：采集素材 dict（stock=大盘数据摘要 / tech=热点列表 / reading=候选诗词列表 / book=书目信息）
流程：前置查重（core/fingerprint + WP check 占位）→ 黑名单过滤 → 生成 3-5 个选题候选（含理由）
特殊：material 中带显式 topic 时直接使用（不重复生成）。
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agents.base import BaseAgent, LLMError, COLUMN_DEFAULT_TAGS  # noqa: E402


class TopicAgent(BaseAgent):
    step = 1
    step_name = "topic"

    def __init__(self, config=None, core=None, dry_run=False):
        super().__init__(config, core, dry_run)

    # ------------------------------------------------------------------
    def generate(self, column, material):
        """生成选题候选列表。

        material: dict，按栏目：
          stock   → {"indices": [...], "breadth": {...}, "turnover": ..., "sectors": [...], "topic": 可选}
          tech    → {"questions": [...], "rss": [...], "topic": 可选}
          reading → {"poems": [...], "topic": 可选}
          book    → {"book": {"title":..., "author":...}, "topic": 可选}
        返回: {"candidates": [{"topic", "reason", "source"}], "used_explicit_topic": bool, "duplicate_filtered": [...]}
        """
        material = material or {}

        # 0) 显式选题：material 带 topic 字段时直接用（总纲 1.2 第 1 步：可人工改）
        explicit = material.get("topic")
        if explicit and str(explicit).strip():
            topic_text = str(explicit).strip()
            if self.is_blacklisted(topic_text):
                raise LLMError("content", f"显式选题命中黑名单: {topic_text}")
            return {
                "candidates": [{"topic": topic_text, "reason": "人工/调度指定选题", "source": "explicit"}],
                "used_explicit_topic": True,
                "duplicate_filtered": [],
            }

        # 1) 前置查重：指纹库 + WP check 占位
        dup = self.pre_dedup(column, material)

        # 2) 构造提示词（含栏目规范 + 素材摘要）
        prompt = self._build_prompt(column, material)
        messages = [
            {"role": "system", "content": "你是资深内容策划。输出必须是 JSON 数组，每项含 topic(字符串) 与 reason(中文理由)。"},
            {"role": "user", "content": prompt},
        ]
        text, tokens = self.call_llm(messages, model=self.model_for(column), max_tokens=1024, temperature=0.9)

        candidates = self._parse_candidates(text)
        # 3) 黑名单过滤（含二次查重兜底）
        kept, dropped = [], []
        for c in candidates:
            t = c["topic"]
            if self.is_blacklisted(t):
                dropped.append({"topic": t, "reason": "黑名单"})
                continue
            if self.core.fingerprint_check(t):
                dropped.append({"topic": t, "reason": "指纹重复"})
                continue
            kept.append(c)
        if not kept:
            raise LLMError("content", "选题候选全部被过滤（黑名单/查重），无法继续")
        return {
            "candidates": kept,
            "used_explicit_topic": False,
            "duplicate_filtered": dup,
            "blacklist_filtered": dropped,
            "tokens_used": tokens,
        }

    # ------------------------------------------------------------------
    def is_blacklisted(self, topic):
        if self.core.is_blacklisted(topic):
            return True
        cfg_bl = self.config.get("blacklist", []) or []
        return any(w and w in topic for w in cfg_bl)

    def model_for(self, column):
        from agents.base import model_for_column
        return model_for_column(self.config, column)

    def pre_dedup(self, column, material):
        """前置查重：指纹库（core/fingerprint）+ WP check 占位端点（总纲 §3.2 /check）。"""
        results = []
        # 1) 指纹库查重（素材指纹）
        probe_text = self._probe_text(column, material)
        if probe_text:
            try:
                dup = self.core.fingerprint_check(probe_text)
                results.append({"channel": "fingerprint", "duplicate": bool(dup)})
            except Exception as e:
                results.append({"channel": "fingerprint", "duplicate": False, "error": str(e)})
        # 2) WP check 占位：插件 REST /check 端点（插件未就绪或无配置时跳过，不阻断）
        wp = self.config.get("wp", {}) or {}
        endpoint = wp.get("check_url") or wp.get("base_url", "").rstrip("/") + "/wp-json/ai-auto-blog/v1/check"
        token = wp.get("token")
        if wp.get("base_url") and token:
            try:
                import httpx
                r = httpx.post(endpoint, headers={"Authorization": f"Bearer {token}"},
                               json={"title": probe_text or "", "column": column}, timeout=15)
                if r.status_code == 200:
                    results.append({"channel": "wp_check", "duplicate": bool(r.json().get("duplicate"))})
                else:
                    results.append({"channel": "wp_check", "duplicate": False, "error": f"HTTP {r.status_code}"})
            except Exception as e:
                results.append({"channel": "wp_check", "duplicate": False, "error": str(e)})
        else:
            results.append({"channel": "wp_check", "duplicate": False, "skipped": "WP 未配置，占位跳过"})
        return results

    # ------------------------------------------------------------------
    def _probe_text(self, column, material):
        if column == "stock":
            idx = (material.get("indices") or [{}])[0]
            return f"大盘复盘 {idx.get('name', '')} {idx.get('close', '')}"
        if column == "tech":
            qs = material.get("questions") or []
            return " ".join(q["question"] for q in qs[:3]) if qs else ""
        if column == "reading":
            poems = material.get("poems") or []
            return " ".join(f"{p.get('title', '')}{p.get('author', '')}" for p in poems[:3]) if poems else ""
        if column == "book":
            b = material.get("book") or {}
            return f"书评 {b.get('title', '')}"
        if column == "industry":
            inds = material.get("hot_industries") or []
            return " ".join(i.get("name", "") for i in inds[:3]) if inds else "行业综述"
        return ""

    def _build_prompt(self, column, material):
        spec = self.load_prompt(f"{column}.md") if column in ("stock", "tech", "reading", "industry") else self.load_prompt("reading.md")
        material_summary = self._summarize_material(column, material)
        structure_hint = {
            "stock": "选题应聚焦当日大盘走势、量能变化、板块轮动或资金动向，可展望后市",
            "tech": "选题应为真实高频问题（WordPress/WP插件/Nginx/服务器/开源工具），可解决、可实操",
            "reading": "选题应从候选诗词中选取，角度可赏析、可解读、可联系当下",
            "book": "选题即所选书目，理由需说明这本书值得读之处",
            "industry": "选题应为 A 股近期热门行业或概念（从素材候选中选择 1-2 个），按综述格式成题",
        }[column]
        return (
            f"栏目：{column}\n"
            f"栏目写作规范（节选）：\n{spec[:1500]}\n\n"
            f"素材摘要：\n{material_summary}\n\n"
            f"选题要求：{structure_hint}\n"
            f"请输出 3-5 个选题候选，JSON 数组格式：\n"
            f'[{{"topic": "选题标题", "reason": "为什么选这个（结合素材）"}}]'
        )

    def _summarize_material(self, column, material):
        if column == "stock":
            idx = material.get("indices") or []
            bd = material.get("breadth") or {}
            sec = material.get("sectors") or []
            lines = []
            for i in idx[:5]:
                lines.append(f"指数 {i.get('name')} 收盘 {i.get('close')} 涨跌幅 {i.get('change_pct')}% 成交额 {i.get('amount_yi')}亿")
            if bd:
                lines.append(f"涨跌家数: 上涨{bd.get('up')} 下跌{bd.get('down')} 平盘{bd.get('flat')}")
            if sec:
                top = sec[:5]
                lines.append("热点板块: " + "、".join(f"{s.get('name')}({s.get('change_pct')}%)" for s in top))
            return "\n".join(lines) or "大盘数据缺失"
        if column == "tech":
            qs = material.get("questions") or []
            return "\n".join(f"- {q.get('question')}" for q in qs[:8]) or "问题池为空"
        if column == "reading":
            poems = material.get("poems") or []
            return "\n".join(f"- 《{p.get('title')}》{p.get('author')}（{p.get('source')}）" for p in poems[:6]) or "诗词素材为空"
        if column == "book":
            b = material.get("book") or {}
            return f"《{b.get('title')}》 {b.get('author')}（来源: {b.get('source_url')}）"
        if column == "industry":
            inds = material.get("hot_industries") or []
            deep = material.get("deep") or {}
            lines = ["热门行业/概念：" + "、".join(i.get("name", "") for i in inds[:5])] if inds else ["热门行业素材缺失"]
            for name, d in deep.items():
                items = []
                if d.get("market"):
                    items.append("市场数据:" + d["market"][0]["title"])
                if d.get("companies"):
                    items.append("公司动态:" + d["companies"][0]["title"])
                if d.get("dynamics"):
                    items.append("热门原因:" + d["dynamics"][0]["title"])
                if items:
                    lines.append(f"[{name}] " + " | ".join(items))
            news = material.get("news") or []
            for n in news[:3]:
                lines.append(f"- {n.get('title', '')}")
            return "\n".join(lines)
        return "无素材"

    def _parse_candidates(self, text):
        """解析 LLM 输出为候选列表；容错：JSON 提取失败时按行切分。"""
        import json
        import re
        try:
            m = re.search(r"\[.*\]", text, re.S)
            data = json.loads(m.group(0)) if m else json.loads(text)
            out = []
            for item in data:
                if isinstance(item, dict) and item.get("topic"):
                    out.append({"topic": str(item["topic"]).strip(),
                                "reason": str(item.get("reason") or "").strip(),
                                "source": "llm"})
            if out:
                return out
        except Exception:
            pass
        # 行式兜底：- 标题 / 理由（跳过“理由：”前缀行与空行）
        out = []
        for line in text.splitlines():
            line = line.strip().lstrip("0123456789.、-· ")
            if not line or line.startswith(("【", "#")) or line.startswith("理由"):
                continue
            if len(line) >= 4:
                out.append({"topic": line[:60], "reason": "（未提供理由）", "source": "llm-fallback"})
            if len(out) >= 5:
                break
        if out:
            return out
        raise LLMError("content", "选题输出解析失败，无法获得候选")
