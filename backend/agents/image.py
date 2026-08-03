# -*- coding: utf-8 -*-
"""
agents/image.py — Step7 配图智能体

- ImageProvider 抽象接口：generate(prompt, size=(1280,720)) → 图片 bytes 或 None
- DalleProvider：OpenAI 兼容 images/generations（可配 key/endpoint/model）
- 无配置 → NoopProvider 返回 None（纯文字发布）
- 生成后调 publishers/image.py 转 WebP 并返回本地路径（延迟导入，避免循环依赖）
dry_run：不调用任何生图接口，直接返回 None（配图跳过）。
"""

import os
import sys
import base64
import logging
import datetime as dt
from abc import ABC, abstractmethod

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

try:
    import httpx
except ImportError:  # pragma: no cover
    httpx = None

from agents.base import BaseAgent  # noqa: E402

logger = logging.getLogger("ablog.agents.image")

DEFAULT_IMAGE_ENDPOINT = "https://api.openai.com/v1"
DEFAULT_IMAGE_MODEL = "dall-e-3"
DEFAULT_SIZE = (1280, 720)


class ImageProvider(ABC):
    """生图提供方抽象接口。"""

    @abstractmethod
    def generate(self, prompt, size=DEFAULT_SIZE):
        """返回图片 bytes；失败或不可用时返回 None。"""
        raise NotImplementedError


class NoopProvider(ImageProvider):
    """未配置生图服务：纯文字发布。"""

    name = "noop"

    def generate(self, prompt, size=DEFAULT_SIZE):
        return None


class DalleProvider(ImageProvider):
    """OpenAI 兼容 images/generations 提供方（DALL·E / 豆包即梦 / 通义万相等兼容端点）。"""

    name = "dalle"

    def __init__(self, api_key, endpoint=DEFAULT_IMAGE_ENDPOINT, model=DEFAULT_IMAGE_MODEL,
                 size=(1280, 720), timeout=120):
        if not api_key:
            raise ValueError("DalleProvider 缺少 api_key")
        self.api_key = api_key
        self.endpoint = endpoint.rstrip("/")
        self.model = model
        self.size = size
        self.timeout = timeout

    def generate(self, prompt, size=DEFAULT_SIZE):
        if httpx is None:
            logger.warning("缺少 httpx，生图不可用")
            return None
        url = f"{self.endpoint}/images/generations"
        payload = {
            "model": self.model,
            "prompt": prompt,
            "n": 1,
            "size": f"{size[0]}x{size[1]}",
            "response_format": "b64_json",
        }
        try:
            resp = httpx.post(
                url,
                headers={"Authorization": f"Bearer {self.api_key}", "Content-Type": "application/json"},
                json=payload,
                timeout=self.timeout,
            )
            resp.raise_for_status()
            data = resp.json()
            b64 = data["data"][0].get("b64_json")
            if not b64:
                url_ = data["data"][0].get("url")
                if url_:
                    img = httpx.get(url_, timeout=self.timeout)
                    img.raise_for_status()
                    return img.content
                logger.warning("生图响应无 b64_json 与 url")
                return None
            return base64.b64decode(b64)
        except Exception as e:
            logger.warning("生图失败: %s", e)
            return None  # Step7 失败 → pipeline 降级纯文字


def build_image_provider(config):
    """按配置构建 ImageProvider；未配置返回 NoopProvider（纯文字发布）。"""
    cfg = (config or {}).get("image", {}) or {}
    provider = (cfg.get("provider") or "").lower()
    if provider in ("dalle", "openai", "dall-e", "dall-e-3") and cfg.get("api_key"):
        try:
            return DalleProvider(
                api_key=cfg["api_key"],
                endpoint=cfg.get("endpoint") or DEFAULT_IMAGE_ENDPOINT,
                model=cfg.get("model") or DEFAULT_IMAGE_MODEL,
                size=tuple(cfg.get("size") or DEFAULT_SIZE),
            )
        except Exception as e:
            logger.warning("DalleProvider 构建失败，降级 Noop: %s", e)
            return NoopProvider()
    if cfg.get("api_key") and not provider:
        # 配置了 key 但未声明 provider → 视为 OpenAI 兼容（总纲 3.4 image_api）
        try:
            return DalleProvider(
                api_key=cfg["api_key"],
                endpoint=cfg.get("endpoint") or DEFAULT_IMAGE_ENDPOINT,
                model=cfg.get("model") or DEFAULT_IMAGE_MODEL,
            )
        except Exception as e:
            logger.warning("ImageProvider 构建失败，降级 Noop: %s", e)
            return NoopProvider()
    return NoopProvider()


class ImageAgent(BaseAgent):
    step = 7
    step_name = "image"

    def __init__(self, config=None, core=None, dry_run=False):
        super().__init__(config, core, dry_run)
        self.provider = NoopProvider() if dry_run else build_image_provider(config)

    def provider_name(self):
        return self.provider.name

    def build_prompt(self, column, topic, final_title=None):
        """按栏目生成封面提示词（1280×720，青简主题 banner 尺寸）。"""
        base = {
            "stock": "现代简约财经风插画，A股大盘走势图与红绿K线元素，沉稳蓝色调，宽幅封面 1280x720",
            "tech": "现代科技风扁平插画，服务器与代码元素，蓝紫渐变色调，宽幅封面 1280x720",
            "reading": "中国水墨风插画，山水与书卷元素，淡雅留白，宽幅封面 1280x720",
            "book": "复古书籍与书桌插画，暖色调，静谧阅读氛围，宽幅封面 1280x720",
        }.get(column, "现代插画风格，宽幅封面 1280x720")
        return f"{topic}。{base}。无水印，无文字干扰。"

    def generate_image(self, column, topic, task_id=None, final_title=None):
        """生成配图 → 返回本地路径（WebP，经 publishers/image.py）；不可用时返回 None。

        返回: {"local_path": str|None, "provider": str, "note": str}
        """
        if self.dry_run:
            return {"local_path": None, "provider": "noop",
                    "note": "MOCK：dry_run 不调用生图接口，配图跳过（纯文字发布）"}
        if not bool(self.config.get("image", {}).get("enabled", True)):
            return {"local_path": None, "provider": "noop",
                    "note": "配图开关关闭 → 纯文字发布"}
        prompt = self.build_prompt(column, topic, final_title)
        img_bytes = self.provider.generate(prompt, size=DEFAULT_SIZE)
        if not img_bytes:
            return {"local_path": None, "provider": self.provider.name,
                    "note": "生图失败或未配置 → 降级纯文字发布"}
        path = self._to_local_image(img_bytes, column, task_id)
        return {"local_path": path, "provider": self.provider.name, "note": "已生成封面"}

    def _to_local_image(self, img_bytes, column, task_id=None):
        """写本地图片：优先走 publishers/image.py 转 WebP（延迟导入避免循环依赖）。"""
        data_dir = self.config.get("data_dir") or os.path.join(_backend_root(), "data", "images")
        os.makedirs(data_dir, exist_ok=True)
        stamp = dt.datetime.now().strftime("%Y%m%d-%H%M%S")
        stem = f"{task_id or stamp}-{column}-{stamp}"
        fname = f"{stem}.webp"

        try:
            from publishers import image as pub_image  # 延迟导入：publishers 由发布层小组提供
            path = pub_image.process_image_bytes(
                img_bytes, name=stem, dest_dir=data_dir,
                width=DEFAULT_SIZE[0], height=DEFAULT_SIZE[1],
            )  # 1280×720 居中裁剪 WebP
            logger.info("封面已转 WebP: %s", path)
            return str(path)
        except Exception as e:
            logger.warning("publishers.image 不可用(%s)，降级存原始 PNG", e)
            path = os.path.join(data_dir, fname.replace(".webp", ".png"))
            with open(path, "wb") as f:
                f.write(img_bytes)
            return path


def _backend_root():
    return os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
