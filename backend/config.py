"""A-Blog 配置加载（总纲 §3.4 / §6 / 原则2：无硬编码密钥）。

优先级（低 -> 高）：
1. backend/config.yaml 默认配置（不含密钥）
2. 环境变量覆盖：``ABLOG__SECTION__KEY`` 形式（如 ABLOG__DAILY__ARTICLES_PER_DAY=5），
   值自动做 bool/int/float/str 转换
3. 密钥解析（不进 yaml、不入日志）：
   - 专用环境变量：wordpress.api_token_env / models.api_key_env /
     wordpress.xmlrpc_username_env / wordpress.xmlrpc_password_env
   - 专用 secret 文件：wordpress.api_token_file / models.api_key_file（内容即密钥，chmod 600）
   - 通用 secrets 文件：secrets.file（KEY=VALUE 每行，# 注释行忽略），
     键名 = 上述 *_env 指定的环境变量名

WP option 同步占位（总纲 §3.4）：apply_wp_model_config() 接收插件
class-abp-models.php 返回的模型配置 JSON，合并进内存配置（模型映射/密钥/来源）。
后续由 agents 层或调度层在启动/刷新时调用。
"""

from __future__ import annotations

import os
from pathlib import Path
from typing import Any, Dict, Optional

import yaml

PROJECT_ROOT = Path(__file__).resolve().parent.parent   # E:\my-project\A-Blog
BACKEND_DIR = Path(__file__).resolve().parent           # E:\my-project\A-Blog\backend
CONFIG_FILE = BACKEND_DIR / "config.yaml"

_ENV_PREFIX = "ABLOG__"
_BOOL_TRUE = {"1", "true", "yes", "on"}
_BOOL_FALSE = {"0", "false", "no", "off", ""}

# 日志输出时须脱敏的配置键（子串匹配）
_SECRET_KEY_HINTS = ("token", "api_key", "password", "secret", "username")


def _coerce(value: str) -> Any:
    v = value.strip()
    low = v.lower()
    if low in _BOOL_TRUE:
        return True
    if low in _BOOL_FALSE:
        return False
    try:
        return int(v)
    except ValueError:
        pass
    try:
        return float(v)
    except ValueError:
        pass
    # JSON 数组/对象（如 scheduler.run_times=["20:30","21:00"]）
    if v[:1] in ("[", "{"):
        try:
            import json

            return json.loads(v)
        except ValueError:
            pass
    return v


def _set_path(node: dict, parts, value: Any) -> None:
    for part in parts[:-1]:
        node = node.setdefault(part, {})
    node[parts[-1]] = value


class Config:
    """配置访问对象：支持 ``cfg.get("a.b.c", default)`` 点路径访问。"""

    def __init__(self, data: Dict[str, Any]):
        self._data = data

    # -- 访问 ------------------------------------------------------------
    def get(self, dotted: str, default: Any = None) -> Any:
        node: Any = self._data
        for part in dotted.split("."):
            if isinstance(node, dict) and part in node:
                node = node[part]
            else:
                return default
        return node

    def set(self, dotted: str, value: Any) -> None:
        parts = dotted.split(".")
        node = self._data
        for part in parts[:-1]:
            node = node.setdefault(part, {})
        node[parts[-1]] = value

    @property
    def raw(self) -> Dict[str, Any]:
        return self._data

    def to_public_dict(self) -> Dict[str, Any]:
        """对外（healthz 等）展示用：密钥字段全部打码。"""
        return _mask_secrets(self._data)

    def __repr__(self) -> str:
        return f"<Config {self.to_public_dict()!r}>"


def _mask_secrets(node):
    if isinstance(node, dict):
        return {k: _mask_secrets(v) for k, v in node.items()}
    return node


def _read_secret_file(path: str) -> str:
    if not path:
        return ""
    p = Path(path)
    if not p.exists():
        return ""
    return p.read_text(encoding="utf-8").strip()


def _load_secrets_file(path: str) -> Dict[str, str]:
    secrets: Dict[str, str] = {}
    if not path:
        return secrets
    p = Path(path)
    if not p.exists():
        return secrets
    for line in p.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        secrets[k.strip().upper()] = v.strip()
    return secrets


def _apply_env_overrides(data: dict) -> None:
    for key, value in os.environ.items():
        if not key.startswith(_ENV_PREFIX):
            continue
        dotted = key[len(_ENV_PREFIX):].lower().replace("__", ".")
        _set_path(data, dotted.split("."), _coerce(value))


def _resolve_secret(cfg: Config, env_key: str, file_key: str, secrets: Dict[str, str]) -> str:
    """解析单个密钥：专用环境变量 > 专用 secret 文件 > 通用 secrets 文件。"""
    env_name = cfg.get(env_key) or ""
    if env_name:
        val = os.environ.get(env_name, "").strip()
        if val:
            return val
        if env_name in secrets:                      # 通用 secrets 文件按 env 键名查找
            return secrets[env_name]
    file_ref = cfg.get(file_key) or ""
    if file_ref:
        p = Path(file_ref)
        if not p.is_absolute():
            p = PROJECT_ROOT / p   # 相对项目根解析（与 data.db_path 等一致）
        val = _read_secret_file(str(p))
        if val:
            return val
    return ""


def load() -> Config:
    if not CONFIG_FILE.exists():
        raise FileNotFoundError(f"config.yaml not found: {CONFIG_FILE}")
    with open(CONFIG_FILE, "r", encoding="utf-8") as f:
        data = yaml.safe_load(f) or {}

    _apply_env_overrides(data)
    cfg = Config(data)

    # ---- 密钥解析（结果存入内存 cfg，不回写 yaml）----
    secrets = _load_secrets_file(str(data.get("secrets", {}).get("file", "") or ""))

    wp = data.setdefault("wordpress", {})
    wp["api_token"] = _resolve_secret(cfg, "wordpress.api_token_env", "wordpress.api_token_file", secrets)
    wp["xmlrpc_username"] = _resolve_secret(
        cfg, "wordpress.xmlrpc_username_env", "wordpress.xmlrpc_username_file", secrets
    )
    wp["xmlrpc_password"] = _resolve_secret(
        cfg, "wordpress.xmlrpc_password_env", "wordpress.xmlrpc_password_file", secrets
    )

    models = data.setdefault("models", {})
    models["api_key"] = _resolve_secret(cfg, "models.api_key_env", "models.api_key_file", secrets)

    search_cfg = data.setdefault("search", {})
    search_cfg["api_key"] = _resolve_secret(cfg, "search.api_key_env", "search.api_key_file", secrets)

    # 解析后的绝对路径（相对项目根）
    for rel_key in ("data.db_path", "data.sensitive_words_file", "data.image_cache_dir",
                    "logging.dir"):
        raw = cfg.get(rel_key)
        if raw:
            p = Path(raw)
            cfg.set(rel_key, str(p if p.is_absolute() else PROJECT_ROOT / p))

    return cfg


def apply_wp_model_config(cfg: Config, payload: Dict[str, Any]) -> Config:
    """WP option 同步占位（总纲 §3.4）。

    接收插件 class-abp-models.php 返回的模型配置：
    {"provider": "theme|plugin|self", "source": ..., "deepseek_api_key": "sk-...",
     "models": {"stock": "deepseek-chat", ...}, "image_api": {...}}

    合并规则：
    - models.mapping.<column> 非空则覆盖
    - deepseek_api_key / api_key 非空则覆盖 models.api_key
    - provider/source 记录来源标识
    - image_api 原样存入 models.image_api
    """
    if not isinstance(payload, dict) or payload.get("ok") is False:
        return cfg

    models = payload.get("models")
    if isinstance(models, dict):
        for col, model in models.items():
            if not (isinstance(model, str) and model.strip()):
                continue
            # config.yaml 显式配置优先；WP 探测的旧别名（deepseek-chat/reasoner）不覆盖新模型名
            cur = cfg.get(f"models.mapping.{col}", "")
            if cur and cur not in ("deepseek-chat", "deepseek-reasoner"):
                continue
            cfg.set(f"models.mapping.{col}", model.strip())

    key = payload.get("deepseek_api_key") or payload.get("api_key")
    if isinstance(key, str) and key.strip():
        cfg.set("models.api_key", key.strip())

    for field in ("provider", "source"):
        if payload.get(field):
            cfg.set(f"models.{field}", payload[field])
    if isinstance(payload.get("image_api"), dict):
        cfg.set("models.image_api", payload["image_api"])
    return cfg


def apply_wp_settings(cfg: Config, payload: Dict[str, Any]) -> Config:
    """合并 WP 插件 /settings 开关与调度参数（让后台复选框真正生效）。

    payload 字段：ai_enabled / column_*_enabled / image_enabled / publish_enabled /
    daily_limit / daily_token_limit / publish_window / column_ratio（均为 bool/int/str）。
    """
    if not isinstance(payload, dict):
        return cfg

    if isinstance(payload.get("ai_enabled"), bool):
        cfg.set("ai.enabled", payload["ai_enabled"])
    col_map = {
        "column_stock_enabled": "stock",
        "column_tech_enabled": "tech",
        "column_reading_enabled": "reading",
        "column_book_enabled": "book",
    }
    for key, col in col_map.items():
        if isinstance(payload.get(key), bool):
            cfg.set(f"columns.{col}.enabled", payload[key])
    if isinstance(payload.get("image_enabled"), bool):
        cfg.set("image.enabled", payload["image_enabled"])
    if isinstance(payload.get("publish_enabled"), bool):
        cfg.set("publish.enabled", payload["publish_enabled"])
    if payload.get("daily_limit"):
        cfg.set("daily.articles_per_day", max(1, min(10, int(payload["daily_limit"]))))
    if payload.get("daily_token_limit"):
        cfg.set("daily.token_quota_per_day", max(0, int(payload["daily_token_limit"])))
    if payload.get("publish_window"):
        w = str(payload["publish_window"]).split("-")
        if len(w) == 2:
            cfg.set("publish.window_start", w[0].strip())
            cfg.set("publish.window_end", w[1].strip())
    return cfg


_cfg: Optional[Config] = None


def get_config() -> Config:
    """进程级单例。"""
    global _cfg
    if _cfg is None:
        _cfg = load()
    return _cfg


def reload_config() -> Config:
    """重新加载（配置热更新用）。"""
    global _cfg
    _cfg = load()
    return _cfg
