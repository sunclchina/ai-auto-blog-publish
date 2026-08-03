# -*- coding: utf-8 -*-
"""指纹交叉验证（Python 侧）— 与 PHP tests/cross_check_fingerprint.php 对拍。

用法: python tests/cross_check_fingerprint.py
规范: docs/05-plugin.md §6.1（S1-S7，唯一权威）
"""
import sys, os
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))) + os.sep + "backend")

from core import fingerprint as fp

samples = [
    "今日A股三大指数集体收跌，上证指数跌0.42%，深证成指跌0.77%。两市成交额1.03万亿，较昨日缩量。板块方面，银行板块逆势走强。",
    "Nginx 反向代理配置实战：从入门到常见报错排查，服务器运维必备技能，WordPress 提速优化指南。",
    "静夜思 李白 床前明月光疑是地上霜举头望明月低头思故乡 这是一首描写思乡之情的经典唐诗。",
]
for i, s in enumerate(samples, 1):
    print(f"PY_SAMPLE{i}={fp.fingerprint_hex(s)}")

a = fp.fingerprint_hex(samples[0])
b = fp.fingerprint_hex("今日A股三大指数集体收跌，上证指数跌0.42%，深证成指跌0.77%，两市成交额1.03万亿。板块方面，银行板块逆势走强。")
print(f"PY_HAMMING_AB={fp.hamming_distance(a, b)}")
print("PY_OK=1")
