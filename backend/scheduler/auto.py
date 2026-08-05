# -*- coding: utf-8 -*-
"""scheduler/auto.py — 内置调度线程（v1.4，方案 A：Windows/Linux 通用，免 crontab/计划任务）

常驻后端（uvicorn）启动后由 main.py 挂载，自动完成：
- 每日到点建当日任务队列（scheduler.build_time，默认 08:00，每天一次）
- 到点按轮次生成正文（scheduler.run_times，默认 20:30/21:00/21:30，
  每轮受 batch.run_per_round 限制，拆小批量防内存/限流峰值）
- 周期检查到期发布（scheduler.publish_every_seconds，默认 600s；幂等，
  停机期间积压的到期任务启动后自动补发）

全部可配（config.yaml scheduler 段），/api/reload-config 热重载即时生效。
轮次防重：scheduler_runs 表记录 (date, slot)，重启后不重复触发。
"""

from __future__ import annotations

import datetime
import threading
from typing import List, Optional

from config import get_config
from core import db, logger


def _table_init() -> None:
    """建调度状态表（幂等，带进程内标志，避免每秒 tick 重复执行 DDL）。"""
    global _table_inited
    if _table_inited:
        return
    db.execute(
        """CREATE TABLE IF NOT EXISTS scheduler_runs (
             date TEXT NOT NULL,
             slot TEXT NOT NULL,
             run_at TEXT,
             PRIMARY KEY (date, slot)
           )"""
    )
    _table_inited = True


_table_inited = False


def _has_run(date: str, slot: str) -> bool:
    row = db.query_one(
        "SELECT 1 FROM scheduler_runs WHERE date=? AND slot=?", (date, slot)
    )
    return row is not None


def _mark_run(date: str, slot: str) -> None:
    db.execute(
        "INSERT OR REPLACE INTO scheduler_runs (date, slot, run_at) VALUES (?, ?, ?)",
        (date, slot, db.now_iso()),
    )


class AutoScheduler:
    """后台调度线程（daemon，随进程退出）。"""

    def __init__(self) -> None:
        self._stop = threading.Event()
        self._thread: Optional[threading.Thread] = None
        self._lock = threading.Lock()
        self._last_publish_ts: float = 0.0  # 0 → 启动后立即补发一次积压到期任务

    # ------------------------------------------------------------------ 生命周期
    def start(self) -> None:
        _table_init()
        cfg = get_config()
        if not bool(cfg.get("scheduler.enabled", False)):
            logger.info("auto scheduler: disabled (scheduler.enabled=false)")
            return
        self._thread = threading.Thread(
            target=self._loop, name="abp-auto-scheduler", daemon=True
        )
        self._thread.start()
        logger.info(
            f"auto scheduler: started (build={cfg.get('scheduler.build_time', '08:00')} "
            f"run={cfg.get('scheduler.run_times', [])} "
            f"publish_every={cfg.get('scheduler.publish_every_seconds', 600)}s)"
        )

    def stop(self) -> None:
        self._stop.set()

    @property
    def alive(self) -> bool:
        return bool(self._thread and self._thread.is_alive())

    # ------------------------------------------------------------------ 主循环
    def _loop(self) -> None:
        while not self._stop.wait(1.0):
            try:
                self._tick(datetime.datetime.now())
            except Exception as e:  # noqa: BLE001 调度线程永不退出
                logger.error(f"auto scheduler tick error: {e}")

    # ------------------------------------------------------------------ 调度判定
    def _tick(self, now: datetime.datetime) -> None:
        """每秒 tick；到点动作加锁串行，防并发重入。now 可注入便于测试。"""
        _table_init()
        cfg = get_config()
        today = now.date().isoformat()
        hm = now.strftime("%H:%M")
        with self._lock:
            # 1) 每日建队列（每天一次，scheduler_runs 防重）
            build_time = str(cfg.get("scheduler.build_time", "08:00"))
            if hm == build_time and not _has_run(today, "build"):
                _mark_run(today, "build")
                logger.info(f"auto scheduler: daily build {today}")
                try:
                    from scheduler.daily_queue import build_daily_tasks

                    tasks = build_daily_tasks()
                    logger.info(
                        f"auto scheduler: build done total={len(tasks)} "
                        f"topics={[t['task_id'] for t in tasks]}"
                    )
                except Exception as e:  # noqa: BLE001
                    logger.error(f"auto scheduler: daily build failed: {e}")

            # 2) 按轮次生成（每轮一次；轮内受 batch.run_per_round 限制）
            for slot in self._run_slots(cfg):
                if hm == slot and not _has_run(today, f"run:{slot}"):
                    _mark_run(today, f"run:{slot}")
                    logger.info(f"auto scheduler: run round {slot}")
                    try:
                        from scheduler.daily_queue import run_pending_tasks

                        results = run_pending_tasks()
                        ok = sum(1 for r in results if r.get("ok"))
                        logger.info(
                            f"auto scheduler: round {slot} done {ok}/{len(results)} ready"
                        )
                    except Exception as e:  # noqa: BLE001
                        logger.error(f"auto scheduler: round {slot} failed: {e}")

            # 3) 周期到期发布（幂等；停机积压自动补发）
            every = max(30, int(cfg.get("scheduler.publish_every_seconds", 600)))
            if now.timestamp() - self._last_publish_ts >= every:
                self._last_publish_ts = now.timestamp()
                try:
                    from scheduler.daily_queue import publish_due_tasks

                    results = publish_due_tasks()
                    ok = sum(1 for r in results if r.get("ok"))
                    if results:
                        logger.info(
                            f"auto scheduler: publish due {ok}/{len(results)} "
                            f"(ids={[r.get('task_id') for r in results]})"
                        )
                except Exception as e:  # noqa: BLE001
                    logger.error(f"auto scheduler: publish due failed: {e}")

    @staticmethod
    def _run_slots(cfg) -> List[str]:
        """生成轮次列表（归一化 HH:MM，非法项剔除）。"""
        raw = cfg.get("scheduler.run_times", [])
        if isinstance(raw, str):
            raw = [raw]
        out = []
        for item in raw or []:
            s = str(item).strip()
            try:
                datetime.datetime.strptime(s, "%H:%M")
                out.append(s)
            except ValueError:
                logger.warning(f"auto scheduler: bad run_time '{s}' ignored")
        return sorted(set(out))
