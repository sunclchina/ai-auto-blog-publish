# A-Blog 设计文档 · 02 调度层

> 依据：`01-architecture.md` v1.0（冲突时以总纲为准并记录变更）
> 版本：v1.0（2026-08-03）｜模块：`backend/scheduler/`（daily_queue.py / calendar.py / crontab.txt）

---

## 1. 职责与边界

调度层只做三件事：**排程**（今天写什么、几点写）、**调度执行**（按状态机推进任务）、**前置拦截**（写文前把所有不该写的拦住，0 Token 消耗）。不直接调用模型、不直接操作 WP —— 分别交给 agents 层（03）与 publishers 层（04）。

```
crontab ──► daily_queue.run_once() ──► 排程(栏目轮换+时段) → 前置检查(配额风控) → 入队(queued)
                                        └─► 状态机推进: 调 pipeline(03) / publisher(04)
```

## 2. 每日任务队列状态机

状态枚举与总纲 3.3 一致：`queued | generating | humanize | ready | published | failed | skipped`。
中间进度用增量扩展列 `tasks.step`（TEXT）记录流水线当前步骤（对总纲 3.3 的**增量扩展**，additive 不冲突，变更已记录）。

### 2.1 状态语义

| 状态 | 含义 | 终态? |
|------|------|-------|
| queued | 已入队，通过全部前置检查，等待生成 | 否 |
| generating | 流水线 Step1-4（选题/标题/大纲/正文）执行中或已完成 | 否 |
| humanize | 流水线 Step5-6（去AI润色/SEO）执行中或已完成 | 否 |
| ready | 成品就绪（配图完成或降级为纯文字，终稿校验通过），等待发布 | 否* |
| published | WP 返回 post_id，发布成功 | 是 |
| failed | 不可恢复失败（error 字段记录原因），可人工重试 | 是 |
| skipped | 被前置拦截（配额/黑名单/重复/开关/数据源）或发布期跳过 | 是 |

\* 发布开关关闭时 `ready` 即终态（仅生成不推送），任务不进入 published。

### 2.2 状态转移表

| 当前态 | 事件 | 新状态 | 说明 |
|--------|------|--------|------|
| queued | start_generation | generating | 调度器取任务交 pipeline |
| generating | step_fatal（topic/title/outline/content 重试耗尽） | failed | 带 error |
| generating | step_skipped（生成中途发现选题重复/风险） | skipped | 带 reason |
| generating | steps_1_4_done | humanize | 正文落库 |
| humanize | steps_5_6_done（含降级路径） | ready | 降级也进 ready，meta 标记 |
| humanize | step_fatal | failed | 理论不发生（该两步骤只降级不致命） |
| ready | publish_ok（WP 200 + post_id） | published | 落 published_at、post_id |
| ready | publish_failed（重试耗尽） | failed | 带 error |
| ready | publish_duplicate（终稿指纹重复 / WP 409） | skipped | 带 reason=duplicate |
| ready | publish_disabled（abp_enable_publish=off） | ready（终态） | 仅生成不推送 |
| 任意 | manual_requeue | queued | 后台/人工恢复 |

### 2.3 状态更新伪代码

```python
# daily_queue.py
def update_state(task_id: str, to: str, *, error: str = "", reason: str = ""):
    allowed = {("queued","generating"), ("generating","humanize"), ("generating","failed"),
               ("generating","skipped"), ("humanize","ready"), ("ready","published"),
               ("ready","failed"), ("ready","skipped"), (ANY, "queued")}  # 人工重试
    cur = db.get_task(task_id).status
    if (cur, to) not in allowed: log.warning(f"illegal transition {cur}->{to}"); return
    db.update_task(task_id, status=to, error=error, updated_at=now())
```

## 3. 调度主循环

### 3.1 run_once() 伪代码

```python
def run_once():
    today = date.today()
    # 1. 排程：只在 00:05-00:30 窗口构建当日计划（防止跨日重复入队）
    if not db.plan_exists(today):
        plan = build_daily_plan(today)            # 栏目轮换 + 时段排程（见 §4/§6）
        for item in plan:
            if precheck_pass(item):               # 前置检查（§7），全部通过才入队
                db.create_task(item)              # status=queued
            else:
                db.create_task(item, status="skipped", error=item.reason)  # 留痕但不生成
    # 2. 推进生成：把 queued 任务逐个交给 pipeline（串行，单进程）
    for t in db.tasks(status="queued"):
        pipeline.run(t)                            # 03-agents.md §6，内部自己改状态
    # 3. 到点发布：ready 且 publish_at <= now 且发布开关开
    for t in db.tasks(status="ready", due=True):
        publish_with_retry(t)                      # 04-publisher.md §7，成功→published
    # 4. 维护：日志轮转 / 熔断状态过期扫描 / wp_abp_log 留存清理
    housekeeping()
```

### 3.2 crontab.txt（目标 Linux 部署）

```
# 每 5 分钟调度循环（08:00-23:00；复盘 20:00 发布依赖此粒度）
*/5 8-23 * * *  cd /opt/ablog && /usr/bin/python3 backend/main.py --scheduler-run >> data/logs/scheduler.log 2>&1
# 每日 00:10 构建当日计划（提前入队，生成可错峰执行）
10 0 * * *      cd /opt/ablog && /usr/bin/python3 backend/main.py --scheduler-plan >> data/logs/scheduler.log 2>&1
```

发布粒度 5 分钟 → 发布时间取整到 5 分钟即可（模拟人工，不必精确秒）。

## 4. 栏目轮换算法（比例 + 随机 + 平滑修正）

### 4.1 比例定义

| 参数 | 默认 | 含义 |
|------|------|------|
| abp_daily_limit | 3 | 每日发文总量（1-10） |
| abp_ratio_stock / tech / reading | 40/30/30（%） | 三级比例；reading 与 book 共享 30% |
| abp_ratio_book | 50（%） | reading 栏目内部：书评占 50%，国学占 50% |
| abp_weekly_cap_tech / reading | 3 / 3 | 每周篇数上限（对应总纲 1-3 篇） |
| abp_stock_days | trading | 复盘仅在交易日 |

规则：
1. **stock 固定占用**：交易日固定排 1 篇（20:00），不参与随机；非交易日不排。
2. 其余 slot = `daily_limit - 已排 stock 数`，在 tech/reading 间按**加权随机**填充，权重带**周度平滑修正**（实际篇数落后于期望则提高权重，封顶则清零）。
3. 每周每栏目不超 `weekly_cap`，超出则该栏目权重归零。
4. 生成时再按 `abp_ratio_book` 在国学素材与书目库之间二选一（reading vs book 子类型，存 task JSON `column=reading, subtype=classic|book`）。

### 4.2 伪代码

```python
def build_daily_plan(today):
    slots = []
    if calendar.is_trading_day(today) and cfg.abp_enable_stock:
        slots.append(Column("stock", subtype=None, publish_at=parse(cfg.abp_stock_time)))
    week_start = monday_of(today)
    actual = db.count_by_column_since(week_start)          # {tech: n, reading: n}
    for _ in range(max(0, cfg.daily_limit - len(slots))):
        weights = {}
        for col in ("tech", "reading"):
            if actual[col] >= cfg.weekly_cap[col]:
                weights[col] = 0.0; continue
            exp = (cfg.ratio[col] / 100.0) * cfg.daily_limit * 5 / 7   # 周期望（近似）
            weights[col] = max(cfg.ratio[col] / 100.0
                               + (exp - actual[col]) / max(exp, 1.0) * 0.5, 0.05)
        if sum(weights.values()) <= 0: break
        col = weighted_random(weights)
        actual[col] += 1
        subtype = "book" if (col == "reading" and rand(100) < cfg.ratio_book) else "classic"
        slots.append(Column(col, subtype))
    return slots
```

## 5. A股交易日历实现方案（calendar.py）

### 5.1 判定规则（A股特性，务必正确）

- 周末（周六/周日）**永远休市** —— 国务院调休"补班"的周末，沪深交易所也**不**开市，无需处理补班日。
- 工作日若在**休市日表**中 → 休市（元旦/春节/清明/劳动节/端午/中秋/国庆 + 交易所临时休市）。
- `is_trading_day(d) = weekday(d) in (Mon..Fri) and d not in closed_days[d.year]`

### 5.2 数据存储（非硬编码，遵循开发原则2）

休市日表放数据文件 `data/trading_holidays.json`（git 跟踪，可编辑），`calendar.py` 启动时加载并做合法性校验；**部署前必须按国务院办公厅通知与沪深交易所公告核对更新**。文件 schema：

```json
{
  "version": "2026-2027-draft",
  "last_reviewed": "2026-08-03",
  "note": "初稿：农历节日按天文历法推算，实际休市以官方公告为准；部署前必须核对更新",
  "closed_days": {
    "2026": ["2026-01-01","2026-01-02","2026-02-16","2026-02-17","2026-02-18",
             "2026-02-19","2026-02-20","2026-04-06","2026-05-01","2026-05-04",
             "2026-05-05","2026-06-19","2026-09-25","2026-10-01","2026-10-02",
             "2026-10-05","2026-10-06","2026-10-07","2026-10-08"],
    "2027": ["2027-01-01","2027-02-05","2027-02-08","2027-02-09","2027-02-10",
             "2027-02-11","2027-04-05","2027-05-03","2027-05-04","2027-05-05",
             "2027-06-09","2027-09-15","2027-10-01","2027-10-04","2027-10-05",
             "2027-10-06","2027-10-07"]
  }
}
```

> ⚠️ 2026/2027 农历节日推算依据：春节 2026-02-17、2027-02-06（正月初一）；清明 2026-04-05（周日）、2027-04-05（周一）；端午 2026-06-19、2027-06-09；中秋 2026-09-25、2027-09-15。**以上为初稿**，节假日调休范围（如春节 7 天、国庆 8 天）以官方最终安排为准，上线前必须逐条核对。

### 5.3 接口与兜底

```python
def is_trading_day(d: date) -> bool            # 规则见 §5.1
def next_trading_day(d: date) -> date
def last_trading_day(d: date) -> date          # 复盘文引用"上一交易日"数据
# 兜底：休市表加载失败 → 保守模式：仅按周末判定（不误生成），并告警
# 数据源兜底（总纲§4）：复盘任务生成前若 market 数据拉取失败 → 当日任务 skipped(reason=data_source_failed)
```

## 6. 模拟人工时段发布策略

### 6.1 时段配置

| 栏目 | 默认时段 | 说明 |
|------|----------|------|
| stock | 固定 20:00（abp_stock_time 可配） | 交易日，无随机 |
| tech | 09:00-21:00（abp_tech_window） | 随机分钟 |
| reading/book | 07:00-22:00（abp_reading_window） | 随机分钟 |

### 6.2 排程算法

1. 每篇在时段内取随机分钟（粒度 5 分钟）；
2. 按时间升序排序后做**间隔约束**：相邻两篇间隔 ≥ `abp_gap_minutes`（默认 30），冲突的后篇向后平移随机 30-90 分钟，循环至无冲突（最多 5 轮，仍冲突则顺延次日早晨窗口并告警）；
3. 仅 `status=publish` 的任务需要 publish_at；draft/仅生成任务不排程；
4. publish_at 计算在站点时区（经 /health 的 timezone_string + gmt_offset，见 04 §8），随任务 JSON 的 `publish_date` 下发。

## 7. 配额与前置检查顺序（写文前拦截，0 Token）

**硬性要求：以下 10 项全部在调用任何模型之前执行**（est_cost 为启发式预估，不产生真实消耗）。任一不通过 → 任务直接 `skipped` 并记录 reason，绝不入队。

| # | 检查 | 依据 | 命中动作 |
|---|------|------|----------|
| 1 | 总开关 abp_enabled（WP /health 同步缓存，≤5min 过期） | 总纲§4 | skipped(disabled) |
| 2 | 栏目开关 abp_enable_<column> | 总纲§4 | skipped(column_off) |
| 3 | 数据源就绪（stock 需当日行情可用） | 总纲§4 | skipped(data_source_failed) |
| 4 | 选题黑名单（blacklist.keyword/topic 命中 topic） | 总纲§5.3 | skipped(blacklist) |
| 5 | 书目防重复（book 子类型查 written_books） | 总纲§5.2 | skipped(book_duplicate) |
| 6 | 标题相似度前置查重（与近期标题向量距离 < 0.7） | 总纲§5.5 | skipped(title_duplicate) |
| 7 | 每日发文上限：quota_daily.articles_published + 当日已排队 ≥ daily_limit | 总纲§6 | skipped(daily_cap) |
| 8 | 每日 Token 额度：quota_daily.tokens_used + est_cost(task) ≥ daily_tokens | 总纲§6 | skipped(quota) |
| 9 | 熔断状态：任务所用模型 breaker == OPEN | 06 §4 | skipped(breaker_open) |
| 10 | 全部通过 → 入队 queued | — | — |

**est_cost 预估公式**（core/risk.py，系数可配）：

```
est_tokens = (输入素材字符数 × 0.8 + Σ各步骤 max_tokens × 1.1) / 1000
```
素材字符数取 topic + 采集素材截断（≤4000 字）长度；含 6 个文本步骤的 max_tokens 之和（配图不计）。

## 8. 任务对象与 SQLite 落库映射

- 入队时写 `tasks` 表：task_id（`YYYYMMDD-<column>-NNN`）、column_name、topic、status=queued、created_at。
- 状态机每步更新：title/outline/content/excerpt/model/tokens_used/error/updated_at/published_at/post_id（增量列 step、meta）。
- 增量列说明（对总纲 3.3 的 additive 扩展）：`step TEXT`（当前流水线步骤，支持崩溃恢复）、`meta TEXT`（JSON：subtype/humanize_failed/image_failed/source 等）、`post_id INTEGER`（发布后回填）。

## 9. 可测试性（tests/ 单测清单）

- `test_calendar.py`：2026/2027 全年交易日数量、周末永休、春节/国庆闭市、节假日表加载失败兜底。
- `test_rotation.py`：比例收敛（跑 100 天模拟，tech/reading 实际占比落在期望 ±10% 内）、weekly_cap 封顶。
- `test_state_machine.py`：全部合法/非法转移、幂等更新、人工重试回 queued。
- `test_precheck.py`：10 项前置检查逐项命中、**全程 0 次模型调用断言**（mock call_model 计数器）。
- `test_schedule.py`：时段随机、间隔约束、冲突消解、跨日不重复入队。
