"""Generate short drama script + image prompts from tweet content using Ollama."""
from __future__ import annotations
import json
import re
import requests
from config import OLLAMA_URL, OLLAMA_MODEL


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

    raise ValueError(f"Could not parse JSON from response: {text[:300]}")


def generate_script(tweet: dict) -> dict:
    """Generate script JSON from tweet data using Ollama.

    Args:
        tweet: {"text": "...", "author": "@...", "author_name": "..."}

    Returns:
        {"title": "...", "scenes": [...]}
    """
    user_prompt = f"""以下のXの投稿から短編動画を作ってください。

投稿者: {tweet['author_name']} ({tweet['author']})
投稿内容:
{tweet['text']}

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

    if "scenes" not in script or not script["scenes"]:
        raise ValueError("Script missing scenes")

    for i, scene in enumerate(script["scenes"]):
        scene.setdefault("index", i)
        scene["index"] = i
        scene.setdefault("duration", 5)

    return script


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
- Do NOT use double quotes inside string values. Use 「」for Japanese quotes.
"""


def generate_news_script(news_items: list) -> dict:
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

    user_prompt = f"""以下の{len(news_items)}本のニュース記事をもとに、ニュース番組風の動画脚本を作成してください。

【記事一覧】
{items_text}
各記事の重要度・分量に応じてシーンを配分してください（合計12シーン）。
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

    if "scenes" not in script or not script["scenes"]:
        raise ValueError("News script missing scenes")

    for i, scene in enumerate(script["scenes"]):
        scene.setdefault("index", i)
        scene.setdefault("duration", 10)

    return script


if __name__ == "__main__":
    import sys
    tweet = {
        "text": sys.argv[1] if len(sys.argv) > 1 else "ChatGPTに「明日の天気は？」と聞いたら「私は過去のデータしか知りません」と言われた。",
        "author": "@test_user",
        "author_name": "テストユーザー",
    }
    result = generate_script(tweet)
    print(json.dumps(result, ensure_ascii=False, indent=2))
