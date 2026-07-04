from __future__ import annotations

import json
import subprocess
import time
from pathlib import Path
from tempfile import NamedTemporaryFile
from typing import Any

import requests


TRANSIENT_GPU_ERRORS = (
    "cudnn",
    "cuda",
    "cublas",
    "out of memory",
    "internal_error",
)


def _recover_voicebox(api: str, error: str) -> None:
    """Best-effort recovery for transient GPU/model failures before retry."""
    message = str(error or "").lower()
    try:
        requests.post(f"{api}/models/unload", timeout=20)
    except Exception:
        pass
    if any(token in message for token in TRANSIENT_GPU_ERRORS):
        try:
            # The dedicated Voicebox host is expected to be supervised. Dropping
            # the connection here is OK; the next retry gets a clean model load.
            requests.post(f"{api}/shutdown", timeout=5)
        except Exception:
            pass


def _duration(path: Path) -> float:
    proc = subprocess.run(
        [
            "ffprobe",
            "-v",
            "error",
            "-show_entries",
            "format=duration",
            "-of",
            "default=noprint_wrappers=1:nokey=1",
            str(path),
        ],
        text=True,
        capture_output=True,
        timeout=60,
    )
    try:
        return float(proc.stdout.strip())
    except Exception:
        return 0.0


def voicebox_tts_job(
    text: str,
    output_path: str,
    voicebox_api: str = "http://192.168.0.11:17493",
    profile_id: str = "1fe9e00c-cc81-4b07-8884-24acf639ef5e",
    engine: str = "qwen",
    language: str = "ja",
    timeout_seconds: int = 600,
    **_: Any,
) -> dict[str, Any]:
    """RQDB4AI entrypoint for serialized Voicebox TTS generation.

    Keep this in the Kurage repository; rqdb4ai remains a generic queue runner.
    The worker writes the MP3 to the shared filesystem path requested by Kurage.
    """
    text = str(text or "").strip()
    if not text:
        raise RuntimeError("text is required")
    output = Path(output_path).expanduser().resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    api = str(voicebox_api or "").rstrip("/")
    if not api:
        raise RuntimeError("voicebox_api is required")

    payload = {
        "profile_id": profile_id,
        "text": text,
        "language": language,
        "engine": engine,
        "personality": False,
        "max_chunk_chars": 800,
        "crossfade_ms": 50,
        "normalize": True,
    }

    response = requests.post(f"{api}/generate", json=payload, timeout=60)
    response.raise_for_status()
    generation = response.json()
    generation_id = generation.get("id")
    if not generation_id:
        raise RuntimeError(json.dumps({"error": "missing_generation_id", "response": generation}, ensure_ascii=False))

    deadline = time.time() + int(timeout_seconds)
    history = generation
    while time.time() < deadline:
        status = history.get("status")
        if status == "completed":
            break
        if status == "failed":
            error = str(history.get("error") or history)
            _recover_voicebox(api, error)
            raise RuntimeError(error)
        time.sleep(2.0)
        hres = requests.get(f"{api}/history/{generation_id}", timeout=30)
        hres.raise_for_status()
        history = hres.json()
    else:
        try:
            requests.post(f"{api}/generate/{generation_id}/cancel", timeout=10)
        except Exception:
            pass
        _recover_voicebox(api, "voicebox generation timed out")
        raise TimeoutError(f"voicebox generation timed out: {generation_id}")

    audio_response = requests.get(f"{api}/audio/{generation_id}", timeout=60)
    audio_response.raise_for_status()

    with NamedTemporaryFile(suffix=".wav", dir=str(output.parent), delete=False) as tmp:
        tmp.write(audio_response.content)
        tmp_path = Path(tmp.name)

    tmp_mp3 = output.with_name(output.stem + ".tmp.mp3")
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
                str(tmp_mp3),
            ],
            check=True,
            timeout=120,
        )
        tmp_mp3.replace(output)
    finally:
        tmp_path.unlink(missing_ok=True)
        tmp_mp3.unlink(missing_ok=True)

    duration = _duration(output)
    if duration <= 0:
        raise RuntimeError(f"invalid generated audio duration: {output}")
    return {
        "ok": True,
        "output_path": str(output),
        "duration": duration,
        "bytes": output.stat().st_size,
        "generation_id": generation_id,
        "engine": engine,
        "voicebox_api": api,
    }
