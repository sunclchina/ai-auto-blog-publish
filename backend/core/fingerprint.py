"""SimHash 文章指纹（总纲 §5 防重复体系 / docs/05-plugin.md §6.1 唯一权威规范）。

与 PHP 侧 class-abp-fingerprint.php 逐字节一致（交叉验证见 tests/）。

S1 归一化
  a. 英文字母转小写（对应 PHP mb_strtolower）
  b. 删除全部 Unicode 标点/符号/空白：等价 PHP 正则 [\\p{P}\\p{S}\\p{Z}]+（覆盖全半角、中文标点）
  c. 删除停用词（固定表，两侧完全一致，共 25 词）：
     「的 了 是 在 和 与 及 就 都 而 或 我 你 他 她 它 们 有 也 着 一个 之 以 为 等」
     实现：对停用词逐词替换一次（停用词互相不得包含，结果确定）
S2 特征提取（2-gram，字符级）：L==0→空集；L==1→[文本]；否则 [substr(i,2) for i in 0..L-2]
S3 特征哈希（64bit）：h64(f) = (crc32(utf8(f) . "\x01") << 32) | crc32(utf8(f) . "\x02")
     Python zlib.crc32 与 PHP crc32() 均为标准 CRC-32/IEEE（0xEDB88320）
S4 加权累加：v[0..63] 初始 0；每特征权重 = 在归一化文本中的出现频次；
     v[b] += ((h64 >> b) & 1) ? +w : -w
S5 收敛：hash 第 b 位 = (v[b] > 0) ? 1 : 0（v[b]==0 记 0）
S6 输出 16 位小写 hex（64bit → %016x）
S7 判重：汉明距离 popcount(a xor b) < 4 → 重复
"""

from __future__ import annotations

import re
import zlib
import re
import zlib
import unicodedata
from typing import Union

_HASH_BITS = 64
_DUPLICATE_THRESHOLD = 4          # 汉明距离 < 4 判重复（S7）

# S1.c 停用词表（与 PHP ABP_STOPWORDS 完全一致，互不包含）
STOPWORDS = (
    "的", "了", "是", "在", "和", "与", "及", "就", "都", "而", "或",
    "我", "你", "他", "她", "它", "们", "有", "也", "着", "一个", "之", "以", "为", "等",
)

Fingerprint = Union[int, str]     # 64bit int 或 16 位 hex 字符串


def _is_punct_or_space(ch: str) -> bool:
    """S1.b 判断 Unicode 标点/符号/空白（对应 PHP [\\p{P}\\p{S}\\p{Z}]）。"""
    cat = unicodedata.category(ch)
    return cat[0] in ("P", "S", "Z")


def normalize_text(text: str) -> str:
    """S1 归一化：小写 → 去标点/符号/空白（unicodedata 等价 [\\p{P}\\p{S}\\p{Z}]）→ 删停用词。"""
    if not text:
        return ""
    s = str(text).lower()
    s = "".join(ch for ch in s if not _is_punct_or_space(ch))
    for w in STOPWORDS:
        s = s.replace(w, "")
    return s


def _features(norm_text: str):
    """S2 特征提取：字符级 2-gram（重叠滑窗）；长度 < 2 退化为单字符。"""
    n = len(norm_text)
    if n == 0:
        return []
    if n == 1:
        return [norm_text]
    return [norm_text[i:i + 2] for i in range(n - 1)]


def _feature_hash64(feature: str) -> int:
    """S3 特征哈希（64bit）：(crc32(f+\x01) << 32) | crc32(f+\x02)。"""
    h1 = zlib.crc32(feature.encode("utf-8") + b"\x01") & 0xFFFFFFFF
    h2 = zlib.crc32(feature.encode("utf-8") + b"\x02") & 0xFFFFFFFF
    return (h1 << 32) | h2


def simhash(text: str) -> int:
    """计算 64 位 SimHash 指纹，返回无符号整数（S1-S5）。"""
    norm = normalize_text(text)
    v = [0] * _HASH_BITS
    from collections import Counter
    for feature, w in Counter(_features(norm)).items():
        h = _feature_hash64(feature)
        for b in range(_HASH_BITS):
            if (h >> b) & 1:
                v[b] += w
            else:
                v[b] -= w
    fingerprint = 0
    for b in range(_HASH_BITS):
        if v[b] > 0:
            fingerprint |= 1 << b
    return fingerprint


def fingerprint_hex(text: str) -> str:
    """S6：64 位指纹的 16 位小写 hex 表示（入库 fingerprints.fhash）。"""
    return format(simhash(text), "016x")


def _to_int(value: Fingerprint) -> int:
    if isinstance(value, int):
        return value
    if isinstance(value, str):
        return int(value, 16) if value else 0
    raise TypeError(f"fingerprint must be int or hex str, got {type(value)}")


def hamming_distance(a: Fingerprint, b: Fingerprint) -> int:
    """两个 64bit 指纹的汉明距离（异或后 popcount）。"""
    return bin(_to_int(a) ^ _to_int(b)).count("1")


def is_duplicate(a: Fingerprint, b: Fingerprint, threshold: int = _DUPLICATE_THRESHOLD) -> bool:
    """汉明距离 < threshold 判重复（默认 4）。"""
    return hamming_distance(a, b) < threshold


def check(text: str, threshold: int = _DUPLICATE_THRESHOLD) -> bool:
    """与指纹库（fingerprints 表）比对：任一历史指纹汉明距离 < 阈值即判重复。

    智能体/调度层前置查重用（总纲 §5.1）；CoreAdapter 探测 "check" 优先。
    """
    from core import db  # 延迟导入避免循环依赖（backend 目录为运行根）
    fh = simhash(text)
    rows = db.query("SELECT fhash FROM fingerprints")
    for row in rows:
        try:
            if hamming_distance(fh, row["fhash"]) < threshold:
                return True
        except (TypeError, ValueError):
            continue
    return False


def add(text: str, task_id: str = "", title: str = "", column_name: str = "") -> None:
    """指纹入库（总纲 §3.3 fingerprints 表）。"""
    from core import db
    db.execute(
        "INSERT OR IGNORE INTO fingerprints (fhash, task_id, title, column_name, created_at) "
        "VALUES (?, ?, ?, ?, ?)",
        (fingerprint_hex(text), task_id or "", title or "", column_name or "",
         db.now_iso()),
    )
