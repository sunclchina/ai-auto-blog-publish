"""WP REST API 发布（主通道，总纲 §3.2 Python ↔ WP 插件 REST 契约）。

- 端点：POST {base_url}/wp-json/ai-auto-blog/v1/articles
- 认证：Authorization: Bearer <ABP_API_TOKEN>（config 解析，不入日志）
- 请求体：任务对象 JSON（总纲 §3.1 核心字段）
- 响应：{"ok": true, "post_id": 123, "permalink": "..."} 或 {"ok": false, "error": "..."}
- 重试策略：5xx 重试 retry_times 次（指数退避 2^n 秒）；4xx 不重试；
  网络类错误按可重试处理。
- 附加端点（契约内）：
  GET /health、GET /categories、POST /check（指纹查重）、GET /written-books

返回 post_id：publish() 成功返回 {"ok": True, "post_id": int, "permalink": str}。
"""

from __future__ import annotations

import time
from typing import Any, Dict, List, Optional

import httpx

from config import get_config  # backend 目录为运行根（统一绝对导入）
from core.logger import get_logger

log = get_logger("wp_rest")


class PublishError(Exception):
    """发布失败。retryable=True 表示可重试（5xx/网络错误），可切换 XML-RPC 兜底。"""

    def __init__(self, message: str, status_code: Optional[int] = None,
                 retryable: bool = False, response_body: Optional[str] = None):
        super().__init__(message)
        self.message = message
        self.status_code = status_code
        self.retryable = retryable
        self.response_body = response_body

    def __str__(self) -> str:
        return f"{self.message} (status={self.status_code}, retryable={self.retryable})"


def _config() -> tuple:
    cfg = get_config()
    base = str(cfg.get("wordpress.base_url", "")).rstrip("/")
    path = str(cfg.get("wordpress.rest_path", "/wp-json/ai-auto-blog/v1"))
    token = str(cfg.get("wordpress.api_token", ""))
    timeout = float(cfg.get("wordpress.timeout_seconds", 30))
    retries = int(cfg.get("publish.retry_times", 2))
    backoff = float(cfg.get("publish.retry_backoff_base", 2.0))
    return base, path, token, timeout, retries, backoff


def is_configured() -> bool:
    return bool(str(get_config().get("wordpress.api_token", "")))


def _headers(token: str) -> dict:
    return {
        "Authorization": f"Bearer {token}",
        "Content-Type": "application/json",
        "Accept": "application/json",
        "User-Agent": "A-Blog/1.0",
    }


def _endpoint(suffix: str = "") -> str:
    base, path, *_ = _config()
    return f"{base}{path}{suffix}"


def _sleep_backoff(backoff: float, attempt: int) -> None:
    delay = backoff ** (attempt + 1)   # 2^1=2s, 2^2=4s
    log.debug(f"retry backoff sleep={delay}s attempt={attempt + 1}")
    time.sleep(delay)


def _request_with_retry(method: str, url: str, *, json: Optional[dict] = None,
                        token: str, timeout: float, retries: int, backoff: float,
                        task_id: str = "") -> httpx.Response:
    """统一重试：网络错误与 5xx 重试（指数退避）；4xx 直接抛 PublishError。"""
    last_err: Optional[PublishError] = None
    for attempt in range(retries + 1):
        try:
            resp = httpx.request(method, url, json=json, headers=_headers(token), timeout=timeout)
        except httpx.HTTPError as e:
            last_err = PublishError(f"network error {e.__class__.__name__}: {e}", retryable=True)
            if attempt < retries:
                log.warning(f"request network error attempt={attempt + 1} retrying url={url}", task_id=task_id)
                _sleep_backoff(backoff, attempt)
                continue
            raise last_err

        if 200 <= resp.status_code < 300:
            return resp
        if 400 <= resp.status_code < 500:
            raise PublishError(
                f"4xx rejected: {resp.text[:300]}",
                status_code=resp.status_code, retryable=False, response_body=resp.text[:300],
            )
        # 5xx
        if attempt < retries:
            log.warning(f"5xx status={resp.status_code} attempt={attempt + 1} retrying url={url}", task_id=task_id)
            _sleep_backoff(backoff, attempt)
            continue
        raise PublishError(
            f"5xx after {retries + 1} attempts: {resp.status_code}",
            status_code=resp.status_code, retryable=True, response_body=resp.text[:300],
        )
    raise last_err or PublishError("unexpected request failure", retryable=True)


# ---------------------------------------------------------------------------
# 任务对象 -> REST payload（总纲 §3.1）
# ---------------------------------------------------------------------------

def _task_to_payload(task: dict) -> dict:
    return {
        "task_id": task.get("task_id", ""),
        "column": task.get("column") or task.get("column_name", ""),
        "topic": task.get("topic", ""),
        "final_title": task.get("final_title") or task.get("title", ""),
        "content_html": task.get("content_html") or task.get("content", ""),
        "excerpt": task.get("excerpt", ""),
        "meta_description": task.get("meta_description", ""),
        "tags": task.get("tags", []) or [],
        "category": task.get("category", ""),
        "featured_image": task.get("featured_image", ""),
        "status": task.get("status", "draft"),
        "publish_date": task.get("publish_date", ""),
        "source": task.get("source", {}),
    }


# ---------------------------------------------------------------------------
# 对外接口
# ---------------------------------------------------------------------------

def publish(task: dict) -> dict:
    """发布单篇文章（POST /articles）。成功返回 {"ok": True, "post_id", "permalink"}。"""
    base, path, token, timeout, retries, backoff = _config()
    if not token:
        raise PublishError("WP api token not configured (set ABP_API_TOKEN env or secret file)",
                           retryable=False)
    task_id = str(task.get("task_id", ""))
    resp = _request_with_retry(
        "POST", _endpoint("/articles"), json=_task_to_payload(task),
        token=token, timeout=timeout, retries=retries, backoff=backoff, task_id=task_id,
    )
    try:
        data = resp.json()
    except ValueError:
        data = {}
    if not isinstance(data, dict) or not data.get("ok"):
        err = (data.get("error") if isinstance(data, dict) else "") or f"wp returned ok=false (status={resp.status_code})"
        raise PublishError(err, status_code=resp.status_code, response_body=str(data)[:300])
    try:
        post_id = int(data["post_id"])
    except (KeyError, TypeError, ValueError):
        raise PublishError(f"wp response missing post_id: {str(data)[:200]}",
                           status_code=resp.status_code, retryable=True)
    log.info(f"REST publish ok task_id={task_id} post_id={post_id}")
    return {"ok": True, "post_id": post_id, "permalink": data.get("permalink", "")}


def check_health() -> dict:
    """GET /health -> {"ok", "version", "models"}。"""
    base, path, token, timeout, retries, backoff = _config()
    resp = _request_with_retry("GET", _endpoint("/health"), token=token,
                               timeout=timeout, retries=retries, backoff=backoff)
    try:
        return resp.json()
    except ValueError:
        raise PublishError("health: non-json response", status_code=resp.status_code)


def fetch_categories() -> List[dict]:
    """GET /categories -> 站点分类列表（供 Python 匹配栏目）。"""
    base, path, token, timeout, retries, backoff = _config()
    resp = _request_with_retry("GET", _endpoint("/categories"), token=token,
                               timeout=timeout, retries=retries, backoff=backoff)
    try:
        data = resp.json()
    except ValueError:
        raise PublishError("categories: non-json response", status_code=resp.status_code)
    return data if isinstance(data, list) else data.get("categories", [])


def check_duplicate(fingerprint: str) -> dict:
    """POST /check -> {"fingerprint", "duplicate": bool}（站内查重，总纲 §5.4）。"""
    base, path, token, timeout, retries, backoff = _config()
    resp = _request_with_retry("POST", _endpoint("/check"), json={"fingerprint": fingerprint},
                               token=token, timeout=timeout, retries=retries, backoff=backoff)
    try:
        return resp.json()
    except ValueError:
        raise PublishError("check: non-json response", status_code=resp.status_code)


def fetch_written_books() -> List[str]:
    """GET /written-books -> 已写书目清单（读书栏目防重复）。"""
    base, path, token, timeout, retries, backoff = _config()
    resp = _request_with_retry("GET", _endpoint("/written-books"), token=token,
                               timeout=timeout, retries=retries, backoff=backoff)
    try:
        data = resp.json()
    except ValueError:
        raise PublishError("written-books: non-json response", status_code=resp.status_code)
    if isinstance(data, list):
        return data
    return data.get("books", [])
