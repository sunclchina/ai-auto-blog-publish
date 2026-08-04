# -*- coding: utf-8 -*-
"""
scheduler/pool.py — 备用选题池（翁老需求：一系列备用题目按计划排队，可编辑/调整/重排）

- topic_pool 表：id / column_name / topic / source(ai|manual|local) / status(queued|used|deleted)
  / sort_order / created_at / used_at
- 任务生成（--generate）时按池子排队顺序取题（take_from_pool），取后标 used
- 池子来源：
  a. 预选题 --topics：任务采用候选第 1 条，其余候选自动入池（source=ai）
  b. --pool 批量填充：本地选题器用真实采集素材直接成题（source=local，无需 API Key），
     或调 LLM 生成（有 Key 时 quality 更高）
  c. 人工添加（后台输入，source=manual，系统判断+优化标题）
- 人工添加时校验：黑名单 / 长度 / 与池内重复 / 与已用指纹重复（不消耗 Token 的部分）
"""

from __future__ import annotations

import datetime
import re
from typing import Dict, List, Optional

from core import db, logger

VALID_COLUMNS = ("stock", "tech", "reading", "book", "industry")

# 备选池容量上限（翁老需求：超过 20 条不再自动生成）
POOL_LIMIT = 20


def queued_count(column: Optional[str] = None) -> int:
    """当前排队中的选题数量。"""
    sql = "SELECT COUNT(*) AS n FROM topic_pool WHERE status='queued'"
    params: list = []
    if column:
        sql += " AND column_name=?"
        params.append(column)
    row = db.query_one(sql, tuple(params))
    return int(row["n"]) if row else 0


def now_iso() -> str:
    return db.now_iso()


# ---------------------------------------------------------------------------
# CRUD
# ---------------------------------------------------------------------------

def list_pool(column: Optional[str] = None, include_used: bool = False) -> List[dict]:
    """排队中的备用选题（按 sort_order, id）。"""
    sql = "SELECT * FROM topic_pool WHERE status='queued'"
    params: list = []
    if column:
        sql += " AND column_name=?"
        params.append(column)
    sql += " ORDER BY sort_order, id"
    rows = db.query(sql, tuple(params))
    if include_used:
        used = db.query("SELECT * FROM topic_pool WHERE status='used' ORDER BY used_at DESC LIMIT 50")
        return rows + used
    return rows


def _norm_topic(text: str) -> str:
    """选题归一化（查重用）：去书名号/空格/标点，统一常见繁简异体字。"""
    s = re.sub(r"[《》「」『』\s:：，。、！？!?\-—·]+", "", str(text or ""))
    # 常见繁简/异体归一（语料库繁简混存导致重复选题）
    repl = {
        "別": "别", "國": "国", "學": "学", "說": "说", "讀": "读", "詩": "诗",
        "詞": "词", "書": "书", "雲": "云", "萬": "万", "舊": "旧", "風": "风",
        "歸": "归", "時": "时", "來": "来", "東": "东", "長": "长", "樓": "楼",
        "夢": "梦", "聲": "声", "聽": "听", "聞": "闻", "離": "离", "臺": "台",
    }
    for a, b in repl.items():
        s = s.replace(a, b)
    return s


def add_to_pool(column: str, topic: str, source: str = "manual") -> dict:
    """人工/外部添加。返回 {ok, item?, error?}（调用方已做判断优化）。"""
    column = (column or "").strip().lower()
    topic = (topic or "").strip()
    if column not in VALID_COLUMNS:
        return {"ok": False, "error": f"未知栏目: {column}"}
    if not topic:
        return {"ok": False, "error": "选题不能为空"}
    if len(topic) > 200:
        return {"ok": False, "error": "选题过长（≤200 字）"}
    # 与池内排队题目重复：归一化精确匹配 + 双向包含（防繁简体/书名号差异漏网）
    norm = _norm_topic(topic)
    queued = db.query("SELECT id, topic FROM topic_pool WHERE status='queued' AND column_name=?", (column,))
    for row in queued:
        old_norm = _norm_topic(row["topic"])
        if old_norm == norm or (len(norm) >= 4 and (norm in old_norm or old_norm in norm)):
            return {"ok": False, "error": "池中已有相同/相近选题（归一化判重）"}
    order = db.query_one("SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM topic_pool WHERE status='queued'")
    nxt = int(order["n"]) if order else 0
    db.execute(
        "INSERT INTO topic_pool (column_name, topic, source, status, sort_order, created_at) VALUES (?, ?, ?, 'queued', ?, ?)",
        (column, topic, source, nxt, now_iso()),
    )
    row = db.query_one("SELECT * FROM topic_pool WHERE id=last_insert_rowid()")
    logger.info(f"pool add col={column} src={source} topic={topic[:40]!r}")
    return {"ok": True, "item": row}


def update_pool(pool_id: int, topic: Optional[str] = None, column: Optional[str] = None) -> dict:
    row = db.query_one("SELECT * FROM topic_pool WHERE id=?", (pool_id,))
    if not row or row["status"] == "deleted":
        return {"ok": False, "error": "选题不存在"}
    new_topic = (topic or row["topic"]).strip() if topic is not None else row["topic"]
    new_col = (column or row["column_name"]).strip().lower() if column else row["column_name"]
    if not new_topic:
        return {"ok": False, "error": "选题不能为空"}
    if new_col not in VALID_COLUMNS:
        return {"ok": False, "error": f"未知栏目: {new_col}"}
    dup = db.query_one("SELECT id FROM topic_pool WHERE status='queued' AND id<>? AND topic=?",
                       (pool_id, new_topic))
    if dup:
        return {"ok": False, "error": "池中已有相同选题"}
    db.execute("UPDATE topic_pool SET topic=?, column_name=? WHERE id=?",
               (new_topic, new_col, pool_id))
    return {"ok": True, "item": db.query_one("SELECT * FROM topic_pool WHERE id=?", (pool_id,))}


def delete_pool(pool_id: int) -> dict:
    row = db.query_one("SELECT * FROM topic_pool WHERE id=?", (pool_id,))
    if not row:
        return {"ok": False, "error": "选题不存在"}
    db.execute("UPDATE topic_pool SET status='deleted' WHERE id=?", (pool_id,))
    logger.info(f"pool delete id={pool_id}")
    return {"ok": True, "deleted": pool_id}


def clear_pool() -> int:
    """一键清空：软删全部排队中的备用选题，返回清空条数（已用/已删历史保留）。"""
    rows = db.query("SELECT id FROM topic_pool WHERE status='queued'")
    for r in rows:
        db.execute("UPDATE topic_pool SET status='deleted' WHERE id=?", (r["id"],))
    logger.info(f"pool clear all queued: {len(rows)}")
    return len(rows)


def reorder_pool(ids: List[int]) -> List[dict]:
    """按给定 id 顺序重写 sort_order（仅 queued）。"""
    updated = []
    for order, pid in enumerate(ids):
        row = db.query_one("SELECT status FROM topic_pool WHERE id=?", (pid,))
        if not row or row["status"] != "queued":
            continue
        db.execute("UPDATE topic_pool SET sort_order=? WHERE id=?", (order, pid))
        updated.append({"id": pid, "sort_order": order})
    return updated


# ---------------------------------------------------------------------------
# 取题（任务生成时按计划排队取用）
# ---------------------------------------------------------------------------

def take_from_pool(column: str, count: int) -> List[str]:
    """从池子按排队顺序取 count 条题目（标记 used），不足则返回已有条目。"""
    rows = db.query(
        "SELECT * FROM topic_pool WHERE status='queued' AND column_name=? ORDER BY sort_order, id LIMIT ?",
        (column, int(count)),
    )
    taken = []
    for r in rows:
        db.execute("UPDATE topic_pool SET status='used', used_at=? WHERE id=?", (now_iso(), r["id"]))
        taken.append(r["topic"])
    return taken


# ---------------------------------------------------------------------------
# 本地选题器（无 API Key 也能用真实采集素材直接成题；有 Key 由 LLM 生成更佳）
# ---------------------------------------------------------------------------

def local_generate(column: str, cfg, n: int = 3) -> List[str]:
    """基于真实采集素材生成 n 条备用题目（source=local，素材驱动，非伪造数据）。

    stock 栏目不生成（翁老规则：A股复盘标题=日期+固定格式+内容后取副标题，不能有备选题目）。
    """
    if column == "stock":
        return []
    topics: List[str] = []
    try:
        if column == "tech":
            from collectors.tech_topics import TechTopicCollector
            qs = TechTopicCollector(cfg).collect(n=n) or []
            for q in qs[:n]:
                t = str(q.get("question") or "").strip()
                if t:
                    topics.append(t)
        elif column == "reading":
            from collectors.reading import ReadingCollector
            poems = ReadingCollector(cfg).collect(n=n) or []
            for p in poems[:n]:
                title = str(p.get("title") or "").strip()
                author = str(p.get("author") or "").strip()
                if title:
                    topics.append(f"读《{title}》{author}：原文赏析" if author else f"读《{title}》：原文赏析")
        elif column == "book":
            from collectors.books import BooksCollector
            b = BooksCollector(cfg).collect()
            if b and b.get("title"):
                topics.append(f"读《{b['title']}》：核心书评与阅读感悟")
        elif column == "industry":
            # 行业综述：Tavily 热门行业/概念发现（阶段1即可成题，不做深挖省时）
            from collectors.industry import IndustryCollector
            data = IndustryCollector(cfg).collect(limit=n, deep_top=0) or {}
            for ind in (data.get("hot_industries") or [])[:n]:
                name = str(ind.get("name") or "").strip()
                if not name:
                    continue
                if re.search(r"(行业|概念|板块|产业链)$", name):
                    topics.append(f"{name}：市场前景与景气龙头盘点")
                else:
                    topics.append(f"{name}行业：市场前景与景气龙头盘点")
    except Exception as e:
        logger.warning(f"local_generate failed column={column} err={e}")
    return topics[:n]


def fill_pool(column: str, cfg, n: int = 3, source: str = "local", limit: int = POOL_LIMIT) -> int:
    """批量填充备用选题池，返回新增条数。

    - 池子总量超过上限（默认 20 条排队）不再生成，返回 0
    - 已有相同题目自动去重跳过
    """
    room = limit - queued_count()
    if room <= 0:
        logger.info(f"pool fill skip: queued >= limit({limit})")
        return 0
    added = 0
    topics = local_generate(column, cfg, n=min(n, room))
    for t in topics:
        r = add_to_pool(column, t, source=source)
        if r.get("ok"):
            added += 1
    return added


def plan_from_pool(pool_id: int) -> dict:
    """把池中选题立即列入今日任务计划（创建 queued 任务，池条目标记 used）。"""
    import datetime
    import random
    from scheduler.daily_queue import _random_publish_time, get_config

    row = db.query_one("SELECT * FROM topic_pool WHERE id=?", (pool_id,))
    if not row or row["status"] != "queued":
        return {"ok": False, "error": "选题不存在或已使用"}
    col = row["column_name"]
    # 任务 ID 必须用英文栏目 code（中文分类名如“行业”会破坏 REST 路由正则）
    try:
        from agents.base import resolve_column
        col_code = resolve_column(col)
    except Exception:
        col_code = str(col).strip().lower()
    if not col_code or col_code == "generic":
        col_code = "misc"
    cfg = get_config()
    today = datetime.date.today()

    # 当日该栏目序号 + 1
    prefix = f"{today:%Y%m%d}-{col_code}-"
    exist = db.query("SELECT task_id FROM tasks WHERE task_id LIKE ?", (prefix + "%",))
    task_id = f"{prefix}{len(exist) + 1:03d}"
    publish_date = _random_publish_time(today, random.Random(), cfg)
    order = db.query_one("SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM tasks WHERE status='queued'")

    db.execute(
        """INSERT INTO tasks (task_id, column_name, topic, status, model, publish_date, created_at, updated_at, sort_order)
           VALUES (?, ?, ?, 'queued', ?, ?, ?, ?, ?)""",
        (task_id, col, row["topic"], cfg.get(f"models.mapping.{col}", ""), publish_date,
         db.now_iso(), db.now_iso(), int(order["n"]) if order else 0),
    )
    db.execute("UPDATE topic_pool SET status='used', used_at=? WHERE id=?", (db.now_iso(), pool_id))
    logger.info(f"pool->plan pool_id={pool_id} task={task_id} col={col}")
    task = db.query_one("SELECT * FROM tasks WHERE task_id=?", (task_id,))
    return {"ok": True, "task": task}


def run_from_pool(pool_id: int) -> dict:
    """备用题「立即完成」：列入今日计划并立即生成发布（一步到位，不等定时调度）。

    复用 plan_from_pool（建任务）+ run_task_now（流水线→发布）。
    """
    r = plan_from_pool(pool_id)
    if not r.get("ok"):
        return r
    task_id = r["task"]["task_id"]
    from scheduler.daily_queue import run_task_now
    return run_task_now(task_id)


# ---------------------------------------------------------------------------
# 人工指定选题的判断与优化（后台单个输入框 → 系统判断 + 优化标题）
# ---------------------------------------------------------------------------

def validate_and_optimize(column: str, raw_topic: str, cfg) -> dict:
    """对人工输入选题做判断与标题优化。

    本地判断（0 Token）：黑名单 / 长度 / 池内重复 / 与历史指纹重复
    LLM 优化（需 API Key）：调用 Step2 标题智能体生成优化标题；无 Key 时返回本地清洗版
    返回: {"ok", "topic": 优化后标题, "optimized": bool, "notes": [...]}
    """
    from core import risk as risk_mod
    from core import fingerprint as fp_mod

    raw = (raw_topic or "").strip()
    notes: List[str] = []
    if not raw:
        return {"ok": False, "error": "选题不能为空"}

    # 黑名单
    hit, words = risk_mod.contains_sensitive(raw)
    if hit or risk_mod.is_blacklisted(raw)[0]:
        return {"ok": False, "error": f"选题命中黑名单/敏感词: {', '.join(words) or '黑名单'}"}
    # 长度
    if len(raw) > 200:
        return {"ok": False, "error": "选题过长（≤200 字）"}

    # 指纹重复（与已发布内容比对）
    try:
        if fp_mod.check(raw):
            notes.append("与历史文章内容相似，建议换一个角度")
    except Exception:
        pass

    optimized = False
    final_topic = raw
    # LLM 优化标题（有 Key 时；无 Key 走本地标题规则）
    try:
        from agents.title import TitleAgent
        from agents.base import build_core_adapter
        data = cfg.raw if hasattr(cfg, "raw") else cfg
        agent = TitleAgent(data, core=None, dry_run=False)
        if agent.api_key:
            res = agent.generate(raw, column, raw[:20])
            best = res.get("final_title", "")
            if best and best != raw:
                final_topic = best
                optimized = True
                notes.append("已按 SEO 规则优化标题")
    except Exception as e:
        logger.warning(f"topic optimize skip: {e}")
        notes.append("标题优化暂不可用（未配置模型 Key），已按原文采用")

    # 本地兜底清洗：去首尾标点/折叠空格
    final_topic = " ".join(final_topic.split())
    return {"ok": True, "topic": final_topic, "optimized": optimized, "notes": notes}
