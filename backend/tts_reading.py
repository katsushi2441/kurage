"""TTS読み台本生成 — 固有名詞・数字をカナに開いたTTS専用テキストをgemma4で作る.

表示用テキスト(字幕・概要欄)は変えず、TTSに渡す読みだけを変換する。
辞書ベースのtts_normalizer(既知の製品名など)を補完し、任意の固有名詞・
数字・英略語の読み間違いを減らす。変換結果が検証を通らない場合は
必ず元テキストを返す(フェイルセーフ。読み台本で動画を壊さない)。
"""
from __future__ import annotations

import os
import re

import requests

from config import OLLAMA_URL, OLLAMA_MODEL

READING_ENABLED = os.environ.get("KURAGE_TTS_READING_ENABLED", "1").lower() not in {"0", "false", "no", "off"}
READING_TIMEOUT = int(os.environ.get("KURAGE_TTS_READING_TIMEOUT", "120"))

_PROMPT = """あなたは日本語TTSの読み上げ原稿を作る変換器です。次の文を、TTSが読み間違えないように変換してください。

ルール:
- 読み間違いしやすい固有名詞(人名・地名・会社名・製品名)、数字と単位、英単語・英略語だけをカタカナの読みに置き換える
- それ以外の部分は一字も変えず、語順・内容・句読点を維持する
- 意味の追加・削除・要約・言い換えをしない
- 出力は変換後の文だけ。説明・前置き・引用符・改行を付けない

文: {text}
"""

# 変換結果に混ざったら失敗とみなすメタ語
_META_WORDS = ("変換後", "読み上げ原稿", "出力:", "ルール", "以下の", "説明")


def _clean(value: str) -> str:
    value = str(value or "").strip()
    value = re.sub(r"^```[a-z]*\s*|\s*```$", "", value).strip()
    value = value.strip('"「」『』')
    value = re.sub(r"^(変換後の文|出力|文)\s*[:：]\s*", "", value)
    return value.strip()


def _is_valid(reading: str, original: str) -> bool:
    if not reading:
        return False
    if "\n" in reading:
        return False
    ratio = len(reading) / max(len(original), 1)
    if ratio < 0.5 or ratio > 3.0:
        return False
    if any(word in reading and word not in original for word in _META_WORDS):
        return False
    return True


def to_reading_text(text: str) -> str:
    """Return a TTS reading of `text`; falls back to `text` itself on any failure."""
    original = str(text or "").strip()
    if not original or not READING_ENABLED:
        return original

    payload = {
        "model": OLLAMA_MODEL,
        # gemma4は思考型: think無効化しないと隠れ推論がnum_predictを食い潰し応答が空になる
        "think": False,
        "prompt": _PROMPT.format(text=original),
        "stream": False,
        "options": {"temperature": 0.0, "num_predict": 1024},
    }
    try:
        resp = requests.post(f"{OLLAMA_URL}/api/generate", json=payload, timeout=READING_TIMEOUT)
        resp.raise_for_status()
        reading = _clean(resp.json().get("response") or "")
    except Exception as exc:
        print(f"  [tts_reading] skipped ({exc})", flush=True)
        return original

    if not _is_valid(reading, original):
        print(f"  [tts_reading] rejected: {reading[:80]!r}", flush=True)
        return original
    if reading != original:
        print(f"  [tts_reading] {original[:40]!r} -> {reading[:60]!r}", flush=True)
    return reading
