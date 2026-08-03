"""图片处理（总纲 §7 青简主题适配：封面 1280×720 WebP）。

只做处理，不做生成（配图生成归 agents/image.py，本模块接收其输出或任意
本地路径 / base64 / 字节流，统一处理为 1280×720 居中裁剪的 WebP）。

- center_crop：等比覆盖缩放（LANCZOS）后居中裁剪到目标尺寸，杜绝变形
- to_webp：Pillow 输出 WebP（默认 quality=82）
- EXIF 方向自动修正（exif_transpose）
- 输出路径管理：dest_dir/YYYYMMDD/<stem>.webp（默认 data/images/，相对项目根）
"""

from __future__ import annotations

import base64
import datetime
from pathlib import Path
from typing import Optional, Union

from PIL import Image, ImageOps

from config import PROJECT_ROOT, get_config  # backend 目录为运行根（统一绝对导入）
from core.logger import get_logger

log = get_logger("image")

COVER_WIDTH = 1280
COVER_HEIGHT = 720
WEBP_QUALITY = 82


def _image_dir(cfg=None) -> Path:
    cfg = cfg or get_config()
    raw = cfg.get("data.image_cache_dir", "data/images")
    p = Path(raw)
    if not p.is_absolute():
        p = PROJECT_ROOT / p
    return p


def center_crop(img: Image.Image, width: int, height: int) -> Image.Image:
    """等比缩放至覆盖目标尺寸后居中裁剪。"""
    ratio = max(width / img.width, height / img.height)
    new_size = (round(img.width * ratio), round(img.height * ratio))
    img = img.resize(new_size, Image.LANCZOS)
    left = (new_size[0] - width) // 2
    top = (new_size[1] - height) // 2
    return img.crop((left, top, left + width, top + height))


def _as_rgb(img: Image.Image) -> Image.Image:
    """RGBA 贴白底转 RGB，其他模式转 RGB。"""
    if img.mode == "RGBA":
        bg = Image.new("RGB", img.size, (255, 255, 255))
        bg.paste(img, mask=img.split()[3])
        return bg
    if img.mode != "RGB":
        return img.convert("RGB")
    return img


def to_webp(img: Image.Image, dest: Union[str, Path], quality: int = WEBP_QUALITY) -> Path:
    dest = Path(dest)
    dest.parent.mkdir(parents=True, exist_ok=True)
    img = _as_rgb(img)
    img.save(dest, "WEBP", quality=quality, method=6)
    return dest


def process_image(src: Union[str, Path],
                  dest_dir: Optional[Union[str, Path]] = None,
                  width: int = COVER_WIDTH,
                  height: int = COVER_HEIGHT,
                  quality: int = WEBP_QUALITY) -> Path:
    """处理本地图片 -> 1280×720 封面 WebP。

    输出：dest_dir（默认 data/images）/YYYYMMDD/<src.stem>.webp
    """
    src = Path(src)
    if not src.exists():
        raise FileNotFoundError(f"image not found: {src}")
    with Image.open(src) as img:
        img = ImageOps.exif_transpose(img)
        img = _as_rgb(img)
        img = center_crop(img, width, height)
        dest = _dest_path(src.stem, dest_dir)
        return to_webp(img, dest, quality)


def process_image_bytes(data: bytes,
                        name: str = "cover",
                        dest_dir: Optional[Union[str, Path]] = None,
                        width: int = COVER_WIDTH,
                        height: int = COVER_HEIGHT,
                        quality: int = WEBP_QUALITY) -> Path:
    """处理字节流（如远程下载）-> WebP。"""
    import io
    with Image.open(io.BytesIO(data)) as img:
        img = ImageOps.exif_transpose(img)
        img = _as_rgb(img)
        img = center_crop(img, width, height)
        dest = _dest_path(name, dest_dir)
        return to_webp(img, dest, quality)


def process_base64(b64: str,
                   name: str = "cover",
                   dest_dir: Optional[Union[str, Path]] = None,
                   width: int = COVER_WIDTH,
                   height: int = COVER_HEIGHT,
                   quality: int = WEBP_QUALITY) -> Path:
    """处理 base64 / data URI 图片（任务对象 featured_image 支持 base64）。"""
    if b64.startswith("data:"):
        b64 = b64.split(",", 1)[1]
    raw = base64.b64decode(b64)
    return process_image_bytes(raw, name, dest_dir, width, height, quality)


def _dest_path(name: str, dest_dir: Optional[Union[str, Path]]) -> Path:
    base = Path(dest_dir) if dest_dir else _image_dir()
    day = datetime.date.today().strftime("%Y%m%d")
    safe = "".join(c for c in name if c.isalnum() or c in "._-") or "cover"
    return base / day / f"{safe}.webp"
