"""TTS narration generation."""
from __future__ import annotations
import asyncio
import os
import re
import shutil
import subprocess
import time
from pathlib import Path
from tempfile import NamedTemporaryFile

from tts_normalizer import normalize_tts_text as normalize_text_for_tts, numerals_to_jp

TTS_VOICE = "ja-JP-NanamiNeural"
TTS_RATE  = "+10%"
TTS_PITCH = "-15Hz"
TTS_BACKEND = os.environ.get("KURAGE_TTS_BACKEND", "edge").strip().lower()
VOICEBOX_API = os.environ.get("VOICEBOX_API", "http://192.168.0.11:17493").rstrip("/")
VOICEBOX_PROFILE_ID = os.environ.get("VOICEBOX_PROFILE_ID", "1fe9e00c-cc81-4b07-8884-24acf639ef5e")
VOICEBOX_ENGINE = os.environ.get("VOICEBOX_ENGINE", "qwen")
VOICEBOX_TIMEOUT = int(os.environ.get("VOICEBOX_TIMEOUT", "900"))
VOICEBOX_GENERATION_TIMEOUT = int(os.environ.get("VOICEBOX_GENERATION_TIMEOUT", "180"))


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
    """Generate narration audio. Returns duration seconds, or 0.0 on failure."""
    if TTS_BACKEND == "voicebox":
        duration = run_voicebox_tts(text, output_path)
        if duration > 0:
            return duration
        print("  [tts] voicebox failed; falling back to edge-tts", flush=True)
    return run_edge_tts(text, output_path)


def run_edge_tts(text: str, output_path: Path) -> float:
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


def run_voicebox_tts(text: str, output_path: Path) -> float:
    """Voicebox cloned-voice TTS, converted to the requested output format."""
    import requests

    text = prepare_prosody_text(text)
    if not text:
        return 0.0

    print(
        f"  [tts] generating (voicebox:{VOICEBOX_ENGINE}, profile={VOICEBOX_PROFILE_ID[:8]}...): {text[:60]}...",
        flush=True,
    )
    payload = {
        "profile_id": VOICEBOX_PROFILE_ID,
        "text": text,
        "language": "ja",
        "engine": VOICEBOX_ENGINE,
        "personality": False,
        "max_chunk_chars": 800,
        "crossfade_ms": 50,
        "normalize": True,
    }

    try:
        response = requests.post(f"{VOICEBOX_API}/generate", json=payload, timeout=60)
        response.raise_for_status()
        generation = response.json()
        generation_id = generation.get("id")
        if not generation_id:
            print(f"  [tts] voicebox failed: missing generation id: {generation}", flush=True)
            return 0.0

        deadline = time.time() + min(VOICEBOX_TIMEOUT, VOICEBOX_GENERATION_TIMEOUT)
        history = generation
        while time.time() < deadline:
            status = history.get("status")
            if status == "completed":
                break
            if status == "failed":
                print(f"  [tts] voicebox failed: {history.get('error')}", flush=True)
                return 0.0
            time.sleep(2.0)
            history_response = requests.get(f"{VOICEBOX_API}/history/{generation_id}", timeout=30)
            history_response.raise_for_status()
            history = history_response.json()
        else:
            print(f"  [tts] voicebox timed out after {VOICEBOX_TIMEOUT}s: {generation_id}", flush=True)
            return 0.0

        audio_response = requests.get(f"{VOICEBOX_API}/audio/{generation_id}", timeout=60)
        audio_response.raise_for_status()

        output_path.parent.mkdir(parents=True, exist_ok=True)
        with NamedTemporaryFile(suffix=".wav", dir=str(output_path.parent), delete=False) as tmp:
            tmp.write(audio_response.content)
            tmp_path = Path(tmp.name)

        try:
            subprocess.run(
                [
                    "ffmpeg",
                    "-y",
                    "-hide_banner",
                    "-loglevel",
                    "error",
                    "-i",
                    str(tmp_path),
                    "-codec:a",
                    "libmp3lame",
                    "-q:a",
                    "2",
                    str(output_path),
                ],
                check=True,
            )
        finally:
            tmp_path.unlink(missing_ok=True)

        if not output_path.exists():
            print("  [tts] voicebox failed: output not created", flush=True)
            return 0.0
        duration = get_audio_duration(output_path)
        print(f"  [tts] {output_path.name} ({duration:.1f}s, voicebox)", flush=True)
        return duration
    except Exception as exc:
        print(f"  [tts] voicebox exception: {exc}", flush=True)
        return 0.0


def normalize_tts_text(text: str) -> str:
    return normalize_text_for_tts(text)


def generate_scene_narration_audio(scenes: list[dict], project_dir: Path) -> float:
    """全シーンのナレーションを連結して1つのmp3を生成。秒数を返す"""
    if TTS_BACKEND == "voicebox":
        return generate_scene_narration_audio_voicebox(scenes, project_dir)

    narration_text = "\n".join(
        prepare_prosody_text(scene.get("narration", "")) for scene in scenes if scene.get("narration")
    )
    if not narration_text.strip():
        return 0.0

    output_path = project_dir / "narration.mp3"
    return run_tts(narration_text, output_path)


def generate_scene_narration_audio_voicebox(scenes: list[dict], project_dir: Path) -> float:
    """Generate Voicebox narration per scene, then concatenate.

    Chatterbox can become unnaturally compressed on long Japanese text. Per-scene
    generation keeps pacing closer to the script and makes failures local.
    """
    output_path = project_dir / "narration.mp3"
    parts_dir = project_dir / "narration_parts"
    if parts_dir.exists():
        shutil.rmtree(parts_dir)
    parts_dir.mkdir(parents=True, exist_ok=True)

    part_paths: list[Path] = []
    for i, scene in enumerate(scenes):
        text = str(scene.get("narration") or "").strip()
        if not text:
            continue
        part_path = parts_dir / f"scene_{i:02d}.mp3"
        duration = run_voicebox_tts(text, part_path)
        if duration <= 0:
            raise RuntimeError(f"Voicebox TTS failed for scene {i}; refusing fallback for voicebox mode")
        min_duration = max(1.5 if len(text) <= 18 else 2.5, len(text) / 16.0)
        if duration < min_duration:
            raise RuntimeError(
                f"Voicebox TTS output is too short for scene {i}: "
                f"{duration:.1f}s for {len(text)} chars (minimum {min_duration:.1f}s)"
            )
        part_paths.append(part_path)

    if not part_paths:
        return 0.0

    concat_list = parts_dir / "concat.txt"
    concat_list.write_text(
        "\n".join(f"file '{p.as_posix()}'" for p in part_paths) + "\n",
        encoding="utf-8",
    )
    tmp_output = output_path.with_suffix(".concat.mp3")
    subprocess.run(
        [
            "ffmpeg",
            "-y",
            "-hide_banner",
            "-loglevel",
            "error",
            "-f",
            "concat",
            "-safe",
            "0",
            "-i",
            str(concat_list),
            "-c:a",
            "libmp3lame",
            "-q:a",
            "2",
            str(tmp_output),
        ],
        check=True,
    )
    tmp_output.replace(output_path)
    duration = get_audio_duration(output_path)
    print(f"  [tts] {output_path.name} ({duration:.1f}s, voicebox scenes={len(part_paths)})", flush=True)
    return duration
