# -*- coding: utf-8 -*-
"""
collectors/market.py — A股大盘数据采集（B组：采集层）

数据源优先级（data-source 技能规范）：
  1. 本地通达信日线（主力）：C:\\zd_zxzq_gm\\vipdoc\\{sh,sz}\\lday\\{code}.day，32 字节/条
  2. 新浪实时行情（备用）：hq.sinajs.cn
  3. 东财接口（板块热点/涨跌家数）：失败可留空字段

铁律：所有网络/文件失败必须 try/except 兜底返回 None 字段，绝不抛异常中断。
输出：dict 含 indices / breadth / turnover / sectors / date / source_chain。
"""

import os
import sys
import struct
import logging
import datetime as dt

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

try:
    import httpx
except ImportError:  # pragma: no cover
    httpx = None

logger = logging.getLogger("ablog.collectors.market")

# 通达信日线路径模板与指数清单
DEFAULT_TDX_VIPDOC = r"C:\zd_zxzq_gm\vipdoc"
INDEX_DAYS = {
    "sh000001": {"market": "sh", "name": "上证指数"},
    "sz399001": {"market": "sz", "name": "深证成指"},
    "sz399006": {"market": "sz", "name": "创业板指"},
    "sz399905": {"market": "sz", "name": "中证500"},
}

# 新浪行情代码
SINA_CODES = "sh000001,sz399001,sz399006,sz399905,sz399106"

# 东财 secid：1.000001=上证指数, 0.399001=深证成指, 0.399006=创业板指
EM_INDEX_IDS = "1.000001,0.399001,0.399006"

DAY_RECORD = struct.Struct("<iiiiifif")  # 5×int32 + float32(成交额) + int32(成交量) + float32(持仓量)，实测验证


class MarketCollector:
    """大盘数据采集器。构造：MarketCollector(config)"""

    def __init__(self, config=None):
        cfg = config or {}
        self.tdx_vipdoc = (cfg.get("market", {}) or {}).get("tdx_vipdoc") or DEFAULT_TDX_VIPDOC
        self.timeout = (cfg.get("market", {}) or {}).get("timeout", 10)
        self.sina_codes = (cfg.get("market", {}) or {}).get("sina_codes") or SINA_CODES
        self.core = None  # 采集层不需要 core 依赖（可选注入）
        self.source_chain = []

    # ------------------------------------------------------------------
    def collect(self, date: Optional[str] = None):
        """主入口。任何异常兜底返回空字段 dict，绝不抛出。

        date=None → 当日（新浪实时主源 + 通达信同时间校对）；
        date=历史日期 → baostock 历史日线（该日真实收盘数据）。
        """
        target = dt.date.fromisoformat(date) if date else dt.date.today()
        result = {
            "date": target.isoformat(),
            "indices": [],
            "breadth": None,        # {up, down, flat}
            "turnover": None,       # 两市成交额（亿元）
            "sectors": [],          # 板块热点 [{name, change_pct}]
            "source_chain": [],
            "errors": [],
        }
        # 历史日期 → baostock 历史数据（当日数据源无历史时）
        if target < dt.date.today():
            indices = self._fetch_history_indices(target)
            self._chain("baostock" if indices else "history_failed")
            result["indices"] = indices or []
            result["turnover"] = self._sum_turnover(indices)
            result["source_chain"] = self.source_chain
            return result
        try:
            # 近期走势（近 5 个交易日收盘，技术面/量能对比素材）
            result["recent"] = self._fetch_recent_indices(target, days=5)
            # 数据源策略（翁老规则）：联网新浪为当日主数据源；本地通达信仅作“同时间”校对，
            # 本地数据日期与当天不符时完全忽略（不能拿旧数据当今日、也不能拿旧数据校对）。

            # 1) 主：新浪实时（今日联网数据）
            sina_indices = self._fetch_sina_indices()
            self._chain("sina" if sina_indices else "sina_failed")
            result["indices"] = sina_indices or []
            if not sina_indices:
                # 1b) 新浪失败 → 腾讯财经备用（点位/涨跌幅；成交额口径存疑，不参与 turnover）
                tencent_indices = self._fetch_tencent_indices()
                self._chain("tencent" if tencent_indices else "tencent_failed")
                result["indices"] = tencent_indices or []

            # 2) 校对：通达信本地（仅当最新记录日期 == 最近交易日才用于交叉校对）
            tdx_indices, tdx_ok = self._read_tdx_indices()
            self._chain("tdx" if tdx_ok else "tdx_failed")
            if tdx_ok and self._indices_fresh(tdx_indices):
                # 日期匹配 → 交叉校对（记录偏差，不覆盖联网数据）
                result["cross_check"] = self._cross_check(sina_indices, tdx_indices)
                self._chain("tdx_crosscheck_ok")
            elif tdx_ok:
                # 日期对不上 → 完全忽略本地数据（“时间不对不能乱对”）
                self._chain("tdx_time_mismatch_ignored")

            # 3) 新浪失败时：仅当通达信数据新鲜才回退本地（否则当日数据缺失）
            if not sina_indices and tdx_ok and self._indices_fresh(tdx_indices):
                result["indices"] = tdx_indices
                self._chain("tdx_fallback_used")

            # 4) 涨跌家数 + 板块热点（东财，失败留空）
            result["breadth"] = self._fetch_breadth()
            result["sectors"] = self._fetch_sectors()
            self._chain("eastmoney" if (result["breadth"] or result["sectors"]) else "eastmoney_failed")

            # 成交额汇总（基于当前采用的指数数据）
            result["turnover"] = self._sum_turnover(result["indices"])
            result["source_chain"] = self.source_chain
        except Exception as e:
            logger.warning("market.collect 兜底: %s", e)
            result["errors"].append(str(e))
            result["source_chain"] = self.source_chain
        return result

    def _cross_check(self, sina_indices, tdx_indices):
        """交叉校对：同日期下比较新浪收盘 vs 通达信收盘，记录偏差（仅记录，不覆盖）。"""
        report = []
        tdx_map = {i.get("code"): i for i in tdx_indices if i.get("code")}
        for s in sina_indices or []:
            t = tdx_map.get(s.get("code"))
            if not t or not t.get("close") or not s.get("close"):
                continue
            diff_pct = round((s["close"] - t["close"]) / t["close"] * 100, 3)
            report.append({
                "code": s.get("code"),
                "name": s.get("name"),
                "sina_close": s["close"],
                "tdx_close": t["close"],
                "diff_pct": diff_pct,
                "ok": abs(diff_pct) < 0.5,
            })
        return report

    def _chain(self, tag):
        self.source_chain.append(tag)

    # ------------------------------------------------------------------
    def _fetch_recent_indices(self, target: dt.date, days: int = 5):
        """主要指数近 N 个交易日日线（baostock），供技术面均线/量能对比。

        返回 {"sh000001": [{"date", "close", "amount_yi"}...], ...}（按日期升序，至 target 当日）。
        失败返回空 dict，不影响主流程。
        """
        try:
            import baostock as bs
        except ImportError:
            return {}
        codes = [
            ("sh.000001", "sh000001"),
            ("sz.399001", "sz399001"),
            ("sz.399006", "sz399006"),
        ]
        start = (target - dt.timedelta(days=days * 2)).isoformat()
        end = target.isoformat()
        out = {}
        try:
            lg = bs.login()
            if lg.error_code != "0":
                return {}
            for bs_code, code in codes:
                rs = bs.query_history_k_data_plus(
                    bs_code, "date,close,amount",
                    start_date=start, end_date=end, frequency="d")
                if rs.error_code != "0":
                    continue
                rows = []
                while rs.next():
                    row = rs.get_row_data()
                    try:
                        rows.append({"date": row[0], "close": float(row[1]),
                                     "amount_yi": round(float(row[2]) / 1e8, 1) if row[2] else None})
                    except (ValueError, IndexError):
                        continue
                if rows:
                    out[code] = rows[-days:]
        except Exception as e:
            logger.warning("baostock recent indices failed %s: %s", target, e)
        finally:
            try:
                bs.logout()
            except Exception:
                pass
        return out

    # ------------------------------------------------------------------
    def _fetch_history_indices(self, target: dt.date):
        """历史日期指数日线（baostock，免费）。返回 indices（含 close/change_pct/amount）。

        翁老规则：复盘按“要求复盘的日期”生成，历史复盘必须用该日真实数据。
        """
        try:
            import baostock as bs
        except ImportError:
            logger.warning("baostock 未安装，无法取历史行情")
            return []
        codes = [
            ("sh.000001", "sh000001", "上证指数"),
            ("sz.399001", "sz399001", "深证成指"),
            ("sz.399006", "sz399006", "创业板指"),
            ("sz.399106", "sz399106", "深证综指"),
        ]
        day = target.isoformat()
        out = []
        try:
            lg = bs.login()
            if lg.error_code != "0":
                return []
            for bs_code, code, name in codes:
                rs = bs.query_history_k_data_plus(
                    bs_code, "date,open,high,low,close,preclose,volume,amount",
                    start_date=day, end_date=day, frequency="d")
                if rs.error_code != "0" or not rs.next():
                    continue
                row = rs.get_row_data()
                try:
                    close = float(row[4])
                    preclose = float(row[5])
                    amount = float(row[7])
                except (ValueError, IndexError):
                    continue
                out.append({
                    "code": code, "name": name, "date": day,
                    "close": close,
                    "change": round(close - preclose, 2) if preclose else None,
                    "change_pct": round((close - preclose) / preclose * 100, 2) if preclose else None,
                    "amount": amount, "amount_yi": round(amount / 1e8, 1) if amount else None,
                    "volume": None, "source": "baostock",
                })
        except Exception as e:
            logger.warning("baostock 历史行情失败 %s: %s", target, e)
        finally:
            try:
                bs.logout()
            except Exception:
                pass
        return out

    # ------------------------------------------------------------------
    def _indices_fresh(self, indices):
        """通达信指数数据新鲜度校验：最新记录日期必须等于最近交易日。

        旧数据（如盘后未更新、停在数日前）返回 False，调用方弃用并降级新浪。
        """
        if not indices:
            return False
        latest = self._latest_trading_day()
        for idx in indices:
            d = str(idx.get("date") or "")
            if d != latest:
                return False
        return True

    def _latest_trading_day(self, max_back: int = 10):
        """最近交易日（YYYYMMDD 字符串）：从今天往回找。"""
        try:
            from scheduler.calendar import is_trading_day
        except Exception:
            is_trading_day = None
        d = dt.date.today()
        for _ in range(max_back):
            if is_trading_day is None or is_trading_day(d):
                return d.strftime("%Y%m%d")
            d -= dt.timedelta(days=1)
        return d.strftime("%Y%m%d")

    # ------------------------------------------------------------------
    def _read_tdx_indices(self):
        """读通达信指数日线（32字节/条）。返回 (indices, all_ok)。

        实测格式（与 SKILL.md 对照，SKILL.md 示例代码格式串「<iiiiiiff」有笔误，以实测为准）：
          offset 0-19 : 5 个 int32 → 日期YYYYMMDD / 开 / 高 / 低 / 收（价格 ÷100）
          offset 20   : float32 成交金额（元，指数为全市场合计）
          offset 24   : int32 成交量（手）
          offset 28   : float32 持仓量（指数无意义）
          实测样例（sh000001 20260723）：f2da6e53→1025875509248.0元；694f8121→562122601手
        """
        indices, all_ok = [], True
        for code, meta in INDEX_DAYS.items():
            try:
                fpath = os.path.join(self.tdx_vipdoc, meta["market"], "lday", f"{code}.day")
                recs = self._read_tdx_day(fpath, 2)
                if not recs:
                    all_ok = False
                    continue
                prev, cur = recs[-2] if len(recs) > 1 else recs[-1], recs[-1]
                close = cur["close"]
                prev_close = prev["close"] if prev else close
                change_pct = round((close - prev_close) / prev_close * 100, 2) if prev_close else None
                indices.append({
                    "code": code,
                    "name": meta["name"],
                    "date": cur["date"],
                    "close": close,
                    "change": round(close - prev_close, 2) if prev_close else None,
                    "change_pct": change_pct,
                    "amount": cur["amount"],            # 元
                    "amount_yi": round(cur["amount"] / 1e8, 1) if cur["amount"] else None,
                    "volume": cur["volume"],            # 手
                    "source": "tdx",
                })
            except Exception as e:
                all_ok = False
                logger.warning("通达信读取失败 %s: %s", code, e)
        return indices, all_ok

    def _read_tdx_day(self, fpath, n_last):
        """读取 .day 文件末尾 n_last 条。"""
        if not os.path.exists(fpath):
            return []
        with open(fpath, "rb") as f:
            size = os.path.getsize(fpath)
            count = size // 32
            if count <= 0:
                return []
            read_n = min(n_last, count)
            f.seek((count - read_n) * 32)
            out = []
            for _ in range(read_n):
                raw = f.read(32)
                if len(raw) < 32:
                    break
                d, o, h, l, c, amt, vol, _intr = DAY_RECORD.unpack(raw)
                out.append({
                    "date": str(d),
                    "open": o / 100, "high": h / 100, "low": l / 100, "close": c / 100,
                    "amount": amt, "volume": vol,
                })
        return out

    # ------------------------------------------------------------------
    def _fetch_sina_indices(self):
        """新浪实时行情兜底（hq.sinajs.cn 需要 Referer）。"""
        if httpx is None:
            return []
        url = f"https://hq.sinajs.cn/list={self.sina_codes}"
        try:
            resp = httpx.get(url, headers={"Referer": "https://finance.sina.com.cn"},
                             timeout=self.timeout)
            resp.raise_for_status()
            out = []
            for line in resp.text.splitlines():
                if "=" not in line or '"' not in line:
                    continue
                head, _, body = line.partition("=")
                body = body.strip().strip(';').strip('"')
                fields = body.split(",")
                if len(fields) < 32:
                    continue
                code = head.split("_")[-1].strip()
                name = fields[0]
                prev_close = self._f(fields[2])
                close = self._f(fields[3])
                amount = self._f(fields[9])  # 成交额（元）
                out.append({
                    "code": code, "name": name,
                    "date": dt.date.today().isoformat(),
                    "close": close,
                    "change": round(close - prev_close, 2) if prev_close else None,
                    "change_pct": round((close - prev_close) / prev_close * 100, 2) if prev_close else None,
                    "amount": amount,
                    "amount_yi": round(amount / 1e8, 1) if amount else None,
                    "volume": self._f(fields[8]),
                    "source": "sina",
                })
            return out
        except Exception as e:
            logger.warning("新浪行情失败: %s", e)
            return []

    @staticmethod
    def _f(v):
        try:
            return float(v)
        except Exception:
            return None

    # ------------------------------------------------------------------
    def _fetch_tencent_indices(self):
        """腾讯财经行情（备用指数源：qt.gtimg.cn）。仅点位/涨跌幅；成交额口径与新浪不一致，不参与 turnover。"""
        if httpx is None:
            return []
        codes = "sh000001,sz399001,sz399006,sz399106"
        try:
            resp = httpx.get(f"https://qt.gtimg.cn/q={codes}",
                             headers={"User-Agent": "Mozilla/5.0", "Referer": "https://gu.qq.com/"},
                             timeout=self.timeout)
            resp.raise_for_status()
            text = resp.content.decode("gbk", errors="replace")
            out = []
            for line in text.splitlines():
                if '"' not in line:
                    continue
                head, _, body = line.partition("=")
                body = body.strip().strip(';').strip('"')
                fields = body.split("~")
                if len(fields) < 33:
                    continue
                raw_code = head.strip().split("_")[-1]  # sh000001 / sz399001
                code = raw_code
                try:
                    close = float(fields[3])
                    prev_close = float(fields[4])
                    pct = float(fields[32])
                except (ValueError, IndexError):
                    continue
                out.append({
                    "code": code, "name": fields[1],
                    "date": dt.date.today().isoformat(),
                    "close": close,
                    "change": round(close - prev_close, 2),
                    "change_pct": pct,
                    "amount": None,      # 腾讯口径与新浪不一致，不参与成交额汇总
                    "amount_yi": None,
                    "volume": None,
                    "source": "tencent",
                })
            return out
        except Exception as e:
            logger.warning("腾讯行情失败: %s", e)
            return []

    def _fetch_breadth(self):
        """东财涨跌家数（f104=上涨 f105=下跌 f106=平盘）。失败返回 None。"""
        if httpx is None:
            return None
        url = ("https://push2.eastmoney.com/api/qt/ulist.np/get"
               f"?fltt=2&secids={EM_INDEX_IDS}&fields=f2,f3,f12,f14,f104,f105,f106")
        try:
            resp = httpx.get(url, timeout=self.timeout)
            resp.raise_for_status()
            data = resp.json()
            diff = data.get("data", {}).get("diff") or []
            up = sum(int(d.get("f104") or 0) for d in diff)
            down = sum(int(d.get("f105") or 0) for d in diff)
            flat = sum(int(d.get("f106") or 0) for d in diff)
            if up or down:
                return {"up": up, "down": down, "flat": flat}
        except Exception as e:
            logger.warning("东财涨跌家数失败: %s", e)
        return None

    def _fetch_sectors(self):
        """板块热点涨幅榜。多源容错：东财 → 新浪行业板块 → 空。

        东财在本机断连（Server disconnected），自动切换新浪行业板块接口。
        """
        # 1) 东财概念板块
        out = self._em_sectors()
        if out:
            return out
        # 2) 新浪行业板块
        out = self._sina_sectors()
        if out:
            return out
        return []

    def _em_sectors(self):
        """东财概念板块涨幅榜。"""
        if httpx is None:
            return []
        url = ("https://push2.eastmoney.com/api/qt/clist/get"
               "?pn=1&pz=5&po=1&np=1&fltt=2&invt=2&fid=f3&fs=m:90+t:3+f:!50"
               "&fields=f2,f3,f12,f14")
        try:
            resp = httpx.get(url, timeout=self.timeout)
            resp.raise_for_status()
            data = resp.json()
            diff = data.get("data", {}).get("diff") or []
            out = []
            for d in diff:
                out.append({"name": d.get("f14"), "code": d.get("f12"), "change_pct": d.get("f3")})
            return out
        except Exception as e:
            logger.warning("东财板块热点失败: %s", e)
            return []

    def _sina_sectors(self):
        """新浪行业板块排行（备用源，东财不可用时切换）。"""
        if httpx is None:
            return []
        url = "https://vip.stock.finance.sina.com.cn/q/view/newSinaHy.php"
        try:
            resp = httpx.get(url, headers={"User-Agent": "Mozilla/5.0",
                                           "Referer": "https://finance.sina.com.cn"},
                             timeout=self.timeout)
            resp.raise_for_status()
            text = resp.text
            start = text.find("{")
            if start < 0:
                return []
            import json as _json
            data = _json.loads(text[start:text.rfind("}") + 1])
            rows = []
            for v in data.values():
                parts = (v or "").split(",")
                # 格式: code,名称,股票数,均价,涨跌额,涨跌幅%,成交额,成交量,领涨股code,领涨股价,领涨股涨跌,领涨股名
                if len(parts) >= 6:
                    try:
                        row = {"name": parts[1], "code": parts[0],
                               "change_pct": round(float(parts[5]), 2)}
                        # 领涨股（新浪格式: ...领涨股code,领涨股名,涨跌额,涨跌幅）
                        if len(parts) >= 10 and parts[9] and not parts[9].replace('.', '').replace('-', '').isdigit():
                            row["leader"] = parts[9].strip()
                        rows.append(row)
                    except (ValueError, IndexError):
                        continue
            rows.sort(key=lambda x: x.get("change_pct") or 0, reverse=True)
            return rows[:12]
        except Exception as e:
            logger.warning("新浪板块热点失败: %s", e)
            return []

    def _sum_turnover(self, indices):
        """两市成交额汇总（亿元）。

        口径修正（翁老指出 28934.8 亿错误）：
        - 沪市全市场 = 上证综指(sh000001) 成交额
        - 深市全市场 = 深证综指(sz399106) 成交额（深证成指/创业板指是成分指数，成交额只含样本股，不能相加）
        缺失任一返回 None（宁缺毋假）。
        """
        by_code = {i.get("code"): i.get("amount") for i in indices if i.get("amount")}
        sh = by_code.get("sh000001")
        sz = by_code.get("sz399106")
        if sh is not None and sz is not None:
            return round((sh + sz) / 1e8, 1)
        if sh is not None:
            return round(sh / 1e8, 1)
        return None
