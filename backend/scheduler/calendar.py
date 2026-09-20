"""A股交易日历（总纲 §4 调度规则）。

规则：
- 周末（周六/周日）跳过
- 法定节假日跳过（休市安排以沪深北交易所公告为准）
- 调休补班上班日**不算交易日**：2026 年国务院安排的全部补班日
  （1/4、2/14、2/28、5/9、9/20、10/10），沪深北交易所均明确为周末休市
- 其余工作日为交易日

数据来源：
- 2026 年：上交所《关于上海证券交易所2026年部分节假日休市安排的通知》
  （上证公告〔2025〕45号，2025-12-22 发布；沪深北交易所一致）—— 权威数据。
  与国办发明电〔2025〕7号逐项对照核验后采用交易所口径：
  元旦 1/1-1/3（1/4 为周末休市）；春节 2/15-2/23（2/14、2/28 为周末休市）；
  清明 4/4-4/6；劳动 5/1-5/5（5/9 为周末休市）；端午 6/19-6/21；
  中秋 9/25-9/27；国庆 10/1-10/7（9/20、10/10 为周末休市）。
  注意：国务院通知中的"补班上班日"与 A股交易日历不完全一致，一律以交易所公告为准。
- 2027 年：国务院办公厅通知尚未发布（预计 2026 年 11 月发布）。
  下表为按《全国年节及纪念日放假办法》（2024-11 修订，自 2025 起春节4天/劳动2天，
  共13天法定假日；春节自除夕调休8天、除夕逢周五可顺连9天，如2027年）推算的
  **暂定安排**（标注 _PROVISIONAL_2027），正式通知及交易所公告发布后必须立即更新。
  推算：除夕 2027-02-05（周五）→ 春节 2/5-2/13 共 9 天；补班暂定 1/30（六）、2/14（日）。
  元旦 1/1（五）-1/3；清明 4/3（六）-4/5（一）；劳动 5/1（六）-5/5（三），补班 5/8（六）；
  端午 6/9（三，逢周三只放当天）；中秋 9/15（三，逢周三只放当天）；
  国庆 10/1（五）-10/7（四），补班暂定 9/26（日）、10/9（六）。
  2027 补班日是否开市同样以交易所公告为准（按 2026 年经验：补班上班日 A股 均周末休市）。
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

# 2026 年国务院补班上班日（1/4、2/14、2/28、5/9、9/20、10/10）沪深北交易所均列为周末休市，
# 不算交易日（上证公告〔2025〕45号），故 _MAKEUP_2026 为空。保留空集合仅用于占位与审计。
_MAKEUP_2026: set[str] = set()

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

# 2027 补班上班日（暂定 1/30、2/14、5/8、9/26、10/9）是否开市待交易所 2027 公告发布后核对；
# 按 2026 年经验（补班上班日 A股 均周末休市），暂不视为交易日。
_MAKEUP_2027: set[str] = set()

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
    """A股交易日判断：以交易所休市安排为准；周末/法定节假日非交易日。

    注意：调休补班上班日（如 2026-09-20）不是 A股交易日（交易所公告明确周末休市），
    此判断不依赖 MAKEUP_WORKDAYS（当前恒为空，待交易所年度公告发布后核对填充）。
    """
    d = _as_date(date)
    iso = d.isoformat()
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
