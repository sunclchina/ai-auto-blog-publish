# -*- coding: utf-8 -*-
"""
collectors/reading.py — 国学素材采集（B组：采集层）

语料库：E:\\my-project\\chinese-poetry（全唐诗/宋词/诗经/论语等，2346 个 JSON）
规则：按栏目要求随机抽题（唐诗三百首优先），输出候选诗词（原文+作者+出处）。
语料库缺失时用内置精选清单兜底（明确标注 builtin_fallback）。
"""

import os
import sys
import json
import glob
import random
import logging

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

logger = logging.getLogger("ablog.collectors.reading")

DEFAULT_CORPUS = r"E:\my-project\chinese-poetry"

# 内置精选清单（语料库缺失时的兜底，来源均为经典篇目，标注 builtin_fallback）
BUILTIN_POEMS = [
    {"title": "静夜思", "author": "李白", "source": "唐诗三百首(内置兜底)",
     "paragraphs": ["床前明月光，疑是地上霜。", "举头望明月，低头思故乡。"]},
    {"title": "登鹳雀楼", "author": "王之涣", "source": "唐诗三百首(内置兜底)",
     "paragraphs": ["白日依山尽，黄河入海流。", "欲穷千里目，更上一层楼。"]},
    {"title": "春晓", "author": "孟浩然", "source": "唐诗三百首(内置兜底)",
     "paragraphs": ["春眠不觉晓，处处闻啼鸟。", "夜来风雨声，花落知多少。"]},
    {"title": "悯农", "author": "李绅", "source": "唐诗三百首(内置兜底)",
     "paragraphs": ["锄禾日当午，汗滴禾下土。", "谁知盘中餐，粒粒皆辛苦。"]},
    {"title": "江雪", "author": "柳宗元", "source": "唐诗三百首(内置兜底)",
     "paragraphs": ["千山鸟飞绝，万径人踪灭。", "孤舟蓑笠翁，独钓寒江雪。"]},
    {"title": "水调歌头·明月几时有", "author": "苏轼", "source": "宋词(内置兜底)",
     "paragraphs": ["明月几时有，把酒问青天。", "不知天上宫阙，今夕是何年。"]},
    {"title": "关雎", "author": "诗经", "source": "诗经(内置兜底)",
     "paragraphs": ["关关雎鸠，在河之洲。", "窈窕淑女，君子好逑。"]},
    {"title": "学而·其一", "author": "论语", "source": "论语(内置兜底)",
     "paragraphs": ["学而时习之，不亦说乎？", "有朋自远方来，不亦乐乎？"]},
]


class ReadingCollector:
    """国学素材采集器。构造：ReadingCollector(config)"""

    def __init__(self, config=None):
        cfg = config or {}
        self.corpus_dir = (cfg.get("reading", {}) or {}).get("corpus_dir") or DEFAULT_CORPUS
        self.rng = random.Random()

    # ------------------------------------------------------------------
    def collect(self, n=3):
        """随机抽取 n 首候选诗词。返回 [{"title","author","source","paragraphs","tags"}]。"""
        poems = self._load_corpus()
        if not poems:
            logger.warning("语料库不可用，使用内置精选清单（builtin_fallback）")
            poems = [dict(p, tags=["内置兜底"]) for p in BUILTIN_POEMS]
        self.rng.shuffle(poems)
        return poems[:n]

    # ------------------------------------------------------------------
    def _load_corpus(self):
        """加载语料库：唐诗三百首 → 宋词 → 诗经 → 论语。返回全部诗词列表。"""
        out = []
        if not os.path.isdir(self.corpus_dir):
            return out
        # 1) 唐诗三百首（优先）
        t300 = os.path.join(self.corpus_dir, "全唐诗", "唐诗三百首.json")
        out.extend(self._read_poem_file(t300, "唐诗三百首"))
        # 2) 全唐诗（诗人诗集，抽样读取避免全量解析 58 个大文件）
        tang_dir = os.path.join(self.corpus_dir, "全唐诗")
        if os.path.isdir(tang_dir):
            files = sorted(glob.glob(os.path.join(tang_dir, "poet.tang.*.json")))
            out.extend(self._sample_files(files, "全唐诗", 3))
        # 3) 宋词
        song_dir = os.path.join(self.corpus_dir, "宋词")
        if os.path.isdir(song_dir):
            files = sorted(glob.glob(os.path.join(song_dir, "ci.song.*.json")))
            out.extend(self._sample_files(files, "宋词", 3))
        # 4) 诗经
        out.extend(self._read_poem_file(os.path.join(self.corpus_dir, "诗经", "shijing.json"), "诗经"))
        # 5) 论语（散句）
        out.extend(self._read_lunyu(os.path.join(self.corpus_dir, "论语", "lunyu.json")))
        return out

    def _read_poem_file(self, path, source):
        if not os.path.exists(path):
            return []
        try:
            data = json.load(open(path, encoding="utf-8"))
            out = []
            for item in data:
                if not isinstance(item, dict) or not item.get("paragraphs"):
                    continue
                paragraphs = item["paragraphs"] if isinstance(item["paragraphs"], list) else [item["paragraphs"]]
                title = str(item.get("title") or "").strip()
                if not title:
                    # 部分宋词条目缺 title：取首句前 8 字作标题兜底（如实标注）
                    title = str(paragraphs[0]).strip()[:8] or "（无题）"
                out.append({
                    "title": title,
                    "author": item.get("author", "").strip(),
                    "source": source,
                    "paragraphs": [str(p).strip() for p in paragraphs if str(p).strip()],
                    "tags": item.get("tags") or [source],
                })
            return out
        except Exception as e:
            logger.warning("语料读取失败 %s: %s", path, e)
            return []

    def _sample_files(self, files, source, k):
        """随机抽 k 个 JSON 文件解析（避免全量读大文件）。"""
        if not files:
            return []
        chosen = self.rng.sample(files, min(k, len(files)))
        out = []
        for f in chosen:
            out.extend(self._read_poem_file(f, source))
            if len(out) >= 40:  # 单语料源上限，够随机抽题即可
                break
        return out

    def _read_lunyu(self, path):
        if not os.path.exists(path):
            return []
        try:
            data = json.load(open(path, encoding="utf-8"))
            out = []
            for chapter in data if isinstance(data, list) else []:
                if not isinstance(chapter, dict):
                    continue
                for para in chapter.get("paragraphs", [])[:3]:
                    text = str(para).strip()
                    if len(text) >= 6:
                        out.append({
                            "title": f"{chapter.get('chapter', '论语')}·节选",
                            "author": "孔子及弟子",
                            "source": "论语",
                            "paragraphs": [text],
                            "tags": ["论语"],
                        })
            return out
        except Exception as e:
            logger.warning("论语读取失败: %s", e)
            return []
