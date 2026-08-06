# -*- coding: utf-8 -*-
"""
backend/main.py — FastAPI 轻量服务入口（总纲 §1.2 / 原则7：固定 127.0.0.1:8080）

职责：
- GET  /healthz               健康检查：ok / 版本 / 模块状态（db、config、熔断器摘要）
- POST /api/run-task          手动触发单任务（body: {column, topic?, material?, dry_run?}）
- POST /api/dry-run           dry_run 演示（不消耗 Token，Mock 标注）
- GET  /api/tasks/{date}      查询某日任务清单
- POST /api/publish-due       手动触发到期发布

运行方式（统一 backend 目录为运行根，绝对导入）：
    cd E:\\my-project\\A-Blog\\backend
    uvicorn main:app --host 127.0.0.1 --port 8080
"""

from __future__ import annotations

import os
import sys
import threading
from contextlib import asynccontextmanager
from typing import Optional

BACKEND_DIR = os.path.dirname(os.path.abspath(__file__))
if BACKEND_DIR not in sys.path:
    sys.path.insert(0, BACKEND_DIR)

import uvicorn  # noqa: E402
from fastapi import FastAPI, HTTPException  # noqa: E402
from pydantic import BaseModel, Field  # noqa: E402

from config import get_config, reload_config  # noqa: E402
from core import db, logger  # noqa: E402
from core.risk import get_breaker  # noqa: E402

APP_VERSION = "1.2.0"

# 服务启动即建表 + 幂等迁移（uvicorn 不走 __main__ 块，必须模块级初始化）
db.init_db()


# ---------------------------------------------------------------------------
# 内置调度（scheduler/auto.py）：常驻自动建队列/生成/发布，免 crontab/计划任务
# ---------------------------------------------------------------------------
_scheduler = None  # type: Optional[AutoScheduler]


@asynccontextmanager
async def lifespan(app: FastAPI):
    global _scheduler
    from scheduler.auto import AutoScheduler

    _scheduler = AutoScheduler()
    _scheduler.start()
    yield
    if _scheduler:
        _scheduler.stop()


app = FastAPI(
    title="A-Blog AI 全自动博客伴生服务",
    description="调度层 + AI 生成层 + WP 发布层（Python 侧），固定监听 127.0.0.1:8080",
    version=APP_VERSION,
    lifespan=lifespan,
)


# ---------------------------------------------------------------------------
# 请求模型
# ---------------------------------------------------------------------------

class RunTaskBody(BaseModel):
    column: str = Field(..., description="stock | tech | reading | book")
    topic: Optional[str] = Field(None, description="可选：显式指定选题")
    material: Optional[dict] = Field(None, description="可选：采集素材 dict")
    dry_run: bool = Field(False, description="dry_run 不调真实模型（Mock 标注）")


class DryRunBody(BaseModel):
    column: str = Field("tech", description="stock | tech | reading | book")
    use_real_collectors: bool = Field(True, description="是否调用真实采集器（网络失败自动兜底）")


# ---------------------------------------------------------------------------
# 工具
# ---------------------------------------------------------------------------

def _module_status() -> dict:
    """各模块状态摘要（healthz 用，密钥脱敏）。"""
    try:
        db.init_db()
        db_ok = True
        db_msg = f"sqlite ok @ {get_config().get('data.db_path', 'data/ablog.db')}"
    except Exception as e:  # pragma: no cover
        db_ok, db_msg = False, str(e)
    breaker = get_breaker()
    img = get_config().get("image", {}) or {}
    return {
        "db": {"ok": db_ok, "detail": db_msg},
        "config": {"provider": get_config().get("models.provider", "self"),
                   "source": get_config().get("models.source", "")},
        "image": {"provider": img.get("provider", ""), "model": img.get("model", ""),
                   "configured": bool(img.get("api_key"))},
        "circuit_breakers": breaker.snapshot(),
        "publish_enabled": bool(get_config().get("publish.enabled", True)),
        "articles_per_day": get_config().get("daily.articles_per_day", 3),
    }


def _run_pipeline(column: str, topic: Optional[str], material: Optional[dict],
                  dry_run: bool) -> dict:
    """执行 AI 生成流水线（Step1-7）。"""
    from agents.pipeline import PipelineAgent
    cfg = get_config()
    pipe = PipelineAgent(cfg.raw if hasattr(cfg, "raw") else cfg, core=None, dry_run=dry_run)
    task = pipe.run(column, material=material, topic=topic)
    # 落库（tasks 表）
    try:
        db.upsert_task(task)
    except Exception as e:
        logger.warning(f"task persist failed {task.get('task_id')}: {e}")
    return task


# ---------------------------------------------------------------------------
# 路由
# ---------------------------------------------------------------------------

@app.get("/healthz")
def healthz() -> dict:
    return {
        "ok": True,
        "version": APP_VERSION,
        "service": "A-Blog backend",
        "modules": _module_status(),
    }


@app.post("/api/run-task")
def run_task(body: RunTaskBody) -> dict:
    """手动触发单任务。dry_run=true 时不消耗 Token。"""
    column = (body.column or "").strip().lower()
    if column not in ("stock", "tech", "reading", "book", "industry"):
        raise HTTPException(status_code=400, detail=f"未知栏目: {column}")
    logger.info(f"run-task called column={column} topic={body.topic!r} dry_run={body.dry_run}")
    task = _run_pipeline(column, body.topic, body.material, bool(body.dry_run))
    return {"ok": True, "task": task}


@app.post("/api/dry-run")
def dry_run(body: DryRunBody) -> dict:
    """dry_run 演示：真实采集器 + Mock 流水线，全程不消耗 Token。"""
    column = (body.column or "").strip().lower()
    if column not in ("stock", "tech", "reading", "book", "industry"):
        raise HTTPException(status_code=400, detail=f"未知栏目: {column}")
    from agents.pipeline import PipelineAgent
    cfg = get_config()
    pipe = PipelineAgent(cfg.raw if hasattr(cfg, "raw") else cfg, core=None, dry_run=True)
    task = pipe.demo_run(column, use_real_collectors=bool(body.use_real_collectors))
    return {"ok": True, "dry_run": True, "task": task}


@app.get("/api/tasks/{task_id}")
def task_status(task_id: str) -> dict:
    """查询单个任务状态（异步生成后前端轮询用）。"""
    from scheduler.daily_queue import get_task
    row = get_task(task_id)
    if not row:
        raise HTTPException(status_code=404, detail="任务不存在")
    return {"ok": True, "task": dict(row)}



@app.get("/api/tasks/{date}")
def tasks_of_day(date: str) -> dict:
    """查询某日任务清单（date=YYYY-MM-DD）。"""
    import datetime
    try:
        d = datetime.date.fromisoformat(date)
    except ValueError:
        raise HTTPException(status_code=400, detail="日期格式应为 YYYY-MM-DD")
    from scheduler.daily_queue import list_tasks_by_date
    return {"ok": True, "date": date, "tasks": list_tasks_by_date(d)}


# ---------------------------------------------------------------------------
# 智能选题中心（总纲 Step1：备选列表可查看 / 指定 / 调整 / 删除）
# ---------------------------------------------------------------------------

class TopicPickBody(BaseModel):
    topic: Optional[str] = Field(None, description="人工指定选题（优先）")
    index: Optional[int] = Field(None, description="采用第几个候选（1 起）")


class ReorderBody(BaseModel):
    task_ids: list = Field(..., description="按新顺序排列的任务 ID 数组")


@app.post("/api/topics/reorder")
def topics_reorder(body: ReorderBody) -> dict:
    """调整排队顺序：按 body.task_ids 顺序重写 sort_order（仅 queued 任务）。"""
    from scheduler.daily_queue import reorder_tasks
    updated = reorder_tasks([str(t) for t in body.task_ids])
    return {"ok": True, "updated": updated, "count": len(updated)}


def _task_view(row: dict) -> dict:
    """任务行 → 对外视图（候选 JSON 反序列化）。"""
    import json as _json
    view = dict(row)
    try:
        raw = row.get("topic_candidates") or ""
        view["topic_candidates"] = _json.loads(raw) if raw else []
    except (ValueError, TypeError):
        view["topic_candidates"] = []
    view.pop("topic_candidates_raw", None)
    return view


@app.get("/api/topics/today")
def topics_today() -> dict:
    """今日任务 + 选题候选列表（含 queued/generating/ready 各状态，供人工干预）。"""
    import datetime
    from scheduler.daily_queue import list_tasks_by_date
    today = datetime.date.today().isoformat()
    rows = list_tasks_by_date(today)
    tasks = [_task_view(r) for r in rows]
    return {"ok": True, "date": today, "tasks": tasks, "count": len(tasks)}


@app.post("/api/topics/{task_id}")
def topics_pick(task_id: str, body: TopicPickBody) -> dict:
    """人工指定 / 调整选题：body.topic 直接指定，或 body.index 采用第 N 个候选（1 起）。"""
    import json as _json
    from scheduler.daily_queue import get_task
    row = get_task(task_id)
    if not row:
        raise HTTPException(status_code=404, detail=f"任务不存在: {task_id}")
    if row["status"] not in ("queued", "generating"):
        raise HTTPException(status_code=409, detail=f"任务状态 {row['status']} 不可改选题（仅 queued/generating）")

    new_topic = None
    optimized = False
    notes = []
    if body.topic and str(body.topic).strip():
        # 人工指定：系统判断（黑名单/长度/查重）+ 优化标题（有 Key 时）
        from scheduler.pool import validate_and_optimize
        try:
            from scheduler.wp_sync import sync_from_wp
            sync_from_wp(force=True)   # 强制同步，确保用到最新 Key
        except Exception:
            pass
        v = validate_and_optimize(row["column_name"], str(body.topic).strip(), get_config())
        if not v.get("ok"):
            raise HTTPException(status_code=400, detail=v.get("error", "选题无效"))
        new_topic = v["topic"]
        optimized = bool(v.get("optimized"))
        notes = v.get("notes", [])
    elif body.index is not None:
        try:
            cands = _json.loads(row.get("topic_candidates") or "[]")
        except (ValueError, TypeError):
            cands = []
        if body.index < 1 or body.index > len(cands):
            raise HTTPException(status_code=400, detail=f"候选序号越界（共 {len(cands)} 个）")
        new_topic = str(cands[body.index - 1].get("topic") or cands[body.index - 1])
    else:
        raise HTTPException(status_code=400, detail="需提供 topic 或 index")

    db.execute(
        "UPDATE tasks SET topic=?, error=NULL, updated_at=? WHERE task_id=?",
        (new_topic[:2000], db.now_iso(), task_id),
    )
    logger.info(f"topics_pick {task_id} -> {new_topic[:50]!r} optimized={optimized}", task_id=task_id)
    updated = get_task(task_id)
    return {"ok": True, "optimized": optimized, "notes": notes, "original": body.topic,
            "task": _task_view(updated) if updated else None}


@app.delete("/api/topics/{task_id}")
def topics_delete(task_id: str) -> dict:
    """删除任务（仅 queued/skipped 可删；已生成内容的用跳过）。"""
    from scheduler.daily_queue import get_task
    row = get_task(task_id)
    if not row:
        raise HTTPException(status_code=404, detail=f"任务不存在: {task_id}")
    if row["status"] not in ("queued", "skipped"):
        raise HTTPException(status_code=409, detail=f"任务状态 {row['status']} 不可删除（仅 queued/skipped）")
    db.execute("DELETE FROM tasks WHERE task_id=?", (task_id,))
    logger.info(f"topics_delete {task_id}", task_id=task_id)
    return {"ok": True, "deleted": task_id}


@app.post("/api/tasks/{task_id}/run")
def task_run_now(task_id: str) -> dict:
    """立即完成指定任务：**异步**（后台线程执行生成+发布，接口秒回）。

    长任务（复盘/行业综述等 1-5 分钟）不能同步阻塞（PHP/FastCGI 请求有时限），
    改为后台线程 + 前端轮询任务状态。
    """
    import threading
    from scheduler.daily_queue import run_task_now

    def _worker():
        try:
            run_task_now(task_id)
        except Exception:
            logger.exception("async run_task_now failed task=%s", task_id)

    threading.Thread(target=_worker, daemon=True).start()
    return {"ok": True, "async": True, "task_id": task_id}



@app.post("/api/tasks/clear")
def tasks_clear() -> dict:
    """清空今日计划任务（默认清 queued/skipped，保留已生成/已发布）。"""
    from scheduler.daily_queue import clear_today_tasks
    n = clear_today_tasks()
    return {"ok": True, "deleted": n}


# ---------------------------------------------------------------------------
# 备用选题池（topic_pool：系列备用题目按计划排队，可编辑/删除/重排）
# ---------------------------------------------------------------------------

class PoolAddBody(BaseModel):
    column: str = Field(..., description="stock | tech | reading | book")
    topic: str = Field(..., description="选题（系统判断并优化标题）")


class PoolEditBody(BaseModel):
    topic: Optional[str] = Field(None, description="新题目")
    column: Optional[str] = Field(None, description="新栏目")


@app.get("/api/pool")
def pool_list() -> dict:
    """备用选题池：排队中（按 sort_order）+ 最近已用。"""
    from scheduler.pool import list_pool
    rows = list_pool()
    used = db.query("SELECT * FROM topic_pool WHERE status='used' ORDER BY used_at DESC LIMIT 20")
    return {"ok": True, "count": len(rows), "topics": rows, "recent_used": used}


@app.post("/api/pool")
def pool_add(body: PoolAddBody) -> dict:
    """人工添加备用选题：系统判断（黑名单/长度/重复）+ 优化标题。"""
    from scheduler.pool import add_to_pool, validate_and_optimize
    cfg = get_config()
    v = validate_and_optimize(body.column, body.topic, cfg)
    if not v.get("ok"):
        raise HTTPException(status_code=400, detail=v.get("error", "选题无效"))
    r = add_to_pool(body.column, v["topic"], source="manual")
    if not r.get("ok"):
        raise HTTPException(status_code=400, detail=r.get("error", "添加失败"))
    return {"ok": True, "item": r["item"], "optimized": v.get("optimized", False),
            "notes": v.get("notes", []), "original": body.topic}


@app.put("/api/pool/{pool_id}")
def pool_edit(pool_id: int, body: PoolEditBody) -> dict:
    """编辑池中选题。"""
    from scheduler.pool import update_pool
    r = update_pool(pool_id, topic=body.topic, column=body.column)
    if not r.get("ok"):
        raise HTTPException(status_code=400, detail=r.get("error", "编辑失败"))
    return {"ok": True, "item": r["item"]}


@app.delete("/api/pool/{pool_id}")
def pool_delete(pool_id: int) -> dict:
    """删除池中选题（软删）。"""
    from scheduler.pool import delete_pool
    r = delete_pool(pool_id)
    if not r.get("ok"):
        raise HTTPException(status_code=404, detail=r.get("error", "不存在"))
    return r


@app.post("/api/pool/{pool_id}/plan")
def pool_plan(pool_id: int) -> dict:
    """指定立即列入计划：把池中选题创建为今日任务（排队）。"""
    from scheduler.pool import plan_from_pool
    r = plan_from_pool(pool_id)
    if not r.get("ok"):
        raise HTTPException(status_code=400, detail=r.get("error", "列入计划失败"))
    return r


@app.post("/api/pool/{pool_id}/run")
def pool_run_now(pool_id: int) -> dict:
    """备用题「立即完成」：列入计划并马上生成发布（一步到位）。"""
    from scheduler.pool import run_from_pool
    r = run_from_pool(pool_id)
    if not r.get("ok"):
        raise HTTPException(status_code=400, detail=r.get("error", "执行失败"))
    return r


class PoolReorderBody(BaseModel):
    ids: list = Field(..., description="按新顺序排列的选题 id 数组")


@app.post("/api/pool/reorder")
def pool_reorder(body: PoolReorderBody) -> dict:
    """备用选题重新排队。"""
    from scheduler.pool import reorder_pool
    updated = reorder_pool([int(i) for i in body.ids])
    return {"ok": True, "updated": updated, "count": len(updated)}


@app.post("/api/pool/clear")
def pool_clear() -> dict:
    """一键清空备用选题池（软删全部排队中的选题，保留已用历史）。"""
    from scheduler.pool import clear_pool
    n = clear_pool()
    return {"ok": True, "cleared": n}


@app.post("/api/pool/fill")
def pool_fill(column: Optional[str] = None, n: Optional[int] = None) -> dict:
    """本地素材生成备用题入池（无 API Key 可用）。body 或 query: column, n。
    n 缺省取 config batch.fill_size（默认 1，拆小批量）。"""
    from scheduler.pool import fill_pool
    from scheduler.daily_queue import enabled_columns
    if column and column not in ("stock", "tech", "reading", "book", "industry"):
        raise HTTPException(status_code=400, detail=f"未知栏目: {column}")
    cfg = get_config()
    if n is None:
        n = int(cfg.get("batch.fill_size", 1))
    n = max(1, min(int(n), 10))
    cols = [column] if column else enabled_columns()
    total = 0
    for c in cols:
        total += fill_pool(c, cfg, n=n)
    return {"ok": True, "added": total}


@app.post("/api/publish-due")
def publish_due() -> dict:
    """手动触发到期发布（status=ready 且到点）。"""
    from scheduler.daily_queue import publish_due_tasks
    results = publish_due_tasks()
    return {"ok": True, "published": sum(1 for r in results if r.get("ok")),
            "total": len(results), "results": results}


@app.post("/api/reload-config")
def reload_cfg() -> dict:
    """配置热重载（改 config.yaml 后调用）。"""
    reload_config()
    return {"ok": True, "config": get_config().to_public_dict()}


# ---------------------------------------------------------------------------
# AI 宸ュ叿绠憋細鏂囩珷灏侀潰 AI 鐢熷浘锛堝紓姝ヤ换鍔★細鐢熷浘 鈫? 涓婁紶 WP 璁惧皝闈紝POST /api/toolbox/cover 绉掑洖锛屽墠绔疆璇?GET 鐘舵€侊級
# ---------------------------------------------------------------------------

class CoverBody(BaseModel):
    """AI 閰嶅浘璇锋眰浣擄細post_id 蹇呭～锛宼itle/column/content 鐢?WP 浼犲叆鐢ㄤ簬鐢熸垚鎻愮ず璇嶃€?"""
    post_id: int
    title: str = ""
    column: str = ""
    content: str = ""  # 鏂囩珷姝ｆ枃/鎽樿瑕佺偣锛堢敤浜庡皝闈㈡彮绀鸿瘝鍒囧悎鍐呭锛夈€?"""


_cover_jobs: dict = {}
_cover_jobs_lock = threading.Lock()
_COVER_JOB_TTL = 3600  # 浠诲姟缁撴灉淇濈暀 1 灏忔椂


def _cover_job_create(post_id: int, title: str, column: str, content: str = "") -> str:
    import uuid
    import time as _t
    job_id = uuid.uuid4().hex[:12]
    now = _t.time()
    with _cover_jobs_lock:
        stale = [k for k, v in _cover_jobs.items() if now - v.get("ts", 0) > _COVER_JOB_TTL]
        for k in stale:
            _cover_jobs.pop(k, None)
        _cover_jobs[job_id] = {
            "status": "running", "post_id": int(post_id), "title": title,
            "column": column, "content": str(content or ""), "message": "", "ts": now,
        }
    return job_id


def _cover_job_set(job_id: str, status: str, message: str = "") -> None:
    with _cover_jobs_lock:
        if job_id in _cover_jobs:
            _cover_jobs[job_id]["status"] = status
            _cover_jobs[job_id]["message"] = message


def _cover_job_run(job_id: str) -> None:
    """后台线程：AI 生图 鈫? 浣撴枃浠?/data URI 鈫? WP /toolbox/cover 璁剧疆灏侀潰銆?"""
    import base64
    import time as _t
    with _cover_jobs_lock:
        job = _cover_jobs.get(job_id)
        if not job:
            return
        post_id, title, column = job["post_id"], job["title"], job["column"]
        content_hint = str(job.get("content") or "")
    started = _t.time()
    try:
        from agents.image import ImageAgent
        cfg = get_config()
        cfg_dict = cfg.raw if hasattr(cfg, "raw") else cfg
        agent = ImageAgent(cfg_dict, core=None, dry_run=False)
        if getattr(agent.provider, "name", "") == "noop":
            _cover_job_set(job_id, "failed",
                           "未配置生图服务：请在后台「AI 自动博客 → 图片 API 配置」填写 Provider=dashscope 与百炼 API Key（或后端 config.yaml image 段），保存后约 5 分钟内自动同步生效")
            return
        topic = title or "通用文章封面"
        result = agent.generate_image(column, topic, task_id=f"toolbox-{job_id}", final_title=title,
                                      content_hint=content_hint)
        local_path = result.get("local_path") if isinstance(result, dict) else None
        if not local_path:
            note = result.get("note") if isinstance(result, dict) else ""
            _cover_job_set(job_id, "failed", note or "生图失败（未配置生图服务或服务不可用）")
            return
        with open(local_path, "rb") as f:
            data = f.read()
        mime = "image/png" if str(local_path).lower().endswith(".png") else "image/webp"
        data_uri = f"data:{mime};base64," + base64.b64encode(data).decode()

        from publishers.wp_rest import _config, _endpoint, _request_with_retry
        base, path, token, timeout, retries, backoff = _config()
        if not token:
            _cover_job_set(job_id, "failed", "WP API Token 未配置")
            return
        resp = _request_with_retry(
            "POST", _endpoint("/toolbox/cover"),
            json={"post_id": post_id, "image_url": data_uri},
            token=token, timeout=timeout, retries=retries, backoff=backoff,
            task_id=f"toolbox-{job_id}",
        )
        d = resp.json()
        results = d.get("results") if isinstance(d, dict) else None
        first = results[0] if isinstance(results, list) and results else {}
        if isinstance(d, dict) and d.get("ok") and isinstance(first, dict) and first.get("ok"):
            _cover_job_set(job_id, "done",
                           f"已生成并设置封面（附件 {first.get('attachment_id', '')}，耗时 {int(_t.time() - started)}s）")
        else:
            err = ""
            if isinstance(d, dict):
                err = str(d.get("error") or first.get("error") or "WP 端设置封面失败")
            _cover_job_set(job_id, "failed", err)
    except Exception as e:
        logger.warning("ai-cover job exception job=%s: %s", job_id, e)
        _cover_job_set(job_id, "failed", f"后端异常：{e}")


@app.post("/api/toolbox/cover")
def toolbox_cover(body: CoverBody) -> dict:
    """AI 閰嶅浘锛氬垱寤哄紓姝ョ敓鍥句换鍔★紙绉掑洖 job_id锛夛紝鍚庡彴绾跨▼鐢熸垚骞朵笂浼?WP銆?"""
    import threading as _th
    if not body.post_id or int(body.post_id) <= 0:
        raise HTTPException(status_code=400, detail="post_id 必填")
    job_id = _cover_job_create(int(body.post_id), (body.title or "").strip(),
                               (body.column or "").strip().lower(), (body.content or "").strip())

    def _worker():
        try:
            _cover_job_run(job_id)
        except Exception:
            logger.exception("ai-cover worker failed job=%s", job_id)
            _cover_job_set(job_id, "failed", "后台任务异常，详见后端日志")

    _th.Thread(target=_worker, daemon=True).start()
    return {"ok": True, "job_id": job_id}


@app.post("/api/sync")
def api_sync() -> dict:
    """强制同步 WP 配置（/health 模型 + /settings 开关），AI 配图前调用，确保生图 Key 最新。"""
    from scheduler.wp_sync import sync_from_wp
    try:
        ok = sync_from_wp(force=True)
        return {"ok": bool(ok), "synced": bool(ok)}
    except Exception as e:
        logger.warning("api sync failed: %s", e)
        return {"ok": False, "error": str(e)}


@app.get("/api/toolbox/cover/{job_id}")
def toolbox_cover_status(job_id: str) -> dict:
    """鏌ヨ AI 閰嶅浘浠诲姟鐘舵€侊細running / done / failed銆?"""
    with _cover_jobs_lock:
        job = _cover_jobs.get(job_id)
    if not job:
        raise HTTPException(status_code=404, detail="任务不存在或已过期")
    return {"ok": True, "job_id": job_id, "post_id": job["post_id"],
            "status": job["status"], "message": job["message"]}


if __name__ == "__main__":
    db.init_db()
    uvicorn.run(app, host="127.0.0.1", port=8080)
