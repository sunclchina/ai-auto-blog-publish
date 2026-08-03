# -*- coding: utf-8 -*-
"""A-Blog 发布层联调测试：health / categories / 发布测试草稿"""
import os, sys, json

BACKEND = r"E:\my-project\A-Blog\backend"
sys.path.insert(0, BACKEND)

# 环境变量覆盖（本地联调用；部署走 .env/systemd）
os.environ.setdefault("ABLOG__WORDPRESS__BASE_URL", "http://localhost/wordpress")
os.environ.setdefault("ABLOG__WORDPRESS__REST_PATH", "/index.php?rest_route=/ai-auto-blog/v1")

from config import get_config
from publishers import wp_rest

cfg = get_config()
print("base_url:", cfg.get("wordpress.base_url"))
print("rest_path:", cfg.get("wordpress.rest_path"))
print("token set:", bool(cfg.get("wordpress.api_token")), "(len=%d)" % len(cfg.get("wordpress.api_token", "")))

# 1) health
try:
    h = wp_rest.check_health()
    print("\n[health] ok=%s provider=%s source=%s models=%s" % (
        h.get("ok"), h.get("provider"), h.get("source"), h.get("models")))
except Exception as e:
    print("[health] FAIL:", e)

# 2) categories
try:
    cats = wp_rest.fetch_categories()
    print("\n[categories] count=%d" % len(cats))
    for c in cats[:10]:
        print("   -", c)
except Exception as e:
    print("[categories] FAIL:", e)

# 3) 发布测试草稿（MOCK 内容，status=draft）
test_task = {
    "task_id": "20260803-local-e2e-001",
    "column": "tech",
    "topic": "本地联调测试：Nginx 502 排查（MOCK）",
    "final_title": "【本地联调测试】Nginx 反向代理 502 错误排查实战（MOCK 草稿，可删除）",
    "content_html": "<h2>一、背景</h2><p>这是一篇本地联调测试文章，由 A-Blog 发布层自动创建，内容为 MOCK 数据。</p>"
                    "<h2>二、排查步骤</h2><pre><code>systemctl status nginx\njournalctl -u nginx -f</code></pre>"
                    "<p>检查后端服务是否存活。</p>"
                    "<h2>三、总结</h2><blockquote>本文章仅用于验证插件发布链路，请忽略内容。</blockquote>",
    "excerpt": "本地联调测试草稿",
    "meta_description": "本地联调测试：验证 A-Blog 插件发布链路",
    "tags": ["本地测试", "Nginx", "A-Blog联调"],
    "category": "IT技术笔记",
    "featured_image": "",
    "status": "draft",
    "publish_date": "",
    "source": {"model": "deepseek-chat", "prompt_version": "v1.0", "dry_run": True},
}
try:
    r = wp_rest.publish(test_task)
    print("\n[publish] OK ->", r)
except Exception as e:
    print("\n[publish] FAIL:", e)
