# -*- coding: utf-8 -*-
"""复盘选题逻辑测试：复盘对象应为「上一交易日」，17:00 前执行一律改写上一交易日。

覆盖（翁老规则）：
1. build_daily_tasks 对 stock 栏目生成的 topic 占位日期 = previous_trading_day(build_date)
2. _review_date_of 能从该 topic 还原出同一目标日期
3. 非交易日（周末/节假日）不再从候选栏目排除 stock（周末也该复盘周五）
4. 日历边界：周一上一交易日为上周五；春节后首交易日上一交易日为补班日
5. _stock_review_target 硬闸门：
   - 17:00 前执行：无论 topic 写什么，target 强制 = 上一交易日
   - 17:00 后执行：topic 指定过去交易日则尊重，否则上一交易日
   - 绝不返回今天或未来（当天交易未结束不复盘当天）
"""
import datetime
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

BACKEND = Path(__file__).resolve().parent.parent / "backend"
sys.path.insert(0, str(BACKEND))

_tmpdir = tempfile.mkdtemp(prefix="abp-rev-")
os.environ["ABLOG__DATA__DB_PATH"] = str(Path(_tmpdir) / "test.db")
os.environ["ABLOG__AI__ENABLED"] = "false"
os.environ["ABLOG__SCHEDULER__ENABLED"] = "false"
os.environ["ABLOG__PUBLISH__ENABLED"] = "true"

from scheduler import daily_queue as dq          # noqa: E402
from scheduler.calendar import previous_trading_day  # noqa: E402
from config import get_config                     # noqa: E402


class TestReviewPrevTradingDay(unittest.TestCase):
    def setUp(self):
        from core import db
        db.init_db()
        cfg = get_config()
        cfg.set("columns.stock.enabled", True)
        cfg.set("columns.tech.enabled", False)
        cfg.set("columns.reading.enabled", False)
        cfg.set("columns.book.enabled", False)
        cfg.set("columns.industry.enabled", False)
        cfg.set("daily.articles_per_day", 1)
        cfg.set("publish.enabled", True)
        self._patchers = []
        for fn in ("execute",):
            p = mock.patch.object(db, fn, lambda *a, **k: None)
            p.start(); self._patchers.append(p)
        p = mock.patch.object(db, "query", lambda *a, **k: [])
        p.start(); self._patchers.append(p)
        p = mock.patch.object(db, "query_one", lambda *a, **k: None)
        p.start(); self._patchers.append(p)
        # build_daily_tasks 内局部 import sync_from_wp，失败会被 try/except 吞掉，这里允许其不存在
        p = mock.patch("scheduler.wp_sync.sync_from_wp", create=True, return_value=None)
        p.start(); self._patchers.append(p)

    def tearDown(self):
        for p in self._patchers:
            p.stop()

    def _stock_topic(self, build_date):
        cfg = get_config()
        tasks = dq.build_daily_tasks(date=build_date)
        for t in tasks:
            if t["column"] == "stock":
                return t["topic"]
        return None

    def test_thursday_reviews_wednesday(self):
        topic = self._stock_topic(datetime.date(2026, 8, 20))
        self.assertEqual(topic, "2026-08-19 A股每日复盘")
        self.assertEqual(dq._review_date_of(topic), datetime.date(2026, 8, 19))

    def test_monday_reviews_friday(self):
        topic = self._stock_topic(datetime.date(2026, 8, 17))
        self.assertEqual(topic, "2026-08-14 A股每日复盘")

    def test_weekend_reviews_friday(self):
        for wd in (datetime.date(2026, 8, 22), datetime.date(2026, 8, 23)):
            self.assertEqual(self._stock_topic(wd), "2026-08-21 A股每日复盘")

    def test_spring_festival_makeup(self):
        # 春节后首交易日（周一 2/16）的上一交易日 = 2/14 补班日（calendar 已正确识别调休）
        self.assertEqual(previous_trading_day(datetime.date(2026, 2, 16)), datetime.date(2026, 2, 14))

    def test_non_trading_day_not_excluded_from_columns(self):
        cols = dq._day_columns(datetime.date(2026, 8, 22))
        self.assertIn("stock", cols)

    # ---------------- 硬闸门 _stock_review_target ----------------
    def test_gate_before_17h_forces_prev_td(self):
        # 周四上午 10 点，topic 哪怕写成「今天」，也强制上一交易日（周三）
        now = datetime.datetime(2026, 8, 20, 10, 0, 0)
        target, corrected = dq._stock_review_target("2026-08-20 A股每日复盘", now)
        self.assertEqual(target, datetime.date(2026, 8, 19))
        self.assertIsNotNone(corrected)

    def test_gate_before_17h_forces_prev_td_even_if_future_topic(self):
        # 周一上午，topic 误写成未来日期，仍强制上一交易日（上周五）
        now = datetime.datetime(2026, 8, 17, 9, 0, 0)
        target, _ = dq._stock_review_target("2026-08-20 A股每日复盘", now)
        self.assertEqual(target, datetime.date(2026, 8, 14))

    def test_gate_after_20h_respects_explicit_past(self):
        # 晚上 21 点，topic 显式指定过去交易日，尊重之
        now = datetime.datetime(2026, 8, 20, 21, 0, 0)
        target, corrected = dq._stock_review_target("2026-08-18 A股每日复盘", now)
        self.assertEqual(target, datetime.date(2026, 8, 18))
        self.assertIsNone(corrected)  # 已一致，无需校正

    def test_gate_after_20h_defaults_prev_td(self):
        # 晚上 21 点，topic 无日期占位，默认上一交易日
        now = datetime.datetime(2026, 8, 20, 21, 0, 0)
        target, _ = dq._stock_review_target("", now)
        self.assertEqual(target, datetime.date(2026, 8, 19))

    def test_gate_never_returns_today_or_future(self):
        for hh in (8, 12, 16, 20, 22):
            now = datetime.datetime(2026, 8, 20, hh, 0, 0)
            for topic in ("2026-08-20 A股每日复盘", "2026-08-25 A股每日复盘", ""):
                target, _ = dq._stock_review_target(topic, now)
                self.assertLess(target, datetime.date(2026, 8, 20),
                                f"hh={hh} topic={topic} 不应返回今天/未来")


if __name__ == "__main__":
    unittest.main(verbosity=2)
