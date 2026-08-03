# -*- coding: utf-8 -*-
"""测试指纹查重拦截：提交与 post_id=8 相同内容的文章，应被 dedup 拦截"""
import os, sys
sys.path.insert(0, r"E:\my-project\A-Blog\backend")

os.environ.setdefault("ABLOG__WORDPRESS__BASE_URL", "http://localhost/wordpress")
os.environ.setdefault("ABLOG__WORDPRESS__REST_PATH", "/index.php?rest_route=/ai-auto-blog/v1")

from publishers import wp_rest

# 与上次完全相同的正文（标题略不同，正文相同 → SimHash 应判重）
dup_task = {
    "task_id": "20260803-local-e2e-002",
    "column": "tech",
    "topic": "重复内容测试",
    "final_title": "【重复测试】Nginx 502 排查（应被拦截）",
    "content_html": "<h2>一、背景</h2><p>这是一篇本地联调测试文章，由 A-Blog 发布层自动创建，内容为 MOCK 数据。</p>"
                    "<h2>二、排查步骤</h2><pre><code>systemctl status nginx\njournalctl -u nginx -f</code></pre>"
                    "<p>检查后端服务是否存活。</p>"
                    "<h2>三、总结</h2><blockquote>本文章仅用于验证插件发布链路，请忽略内容。</blockquote>",
    "excerpt": "重复内容测试",
    "meta_description": "重复内容测试",
    "tags": ["测试"],
    "category": "IT技术笔记",
    "featured_image": "",
    "status": "draft",
    "publish_date": "",
    "source": {"model": "deepseek-chat", "prompt_version": "v1.0", "dry_run": True},
}
try:
    r = wp_rest.publish(dup_task)
    print("[duplicate-test] 未被拦截（异常！）->", r)
except Exception as e:
    print("[duplicate-test] 被拦截:", str(e)[:150])
