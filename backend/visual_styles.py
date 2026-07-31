"""シーン画像のバラエティ注入（kcomicのプリセット/コマ割りノウハウの移植）。

kmontage / kmontage_news / entertainment の動画が毎回「白スタジオ+カード」の
同じ絵になる問題への対策。負荷を増やさないことが前提条件なので、
**プロンプト文字列だけ**を変える（枚数・解像度・ステップは一切変えない）。

負荷とキャッシュの設計:
- entertainment(1日120本規模)は同一の汎用プロンプトがキャッシュヒットすることで
  GPU負荷が抑えられている。そこで**スタイルは日替わり**(日付+チャンネルで決定)にして、
  同じ日の中ではキャッシュが今までどおり効き、日をまたぐと見た目が変わるようにする。
- kmontage系は本数が少ない(手動起点)ので**ジョブ毎**にスタイルを変えてよい。
- 構図ローテーションはシーンindexで決まる決定的なもの（リトライしても同じ絵）。

スタイルはワークスペースのデザイン規約(QUALITY_RULES: 明るいWhite Studio系・
黒背景禁止)に適合する明るい系のみ。
"""
from __future__ import annotations

import hashlib
from datetime import datetime
from typing import Any

# 明るい背景ベースのスタイル群。文字を描かせない・のっぺり回避は既存規約を踏襲。
STYLES = [
    "bright isometric 3d illustration, soft pastel palette, white background",
    "clean flat vector illustration, bold shapes, warm accent colors, off-white background",
    "paper craft diorama style, layered cutouts, soft shadows, bright studio light",
    "watercolor infographic style, light washes, ink outlines, white paper background",
    "soft clay 3d render, rounded shapes, cheerful colors, bright seamless background",
    "ink line art with single accent color, generous white space, editorial illustration",
    "pop-art comic panel style, halftone accents, bright cream background",
    "japanese woodblock inspired flat illustration, light tones, modern clean layout",
    "macro photography style product shot, bright softbox lighting, white sweep background",
    "blueprint-inspired light diagram style, thin lines, pale blue on white",
    "cheerful hand-drawn sketchnote style, marker accents, whiteboard background",
    "low-poly 3d illustration, gentle gradients, bright daylight, white floor",
]

# コマ割り由来の構図ローテーション（シーンindexで決定的に回す）
COMPOSITIONS = [
    "hero composition, bold single subject centered",
    "close-up detail shot, shallow depth",
    "top-down flat lay composition",
    "isometric three-quarter view",
    "wide establishing composition, subject on left third",
    "dynamic diagonal composition",
    "side profile view, clean negative space on right",
    "over-the-shoulder perspective",
]

_QUALITY_TAIL = "no text, no letters, no numbers, no watermark"


def _pick(seq: list[str], seed: str) -> int:
    return int(hashlib.md5(seed.encode("utf-8")).hexdigest(), 16) % len(seq)


def style_seed(source: str, job_id: str, title: str = "") -> str:
    """entertainmentは日替わり(キャッシュ温存)、それ以外はジョブ毎。"""
    if source == "entertainment":
        return f"entertainment:{datetime.now().strftime('%Y-%m-%d')}"
    return f"{source}:{job_id}:{title}"


def apply_visual_variety(scenes: list[dict[str, Any]], source: str, job_id: str, title: str = "") -> list[dict[str, Any]]:
    """各シーンのimage_promptにスタイル+構図を前置する。プロンプト以外は不変。"""
    if not scenes:
        return scenes
    seed = style_seed(source, job_id, title)
    style = STYLES[_pick(STYLES, seed)]
    comp_offset = _pick(COMPOSITIONS, seed + ":comp")
    for i, scene in enumerate(scenes):
        if not isinstance(scene, dict):
            continue
        base = str(scene.get("image_prompt") or "").strip()
        if not base or style.split(",")[0] in base:
            continue  # 空/適用済みはそのまま
        # 冒頭シーンは既存の「hero visual」規約を活かすため構図はheroに固定
        comp = COMPOSITIONS[0] if i == 0 else COMPOSITIONS[(i + comp_offset) % len(COMPOSITIONS)]
        merged = f"{style}, {comp}, {base}"
        if "no text" not in merged:
            merged = f"{merged}, {_QUALITY_TAIL}"
        scene["image_prompt"] = merged[:320]
    print(f"[visual_styles] source={source} style='{style.split(',')[0]}' comp_offset={comp_offset}", flush=True)
    return scenes
