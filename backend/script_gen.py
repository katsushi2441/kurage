"""Generate short drama script + image prompts from tweet content using Ollama."""
from __future__ import annotations
import json
import re
import requests
from config import OLLAMA_URL, OLLAMA_MODEL
from video_styles import apply_video_style, resolve_video_style, style_prompt


SYSTEM_PROMPT = """You are a short vertical video scriptwriter. Based on an X (Twitter) post, generate an 8-scene short drama script with image prompts.

Return ONLY a JSON object. No markdown, no code blocks, no explanation. Start your response with { and end with }.

Required format:
{"title":"動画タイトル(30字以内)","scenes":[{"index":0,"narration":"ナレーション日本語(30字以内)","image_prompt":"English cinematic vertical 9:16 scene description under 80 chars","duration":5},{"index":1,"narration":"ナレーション","image_prompt":"English prompt","duration":5},{"index":2,"narration":"ナレーション","image_prompt":"English prompt","duration":5},{"index":3,"narration":"ナレーション","image_prompt":"English prompt","duration":5},{"index":4,"narration":"ナレーション","image_prompt":"English prompt","duration":5},{"index":5,"narration":"ナレーション","image_prompt":"English prompt","duration":5},{"index":6,"narration":"ナレーション","image_prompt":"English prompt","duration":5},{"index":7,"narration":"ナレーション","image_prompt":"English prompt","duration":5}]}

Rules:
- Exactly 8 scenes
- duration: 5 seconds each (total ~40 seconds)
- image_prompt: English only, specify "vertical 9:16 composition", keep under 80 characters
- Tell a dramatic story arc across 8 scenes (setup → development → turning point → climax → resolution)
- narration: Japanese, engaging storytelling tone, max 30 characters per scene
- IMPORTANT: Preserve proper nouns from the tweet (place names, event names, brand names, etc. like 武道館・東京ドーム・etc.) in the narration. Only person names can be omitted or replaced.
- Do NOT use double quotes inside string values. Use 「」for Japanese quotes.
"""


def parse_json_from_response(text: str) -> dict:
    text = text.strip()

    # Strip markdown code fences
    text = re.sub(r'^```(?:json)?\s*', '', text, flags=re.MULTILINE)
    text = re.sub(r'```\s*$', '', text, flags=re.MULTILINE)
    text = text.strip()

    def repair_json_drift(value: str) -> str:
        # Local LLMs sometimes replace the comma between title and scenes with
        # Japanese quote marks, e.g. {"title":"...」「scenes":[...]}.
        value = re.sub(r'("title"\s*:\s*"[^"]+")[「」]\s*("scenes"\s*:)', r'\1,\2', value)
        value = re.sub(r'("[^"]+")[「」]\s*("scenes"\s*:)', r'\1,\2', value)
        return value

    candidates = [text, repair_json_drift(text)]

    # Try direct parse
    for candidate in candidates:
        try:
            return json.loads(candidate)
        except Exception:
            pass

    # Extract the outermost JSON object
    for candidate in candidates:
        start = candidate.find('{')
        if start != -1:
            depth = 0
            for i, c in enumerate(candidate[start:], start):
                if c == '{':
                    depth += 1
                elif c == '}':
                    depth -= 1
                    if depth == 0:
                        fragment = candidate[start:i+1]
                        for repaired in [fragment, repair_json_drift(fragment)]:
                            try:
                                return json.loads(repaired)
                            except Exception:
                                pass
                        break

    # Try to recover partial JSON: extract complete scenes via regex
    title_m = re.search(r'"title"\s*:\s*"([^"]+)"', text)
    scene_matches = list(re.finditer(
        r'\{"index"\s*:\s*(\d+)\s*,\s*"narration"\s*:\s*"([^"]+)"\s*,\s*"image_prompt"\s*:\s*"([^"]+)"\s*,\s*"duration"\s*:\s*(\d+)\}',
        text
    ))
    if title_m and scene_matches:
        scenes = [
            {"index": int(m.group(1)), "narration": m.group(2),
             "image_prompt": m.group(3), "duration": int(m.group(4))}
            for m in scene_matches
        ]
        return {"title": title_m.group(1), "scenes": scenes}

    raise ValueError(f"Could not parse JSON from response: {text[:300]}")


def normalize_narration_text(value: str) -> str:
    """Avoid TTS misreadings and awkward repeated phrases."""
    value = value or ""
    replacements = {
        "深堀り": "詳しい考察",
        "深掘り": "詳しい考察",
        "深堀": "詳しい考察",
        "ふかぼり": "詳しい考察",
    }
    for src, dst in replacements.items():
        value = value.replace(src, dst)
    return value


def normalize_script(script: dict, scene_duration: int = 10, force_title: str = "") -> dict:
    if force_title:
        script["title"] = force_title[:60]
    if "scenes" not in script or not script["scenes"]:
        raise ValueError("Script missing scenes")
    for i, scene in enumerate(script["scenes"]):
        scene.setdefault("index", i)
        scene["index"] = i
        scene["duration"] = int(scene.get("duration") or scene_duration)
        scene["narration"] = normalize_narration_text(scene.get("narration") or "")
    script["title"] = normalize_narration_text(script.get("title") or "")
    return script


def generate_script(tweet: dict, video_style: str = "auto") -> dict:
    """Generate script JSON from tweet data using Ollama.

    Args:
        tweet: {"text": "...", "author": "@...", "author_name": "..."}

    Returns:
        {"title": "...", "scenes": [...]}
    """
    resolved_style = resolve_video_style(video_style, content_type="tweet", title=tweet.get("text", "")[:80])
    user_prompt = f"""以下のXの投稿から短編動画を作ってください。

投稿者: {tweet['author_name']} ({tweet['author']})
投稿内容:
{tweet['text']}

{style_prompt(resolved_style)}

JSONのみ返してください。"""

    payload = {
        "model": OLLAMA_MODEL,
        "prompt": SYSTEM_PROMPT + "\n\n" + user_prompt,
        "stream": False,
        "options": {
            "temperature": 0.7,
            "num_predict": 2048,
        },
    }

    resp = requests.post(
        f"{OLLAMA_URL}/api/generate",
        json=payload,
        timeout=180,
    )
    resp.raise_for_status()
    data = resp.json()
    response_text = data.get("response") or ""
    print(f"  [script] Ollama response length: {len(response_text)}", flush=True)

    # Unload model from GPU after generation
    try:
        requests.post(
            f"{OLLAMA_URL}/api/generate",
            json={"model": OLLAMA_MODEL, "prompt": "", "keep_alive": 0},
            timeout=10,
        )
        print("  [script] Ollama model unloaded", flush=True)
    except Exception:
        pass

    script = parse_json_from_response(response_text)

    return apply_video_style(normalize_script(script, scene_duration=5), resolved_style)


NEWS_SYSTEM_PROMPT = """You are a news video scriptwriter. Based on multiple news articles, generate a 12-scene news broadcast script with image prompts.

Return ONLY a JSON object. No markdown, no code blocks, no explanation. Start your response with { and end with }.

Required format:
{"title":"ニュースタイトル(30字以内)","scenes":[{"index":0,"narration":"ナレーション日本語(50〜60字)","image_prompt":"English cinematic vertical 9:16 scene description under 80 chars","duration":10},...]}

Rules:
- Exactly 12 scenes, duration: 10 seconds each (total ~120 seconds = 2 minutes)
- Structure: scene 0 = opening/overview, scenes 1-10 = distribute across news items (2-4 scenes each based on importance), scene 11 = closing summary
- narration: Japanese, news broadcast tone, 50-60 characters per scene
- image_prompt: English only, "vertical 9:16 composition", under 80 characters, match the news topic visually
- Preserve proper nouns (company names, product names, place names) exactly as given
- Do NOT use 「深堀り」「深掘り」「ふかぼり」. Use 「詳しい考察」 or 「掘り下げ」 instead.
- Do NOT use double quotes inside string values. Use 「」for Japanese quotes.
"""


def generate_news_script(news_items: list, video_style: str = "auto") -> dict:
    """Generate 12-scene news broadcast script from multiple news articles.

    Args:
        news_items: [{"title": str, "content": str, "url": str, "source_name": str}, ...]

    Returns:
        {"title": "...", "scenes": [...]}  (12 scenes)
    """
    items_text = ""
    for i, item in enumerate(news_items, 1):
        items_text += f"{i}. 【{item.get('source_name', 'News')}】{item['title']}\n"
        if item.get('content'):
            items_text += f"   {item['content'][:300]}\n"
        items_text += "\n"

    title_hint = "、".join((item.get("title") or "") for item in news_items[:3])
    resolved_style = resolve_video_style(video_style, content_type="news", title=title_hint)
    user_prompt = f"""以下の{len(news_items)}本のニュース記事をもとに、ニュース番組風の動画脚本を作成してください。

【記事一覧】
{items_text}
各記事の重要度・分量に応じてシーンを配分してください（合計12シーン）。

{style_prompt(resolved_style)}

JSONのみ返してください。"""

    payload = {
        "model": OLLAMA_MODEL,
        "prompt": NEWS_SYSTEM_PROMPT + "\n\n" + user_prompt,
        "stream": False,
        "options": {
            "temperature": 0.2,
            "num_predict": 4096,
        },
    }

    resp = requests.post(
        f"{OLLAMA_URL}/api/generate",
        json=payload,
        timeout=300,
    )
    resp.raise_for_status()
    data = resp.json()
    response_text = data.get("response") or ""
    print(f"  [news_script] Ollama response length: {len(response_text)}", flush=True)

    try:
        requests.post(
            f"{OLLAMA_URL}/api/generate",
            json={"model": OLLAMA_MODEL, "prompt": "", "keep_alive": 0},
            timeout=10,
        )
    except Exception:
        pass

    script = parse_json_from_response(response_text)

    return apply_video_style(normalize_script(script, scene_duration=10), resolved_style)


BLOG_SYSTEM_PROMPT = """You are a thoughtful Japanese video essay scriptwriter. Based on one blog article, generate a 12-scene vertical video script for a 2-minute commentary video.

Return ONLY a JSON object. No markdown, no code blocks, no explanation. Start your response with { and end with }.

Required format:
{"title":"ブログ記事タイトルを元にした動画タイトル(60字以内)","scenes":[{"index":0,"narration":"ナレーション日本語(45〜60字)","image_prompt":"English vertical 9:16 prompt under 60 chars","duration":10},...]}

Rules:
- Exactly 12 scenes, duration: 10 seconds each (total ~120 seconds = 2 minutes)
- This is NOT a news broadcast.
- Speak as a reflective commentary on the blog article.
- If the blog title contains a person name, preserve that person name in the video title and narration.
- The video title must follow the blog title as closely as possible.
- Structure: scene 0 = introduce the article/person, scenes 1-10 = explain the episode and business/AI lesson, scene 11 = closing insight.
- narration: Japanese, calm essay tone, 45-60 characters per scene.
- image_prompt: English only, short visual keywords, include "vertical 9:16", under 60 characters.
- Preserve proper nouns and the named person exactly.
- Do NOT make it sound like daily news or breaking news.
- Do NOT use 「深堀り」「深掘り」「ふかぼり」. Use 「詳しい考察」 or 「掘り下げ」 instead.
- Do NOT use double quotes inside string values. Use 「」for Japanese quotes.
"""


def generate_blog_script(article: dict, video_style: str = "auto", vtuber_mode: bool = False) -> dict:
    """Generate 12-scene blog commentary script from one article."""
    title = (article.get("title") or "").strip()
    content = (article.get("content") or "").strip()
    source_name = article.get("source_name") or "Blog"
    resolved_style = resolve_video_style(video_style, content_type="blog", vtuber_mode=vtuber_mode, title=title)
    user_prompt = f"""以下のブログ記事をもとに、2分の人物・ビジネス考察動画の脚本を作成してください。

【ブログタイトル】
{title}

【出典】
{source_name}

【本文】
{content[:2500]}

{style_prompt(resolved_style)}

重要:
- 動画タイトルはブログタイトルにできるだけ合わせる。
- タイトルに人物名がある場合、その人物名を必ず動画タイトルと冒頭ナレーションに入れる。
- ニュース番組風ではなく、ブログを読んで考察する語り口にする。
- 「深堀り」「深掘り」「ふかぼり」という語は使わない。

JSONのみ返してください。"""

    payload = {
        "model": OLLAMA_MODEL,
        "prompt": BLOG_SYSTEM_PROMPT + "\n\n" + user_prompt,
        "stream": False,
        "options": {
            "temperature": 0.25,
            "num_predict": 8192,
        },
    }
    resp = requests.post(
        f"{OLLAMA_URL}/api/generate",
        json=payload,
        timeout=300,
    )
    resp.raise_for_status()
    data = resp.json()
    response_text = data.get("response") or ""
    print(f"  [blog_script] Ollama response length: {len(response_text)}", flush=True)

    try:
        requests.post(
            f"{OLLAMA_URL}/api/generate",
            json={"model": OLLAMA_MODEL, "prompt": "", "keep_alive": 0},
            timeout=10,
        )
    except Exception:
        pass

    script = parse_json_from_response(response_text)
    return apply_video_style(normalize_script(script, scene_duration=10, force_title=title), resolved_style)


ENTERTAINMENT_SHORT_SYSTEM_PROMPT = """You are a Japanese short video editor for safe entertainment news commentary.

Return ONLY a JSON object. No markdown, no code blocks, no explanation. Start with { and end with }.

Required format:
{"title":"30秒動画タイトル(50字以内)","scenes":[{"index":0,"narration":"日本語ナレーション(25〜35字)","image_prompt":"English vertical 9:16 abstract entertainment news visual under 70 chars","duration":5},...]}

Rules:
- Exactly 6 scenes, duration: 5 seconds each (total ~30 seconds).
- Do not imply endorsement, sponsorship, recommendation, romance, scandal, guilt, or private facts unless explicitly in the source text.
- Do not describe a real person's face, body, or likeness in image_prompt.
- Use abstract safe visuals: studio lights, city billboard, smartphone news cards, books, streaming icons, cinema seats.
- Preserve public proper nouns in narration when relevant.
- In the closing, cite the exact article URL and source URL when supplied.
- Do not say vague phrases like 「続きはKurageで」 or 「詳しくはKurageで」.
- No double quotes inside string values. Use 「」for Japanese quotes.
"""


def generate_entertainment_short_script(article: dict, video_style: str = "auto", vtuber_mode: bool = False) -> dict:
    """Generate a 30-second short video script for entertainment SEO articles."""
    title = (article.get("title") or "").strip()
    summary = (article.get("summary") or article.get("content") or "").strip()
    celebrity = "、".join(article.get("celebrity_names") or []) or "話題の人物"
    kurage_url = article.get("url") or "https://kurage.exbridge.jp/entertainment.php"
    source_url = article.get("source_url") or ""

    resolved_style = resolve_video_style(video_style, content_type="entertainment_short", vtuber_mode=vtuber_mode, title=title)
    user_prompt = f"""以下の芸能ニュース考察記事を、30秒の安全なショート動画にしてください。

【記事タイトル】
{title}

【人物名】
{celebrity}

【要約】
{summary[:1200]}

【Kurage記事URL】
{kurage_url}

【元ニュース・元動画URL】
{source_url or "記事ページ内の参考リンク"}

{style_prompt(resolved_style)}

重要:
- 本人が商品をおすすめした、愛用した、宣伝したとは言わない。
- 人物の顔写真を使う前提にしない。
- 最後は記事URLと元ニュースURLを短く案内する。
- 「続きはKurageで」「詳しくはKurageで」のような曖昧な表現は禁止。

JSONのみ返してください。"""

    payload = {
        "model": OLLAMA_MODEL,
        "prompt": ENTERTAINMENT_SHORT_SYSTEM_PROMPT + "\n\n" + user_prompt,
        "stream": False,
        "options": {
            "temperature": 0.25,
            "num_predict": 4096,
        },
    }
    resp = requests.post(
        f"{OLLAMA_URL}/api/generate",
        json=payload,
        timeout=240,
    )
    resp.raise_for_status()
    response_text = (resp.json().get("response") or "")
    print(f"  [entertainment_short_script] Ollama response length: {len(response_text)}", flush=True)

    try:
        requests.post(
            f"{OLLAMA_URL}/api/generate",
            json={"model": OLLAMA_MODEL, "prompt": "", "keep_alive": 0},
            timeout=10,
        )
    except Exception:
        pass

    script = parse_json_from_response(response_text)
    return apply_video_style(normalize_script(script, scene_duration=5, force_title=title[:50]), resolved_style)


if __name__ == "__main__":
    import sys
    tweet = {
        "text": sys.argv[1] if len(sys.argv) > 1 else "ChatGPTに「明日の天気は？」と聞いたら「私は過去のデータしか知りません」と言われた。",
        "author": "@test_user",
        "author_name": "テストユーザー",
    }
    result = generate_script(tweet)
    print(json.dumps(result, ensure_ascii=False, indent=2))
