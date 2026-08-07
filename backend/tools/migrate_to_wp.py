# -*- coding: utf-8 -*-
"""migrate_to_wp.py — 一次性迁移：服务端 SQLite 的备选池/任务 → WordPress 插件表（v1.5.0）。

用法（服务所在机，backend 目录）：
    python -m tools.migrate_to_wp [--pool] [--tasks] [--dry-run]

默认两样都迁。通过插件 REST API 写入（Bearer Token），幂等（task_id 已存在跳过）。
"""

from __future__ import annotations

import argparse
import json
import sqlite3
import sys
from pathlib import Path

BACKEND_DIR = Path(__file__).resolve().parent.parent
if str(BACKEND_DIR) not in sys.path:
    sys.path.insert(0, str(BACKEND_DIR))

import httpx  # noqa: E402

from config import get_config  # noqa: E402


def wp_client(cfg):
    base = str(cfg.get("wordpress.base_url", "")).rstrip("/")
    token = cfg.get("wordpress.api_token", "")
    if not base or not token:
        raise SystemExit("config.yaml 缺少 wordpress.base_url / api_token，无法迁移")
    rest = str(cfg.get("wordpress.rest_path", "/wp-json/ai-auto-blog/v1")).rstrip("/")
    return httpx.Client(base_url=base + rest, headers={"Authorization": f"Bearer {token}"}, timeout=30)


def migrate_pool(db_path: str, client: httpx.Client, dry: bool) -> tuple[int, int]:
    con = sqlite3.connect(db_path)
    con.row_factory = sqlite3.Row
    rows = con.execute(
        "SELECT id, column_name, topic, source, status FROM topic_pool WHERE status='queued' ORDER BY sort_order ASC, id ASC"
    ).fetchall()
    con.close()
    ok = fail = 0
    for r in rows:
        if dry:
            print(f"  [dry] pool: {r['column_name']} / {r['topic'][:40]}")
            ok += 1
            continue
        resp = client.post("/pool", json={
            "column": r["column_name"] or "tech",
            "topic": r["topic"],
            "source": r["source"] or "ai",
        })
        if resp.status_code == 200:
            ok += 1
        else:
            fail += 1
            print(f"  [fail] pool {r['id']}: HTTP {resp.status_code} {resp.text[:120]}")
    return ok, fail


def migrate_tasks(db_path: str, client: httpx.Client, dry: bool) -> tuple[int, int]:
    con = sqlite3.connect(db_path)
    con.row_factory = sqlite3.Row
    today = __import__("datetime").date.today().strftime("%Y%m%d")
    rows = con.execute(
        "SELECT task_id, column_name, topic, status, topic_candidates, publish_at FROM tasks "
        "WHERE task_id LIKE ? AND status NOT IN ('published','failed','skipped') ORDER BY sort_order ASC, id ASC",
        (today + "%",),
    ).fetchall()
    con.close()
    ok = fail = 0
    for r in rows:
        cands = []
        try:
            cands = json.loads(r["topic_candidates"]) if r["topic_candidates"] else []
        except (ValueError, TypeError):
            cands = []
        if dry:
            print(f"  [dry] task: {r['task_id']} / {r['topic'][:40]}")
            ok += 1
            continue
        resp = client.post("/tasks", json={
            "task_id": r["task_id"],
            "column": r["column_name"] or "tech",
            "topic": r["topic"] or "",
            "candidates": cands if isinstance(cands, list) else [],
            "publish_at": r["publish_at"] or None,
        })
        if resp.status_code == 200:
            ok += 1
        else:
            fail += 1
            print(f"  [fail] task {r['task_id']}: HTTP {resp.status_code} {resp.text[:120]}")
    return ok, fail


def main() -> int:
    ap = argparse.ArgumentParser(description="迁移备选池/任务到 WP")
    ap.add_argument("--pool", action="store_true", help="只迁备选池")
    ap.add_argument("--tasks", action="store_true", help="只迁今日任务")
    ap.add_argument("--dry-run", action="store_true", help="只打印不写入")
    args = ap.parse_args()

    cfg = get_config()
    db_path = cfg.get("data.db_path", str(BACKEND_DIR / "../data/ablog.db"))
    do_pool = args.pool or not args.tasks
    do_tasks = args.tasks or not args.pool

    print(f"目标: {cfg.get('wordpress.base_url')}  数据库: {db_path}  dry-run={args.dry_run}")
    client = wp_client(cfg)

    if do_pool:
        ok, fail = migrate_pool(db_path, client, args.dry_run)
        print(f"备选池迁移完成: 成功 {ok}, 失败 {fail}")
    if do_tasks:
        ok, fail = migrate_tasks(db_path, client, args.dry_run)
        print(f"任务迁移完成: 成功 {ok}, 失败 {fail}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
