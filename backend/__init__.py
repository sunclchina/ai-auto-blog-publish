"""A-Blog Python 伴生服务（FastAPI, 127.0.0.1:8080）。

包结构（总纲 §2 目录结构）：
- config.py           配置加载（config.yaml + 环境变量 + WP option 同步占位）
- core/               SQLite / SimHash 指纹 / 风控 / SEO / 日志
- scheduler/          交易日历 / 每日任务队列
- publishers/         WP REST / XML-RPC 发布 / 图片处理
- agents/ collectors/ prompts/  由对应开发组负责（本包不实现）
"""

__version__ = "1.0.0"
