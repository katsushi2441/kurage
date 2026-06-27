"""TTS narration generation using edge-tts."""
from __future__ import annotations
import asyncio
import re
import subprocess
from pathlib import Path

from tts_normalizer import normalize_tts_text as normalize_text_for_tts, numerals_to_jp

TTS_VOICE = "ja-JP-NanamiNeural"
TTS_RATE  = "+10%"
TTS_PITCH = "-15Hz"


def prepare_prosody_text(text: str) -> str:
    """Add TTS-only punctuation so Japanese neural voices keep natural phrasing."""
    text = normalize_text_for_tts(text)
    text = re.sub(r"[ \t\u3000]+", " ", text).strip()
    if not text:
        return ""

    # Japanese TTS often sounds flat when clauses are separated only by spaces.
    text = re.sub(r"(です|ます|ました|ません|でしょう|ください|できます|ありません)\s+", r"\1。", text)
    text = re.sub(r"(?<=[ぁ-んァ-ヶ一-龥ー])\s+(?=[ぁ-んァ-ヶ一-龥ー])", "、", text)
    text = re.sub(r"\s+", "、", text)
    text = re.sub(r"、+([。！？!?])", r"\1", text)
    text = re.sub(r"。+", "。", text)
    if text[-1] not in "。！？!?":
        text += "。"
    return text


def get_audio_duration(path: Path) -> float:
    """ffprobe で音声の長さを取得（秒）"""
    try:
        r = subprocess.run(
            ['ffprobe', '-v', 'error', '-show_entries', 'format=duration',
             '-of', 'default=noprint_wrappers=1:nokey=1', str(path)],
            capture_output=True, text=True,
        )
        return float(r.stdout.strip())
    except Exception:
        return 0.0


def run_tts(text: str, output_path: Path) -> float:
    """edge-tts でナレーション音声を生成。成功時は秒数、失敗時は 0.0 を返す"""
    import edge_tts

    text = prepare_prosody_text(text)
    print(f"  [tts] generating ({TTS_VOICE}): {text[:60]}...", flush=True)

    async def _gen():
        communicate = edge_tts.Communicate(text, voice=TTS_VOICE, rate=TTS_RATE, pitch=TTS_PITCH)
        await communicate.save(str(output_path))

    asyncio.run(_gen())

    if not output_path.exists():
        print("  [tts] failed: output not created", flush=True)
        return 0.0

    duration = get_audio_duration(output_path)
    print(f"  [tts] {output_path.name} ({duration:.1f}s)", flush=True)
    return duration


def normalize_tts_text(text: str) -> str:
    return normalize_text_for_tts(text)


def generate_scene_narration_audio(scenes: list[dict], project_dir: Path) -> float:
    """全シーンのナレーションを連結して1つのmp3を生成。秒数を返す"""
    narration_text = "\n".join(
        prepare_prosody_text(scene.get("narration", "")) for scene in scenes if scene.get("narration")
    )
    if not narration_text.strip():
        return 0.0

    output_path = project_dir / "narration.mp3"
    return run_tts(narration_text, output_path)
