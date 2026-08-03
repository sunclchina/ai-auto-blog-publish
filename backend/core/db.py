"""SQLite 封装（总纲 §3.3）：线程安全、连接复用。

设计：
- 连接复用：threading.local 每线程一个连接（SQLite 连接不可跨线程使用），
  同线程内重复获取同一连接；连接开启 WAL 模式提升并发读写。
- 线程安全：全局写锁（RLock）串行化 execute/executemany/transaction，
  SQLite 单写者模型下保证不互踩。
- init_db() 幂等建表，表结构严格按总纲 §3.3（tasks / fingerprints /
  written_books / quota_daily / blacklist）。
"""

from __future__ import annotations

import datetime
import sqlite3
import threading
from contextlib import contextmanager
from pathlib import Path
from typing import Any, Dict, List, Optional, Sequence

from config import PROJECT_ROOT, get_config  # backend 目录为运行根（统一绝对导入）

_local = threading.local()
_write_lock = threading.RLock()

_SCHEMA = """
CREATE TABLE IF NOT EXISTS tasks (
  task_id TEXT PRIMARY KEY,
  column_name TEXT NOT NULL,        -- stock/tech/reading/book
  topic TEXT, title TEXT, outline TEXT, content TEXT, excerpt TEXT,
  status TEXT DEFAULT 'queued',     -- queued|generating|humanize|ready|published|failed|skipped
  model TEXT, tokens_used INTEGER DEFAULT 0,
  error TEXT, created_at TEXT, updated_at TEXT, published_at TEXT,
  publish_date TEXT,                -- 定时发布 ISO8601（调度层写入，publish_due 筛选依据）
  topic_candidates TEXT,            -- 选题候选 JSON 数组（智能选题备选列表，供人工调整）
  sort_order INTEGER DEFAULT 0      -- 执行顺序（人工可调整排队顺序，小者先执行）
);
CREATE TABLE IF NOT EXISTS fingerprints (
  fhash TEXT PRIMARY KEY,           -- SimHash 64bit hex
  task_id TEXT, title TEXT, column_name TEXT, created_at TEXT
);
CREATE TABLE IF NOT EXISTS written_books (
  book_title TEXT PRIMARY KEY,      -- 书目防重复（读书栏目）
  task_id TEXT, created_at TEXT
);
CREATE TABLE IF NOT EXISTS quota_daily (
  day TEXT PRIMARY KEY,             -- YYYY-MM-DD
  tokens_used INTEGER DEFAULT 0,
  articles_published INTEGER DEFAULT 0
);
CREATE TABLE IF NOT EXISTS blacklist (
  word TEXT PRIMARY KEY, kind TEXT  -- keyword|topic 黑名单
);
CREATE TABLE IF NOT EXISTS topic_pool (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  column_name TEXT NOT NULL,        -- stock/tech/reading/book
  topic TEXT NOT NULL,              -- 备用选题题目
  source TEXT DEFAULT 'ai',         -- ai|manual|local
  status TEXT DEFAULT 'queued',     -- queued|used|deleted
  sort_order INTEGER DEFAULT 0,     -- 排队顺序（人工可调）
  created_at TEXT, used_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_tasks_status ON tasks(status);
CREATE INDEX IF NOT EXISTS idx_tasks_column ON tasks(column_name);
CREATE INDEX IF NOT EXISTS idx_fp_task ON fingerprints(task_id);
"""


def now_iso() -> str:
    """本地时间 ISO8601（秒精度），入库统一用。"""
    return datetime.datetime.now().astimezone().isoformat(timespec="seconds")


def db_path() -> Path:
    cfg = get_config()
    raw = cfg.get("data.db_path", "data/ablog.db")
    p = Path(raw)
    if not p.is_absolute():
        p = PROJECT_ROOT / p
    return p


def _connect() -> sqlite3.Connection:
    p = db_path()
    p.parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(str(p), timeout=30, check_same_thread=False)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA foreign_keys=ON")
    conn.execute("PRAGMA busy_timeout=30000")
    return conn


def get_conn() -> sqlite3.Connection:
    """获取当前线程的连接（复用）。"""
    conn = getattr(_local, "conn", None)
    if conn is None:
        conn = _connect()
        _local.conn = conn
    return conn


def close_conn() -> None:
    """关闭当前线程连接（测试/退出时调用）。"""
    conn = getattr(_local, "conn", None)
    if conn is not None:
        try:
            conn.close()
        finally:
            _local.conn = None


def init_db() -> None:
    """幂等建表 + 列迁移。"""
    with _write_lock:
        conn = _connect()
        try:
            conn.executescript(_SCHEMA)
            # 幂等迁移：老库缺 publish_date 列时补上（不破坏既有数据）
            cols = [r["name"] for r in conn.execute("PRAGMA table_info(tasks)").fetchall()]
            if cols and "publish_date" not in cols:
                conn.execute("ALTER TABLE tasks ADD COLUMN publish_date TEXT")
            if cols and "topic_candidates" not in cols:
                conn.execute("ALTER TABLE tasks ADD COLUMN topic_candidates TEXT")
            if cols and "sort_order" not in cols:
                conn.execute("ALTER TABLE tasks ADD COLUMN sort_order INTEGER DEFAULT 0")
            # topic_pool 表老库补建（旧库无此表）
            tables = [r["name"] for r in conn.execute("SELECT name FROM sqlite_master WHERE type='table'").fetchall()]
            if tables and "topic_pool" not in tables:
                conn.execute(
                    """CREATE TABLE IF NOT EXISTS topic_pool (
                      id INTEGER PRIMARY KEY AUTOINCREMENT,
                      column_name TEXT NOT NULL,
                      topic TEXT NOT NULL,
                      source TEXT DEFAULT 'ai',
                      status TEXT DEFAULT 'queued',
                      sort_order INTEGER DEFAULT 0,
                      created_at TEXT, used_at TEXT
                    )"""
                )
            conn.commit()
        finally:
            conn.close()


def execute(sql: str, params: Sequence[Any] = ()) -> int:
    """执行写语句并提交，返回 lastrowid。"""
    with _write_lock:
        conn = get_conn()
        cur = conn.execute(sql, params)
        conn.commit()
        return cur.lastrowid


def executemany(sql: str, seq: Sequence[Sequence[Any]]) -> None:
    with _write_lock:
        conn = get_conn()
        conn.executemany(sql, seq)
        conn.commit()


def query(sql: str, params: Sequence[Any] = ()) -> List[Dict[str, Any]]:
    conn = get_conn()
    rows = conn.execute(sql, params).fetchall()
    return [dict(r) for r in rows]


def query_one(sql: str, params: Sequence[Any] = ()) -> Optional[Dict[str, Any]]:
    row = get_conn().execute(sql, params).fetchone()
    return dict(row) if row else None


@contextmanager
def transaction():
    """事务上下文：正常提交，异常回滚。"""
    with _write_lock:
        conn = get_conn()
        try:
            conn.execute("BEGIN")
            yield conn
            conn.commit()
        except Exception:
            conn.rollback()
            raise


def upsert_task(task: dict) -> None:
    """任务对象（总纲 §3.1）落库：存在则更新，不存在则插入。

    main.py / pipeline 收尾时调用；字段缺失时用默认值兜底，绝不抛异常中断流程。
    """
    import json as _json

    col = str(task.get("column") or "")[:32]
    # 原始栏目名优先（column_name，如“行业”）；无则回退流水线 code（column）
    if task.get("column_name"):
        col = str(task["column_name"])[:32]
    title = str(task.get("final_title") or "")[:500]
    topic = str(task.get("topic") or "")[:2000]
    outline = _json.dumps(task.get("outline"), ensure_ascii=False) if isinstance(task.get("outline"), (list, dict)) else str(task.get("outline") or "")[:8000]
    content = str(task.get("content_html") or "")[:200000]
    excerpt = str(task.get("excerpt") or "")[:500]
    status = str(task.get("status") or "queued")[:32]
    model = str((task.get("source") or {}).get("model") or "")[:64]
    tokens = int(task.get("tokens_used") or 0)
    error = str(task.get("error") or "")[:1000]
    now = now_iso()
    publish_date = str(task.get("publish_date") or "")[:40]
    candidates = task.get("topic_candidates")
    candidates_json = _json.dumps(candidates, ensure_ascii=False) if candidates else ""

    with transaction() as conn:
        conn.execute(
            """INSERT INTO tasks (task_id, column_name, topic, title, outline, content, excerpt,
                                   status, model, tokens_used, error, created_at, updated_at, published_at,
                                   publish_date, topic_candidates)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
               ON CONFLICT(task_id) DO UPDATE SET
                 column_name=excluded.column_name, topic=excluded.topic, title=excluded.title,
                 outline=excluded.outline, content=excluded.content, excerpt=excluded.excerpt,
                 status=excluded.status, model=excluded.model, tokens_used=excluded.tokens_used,
                 error=excluded.error, updated_at=excluded.updated_at, published_at=excluded.published_at,
                 publish_date=excluded.publish_date, topic_candidates=excluded.topic_candidates""",
            (task.get("task_id", ""), col, topic, title, outline, content, excerpt,
             status, model, tokens, error, now, now, publish_date, publish_date, candidates_json),
        )
