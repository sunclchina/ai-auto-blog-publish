# -*- coding: utf-8 -*-
"""重新生成 wp-plugin/includes/data-prompts.php（提示词更新后运行）。"""
import io

FILES = ['stock', 'tech', 'reading', 'industry']
SRC = 'backend/prompts/%s.md'
DST = 'wp-plugin/includes/data-prompts.php'

def php_str(s):
    return "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"

out = []
out.append('<?php')
out.append('/** data-prompts.php — 各栏目正文写作提示词。生成：python backend/tools/build_prompt_data.py */')
out.append("if ( ! defined( \'ABSPATH\' ) ) { exit; }")
out.append('return array(')
for f in FILES:
    text = io.open(SRC % f, encoding='utf-8').read().strip()
    out.append("\t'%s' => %s," % (f, php_str(text)))
out.append(');')
io.open(DST, 'w', encoding='utf-8', newline='\n').write('\n'.join(out))
print('regenerated')
