#!/usr/bin/env python3
"""Build Kurage Knowledge Library topic JSON from generated video jobs.

The output is intentionally static JSON so the public PHP pages stay fast and
do not need to run heavy LLM/classification work on each request.
"""
from __future__ import annotations

import argparse
import json
import re
import time
from collections import Counter, defaultdict
from dataclasses import dataclass
from datetime import datetime
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
JOBS_DIR = ROOT / "storage" / "jobs"
OUT_DIR = ROOT / "storage" / "knowledge"
BASE_URL = "https://kurage.exbridge.jp"


@dataclass(frozen=True)
class TopicDef:
    slug: str
    title: str
    short_title: str
    lead: str
    keywords: tuple[str, ...]
    priority: int = 50


TOPICS: tuple[TopicDef, ...] = (
    TopicDef(
        "ai-video-generation",
        "AI動画生成・ショート動画制作",
        "AI動画生成",
        "台本、画像、音声、字幕、アバターを組み合わせ、短い動画を継続的に作るための実験をまとめています。",
        ("動画生成", "shorts", "ショート", "youtube", "サムネ", "hyperframes", "ernie", "wan", "棒人間", "アニメ", "動画制作", "自動生成", "プロモーション動画", "mv"),
        95,
    ),
    TopicDef(
        "ai-oss-llm",
        "AI OSS・LLM技術解説",
        "AI OSS / LLM",
        "OSS、ローカルLLM、Claude Code、Codex、MCP、AIエージェントなど、実装寄りの技術動画を束ねています。",
        ("oss", "llm", "claude", "codex", "mcp", "ollama", "gemma", "qwen", "openai", "anthropic", "aiエージェント", "agent", "github", "ローカルllm", "技術解説"),
        100,
    ),
    TopicDef(
        "vibe-coding-monetization",
        "バイブコーディング・AI収益化",
        "バイブコーディング",
        "AI開発、Claude Code、Codex、SNS、YouTubeを使って価値を作り、収益化へつなげる視点の動画です。",
        ("バイブコーディング", "vibe", "収益化", "稼ぐ", "monetization", "副業", "claude code", "codex", "web3", "crypto", "クリプト", "youtube攻略"),
        98,
    ),
    TopicDef(
        "kurage-products",
        "Kurageプロダクト・自動化パイプライン",
        "Kurageプロダクト",
        "Kurage、Kurage Montage、Kurage Voice Pro、AIRadio、kvtuberなど、プロダクト群の進化を追える動画です。",
        ("kurage", "kmontage", "kurage montage", "voice pro", "airadio", "kvtuber", "vtuber", "ksnsposter", "kargov", "kdeck", "プロダクト", "デモ"),
        94,
    ),
    TopicDef(
        "youtube-sns-growth",
        "YouTube・SNS運用と成長戦略",
        "YouTube / SNS",
        "YouTube Shorts、X、Threads、TikTok、Instagramなど、配信と告知の自動化・運用改善を扱う動画です。",
        ("youtube", "shorts", "sns", "threads", "tiktok", "instagram", "x投稿", "reddit", "フォロワー", "登録者", "再生数", "投稿", "ライブ配信"),
        88,
    ),
    TopicDef(
        "voice-translation",
        "翻訳字幕・吹き替え・音声生成",
        "翻訳 / TTS",
        "Kurage Voice ProやTTS、字幕、吹き替えを使い、海外動画や音声コンテンツを日本語化・多言語化する動画です。",
        ("翻訳", "字幕", "吹替", "dub", "subtitles", "tts", "voicebox", "voicevox", "音声", "ナレーション", "英語字幕", "日本語字幕"),
        86,
    ),
    TopicDef(
        "news-reaction",
        "ニュース反応・コメント考察",
        "ニュース反応",
        "ニュース本文だけでなく、YahooコメントやXの反応を拾い、世の中の見方を整理する動画です。",
        ("yahoo", "コメント", "ニュース", "反応", "意見", "政治", "経済", "it系", "報道", "kmontage_news", "news reaction"),
        82,
    ),
    TopicDef(
        "business-media",
        "企業発信・メディア自動化",
        "企業発信",
        "ブログ、動画、SNS、AIラジオを組み合わせ、企業の情報発信を継続運用する仕組みを扱います。",
        ("経営者", "企業", "vwork", "ブログ", "メディア", "情報発信", "自動化", "ai radio", "ラジオ", "cms", "aiknowledgecms", "エクスブリッジ"),
        80,
    ),
    TopicDef(
        "entertainment-culture",
        "エンタメ・人物・話題の考察",
        "エンタメ",
        "芸能、映画、スポーツ、SNSで話題になった人物や出来事を、短い動画で紹介・考察しています。",
        ("芸能", "俳優", "女優", "映画", "ドラマ", "アニメ", "音楽", "スポーツ", "エンタメ", "有名人", "スター・ウォーズ", "entertainment"),
        65,
    ),
    TopicDef(
        "life-stories",
        "人生・学び・物語",
        "人生と物語",
        "奇跡、失敗、学び、人生観など、短い物語として心に残る動画をまとめています。",
        ("人生", "奇跡", "学び", "物語", "失敗", "成功", "教訓", "生き", "感動", "ストーリー"),
        60,
    ),
)


STOPWORDS = {
    "https", "http", "www", "com", "kurage", "動画", "生成", "解説", "ニュース", "これ", "それ",
    "ため", "よう", "こと", "今回", "詳細", "確認", "紹介", "考察", "する", "ます", "です",
}


def now_jst() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def load_json(path: Path) -> dict[str, Any] | None:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return None
    return data if isinstance(data, dict) else None


def job_id_from_path(path: Path, job: dict[str, Any]) -> str:
    value = str(job.get("job_id") or path.stem)
    return re.sub(r"[^a-zA-Z0-9]", "", value)


def plain_text(value: Any) -> str:
    text = str(value or "")
    text = text.replace("\uFFFD", "")
    return re.sub(r"\s+", " ", text).strip()


def job_title(job: dict[str, Any]) -> str:
    for key in ("display_title", "summary_title", "article_title", "title", "source_title"):
        value = plain_text(job.get(key))
        if value:
            return value
    return "Kurage動画"


def scene_text(job: dict[str, Any], limit: int = 1200) -> str:
    scenes = ((job.get("script") or {}).get("scenes") or [])
    parts: list[str] = []
    if isinstance(scenes, list):
        for scene in scenes:
            if isinstance(scene, dict):
                narration = plain_text(scene.get("narration"))
                if narration:
                    parts.append(narration)
    return plain_text(" ".join(parts))[:limit]


def job_body(job: dict[str, Any]) -> str:
    parts = [
        job_title(job),
        plain_text(job.get("tweet_text")),
        plain_text(job.get("display_summary")),
        plain_text(job.get("summary")),
        scene_text(job),
        plain_text(job.get("source")),
        plain_text(job.get("content_type")),
        plain_text(job.get("source_platform")),
    ]
    return plain_text(" ".join(parts))


def excerpt(text: str, limit: int = 160) -> str:
    text = plain_text(text)
    return text[:limit] + ("..." if len(text) > limit else "")


def score_topic(text: str, topic: TopicDef) -> int:
    lower = text.lower()
    score = 0
    for kw in topic.keywords:
        count = lower.count(kw.lower())
        if count:
            score += count * (8 + min(len(kw), 10))
    return score + topic.priority // 10


def classify(job: dict[str, Any]) -> str:
    text = job_body(job)
    scores = [(score_topic(text, topic), topic.slug) for topic in TOPICS]
    scores.sort(reverse=True)
    best_score, best_slug = scores[0]
    return best_slug if best_score >= 10 else "life-stories"


def term_counts(items: list[dict[str, Any]]) -> list[str]:
    counter: Counter[str] = Counter()
    for item in items:
        text = item.get("title", "") + " " + item.get("excerpt", "")
        for token in re.findall(r"[A-Za-z][A-Za-z0-9+\-.#]{2,}|[一-龥ぁ-んァ-ンー]{2,}", text):
            token = token.strip().lower()
            if token in STOPWORDS or len(token) < 2:
                continue
            counter[token] += 1
    return [word for word, _count in counter.most_common(10)]


def build_editor_summary(topic: TopicDef, items: list[dict[str, Any]]) -> str:
    terms = term_counts(items)[:5]
    term_text = "、".join(terms) if terms else topic.short_title
    top_titles = [item["title"] for item in items[:3]]
    title_text = "」「".join(top_titles)
    return (
        f"Kurage編集部は、このテーマを「{topic.short_title}」として整理しました。"
        f"中心になっているキーワードは {term_text} です。"
        f"代表的な動画は「{title_text}」で、単発のニュースや動画ではなく、"
        f"{topic.lead} 動画を順番に見ることで、背景、実装、運用上の学びがつながって見えるようになります。"
    )


def read_jobs(limit: int = 0) -> list[dict[str, Any]]:
    jobs: list[dict[str, Any]] = []
    for path in JOBS_DIR.glob("*.json"):
        job = load_json(path)
        if not job:
            continue
        if job.get("status") != "done":
            continue
        job_id = job_id_from_path(path, job)
        video_file = ROOT / "storage" / "jobs" / job_id / "output.mp4"
        if not job.get("static_video_url") and not video_file.exists() and not job.get("video_file"):
            continue
        created = plain_text(job.get("created_at") or job.get("updated_at"))
        item = {
            "job_id": job_id,
            "title": job_title(job),
            "excerpt": excerpt(plain_text(job.get("tweet_text") or job.get("display_summary") or job.get("summary") or scene_text(job)), 220),
            "source": plain_text(job.get("source") or "kurage"),
            "content_type": plain_text(job.get("content_type")),
            "created_at": created,
            "updated_at": plain_text(job.get("updated_at")),
            "views": int(job.get("views") or 0),
            "duration_seconds": int(job.get("duration_seconds") or 0),
            "video_url": plain_text(job.get("static_video_url")) or f"{BASE_URL}/kuragev.php?proxy=video&job_id={job_id}",
            "thumbnail_url": plain_text(job.get("static_thumbnail_url")) or f"{BASE_URL}/kuragev.php?proxy=thumbnail&job_id={job_id}",
            "page_url": f"{BASE_URL}/kuragev.php?id={job_id}",
            "source_url": plain_text(job.get("source_url") or job.get("original_url") or job.get("tweet_url")),
        }
        item["topic_slug"] = classify(job)
        jobs.append(item)
    jobs.sort(key=lambda item: (item.get("created_at") or "", item.get("job_id") or ""), reverse=True)
    return jobs[:limit] if limit > 0 else jobs


def build(limit: int = 0) -> dict[str, Any]:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    (OUT_DIR / "topics").mkdir(parents=True, exist_ok=True)
    jobs = read_jobs(limit=limit)
    grouped: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for job in jobs:
        grouped[job["topic_slug"]].append(job)

    topic_defs = {topic.slug: topic for topic in TOPICS}
    topics_out: list[dict[str, Any]] = []
    for slug, items in sorted(grouped.items(), key=lambda kv: (-len(kv[1]), kv[0])):
        topic = topic_defs.get(slug) or TOPICS[-1]
        items_by_views = sorted(items, key=lambda item: (int(item.get("views") or 0), item.get("created_at") or ""), reverse=True)
        payload = {
            "slug": slug,
            "title": topic.title,
            "short_title": topic.short_title,
            "lead": topic.lead,
            "editor": "Kurage",
            "editor_summary": build_editor_summary(topic, items_by_views[:12]),
            "keywords": term_counts(items)[:12],
            "video_count": len(items),
            "total_views": sum(int(item.get("views") or 0) for item in items),
            "latest_created_at": max((item.get("created_at") or "" for item in items), default=""),
            "featured_videos": items_by_views[:6],
            "videos": items_by_views[:80],
            "wiki_ready_markdown": build_wiki_markdown(topic, items_by_views[:12]),
            "updated_at": now_jst(),
        }
        (OUT_DIR / "topics" / f"{slug}.json").write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
        topics_out.append({
            "slug": slug,
            "title": topic.title,
            "short_title": topic.short_title,
            "lead": topic.lead,
            "editor_summary": payload["editor_summary"],
            "keywords": payload["keywords"],
            "video_count": payload["video_count"],
            "total_views": payload["total_views"],
            "latest_created_at": payload["latest_created_at"],
            "featured_videos": payload["featured_videos"][:3],
        })

    index = {
        "site_title": "Kurage Knowledge Library",
        "description": "Kurage編集者が動画をテーマ別に整理し、学びや流れが分かる知識ページとして育てるライブラリです。",
        "editor": "Kurage",
        "video_count": len(jobs),
        "topic_count": len(topics_out),
        "topics": topics_out,
        "updated_at": now_jst(),
    }
    (OUT_DIR / "index.json").write_text(json.dumps(index, ensure_ascii=False, indent=2), encoding="utf-8")
    return index


def build_wiki_markdown(topic: TopicDef, items: list[dict[str, Any]]) -> str:
    lines = [
        f"# {topic.title}",
        "",
        topic.lead,
        "",
        "## Kurage編集者の要約",
        "",
        build_editor_summary(topic, items),
        "",
        "## 代表動画",
        "",
    ]
    for item in items[:8]:
        lines.append(f"- [{item['title']}]({item['page_url']})")
    lines.append("")
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--limit", type=int, default=0, help="Limit source jobs for testing")
    parser.add_argument("--watch", action="store_true", help="Keep rebuilding in the background")
    parser.add_argument("--interval", type=int, default=900, help="Watch interval seconds")
    args = parser.parse_args()

    while True:
        index = build(limit=max(0, args.limit))
        print(f"[{index['updated_at']}] built {index['topic_count']} topics from {index['video_count']} videos")
        if not args.watch:
            return 0
        time.sleep(max(60, args.interval))


if __name__ == "__main__":
    raise SystemExit(main())
