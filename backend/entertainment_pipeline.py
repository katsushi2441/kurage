"""Entertainment news SEO article pipeline for Kurage.

Collects public entertainment-news headlines, creates safe original Kurage
articles, and prepares Amazon + Kurage short-video links.
"""
from __future__ import annotations

import argparse
import email.utils
import hashlib
import html
import json
import re
import time
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from datetime import datetime, timezone, timedelta
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
ARTICLES_PATH = ROOT / "data" / "entertainment_articles.json"
JOBS_DIR = ROOT / "storage" / "jobs"
KURAGE_BASE = "https://kurage.exbridge.jp"
GO_BASE = "/go.php"
DEFAULT_QUERY = "芸能人 OR 俳優 OR 女優 OR アイドル OR 歌手 OR タレント"
DEFAULT_TARGET_PER_DAY = 30
MAX_SOURCE_AGE_DAYS = 7

NG_WORDS = (
    "逮捕", "容疑", "起訴", "不起訴", "不倫", "浮気", "離婚", "訃報", "死去",
    "暴露", "炎上", "謝罪", "被害", "加害", "薬物", "違法", "疑惑", "裁判",
    "交際", "熱愛", "嫉妬", "報酬", "年俸", "大公開", "がん", "病気", "闘病",
)

GENERIC_WORDS = {
    "芸能", "ニュース", "映画", "ドラマ", "番組", "主演", "出演", "発表", "話題",
    "注目", "公式", "写真", "公開", "新作", "主題歌", "ライブ", "イベント",
    "韓国", "日本", "メンバー", "メンバ", "最新", "設備", "映画館", "前売券",
    "研究所", "ドラマ", "映画祭", "総力戦", "栄", "名古屋", "東京", "大阪",
    "社外取締役", "交際報道", "有名人", "都道府県別", "人気美人女優",
    "中学生役", "年上半期", "男性タレント", "女性タレント", "ランキング",
    "Kurage", "Horizon", "AI", "Google", "OpenAI", "動画", "翻訳", "生成",
    "物語", "今夜", "金融業界", "製品的", "知識戦略", "婚約者", "募集中",
    "代償", "昔", "人生", "再構築", "最新", "テクノロジー",
}

ENGLISH_GENERIC_WORDS = {
    "AI", "API", "CEO", "CTO", "CFO", "USA", "US", "UK", "Google", "OpenAI",
    "Kurage", "Horizon", "Amazon", "YouTube", "Twitter", "X", "News",
    "Video", "Blog", "Voice", "Pro", "Project", "Agent", "Code", "Data",
    "Canada", "Strong", "America", "Great", "Again", "Banking", "Homebrew",
}

JOB_VIDEO_SOURCES = {"kuragevp", "horizon", "blog"}


def now_jst() -> str:
    return datetime.now(timezone(timedelta(hours=9))).strftime("%Y-%m-%d %H:%M:%S")


def slugify(value: str) -> str:
    digest = hashlib.sha1(value.encode("utf-8")).hexdigest()[:12]
    return "ent-" + digest


def fetch_url(url: str, timeout: int = 20) -> bytes:
    req = urllib.request.Request(
        url,
        headers={
            "User-Agent": "KurageEntertainmentBot/1.0 (+https://kurage.exbridge.jp/)",
            "Accept": "application/rss+xml, application/xml, text/xml",
        },
    )
    with urllib.request.urlopen(req, timeout=timeout) as res:
        return res.read()


def google_news_rss_url(query: str) -> str:
    params = urllib.parse.urlencode({
        "q": query,
        "hl": "ja",
        "gl": "JP",
        "ceid": "JP:ja",
    })
    return "https://news.google.com/rss/search?" + params


def parse_google_news_items(xml_bytes: bytes) -> list[dict[str, Any]]:
    root = ET.fromstring(xml_bytes)
    items: list[dict[str, Any]] = []
    for item in root.findall("./channel/item"):
        title = html.unescape((item.findtext("title") or "").strip())
        link = (item.findtext("link") or "").strip()
        pub_date = (item.findtext("pubDate") or "").strip()
        source_el = item.find("source")
        source_name = html.unescape((source_el.text or "").strip()) if source_el is not None else "Google News"
        published_at = ""
        if pub_date:
            try:
                dt = email.utils.parsedate_to_datetime(pub_date)
                published_at = dt.astimezone(timezone(timedelta(hours=9))).strftime("%Y-%m-%d %H:%M:%S")
            except Exception:
                published_at = pub_date
        if title and link:
            items.append({
                "title": title,
                "url": link,
                "source_name": source_name,
                "published_at": published_at,
            })
    return items


def load_articles(path: Path = ARTICLES_PATH) -> list[dict[str, Any]]:
    if not path.exists():
        return []
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return data if isinstance(data, list) else []
    except Exception:
        return []


def save_articles(articles: list[dict[str, Any]], path: Path = ARTICLES_PATH) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    articles = sorted(articles, key=lambda a: a.get("created_at") or "", reverse=True)
    path.write_text(json.dumps(articles[:500], ensure_ascii=False, indent=2), encoding="utf-8")


def is_safe_headline(title: str) -> bool:
    return not any(word in title for word in NG_WORDS)


def is_recent_item(item: dict[str, Any], max_age_days: int = MAX_SOURCE_AGE_DAYS) -> bool:
    published_at = str(item.get("published_at") or "")
    if not published_at:
        return False
    try:
        dt = datetime.strptime(published_at, "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone(timedelta(hours=9)))
    except Exception:
        return False
    return dt >= datetime.now(timezone(timedelta(hours=9))) - timedelta(days=max_age_days)


def extract_celebrity_names(title: str) -> list[str]:
    quoted = re.findall(r"[「『]([A-Za-z0-9][A-Za-z0-9 ._+-]{1,24})[」』]", title)
    cleaned = re.sub(r"【[^】]+】|『[^』]+』|「[^」]+」|\([^)]*\)|（[^）]*）", " ", title)
    candidates: list[str] = []
    for name in quoted:
        name = name.strip()
        if name and name.upper() not in {c.upper() for c in candidates}:
            candidates.append(name)
    patterns = [
        r"([一-龥]{2,4}\s?[一-龥]{1,4})(?:さん|氏|、|が|は|の|に|と|で)",
        r"([ァ-ヴー]{2,12}(?:・[ァ-ヴー]{2,12}){1,3})(?:さん|氏|、|が|は|の|に|と|で|:|：)",
        r"(?:俳優|女優|歌手|タレント|モデル|アイドル|声優|芸人)の([一-龥ぁ-んァ-ヶA-Za-z][一-龥ぁ-んァ-ヶA-Za-z・ー]{1,12})",
    ]
    for pattern in patterns:
        for match in re.findall(pattern, cleaned):
            name = str(match).strip(" ・ー")
            if len(name) < 2 or name in GENERIC_WORDS:
                continue
            if any(word in name for word in GENERIC_WORDS):
                continue
            if name not in candidates:
                candidates.append(name)
    for match in re.findall(r"\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+){1,2})\b", cleaned):
        parts = match.split()
        if any(part in ENGLISH_GENERIC_WORDS for part in parts):
            continue
        if match not in candidates:
            candidates.append(match)
    return candidates[:3]


def extract_job_celebrity_names(job: dict[str, Any]) -> list[str]:
    """Use stricter extraction for video jobs so generic narration is not treated as a person."""
    title = re.sub(r"\s+", " ", str(job.get("title") or "")).strip()
    author_name = re.sub(r"\s+", " ", str(job.get("tweet_author_name") or "")).strip()
    search_text = "\n".join(p for p in [author_name, title] if p)
    candidates: list[str] = []
    patterns = [
        r"([ァ-ヴー]{2,12}(?:・[ァ-ヴー]{2,12}){1,3})(?:さん|氏|、|:|：|$)",
        r"([一-龥]{2,6}(?:\s?[一-龥]{1,4})?)(?:さん|氏)",
        r"([一-龥]{2,5})(?:が語る|が明かす|が出演)",
        r"\b(Mr\.?\s?Beast|MrBeast)\b",
        r"\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+){1,2})\b(?:\s+(?:says|said|reveals|on|at)|:)",
    ]
    for pattern in patterns:
        for match in re.findall(pattern, search_text):
            name = str(match).strip(" ・ーさん氏")
            if name.lower().replace(" ", "") == "mrbeast":
                name = "MrBeast"
            parts = name.split()
            if len(name) < 2 or name in GENERIC_WORDS:
                continue
            if any(word in name for word in GENERIC_WORDS):
                continue
            if any(part in ENGLISH_GENERIC_WORDS for part in parts):
                continue
            if name not in candidates:
                candidates.append(name)
    preferred: list[str] = []
    for name in sorted(candidates, key=len, reverse=True):
        if any(name != other and name in other for other in preferred):
            continue
        preferred.append(name)
    return preferred[:3]


def article_title(source_title: str, names: list[str]) -> str:
    if names:
        return f"{names[0]}さんの話題から見る関連作品とKurage活用メモ"
    return "今日の芸能ニュースから見る関連作品とKurage活用メモ"


def amazon_keyword(source_title: str, names: list[str]) -> str:
    if names:
        return f"{names[0]} 出演作 写真集 本 映画 ドラマ"
    words = re.sub(r"[^\w一-龥ぁ-んァ-ヶー]+", " ", source_title)
    return (words[:80] + " 関連作品 本").strip()


def make_article(item: dict[str, Any]) -> dict[str, Any]:
    source_title = item["title"]
    names = extract_celebrity_names(source_title)
    slug = slugify(item["url"] or source_title)
    page_url = f"{KURAGE_BASE}/entertainment.php?id={urllib.parse.quote(slug)}"
    kw = amazon_keyword(source_title, names)
    amazon_url = GO_BASE + "?" + urllib.parse.urlencode({
        "to": "amazon",
        "kw": kw,
        "from": f"/entertainment.php?id={slug}",
    })
    title = article_title(source_title, names)
    person_label = "、".join(names) if names else "話題の作品"
    summary = (
        f"{source_title}という芸能ニュースをきっかけに、{person_label}への検索流入を"
        "KurageのAI動画・記事回遊につなげるための安全な関連記事です。"
    )
    body = [
        f"今日の芸能ニュースでは「{source_title}」が注目されています。",
        (
            "Kurageでは、単に速報を追うだけでなく、話題になった人物や作品名から"
            "関連作品、原作、書籍、映像制作の学びへ自然に回遊できる記事として整理します。"
        ),
        (
            "この記事内のAmazonリンクは、本人の推奨・愛用を示すものではありません。"
            "ニュースのテーマに近い作品や資料を探すための関連リンクです。"
        ),
        (
            "さらに、この話題は30秒程度のKurageショート動画に変換し、記事から動画へ、"
            "動画から記事へ戻る導線を作ることでKurage全体の知名度向上を狙います。"
        ),
    ]
    video_script = [
        "話題の芸能ニュースを30秒で確認。",
        f"今日の注目は、{person_label}に関するニュースです。",
        "背景には作品、番組、SNS上の関心があります。",
        "Kurageでは関連作品や資料も安全に整理します。",
        "Amazonリンクは関連テーマの検索導線です。",
        "詳しい記事とAI動画はKurageでチェック。",
    ]
    return {
        "slug": slug,
        "title": title,
        "source_title": source_title,
        "source_url": item["url"],
        "source_name": item.get("source_name") or "Google News",
        "source_published_at": item.get("published_at") or "",
        "created_at": now_jst(),
        "updated_at": now_jst(),
        "celebrity_names": names,
        "summary": summary,
        "body": body,
        "amazon_kw": kw,
        "amazon_url": amazon_url,
        "kurage_url": page_url,
        "kurage_cta_url": "/kurage.php",
        "video_cta_url": "/horizon.php",
        "video_script_30s": video_script,
        "video_job_id": "",
        "status": "published",
        "safety_note": "本人の推奨・愛用・広告出演を断定せず、関連作品・関連資料への導線として生成。",
    }


def job_id_from_path(path: Path, job: dict[str, Any]) -> str:
    return re.sub(r"[^a-zA-Z0-9]", "", str(job.get("job_id") or path.stem))


def job_view_file(job: dict[str, Any]) -> str:
    source = str(job.get("source") or "")
    return "horizonv.php" if source == "horizon" else "kuragev.php"


def job_search_text(job: dict[str, Any]) -> str:
    parts = [
        str(job.get("title") or ""),
        str(job.get("tweet_author_name") or ""),
        str(job.get("tweet_author") or ""),
        str(job.get("tweet_text") or ""),
    ]
    script = job.get("script")
    if isinstance(script, dict):
        parts.append(str(script.get("title") or ""))
        for scene in script.get("scenes") or []:
            if isinstance(scene, dict):
                parts.append(str(scene.get("narration") or ""))
    return "\n".join(p for p in parts if p)


def load_done_jobs(limit: int = 300) -> list[tuple[Path, dict[str, Any]]]:
    if not JOBS_DIR.exists():
        return []
    files = sorted(JOBS_DIR.glob("*.json"), key=lambda p: p.stat().st_mtime, reverse=True)
    jobs: list[tuple[Path, dict[str, Any]]] = []
    for path in files[:limit]:
        try:
            job = json.loads(path.read_text(encoding="utf-8"))
        except Exception:
            continue
        if job.get("status") != "done":
            continue
        jobs.append((path, job))
    return jobs


def make_article_from_job(path: Path, job: dict[str, Any], names: list[str]) -> dict[str, Any]:
    jid = job_id_from_path(path, job)
    file_name = job_view_file(job)
    video_page = f"{KURAGE_BASE}/{file_name}?id={urllib.parse.quote(jid)}"
    source_title = str(job.get("title") or job.get("tweet_author_name") or "Kurage生成動画")
    kw = amazon_keyword(source_title, names)
    slug = slugify("kurage-job:" + jid)
    person_label = "、".join(names)
    amazon_url = GO_BASE + "?" + urllib.parse.urlencode({
        "to": "amazon",
        "kw": kw,
        "from": f"/entertainment.php?id={slug}",
    })
    summary = (
        f"Kurageで生成された動画「{source_title}」をきっかけに、{person_label}への検索流入を"
        "公開動画、関連記事、Amazon関連作品導線へつなげるための自動生成コンテンツです。"
    )
    body = [
        f"Kurageに新しい動画「{source_title}」が追加されました。",
        (
            f"この動画には、{person_label}に関連する話題が含まれています。"
            "Kurage Entertainmentでは、動画で関心を持った人が関連作品や資料へ自然に進めるよう整理します。"
        ),
        (
            "Amazonリンクは、本人の推奨・愛用・広告出演を示すものではありません。"
            "動画テーマに近い作品や資料を探すための関連リンクです。"
        ),
        (
            "記事からKurage動画へ、動画から記事へ戻る導線を作ることで、"
            "芸能人名・著名人名の検索流入をKurage全体の認知につなげます。"
        ),
    ]
    video_script = [
        "Kurageに新しい動画が追加されました。",
        f"今回の注目は、{person_label}に関する話題です。",
        "動画の内容を短く整理して確認できます。",
        "関連作品や資料も安全に探せます。",
        "Amazonリンクは関連テーマの検索導線です。",
        "詳しい記事と動画はKurageでチェック。",
    ]
    return {
        "slug": slug,
        "title": article_title(source_title, names),
        "source_title": source_title,
        "source_url": video_page,
        "source_name": "Kurage Video",
        "source_published_at": job.get("created_at") or "",
        "created_at": now_jst(),
        "updated_at": now_jst(),
        "celebrity_names": names,
        "summary": summary,
        "body": body,
        "amazon_kw": kw,
        "amazon_url": amazon_url,
        "kurage_url": f"{KURAGE_BASE}/entertainment.php?id={urllib.parse.quote(slug)}",
        "kurage_cta_url": "/kuragev.php",
        "video_cta_url": "/" + file_name,
        "video_script_30s": video_script,
        "video_job_id": jid,
        "status": "published",
        "safety_note": "本人の推奨・愛用・広告出演を断定せず、Kurage生成動画から関連作品・関連資料への導線として生成。",
    }


def published_today_count(articles: list[dict[str, Any]]) -> int:
    today = now_jst()[:10]
    return sum(1 for a in articles if str(a.get("created_at", "")).startswith(today))


def run_once(target_per_day: int, max_new: int, query: str, dry_run: bool = False) -> dict[str, Any]:
    articles = load_articles()
    existing_slugs = {a.get("slug") for a in articles}
    existing_sources = {a.get("source_url") for a in articles}
    remaining = max(0, target_per_day - published_today_count(articles))
    limit = min(max_new, remaining)
    result = {"target_per_day": target_per_day, "remaining_today": remaining, "created": 0, "created_from_jobs": 0, "skipped": 0, "articles": []}
    if limit <= 0:
        return result

    new_articles: list[dict[str, Any]] = []
    for path, job in load_done_jobs():
        if str(job.get("source") or "") not in JOB_VIDEO_SOURCES:
            result["skipped"] += 1
            continue
        jid = job_id_from_path(path, job)
        source_url = f"{KURAGE_BASE}/{job_view_file(job)}?id={urllib.parse.quote(jid)}"
        slug = slugify("kurage-job:" + jid)
        if slug in existing_slugs or source_url in existing_sources:
            result["skipped"] += 1
            continue
        text = job_search_text(job)
        if not is_safe_headline(text):
            result["skipped"] += 1
            continue
        names = extract_job_celebrity_names(job)
        if not names:
            result["skipped"] += 1
            continue
        article = make_article_from_job(path, job, names)
        new_articles.append(article)
        existing_slugs.add(article["slug"])
        existing_sources.add(article["source_url"])
        result["created_from_jobs"] += 1
        result["articles"].append({"slug": article["slug"], "title": article["title"], "source": "job", "job_id": jid})
        if len(new_articles) >= limit:
            break

    remaining_after_jobs = max(0, limit - len(new_articles))
    if remaining_after_jobs <= 0:
        result["created"] = len(new_articles)
        if not dry_run and new_articles:
            save_articles(new_articles + articles)
        return result

    items = parse_google_news_items(fetch_url(google_news_rss_url(query)))
    for item in items:
        slug = slugify(item["url"] or item["title"])
        if slug in existing_slugs or item["url"] in existing_sources:
            result["skipped"] += 1
            continue
        if not is_safe_headline(item["title"]):
            result["skipped"] += 1
            continue
        if not is_recent_item(item):
            result["skipped"] += 1
            continue
        if not extract_celebrity_names(item["title"]):
            result["skipped"] += 1
            continue
        article = make_article(item)
        new_articles.append(article)
        result["articles"].append({"slug": article["slug"], "title": article["title"]})
        if len(new_articles) >= limit:
            break

    result["created"] = len(new_articles)
    if not dry_run and new_articles:
        save_articles(new_articles + articles)
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description="Kurage entertainment news article pipeline")
    parser.add_argument("--target-per-day", type=int, default=DEFAULT_TARGET_PER_DAY)
    parser.add_argument("--max-new", type=int, default=3)
    parser.add_argument("--query", default=DEFAULT_QUERY)
    parser.add_argument("--loop", action="store_true", help="Run forever, sleeping between cycles.")
    parser.add_argument("--interval", type=int, default=300)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    while True:
        result = run_once(args.target_per_day, args.max_new, args.query, args.dry_run)
        print(json.dumps(result, ensure_ascii=False, indent=2))
        if not args.loop:
            break
        time.sleep(max(60, args.interval))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
