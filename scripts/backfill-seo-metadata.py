#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import re
import time
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
JOBS_DIR = ROOT / "storage" / "jobs"


def clean_text(value: Any) -> str:
    text = re.sub(r"\s+", " ", str(value or "")).strip()
    return text.replace("\ufffd", "")


def clip(text: str, limit: int) -> str:
    text = clean_text(text)
    if len(text) <= limit:
        return text
    return text[:limit].rstrip(" 、。,.") + "..."


def display_title(job: dict[str, Any]) -> str:
    for key in ("display_title", "summary_title", "article_title", "title", "source_title"):
        title = clean_text(job.get(key))
        if title:
            return title
    return "Kurage AI動画"


def body_source(job: dict[str, Any]) -> str:
    for key in ("display_summary", "summary", "copy_summary", "primary_description", "tweet_text", "translated_text", "source_title"):
        text = clean_text(job.get(key))
        if text:
            return text
    scenes = (job.get("script") or {}).get("scenes") if isinstance(job.get("script"), dict) else []
    if isinstance(scenes, list):
        return clean_text(" ".join(str(scene.get("narration") or "") for scene in scenes if isinstance(scene, dict)))
    return ""


def tool_label(job: dict[str, Any]) -> str:
    source = clean_text(job.get("source")).lower()
    content_type = clean_text(job.get("content_type")).lower()
    if source == "kuragevp" or content_type == "voice_pro_translation" or job.get("kuragevp_job_id"):
        return "Kurage Voice Pro"
    if source == "kmontage_news":
        return "Kurage Montage News"
    if source == "kmontage":
        return "Kurage Montage"
    if source == "blog" or content_type == "blog":
        return "Kurage Blog"
    if source == "horizon":
        return "Kurage Horizon"
    if source == "entertainment" or content_type == "entertainment_short":
        return "Kurage Entertainment"
    if source == "klofi" or content_type == "lofi_longform":
        return "Kurage Lo-Fi"
    return "Kurage"


def keyword_candidates(job: dict[str, Any], title: str, body: str) -> list[str]:
    base = [
        "AI動画",
        "ショート動画",
        "要点解説",
        "Kurage",
    ]
    text = f"{title} {body}"
    rules = [
        ("Claude", "Claude"),
        ("Codex", "Codex"),
        ("AIエージェント", "AIエージェント"),
        ("YouTube", "YouTube"),
        ("ショート", "YouTubeショート"),
        ("収益", "収益化"),
        ("稼", "稼ぐ"),
        ("Vibe", "バイブコーディング"),
        ("バイブ", "バイブコーディング"),
        ("HyperFrames", "HyperFrames"),
        ("Remotion", "Remotion"),
        ("Reddit", "Reddit"),
        ("Google", "Google AI"),
        ("VTuber", "AI VTuber"),
        ("字幕", "日本語字幕"),
        ("吹替", "日本語吹替"),
        ("Yahoo", "ニュースコメント"),
    ]
    for needle, keyword in rules:
        if needle.lower() in text.lower():
            base.append(keyword)
    base.append(tool_label(job))
    seen: set[str] = set()
    out: list[str] = []
    for item in base:
        if item and item not in seen:
            seen.add(item)
            out.append(item)
    return out[:12]


def seo_title_for(job: dict[str, Any], title: str, keywords: list[str]) -> str:
    title = clean_text(title)
    if len(title) >= 24:
        return clip(title, 58)
    suffix = "・".join(keywords[:2])
    return clip(f"{title} | {suffix}の要点解説", 58)


def seo_description_for(job: dict[str, Any], title: str, body: str, keywords: list[str]) -> str:
    body = clean_text(body)
    if body:
        return clip(body, 158)
    return clip(f"{title}をKurageが短く整理したAIショート動画です。{', '.join(keywords[:4])}の要点を動画で確認できます。", 158)


def seo_body_for(job: dict[str, Any], title: str, body: str, keywords: list[str]) -> str:
    tool = tool_label(job)
    source = clean_text(job.get("source_title")) or title
    summary = clip(body, 520) if body else f"{title}の要点を短時間で確認できる動画です。"
    keyword_text = "、".join(keywords[:8])
    lines = [
        f"この動画は「{title}」について、{tool}で生成した要点解説動画です。",
        f"元情報や台本の内容をもとに、重要なポイントを短く整理しています。",
        f"主なテーマは、{keyword_text}です。",
        f"動画の概要: {summary}",
    ]
    if source and source != title:
        lines.append(f"関連する元情報: {source}")
    return "\n".join(lines)


def load_jobs() -> list[tuple[int, Path, dict[str, Any]]]:
    jobs: list[tuple[int, Path, dict[str, Any]]] = []
    for path in JOBS_DIR.glob("*.json"):
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except Exception:
            continue
        if data.get("status") != "done":
            continue
        try:
            views = max(0, int(data.get("views") or 0))
        except Exception:
            views = 0
        jobs.append((views, path, data))
    jobs.sort(key=lambda item: (item[0], str(item[2].get("created_at") or "")), reverse=True)
    return jobs


def save(path: Path, data: dict[str, Any]) -> None:
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    tmp.replace(path)


def main() -> int:
    parser = argparse.ArgumentParser(description="Add SEO metadata to high-view Kurage videos.")
    parser.add_argument("--limit", type=int, default=30)
    parser.add_argument("--min-views", type=int, default=1)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    changed = 0
    for views, path, job in load_jobs():
        if changed >= args.limit:
            break
        if views < args.min_views:
            continue
        title = display_title(job)
        body = body_source(job)
        keywords = keyword_candidates(job, title, body)
        updates = {
            "seo_title": seo_title_for(job, title, keywords),
            "seo_description": seo_description_for(job, title, body, keywords),
            "seo_body": seo_body_for(job, title, body, keywords),
            "seo_keywords": keywords,
            "seo_updated_at": time.strftime("%Y-%m-%d %H:%M:%S"),
        }
        if all(job.get(k) == v for k, v in updates.items() if k != "seo_updated_at"):
            continue
        job.update(updates)
        changed += 1
        print(json.dumps({"job_id": path.stem, "views": views, "seo_title": updates["seo_title"]}, ensure_ascii=False))
        if not args.dry_run:
            save(path, job)
    print(json.dumps({"ok": True, "changed": changed, "dry_run": args.dry_run}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
