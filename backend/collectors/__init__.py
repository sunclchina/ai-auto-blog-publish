# -*- coding: utf-8 -*-
"""
backend/collectors — 采集层（B组）

模块：
  market.py       A股大盘数据（通达信本地 → 新浪 → 东财）
  tech_topics.py  IT 技术热点（固定问题池 + 可选 RSS）
  reading.py      国学素材（chinese-poetry 语料库，唐诗三百首优先）
  books.py        站点书目库（sunclnas.cn 藏书阁，过滤已写书目）

铁律：所有采集器网络/文件失败必须 try/except 兜底返回 None 字段，绝不抛异常。
"""

import os
import sys
import datetime as dt

_BACKEND_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _BACKEND_ROOT not in sys.path:
    sys.path.insert(0, _BACKEND_ROOT)

__all__ = ["market", "tech_topics", "reading", "books", "register_topic_providers"]


def register_topic_providers(config=None):
    """把四个采集器注册为调度层选题提供方（scheduler.daily_queue.register_topic_provider）。

    契约：fn(date: datetime.date, column: str) -> str 选题描述。
    采集失败返回空字符串（调度层 topic 保持为空，绝不伪造选题数据——原则2）。
    由调度层/启动入口在进程初始化时调用一次。
    """
    try:
        from scheduler.daily_queue import register_topic_provider
    except Exception as e:
        import logging
        logging.getLogger("ablog.collectors").warning("scheduler 未就绪，跳过选题提供方注册: %s", e)
        return

    def _provider(col):
        def _fn(date, column):
            try:
                if col == "stock":
                    from collectors.market import MarketCollector
                    m = MarketCollector(config).collect()
                    if not m.get("indices"):
                        return ""
                    idx = m["indices"][0]
                    sec = (m.get("sectors") or [{}])[0]
                    parts = [f"A股{date}复盘：{idx.get('name')}收{idx.get('close')}点"
                             f"（{idx.get('change_pct')}%）"]
                    if m.get("turnover"):
                        parts.append(f"两市成交{m['turnover']}亿")
                    if m.get("breadth"):
                        parts.append(f"涨{ m['breadth'].get('up')}家跌{ m['breadth'].get('down')}家")
                    if sec.get("name"):
                        parts.append(f"热点板块{sec['name']}（{sec.get('change_pct')}%）")
                    return "，".join(parts)
                if col == "tech":
                    from collectors.tech_topics import TechTopicCollector
                    qs = TechTopicCollector(config).collect(n=1)
                    return qs[0]["question"] if qs else ""
                if col == "reading":
                    from collectors.reading import ReadingCollector
                    poems = ReadingCollector(config).collect(n=1)
                    return f"《{poems[0]['title']}》{poems[0]['author']}" if poems else ""
                if col == "book":
                    from collectors.books import BooksCollector
                    from agents.base import build_core_adapter
                    core = build_core_adapter(config, None)
                    b = BooksCollector(config, core=core).collect()
                    return f"书评《{b['title']}》{b.get('author') or ''}".strip() if b else ""
                if col == "industry":
                    from collectors.industry import IndustryCollector
                    data = IndustryCollector(config).collect(limit=1, deep_top=0) or {}
                    inds = data.get("hot_industries") or []
                    return str(inds[0].get("name") or "") if inds else ""
            except Exception as e:
                import logging
                logging.getLogger("ablog.collectors").warning("选题提供方 %s 采集异常已兜底: %s", col, e)
                return ""
            return ""
        return _fn

    for col in ("stock", "tech", "reading", "book", "industry"):
        register_topic_provider(col, _provider(col))
    return True
