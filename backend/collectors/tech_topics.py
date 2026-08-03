# -*- coding: utf-8 -*-
"""
collectors/tech_topics.py — IT 技术热点采集（B组：采集层）

选题来源：
  1. 固定高频问题库（WordPress/WP插件/Nginx/服务器/开源工具场景，30+ 条中文问题池）——主力
  2. 可选 RSS（config.tech_topics.rss_urls）——网络失败静默降级到问题池

输出：[{"question", "source": "question_pool"|"rss", "url": 可选}]
铁律：网络失败静默降级，绝不抛异常。
"""

import os
import sys
import logging
import xml.etree.ElementTree as ET
from urllib.parse import urlparse

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

try:
    import httpx
except ImportError:  # pragma: no cover
    httpx = None

logger = logging.getLogger("ablog.collectors.tech")

# 固定高频问题库（中文，WordPress/WP插件/Nginx/服务器/开源工具场景）
QUESTION_POOL = [
    # WordPress / WP 插件
    "WordPress 网站打开慢的十大原因与提速优化方法",
    "WordPress 后台登录页面打不开或一直加载中如何解决",
    "WordPress 修改固定链接后文章 404 的排查与修复",
    "WordPress 主题更新后样式错乱怎么办",
    "WordPress 自动更新失败的原因与手动更新步骤",
    "WordPress 数据库连接错误（Error establishing a database connection）排查",
    "WordPress 文章图片不显示或防盗链失效怎么处理",
    "WordPress 被攻击挂马后的清理与加固流程",
    "WordPress 评论垃圾信息太多，如何配置反垃圾插件",
    "WordPress 多站点（Multisite）配置注意事项",
    "WP Super Cache 与 W3 Total Cache 怎么选",
    "WordPress 网站被 502 Bad Gateway 缠住的原因与解决",
    "WordPress 定时发布文章不生效的排查思路",
    "WordPress 备份与恢复：数据库+文件完整教程",
    # Nginx
    "Nginx 配置反向代理后 502/504 报错排查",
    "Nginx location 匹配优先级详解与常见坑",
    "Nginx 开启 gzip 压缩后网页仍慢的原因",
    "Nginx 配置 HTTPS 证书后 http 自动跳转 https",
    "Nginx 限制单个 IP 并发连接与请求速率（防 CC）",
    "Nginx 日志格式自定义与访问日志分析",
    "Nginx 静态资源缓存配置（expires/cache-control）",
    "Nginx 负载均衡 upstream 配置与健康检查",
    "Nginx 上传文件大小限制 client_max_body_size 详解",
    # 服务器 / Linux
    "服务器 SSH 登录慢的排查与优化",
    "Linux 服务器磁盘空间不足的清理思路（du/find 实战）",
    "服务器 CPU 占用 100% 的定位与处理（top/ps）",
    "Linux 定时任务 crontab 不执行的原因排查",
    "iptables 与 firewalld 防火墙规则配置入门",
    "服务器被暴力破解 SSH 的防护（fail2ban）",
    "Linux 查看端口占用与进程（netstat/lsof/ss）",
    "服务器内存不足导致 OOM 的排查与优化",
    "系统日志 /var/log 排查思路：journalctl 与 dmesg",
    "宝塔面板常见故障：MySQL 启动失败的排查",
    # 开源工具 / 建站
    "Hugo 静态博客部署到 Nginx 的完整流程",
    "Git 常用命令速查与误操作恢复（reflog）",
    "Docker 容器日志占用磁盘过大如何清理",
    "Docker Compose 部署 WordPress+MySQL 实战",
    "Markdown 写作与发布工作流：从本地到线上",
    "Cloudflare CDN 加速网站并隐藏真实 IP 的配置",
    "网站被收录慢？robots.txt 与 sitemap 正确写法",
]


class TechTopicCollector:
    """IT 技术热点采集器。构造：TechTopicCollector(config)"""

    def __init__(self, config=None):
        cfg = config or {}
        tech_cfg = cfg.get("tech_topics", {}) or {}
        self.rss_urls = tech_cfg.get("rss_urls") or []
        self.timeout = tech_cfg.get("timeout", 8)
        self.max_pool = tech_cfg.get("max_pool", 12)

    # ------------------------------------------------------------------
    def collect(self, n=None):
        """返回问题列表 [{"question", "source", "url"}]; 网络失败静默降级到问题池。"""
        questions = []
        rss_ok = False
        for url in self.rss_urls:
            items = self._fetch_rss(url)
            if items:
                rss_ok = True
                questions.extend(items)
        if rss_ok:
            logger.info("RSS 采集成功 %d 条", len(questions))
        else:
            logger.info("RSS 不可用（或无配置），降级到固定问题池")
        # 问题池兜底（保证始终有可用素材）
        pool = [{"question": q, "source": "question_pool", "url": None} for q in QUESTION_POOL]
        questions.extend(pool)
        # 去重 + 打乱取前 n
        seen, dedup = set(), []
        for q in questions:
            key = q["question"].strip()
            if key and key not in seen:
                seen.add(key)
                dedup.append(q)
        import random
        random.shuffle(dedup)
        limit = n or self.max_pool
        return dedup[:limit]

    # ------------------------------------------------------------------
    def _fetch_rss(self, url):
        """抓取 RSS/Atom（stdlib 解析），失败返回 []。"""
        if httpx is None or not url:
            return []
        try:
            resp = httpx.get(url, timeout=self.timeout, follow_redirects=True,
                             headers={"User-Agent": "Mozilla/5.0 (A-Blog collector)"})
            resp.raise_for_status()
            root = ET.fromstring(resp.content)
            items = []
            for node in root.iter():
                tag = node.tag.rsplit("}", 1)[-1].lower()
                if tag == "item" or tag == "entry":
                    title, link = None, None
                    for child in node:
                        ctag = child.tag.rsplit("}", 1)[-1].lower()
                        if ctag == "title":
                            title = (child.text or "").strip()
                        elif ctag == "link":
                            link = child.get("href") or (child.text or "").strip()
                    if title:
                        items.append({"question": title, "source": "rss", "url": link or url})
            return items[:10]
        except Exception as e:
            logger.warning("RSS 抓取失败 %s: %s", url, e)
            return []
