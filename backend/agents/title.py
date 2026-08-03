# -*- coding: utf-8 -*-
"""
agents/title.py — Step2 标题智能体

给定选题 → 生成 3-5 条 SEO 标题 → 自动择优
择优规则：长度 15-30 字优先、含核心关键词、无重复、无 AI 高频词。
"""

import os
import sys
import re

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agents.base import BaseAgent, LLMError  # noqa: E402

AI_WORDS = ["首先", "其次", "再次", "总之", "值得注意的是", "综上所述", "总而言之", "显而易见", "众所周知"]


class TitleAgent(BaseAgent):
    step = 2
    step_name = "title"

    def __init__(self, config=None, core=None, dry_run=False):
        super().__init__(config, core, dry_run)

    # ------------------------------------------------------------------
    def generate(self, topic, column, material=None):
        """生成并择优标题。

        返回: {"final_title": str, "candidates": [str], "pick_reason": str, "tokens_used": int}
        """
        keyword = self._core_keyword(topic, column, material)
        prompt = self._build_prompt(topic, column, keyword)
        messages = [
            {"role": "system", "content": "你是 SEO 标题专家。只输出标题，每行一条，不要编号以外的多余文字。"},
            {"role": "user", "content": prompt},
        ]
        text, tokens = self.call_llm(messages, model=self.model_for(column), max_tokens=512, temperature=0.8)

        candidates = self._parse_titles(text)
        if not candidates:
            raise LLMError("content", "标题生成失败：输出无法解析")
        candidates = self._dedup(candidates)
        if not candidates:
            raise LLMError("content", "标题全部重复，无法择优")

        scored = [(self._score(t, keyword), i, t) for i, t in enumerate(candidates)]
        scored.sort(key=lambda x: (-x[0], x[1]))
        best = scored[0][2]
        # 读书/国学标题规范：必须含《书名》（从选题提取，缺失则包装），加标点断句
        best = self._enforce_book_format(best, topic, column)
        return {
            "final_title": best,
            "candidates": candidates,
            "pick_reason": f"评分 {scored[0][0]}（长度 {len(best)} 字，含关键词: {keyword in best}）",
            "tokens_used": tokens,
        }

    def _enforce_book_format(self, title, topic, column):
        """读书栏目标题规范：以“读《书名》作者：角度”为格式，确保书名号与断句。"""
        if column not in ("reading", "book"):
            return title
        title = (title or "").strip()
        m = re.search(r"《([^《》]+)》", topic)
        if not m:
            return title
        book = m.group(1).strip()
        author = ""
        am = re.search(r"《[^《》]+》\s*([\u4e00-\u9fff]{1,6})", topic)
        if am:
            author = am.group(1)
        # 标题已含书名号 → 保持原样（LLM 输出一般可读，不做易出错的强行断句）
        if "《" in title:
            return title
        # 标题缺书名号 → 用选题的书名包装（保留原标题作为角度）
        angle = re.sub(rf"^{re.escape(book)}|读《?[^《》]*》?", "", title).strip("：:,，。")
        if not angle:
            angle = "原文赏析"
        # 繁简差异下再去一次书名（如 简体标题 + 繁体书名）
        try:
            from scheduler.pool import _norm_topic
            na, nb = _norm_topic(angle), _norm_topic(book)
            if nb and nb in na:
                angle = na.replace(nb, "").strip("读").strip()
            # 作者名重复（如角度里含“李白原文赏析”）
            if author and _norm_topic(angle).startswith(_norm_topic(author)):
                angle = angle[len(author):].strip("：:,，。")
        except Exception:
            pass
        if not angle:
            angle = "原文赏析"
        if author:
            return f"读《{book}》{author}：{angle}"
        return f"读《{book}》：{angle}"

    # ------------------------------------------------------------------
    def model_for(self, column):
        from agents.base import model_for_column
        return model_for_column(self.config, column)

    def generate_review_title(self, date_str: str, content_html: str, fallback_topic: str = "") -> dict:
        """A股复盘专用标题（翁老规则）：标题 = 当日日期 + “A股市场” + “：” + 副标题。

        副标题必须基于正文内容特点生成（先写正文后定标题），不预设、不备选；
        内容每天相似仅数字不同，标题中的日期保证每日唯一，查重按日期而非内容。
        返回: {"final_title": str, "subtitle": str, "tokens_used": int}
        """
        plain = self.strip_html(content_html)
        if self.dry_run:
            subtitle = "缩量震荡，板块分化加剧"  # MOCK：仅 dry_run 占位
            return {
                "final_title": f"{date_str} A股市场：{subtitle}",
                "subtitle": subtitle,
                "tokens_used": 0,
                "note": "MOCK 副标题（dry_run 未调真实模型）",
            }
        prompt = (
            f"你是资深证券分析师。以下是今日 A 股复盘文章全文：\n\n{plain[:4000]}\n\n"
            f"请提炼一个 6-14 字的副标题，概括今日盘面最突出的特点"
            f"（如：缩量调整、放量突破、板块轮动、情绪修复、权重护盘、题材退潮等），"
            f"只输出副标题本身，不要引号、不要多余文字。"
        )
        messages = [{"role": "system", "content": "你是资深证券分析师，只输出副标题，禁止多余文字。"},
                    {"role": "user", "content": prompt}]
        text, tokens = self.call_llm(messages, model=self.model_for("stock"), max_tokens=64, temperature=0.5)
        subtitle = text.strip().strip("「」“”\"'。，、\n")[:16]
        if not subtitle:
            subtitle = (fallback_topic or "A股收盘综述")[:12]
        return {"final_title": f"{date_str} A股市场：{subtitle}", "subtitle": subtitle, "tokens_used": tokens}

    def _core_keyword(self, topic, column, material):
        """提取核心关键词：优先素材自带 keywords，其次选题首段。"""
        kw = []
        if material and isinstance(material, dict):
            kw = material.get("keywords") or []
        if not kw:
            # 从 topic 中提取：书名/诗词名/指数名等
            m = re.search(r"《(.+?)》", topic)
            if m:
                kw.append(m.group(1))
            m = re.search(r"[\u4e00-\u9fff]{2,8}(?:复盘|教程|优化|配置|指南|心得|解读|书评|笔记)", topic)
            if m:
                kw.append(m.group(0))
        if not kw:
            kw = [topic[:8]]
        return kw[0]

    def _build_prompt(self, topic, column, keyword):
        fmt = (
            "\n6. 综述格式：关于【范围（全球或我国）】+【行业或概念名称】及企业发展现状的综述，"
            "如《关于我国AI算力行业及企业发展现状的综述》\n"
            if column == "industry"
            else ""
        )
        return (
            f"请为主题生成 3-5 条 SEO 标题。\n"
            f"栏目：{column}\n选题：{topic}\n核心关键词：{keyword}\n\n"
            f"要求：\n"
            f"1. 长度 15-30 字优先\n"
            f"2. 必须自然包含核心关键词\n"
            f"3. 避免使用“{AI_WORDS[0]}”“{AI_WORDS[1]}”“总之”等 AI 高频词\n"
            f"4. 差异化角度，不重复\n"
            f"5. 吸引点击但不标题党\n"
            f"{fmt}"
            f"每行一条标题。"
        )

    def _parse_titles(self, text):
        out = []
        for line in text.splitlines():
            line = line.strip()
            if not line:
                continue
            line = re.sub(r"^[\d一二三四五六七八九十]+[\.、\)）]\s*", "", line)
            line = line.strip("-–—·•* ").strip()
            if not line or line.startswith(("【", "#", "标题")):
                continue
            if 4 <= len(line) <= 60:
                out.append(line)
            if len(out) >= 8:
                break
        return out

    def _dedup(self, titles):
        seen, out = set(), []
        for t in titles:
            norm = re.sub(r"[\s，。！？、,.;:：！？%％]", "", t)
            if norm and norm not in seen:
                seen.add(norm)
                out.append(t)
        return out

    def _score(self, title, keyword):
        s = 0
        n = len(title)
        if 15 <= n <= 30:
            s += 2
        elif 10 <= n < 15 or 30 < n <= 40:
            s += 1
        if keyword and keyword in title:
            s += 2
        if not any(w in title for w in AI_WORDS):
            s += 1
        if re.search(r"[\u4e00-\u9fff]", title):
            s += 1
        return s
