# -*- coding: utf-8 -*-
"""重新生成 wp-plugin/includes/data-poems.php（语料库更新时运行）。"""
import io
import json

TANG = json.load(io.open('E:/my-project/chinese-poetry/全唐诗/唐诗三百首.json', encoding='utf-8'))
SONG = json.load(io.open('E:/my-project/chinese-poetry/宋词/宋词三百首.json', encoding='utf-8'))

def php_str(s):
    s = str(s).replace('\\', '\\\\').replace("'", "\\'")
    return "'" + s + "'"

out = []
out.append('<?php')
out.append('/**')
out.append(' * data-poems.php — 内置诗词语料（唐诗三百首 + 宋词三百首）。')
out.append(' * 生成：python backend/tools/build_poem_data.py（chinese-poetry 语料库更新后重跑）。')
out.append(' * @package AI_Auto_Blog_Publish')
out.append(' */')
out.append('')
out.append('if ( ! defined( \'ABSPATH\' ) ) { exit; }')
out.append('')
out.append('return array(')
for key, data in (('tang', TANG), ('song', SONG)):
    out.append("\t'%s' => array(" % key)
    for p in data:
        title = p.get('title', '')
        if not title and key == 'song':
            rhythmic = p.get('rhythmic', '')
            first = next(iter(p.get('paragraphs', [])), '')
            title = (rhythmic + '·' + first[:6].replace('。', '').replace('，', '')) if rhythmic else first[:6]
        paras = p.get('paragraphs', [])
        out.append("\t\tarray(")
        out.append("\t\t\t'title' => " + php_str(title) + ',')
        out.append("\t\t\t'author' => " + php_str(p.get('author', '')) + ',')
        out.append("\t\t\t'paragraphs' => array(")
        for para in paras:
            out.append("\t\t\t\t" + php_str(para) + ',')
        out.append("\t\t\t),")
        out.append("\t\t),")
    out.append("\t),")
out.append(');')
io.open('E:/my-project/A-Blog/wp-plugin/includes/data-poems.php', 'w', encoding='utf-8', newline='\n').write('\n'.join(out))
print('regenerated data-poems.php')
