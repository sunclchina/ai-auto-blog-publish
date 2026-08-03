# -*- coding: utf-8 -*-
"""
collectors/industry.py — A股热门行业/概念采集（C组：采集层）

两阶段采集（翁老要求：文章必须有最新数据、时效性、热门原因、公司数据）：
  阶段1：Tavily 搜索 A股最近一周热门行业/概念/板块，提炼候选；
  阶段2：对选定的 1-2 个热门行业做专题深挖，多组查询收集：
         - 市场规模/增速数据（market）
         - 龙头公司业绩/股价/动态（companies）
         - 政策/事件（policy）
         - 最近动态与上涨原因（dynamics，时效性+热门原因）
输出：
    {
      "hot_industries": [{"name", "reason", "source"}],
      "deep": { "<行业名>": {"market": [...], "companies": [...], "policy": [...], "dynamics": [...]} },
      "news": [...]
    }
铁律：网络失败静默降级（空结构），绝不抛异常。
"""

import os
import sys
import logging

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

try:
    from core.search import search
except Exception:  # pragma: no cover
    search = None

logger = logging.getLogger("ablog.collectors.industry")


class IndustryCollector:
    """A股热门行业/概念采集器（两阶段：热门发现 + 专题深挖）。"""

    def __init__(self, cfg=None):
        self.cfg = cfg or {}

    def collect(self, limit: int = 3, deep_top: int = 1):
        """返回热门行业候选 + 每个候选的深挖素材（deep_top 默认 1：控制总耗时）。"""
        result = {"hot_industries": [], "deep": {}, "news": [], "source_chain": []}
        if search is None:
            return result

        # ---- 阶段1：热门行业/概念发现 ----
        seen = set()
        for q in [
            "A股 本周 热门行业 概念 板块 涨幅",
            "A股 热点板块 一周 主线 回顾",
            "A股 涨停 板块 资金 流入 本周",
        ]:
            try:
                hits = search(q, max_results=5, topic="finance", time_range="week")
            except Exception as e:
                logger.warning("industry hot search failed q=%s err=%s", q, e)
                hits = []
            for h in hits or []:
                title = str(h.get("title") or "").strip()
                if not title:
                    continue
                result["news"].append({
                    "title": title,
                    "url": h.get("url", ""),
                    "snippet": str(h.get("snippet") or h.get("content") or "")[:300],
                })
                for kw in _extract_industry_keywords(title):
                    if kw not in seen:
                        seen.add(kw)
                        result["hot_industries"].append({
                            "name": kw,
                            "reason": title[:100],
                            "source": "tavily",
                        })
            if len(result["hot_industries"]) >= limit * 3:
                break
        result["hot_industries"] = result["hot_industries"][: limit * 3]

        # ---- 阶段2：对前 deep_top 个热门行业专题深挖 ----
        for ind in result["hot_industries"][:deep_top]:
            name = ind["name"]
            result["deep"][name] = self._deep_search(name)

        result["source_chain"].append("tavily" if result["news"] else "search_failed")
        return result

    def _deep_search(self, name: str) -> dict:
        """对一个行业做专题深挖：数据 / 公司 / 政策 / 动态。"""
        out = {"market": [], "companies": [], "policy": [], "dynamics": []}
        queries = {
            "market": [f"{name} 市场规模 2026 增速 数据"],
            "companies": [f"{name} A股 龙头 上市公司 业绩 最新"],
            "policy": [f"{name} 政策 最新 支持 规划"],
            "dynamics": [f"{name} 最新 动态 消息 上涨 原因"],
        }
        for key, qs in queries.items():
            for q in qs:
                try:
                    hits = search(q, max_results=4, topic="finance", time_range="month")
                except Exception as e:
                    logger.warning("industry deep search failed q=%s err=%s", q, e)
                    hits = []
                for h in hits or []:
                    title = str(h.get("title") or "").strip()
                    if not title:
                        continue
                    snippet = str(h.get("snippet") or h.get("content") or "")[:300]
                    out[key].append({
                        "title": title,
                        "url": h.get("url", ""),
                        "snippet": snippet,
                        # 含数字的素材更可能是数据/业绩类，标记权重
                        "has_number": bool(re_search_digit(title + snippet)),
                    })
        # 含数字的排前面（数据优先）
        for key in out:
            out[key].sort(key=lambda x: not x["has_number"])
            out[key] = out[key][:4]
        return out


def re_search_digit(text: str) -> bool:
    import re
    return bool(re.search(r"\d", text or ""))


def _extract_industry_keywords(title: str):
    """从搜索标题粗提取行业/概念词。"""
    import re
    kws = []
    stop = ("热点板块", "主线板块", "炒股", "复盘", "收评", "午评", "早盘", "尾盘")
    for m in re.finditer(r"([\u4e00-\u9fffA-Za-z0-9]{2,10}(?:行业|概念|产业链|板块))", title):
        k = m.group(1)
        if not any(s in k for s in stop):
            kws.append(k)
    for m in re.finditer(r"([\u4e00-\u9fff]{2,8}(?:龙头|概念股))", title):
        k = m.group(1)
        if not any(s in k for s in stop):
            kws.append(k)
    return kws[:4]
