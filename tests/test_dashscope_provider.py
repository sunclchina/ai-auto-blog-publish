# -*- coding: utf-8 -*-
"""DashScopeProvider（阿里百炼原生文生图）单测。

覆盖：
1. 提交请求格式：image-synthesis 端点、X-DashScope-Async 头、size 用「宽*高」星号格式
2. input_format=messages 切换（wan2.6-t2i 等新模型）
3. 完整链路：提交 → 轮询（PENDING→SUCCEEDED）→ 下载图片字节
4. 任务失败 / 轮询超时 → 返回 None（降级纯文字）
"""
import sys
import unittest
from pathlib import Path
from unittest import mock

BACKEND = Path(__file__).resolve().parent.parent / "backend"
sys.path.insert(0, str(BACKEND))

from agents import image as image_mod  # noqa: E402
from agents.image import DashScopeProvider  # noqa: E402


class FakeResponse:
    """最小 httpx.Response 替身。"""

    def __init__(self, payload=None, status=200, content=b""):
        self._payload = payload
        self.status_code = status
        self.content = content

    def json(self):
        return self._payload

    def raise_for_status(self):
        if self.status_code != 200:
            raise RuntimeError(f"HTTP {self.status_code}")


class TestDashScopeProvider(unittest.TestCase):

    def setUp(self):
        self.prov = DashScopeProvider(api_key="sk-test-123", model="wanx-v1")

    def test_submit_payload_prompt_format(self):
        resp = FakeResponse({"output": {"task_id": "t1", "task_status": "PENDING"}})
        with mock.patch.object(image_mod.httpx, "post", return_value=resp) as mpost:
            task_id = self.prov._submit("测试提示词", (1280, 720))
        self.assertEqual(task_id, "t1")
        url, kwargs = mpost.call_args
        self.assertIn("/api/v1/services/aigc/text2image/image-synthesis", url[0])
        self.assertEqual(kwargs["headers"]["X-DashScope-Async"], "enable")
        self.assertEqual(kwargs["headers"]["Authorization"], "Bearer sk-test-123")
        payload = kwargs["json"]
        self.assertEqual(payload["model"], "wanx-v1")
        self.assertEqual(payload["input"], {"prompt": "测试提示词"})
        self.assertEqual(payload["parameters"]["size"], "1280*720")
        self.assertEqual(payload["parameters"]["n"], 1)

    def test_submit_messages_format(self):
        prov = DashScopeProvider(api_key="sk-test-123", model="wan2.6-t2i",
                                 input_format="messages")
        resp = FakeResponse({"output": {"task_id": "t2"}})
        with mock.patch.object(image_mod.httpx, "post", return_value=resp) as mpost:
            prov._submit("你好", (1280, 720))
        payload = mpost.call_args.kwargs["json"]
        self.assertEqual(payload["model"], "wan2.6-t2i")
        self.assertEqual(
            payload["input"],
            {"messages": [{"role": "user", "content": [{"text": "你好"}]}]},
        )

    def test_poll_until_succeeded(self):
        pending = FakeResponse({"output": {"task_status": "PENDING"}})
        done = FakeResponse({"output": {"task_status": "SUCCEEDED",
                                        "results": [{"url": "https://img.example.com/a.webp"}]}})
        with mock.patch.object(image_mod.httpx, "get", side_effect=[pending, done]) as mget, \
             mock.patch.object(image_mod.time, "sleep") as msleep:
            url = self.prov._poll("t1")
        self.assertEqual(url, "https://img.example.com/a.webp")
        self.assertIn("/api/v1/tasks/t1", mget.call_args_list[0][0][0])
        msleep.assert_called_once()

    def test_generate_full_flow(self):
        submit = FakeResponse({"output": {"task_id": "t1"}})
        poll = FakeResponse({"output": {"task_status": "SUCCEEDED",
                                        "results": [{"url": "https://img.example.com/a.webp"}]}})
        img = FakeResponse(content=b"FAKEIMGBYTES")
        with mock.patch.object(image_mod.httpx, "post", return_value=submit), \
             mock.patch.object(image_mod.httpx, "get", side_effect=[poll, img]), \
             mock.patch.object(image_mod.time, "sleep"):
            out = self.prov.generate("测试", size=(1280, 720))
        self.assertEqual(out, b"FAKEIMGBYTES")

    def test_generate_task_failed_returns_none(self):
        submit = FakeResponse({"output": {"task_id": "t1"}})
        fail = FakeResponse({"output": {"task_status": "FAILED", "code": "x", "message": "y"}})
        with mock.patch.object(image_mod.httpx, "post", return_value=submit), \
             mock.patch.object(image_mod.httpx, "get", return_value=fail), \
             mock.patch.object(image_mod.time, "sleep"):
            out = self.prov.generate("测试", size=(1280, 720))
        self.assertIsNone(out)

    def test_generate_poll_timeout_returns_none(self):
        submit = FakeResponse({"output": {"task_id": "t1"}})
        pending = FakeResponse({"output": {"task_status": "RUNNING"}})
        with mock.patch.object(image_mod.httpx, "post", return_value=submit), \
             mock.patch.object(image_mod.httpx, "get", return_value=pending), \
             mock.patch.object(image_mod.time, "sleep"):
            out = self.prov.generate("测试", size=(1280, 720))
        self.assertIsNone(out)

    def test_build_provider_dashscope(self):
        prov = image_mod.build_image_provider({
            "image": {"provider": "dashscope", "api_key": "sk-x",
                      "model": "wan2.1-t2i-turbo", "endpoint": "https://dashscope.aliyuncs.com"},
        })
        self.assertIsInstance(prov, DashScopeProvider)
        self.assertEqual(prov.model, "wan2.1-t2i-turbo")

    def test_build_provider_noop_when_no_key(self):
        prov = image_mod.build_image_provider({"image": {"provider": "dashscope"}})
        self.assertEqual(prov.name, "noop")


if __name__ == "__main__":
    unittest.main()
