# -*- coding: utf-8 -*-
"""
scheduler/wp_sync.py — 调度前同步 WP 插件设置（让后台复选框真正生效）

插件 GET /ai-auto-blog/v1/settings 返回开关与调度参数（非敏感）；
合并进内存 config（apply_wp_settings），覆盖 config.yaml 对应字段。
带 5 分钟缓存；失败静默降级（继续用 config.yaml），绝不中断调度。
"""

from __future__ import annotations

import threading
import time

import httpx

from config import apply_wp_model_config, apply_wp_settings, get_config
from core import logger

_sync_interval = 300.0   # 5 分钟
_last_sync = 0.0
_lock = threading.Lock()


def sync_from_wp(force: bool = False) -> bool:
    """拉取并合并 WP 设置（开关/配额 + 模型配置）。成功返回 True（含缓存命中）。

    1) GET /settings：开关与调度参数（apply_wp_settings）
    2) GET /health：模型探测结果（deepseek_api_key + models 映射，apply_wp_model_config）
    这样后台/主题里填的 DeepSeek Key 无需重复配置，Python 自动采用。
    """
    global _last_sync
    now = time.time()
    with _lock:
        if not force and (now - _last_sync) < _sync_interval:
            return True
        cfg = get_config()
        base = str(cfg.get("wordpress.base_url", "")).rstrip("/")
        path = str(cfg.get("wordpress.rest_path", "/wp-json/ai-auto-blog/v1"))
        token = str(cfg.get("wordpress.api_token", ""))
        if not base or not token:
            return False
        try:
            headers = {"Authorization": f"Bearer {token}"}
            ok = False
            # 1) 开关/调度参数
            resp = httpx.get(f"{base}{path}/settings", headers=headers, timeout=8)
            if resp.status_code == 200:
                data = resp.json()
                if isinstance(data, dict) and data.get("ok"):
                    apply_wp_settings(cfg, data)
                    ok = True
            # 2) 模型配置（主题/插件探测结果，含 Key）
            resp2 = httpx.get(f"{base}{path}/health", headers=headers, timeout=8)
            if resp2.status_code == 200:
                data2 = resp2.json()
                if isinstance(data2, dict) and data2.get("ok"):
                    apply_wp_model_config(cfg, data2)
                    ok = True
            if ok:
                _last_sync = now
                logger.info("wp settings synced (switches + models)")
                return True
            logger.warning(f"wp settings sync http={resp.status_code}/{resp2.status_code}")
        except Exception as e:
            logger.warning(f"wp settings sync failed: {e}")
    return False
