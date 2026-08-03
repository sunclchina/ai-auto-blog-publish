# -*- coding: utf-8 -*-
"""
core/search.py — 联网搜索模块（Tavily API）

让 AI 生成流程具备"自动搜索"能力（翁老需求：解决模型联网问题）：
- 正文生成前对选题自动联网搜索，补充最新素材（标题/摘要/链接）
- 复盘栏目（stock）不启用：行情数据必须走结构化采集，防搜索引入错误数字
- 失败静默降级：搜索不可用时返回 []，绝不阻断生成

配置（config.yaml search 段）：
  search.enabled: true
  search.api_key_env: "TAVILY_API_KEY"   # 或 api_key_file
  search.max_results: 5
"""

from __future__ import annotations

import os
from typing import Dict, List, Optional

import httpx

from config import get_config


def search(query: str, max_results: int = 5, time_range: Optional[str] = None,
           topic: str = "general") -> List[Dict[str, str]]:
    """Tavily 联网搜索。返回 [{"title", "url", "content"}]；不可用时返回 []。"""
    cfg = get_config()
    if not bool(cfg.get("search.enabled", True)):
        return []
    key = str(cfg.get("search.api_key", "") or "").strip()
    if not key:
        key = os.environ.get("TAVILY_API_KEY", "").strip()
    if not key or not query:
        return []
    try:
        payload: dict = {
            "api_key": key,
            "query": str(query)[:380],
            "max_results": max(1, min(int(max_results), 10)),
            "search_depth": "basic",
            "topic": topic,
        }
        if time_range in ("day", "week", "month", "year"):
            payload["time_range"] = time_range
        # Tavily 国际端点较慢（实测 TLS 握手 5-8s），超时放宽 + 重试 2 次
        last_err = None
        for attempt in range(3):
            try:
                resp = httpx.post("https://api.tavily.com/search", json=payload, timeout=45)
                if resp.status_code != 200:
                    return []
                data = resp.json()
                out = []
                for r in (data.get("results") or [])[:max_results]:
                    out.append({
                        "title": str(r.get("title") or "")[:200],
                        "url": str(r.get("url") or ""),
                        "content": str(r.get("content") or "")[:600],
                    })
                return out
            except Exception as e:
                last_err = e
                continue
        return []
    except Exception:
        return []


def search_available() -> bool:
    """搜索能力是否可用（配置 + Key）。"""
    cfg = get_config()
    key = str(cfg.get("search.api_key", "") or "").strip()
    if not key:
        key = os.environ.get("TAVILY_API_KEY", "").strip()
    return bool(cfg.get("search.enabled", True)) and bool(key)
