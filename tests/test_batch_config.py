# -*- coding: utf-8 -*-
"""批量拆小 + 发布间隔 配置与调度单测（翁老 2026-08-05 需求）。

覆盖：
1. _schedule_publish_times：同日多篇间隔 >= min_interval_minutes，且在窗口内
2. 窗口放不下时退化为均匀分散（不崩溃、不挤同一分钟）
3. 单篇随机
4. config.yaml 新键 batch.* / publish.min_interval_minutes 齐全
5. fill_pool 缺省 n/limit 走 config（静态断言默认值语义）
"""
import datetime
import random
import sys
from pathlib import Path

BACKEND = Path(__file__).resolve().parent.parent / "backend"
sys.path.insert(0, str(BACKEND))

from scheduler.daily_queue import _schedule_publish_times  # noqa: E402


class Cfg(dict):
    """最小 Config 替身（点路径访问）。"""
    def get(self, dotted, default=None):
        node = self
        for part in dotted.split("."):
            if isinstance(node, dict) and part in node:
                node = node[part]
            else:
                return default
        return node


def _mins(iso: str) -> int:
    return int(iso[11:13]) * 60 + int(iso[14:16])


def test_interval_enforced():
    cfg = Cfg({"publish": {"window_start": "09:00", "window_end": "21:00",
                           "min_interval_minutes": 120}})
    for seed in range(50):
        rng = random.Random(seed)
        ts = _schedule_publish_times(datetime.date(2026, 8, 5), 3, rng, cfg)
        mins = [_mins(t) for t in ts]
        assert len(mins) == 3, mins
        assert all(9 * 60 <= m <= 21 * 60 for m in mins), mins
        for a, b in zip(mins, mins[1:]):
            assert b - a >= 120, (seed, mins)
    print("test_interval_enforced OK: 50 组随机, 相邻间隔均 >= 120min 且在 09:00-21:00 内")


def test_window_too_small_fallback():
    cfg = Cfg({"publish": {"window_start": "09:00", "window_end": "10:00",
                           "min_interval_minutes": 120}})
    ts = _schedule_publish_times(datetime.date(2026, 8, 5), 10, random.Random(1), cfg)
    mins = [_mins(t) for t in ts]
    assert len(mins) == 10
    assert all(9 * 60 <= m <= 10 * 60 for m in mins), mins
    assert len(set(mins)) >= 9, mins  # 至少不挤在同一分钟
    print("test_window_too_small_fallback OK:", mins)


def test_single_random():
    cfg = Cfg({"publish": {"window_start": "09:00", "window_end": "21:00",
                           "min_interval_minutes": 120}})
    ts = _schedule_publish_times(datetime.date(2026, 8, 5), 1, random.Random(2), cfg)
    m = _mins(ts[0])
    assert 9 * 60 <= m <= 21 * 60
    print("test_single_random OK:", ts[0])


def test_config_keys():
    import yaml
    with open(BACKEND / "config.yaml", encoding="utf-8") as f:
        d = yaml.safe_load(f)
    assert d["batch"]["fill_size"] == 1
    assert d["batch"]["pool_limit"] == 20
    assert d["batch"]["run_per_round"] == 1
    assert d["publish"]["min_interval_minutes"] == 120
    print("test_config_keys OK: batch.* / publish.min_interval_minutes 齐全")


def test_fill_pool_defaults():
    import inspect
    from scheduler import pool
    sig = inspect.signature(pool.fill_pool)
    assert sig.parameters["n"].default is None, "fill_pool 默认 n 应为 None（走 config）"
    assert sig.parameters["limit"].default is None, "fill_pool 默认 limit 应为 None（走 config）"
    assert pool.POOL_LIMIT == 20, "POOL_LIMIT 保留为回退默认 20"
    print("test_fill_pool_defaults OK: fill_pool 缺省值已改为 config 驱动")


if __name__ == "__main__":
    test_interval_enforced()
    test_window_too_small_fallback()
    test_single_random()
    test_config_keys()
    test_fill_pool_defaults()
    print("\nALL TESTS PASSED")
