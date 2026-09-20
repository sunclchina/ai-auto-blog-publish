# -*- coding: utf-8 -*-
"""复盘数据闸加严 + AI 输出 JSON 包装解析测试（v1.5.9，线上 post 7482 成因回归）。

覆盖：
1. _stock_data_ok：仅指数（历史补写日K）→ 跳过；指数 + 任一结构维度 → 放行；无指数 → 跳过
2. ContentAgent._sanitize_html：AI 输出 JSON 包装（{"content_html": ...}）→ 提取正文不泄漏键名；
   普通 HTML 原样；纯文本包 <p>
"""
import datetime
import os
import sys
import tempfile
import unittest
from pathlib import Path

BACKEND = Path(__file__).resolve().parent.parent / "backend"
sys.path.insert(0, str(BACKEND))

_tmpdir = tempfile.mkdtemp(prefix="abp-datagate-")
os.environ["ABLOG__DATA__DB_PATH"] = str(Path(_tmpdir) / "test.db")
os.environ["ABLOG__AI__ENABLED"] = "false"
os.environ["ABLOG__SCHEDULER__ENABLED"] = "false"
os.environ["ABLOG__PUBLISH__ENABLED"] = "true"

from scheduler import daily_queue as dq          # noqa: E402
from agents.content import ContentAgent          # noqa: E402
from config import get_config                     # noqa: E402


class TestStockDataGate(unittest.TestCase):
    """复盘数据闸：指数 + 至少一个结构维度才放行（post 7482：仅指数 → 数据盲区废稿）。"""

    def test_only_indices_is_insufficient(self):
        # 9/18 复盘补跑场景：新浪日K只有指数，板块/涨跌家数/资金流全空 → 必须跳过
        material = {
            "indices": [{"name": "上证指数", "close": 3911.87, "change_pct": 0.94}],
            "sectors": [], "breadth": {}, "main_flow": None,
            "industry_flow": {}, "limit": {}, "margin": None, "north": None,
        }
        self.assertFalse(dq._stock_data_ok(material))

    def test_no_indices_insufficient(self):
        self.assertFalse(dq._stock_data_ok({}))
        self.assertFalse(dq._stock_data_ok({"indices": []}))

    def test_indices_plus_sectors_ok(self):
        material = {
            "indices": [{"name": "上证指数", "close": 3911.87}],
            "sectors": [{"name": "半导体", "change_pct": 2.5}],
        }
        self.assertTrue(dq._stock_data_ok(material))

    def test_indices_plus_breadth_ok(self):
        material = {"indices": [{"close": 3911.87}], "breadth": {"up": 3000, "down": 200}}
        self.assertTrue(dq._stock_data_ok(material))

    def test_indices_plus_flow_ok(self):
        material = {"indices": [{"close": 3911.87}], "main_flow": 123.45}
        self.assertTrue(dq._stock_data_ok(material))

    def test_indices_plus_limit_ok(self):
        material = {"indices": [{"close": 3911.87}], "limit": {"zt": 45, "dt": 3}}
        self.assertTrue(dq._stock_data_ok(material))


class TestContentJsonWrapper(unittest.TestCase):
    """AI 偶发输出 JSON 包装 → _sanitize_html 提取 content_html（post 7482 泄漏回归）。"""

    def setUp(self):
        self.agent = ContentAgent(get_config(), core=None, dry_run=True)

    def test_json_wrapped_content_extracted(self):
        out = ('{"content_html": "<h2>2026.9.18 普涨</h2><p>正文内容</p>", "excerpt": "摘要"}')
        html = self.agent._sanitize_html(out)
        self.assertNotIn("content_html", html)
        self.assertIn("<h2>2026.9.18 普涨</h2>", html)
        self.assertIn("<p>正文内容</p>", html)

    def test_plain_html_unchanged(self):
        html = self.agent._sanitize_html("<h2>标题</h2><p>正文</p>")
        self.assertEqual(html, "<h2>标题</h2><p>正文</p>")

    def test_markdown_fence_html(self):
        html = self.agent._sanitize_html("```html\n<h2>标题</h2><p>正文</p>\n```")
        self.assertNotIn("```", html)
        self.assertIn("<h2>标题</h2>", html)

    def test_plain_text_wrapped_in_p(self):
        html = self.agent._sanitize_html("第一段\n\n第二段")
        self.assertEqual(html, "<p>第一段</p>\n<p>第二段</p>")

    def test_json_in_fence_extracted(self):
        out = '```json\n{"content_html": "<p>fenced json</p>"}\n```'
        html = self.agent._sanitize_html(out)
        self.assertIn("<p>fenced json</p>", html)
        self.assertNotIn("content_html", html)

    def test_realistic_7482_shape(self):
        # 7482 泄漏形态：AI 返回含 content_html 键的输出，键名不得进入正文；
        # 伪 JSON（值内未转义换行）→ json.loads 失败 → 特征正则兜底提取
        out = '{"content_html": "<h2>普涨缩量</h2>\n<p>数据盲区</p>", "excerpt": "摘要"}'
        html = self.agent._sanitize_html(out)
        self.assertNotIn("content_html", html)
        self.assertNotIn("excerpt", html)
        self.assertIn("<h2>普涨缩量</h2>", html)
        self.assertIn("<p>数据盲区</p>", html)


if __name__ == "__main__":
    unittest.main(verbosity=2)
