"""每日任务队列（总纲 §4 调度规则）。

职责：
- 生成当日任务清单：栏目轮换（默认 stock 40% / tech 30% / reading 15% + book 15%，
  合计 100，可配 daily.rotation）、每日篇数（默认 3，可配 daily.articles_per_day）
- 任务状态机流转（tasks 表 status）：queued → generating → humanize → ready
  → published | failed | skipped（失败可重试回 queued）
- 模拟人工时段：发布时间在 publish.window_start ~ window_end 内随机分钟
- 发布开关：publish.enabled=false 时任务 publish_mode=draft（WP 端存草稿不推送）
- 复盘栏目（stock）仅交易日生成；非交易日自动排除
- 选题数据由 collectors/agents 模块通过 register_topic_provider() 注入，
  未注册前 topic 保持为空字符串 —— 本模块绝不伪造选题数据（原则2）

CLI（crontab 使用，见 scheduler/crontab.txt）：
  python -m backend.scheduler.daily_queue --build-today   生成今日任务清单
  python -m backend.scheduler.daily_queue --publish-due   发布到期任务（ready 且到点）
"""

from __future__ import annotations

import argparse
import datetime
import random
import re
import sys
from typing import Callable, Dict, List, Optional

from config import get_config  # backend 目录为运行根（统一绝对导入）
from core import db, logger

# ---------------------------------------------------------------------------
# 任务状态机（总纲 §3.3 status 枚举）
# ---------------------------------------------------------------------------

VALID_STATUSES = ("queued", "generating", "humanize", "ready", "published", "failed", "skipped")

TRANSITIONS: Dict[str, set] = {
    "queued": {"generating", "skipped", "failed"},
    "generating": {"humanize", "failed"},
    "humanize": {"ready", "failed"},
    "ready": {"published", "failed", "skipped"},
    "published": set(),
    "failed": {"queued", "skipped"},     # 允许失败后重试/放弃
    "skipped": set(),
}


class TaskStateError(Exception):
    """非法状态流转。"""


def allowed_transitions(status: str) -> set:
    return set(TRANSITIONS.get(status, set()))


def transition(task_id: str, to: str, error: Optional[str] = None) -> None:
    """状态机流转：校验合法性后更新 tasks 表（幂等更新 error/updated_at）。"""
    if to not in VALID_STATUSES:
        raise TaskStateError(f"invalid target status: {to}")
    row = db.query_one("SELECT status FROM tasks WHERE task_id=?", (task_id,))
    if not row:
        raise TaskStateError(f"task not found: {task_id}")
    current = row["status"]
    if to not in allowed_transitions(current):
        raise TaskStateError(f"invalid transition {current} -> {to} for {task_id}")
    db.execute(
        "UPDATE tasks SET status=?, error=?, updated_at=? WHERE task_id=?",
        (to, error, db.now_iso(), task_id),
    )
    logger.info(f"task {task_id} status {current} -> {to}", task_id=task_id)


# ---------------------------------------------------------------------------
# 栏目轮换
# ---------------------------------------------------------------------------

def enabled_columns() -> List[str]:
    """当前启用的栏目（columns.*.enabled）。"""
    cfg = get_config()
    cols = cfg.get("columns", {})
    return [c for c, v in cols.items() if isinstance(v, dict) and v.get("enabled")]


def _rotation_weights(cfg) -> tuple:
    cols = enabled_columns()
    rot = cfg.get("daily.rotation", {})
    weights = {c: int(rot.get(c, 0)) for c in cols}
    total = sum(weights.values())
    if total <= 0:
        weights = {c: 1 for c in cols}
        total = len(cols)
    return weights, total


def pick_columns(count: int, weights: Optional[Dict[str, int]] = None, rng: Optional[random.Random] = None) -> List[str]:
    """按权重选 count 个栏目。

    - count < 启用栏目数：无放回加权抽样（当日栏目不重复）
    - count >= 启用栏目数：有放回加权抽样（允许同栏目多篇）
    """
    cols = enabled_columns()
    if count <= 0 or not cols:
        return []
    rng = rng or random.Random()
    if weights is None:
        weights, _ = _rotation_weights(get_config())
    w = [max(0, int(weights.get(c, 0))) for c in cols]
    if sum(w) <= 0:
        w = [1] * len(cols)
    if count >= len(cols):
        return [rng.choices(cols, weights=w, k=count)]
    pool = cols[:]
    pool_w = w[:]
    picks = []
    for _ in range(count):
        c = rng.choices(pool, weights=pool_w, k=1)[0]
        picks.append(c)
        i = pool.index(c)
        pool.pop(i)
        pool_w.pop(i)
    return picks


def _day_columns(date: datetime.date) -> List[str]:
    """当日可选栏目：非交易日排除复盘栏目（stock）。"""
    from .calendar import is_trading_day
    cols = enabled_columns()
    if not is_trading_day(date):
        cols = [c for c in cols if c != "stock"]
    return cols


# ---------------------------------------------------------------------------
# 模拟人工时段
# ---------------------------------------------------------------------------

def _random_publish_time(date: datetime.date, rng: random.Random, cfg) -> str:
    """窗口内随机分钟 -> ISO8601 字符串（本地时间，秒精度）。"""
    start = str(cfg.get("publish.window_start", "09:00"))
    end = str(cfg.get("publish.window_end", "21:00"))
    try:
        h1, m1 = map(int, start.split(":"))
        h2, m2 = map(int, end.split(":"))
    except ValueError:
        h1, m1, h2, m2 = 9, 0, 21, 0
    total = (h2 * 60 + m2) - (h1 * 60 + m1)
    if total <= 0:
        total = 60
    minutes = h1 * 60 + m1 + rng.randrange(0, total)
    h, m = divmod(minutes, 60)
    return datetime.datetime(date.year, date.month, date.day, h, m).isoformat(timespec="seconds")


# ---------------------------------------------------------------------------
# 选题提供方注册（collectors/agents 模块注入）
# ---------------------------------------------------------------------------

_topic_providers: Dict[str, Callable] = {}


def register_topic_provider(column: str, fn: Callable) -> None:
    """注册选题提供方：fn(date: datetime.date, column: str) -> str 选题描述。
    由 collectors/agents 模块在启动时调用。"""
    if column not in ("stock", "tech", "reading", "book"):
        raise ValueError(f"unknown column: {column}")
    _topic_providers[column] = fn


def has_topic_provider(column: str) -> bool:
    return column in _topic_providers


def _topic_for(column: str, date: datetime.date) -> str:
    fn = _topic_providers.get(column)
    if fn:
        try:
            return str(fn(date, column) or "")
        except Exception as e:
            logger.warning(f"topic provider failed column={column} err={e}")
            return ""
    return ""


# ---------------------------------------------------------------------------
# 任务清单构建
# ---------------------------------------------------------------------------

def build_daily_tasks(date: Optional[datetime.date] = None,
                      count: Optional[int] = None,
                      rng: Optional[random.Random] = None) -> List[dict]:
    """生成当日任务清单并写库（tasks 表，status=queued），返回任务 dict 列表。

    - count 缺省取 daily.articles_per_day（1-10 收敛）
    - 发布开关 off -> 任务 publish_mode='draft'
    """
    # 同步 WP 后台开关（复选框），失败静默回退 config.yaml
    try:
        from scheduler.wp_sync import sync_from_wp
        sync_from_wp()
    except Exception:
        pass
    cfg = get_config()
    date = date or datetime.date.today()
    if isinstance(date, str):
        date = datetime.date.fromisoformat(date)
    count = max(1, min(int(count if count is not None else cfg.get("daily.articles_per_day", 3)), 10))
    rng = rng or random.Random()

    cols = _day_columns(date)
    if not cols:
        logger.info(f"daily queue: no enabled column for {date} (non-trading day or all disabled)")
        return []

    weights, _ = _rotation_weights(cfg)
    picked = pick_columns(count, weights, rng)
    publish_enabled = bool(cfg.get("publish.enabled", True))

    tasks = []
    seq: Dict[str, int] = {}
    now = db.now_iso()
    for order, col in enumerate(picked):
        seq[col] = seq.get(col, 0) + 1
        task_id = f"{date:%Y%m%d}-{col}-{seq[col]:03d}"
        publish_date = _random_publish_time(date, rng, cfg)
        # 备用选题池按计划取题（取后标 used），池子不足则退回提供方/空；
        # stock 复盘不走池子（翁老规则：不能有备选题目），topic 用日期占位，副标题正文后取。
        pool_topic = ""
        if col != "stock":
            try:
                from scheduler.pool import take_from_pool
                taken = take_from_pool(col, 1)
                pool_topic = taken[0] if taken else ""
            except Exception:
                pass
        task = {
            "task_id": task_id,
            "column": col,
            "topic": (f"{date:%Y-%m-%d} A股每日复盘") if col == "stock" else (pool_topic or _topic_for(col, date)),
            "final_title": "",
            "content_html": "",
            "excerpt": "",
            "meta_description": "",
            "tags": [],
            "category": cfg.get(f"columns.{col}.category", ""),
            "featured_image": "",
            "status": "queued",                      # WP 端最终状态由 publish_mode 决定
            "publish_mode": "draft" if not publish_enabled else "publish",
            "publish_date": publish_date,
            "source": {"model": cfg.get(f"models.mapping.{col}", ""), "prompt_version": "v1.0"},
        }
        db.execute(
            """INSERT OR REPLACE INTO tasks
               (task_id, column_name, topic, status, model, publish_date, created_at, updated_at, sort_order)
               VALUES (?, ?, ?, 'queued', ?, ?, ?, ?, ?)""",
            (task_id, col, task["topic"], task["source"]["model"], publish_date, now, now, order),
        )
        tasks.append(task)
        logger.info(f"daily queue task created {task_id} col={col} publish={task['publish_mode']} "
                    f"publish_date={publish_date}", task_id=task_id)

    logger.info(f"daily queue built date={date} total={len(tasks)} publish_enabled={publish_enabled}")
    return tasks


def list_tasks_by_date(date: datetime.date) -> List[dict]:
    if isinstance(date, str):
        date = datetime.date.fromisoformat(date)
    # 按人工调整后的排队顺序（sort_order）展示，其次 task_id 兜底
    return db.query("SELECT * FROM tasks WHERE task_id LIKE ? ORDER BY sort_order, task_id",
                    (f"{date:%Y%m%d}-%",))


def clear_today_tasks(statuses: tuple = ("queued", "skipped", "failed", "ready", "published")) -> int:
    """清空今日计划任务（翁老：清空=全部清掉，含已发布记录；WP 文章本身不动）。
    仅保留 generating（正在生成的）。返回删除条数。"""
    prefix = f"{datetime.date.today():%Y%m%d}-%"
    ph = ",".join("?" * len(statuses))
    rows = db.query(f"SELECT task_id FROM tasks WHERE task_id LIKE ? AND status IN ({ph})",
                    (prefix,) + tuple(statuses))
    n = 0
    for r in rows:
        db.execute("DELETE FROM tasks WHERE task_id=?", (r["task_id"],))
        n += 1
    logger.info(f"clear_today_tasks deleted={n} statuses={statuses}")
    return n


def get_task(task_id: str) -> Optional[dict]:
    return db.query_one("SELECT * FROM tasks WHERE task_id=?", (task_id,))


# ---------------------------------------------------------------------------
# 到期发布（--publish-due 入口）
# ---------------------------------------------------------------------------

def publish_due_tasks(now: Optional[datetime.datetime] = None) -> List[dict]:
    """发布到期任务：status=ready 且 publish_date <= now。

    - 走 REST 主通道（wp_rest.publish），REST 失败时自动切 XML-RPC 兜底
      （wp_xmlrpc.publish，接口同签名）
    - 成功后：状态 ready -> published、写指纹表、记账 quota_daily.articles_published
    - 发布开关 off 的任务 publish_mode=draft，WP 端存草稿
    """
    from scheduler.calendar import is_trading_day
    from publishers import wp_rest, wp_xmlrpc
    from core import fingerprint as fp_mod

    now = now or datetime.datetime.now()
    rows = db.query(
        "SELECT * FROM tasks WHERE status='ready' AND publish_date IS NOT NULL AND publish_date <= ?",
        (now.isoformat(timespec="seconds"),),
    )
    results = []
    for row in rows:
        task_id = row["task_id"]
        ok, allowed = check_article_quota_wrapper()
        if not ok:
            logger.warning(f"publish skipped {task_id}: daily article quota exceeded "
                           f"(remaining={allowed})", task_id=task_id)
            results.append({"task_id": task_id, "ok": False, "error": "daily article quota exceeded"})
            continue

        payload = {
            "task_id": task_id,
            "column": row["column_name"],
            "topic": row["topic"],
            "final_title": row["title"] or row["topic"],
            "content_html": row["content"],
            "excerpt": row["excerpt"],
            "meta_description": "",
            "tags": [],
            "category": get_config().get(f"columns.{row['column_name']}.category", ""),
            "featured_image": "",
            "status": "draft" if _publish_mode_of(row) == "draft" else _wp_post_status(row),
            "publish_date": row["publish_date"],
            "source": {"model": row["model"], "prompt_version": "v1.0"},
        }
        try:
            result = wp_rest.publish(payload)
        except wp_rest.PublishError as e:
            if e.retryable:
                logger.warning(f"REST publish failed retryable {task_id}: {e} -> fallback XML-RPC",
                               task_id=task_id)
                try:
                    result = wp_xmlrpc.publish(payload)
                except Exception as xe:
                    transition(task_id, "failed", error=f"xmlrpc fallback failed: {xe}")
                    results.append({"task_id": task_id, "ok": False, "error": str(xe)})
                    continue
            else:
                transition(task_id, "failed", error=str(e))
                results.append({"task_id": task_id, "ok": False, "error": str(e)})
                continue
        except Exception as e:
            transition(task_id, "failed", error=str(e))
            results.append({"task_id": task_id, "ok": False, "error": str(e)})
            continue

        post_id = result.get("post_id")
        published_at = db.now_iso()
        transition(task_id, "published")
        db.execute("UPDATE tasks SET published_at=?, error=NULL WHERE task_id=?",
                   (published_at, task_id))
        # 指纹入库（防重复体系）
        if row.get("content"):
            try:
                fh = fp_mod.fingerprint_hex(row["content"])
                db.execute(
                    "INSERT OR IGNORE INTO fingerprints (fhash, task_id, title, column_name, created_at) "
                    "VALUES (?, ?, ?, ?, ?)",
                    (fh, task_id, row.get("title"), row["column_name"], published_at),
                )
            except Exception as e:
                logger.warning(f"fingerprint store failed {task_id}: {e}", task_id=task_id)
        add_article_published_wrapper()
        logger.info(f"published {task_id} post_id={post_id}")
        results.append({"task_id": task_id, "ok": True, "post_id": post_id})
    return results


def check_article_quota_wrapper():
    from core import risk
    return risk.check_article_quota()


def add_article_published_wrapper():
    from core import risk
    risk.add_article_published()


def _publish_mode_of(row: dict) -> str:
    """任务发布模式：发布开关 off 时任务以 draft 落库（在 created 时未存则按当前配置回算）。"""
    cfg = get_config()
    return "draft" if not bool(cfg.get("publish.enabled", True)) else "publish"


def _wp_post_status(row: dict) -> str:
    """WP post_status：publish_date 在未来 -> future（定时发布），否则 publish。"""
    cfg = get_config()
    pd = row.get("publish_date")
    if pd:
        try:
            if datetime.datetime.fromisoformat(pd) > datetime.datetime.now():
                return "future"
        except ValueError:
            pass
    return "publish"


# ---------------------------------------------------------------------------
# 预选题（--topics：Step1 选题智能体生成备选列表，任务保持 queued）
# 人工干预窗口：生成候选后可在 WP 后台查看/调整/删除/排序；
# 到点 --run 时若仍无人指定，自动采用候选第 1 条继续（总纲 Step1）。
# ---------------------------------------------------------------------------

def _collect_material(column: str, cfg, date: Optional[str] = None) -> dict:
    """采集素材（与 pipeline._collect_real 同逻辑，独立实现避免循环依赖）。
    所有采集器异常必须兜底，返回空 dict 不中断。date 为复盘目标日期（历史复盘用 baostock）。
    column 支持中文分类名（如“股市”），内部解析为流水线 code。
    """
    from agents.base import resolve_column
    column = resolve_column(column)
    try:
        if column == "stock":
            from collectors.market import MarketCollector
            return MarketCollector(cfg).collect(date=date) or {}
        if column == "industry":
            from collectors.industry import IndustryCollector
            return IndustryCollector(cfg).collect() or {}
        if column == "tech":
            from collectors.tech_topics import TechTopicCollector
            return TechTopicCollector(cfg).collect() or {}
        if column == "reading":
            from collectors.reading import ReadingCollector
            poems = ReadingCollector(cfg).collect(n=3) or []
            return {"poems": poems}
        if column == "book":
            from collectors.books import BooksCollector
            b = BooksCollector(cfg).collect()
            return {"book": b} if b else {}
    except Exception as e:
        logger.warning(f"collect material failed column={column} err={e}")
    return {}


def _review_date_of(topic: str) -> Optional[datetime.date]:
    """从复盘标题/选题中提取目标复盘日期（YYYY年M月D日 / YYYY-MM-DD / M月D日）。
    提取不到返回 None（视为当日）。"""
    m = re.search(r"(\d{4})\s*[年\-/]\s*(\d{1,2})\s*[月\-/]\s*(\d{1,2})\s*日?", str(topic or ""))
    if m:
        try:
            return datetime.date(int(m.group(1)), int(m.group(2)), int(m.group(3)))
        except ValueError:
            return None
    return None


def run_topic_selection(column: Optional[str] = None) -> List[dict]:
    """预选题：对 queued 任务执行 Step1（仅生成选题候选 + 定选题），任务保持 queued。

    - 已有选题（人工指定/此前生成）的任务跳过，不重复消耗 Token
    - 采集素材优先（真实采集器），失败兜底为无素材
    - 候选落库 topic_candidates，选题取候选第 1 条写入 topic（人工可改）
    """
    import json as _json
    from agents.topic import TopicAgent

    try:
        from scheduler.wp_sync import sync_from_wp
        sync_from_wp()
    except Exception:
        pass
    cfg = get_config()
    if not bool(cfg.get("ai.enabled", True)):
        logger.warning("run_topic_selection: AI 写文总开关关闭，跳过")
        return []
    rows = db.query("SELECT * FROM tasks WHERE status='queued' ORDER BY sort_order, task_id")
    if column:
        rows = [r for r in rows if r["column_name"] == column]
    # 复盘栏目不预选题（标题=日期+副标题，副标题正文后取，不能有备选）
    rows = [r for r in rows if r["column_name"] != "stock"]
    if not rows:
        return []

    agent = TopicAgent(cfg.raw if hasattr(cfg, "raw") else cfg, core=None, dry_run=False)
    results = []
    for row in rows:
        task_id, col = row["task_id"], row["column_name"]
        if row.get("topic"):
            results.append({"task_id": task_id, "ok": True, "skipped": "已有选题，跳过"})
            continue
        material = _collect_material(col, cfg)
        try:
            res = agent.generate(col, material)
            cands = res["candidates"]
            if not cands:
                raise ValueError("候选为空")
            pick = str(cands[0]["topic"])[:2000]
            db.execute(
                "UPDATE tasks SET topic=?, topic_candidates=?, error=NULL, updated_at=? WHERE task_id=?",
                (pick, _json.dumps(cands, ensure_ascii=False), db.now_iso(), task_id),
            )
            # 其余候选自动入备用选题池（按计划排队，供后续任务取用）
            pool_added = 0
            try:
                from scheduler.pool import add_to_pool
                for c in cands[1:]:
                    t = str(c.get("topic") or "").strip()
                    if t:
                        r = add_to_pool(col, t, source="ai")
                        if r.get("ok"):
                            pool_added += 1
            except Exception as e:
                logger.warning(f"pool fill skipped {task_id}: {e}", task_id=task_id)
            logger.info(f"topic selected {task_id} col={col} cands={len(cands)} pool_added={pool_added}", task_id=task_id)
            results.append({"task_id": task_id, "ok": True, "topic": pick, "candidates": len(cands), "pool_added": pool_added})
        except Exception as e:
            logger.error(f"topic selection failed {task_id}: {e}", task_id=task_id)
            results.append({"task_id": task_id, "ok": False, "error": str(e)[:300]})
    return results


def reorder_tasks(task_ids: List[str]) -> List[dict]:
    """调整排队顺序：按给定 task_id 顺序重写 sort_order（仅 queued 任务）。"""
    order = 0
    updated = []
    for tid in task_ids:
        row = db.query_one("SELECT status FROM tasks WHERE task_id=?", (tid,))
        if not row or row["status"] != "queued":
            continue
        db.execute("UPDATE tasks SET sort_order=?, updated_at=? WHERE task_id=?",
                   (order, db.now_iso(), tid))
        updated.append({"task_id": tid, "sort_order": order})
        order += 1
    return updated


# ---------------------------------------------------------------------------
# 执行队列（--run 主入口：crontab 08:00 生成+执行 / 20:00 复盘专用）
# ---------------------------------------------------------------------------

def run_pending_tasks(column: Optional[str] = None) -> List[dict]:
    """执行 queued 任务：跑 AI 流水线生成内容（Step1-7）→ 状态 ready/failed/skipped。

    - 非交易日自动排除 stock（build 时已排除；此处二次校验兜底）
    - 写文总开关 off 时任务直接跳过（不消耗 Token）
    - 生成完成后由调用方（CLI --run）触发 publish_due_tasks() 发布到点任务
    """
    from agents.pipeline import PipelineAgent
    from scheduler.calendar import is_trading_day

    try:
        from scheduler.wp_sync import sync_from_wp
        sync_from_wp()
    except Exception:
        pass
    cfg = get_config()
    if not bool(cfg.get("ai.enabled", True)):
        logger.warning("run_pending_tasks: AI 写文总开关关闭，全部跳过")
        return [{"task_id": "", "ok": False, "error": "ai.enabled=false 写文总开关关闭"}]

    rows = db.query("SELECT * FROM tasks WHERE status='queued' ORDER BY sort_order, task_id")
    if column:
        rows = [r for r in rows if r["column_name"] == column]
    if not rows:
        return []

    today = datetime.date.today()
    pipe = PipelineAgent(cfg.raw if hasattr(cfg, "raw") else cfg, core=None, dry_run=False)
    results = []
    for row in rows:
        task_id, col = row["task_id"], row["column_name"]
        if col == "stock" and not is_trading_day(today):
            db.execute("UPDATE tasks SET status='skipped', error=? WHERE task_id=?",
                       ("非交易日跳过", task_id))
            results.append({"task_id": task_id, "ok": False, "status": "skipped", "error": "非交易日"})
            continue
        try:
            # 真实数据注入（尤其 stock 复盘：按复盘目标日期采集，历史复盘用 baostock）
            from agents.base import resolve_column as _resolve_col
            is_stock = _resolve_col(col) == "stock"
            review_date = _review_date_of(row.get("topic") or "") if is_stock else None
            material = _collect_material(col, cfg, date=review_date.isoformat() if review_date else None)
            if row.get("topic"):
                material["topic"] = row["topic"]
            # 复盘数据闸：目标日期数据不可用 → 跳过，不发布旧数据/编数据
            if is_stock and not (material.get("indices") or []):
                db.execute("UPDATE tasks SET status='skipped', error=?, updated_at=? WHERE task_id=?",
                           ("目标日期行情数据不可用（数据源失败），复盘跳过", db.now_iso(), task_id))
                results.append({"task_id": task_id, "ok": False, "status": "skipped",
                                "error": "目标日期行情数据不可用，复盘跳过"})
                continue
            task = pipe.run(col, material=material, task_id=task_id, publish_date=row.get("publish_date"))
            db.upsert_task(task)
            results.append({"task_id": task_id, "ok": task["status"] == "ready",
                            "status": task["status"], "title": task.get("final_title"),
                            "error": task.get("error")})
            logger.info(f"run_pending {task_id} -> {task['status']}", task_id=task_id)
        except Exception as e:
            db.execute("UPDATE tasks SET status='failed', error=?, updated_at=? WHERE task_id=?",
                       (str(e)[:500], db.now_iso(), task_id))
            results.append({"task_id": task_id, "ok": False, "status": "failed", "error": str(e)[:300]})
            logger.error(f"run_pending {task_id} 异常: {e}", task_id=task_id)
    return results


# ---------------------------------------------------------------------------
# 立即完成（后台「立即完成」按钮：指定任务马上生成并发布，不等定时调度）
# ---------------------------------------------------------------------------

def run_task_now(task_id: str) -> dict:
    """立即完成指定任务：执行 AI 流水线（Step1-7）并立即发布。

    - 仅 queued/generating/failed 可执行；已发布/生成中不可重复触发
    - 发布开关 off → 存草稿不推送
    - 返回 {"ok", "post_id"?, "permalink"?, "status"?, "error"?}
    - 任何未预期异常兜底为 {"ok": False, "error": ...}，绝不抛出（翁老：前端不能出现“未知错误”）
    """
    try:
        return _run_task_now_inner(task_id)
    except Exception as e:
        logger.exception("run_task_now crashed task=%s", task_id)
        try:
            db.execute("UPDATE tasks SET status='failed', error=?, updated_at=? WHERE task_id=?",
                       (f"执行异常：{e}", db.now_iso(), task_id))
        except Exception:
            pass
        return {"ok": False, "status": "failed", "error": f"执行异常：{e}"}


def _run_task_now_inner(task_id: str) -> dict:
    """run_task_now 主体（异常由外层兜底）。"""
    from agents.pipeline import PipelineAgent
    from publishers import wp_rest, wp_xmlrpc

    cfg = get_config()
    row = db.query_one("SELECT * FROM tasks WHERE task_id=?", (task_id,))
    if not row:
        return {"ok": False, "error": "任务不存在"}
    if row["status"] not in ("queued", "generating", "failed"):
        return {"ok": False, "error": f"任务状态 {row['status']} 不可立即执行"}
    if not bool(cfg.get("ai.enabled", True)):
        return {"ok": False, "error": "AI 写文总开关关闭"}

    # 立即执行场景强制同步 WP 设置/Key（绕过 5 分钟缓存，后台刚填的 Key 立即生效）
    try:
        from scheduler.wp_sync import sync_from_wp
        sync_from_wp(force=True)
        cfg = get_config()
    except Exception:
        pass

    # 真实数据注入（尤其 stock 复盘：正文必须基于采集的真实大盘数据，AI 不得编造行情）
    from agents.base import resolve_column as _resolve_col
    is_stock = _resolve_col(row["column_name"]) == "stock"
    review_date = _review_date_of(row.get("topic") or "") if is_stock else None
    material = _collect_material(row["column_name"], cfg, date=review_date.isoformat() if review_date else None)
    if row.get("topic"):
        material["topic"] = row["topic"]

    # 复盘数据闸：目标日期行情数据不可用 → 跳过，绝不发布旧数据/编数据
    if is_stock and not (material.get("indices") or []):
        db.execute("UPDATE tasks SET status='skipped', error=?, updated_at=? WHERE task_id=?",
                   ("目标日期行情数据不可用（数据源失败），复盘跳过", db.now_iso(), task_id))
        return {"ok": False, "status": "skipped",
                "error": "目标日期行情数据不可用（数据源失败），复盘跳过"}
    # 数据日期与复盘日期必须一致（历史复盘用历史数据，当日复盘用当日数据）
    if is_stock:
        data_date = str(material.get("date") or "")
        want_date = review_date.isoformat() if review_date else datetime.date.today().isoformat()
        if data_date and data_date != want_date:
            db.execute("UPDATE tasks SET status='skipped', error=?, updated_at=? WHERE task_id=?",
                       (f"数据日期 {data_date} 与复盘日期 {want_date} 不符，复盘跳过", db.now_iso(), task_id))
            return {"ok": False, "status": "skipped",
                    "error": f"数据日期 {data_date} 与复盘日期 {want_date} 不符，复盘跳过"}

    now_iso = datetime.datetime.now().astimezone().isoformat(timespec="seconds")
    pipe = PipelineAgent(cfg.raw if hasattr(cfg, "raw") else cfg, core=None, dry_run=False)
    task = pipe.run(row["column_name"], material=material,
                    task_id=task_id, publish_date=now_iso)
    db.upsert_task(task)
    if task["status"] != "ready":
        return {"ok": False, "status": task["status"], "error": task.get("error") or "生成失败"}

    # 发布（开关 off → 仅存草稿）
    if not bool(cfg.get("publish.enabled", True)):
        return {"ok": True, "status": "draft", "task_id": task_id,
                "note": "发布开关已关闭，生成内容留作草稿（未推送 WP）"}

    payload = {
        "task_id": task_id,
        "column": row["column_name"],
        "topic": task.get("topic") or "",
        "final_title": task.get("final_title") or "",
        "content_html": task.get("content_html") or "",
        "excerpt": task.get("excerpt") or "",
        "meta_description": task.get("meta_description") or "",
        "tags": task.get("tags") or [],
        "category": task.get("category") or cfg.get(f"columns.{row['column_name']}.category", ""),
        "featured_image": task.get("featured_image") or "",
        "status": "publish",
        "publish_date": now_iso,
        "source": task.get("source", {}),
    }
    try:
        result = wp_rest.publish(payload)
    except wp_rest.PublishError as e:
        if not e.retryable:
            transition(task_id, "failed", error=str(e)[:500])
            return {"ok": False, "error": str(e)}
        logger.warning(f"run_task_now REST 失败可重试 {task_id}: {e} → XML-RPC 兜底", task_id=task_id)
        try:
            result = wp_xmlrpc.publish(payload)
        except Exception as xe:
            transition(task_id, "failed", error=f"xmlrpc fallback failed: {xe}")
            return {"ok": False, "error": str(xe)[:300]}
    except Exception as e:
        transition(task_id, "failed", error=str(e)[:500])
        return {"ok": False, "error": str(e)[:300]}

    transition(task_id, "published")
    db.execute("UPDATE tasks SET published_at=?, error=NULL WHERE task_id=?", (db.now_iso(), task_id))
    logger.info(f"run_task_now published {task_id} post_id={result.get('post_id')}", task_id=task_id)
    return {"ok": True, "post_id": result.get("post_id"), "permalink": result.get("permalink", ""),
            "task_id": task_id}


# ---------------------------------------------------------------------------
# CLI（crontab 契约：--generate / --run / --column）
# ---------------------------------------------------------------------------

def main(argv: Optional[List[str]] = None) -> int:
    parser = argparse.ArgumentParser(description="A-Blog 每日任务队列 CLI（crontab 入口）")
    parser.add_argument("--generate", action="store_true", help="生成当日任务清单（栏目轮换/配额/随机时段）")
    parser.add_argument("--topics", action="store_true", help="预选题：对 queued 任务生成备选列表（Step1，任务保持排队待人工确认）")
    parser.add_argument("--pool", action="store_true", help="填充备用选题池（本地素材成题，无需 API Key）")
    parser.add_argument("--pool-count", type=int, default=3, help="每栏目填充条数（默认 3）")
    parser.add_argument("--run", action="store_true", help="执行 queued 任务（AI 流水线→发布到点任务）")
    parser.add_argument("--column", choices=("stock", "tech", "reading", "book"), default=None,
                        help="仅处理某栏目（如 20:00 复盘专用 --column stock --run）")
    parser.add_argument("--build-today", action="store_true", help="兼容别名：等价 --generate")
    parser.add_argument("--publish-due", action="store_true", help="兼容别名：仅发布到期任务（不生成）")
    args = parser.parse_args(argv)

    db.init_db()
    if args.build_today:
        args.generate = True

    if args.publish_due:
        results = publish_due_tasks()
        print(f"publish-due: {sum(1 for r in results if r['ok'])}/{len(results)} published")
        for r in results:
            print(f"  {r.get('task_id')}: ok={r.get('ok')} {r.get('post_id', r.get('error', ''))}")
        return 0

    if args.generate:
        tasks = build_daily_tasks()
        print(f"generate: {len(tasks)} tasks created: {[t['task_id'] for t in tasks]}")

    if args.topics:
        top = run_topic_selection(args.column)
        print(f"topics: {sum(1 for r in top if r['ok'])}/{len(top)} selected")
        for r in top:
            print(f"  {r.get('task_id')}: ok={r.get('ok')} {r.get('topic', r.get('error', r.get('skipped', '')))}")

    if args.pool:
        from scheduler.pool import fill_pool
        cols = [args.column] if args.column else list(enabled_columns())
        total = 0
        for c in cols:
            n = fill_pool(c, get_config(), n=max(1, args.pool_count))
            print(f"pool fill {c}: +{n}")
            total += n
        print(f"pool total added: {total}")

    if args.run:
        if not args.generate and not args.topics and args.column is None:
            # 仅 --run 未带 --generate/--topics：若无 queued 任务则先建当日队列
            pending = db.query("SELECT COUNT(*) AS n FROM tasks WHERE status='queued'")
            if not pending or int(pending[0]["n"]) == 0:
                build_daily_tasks()
        run_results = run_pending_tasks(args.column)
        print(f"run: {sum(1 for r in run_results if r['ok'])}/{len(run_results)} ready")
        for r in run_results:
            print(f"  {r.get('task_id')}: status={r.get('status')} {r.get('title', r.get('error', ''))}")
        pub = publish_due_tasks()
        print(f"publish-due: {sum(1 for r in pub if r['ok'])}/{len(pub)} published")
        for r in pub:
            print(f"  {r.get('task_id')}: ok={r.get('ok')} {r.get('post_id', r.get('error', ''))}")
        return 0

    if not (args.generate or args.topics or args.run or args.publish_due):
        parser.print_help()
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
