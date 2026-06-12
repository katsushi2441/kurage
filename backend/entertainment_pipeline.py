"""Entertainment news article pipeline for Kurage.

Collects public entertainment-news headlines and creates safe original Kurage
articles with related reference links.
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
DEFAULT_VIDEO_API = "http://127.0.0.1:18303"

NG_WORDS = (
    "逮捕", "容疑", "起訴", "不起訴", "不倫", "浮気", "離婚", "訃報", "死去",
    "暴露", "炎上", "謝罪", "被害", "加害", "薬物", "違法", "疑惑", "裁判",
    "交際", "熱愛", "嫉妬", "報酬", "年俸", "大公開", "がん", "病気", "闘病",
    "トラブル",
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
    "代償", "昔", "人生", "再構築", "最新", "テクノロジー", "新予告",
    "日本版", "ポスター", "創価大学", "大学", "往復書簡", "対談", "身体性",
    "魅力", "新番組", "静寂", "狂気", "アクション", "ドラマ",
    "実在人物", "人物", "保育園",
    "グループ", "人気アイドルグループ", "アーティスト", "タレントマネジメント",
    "業務開始", "お知らせ", "ジェリービーンズグループ",
}

ENGLISH_GENERIC_WORDS = {
    "AI", "API", "CEO", "CTO", "CFO", "USA", "US", "UK", "Google", "OpenAI",
    "Kurage", "Horizon", "Amazon", "YouTube", "Twitter", "X", "News",
    "Video", "Blog", "Voice", "Pro", "Project", "Agent", "Code", "Data",
    "Canada", "Strong", "America", "Great", "Again", "Banking", "Homebrew",
    "NEW", "GROUP", "WOOAH",
}

JOB_VIDEO_SOURCES = {"kuragevp", "horizon", "blog"}
NON_PERSON_PARTS = (
    "大学", "高校", "中学", "学校", "番組", "予告", "ポスター", "映画",
    "書簡", "対談", "ドラマ", "アクション", "ニュース", "魅力", "身体性",
    "新作", "公開", "解禁", "日本版", "公式", "作品", "企業", "会社",
    "選手", "監督", "主演", "発覚", "発言", "状態", "報道", "衝撃",
    "大物俳優", "Festival", "FESTIVAL", "Period", "Hatsuboshi", "Studios", "Century",
    "披露宴", "出席",
    "挿入歌", "洋画", "独占", "興行", "全体",
    "実在人物", "保育園", "園児", "子ども", "先生",
    "グループ", "業務開始", "お知らせ", "マネジメント",
)


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


def post_json(url: str, payload: dict[str, Any], timeout: int = 15) -> dict[str, Any]:
    data = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(
        url,
        data=data,
        headers={
            "Content-Type": "application/json; charset=utf-8",
            "Accept": "application/json",
            "User-Agent": "KurageEntertainmentBot/1.0 (+https://kurage.exbridge.jp/)",
        },
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=timeout) as res:
        body = res.read().decode("utf-8", errors="replace")
    parsed = json.loads(body)
    return parsed if isinstance(parsed, dict) else {}


def enqueue_pending_videos(api_base: str, max_videos: int) -> dict[str, Any]:
    """Queue short-video generation for published articles that do not have a video job."""
    articles = load_articles()
    result: dict[str, Any] = {"queued": 0, "skipped": 0, "errors": [], "jobs": []}
    if max_videos <= 0:
        return result
    api_base = api_base.rstrip("/")
    changed = False
    for article in articles:
        if result["queued"] >= max_videos:
            break
        if str(article.get("status") or "published") != "published":
            result["skipped"] += 1
            continue
        if article.get("video_job_id"):
            result["skipped"] += 1
            continue
        payload = {
            "title": article.get("title") or article.get("source_title") or "芸能ニュース考察",
            "summary": article.get("summary") or "",
            "content": "\n".join(article.get("body") or []),
            "url": article.get("kurage_url") or "",
            "source_url": article.get("source_url") or "",
            "source_name": article.get("source_name") or "Kurage Entertainment",
            "celebrity_names": article.get("celebrity_names") or [],
        }
        try:
            response = post_json(f"{api_base}/generate_entertainment_short", payload)
            job_id = str(response.get("job_id") or "")
            if not response.get("ok") or not job_id:
                raise RuntimeError(f"unexpected response: {response}")
            article["video_job_id"] = job_id
            article["video_status"] = "queued"
            article["video_queued_at"] = now_jst()
            article["updated_at"] = now_jst()
            result["queued"] += 1
            result["jobs"].append({"slug": article.get("slug"), "job_id": job_id, "title": article.get("title")})
            changed = True
        except Exception as exc:
            result["errors"].append({"slug": article.get("slug"), "error": str(exc)})
    if changed:
        save_articles(articles)
    return result


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


def normalize_person_candidate(value: str) -> str:
    name = re.sub(r"\s+", " ", str(value)).strip(" ・ーさん氏")
    name = re.sub(r"^(故|故人|元)[・\s]+", "", name)
    if " " in name:
        parts = [p.strip(" ・ー") for p in name.split() if p.strip(" ・ー")]
        if len(parts) >= 2 and parts[0] in GENERIC_WORDS:
            name = parts[-1]
    return name


def looks_like_person_name(name: str) -> bool:
    name = normalize_person_candidate(name)
    if len(name) < 2:
        return False
    if name in GENERIC_WORDS:
        return False
    if name.upper() in ENGLISH_GENERIC_WORDS:
        return False
    if any(part in name for part in NON_PERSON_PARTS):
        return False
    if len(name) > 6 and re.search(r"[のにへがをで]", name):
        return False
    if any(word in name for word in GENERIC_WORDS):
        return False
    if re.fullmatch(r"[一-龥]{2,8}", name):
        return True
    if re.fullmatch(r"[ぁ-んァ-ヶ一-龥A-Za-z][ぁ-んァ-ヶ一-龥A-Za-z・ー]{1,18}", name):
        return True
    if re.fullmatch(r"[A-Za-z][A-Za-z .'-]{1,30}", name):
        parts = name.replace(".", " ").replace("-", " ").split()
        return not any(part in ENGLISH_GENERIC_WORDS for part in parts)
    return False


def extract_celebrity_names(title: str) -> list[str]:
    quoted = re.findall(r"[「『]([A-Za-z0-9][A-Za-z0-9 ._+-]{1,24})[」』]", title)
    cleaned = re.sub(r"【[^】]+】|『[^』]+』|「[^」]+」|\([^)]*\)|（[^）]*）", " ", title)
    candidates: list[str] = []
    for name in quoted:
        name = normalize_person_candidate(name)
        if looks_like_person_name(name) and name.upper() not in {c.upper() for c in candidates}:
            candidates.append(name)
    patterns = [
        r"^([一-龥ァ-ヴー・ー]{2,12})(?:[「、]|さん|氏)",
        r"^([一-龥]{2,6})(?:\s)",
        r"・([一-龥]{2,6})(?:[「、]|さん|氏)",
        r"([一-龥ァ-ヴー・ー]{2,12})(?:さん|氏)",
        r"([一-龥]{2,4}\s?[一-龥]{1,4})(?:さん|氏|、|が|は|の|に|と|で)",
        r"([ァ-ヴー]{2,12}(?:・[ァ-ヴー]{2,12}){1,3})(?:さん|氏|、|が|は|の|に|と|で|:|：)",
        r"(?:俳優|女優|歌手|タレント|モデル|アイドル|声優|芸人)の([一-龥ァ-ヶA-Za-z][一-龥ァ-ヶA-Za-z・ー]{1,12})(?:さん|氏|、|が|は|に|と|で|「|:|：)",
    ]
    for pattern in patterns:
        for match in re.findall(pattern, cleaned):
            name = normalize_person_candidate(str(match))
            if not looks_like_person_name(name):
                continue
            if name not in candidates:
                candidates.append(name)
    for match in re.findall(r"\b([A-Z][a-z]+(?:\s+[A-Z][a-z]+){1,2})\b", cleaned):
        if not looks_like_person_name(match):
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
            name = normalize_person_candidate(str(match))
            if name.lower().replace(" ", "") == "mrbeast":
                name = "MrBeast"
            parts = name.split()
            if not looks_like_person_name(name) or any(part in ENGLISH_GENERIC_WORDS for part in parts):
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
    content = article_content(source_title, names)
    return content["title"]


def compact_title(title: str, limit: int = 46) -> str:
    title = re.sub(r"\s+", " ", str(title)).strip(" -｜|。")
    return title if len(title) <= limit else title[:limit].rstrip() + "…"


def clean_source_title(source_title: str) -> str:
    """Keep the actual news topic while removing distributor boilerplate."""
    title = re.sub(r"\s+", " ", str(source_title)).strip()
    title = re.sub(r"\s*-\s*(Yahoo!ニュース|Google ニュース|Google News)\s*$", "", title)
    title = re.sub(r"（[^）]{1,24}）\s*$", "", title)
    title = re.sub(r"\s*-\s*[^-]{2,30}$", "", title)
    return title.strip(" -｜|。") or str(source_title).strip()


def news_summary_sentence(source_title: str, names: list[str]) -> str:
    clean = clean_source_title(source_title)
    label = "、".join(names) if names else "話題の人物"
    if len(clean) > 78:
        clean = clean[:78].rstrip() + "..."
    return f"今回取り上げるのは「{clean}」というニュースです。中心にいるのは{label}で、見出しからは発表・出演・発言・作品情報のどこに注目が集まっているのかが分かります。"


def commentary_sentence(source_title: str, name: str, angle: dict[str, str]) -> str:
    title = str(source_title)
    if any(word in title for word in ("出演", "キャスト", "主演", "映画", "ドラマ", "公開")):
        return f"{name}の名前が作品ニュースの中で出てくるときは、単なる出演情報だけでなく、その作品がどんな層に届こうとしているのか、キャスティングで何を伝えたいのかを見ると読みやすくなります。"
    if any(word in title for word in ("発言", "語る", "明かす", "対談", "インタビュー", "手紙")):
        return f"{name}の発言や対談が話題になるのは、言葉そのものに加えて、これまでの活動イメージとの違いや、いま本人が何を大事にしているかが見えるからです。"
    if any(word in title for word in ("写真", "ショット", "再会", "披露宴", "イベント")):
        return f"写真やイベントのニュースは一瞬で消費されがちですが、反応が集まる背景には、過去の共演、ファンの記憶、作品への思い入れが重なっています。"
    if angle["label"] == "AI時代の読み解き":
        return "AI関連の発言は、過激な見出しだけで判断せず、技術が実際の仕事や生活にどう入り込むのかまで分けて読む必要があります。"
    return f"{name}のニュースとして読むだけでなく、作品、発言、時期、メディアでの見え方を分けると、なぜこの話題が今出てきたのかを立体的に理解できます。"


def quoted_work_title(source_title: str) -> str:
    for value in re.findall(r"[「『]([^」』]{2,40})[」』]", str(source_title)):
        value = value.strip()
        if value.endswith(("です", "ます")) or "？" in value or "?" in value:
            continue
        return value
    return ""


def source_based_headline(source_title: str, name: str) -> str:
    clean = clean_source_title(source_title)
    work = quoted_work_title(clean)
    if "出演決定" in clean:
        return f"{name}の出演決定ニュース：{('「' + work + '」') if work else '新作'}で何が動いたか"
    if "追加キャスト" in clean or "キャスト" in clean:
        return f"{name}の参加で見える{('「' + work + '」') if work else '作品ニュース'}の狙い"
    if "挿入歌" in clean or "新曲" in clean:
        return f"{name}の新曲・挿入歌ニュースから作品の余韻を読む"
    if "ゲスト声優" in clean or "声優" in clean:
        return f"{name}の声優参加ニュース：{('「' + work + '」') if work else '作品'}の注目点"
    if "公開" in clean and work:
        return f"{name}と「{work}」公開ニュース：注目点を整理"
    if "不思議な映画" in clean and work:
        return f"{name}が語る「{work}」の不思議さを読む"
    if "手紙" in clean:
        return f"{name}の手紙企画に見る、言葉で届く魅力"
    if "対談" in clean:
        return f"{name}の対談ニュースから見えるテーマ"
    if "番組出演" in clean or "出演" in clean:
        return f"{name}の出演ニュースから見える注目点"
    if "2ショット" in clean or "ショット" in clean:
        return f"{name}の写真ニュースに反応が集まる理由"
    if "語る" in clean or "明かす" in clean:
        return f"{name}の発言ニュースを読み解く"
    topic = clean
    if topic.startswith(name):
        topic = topic[len(name):].strip(" 、,:：")
    topic = topic.lstrip("の").strip(" 、,:：")
    topic = re.sub(r"^(さん|氏)\s*", "", topic)
    topic = compact_title(topic, 26)
    return f"{name}のニュース：{topic}を読む"


def article_angle(source_title: str) -> dict[str, str]:
    title = str(source_title)
    if any(word in title for word in ("MIXI", "市場", "経営", "戦略", "ゲーム")):
        return {
            "label": "経営考察",
            "question": "ヒットを作った経営者の発言から、今の市場で再現できる考え方は何か。",
            "insight": "発言の面白さは、個人の経験談に見えて、実際には市場の見方やプロダクト運営の思想がにじんでいる点にあります。",
            "commerce": "この話題をさらに理解したい場合は、ゲームビジネス、経営戦略、マーケティング関連の本も参考になります。",
            "headline": "{name}が語る市場戦略：ヒットの裏側を読む",
        }
    if any(word in title for word in ("採用", "人材", "才能", "リクルート")):
        return {
            "label": "人材戦略",
            "question": "なぜ優秀な人ほど、報酬だけではなくミッションや裁量に反応するのか。",
            "insight": "この話題の核心は、有名人の発言そのものよりも、組織が人を惹きつける条件が変わっている点にあります。",
            "commerce": "採用・経営・リーダーシップの視点で読むと、発言の背景がより立体的に見えてきます。",
            "headline": "{name}の採用論：優秀な人材が動く条件",
        }
    if any(word in title for word in ("AI", "Grok", "OpenAI", "コンピューティング", "バブル", "Google")):
        return {
            "label": "AI時代の読み解き",
            "question": "AIの進化は、仕事・健康・ビジネスモデルのどこを先に変えるのか。",
            "insight": "派手な発言だけを追うと消費されて終わりますが、技術の使われ方まで見ると、次の需要や学ぶべきテーマが見えてきます。",
            "commerce": "AIビジネス書や技術入門をあわせて読むと、発言のインパクトを一時的なニュースで終わらせず理解できます。",
            "headline": "{name}発言から読むAI時代の次の焦点",
        }
    if any(word in title for word in ("億万長者", "YouTube", "動画", "チャンネル", "散財", "休日")):
        return {
            "label": "クリエイター経済",
            "question": "トップクリエイターの言葉から、動画制作や発信者ビジネスの何を学べるのか。",
            "insight": "このニュースは単なるゴシップではなく、個人がメディア化する時代の働き方、投資判断、発信技術を考える材料になります。",
            "commerce": "動画編集、撮影機材、YouTube運営の知識とあわせて見ると、発言者の行動原理も読み解きやすくなります。",
            "headline": "{name}の金銭感覚と動画ビジネスのリアル",
        }
    if any(word in title for word in ("CD", "歌", "歌手", "ベスト", "ライブ", "出演", "ドラマ", "映画")):
        return {
            "label": "作品考察",
            "question": "ニュースで名前を見かけたとき、過去の作品や音源をどう見直せるのか。",
            "insight": "芸能ニュースは、その人の過去作品や役柄を思い出しながら読むと、短い話題でも受け止め方が変わります。",
            "commerce": "CD、DVD、写真集、出演作などを見直すと、その人物が長く記憶されている理由も見えてきます。",
            "headline": "{name}の作品ニュースを読み解く",
        }
    return {
        "label": "話題の背景",
        "question": "このニュースは、名前の検索だけで終わらせず何を読み取れるのか。",
        "insight": "一見すると短いニュースでも、人物名、作品名、発言の背景を分けて見ると、なぜ話題になったのかが見えてきます。",
        "commerce": "関連する作品や資料を確認すると、ニュースだけでは見えない背景を補いやすくなります。",
        "headline": "{name}発言の背景：話題化した理由を整理",
    }


def article_content(source_title: str, names: list[str], origin: str = "news") -> dict[str, Any]:
    name = names[0] if names else "この話題"
    label = "、".join(names) if names else "話題の人物"
    short = compact_title(source_title)
    clean_title = clean_source_title(source_title)
    angle = article_angle(source_title)
    title = source_based_headline(source_title, name) or specific_headline(source_title, name) or angle["headline"].format(name=name)
    if origin == "job":
        lead = f"動画・投稿として追加された「{clean_title}」をもとに、{label}について{angle['label']}の視点から整理します。"
    else:
        lead = f"ニュース「{clean_title}」をもとに、{label}の話題を整理します。"
    summary = f"{lead} 何のニュースなのか、なぜ注目されるのか、作品や発言の背景をあわせて考察します。"
    body = [
        news_summary_sentence(source_title, names),
        f"まず押さえたいのは、見出しが伝えている主役は{label}だけではなく、作品名、番組名、発言の場、公開時期といった文脈もセットになっている点です。人物名だけで検索すると話題の表面だけを追いがちですが、ニュースの意味はこの文脈にあります。",
        commentary_sentence(source_title, name, angle),
        f"{angle['label']}として見ると、ポイントは「{angle['question']}」という問いです。{angle['insight']}",
        f"関連作品や資料を見るなら、本人の推奨や広告出演と混同せず、ニュースの背景を補うための参考として確認するのが自然です。{angle['commerce']}",
    ]
    video_script = [
        f"今日のテーマは、{name}に関する話題です。",
        short,
        "まず、何がニュースになったのかを整理します。",
        commentary_sentence(source_title, name, angle),
        "背景を見ると、単なる名前検索より理解が深まります。",
        "記事URLと元ニュースURLは説明欄で確認できます。",
    ]
    return {"title": title, "summary": summary, "body": body, "video_script": video_script}


def specific_headline(source_title: str, name: str) -> str:
    title = str(source_title)
    if "往復書簡" in title and "対談" in title:
        return f"{name}の対談から読む「身体性」というテーマ"
    if "再会" in title and ("2ショット" in title or "ショット" in title):
        return f"{name}の再会ショットが話題：ファンが反応した理由"
    if "ドラマ制作" in title and "細木数子" in title:
        return f"{name}が語るドラマ構想：細木数子邸エピソードの余韻"
    if "番組出演" in title and "後輩" in title:
        return f"{name}の番組出演が話題：後輩との関係性を読む"
    if "歌謡祭" in title or "ベスト" in title:
        return f"{name}の歌声を聴き直す：歌謡ニュースの余韻"
    if "2ショット" in title:
        return f"{name}の2ショットが話題：写真に集まる反応を読む"
    if "披露宴" in title:
        return f"{name}が披露宴で見せた交友関係の広がり"
    if "隠れた" in title or "知性派" in title:
        return f"{name}の意外な一面：バラエティの印象を読み直す"
    if "Grok" in title or "血液検査" in title:
        return f"{name}のGrok医療構想：AIは健康管理を変えるのか"
    if "危険性" in title or "眠れ" in title:
        return f"{name}が語るAIリスク：不安と期待の境界線"
    if "Google" in title and "OpenAI" in title:
        return f"{name}が語るGoogleとOpenAI：AI競争の分岐点"
    if "バブル" in title:
        return f"{name}が見るAIバブル論：熱狂の奥にある本質"
    if "コンピューティング層" in title or "インテリジェンス" in title:
        return f"{name}が描く知能生成の未来：NVIDIA時代の読み方"
    if "無制限の大量移民" in title or "移民" in title:
        return f"{name}発言の波紋：国家観とSNS時代の拡散力"
    if "最大の散財" in title:
        return f"{name}の散財エピソードから見る成功者の時間価値"
    if "億万長者" in title:
        return f"{name}の金銭感覚：若くして成功した発信者のリアル"
    if "休日" in title:
        return f"{name}の仕事観：休まない戦略は再現できるのか"
    if "AIアプリケーションレイヤー" in title:
        return f"{name}が語るAIアプリ市場：次の勝ち筋を読む"
    if "市場戦略" in title:
        return f"{name}の市場戦略：モンスト後の勝ち筋を考える"
    if "採用" in title or "人材" in title:
        return f"{name}の採用論：優秀な人材が動く条件"
    return ""


def amazon_keyword(source_title: str, names: list[str]) -> str:
    params = amazon_link_params(source_title, names)
    return params["kw"]


def amazon_link_params(source_title: str, names: list[str]) -> dict[str, str]:
    name = names[0] if names else ""
    music_names = {"西城秀樹", "松田聖子", "山口百恵", "DA PUMP"}
    video_creator_names = {"MrBeast", "ローガン・ポール"}
    if name in music_names:
        return {"kw": f"{name} ベスト CD DVD", "cat": "music"}
    if name in {"道枝駿佑", "森脇健児"}:
        return {"kw": f"{name} DVD 写真集", "cat": "dvd"}
    if name in {"イーロン・マスク", "ビル・ゲイツ"}:
        return {"kw": f"{name} 本", "cat": "books"}
    if name in {"サム・アルトマン", "ジェンセン・ファン"}:
        return {"kw": "AI ビジネス書 起業 経営", "cat": "ai"}
    if name == "木村弘毅":
        return {"kw": "ゲームビジネス 経営 戦略 本", "cat": "business"}
    if name in video_creator_names:
        return {"kw": "動画編集 撮影機材 YouTube 本", "cat": "video"}
    if name:
        return {"kw": f"{name} 本 DVD CD", "cat": "books"}
    words = re.sub(r"[^\w一-龥ぁ-んァ-ヶー]+", " ", source_title)
    kw = (words[:60] + " 関連作品 本").strip()
    return {"kw": kw, "cat": "books"}


def make_article(item: dict[str, Any]) -> dict[str, Any]:
    source_title = item["title"]
    names = extract_celebrity_names(source_title)
    slug = slugify(item["url"] or source_title)
    page_url = f"{KURAGE_BASE}/entertainment.php?id={urllib.parse.quote(slug)}"
    amazon_params = amazon_link_params(source_title, names)
    kw = amazon_params["kw"]
    amazon_url = GO_BASE + "?" + urllib.parse.urlencode({
        "to": "amazon",
        "kw": kw,
        "cat": amazon_params["cat"],
        "from": f"/entertainment.php?id={slug}",
    })
    content = article_content(source_title, names, "news")
    content["video_script"][-1] = f"記事URL: {page_url}"
    content["video_script"].append(f"元ニュースURL: {item['url']}")
    return {
        "slug": slug,
        "title": content["title"],
        "source_title": source_title,
        "source_url": item["url"],
        "source_name": item.get("source_name") or "Google News",
        "source_published_at": item.get("published_at") or "",
        "created_at": now_jst(),
        "updated_at": now_jst(),
        "celebrity_names": names,
        "summary": content["summary"],
        "body": content["body"],
        "amazon_kw": kw,
        "amazon_url": amazon_url,
        "kurage_url": page_url,
        "kurage_cta_url": "/kurage.php",
        "video_cta_url": "/horizon.php",
        "video_script_30s": content["video_script"],
        "video_job_id": "",
        "status": "published",
        "safety_note": "関連リンクは、本人の推奨・愛用・広告出演を示すものではありません。",
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


def load_done_jobs(limit: int = 5000) -> list[tuple[Path, dict[str, Any]]]:
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
    amazon_params = amazon_link_params(source_title, names)
    kw = amazon_params["kw"]
    slug = slugify("kurage-job:" + jid)
    person_label = "、".join(names)
    amazon_url = GO_BASE + "?" + urllib.parse.urlencode({
        "to": "amazon",
        "kw": kw,
        "cat": amazon_params["cat"],
        "from": f"/entertainment.php?id={slug}",
    })
    content = article_content(source_title, names, "job")
    page_url = f"{KURAGE_BASE}/entertainment.php?id={urllib.parse.quote(slug)}"
    content["video_script"][-1] = f"記事URL: {page_url}"
    content["video_script"].append(f"元動画URL: {video_page}")
    return {
        "slug": slug,
        "title": content["title"],
        "source_title": source_title,
        "source_url": video_page,
        "source_name": "Kurage Video",
        "source_published_at": job.get("created_at") or "",
        "created_at": now_jst(),
        "updated_at": now_jst(),
        "celebrity_names": names,
        "summary": content["summary"],
        "body": content["body"],
        "amazon_kw": kw,
        "amazon_url": amazon_url,
        "kurage_url": page_url,
        "kurage_cta_url": "/kuragev.php",
        "video_cta_url": "/" + file_name,
        "video_script_30s": content["video_script"],
        "video_job_id": jid,
        "status": "published",
        "safety_note": "関連リンクは、本人の推奨・愛用・広告出演を示すものではありません。",
    }


def published_today_count(articles: list[dict[str, Any]]) -> int:
    today = now_jst()[:10]
    return sum(1 for a in articles if str(a.get("created_at", "")).startswith(today))


def run_once(target_per_day: int, max_new: int, query: str, dry_run: bool = False, jobs_only: bool = False) -> dict[str, Any]:
    articles = load_articles()
    existing_slugs = {a.get("slug") for a in articles}
    existing_sources = {a.get("source_url") for a in articles}
    existing_job_titles = {
        re.sub(r"\s+", " ", str(a.get("source_title") or "")).strip()
        for a in articles
        if a.get("source_name") == "Kurage Video" and a.get("source_title")
    }
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
        source_title = re.sub(r"\s+", " ", str(job.get("title") or job.get("tweet_author_name") or "")).strip()
        if slug in existing_slugs or source_url in existing_sources or source_title in existing_job_titles:
            result["skipped"] += 1
            continue
        safety_text = "\n".join(
            str(job.get(key) or "") for key in ("title", "tweet_author_name", "tweet_author")
        )
        if not is_safe_headline(safety_text):
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
        existing_job_titles.add(source_title)
        result["created_from_jobs"] += 1
        result["articles"].append({"slug": article["slug"], "title": article["title"], "source": "job", "job_id": jid})
        if len(new_articles) >= limit:
            break

    remaining_after_jobs = max(0, limit - len(new_articles))
    if remaining_after_jobs <= 0 or jobs_only:
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
    parser.add_argument("--jobs-only", action="store_true", help="Only create articles from completed Kurage video jobs.")
    parser.add_argument("--auto-video", action="store_true", help="Queue short-video generation for articles without video jobs.")
    parser.add_argument("--video-api", default=DEFAULT_VIDEO_API)
    parser.add_argument("--max-videos", type=int, default=3)
    args = parser.parse_args()

    while True:
        result = run_once(args.target_per_day, args.max_new, args.query, args.dry_run, args.jobs_only)
        if args.auto_video and not args.dry_run:
            result["video_queue"] = enqueue_pending_videos(args.video_api, args.max_videos)
        print(json.dumps(result, ensure_ascii=False, indent=2))
        if not args.loop:
            break
        time.sleep(max(60, args.interval))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
