# -*- coding: utf-8 -*-
"""
agents/content.py — Step4 正文智能体

按大纲 + 栏目规范逐节填充，输出 HTML（h2/h3/p/blockquote/pre>code/strong）。
长度验收（中文字数）：stock 1500-2500 / tech 2000-3500 / reading 1000-2000 / book 1200-2500。
stock 栏目强制携带风险提示句（“不构成投资建议”）。
"""

import os
import sys
import re

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from agents.base import BaseAgent, LLMError  # noqa: E402

LENGTH_SPEC = {
    "stock": (1500, 2500),
    "tech": (2000, 3500),
    "reading": (1000, 2000),
    "book": (1200, 2500),
    "industry": (1800, 2600),
}

RISK_SENTENCE = "（以上内容仅为个人复盘笔记，不构成任何投资建议。市场有风险，投资需谨慎。）"


class ContentAgent(BaseAgent):
    step = 4
    step_name = "content"

    def __init__(self, config=None, core=None, dry_run=False):
        super().__init__(config, core, dry_run)

    # ------------------------------------------------------------------
    def generate(self, column, topic, outline, material=None, final_title=None):
        """生成正文 HTML。返回 {"content_html", "char_count", "length_ok", "regenerated": bool}"""
        if column not in LENGTH_SPEC:
            raise LLMError("content", f"未知栏目: {column}")
        # 生成前自动联网搜索补充素材（RAG 式；复盘除外）
        material = self._augment_with_search(column, topic, material)
        outline_data = outline if isinstance(outline, list) else (outline or {}).get("sections", [])
        if not outline_data:
            raise LLMError("content", "大纲为空，无法生成正文")

        spec = self.load_prompt(f"{column}.md") if column in ("stock", "tech", "reading", "industry") else self.load_prompt("reading.md")
        min_len, max_len = LENGTH_SPEC[column]

        prompt = self._build_prompt(column, topic, outline_data, material, final_title, spec, min_len, max_len)
        messages = [
            {"role": "system", "content": "你是资深内容写作者。只输出 HTML 正文（不含 html/body 标签），严格遵守结构与字数要求。"},
            {"role": "user", "content": prompt},
        ]
        text, tokens = self.call_llm(messages, model=self.model_for(column), max_tokens=4000, temperature=0.7)

        html = self._sanitize_html(text)
        count = self.zh_len(self.strip_html(html))

        regenerated = False
        # 长度不达标 → 自动再生成一次（Step4 失败策略内的一次补写）
        if not self.dry_run and count < int(min_len * 0.6):
            messages2 = [
                {"role": "system", "content": "你是资深内容写作者。上一版字数不足，请扩写至目标字数，只输出 HTML 正文。"},
                {"role": "user", "content": prompt + f"\n\n上一版正文仅 {count} 字（要求 {min_len}-{max_len} 字），请扩写重写。"},
            ]
            text2, tokens2 = self.call_llm(messages2, model=self.model_for(column), max_tokens=5000, temperature=0.7)
            html2 = self._sanitize_html(text2)
            if self.zh_len(self.strip_html(html2)) > count:
                html, tokens = html2, tokens + tokens2
                count = self.zh_len(self.strip_html(html))
                regenerated = True

        length_ok = min_len <= count <= max_len
        if not length_ok:
            # 短于下限时插入扩写占位段会污染正文，这里仅记录告警（由 pipeline 判定是否需要重试）
            pass
        return {
            "content_html": html,
            "char_count": count,
            "length_ok": length_ok,
            "length_spec": [min_len, max_len],
            "regenerated": regenerated,
            "tokens_used": tokens,
        }

    # ------------------------------------------------------------------
    def _augment_with_search(self, column, topic, material):
        """生成前自动联网搜索（翁老需求：解决模型联网问题）。

        - 复盘（stock）：搜当日财经新闻/市场综述（软信息：政策、板块、资金动向），
          行情硬数据仍以结构化采集素材为准（prompt 中约束）
        - 其他栏目：对选题自动搜索补充背景/时效素材
        - 搜索失败静默跳过，不影响生成
        """
        material = dict(material or {})
        if material.get("_searched"):
            return material
        try:
            from core.search import search
            results = []
            if column == "stock":
                # 搜索目标日期（历史复盘搜该日新闻，当日复盘搜今日）
                day_str = str((material or {}).get("date") or datetime.date.today().isoformat())
                try:
                    d = datetime.date.fromisoformat(day_str)
                    day = f"{d.year}年{d.month}月{d.day}日"
                except ValueError:
                    day = datetime.date.today().strftime("%Y年%m月%d日")
                results = search(f"{day} A股 收盘 复盘", max_results=5, topic="finance", time_range="day")
                if not results:
                    results = search("A股 大盘 板块 资金", max_results=5, time_range="day")
            else:
                q = str(topic or "")[:80]
                if q:
                    results = search(q, max_results=4)
            if results:
                material["web_results"] = results
        except Exception:
            pass
        material["_searched"] = True
        return material

    # ------------------------------------------------------------------
    def model_for(self, column):
        from agents.base import model_for_column
        return model_for_column(self.config, column)

    def _build_prompt(self, column, topic, outline_data, material, final_title, spec, min_len, max_len):
        outline_lines = "\n".join(
            f"## {o['section']}\n" + "\n".join(f"- {p}" for p in o.get("points", []))
            for o in outline_data
        )
        material_summary = self._material_hint(column, material)
        risk_line = RISK_SENTENCE if column == "stock" else ""
        # 素材数据置顶（stock 尤其重要：行情数字必须照用素材，禁止编造）
        web_hint = self._web_hint(material, column)
        data_block = (
            "【今日真实数据（最高优先级，必须严格照用，禁止编造或臆测任何数字）】\n"
            f"{material_summary}\n\n"
            f"{web_hint}\n"
        )
        return (
            f"栏目：{column}\n发布标题：{final_title or topic}\n选题：{topic}\n\n"
            f"{data_block}"
            f"栏目写作规范（backend/prompts/{column}.md 全文）：\n{spec}\n\n"
            f"大纲：\n{outline_lines}\n\n"
            f"写作要求：\n"
            f"1. 逐节填充，每节以 <h2> 起，小节可用 <h3>\n"
            f"2. 正文用 <p>；引用/原文用 <blockquote>；tech 栏目代码用 <pre><code>...</code></pre>\n"
            f"3. stock 栏目：文中出现的所有指数点位、涨跌幅、成交额、资金数据必须与【今日真实数据】一致，素材里没有的数据不得编造，宁缺毋滥\n"
            f"4. stock 栏目关键数据（点位/涨跌幅/成交额）用 <strong> 加粗\n"
            f"5. 全文（含标点）目标字数 {min_len}-{max_len} 字\n"
            f"6. 语言自然，避免“首先/其次/总之/值得注意的是”等 AI 高频词\n"
            f"7. {risk_line}\n"
            f"仅输出 HTML 正文。"
        )

    def _material_hint(self, column, material):
        if not material:
            return "无"
        if column == "stock":
            idx = material.get("indices") or []
            bd = material.get("breadth") or {}
            sec = material.get("sectors") or []
            turnover = material.get("turnover")
            recent = material.get("recent") or {}
            lines = [f"指数 {i.get('name')} 收盘{i.get('close')} 涨跌{i.get('change_pct')}% 成交额{i.get('amount_yi')}亿" for i in idx[:5]]
            if turnover:
                lines.append(f"两市合计成交额{turnover}亿元（口径：沪深京，数据源真实采集）")
            if bd:
                lines.append(f"涨跌家数 上涨{bd.get('up')} 下跌{bd.get('down')} 平盘{bd.get('flat')}")
            if sec:
                lines.append("涨幅榜板块: " + "、".join(f"{s.get('name')}{s.get('change_pct')}%（领涨股:{s.get('leader') or 'N/A'}）" for s in sec[:6]))
            # 近期走势（技术面/量能对比）
            if recent:
                rl = []
                for code in ("sh000001", "sz399001", "sz399006"):
                    rows = recent.get(code) or []
                    if rows:
                        closes = [r["close"] for r in rows]
                        amts = [r.get("amount_yi") or 0 for r in rows]
                        ma5 = round(sum(closes) / len(closes), 2)
                        avg_amt = round(sum(amts) / len(amts), 1) if amts else None
                        rl.append(f"{code} 近{len(rows)}日收盘均值(MA5)≈{ma5} 日均成交额≈{avg_amt}亿（{rows[0]['date']}~{rows[-1]['date']}）")
                if rl:
                    lines.append("近期走势: " + "; ".join(rl))
            lines.append("【硬性要求】正文中的指数点位、涨跌幅、成交额、资金数据必须严格使用上述素材数值，禁止自行编造或臆测任何行情数据；素材缺失的维度宁可不写也不要编造；技术面尽量用【近期走势】做均线/量能对比；板块分析给出涨幅与领涨股。")
            return "\n".join(lines) or "大盘数据缺失"
        if column == "industry":
            inds = material.get("hot_industries") or []
            deep = material.get("deep") or {}
            lines = []
            if inds:
                lines.append("最近一周热门行业/概念：" + "、".join(i.get("name", "") for i in inds[:5]))
            for name, d in deep.items():
                lines.append(f"——【{name}】专题素材——")
                if d.get("market"):
                    lines.append("· 市场规模/数据：" + "；".join(f"{i['title']}（{i.get('snippet', '')[:120]}）" for i in d["market"][:3]))
                if d.get("companies"):
                    lines.append("· 公司业绩/动态：" + "；".join(f"{i['title']}（{i.get('snippet', '')[:120]}）" for i in d["companies"][:3]))
                if d.get("policy"):
                    lines.append("· 政策/事件：" + "；".join(f"{i['title']}（{i.get('snippet', '')[:100]}）" for i in d["policy"][:2]))
                if d.get("dynamics"):
                    lines.append("· 最新动态/热门原因：" + "；".join(f"{i['title']}（{i.get('snippet', '')[:120]}）" for i in d["dynamics"][:3]))
            if not lines:
                lines.append("行业素材缺失")
            lines.append("【硬性要求】正文必须大量引用上述素材中的具体数据（规模/增速/业绩/涨幅等）并标注来源时间；公司分析必须给出每家公司的具体数据或明确说明数据暂缺；体现最近一周的时效性与热门原因；素材没有的数字禁止编造。")
            return "\n".join(lines)
        if column == "tech":
            qs = material.get("questions") or []
            return "\n".join(f"- {q.get('question')}" for q in qs[:5])
        if column == "reading":
            poems = material.get("poems") or []
            lines = []
            for p in poems[:3]:
                lines.append(f"《{p.get('title')}》{p.get('author')}（{p.get('source')}）\n" + "\n".join(p.get("paragraphs") or []))
            return "\n".join(lines)
        if column == "book":
            b = material.get("book") or {}
            return f"《{b.get('title')}》{b.get('author')}（{b.get('source_url')}）"
        return "无"

    def _web_hint(self, material, column=""):
        """联网搜索结果注入。

        - stock：仅作当日新闻/政策/板块背景引用，行情数字必须用【今日真实数据】，禁止用搜索数字
        - 其他栏目：背景与时效参考，不得编造成具体数字
        """
        results = (material or {}).get("web_results") or []
        if not results:
            return ""
        if column == "stock":
            head = "\n【当日联网资讯（新闻/政策/板块/资金动态，仅作背景引用；文中所有行情数字必须严格使用上面【今日真实数据】，禁止使用资讯中的数字）】"
        else:
            head = "\n【联网检索参考（标题/摘要/链接，仅作背景与时效参考，不得编造成具体数字）】"
        lines = [head]
        for r in results[:4]:
            lines.append(f"- {r.get('title', '')}\n  {r.get('content', '')[:200]}\n  来源: {r.get('url', '')}")
        return "\n".join(lines)

    def _sanitize_html(self, text):
        """清洗 LLM 输出为纯正文 HTML：去掉 markdown 代码围栏与 html 外壳。"""
        text = text.strip()
        text = re.sub(r"^```(?:html)?\s*", "", text)
        text = re.sub(r"\s*```$", "", text)
        text = re.sub(r"^<html.*?>", "", text, flags=re.S)
        text = re.sub(r"</html>\s*$", "", text)
        # 兜底：若输出是纯文本（无任何标签），按段落包 <p>
        if "<" not in text or not re.search(r"</?(h2|h3|p|blockquote|pre|strong|ul|ol|li|table)\b", text):
            paras = [p.strip() for p in re.split(r"\n\s*\n|\n", text) if p.strip()]
            text = "\n".join(f"<p>{p}</p>" for p in paras)
        return text.strip()
