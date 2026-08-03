# -*- coding: utf-8 -*-
"""
agents/humanize.py — Step5 去AI润色

手法：句式重组、段落节奏变化、同义词替换、插入个人视角句（轻量，不过度）、
     消除“首先/其次/总之/值得注意的是”等 AI 高频词。
真实模式：LLM 重写（保留 HTML 结构、事实、风险句）。
dry_run 模式：本地确定性变换（同义词表 + AI 词清除 + 视角句注入），输出带 MOCK 标注。
"""

import os
import sys
import re

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agents.base import BaseAgent, LLMError, MockLLM  # noqa: E402

# AI 高频词 → 替换/删除策略（None 表示直接删除）
AI_FREQ_WORDS = {
    "首先": "", "其次": "", "再次": "", "最后": "", "总之": "",
    "值得注意的是": "值得一提的是", "值得注意的是，": "",
    "综上所述": "", "总而言之": "", "显而易见": "不难看出",
    "众所周知": "", "事实上": "", "与此同时": "", "此外": "",
    "需要注意的是": "", "需要指出的是": "", "不可否认": "",
    "从某种程度上来说": "", "总的来说": "", "不难发现": "细看之下",
}

# 轻量同义词替换
SYNONYMS = {
    "非常": ["相当", "十分"], "非常地": ["格外"], "很多": ["不少", "众多"],
    "进行": ["展开", "着手"], "使用": ["采用", "借助"], "提升": ["抬高", "增强"],
    "下降": ["回落", "走低"], "上涨": ["走高", "攀升"], "重要": ["关键", "要紧"],
    "问题": ["难题", "症结"], "方法": ["思路", "做法"], "结果": ["走向", "局面"],
}

# 个人视角句模板（dry_run 注入用；真实模式由 LLM 按文风生成）
PERSPECTIVE_TEMPLATES = [
    "写到这里，我忍不住多想一层：{topic}。",
    "就我个人的观察，{topic}。",
    "这一点我自己踩过坑，深有体会。",
    "换个角度想，{topic}。",
    "这是我最近的一点真实感受，分享给大家。",
]


class HumanizeAgent(BaseAgent):
    step = 5
    step_name = "humanize"

    def __init__(self, config=None, core=None, dry_run=False):
        super().__init__(config, core, dry_run)

    # ------------------------------------------------------------------
    def improve(self, column, content_html, topic=None):
        """去AI润色。返回 {"content_html", "transforms": [str], "tokens_used": int}"""
        if not content_html or not content_html.strip():
            raise LLMError("content", "正文为空，无法润色")
        if self.dry_run:
            return self._local_improve(column, content_html, topic)
        return self._llm_improve(column, content_html, topic)

    # ------------------------------------------------------------------
    def _llm_improve(self, column, content_html, topic):
        spec = self.load_prompt(f"{column}.md") if column in ("stock", "tech", "reading") else self.load_prompt("reading.md")
        prompt = (
            f"请对下面的文章做去AI化润色（保留全部 HTML 标签结构与事实内容）：\n"
            f"1. 句式重组：长短句交替，打破排比式开头\n"
            f"2. 段落节奏：拆分/合并长段，制造停顿\n"
            f"3. 同义词替换：消除重复用词\n"
            f"4. 插入 1-2 句个人视角/亲身感受（轻量，不夸张）\n"
            f"5. 删除“首先/其次/再次/总之/值得注意的是/综上所述/总而言之”等 AI 高频词\n"
            f"6. 保留风险提示句（如有）与代码块、引用\n"
            f"栏目规范：{spec[:800]}\n\n文章：\n{content_html}\n\n只输出润色后的 HTML。"
        )
        messages = [
            {"role": "system", "content": "你是擅长去AI化写作的文字编辑，输出保留 HTML 结构。"},
            {"role": "user", "content": prompt},
        ]
        text, tokens = self.call_llm(messages, model=self.model_for(column), max_tokens=4500, temperature=0.8)
        html = re.sub(r"^```(?:html)?\s*|```$", "", text.strip()).strip()
        if not html or "<" not in html:
            raise LLMError("content", "润色输出异常（非 HTML），保留原正文")
        return {"content_html": html, "transforms": ["LLM 去AI化重写（真实模式）"], "tokens_used": tokens}

    def _local_improve(self, column, content_html, topic):
        """dry_run 本地变换（原则2：输出带 MOCK 标注，绝不冒充真实润色）。"""
        html = content_html
        transforms = []

        # 1) 删除/替换 AI 高频词
        for w, repl in AI_FREQ_WORDS.items():
            if w in html:
                html = html.replace(w, repl)
                transforms.append(f"清除AI高频词「{w}」")

        # 2) 轻量同义词替换（只在 <p> 文本区做，避免破坏标签）
        def _swap(m):
            return self._synonym_replace(m.group(0))
        html = re.sub(r">([^<>]+)<", _swap, html)
        transforms.append("同义词替换（MOCK 词表）")

        # 3) 插入个人视角句（每篇最多 1 句，插在首个 <p> 后）
        tpl = PERSPECTIVE_TEMPLATES[0].format(topic=topic or "这个选题")
        m = re.search(r"(</p>)", html)
        if m:
            html = html[: m.start(1)] + tpl + html[m.start(1):]
            transforms.append(f"插入个人视角句「{tpl[:24]}…」（MOCK 模板）")

        html = MockLLM.MOCK_TAG + " 去AI润色（dry_run 本地模拟）\n" + html
        transforms.append("dry_run MOCK 标注")
        return {"content_html": html, "transforms": transforms, "tokens_used": 0}

    def _synonym_replace(self, text):
        for w, repls in SYNONYMS.items():
            if w in text:
                text = text.replace(w, repls[0], 1)
        return text

    def model_for(self, column):
        from agents.base import model_for_column
        return model_for_column(self.config, column)
