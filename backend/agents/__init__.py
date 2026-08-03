# -*- coding: utf-8 -*-
"""
backend/agents — AI 生成层（多智能体流水线）

模块：
  base.py      智能体基类：统一模型调用/重试/熔断/记账/dry_run mock
  topic.py     Step1 选题（前置查重 + 黑名单过滤）
  title.py     Step2 SEO 标题（3-5 条自动择优）
  outline.py   Step3 分栏目固定结构大纲
  content.py   Step4 正文填充（分栏目长度规范，输出 HTML）
  humanize.py  Step5 去AI润色
  seo.py       Step6 SEO 优化（Meta/密度/长尾/内链）
  image.py     Step7 配图（ImageProvider 抽象 + DalleProvider + 降级）
  pipeline.py  流水线编排器（7 步顺序执行 + 失败策略 + dry_run）

运行方式（backend/ 为根）：
  cd E:\\my-project\\A-Blog\\backend
  python -c "from agents.pipeline import PipelineAgent; ..."
"""

import os
import sys

_BACKEND_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
if _BACKEND_ROOT not in sys.path:
    sys.path.insert(0, _BACKEND_ROOT)

__all__ = [
    "base", "topic", "title", "outline", "content",
    "humanize", "seo", "image", "pipeline",
]
