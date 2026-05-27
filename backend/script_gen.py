"""Generate short drama script + image prompts from tweet content using Ollama."""
from __future__ import annotations
import json
import re
import requests
from config import OLLAMA_URL, OLLAMA_MODEL


SYSTEM_PROMPT = """You are a short vertical video scriptwriter. Based on an X (Twitter) post, generate a 6-scene short drama script with image prompts.

Return ONLY a JSON object. No markdown, no code blocks, no explanation. Start your response with { and end with }.

Required format:
{"title":"動画タイトル(30字以内)","scenes":[{"index":0,"narration":"ナレーション日本語(30字以内)","image_prompt":"English cinematic vertical 9:16 scene description under 80 chars","duration":5},{"index":1,"narration":"ナレーション","image_prompt":"English prompt","duration":5},{"index":2,"narration":"ナレーション","image_prompt":"English prompt","duration":5},{"index":3,"narration":"ナレーション","image_prompt":"English prompt","duration":5},{"index":4,"narration":"ナレーション","image_prompt":"English prompt","duration":5},{"index":5,"narration":"ナレーション","image_prompt":"English prompt","duration":5}]}

Rules:
- Exactly 6 scenes
- duration: 5 seconds each (total ~30 seconds)
- image_prompt: English only, specify "vertical 9:16 composition", keep under 80 characters
- Tell a dramatic story arc across 6 scenes (setup → development → climax → resolution)
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

    # Try direct parse
    try:
        return json.loads(text)
    except Exception:
        pass

    # Extract the outermost JSON object
    start = text.find('{')
    if start != -1:
        depth = 0
        for i, c in enumerate(text[start:], start):
            if c == '{':
                depth += 1
            elif c == '}':
                depth -= 1
                if depth == 0:
                    try:
                        return json.loads(text[start:i+1])
                    except Exception:
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
        scene.setdefault("duration", 6)

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
