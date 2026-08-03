"""SEO 工具（总纲 §7 适配要点 / 数据流第 6 步 SEO 优化）：

- generate_meta_description：Meta 描述生成规则
  标题（去尾句读） + 摘要 + 「关键词：…」收尾，总长 ≤ 150 字（中文按字符计），
  超长截断并以「…… 」省略号收尾，避免截断在词中间。
- keyword_density：关键词密度 = 关键词出现次数 × 关键词中文字符数 / 正文总字数 × 100。
  目标区间 0.3%–0.8%（可配），超出区间提示调整。
- extract_long_tail：长尾词植入辅助 —— 从正文中提取包含种子词的 n-gram 候选
  （min_len..max_len 字），供标题/正文改写时植入，去重保序。
"""

from __future__ import annotations

import re
from typing import List, Optional, Union

_CJK = re.compile(r"[\u4e00-\u9fff]")
_META_MAX_LEN = 150
_DENSITY_MIN = 0.3
_DENSITY_MAX = 0.8


def count_cjk(text: str) -> int:
    """统计中文字符数（密度计算的分母）。"""
    return len(_CJK.findall(text or ""))


def _clean_title(title: str) -> str:
    return re.sub(r"[\s|｜\-—_—,，。;；:：]+$", "", str(title).strip())


def generate_meta_description(
    title: str,
    excerpt: str,
    keywords: Optional[Union[str, List[str]]] = None,
    max_len: int = _META_MAX_LEN,
) -> str:
    """Meta 描述生成规则：
    1) 拼接 标题 + 摘要（中文句号连接，压缩空白）；
    2) 预留关键词尾巴空间（约 30 字），正文超长先截断；
    3) 末尾追加「关键词：kw1、kw2」，总长不超过 max_len。
    """
    max_len = max(20, int(max_len))
    parts = []
    if title:
        parts.append(_clean_title(title))
    if excerpt:
        parts.append(re.sub(r"\s+", " ", str(excerpt).strip()))
    base = "。".join(p for p in parts if p)

    kw = keywords or []
    if isinstance(kw, str):
        kw = [kw]
    kws = "、".join(str(k).strip() for k in kw if str(k).strip())

    tail = f"关键词：{kws}" if kws else ""
    budget = max_len - len(tail) - 1  # 预留 1 个句号
    if len(base) > budget:
        base = base[:budget].rstrip("。，、;； ")
        base += "……"

    if tail and len(base) + len(tail) + 1 <= max_len:
        return f"{base}。{tail}"
    return base[:max_len]


def keyword_density(text: str, keyword: str) -> float:
    """关键词密度（百分比）。

    - 中文关键词：次数 × 关键词中文字数 / 正文中文字数 × 100
    - 英文/数字关键词：按不区分大小写的出现次数 × 关键词长度 / 正文总长 × 100
    """
    if not text or not keyword:
        return 0.0
    kw = str(keyword).strip()
    if not kw:
        return 0.0
    kw_cjk = count_cjk(kw)
    if kw_cjk > 0:
        total = count_cjk(text)
        if total == 0:
            return 0.0
        count = len(re.findall(re.escape(kw), text))
        return count * kw_cjk / total * 100
    total = max(len(text), 1)
    count = len(re.findall(re.escape(kw), text, flags=re.IGNORECASE))
    return count * len(kw) / total * 100


def density_ok(density: float, min_pct: float = _DENSITY_MIN, max_pct: float = _DENSITY_MAX) -> bool:
    """目标密度区间判断（默认 0.3%–0.8%）。"""
    return min_pct <= density <= max_pct


def check_keyword_density(
    text: str,
    keyword: str,
    min_pct: float = _DENSITY_MIN,
    max_pct: float = _DENSITY_MAX,
) -> dict:
    """综合检查：返回密度与是否达标。"""
    d = keyword_density(text, keyword)
    return {
        "keyword": keyword,
        "density": round(d, 4),
        "target": [min_pct, max_pct],
        "ok": density_ok(d, min_pct, max_pct),
        "suggestion": (
            "ok"
            if density_ok(d, min_pct, max_pct)
            else ("密度偏低，建议在正文中自然植入关键词" if d < min_pct else "密度偏高，建议减少关键词堆砌")
        ),
    }


def extract_long_tail(
    text: str,
    seed: str,
    max_results: int = 5,
    min_len: int = 2,
    max_len: int = 8,
) -> List[str]:
    """从正文提取包含种子词的长尾候选（n-gram，min_len..max_len 字）。

    对种子词的每个出现位置，分别取「以 seed 结尾」和「以 seed 开头」的窗口短语，
    去重保序，返回最多 max_results 个。供标题/正文长尾词植入参考。
    """
    seed = str(seed).strip()
    if not seed or not text or min_len < 1 or max_len < min_len:
        return []
    found: List[str] = []
    seen = set()
    start = 0
    while True:
        idx = text.find(seed, start)
        if idx < 0:
            break
        for length in range(min_len, max_len + 1):
            # 以 seed 结尾的窗口
            s = idx + len(seed) - length
            if s >= 0:
                cand = text[s: idx + len(seed)]
                if len(cand) == length and seed in cand and cand not in seen:
                    seen.add(cand)
                    found.append(cand)
            # 以 seed 开头的窗口
            e = idx + length
            if e <= len(text):
                cand = text[idx: e]
                if len(cand) == length and seed in cand and cand not in seen:
                    seen.add(cand)
                    found.append(cand)
        start = idx + 1
        if len(found) >= max_results:
            break
    return found[:max_results]
