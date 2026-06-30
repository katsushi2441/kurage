"""Shared Japanese TTS text normalizer for Kurage products.

The normalizer keeps display text unchanged while preparing a separate text for
TTS engines. It focuses on product names, AI/OSS terms, and common phrases that
Japanese TTS engines often misread.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sys
from pathlib import Path
from typing import Any

ROOT_DIR = Path(__file__).resolve().parents[1]
DEFAULT_DICTIONARY_PATH = ROOT_DIR / "config" / "tts_pronunciation.json"

DEFAULT_REPLACEMENTS: dict[str, str] = {
    "深堀り": "詳しい考察",
    "深掘り": "詳しい考察",
    "深堀": "詳しい考察",
    "ふかぼり": "詳しい考察",
}

DEFAULT_PRONUNCIATIONS: dict[str, str] = {
    "Kurage AI VTuber": "クラゲ エーアイ ブイチューバー",
    "Kurage Voice Pro": "クラゲ ボイス プロ",
    "Kurage Work Protocol": "クラゲ ワーク プロトコル",
    "Kurage Content Engine": "クラゲ コンテンツ エンジン",
    "Kurage Argo Video": "クラゲ アルゴ ビデオ",
    "Kurage Blog": "クラゲ ブログ",
    "Kurage": "クラゲ",
    "kuragevp": "クラゲ ブイピー",
    "kvtuber": "ケーブイチューバー",
    "kargov": "カーゴブイ",
    "kcengine": "ケーシーエンジン",
    "kdeck": "ケーデック",
    "kagentreach": "ケーエージェントリーチ",
    "AgentReach": "エージェントリーチ",
    "rqdb4ai": "アールキューディービー フォー エーアイ",
    "VWork": "ブイワーク",
    "AIxSNS": "エーアイエックス エスエヌエス",
    "AIxEC": "エーアイエックス イーシー",
    "AIxTube": "エーアイエックス チューブ",
    "OpenLLMVTuber": "オープン エルエルエム ブイチューバー",
    "Open-LLM-VTuber": "オープン エルエルエム ブイチューバー",
    "AITuber OnAir": "エーアイチューバー オンエア",
    "AITuberKit": "エーアイチューバーキット",
    "VTuber": "ブイチューバー",
    "YouTube Live": "ユーチューブ ライブ",
    "YouTube": "ユーチューブ",
    "Live2D": "ライブツーディー",
    "Inochi2D": "イノチツーディー",
    "VRM": "ブイアールエム",
    "VOICEVOX": "ボイスボックス",
    "Voice Pro": "ボイス プロ",
    "voice-pro": "ボイス プロ",
    "browser-use": "ブラウザ ユース",
    "browser_agent": "ブラウザ エージェント",
    "HyperFrames": "ハイパーフレームズ",
    "OpenAI": "オープンエーアイ",
    "Claude Code": "クロード コード",
    "Claude": "クロード",
    "Codex": "コーデックス",
    "Gemini": "ジェミニ",
    "Ollama": "オラマ",
    "AIRI": "アイリ",
    "Superpowers": "スーパーパワーズ",
    "GitHub": "ギットハブ",
    "Git": "ギット",
    "OSS": "オープンソース",
    "LLM": "エルエルエム",
    "TTS": "ティーティーエス",
    "STT": "エスティーティー",
    "RAG": "ラグ",
    "API": "エーピーアイ",
    "RTMP": "アールティーエムピー",
    "OBS": "オービーエス",
    "SNS": "エスエヌエス",
    "X投稿": "エックス投稿",
}

_UNIT = ["", "いち", "に", "さん", "よん", "ご", "ろく", "なな", "はち", "きゅう"]
_SEN = {1: "せん", 2: "にせん", 3: "さんぜん", 4: "よんせん", 5: "ごせん", 6: "ろくせん", 7: "ななせん", 8: "はっせん", 9: "きゅうせん"}
_HYAK = {1: "ひゃく", 2: "にひゃく", 3: "さんびゃく", 4: "よんひゃく", 5: "ごひゃく", 6: "ろっぴゃく", 7: "ななひゃく", 8: "はっぴゃく", 9: "きゅうひゃく"}


def int_to_jp(n: int) -> str:
    if n == 0:
        return "ゼロ"
    if n < 0:
        return "マイナス" + int_to_jp(-n)
    result = ""
    if n >= 100_000_000:
        result += int_to_jp(n // 100_000_000) + "おく"
        n %= 100_000_000
    if n >= 10_000:
        result += int_to_jp(n // 10_000) + "まん"
        n %= 10_000
    if n >= 1_000:
        result += _SEN[n // 1_000]
        n %= 1_000
    if n >= 100:
        result += _HYAK[n // 100]
        n %= 100
    if n >= 10:
        tens = n // 10
        result += ("" if tens == 1 else _UNIT[tens]) + "じゅう"
        n %= 10
    if n > 0:
        result += _UNIT[n]
    return result


def numerals_to_jp(text: str) -> str:
    """Convert standalone Arabic numerals to Japanese readings for TTS."""

    def repl(match: re.Match[str]) -> str:
        raw = match.group(0)
        try:
            return int_to_jp(int(raw.replace(",", "")))
        except ValueError:
            return raw

    return re.sub(r"(?<![A-Za-z_])(?:\d{1,3}(?:,\d{3})+|\d+)", repl, text)


def normalize_numeric_ranges(text: str) -> str:
    """Make numeric ranges explicit before number reading conversion."""
    return re.sub(
        r"(?<![A-Za-z_])(\d{1,3}(?:,\d{3})*|\d+)\s*[〜～~\-－]\s*(\d{1,3}(?:,\d{3})*|\d+)",
        r"\1から\2",
        text,
    )


def normalize_native_counters(text: str) -> str:
    """Normalize common native Japanese counters before Arabic numerals."""
    readings = {
        "1": "ひとつ",
        "2": "ふたつ",
        "3": "みっつ",
        "4": "よっつ",
        "5": "いつつ",
        "6": "むっつ",
        "7": "ななつ",
        "8": "やっつ",
        "9": "ここのつ",
    }

    def repl(match: re.Match[str]) -> str:
        return readings.get(match.group(1), match.group(0))

    return re.sub(r"(?<![A-Za-z_])([1-9])つ", repl, text)


def normalize_duration_phrases(text: str) -> str:
    """Avoid TTS engines hanging or misreading common duration phrases."""
    text = re.sub(r"(?<![A-Za-z_])90\s*日間", "三か月間", text)
    text = re.sub(r"(?<![A-Za-z_])90\s*日", "三か月", text)
    minute_readings = {
        "1": "いっぷん",
        "2": "にふん",
        "3": "さんぷん",
        "4": "よんぷん",
        "5": "ごふん",
        "6": "ろっぷん",
        "7": "ななふん",
        "8": "はっぷん",
        "9": "きゅうふん",
        "10": "じゅっぷん",
    }

    def minute_repl(match: re.Match[str]) -> str:
        raw = match.group(1)
        return minute_readings.get(raw, f"{int_to_jp(int(raw))}ふん")

    text = re.sub(r"(?<![A-Za-z_])(\d{1,2})\s*分", minute_repl, text)
    return text


def normalize_english_money_phrases(text: str) -> str:
    """Normalize common monetization shorthand that Japanese TTS handles poorly."""

    def k_or_bust(match: re.Match[str]) -> str:
        amount = int(match.group(1).replace(",", "")) * 1000
        return f"{int_to_jp(amount)}ドル達成か失敗か"

    def k_amount(match: re.Match[str]) -> str:
        amount = int(match.group(1).replace(",", "")) * 1000
        return f"{int_to_jp(amount)}ドル"

    text = re.sub(r"\$?\s*(\d{1,3}(?:,\d{3})*|\d+)\s*[kK]\s*,?\s*or\s*,?\s*bust", k_or_bust, text, flags=re.IGNORECASE)
    text = re.sub(r"\$?\s*(\d{1,3}(?:,\d{3})*|\d+)\s*[kK](?![A-Za-z])", k_amount, text)
    text = re.sub(r"\bper\s+month\b|\b/month\b", "毎月", text, flags=re.IGNORECASE)
    return text


def _load_user_dictionary(path: Path | None = None) -> dict[str, str]:
    path = path or Path(os.environ.get("KURAGE_TTS_DICTIONARY", DEFAULT_DICTIONARY_PATH))
    if not path.exists():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        print(f"[tts-normalizer] failed to load dictionary {path}: {exc}", file=sys.stderr)
        return {}

    if isinstance(data, dict) and isinstance(data.get("entries"), list):
        items: dict[str, str] = {}
        for entry in data["entries"]:
            if not isinstance(entry, dict) or entry.get("enabled") is False:
                continue
            surface = str(entry.get("surface") or entry.get("word") or "").strip()
            reading = str(entry.get("reading") or "").strip()
            if surface and reading:
                items[surface] = reading
        return items
    if isinstance(data, dict):
        return {str(k): str(v) for k, v in data.items() if str(k).strip() and str(v).strip()}
    return {}


def _compile_entries(user_dictionary: dict[str, str] | None = None) -> list[tuple[str, str]]:
    entries = dict(DEFAULT_PRONUNCIATIONS)
    entries.update(user_dictionary or _load_user_dictionary())
    return sorted(entries.items(), key=lambda item: len(item[0]), reverse=True)


def _replace_surface(text: str, surface: str, reading: str) -> str:
    # ASCII-ish terms should not fire inside longer identifiers.
    if re.fullmatch(r"[A-Za-z0-9_+.#/ -]+", surface):
        pattern = re.compile(rf"(?<![A-Za-z0-9_]){re.escape(surface)}(?![A-Za-z0-9_])", re.IGNORECASE)
        return pattern.sub(reading, text)
    return text.replace(surface, reading)


def normalize_tts_text(text: str, *, dictionary_path: Path | None = None, convert_numbers: bool = True) -> str:
    """Return a TTS-only string with predictable Japanese readings."""
    normalized = text or ""
    for src, dst in DEFAULT_REPLACEMENTS.items():
        normalized = normalized.replace(src, dst)
    user_dict = _load_user_dictionary(dictionary_path)
    for surface, reading in _compile_entries(user_dict):
        normalized = _replace_surface(normalized, surface, reading)
    normalized = normalize_english_money_phrases(normalized)
    normalized = normalize_numeric_ranges(normalized)
    normalized = normalize_native_counters(normalized)
    normalized = normalize_duration_phrases(normalized)
    if convert_numbers:
        normalized = numerals_to_jp(normalized)
    # Edge-TTS handles punctuation, but long ASCII separators often sound odd.
    normalized = normalized.replace("/", "、").replace("_", " ")
    normalized = re.sub(r"[ \t]{2,}", " ", normalized).strip()
    return normalized


def main() -> int:
    parser = argparse.ArgumentParser(description="Normalize Japanese text before TTS.")
    parser.add_argument("text", nargs="*", help="Text to normalize. Reads stdin when omitted.")
    parser.add_argument("--dictionary", type=Path, default=None)
    parser.add_argument("--no-numbers", action="store_true")
    parser.add_argument("--json", action="store_true")
    args = parser.parse_args()

    src = " ".join(args.text) if args.text else sys.stdin.read()
    normalized = normalize_tts_text(src, dictionary_path=args.dictionary, convert_numbers=not args.no_numbers)
    if args.json:
        print(json.dumps({"input": src, "normalized": normalized}, ensure_ascii=False))
    else:
        print(normalized)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
