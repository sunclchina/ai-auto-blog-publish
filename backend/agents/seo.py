# -*- coding: utf-8 -*-
"""
agents/seo.py — Step6 SEO 优化

产出：Meta 描述（≤120字）、关键词密度检查与自然调整、长尾词植入、内链占位、标签。
工具函数调用 core/seo.py（经 CoreAdapter；core 未就绪时内置降级并标注 [CORE_FALLBACK]）。
"""

import os
import sys
import re

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agents.base import BaseAgent, LLMError, COLUMN_DEFAULT_TAGS  # noqa: E402

META_MAX_LEN = 120
DENSITY_TARGET = (0.3, 0.8)  # 百分比口径（与 core/seo.py 一致）


class SEOAgent(BaseAgent):
    step = 6
    step_name = "seo"

    def __init__(self, config=None, core=None, dry_run=False):
        super().__init__(config, core, dry_run)

    # ------------------------------------------------------------------
    def optimize(self, column, topic, content_html, final_title=None):
        """SEO 优化。返回 dict：
        {meta_description, keywords, density, density_ok, longtail, tags,
         content_html（含内链占位）, notes: [str], tokens_used}
        """
        if not content_html or not content_html.strip():
            raise LLMError("content", "正文为空，无法做 SEO 优化")

        keyword = self._pick_keyword(column, topic, final_title)

        if self.dry_run:
            return self._dry_run_seo(column, topic, content_html, keyword)

        # 真实模式：LLM 生成 Meta 与关键词（工具函数仍走 core/seo.py）
        prompt = (
            f"栏目：{column}\n标题：{final_title or topic}\n核心词：{keyword}\n\n"
            f"请基于正文生成 SEO 元数据，JSON 格式：\n"
            f'{{"meta_description": "≤{META_MAX_LEN}字的中文描述", "keywords": ["词1","词2","词3"], '
            f'"longtail": ["长尾词1","长尾词2"], "tags": ["标签1","标签2","标签3"]}}\n'
            f"要求：meta 自然含核心词与栏目词；tags 贴合青简主题分类。\n\n正文：\n{content_html[:6000]}"
        )
        messages = [
            {"role": "system", "content": "你是 SEO 优化师。只输出 JSON。"},
            {"role": "user", "content": prompt},
        ]
        text, tokens = self.call_llm(messages, model=self.model_for(column), max_tokens=600, temperature=0.4)
        seo_data = self._parse_json(text)

        meta = self.core.build_meta(title=final_title or topic, excerpt=self._excerpt(content_html),
                                    keywords=keyword, max_len=META_MAX_LEN)
        if len(seo_data.get("meta_description") or "") > META_MAX_LEN:
            meta = (seo_data["meta_description"] or meta)[:META_MAX_LEN]
        elif seo_data.get("meta_description"):
            meta = seo_data["meta_description"]

        keywords = seo_data.get("keywords") or [keyword]
        longtail = seo_data.get("longtail") or self.core.longtail_keywords(self.strip_html(content_html), keyword)
        tags = list(dict.fromkeys((seo_data.get("tags") or []) + COLUMN_DEFAULT_TAGS.get(column, [])))

        density = self.core.keyword_density(self.strip_html(content_html), keyword)
        html = self._inject_longtail(content_html, longtail, keyword)
        html = self._inject_internal_link(html, keyword)

        notes = []
        if density < DENSITY_TARGET[0]:
            notes.append(f"关键词密度 {density:.2f}% 低于目标下限，已在正文植入长尾词自然调整")
        elif density > DENSITY_TARGET[1]:
            notes.append(f"关键词密度 {density:.2f}% 高于上限，建议人工检查堆砌")
        else:
            notes.append(f"关键词密度 {density:.2f}% 处于目标区间")

        return {
            "meta_description": meta,
            "keywords": keywords,
            "density": density,
            "density_ok": DENSITY_TARGET[0] <= density <= DENSITY_TARGET[1],
            "longtail": longtail,
            "tags": tags,
            "content_html": html,
            "notes": notes,
            "tokens_used": tokens,
        }

    # ------------------------------------------------------------------
    def _dry_run_seo(self, column, topic, content_html, keyword):
        from agents.base import MockLLM
        meta = self.core.build_meta(title=topic, excerpt=self._excerpt(content_html),
                                    keywords=keyword, max_len=META_MAX_LEN)
        longtail = self.core.longtail_keywords(self.strip_html(content_html), keyword)
        tags = list(dict.fromkeys([keyword] + COLUMN_DEFAULT_TAGS.get(column, [])))
        density = self.core.keyword_density(self.strip_html(content_html), keyword)
        html = self._inject_longtail(content_html, longtail, keyword)
        html = self._inject_internal_link(html, keyword)
        return {
            "meta_description": MockLLM.MOCK_TAG + " " + meta[:60],
            "keywords": [keyword, f"{keyword}方法", f"{keyword}实操"],
            "density": density,
            "density_ok": DENSITY_TARGET[0] <= density <= DENSITY_TARGET[1],
            "longtail": longtail,
            "tags": tags,
            "content_html": html,
            "notes": ["dry_run MOCK：Meta/关键词由本地工具生成，未调用真实模型"],
            "tokens_used": 0,
        }

    def _excerpt(self, html, limit=80):
        return self.strip_html(html)[:limit]

    def _pick_keyword(self, column, topic, final_title):
        if column == "stock":
            # 复盘栏目固定好词（避免 “A股每日复盘” 中拉丁字母 A 被中文正则切掉成“股每日复盘”）
            return "A股复盘"
        m = re.search(r"《(.+?)》", topic)
        if m:
            return m.group(1)
        base = re.sub(r"[【】\[\]（）()·•\s：:，。、！？!?\-—]", "", (final_title or topic))
        # 优先取连续中文片段（更自然的关键词），避免切断词
        cjk = re.findall(r"[\u4e00-\u9fff]+", base)
        if cjk and len(cjk[0]) >= 2:
            return cjk[0][:8]
        return base[:10] if base else column

    def _parse_json(self, text):
        import json
        try:
            m = re.search(r"\{.*\}", text, re.S)
            data = json.loads(m.group(0)) if m else json.loads(text)
            if isinstance(data, dict):
                return data
        except Exception:
            pass
        return {}

    def _inject_longtail(self, html, longtail, keyword):
        """长尾词自然植入：在首段与末段各补一句带长尾词的句子（真实模式正文由 LLM 控制，这里做轻量植入）。"""
        if not longtail:
            return html
        lt = longtail[0]
        if lt in html:
            return html
        sentence = f"想进一步了解{lt}的朋友，可以结合本篇的步骤逐步实践。"
        m = re.search(r"</p>", html)
        if m:
            html = html[: m.end()] + sentence + html[m.end():]
        return html

    def _inject_internal_link(self, html, keyword):
        """内链占位（调 core/seo.py 工具函数；core 未就绪时降级）。"""
        link = self.core.internal_link_placeholder(keyword)
        marker = f"<!-- 内链占位:{keyword} -->"
        if marker in html:
            return html
        m = re.search(r"</p>", html)
        if m:
            html = html[: m.end()] + link + html[m.end():]
        else:
            html += link
        return html

    def model_for(self, column):
        from agents.base import model_for_column
        return model_for_column(self.config, column)
