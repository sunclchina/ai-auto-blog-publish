"""A股交易日历（总纲 §4 调度规则）。

规则：
- 周末（周六/周日）跳过
- 法定节假日跳过
- 节假日前后调休补班日（周末上班日）**算交易日**
- 其余工作日为交易日

数据来源：
- 2026 年：国务院办公厅《关于2026年部分节假日安排的通知》
  （国办发明电〔2025〕7号，2025-11-04 发布）—— 权威数据。
  元旦 1/1-1/3（1/4 补班）；春节 2/15-2/23（2/14、2/28 补班）；
  清明 4/4-4/6；劳动 5/1-5/5（5/9 补班）；端午 6/19-6/21；
  中秋 9/25-9/27；国庆 10/1-10/7（9/20、10/10 补班）。
- 2027 年：国务院办公厅通知尚未发布（预计 2026 年 11 月发布）。
  下表为按《全国年节及纪念日放假办法》（2024-11 修订，自 2025 起春节4天/劳动2天，
  共13天法定假日；春节自除夕调休8天、除夕逢周五可顺连9天，如2027年）推算的
  **暂定安排**（标注 _PROVISIONAL_2027），正式通知发布后必须立即更新。
  推算：除夕 2027-02-05（周五）→ 春节 2/5-2/13 共 9 天；补班暂定 1/30（六）、2/14（日）。
  元旦 1/1（五）-1/3；清明 4/3（六）-4/5（一）；劳动 5/1（六）-5/5（三），补班 5/8（六）；
  端午 6/9（三，逢周三只放当天）；中秋 9/15（三，逢周三只放当天）；
  国庆 10/1（五）-10/7（四），补班暂定 9/26（日）、10/9（六）。
"""

from __future__ import annotations

import datetime
from typing import Iterable, List, Optional, Union

DateLike = Union[datetime.date, datetime.datetime, str]


def _daterange(start: str, end: str) -> Iterable[str]:
    d = datetime.date.fromisoformat(start)
    e = datetime.date.fromisoformat(end)
    while d <= e:
        yield d.isoformat()
        d += datetime.timedelta(days=1)


# ---------------- 2026 法定节假日（权威：国办发明电〔2025〕7号） ----------------
_HOLIDAYS_2026 = set()
_HOLIDAYS_2026.update(_daterange("2026-01-01", "2026-01-03"))   # 元旦
_HOLIDAYS_2026.update(_daterange("2026-02-15", "2026-02-23"))   # 春节
_HOLIDAYS_2026.update(_daterange("2026-04-04", "2026-04-06"))   # 清明
_HOLIDAYS_2026.update(_daterange("2026-05-01", "2026-05-05"))   # 劳动
_HOLIDAYS_2026.update(_daterange("2026-06-19", "2026-06-21"))   # 端午
_HOLIDAYS_2026.update(_daterange("2026-09-25", "2026-09-27"))   # 中秋
_HOLIDAYS_2026.update(_daterange("2026-10-01", "2026-10-07"))   # 国庆

_MAKEUP_2026 = {"2026-01-04", "2026-02-14", "2026-02-28", "2026-05-09", "2026-09-20", "2026-10-10"}

# ---------------- 2027 法定节假日（暂定，待官方通知更新） ----------------
_PROVISIONAL_2027 = True   # 官方通知发布后置 False 并核对下表
_HOLIDAYS_2027 = set()
_HOLIDAYS_2027.update(_daterange("2027-01-01", "2027-01-03"))   # 元旦（五-日，自然连休）
_HOLIDAYS_2027.update(_daterange("2027-02-05", "2027-02-13"))   # 春节（除夕逢周五，9 天）
_HOLIDAYS_2027.update(_daterange("2027-04-03", "2027-04-05"))   # 清明
_HOLIDAYS_2027.update(_daterange("2027-05-01", "2027-05-05"))   # 劳动
_HOLIDAYS_2027.add("2027-06-09")                                # 端午（周三，只放当天）
_HOLIDAYS_2027.add("2027-09-15")                                # 中秋（周三，只放当天）
_HOLIDAYS_2027.update(_daterange("2027-10-01", "2027-10-07"))   # 国庆

_MAKEUP_2027 = {"2027-01-30", "2027-02-14", "2027-05-08", "2027-09-26", "2027-10-09"}

# ---------------- 合并表 ----------------
HOLIDAYS = frozenset(_HOLIDAYS_2026 | _HOLIDAYS_2027)
MAKEUP_WORKDAYS = frozenset(_MAKEUP_2026 | _MAKEUP_2027)


def _as_date(value: DateLike) -> datetime.date:
    if isinstance(value, datetime.datetime):
        return value.date()
    if isinstance(value, datetime.date):
        return value
    if isinstance(value, str):
        return datetime.date.fromisoformat(value[:10])
    raise TypeError(f"unsupported date type: {type(value)}")


def is_trading_day(date: DateLike) -> bool:
    """A股交易日判断：补班日算交易日；周末/法定节假日非交易日。"""
    d = _as_date(date)
    iso = d.isoformat()
    if iso in MAKEUP_WORKDAYS:
        return True
    if d.weekday() >= 5:      # 周六=5 周日=6
        return False
    if iso in HOLIDAYS:
        return False
    return True


def next_trading_day(date: DateLike, n: int = 1) -> datetime.date:
    """返回 date 之后第 n 个交易日（含 date 本身向后数）。"""
    d = _as_date(date)
    step = 1 if n >= 0 else -1
    count = abs(n)
    while count > 0:
        d += datetime.timedelta(days=step)
        if is_trading_day(d):
            count -= 1
    return d


def previous_trading_day(date: DateLike, n: int = 1) -> datetime.date:
    return next_trading_day(date, -n)


def trading_days_between(start: DateLike, end: DateLike, include_end: bool = True) -> List[datetime.date]:
    """闭区间 [start, end]（默认含 end）内的交易日列表。"""
    s = _as_date(start)
    e = _as_date(end)
    if s > e:
        return []
    days = []
    d = s
    while d < e or (include_end and d == e):
        if is_trading_day(d):
            days.append(d)
        d += datetime.timedelta(days=1)
        if d > e:
            break
    return days


def holiday_table() -> List[dict]:
    """导出节假日表（healthz/调试用），标注年份与是否暂定。"""
    rows = []
    for iso in sorted(HOLIDAYS):
        year = int(iso[:4])
        rows.append({
            "date": iso,
            "year": year,
            "provisional": _PROVISIONAL_2027 and year == 2027,
        })
    return rows
