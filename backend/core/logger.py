"""结构化日志（总纲 §6 安全风控）：data/logs/app-YYYYMMDD.log

格式：``时间|级别|task_id|消息``（'|' 分隔，时间 ISO8601 毫秒精度）
安全：日志中禁止出现 API Key —— 所有消息经 sanitize() 脱敏后落盘
（sk-... / Bearer ... / key= / token= / password= 等模式一律替换为 ***REDACTED***，
同时覆盖 config 中已解析的密钥原文）。
"""

from __future__ import annotations

import copy
import datetime
import logging
import re
import threading
from pathlib import Path

from config import PROJECT_ROOT, get_config  # backend 目录为运行根（统一绝对导入）

_SENSITIVE_PATTERNS = [
    re.compile(r"(?i)\b(sk-[A-Za-z0-9_\-]{8,})\b"),
    re.compile(r"(?i)\b(bearer\s+[A-Za-z0-9._~+/=\-]{8,})\b"),
    re.compile(r"(?i)(api[_-]?key['\"]?\s*[:=]\s*['\"]?[^\s'\"]{8,})"),
    re.compile(r"(?i)(token['\"]?\s*[:=]\s*['\"]?[^\s'\"]{8,})"),
    re.compile(r"(?i)(password['\"]?\s*[:=]\s*['\"]?[^\s'\"]{8,})"),
    re.compile(r"(?i)(secret['\"]?\s*[:=]\s*['\"]?[^\s'\"]{8,})"),
]
_REDACTED = "***REDACTED***"


def _config_secret_values() -> list:
    """收集 config 中已解析的密钥原文，用于整段替换。"""
    vals = []
    try:
        cfg = get_config()
        for dotted in (
            "wordpress.api_token",
            "wordpress.xmlrpc_username",
            "wordpress.xmlrpc_password",
            "models.api_key",
        ):
            v = cfg.get(dotted)
            if isinstance(v, str) and v:
                vals.append(v)
    except Exception:
        pass
    return vals


def sanitize(message: str) -> str:
    """脱敏：模式替换 + 密钥原文替换。"""
    if not isinstance(message, str):
        message = str(message)
    out = message
    for pat in _SENSITIVE_PATTERNS:
        out = pat.sub(_REDACTED, out)
    for secret in _config_secret_values():
        if secret and len(secret) >= 6 and secret in out:
            out = out.replace(secret, _REDACTED)
    return out


class DailyFileHandler(logging.Handler):
    """按日期滚动：data/logs/app-YYYYMMDD.log（日期变化自动切换文件）。"""

    def __init__(self, level: int = logging.NOTSET):
        super().__init__(level)
        self._date: str | None = None
        self._stream = None

    def _ensure_stream(self):
        today = datetime.date.today().strftime("%Y%m%d")
        if self._stream is None or today != self._date:
            if self._stream:
                try:
                    self._stream.close()
                except Exception:
                    pass
            cfg = get_config()
            log_dir = cfg.get("logging.dir", "data/logs")
            prefix = cfg.get("logging.file_prefix", "app")
            p = Path(log_dir)
            if not p.is_absolute():
                p = PROJECT_ROOT / p
            p.mkdir(parents=True, exist_ok=True)
            self._date = today
            self._stream = open(p / f"{prefix}-{today}.log", "a", encoding="utf-8")
        return self._stream

    def emit(self, record: logging.LogRecord) -> None:
        try:
            stream = self._ensure_stream()
            msg = sanitize(record.getMessage())
            task_id = getattr(record, "task_id", "") or ""
            ts = datetime.datetime.fromtimestamp(record.created).strftime(
                "%Y-%m-%d %H:%M:%S.%f"
            )[:-3]
            stream.write(f"{ts}|{record.levelname}|{task_id}|{msg}\n")
            stream.flush()
        except Exception:
            self.handleError(record)


class SanitizeFormatter(logging.Formatter):
    """控制台输出同样脱敏。"""

    def format(self, record: logging.LogRecord) -> str:
        r = copy.copy(record)
        r.msg = sanitize(record.getMessage())
        r.args = ()
        if r.exc_text:
            r.exc_text = sanitize(r.exc_text)
        return super().format(r)


_lock = threading.Lock()
_initialized = False


def _configure_logging() -> None:
    global _initialized
    with _lock:
        if _initialized:
            return
        root = logging.getLogger("ablog")
        if not root.handlers:
            cfg = get_config()
            level = getattr(logging, str(cfg.get("logging.level", "INFO")).upper(), logging.INFO)
            root.setLevel(level)
            root.addHandler(DailyFileHandler())
            console = logging.StreamHandler()
            console.setFormatter(SanitizeFormatter("%(asctime)s|%(levelname)s|%(task_id)s|%(message)s"))
            root.addHandler(console)
            root.propagate = False
        _initialized = True


def get_logger(name: str = "ablog") -> logging.Logger:
    _configure_logging()
    return logging.getLogger(name)


def _log(level: int, message: str, task_id: str = "") -> None:
    get_logger().log(level, message, extra={"task_id": task_id})


def debug(message: str, task_id: str = "") -> None:
    _log(logging.DEBUG, message, task_id)


def info(message: str, task_id: str = "") -> None:
    _log(logging.INFO, message, task_id)


def warning(message: str, task_id: str = "") -> None:
    _log(logging.WARNING, message, task_id)


def error(message: str, task_id: str = "") -> None:
    _log(logging.ERROR, message, task_id)


class TaskLogger:
    """绑定 task_id 的日志器。"""

    def __init__(self, task_id: str = ""):
        self.task_id = task_id

    def debug(self, message: str) -> None:
        debug(message, self.task_id)

    def info(self, message: str) -> None:
        info(message, self.task_id)

    def warning(self, message: str) -> None:
        warning(message, self.task_id)

    def error(self, message: str) -> None:
        error(message, self.task_id)
