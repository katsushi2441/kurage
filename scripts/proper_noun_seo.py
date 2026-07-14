#!/usr/bin/env python3
"""固有名詞SEO自動化 — 動画ジョブのSEOフィールドを固有名詞起点で自動生成する。

背景(2026-07-15): 1982年映画「土の汗」の紹介ショートが、SEOフィールド空のまま
Google検索「映画 土の汗」から2日で100超のアクセスを獲得した(kurageの動画PVの
過半)。バズった無名の固有名詞×日本語情報の空白は競合ゼロで即上位に入る。
この偶然を仕組み化する: タイトル/要約に固有名詞(作品名・人名など)を含む
完了ジョブに、固有名詞を軸にしたseo_title/description/body/keywordsを埋める。

- kuragev.php(heteml)は:18303 APIをライブ参照するので、storage/jobs/<id>.json
  を更新すれば即公開ページに反映される。
- 生成はローカルgemma4。**与えた情報にない事実(年代・出演者・あらすじ等)の
  捏造は禁止**とプロンプトで明示する。マイナー作品ほど幻覚リスクが高く、
  ランキングに効くのは固有名詞の存在であって捏造された蘊蓄ではない。
- gemma4は思考型モデルなので "think": false 必須(CLAUDE.md運用手順)。

使い方:
  python3 scripts/proper_noun_seo.py --id b10de882a73a4e43   # 1件
  python3 scripts/proper_noun_seo.py --recent 30             # 直近30件の未設定分
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sys
import time
from pathlib import Path

import requests

ROOT = Path(__file__).resolve().parents[1]
JOBS_DIR = ROOT / "storage" / "jobs"
sys.path.insert(0, str(ROOT / "backend"))
from config import OLLAMA_URL, OLLAMA_MODEL  # noqa: E402


def clean(value) -> str:
    return re.sub(r"\s+", " ", str(value or "")).strip()


def gather_context(job: dict) -> str:
    parts = []
    for key in ("title", "display_summary", "summary", "source_title"):
        v = clean(job.get(key))
        if v and v not in parts:
            parts.append(v)
    return "\n".join(parts)[:1200]


def extract_json(text: str):
    text = text.strip()
    start, end = text.find("{"), text.rfind("}")
    if start != -1 and end > start:
        try:
            return json.loads(text[start:end + 1])
        except json.JSONDecodeError:
            return None
    return None


def generate_seo(context: str) -> dict | None:
    prompt = f"""あなたは日本語SEOの専門家です。以下はショート動画のタイトルと要約です。

{context}

この動画ページの検索流入を最大化するSEOメタデータを作ってください。

最重要ルール:
- タイトル/要約に含まれる固有名詞(映画・番組・作品名、人名、ブランド名等)を特定し、
  seo_title・seo_description・seo_keywordsに必ず含める。
- **ここに書かれていない事実(公開年・出演者・あらすじ・国名など)を推測で書かない。**
  与えられた情報だけを言い換える。知識の追加は禁止。
- seo_keywordsには検索者が打ちそうな組み合わせを入れる(例: 作品名なら
  「映画 ○○」「○○ どんな映画」、人名なら「○○ 何者」など)。

次のJSONだけを出力(他の文章は書かない):
{{"proper_nouns": ["固有名詞のリスト(なければ空)"],
 "seo_title": "58字以内。固有名詞を先頭近くに",
 "seo_description": "120〜158字。固有名詞を含む自然な紹介文",
 "seo_body": "3〜5文の日本語。固有名詞を含み、動画で何が見られるかを説明。捏造禁止",
 "seo_keywords": ["8個まで"]}}"""
    resp = requests.post(f"{OLLAMA_URL}/api/generate", json={
        "model": OLLAMA_MODEL, "prompt": prompt, "stream": False,
        "think": False,
        "options": {"num_predict": 700, "temperature": 0.2},
    }, timeout=300)
    resp.raise_for_status()
    parsed = extract_json(resp.json().get("response") or "")
    if not isinstance(parsed, dict) or not clean(parsed.get("seo_title")):
        return None
    return parsed


def process_job(path: Path, force: bool = False) -> bool:
    job = json.loads(path.read_text(encoding="utf-8"))
    if job.get("status") != "done":
        return False
    if clean(job.get("seo_title")) and not force:
        return False
    context = gather_context(job)
    if len(context) < 10:
        return False
    seo = generate_seo(context)
    if not seo:
        print(f"  [skip] {path.stem}: 生成失敗")
        return False
    job["seo_title"] = clean(seo.get("seo_title"))[:70]
    job["seo_description"] = clean(seo.get("seo_description"))[:200]
    job["seo_body"] = str(seo.get("seo_body") or "").strip()[:1200]
    kws = [clean(k) for k in (seo.get("seo_keywords") or []) if clean(k)]
    job["seo_keywords"] = kws[:10]
    job["seo_generated_by"] = f"proper_noun_seo/{OLLAMA_MODEL}"
    job["seo_generated_at"] = time.strftime("%Y-%m-%dT%H:%M:%S%z")
    tmp = path.with_suffix(".json.tmp")
    tmp.write_text(json.dumps(job, ensure_ascii=False, indent=1), encoding="utf-8")
    os.replace(tmp, path)
    nouns = ", ".join(seo.get("proper_nouns") or []) or "-"
    print(f"  [ok] {path.stem}: 固有名詞=[{nouns}] title={job['seo_title'][:40]}")
    return True


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--id", help="特定ジョブIDのみ処理")
    ap.add_argument("--recent", type=int, default=0, help="直近N件の完了ジョブを処理")
    ap.add_argument("--force", action="store_true", help="既存SEOも上書き")
    args = ap.parse_args()

    if args.id:
        targets = [JOBS_DIR / f"{args.id}.json"]
    else:
        n = args.recent or 30
        targets = sorted(JOBS_DIR.glob("*.json"),
                         key=lambda p: p.stat().st_mtime, reverse=True)[:n]
    done = 0
    for p in targets:
        if not p.exists():
            print(f"  [skip] {p.stem}: ファイルなし")
            continue
        try:
            if process_job(p, force=args.force):
                done += 1
        except Exception as e:
            print(f"  [err] {p.stem}: {str(e)[:100]}")
    print(f"[proper_noun_seo] 更新 {done}件 / 対象 {len(targets)}件")


if __name__ == "__main__":
    main()
