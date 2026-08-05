# -*- coding: utf-8 -*-
"""内置调度 AutoScheduler 单元测试（临时库隔离 + mock 任务函数，不耗 Token）。

覆盖：
1. build_time 到点触发 build_daily_tasks，同一天防重
2. run_times 到点触发 run_pending_tasks，同轮防重
3. publish 周期：启动立即补发一次，周期内不重复，超周期再触发
4. 非法 run_time 被忽略
5. scheduler.enabled=false 时不启动线程
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

# 隔离：临时 SQLite + 调度配置
_tmpdir = tempfile.mkdtemp(prefix="abp-test-")
os.environ["ABLOG__DATA__DB_PATH"] = str(Path(_tmpdir) / "test.db")
os.environ["ABLOG__SCHEDULER__ENABLED"] = "true"
os.environ["ABLOG__SCHEDULER__BUILD_TIME"] = "08:00"
os.environ["ABLOG__SCHEDULER__RUN_TIMES"] = '["20:30", "21:00"]'
os.environ["ABLOG__SCHEDULER__PUBLISH_EVERY_SECONDS"] = "600"
os.environ["ABLOG__AI__ENABLED"] = "false"  # 防真实生成（mock 兜底 + 双保险）

from scheduler.auto import AutoScheduler, _has_run, _table_init  # noqa: E402


def _at(hh: int, mm: int, ss: int = 0) -> datetime.datetime:
    return datetime.datetime(2026, 8, 5, hh, mm, ss)


class TestAutoScheduler(unittest.TestCase):
    def setUp(self):
        from core import db
        from config import get_config

        db.init_db()
        _table_init()
        db.execute("DELETE FROM scheduler_runs")
        # 重置调度配置（防 test_bad_run_time_ignored 等测试污染全局 cfg）
        cfg = get_config()
        cfg.set("scheduler.build_time", "08:00")
        cfg.set("scheduler.run_times", ["20:30", "21:00"])
        cfg.set("scheduler.publish_every_seconds", 600)
        cfg.set("scheduler.enabled", True)
        self.sched = AutoScheduler()

    def tearDown(self):
        self.sched.stop()

    def test_build_trigger_and_dedupe(self):
        with mock.patch(
            "scheduler.daily_queue.build_daily_tasks", return_value=[]
        ) as m_build, mock.patch(
            "scheduler.daily_queue.run_pending_tasks", return_value=[]
        ), mock.patch(
            "scheduler.daily_queue.publish_due_tasks", return_value=[]
        ):
            self.sched._tick(_at(8, 0, 30))   # 到点
            self.sched._tick(_at(8, 0, 45))   # 同分钟再 tick
            self.sched._tick(_at(8, 1, 0))    # 下一分钟
            m_build.assert_called_once_with()

    def test_run_round_trigger_and_dedupe(self):
        with mock.patch(
            "scheduler.daily_queue.build_daily_tasks", return_value=[]
        ), mock.patch(
            "scheduler.daily_queue.run_pending_tasks", return_value=[]
        ) as m_run, mock.patch(
            "scheduler.daily_queue.publish_due_tasks", return_value=[]
        ):
            self.sched._tick(_at(20, 30, 10))  # 第一轮
            self.sched._tick(_at(20, 30, 20))  # 同轮防重
            self.sched._tick(_at(21, 0, 5))    # 第二轮
            self.assertEqual(m_run.call_count, 2)
            self.assertTrue(_has_run("2026-08-05", "run:20:30"))
            self.assertTrue(_has_run("2026-08-05", "run:21:00"))

    def test_publish_periodic(self):
        with mock.patch(
            "scheduler.daily_queue.build_daily_tasks", return_value=[]
        ), mock.patch(
            "scheduler.daily_queue.run_pending_tasks", return_value=[]
        ), mock.patch(
            "scheduler.daily_queue.publish_due_tasks", return_value=[]
        ) as m_pub:
            self.sched._tick(_at(9, 0, 0))    # 首次：立即补发（_last_publish_ts=0）
            self.sched._tick(_at(9, 5, 0))    # 5 分钟后：未到 600s
            self.sched._tick(_at(9, 11, 0))   # 11 分钟后：>600s 再触发
            self.assertEqual(m_pub.call_count, 2)

    def test_bad_run_time_ignored(self):
        from config import get_config

        cfg = get_config()
        cfg.set("scheduler.run_times", ["20:30", "bad-time", "25:99"])
        slots = AutoScheduler._run_slots(cfg)
        self.assertEqual(slots, ["20:30"])

    def test_disabled_no_thread(self):
        from config import get_config

        cfg = get_config()
        cfg.set("scheduler.enabled", False)
        s = AutoScheduler()
        s.start()
        self.assertFalse(s.alive)


if __name__ == "__main__":
    unittest.main(verbosity=2)
