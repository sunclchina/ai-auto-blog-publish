"""XML-RPC 兜底发布（总纲 §3.2 之外，接口与 wp_rest.publish 保持同签名）。

启用条件：仅当 REST 主通道连续失败（retryable=True 的网络/5xx 错误）时，
由调度层（daily_queue.publish_due_tasks）自动切换调用；config 中
wordpress.xmlrpc_enabled=false 可彻底关闭。

实现：标准 XML-RPC 协议 wp.newPost（wordpress 原生支持，无需插件）：
  server.wp.newPost(blog_id=0, username, password, struct)
struct 字段：post_title / post_content / post_excerpt / post_status
           / post_date（仅 future 定时发布时携带，格式 YYYYMMDDTHH:MM:SS）
成功返回新文章 ID（int）。
"""

from __future__ import annotations

import datetime
import xmlrpc.client
from typing import Dict, Optional

from config import get_config  # backend 目录为运行根（统一绝对导入）
from core.logger import get_logger

log = get_logger("wp_xmlrpc")


class PublishError(Exception):
    """XML-RPC 发布失败。retryable=True 表示可重试。"""

    def __init__(self, message: str, retryable: bool = False):
        super().__init__(message)
        self.message = message
        self.retryable = retryable

    def __str__(self) -> str:
        return f"{self.message} (retryable={self.retryable})"


def is_configured() -> bool:
    cfg = get_config()
    return bool(cfg.get("wordpress.xmlrpc_username")) and bool(cfg.get("wordpress.xmlrpc_password"))


def _server():
    cfg = get_config()
    base = str(cfg.get("wordpress.base_url", "")).rstrip("/")
    return xmlrpc.client.ServerProxy(f"{base}/xmlrpc.php", allow_none=True, use_builtin_types=True)


def _wp_status(task: dict) -> str:
    """WP post_status：draft / publish / future（定时发布）。"""
    mode = task.get("publish_mode", "publish")
    if mode == "draft":
        return "draft"
    pd = task.get("publish_date")
    if pd:
        try:
            dt = datetime.datetime.fromisoformat(pd)
            if dt > datetime.datetime.now():
                return "future"
        except ValueError:
            pass
    return "publish"


def _post_date(task: dict) -> Optional[xmlrpc.client.DateTime]:
    """定时发布时携带 post_date（仅 future）。"""
    if _wp_status(task) != "future":
        return None
    pd = task.get("publish_date")
    if not pd:
        return None
    try:
        dt = datetime.datetime.fromisoformat(pd)
    except ValueError:
        return None
    return xmlrpc.client.DateTime(dt.strftime("%Y%m%dT%H:%M:%S"))


def publish(task: dict) -> dict:
    """XML-RPC 兜底发布。成功返回 {"ok": True, "post_id": int, "permalink": ""}。"""
    cfg = get_config()
    if not bool(cfg.get("wordpress.xmlrpc_enabled", True)):
        raise PublishError("xmlrpc disabled by config (wordpress.xmlrpc_enabled=false)")
    username = str(cfg.get("wordpress.xmlrpc_username", ""))
    password = str(cfg.get("wordpress.xmlrpc_password", ""))
    if not username or not password:
        raise PublishError("xmlrpc credentials not configured (ABP_XMLRPC_USER / ABP_XMLRPC_PASS)",
                           retryable=False)

    task_id = str(task.get("task_id", ""))
    struct: Dict[str, object] = {
        "post_title": task.get("final_title") or task.get("title") or task.get("topic") or "untitled",
        "post_content": task.get("content_html") or task.get("content") or "",
        "post_excerpt": task.get("excerpt") or "",
        "post_status": _wp_status(task),
    }
    pdate = _post_date(task)
    if pdate is not None:
        struct["post_date"] = pdate

    try:
        server = _server()
        post_id = server.wp.newPost(0, username, password, struct)
    except Exception as e:
        raise PublishError(f"xmlrpc wp.newPost failed: {e}", retryable=True)

    if not post_id:
        raise PublishError("xmlrpc wp.newPost returned empty post_id", retryable=True)
    log.info(f"XML-RPC publish ok task_id={task_id} post_id={post_id}")
    return {"ok": True, "post_id": int(post_id), "permalink": ""}
