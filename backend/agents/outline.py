# -*- coding: utf-8 -*-
"""
agents/outline.py — Step3 大纲智能体

按栏目固定结构输出大纲：
  stock:   宏观环境 / 技术面 / 资金面 / 市场情绪 / 行业与板块 / 盘面综述 / 后市展望
  tech:    背景 / 原理 / 实操 / 代码 / 报错 / 总结
  reading: 原文 / 背景 / 解读 / 感悟 / 总结
  industry: 引言 / 行业概述与发展现状 / 政策与驱动因素 / A股产业链梳理 / 景气度较高的细分领域 / 重点公司简析 / 总结与展望
输出：结构化 list[{"section", "points": [str]}]（可序列化为 JSON）。
"""

import os
import sys
import json

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agents.base import BaseAgent, LLMError  # noqa: E402

FIXED_SECTIONS = {
    "stock": ["宏观政策面", "技术面", "资金流向", "市场情绪", "行业景气度", "维度市场扫描", "总结与展望"],
    "tech": ["背景", "原理", "实操", "代码", "报错", "总结"],
    "reading": ["原文", "背景", "解读", "感悟", "总结"],
    "book": ["简介", "解读", "名句", "感悟", "适合人群"],
"industry": ["引言", "行业概述与发展现状", "政策与驱动因素", "A股产业链梳理", "景气度较高的细分领域", "重点公司简析", "总结与展望"],
}


class OutlineAgent(BaseAgent):
    step = 3
    step_name = "outline"

    def __init__(self, config=None, core=None, dry_run=False):
        super().__init__(config, core, dry_run)

    # ------------------------------------------------------------------
    def generate(self, column, topic, material=None):
        """生成大纲 → list[{"section": str, "points": [str, ...]}]"""
        if column not in FIXED_SECTIONS:
            raise LLMError("content", f"未知栏目: {column}")
        sections = FIXED_SECTIONS[column]

        prompt = self._build_prompt(column, topic, sections, material)
        messages = [
            {"role": "system", "content": "你是结构化写作规划者。只输出 JSON，格式：[{\"section\": \"节名\", \"points\": [\"要点1\", \"要点2\"]}]"},
            {"role": "user", "content": prompt},
        ]
        text, tokens = self.call_llm(messages, model=self.model_for(column), max_tokens=1024, temperature=0.6)

        outline = self._parse_outline(text, sections)
        return {"sections": outline, "tokens_used": tokens}

    # ------------------------------------------------------------------
    def model_for(self, column):
        from agents.base import model_for_column
        return model_for_column(self.config, column)

    def _build_prompt(self, column, topic, sections, material):
        material_summary = self._material_hint(column, material)
        return (
            f"栏目：{column}\n选题：{topic}\n素材：{material_summary}\n\n"
            f"请按以下固定章节输出大纲（必须全部包含，顺序一致）：\n{json.dumps(sections, ensure_ascii=False)}\n"
            f"每个章节给出 2-4 个写作要点（stock 的“盘面综述”应含数据点；tech 的“代码”应注明代码主题）。\n"
            f"仅输出 JSON。"
        )

    def _material_hint(self, column, material):
        if not material:
            return "无"
        if column == "stock":
            idx = (material.get("indices") or [{}])[0]
            return f"大盘 {idx.get('name', '')} {idx.get('close', '')} 涨跌 {idx.get('change_pct', '')}%"
        if column == "tech":
            return f"问题: {((material.get('questions') or [{}])[0]).get('question', '无')}"
        if column == "reading":
            p = (material.get("poems") or [{}])[0]
            return f"诗词《{p.get('title', '')}》{p.get('author', '')}"
        if column == "book":
            b = material.get("book") or {}
            return f"书目《{b.get('title', '')}》{b.get('author', '')}"
        return "无"

    def _parse_outline(self, text, sections):
        import re
        try:
            m = re.search(r"\[.*\]", text, re.S)
            data = json.loads(m.group(0)) if m else json.loads(text)
            if isinstance(data, list) and data and isinstance(data[0], dict) and "section" in data[0]:
                outline = []
                for item in data:
                    sec = str(item.get("section", "")).strip()
                    pts = item.get("points") or []
                    if not sec:
                        continue
                    outline.append({"section": sec, "points": [str(p).strip() for p in pts if str(p).strip()]})
                if outline:
                    # 补齐固定章节（LLM 漏掉时补空，保证 7 步结构与栏目规范一致）
                    have = {o["section"] for o in outline}
                    for s in sections:
                        if s not in have:
                            outline.append({"section": s, "points": ["（待正文填充）"]})
                    return outline
        except Exception:
            pass
        # 行式兜底
        outline, cur = [], None
        for line in text.splitlines():
            line = line.strip().lstrip("-*·0123456789.、 ")
            if not line:
                continue
            if line.rstrip("：:") in sections or any(line.startswith(s) for s in sections):
                cur = {"section": line.rstrip("：:"), "points": []}
                outline.append(cur)
            elif cur is not None and len(line) > 2:
                cur["points"].append(line)
        if outline:
            return outline
        raise LLMError("content", "大纲输出解析失败")
