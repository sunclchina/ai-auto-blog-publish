# -*- coding: utf-8 -*-
"""
collectors/books.py — 站点书目库采集（B组：采集层）

抓取 https://sunclnas.cn/藏书阁书目【电子书】 解析书目列表；
过滤已写书目（written_books 表，经 CoreAdapter）；随机抽取一本。
抓取失败返回 None → 由调度层跳过该栏目（绝不抛异常）。
"""

import os
import sys
import re
import random
import logging

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

try:
    import httpx
except ImportError:  # pragma: no cover
    httpx = None

logger = logging.getLogger("ablog.collectors.books")

DEFAULT_BOOKS_URL = "https://sunclnas.cn/%e8%97%8f%e4%b9%a6%e9%98%81%e4%b9%a6%e7%9b%ae%e3%80%90%e7%94%b5%e5%ad%90%e4%b9%a6%e3%80%91"


class BooksCollector:
    """站点书目采集器。构造：BooksCollector(config, core=None)"""

    def __init__(self, config=None, core=None):
        cfg = config or {}
        self.url = (cfg.get("books", {}) or {}).get("url") or DEFAULT_BOOKS_URL
        self.timeout = (cfg.get("books", {}) or {}).get("timeout", 15)
        # 已写书目过滤依赖 core/db（core 未就绪时通过 adapter 拿空集合，不误杀）
        if core is not None and hasattr(core, "written_books"):
            self.core = core
        else:
            from agents.base import build_core_adapter
            self.core = build_core_adapter(cfg, None)

    # ------------------------------------------------------------------
    def collect(self):
        """抓取书目列表 → 过滤已写 → 随机抽一本。
        返回 dict {"title","author","source_url"}；失败返回 None（调度层跳过该栏目）。"""
        books = self._fetch_books()
        if not books:
            return None
        written = self.core.written_books()
        fresh = [b for b in books if b["title"] not in written]
        if not fresh:
            logger.info("书目库中所有书目均已写过，返回 None 由调度层跳过")
            return None
        pick = random.choice(fresh)
        pick["source_url"] = self.url
        return pick

    # ------------------------------------------------------------------
    def fetch_all(self):
        """抓取全部书目（调试/人工挑选用）。失败返回 []。"""
        return self._fetch_books()

    def _fetch_books(self):
        """抓取并解析页面中的《书名》 + 作者。"""
        if httpx is None:
            return []
        try:
            resp = httpx.get(self.url, timeout=self.timeout, follow_redirects=True,
                             headers={"User-Agent": "Mozilla/5.0 (A-Blog collector)"})
            resp.raise_for_status()
            text = resp.text
            # 页面为 WP 列表页：书目形如 《书名》 , 作者。两套正则兜底：
            # 1) 优先抓 <li>/<p> 结构行（排除侧栏「最近评论」widget：评论标题带《》会污染书目）
            lines = re.findall(r"<li[^>]*>(.*?)</li>", text, re.S)
            lines = [l for l in lines if "recentcomments" not in l and "\u53d1\u8868\u5728" not in l]  # 发表在
            if not lines:
                lines = re.findall(r"<p[^>]*>(.*?)</p>", text, re.S)
            books = []
            for line in lines:
                line = re.sub(r"<[^>]+>", "", line).strip()
                m = re.search(r"《(.+?)》\s*[，,、]?\s*(.*)", line)
                if m:
                    title = m.group(1).strip()
                    author = m.group(2).strip().rstrip("，,").strip()
                    if title:
                        books.append({"title": title, "author": author})
            if not books:
                # 2) 整页文本兜底（《书名》 作者 模式）
                plain = re.sub(r"<[^>]+>", " ", text)
                for m in re.finditer(r"《([^》]{2,60})》\s*[，,、]?\s*([^《\n]{0,40})", plain):
                    title = m.group(1).strip()
                    author = m.group(2).strip().rstrip("，,、").strip()
                    books.append({"title": title, "author": author})
            # 去重保序
            seen, out = set(), []
            for b in books:
                if b["title"] and b["title"] not in seen:
                    seen.add(b["title"])
                    out.append(b)
            logger.info("书目抓取成功 %d 条", len(out))
            return out
        except Exception as e:
            logger.warning("书目抓取失败: %s", e)
            return []
