"""风控模块（总纲 §6 安全风控与成本保护）：敏感词过滤 / 每日额度记账 / 模型熔断。

- 敏感词：data/sensitive_words.txt（UTF-8，一行一词，# 注释行忽略），
  文件 mtime 变化自动热重载，可随时编辑。
- 每日额度：quota_daily 表（day / tokens_used / articles_published），
  按本地日期 YYYY-MM-DD 记账；Token 额度与发文数双限额，超额当日拦截。
- 模型熔断器：内存实现（进程内，不落库），同一模型连续失败
  max_failures 次 -> 暂停 cooldown_minutes 分钟；成功即复位。
- 黑名单：blacklist 表（kind=keyword|topic），选题/标题拦截用。
"""

from __future__ import annotations

import datetime
import threading
import time
from pathlib import Path
from typing import Dict, List, Optional, Tuple

from config import PROJECT_ROOT, get_config  # backend 目录为运行根（统一绝对导入）
from core import db, logger

# ---------------------------------------------------------------------------
# 敏感词
# ---------------------------------------------------------------------------

_sensitive_lock = threading.Lock()
_sensitive_cache: Dict[str, object] = {"mtime": None, "words": ()}


def _sensitive_words_path() -> Path:
    cfg = get_config()
    raw = cfg.get("data.sensitive_words_file", "data/sensitive_words.txt")
    p = Path(raw)
    if not p.is_absolute():
        p = PROJECT_ROOT / p
    return p


def sensitive_words() -> Tuple[str, ...]:
    """加载敏感词表（UTF-8 一行一词，# 开头为注释）。文件变更自动重载。"""
    with _sensitive_lock:
        path = _sensitive_words_path()
        try:
            mtime = path.stat().st_mtime
        except OSError:
            return _sensitive_cache["words"]  # type: ignore[return-value]
        if _sensitive_cache["mtime"] != mtime:
            words = []
            if path.exists():
                for line in path.read_text(encoding="utf-8").splitlines():
                    line = line.strip()
                    if line and not line.startswith("#"):
                        words.append(line)
            _sensitive_cache["mtime"] = mtime
            _sensitive_cache["words"] = tuple(words)
        return _sensitive_cache["words"]  # type: ignore[return-value]


def contains_sensitive(text: str) -> Tuple[bool, List[str]]:
    """返回 (是否命中, 命中的敏感词列表)。"""
    if not text:
        return False, []
    low = text.lower()
    hits = [w for w in sensitive_words() if w and w in low]
    return bool(hits), hits


def filter_sensitive(text: str, replacement: str = "*") -> str:
    """敏感词打码（不区分大小写替换，保持原文长度）。"""
    out = text
    for w in sensitive_words():
        if not w:
            continue
        import re
        out = re.sub(re.escape(w), replacement * len(w), out, flags=re.IGNORECASE)
    return out


# ---------------------------------------------------------------------------
# 每日额度（quota_daily 表）
# ---------------------------------------------------------------------------

def today() -> str:
    return datetime.date.today().isoformat()


def get_daily_quota(day: Optional[str] = None) -> Dict[str, object]:
    day = day or today()
    row = db.query_one("SELECT day, tokens_used, articles_published FROM quota_daily WHERE day=?", (day,))
    if row:
        return row
    return {"day": day, "tokens_used": 0, "articles_published": 0}


def token_quota_limit() -> int:
    return int(get_config().get("daily.token_quota_per_day", 200000))


def article_quota_limit() -> int:
    return int(get_config().get("daily.articles_per_day", 3))


def check_token_quota(tokens: int, day: Optional[str] = None) -> Tuple[bool, int]:
    """检查今日 Token 额度：返回 (是否允许, 剩余额度)。"""
    row = get_daily_quota(day)
    used = int(row["tokens_used"])
    limit = token_quota_limit()
    return (used + tokens) <= limit, max(limit - used, 0)


def add_token_usage(tokens: int, day: Optional[str] = None) -> None:
    """记账：累加当日 Token 消耗（负值用于回滚）。"""
    day = day or today()
    with db.transaction() as conn:
        conn.execute(
            """INSERT INTO quota_daily (day, tokens_used, articles_published)
               VALUES (?, ?, 0)
               ON CONFLICT(day) DO UPDATE SET tokens_used = tokens_used + ?""",
            (day, tokens, tokens),
        )


def check_article_quota(day: Optional[str] = None) -> Tuple[bool, int]:
    """检查每日发文上限：返回 (是否允许, 剩余篇数)。"""
    row = get_daily_quota(day)
    used = int(row["articles_published"])
    limit = article_quota_limit()
    return used < limit, max(limit - used, 0)


def add_article_published(day: Optional[str] = None) -> None:
    day = day or today()
    with db.transaction() as conn:
        conn.execute(
            """INSERT INTO quota_daily (day, tokens_used, articles_published)
               VALUES (?, 0, 1)
               ON CONFLICT(day) DO UPDATE SET articles_published = articles_published + 1""",
            (day,),
        )


# ---------------------------------------------------------------------------
# 模型熔断器（内存实现）
# ---------------------------------------------------------------------------

class CircuitBreaker:
    """内存熔断器：同一模型连续失败 max_failures 次 -> 冷却 cooldown_minutes 分钟。

    Memory 实现（进程内字典 + 锁），不落库；服务重启后熔断状态复位。
    冷却结束后进入半开状态：下次调用自动复位失败计数并放行。
    """

    def __init__(self, max_failures: int = 5, cooldown_minutes: int = 30):
        self.max_failures = max(1, int(max_failures))
        self.cooldown_seconds = max(0, int(cooldown_minutes)) * 60
        self._state: Dict[str, Dict[str, float]] = {}   # model -> {"fails": n, "open_until": ts}
        self._lock = threading.Lock()

    @classmethod
    def from_config(cls) -> "CircuitBreaker":
        cfg = get_config().get("circuit_breaker", {})
        return cls(
            max_failures=int(cfg.get("max_failures", 5)),
            cooldown_minutes=int(cfg.get("cooldown_minutes", 30)),
        )

    def record_failure(self, model: str) -> bool:
        """记录一次失败；达到阈值返回 True（本次调用使熔断器开启）。"""
        with self._lock:
            st = self._state.setdefault(model, {"fails": 0.0, "open_until": 0.0})
            st["fails"] += 1
            if st["fails"] >= self.max_failures:
                st["open_until"] = time.time() + self.cooldown_seconds
                logger.warning(
                    f"circuit breaker OPEN model={model} fails={int(st['fails'])} "
                    f"cooldown={self.cooldown_seconds}s"
                )
                return True
            return False

    def record_success(self, model: str) -> None:
        with self._lock:
            self._state.pop(model, None)

    def is_open(self, model: str) -> bool:
        """熔断开启（冷却期内）返回 True；冷却结束自动半开复位。"""
        with self._lock:
            st = self._state.get(model)
            if not st:
                return False
            if st["fails"] < self.max_failures:
                return False
            if time.time() < st["open_until"]:
                return True
            # 冷却结束：半开复位，放行下一次调用
            st["fails"] = 0.0
            st["open_until"] = 0.0
            return False

    def get_state(self, model: str) -> Dict[str, object]:
        with self._lock:
            st = self._state.get(model)
            if not st:
                return {"model": model, "fails": 0, "open": False, "remaining_seconds": 0}
            remaining = max(0.0, st["open_until"] - time.time())
            return {
                "model": model,
                "fails": int(st["fails"]),
                "open": st["fails"] >= self.max_failures and remaining > 0,
                "remaining_seconds": int(remaining),
            }

    def snapshot(self) -> List[Dict[str, object]]:
        with self._lock:
            return [self.get_state(m) for m in list(self._state.keys())]


_breaker: Optional[CircuitBreaker] = None


def get_breaker() -> CircuitBreaker:
    global _breaker
    if _breaker is None:
        _breaker = CircuitBreaker.from_config()
    return _breaker


# ---------------------------------------------------------------------------
# 黑名单（blacklist 表，kind=keyword|topic）
# ---------------------------------------------------------------------------

def add_blacklist(word: str, kind: str = "keyword") -> None:
    word = word.strip()
    if not word or kind not in ("keyword", "topic"):
        return
    db.execute("INSERT OR IGNORE INTO blacklist (word, kind) VALUES (?, ?)", (word, kind))


def remove_blacklist(word: str, kind: Optional[str] = None) -> None:
    if kind:
        db.execute("DELETE FROM blacklist WHERE word=? AND kind=?", (word, kind))
    else:
        db.execute("DELETE FROM blacklist WHERE word=?", (word,))


def is_blacklisted(text: str, kind: Optional[str] = None) -> Tuple[bool, List[str]]:
    """检查文本是否命中黑名单。kind 为空则 keyword/topic 都查。"""
    if not text:
        return False, []
    if kind:
        rows = db.query("SELECT word FROM blacklist WHERE kind=?", (kind,))
    else:
        rows = db.query("SELECT word FROM blacklist")
    hits = [r["word"] for r in rows if r["word"] and r["word"] in text]
    return bool(hits), hits
