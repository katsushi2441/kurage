"""Generate short drama script + image prompts from tweet content using Ollama."""
from __future__ import annotations
import json
import re
import requests
from config import OLLAMA_URL, OLLAMA_MODEL
from tts_normalizer import normalize_tts_text
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
    return normalize_tts_text(value or "")


def normalize_script(script: dict, scene_duration: int = 10, force_title: str = "") -> dict:
    if force_title:
        script["title"] = force_title[:80]
    if "scenes" not in script or not script["scenes"]:
        raise ValueError("Script missing scenes")
    for i, scene in enumerate(script["scenes"]):
        scene.setdefault("index", i)
        scene["index"] = i
        scene["duration"] = int(scene.get("duration") or scene_duration)
        scene["narration"] = normalize_narration_text(scene.get("narration") or "")
    # Titles are display metadata, not TTS text. Keep product names such as
    # "Kurage AI VTuber Radio" in their original branded form.
    script["title"] = str(script.get("title") or "").strip()
    return script


KMONTAGE_QUALITY_RULES = """
Kurage Montage quality rules:
- Do not produce generic summaries. Extract the concrete claim, numbers, tools, workflow, risks, and why it matters.
- Build scenes in this order when possible: hook, context, key evidence, workflow, implications, caveats, action insight, closing.
- Every narration must be natural Japanese. Proper nouns and tool names may stay in English, but explanations must be Japanese.
- Avoid thin phrases like 「注目です」「話題です」「詳しく見ます」 unless followed by a concrete point.
- For each scene, image_prompt must be English and describe a concrete ERNIE-friendly visual: subject, setting, camera, lighting, readable cards, vertical 9:16.
- Use bright White Studio / pale aqua / clean explainer visuals. Avoid black backgrounds and generic cyberpunk.
- Keep source facts faithful. If information is not in the source, do not invent it.
""".strip()


def _json_source_excerpt(value, limit: int = 5200) -> str:
    if isinstance(value, str):
        text = value
    else:
        text = json.dumps(value, ensure_ascii=False)
    text = re.sub(r"\s+", " ", text).strip()
    return text[:limit]


def _japanese_signal(text: str) -> int:
    return len(re.findall(r"[\u3040-\u30ff\u3400-\u9fff]", text or ""))


def _script_needs_repair(script: dict, expected_scenes: int) -> bool:
    scenes = script.get("scenes") if isinstance(script, dict) else []
    if not isinstance(scenes, list) or len(scenes) < max(4, expected_scenes // 2):
        return True
    narrations = " ".join(str(s.get("narration") or "") for s in scenes if isinstance(s, dict))
    if _japanese_signal(narrations) < max(40, len(narrations) // 4):
        return True
    weak = 0
    for scene in scenes:
        if not isinstance(scene, dict):
            weak += 1
            continue
        narration = str(scene.get("narration") or "").strip()
        image_prompt = str(scene.get("image_prompt") or "").strip()
        if len(narration) < 16:
            weak += 1
        if len(image_prompt) < 35 or "vertical" not in image_prompt.lower():
            weak += 1
    return weak >= max(2, len(scenes) // 3)


def _repair_script_with_kmontage_quality(script: dict, *, source_type: str, source_material, expected_scenes: int,
                                         scene_duration: int, force_title: str = "",
                                         video_style: str = "auto") -> dict:
    previous = json.dumps(script, ensure_ascii=False)[:7000]
    source_excerpt = _json_source_excerpt(source_material)
    prompt = f"""次のKurage動画台本を、Kurage Montageと同等の品質へ修復してください。

対象: {source_type}
期待シーン数: {expected_scenes}
各シーン秒数: {scene_duration}
固定タイトル: {force_title or "なし"}

元資料:
{source_excerpt}

現在の台本:
{previous}

{KMONTAGE_QUALITY_RULES}

必須:
- JSONのみ返す
- title は自然な日本語
- scenes はちょうど {expected_scenes} 件
- scenes[].narration は日本語で、具体的な事実・手順・数字・示唆を入れる
- scenes[].image_prompt は英語で、ERNIE-Image-Turboが作りやすい明確な映像指示にする
- image_prompt は vertical 9:16, bright white studio, clean explainer, data cards の方向を優先
- 黒背景、暗いサイバー背景、抽象的すぎる絵は避ける
- 元資料にない事実は足さない

形式:
{{"title":"日本語タイトル","scenes":[{{"index":0,"narration":"日本語ナレーション","image_prompt":"English vertical 9:16 bright explainer visual","duration":{scene_duration}}}]}}
"""
    payload = {
        "model": OLLAMA_MODEL,
        "prompt": prompt,
        "stream": False,
        "options": {"temperature": 0.08, "num_predict": 8192},
    }
    try:
        resp = requests.post(f"{OLLAMA_URL}/api/generate", json=payload, timeout=300)
        resp.raise_for_status()
        repaired = parse_json_from_response(resp.json().get("response") or "")
        return normalize_script(repaired, scene_duration=scene_duration, force_title=force_title)
    except Exception as exc:
        print(f"  [script_quality] repair skipped: {exc}", flush=True)
        return script


def _deterministic_quality_polish(script: dict, *, source_type: str, expected_scenes: int, scene_duration: int,
                                  force_title: str = "") -> dict:
    script = normalize_script(script, scene_duration=scene_duration, force_title=force_title)
    scenes = script.get("scenes") or []
    if len(scenes) > expected_scenes:
        scenes = scenes[:expected_scenes]
    while scenes and len(scenes) < expected_scenes:
        base = scenes[-1].copy()
        base["index"] = len(scenes)
        base["narration"] = f"{base.get('narration', '')} ここから実践上の意味を整理します。".strip()
        scenes.append(base)
    for i, scene in enumerate(scenes):
        scene["index"] = i
        scene["duration"] = int(scene.get("duration") or scene_duration)
        narration = normalize_narration_text(str(scene.get("narration") or "").strip())
        narration = narration.replace("深堀り", "掘り下げ").replace("深掘り", "掘り下げ")
        scene["narration"] = narration
        prompt = str(scene.get("image_prompt") or "").strip()
        if not prompt:
            prompt = "clean Japanese vertical explainer, data cards"
        if "vertical" not in prompt.lower():
            prompt += ", vertical 9:16"
        if "black" not in prompt.lower() and "dark" not in prompt.lower():
            prompt += ", bright white studio, pale aqua accents"
        if "card" not in prompt.lower() and source_type in {"news", "blog", "entertainment_short", "tweet"}:
            prompt += ", readable data cards"
        scene["image_prompt"] = prompt[:180].strip()
    script["scenes"] = scenes
    if not script.get("title"):
        script["title"] = "Kurage解説動画"
    return script


def quality_boost_script(script: dict, *, source_type: str, source_material, expected_scenes: int,
                         scene_duration: int, force_title: str = "", video_style: str = "auto") -> dict:
    script = _deterministic_quality_polish(
        script,
        source_type=source_type,
        expected_scenes=expected_scenes,
        scene_duration=scene_duration,
        force_title=force_title,
    )
    if _script_needs_repair(script, expected_scenes):
        script = _repair_script_with_kmontage_quality(
            script,
            source_type=source_type,
            source_material=source_material,
            expected_scenes=expected_scenes,
            scene_duration=scene_duration,
            force_title=force_title,
            video_style=video_style,
        )
        script = _deterministic_quality_polish(
            script,
            source_type=source_type,
            expected_scenes=expected_scenes,
            scene_duration=scene_duration,
            force_title=force_title,
        )
    script["quality_profile"] = "kmontage"
    return script


def _sanitize_entertainment_short_script(script: dict, article: dict | None = None) -> dict:
    """Keep entertainment-short narration TTS-safe.

    Entertainment article URLs remain in job metadata and the public article.
    They should not be read aloud, because long URL strings make Voicebox fail
    and produce unwatchable videos.
    """
    title = str((article or {}).get("title") or script.get("title") or "エンタメニュース考察").strip()
    safe_closing = "背景と今後の動きを整理します"
    url_like = re.compile(r"(https?://|www\.|\.com|\.jp|\.net|\.org|/|\\?|=|&)", re.I)
    bad_phrases = ("詳細は", "詳しくは", "続きは", "元ソース", "記事URL", "ニュースURL", "Kurageで", "クラゲで")
    scenes = script.get("scenes") if isinstance(script, dict) else []
    if not isinstance(scenes, list):
        scenes = []
    for i, scene in enumerate(scenes):
        if not isinstance(scene, dict):
            continue
        narration = normalize_narration_text(str(scene.get("narration") or "").strip())
        if url_like.search(narration) or any(p in narration for p in bad_phrases):
            narration = safe_closing if i >= len(scenes) - 2 else f"{title[:24]}の要点を整理します"
        # Five-second scenes should stay short. Cutting at Japanese punctuation
        # prevents Voicebox from stretching a long sentence into a failed chunk.
        if len(narration) > 42:
            cut = narration[:42]
            m = re.search(r"^(.{18,42}?)[。！？、,]", cut)
            narration = (m.group(1) if m else cut).rstrip("、,。 ")
        scene["narration"] = narration or safe_closing
        scene["duration"] = int(scene.get("duration") or 5)
    script["scenes"] = scenes[:6]
    while len(script["scenes"]) < 6:
        script["scenes"].append({
            "index": len(script["scenes"]),
            "narration": safe_closing,
            "image_prompt": "clean recap card with subtle motion graphics, vertical 9:16, bright white",
            "duration": 5,
        })
    for i, scene in enumerate(script["scenes"]):
        scene["index"] = i
    return script


def fallback_entertainment_short_script(article: dict, video_style: str = "auto") -> dict:
    """Build a safe article-based script when the LLM returns unusable JSON."""
    title = str(article.get("title") or "エンタメニュース考察").strip()
    summary = re.sub(r"\s+", " ", str(article.get("summary") or article.get("content") or "")).strip()
    source_name = str(article.get("source_name") or "ニュース").strip()
    base = summary or title
    sentences = [s.strip(" 。、") for s in re.split(r"[。！？!?]\s*", base) if s.strip()]
    while len(sentences) < 4:
        sentences.append(title)

    narrations = [
        f"{title[:28]}の話題です",
        f"{source_name[:14]}が伝えた注目ニュースです",
        sentences[0][:34],
        sentences[1][:34],
        sentences[2][:34],
        "背景と今後の動きを整理します",
    ]
    prompts = [
        "bright Japanese entertainment news title card, vertical 9:16, pale aqua accents",
        "clean smartphone news cards on white desk, vertical 9:16, soft studio light",
        "cinematic studio data cards and headlines, vertical 9:16, bright white studio",
        "abstract cinema seats and soft spotlight, vertical 9:16, commercial look",
        "minimal timeline cards floating in white studio, vertical 9:16, pale aqua",
        "clean recap card with subtle motion graphics, vertical 9:16, bright white",
    ]
    script = {
        "title": title[:50],
        "scenes": [
            {"index": i, "narration": narrations[i], "image_prompt": prompts[i], "duration": 5}
            for i in range(6)
        ],
        "fallback_reason": "LLM response was empty or invalid JSON",
    }
    resolved_style = resolve_video_style(video_style, content_type="entertainment_short", title=title)
    script = quality_boost_script(
        script,
        source_type="entertainment_short",
        source_material=article,
        expected_scenes=6,
        scene_duration=5,
        force_title=title[:50],
        video_style=resolved_style,
    )
    script = _sanitize_entertainment_short_script(script, article)
    return apply_video_style(script, resolved_style)


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

{KMONTAGE_QUALITY_RULES}

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
    script = quality_boost_script(
        script,
        source_type="tweet",
        source_material=tweet,
        expected_scenes=8,
        scene_duration=5,
        video_style=resolved_style,
    )
    return apply_video_style(script, resolved_style)


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

{KMONTAGE_QUALITY_RULES}

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
    script = quality_boost_script(
        script,
        source_type="news",
        source_material=news_items,
        expected_scenes=12,
        scene_duration=10,
        video_style=resolved_style,
    )
    return apply_video_style(script, resolved_style)


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

{KMONTAGE_QUALITY_RULES}

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
    script = quality_boost_script(
        script,
        source_type="blog",
        source_material=article,
        expected_scenes=12,
        scene_duration=10,
        force_title=title,
        video_style=resolved_style,
    )
    return apply_video_style(script, resolved_style)


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
- Do not read URLs aloud. Do not include http, domain names, query strings, source URLs, or article URLs in narration.
- The closing should summarize the insight, not cite links. Links stay in metadata outside the spoken script.
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

{KMONTAGE_QUALITY_RULES}

重要:
- 本人が商品をおすすめした、愛用した、宣伝したとは言わない。
- 人物の顔写真を使う前提にしない。
- URLはナレーションに絶対に入れない。http、ドメイン名、クエリ文字列を読ませない。
- 最後は記事の要点や今後の見方を短くまとめる。
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

    try:
        script = parse_json_from_response(response_text)
    except Exception as exc:
        print(f"  [entertainment_short_script] LLM JSON parse failed; using fallback script: {exc}", flush=True)
        return fallback_entertainment_short_script(article, video_style=resolved_style)
    script = quality_boost_script(
        script,
        source_type="entertainment_short",
        source_material=article,
        expected_scenes=6,
        scene_duration=5,
        force_title=title[:50],
        video_style=resolved_style,
    )
    script = _sanitize_entertainment_short_script(script, article)
    return apply_video_style(script, resolved_style)


if __name__ == "__main__":
    import sys
    tweet = {
        "text": sys.argv[1] if len(sys.argv) > 1 else "ChatGPTに「明日の天気は？」と聞いたら「私は過去のデータしか知りません」と言われた。",
        "author": "@test_user",
        "author_name": "テストユーザー",
    }
    result = generate_script(tweet)
    print(json.dumps(result, ensure_ascii=False, indent=2))
