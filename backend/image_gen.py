"""Generate images per scene using ERNIE-Image-Turbo."""
from __future__ import annotations
import base64
import hashlib
import io
import os
import time
import requests
from pathlib import Path
from PIL import Image
from config import ERNIE_URL
from character_identity import CHARACTER_SEED, should_use_kurage_character, with_kurage_character


ERNIE_CONNECT_TIMEOUT = float(os.environ.get("ERNIE_CONNECT_TIMEOUT", "15"))
ERNIE_READ_TIMEOUT = float(os.environ.get("ERNIE_READ_TIMEOUT", "900"))
ERNIE_MAX_ATTEMPTS = max(1, int(os.environ.get("ERNIE_MAX_ATTEMPTS", "3")))
ERNIE_RETRY_BACKOFF = max(0.0, float(os.environ.get("ERNIE_RETRY_BACKOFF", "15")))


def is_valid_image(path: Path) -> bool:
    if not path.exists() or path.stat().st_size < 1024:
        return False
    try:
        with Image.open(path) as image:
            image.verify()
        return True
    except Exception:
        return False


def _retryable_error(exc: Exception) -> bool:
    if isinstance(exc, (requests.Timeout, requests.ConnectionError)):
        return True
    if isinstance(exc, requests.HTTPError) and exc.response is not None:
        return exc.response.status_code == 429 or exc.response.status_code >= 500
    return isinstance(exc, (ValueError, OSError))


def _validated_image_bytes(image_b64: str) -> bytes:
    try:
        content = base64.b64decode(image_b64, validate=True)
        with Image.open(io.BytesIO(content)) as image:
            image.verify()
    except Exception as exc:
        raise ValueError(f"ERNIE returned invalid image data: {exc}") from exc
    if len(content) < 1024:
        raise ValueError(f"ERNIE returned an unexpectedly small image: {len(content)} bytes")
    return content


def _cache_key(prompt: str, width: int, height: int) -> str:
    value = f"v1\n{width}x{height}\n{prompt}".encode("utf-8")
    return hashlib.sha256(value).hexdigest()


def generate_or_reuse_image(prompt: str, output_path: Path, width: int = 384, height: int = 384) -> Path:
    cache_path = output_path.with_suffix(output_path.suffix + ".sha256")
    expected_key = _cache_key(prompt, width, height)
    if is_valid_image(output_path) and cache_path.exists() and cache_path.read_text(encoding="ascii").strip() == expected_key:
        print(f"  [image] reusing verified cache: {output_path.name}", flush=True)
        return output_path
    output_path.unlink(missing_ok=True)
    cache_path.unlink(missing_ok=True)
    result = generate_image(prompt, output_path, width=width, height=height)
    cache_path.write_text(expected_key + "\n", encoding="ascii")
    return result


def generate_image(prompt: str, output_path: Path, width: int = 384, height: int = 384) -> Path:
    """Generate a single image using ERNIE-Image-Turbo.

    Args:
        prompt: English image prompt
        output_path: Where to save the PNG file

    Returns:
        Path to saved PNG file
    """
    use_character = should_use_kurage_character(prompt)
    prompt = with_kurage_character(prompt)
    negative_prompt = (
        "horror, creepy, ghost, grotesque, gore, blood, bad anatomy, "
        "blurry, low quality, dark horror, zombie, uncanny, watermark, text"
    )
    if use_character:
        negative_prompt += (
            ", different character, different hair color, long hair, blue eyes, "
            "missing hair clips"
        )
    payload = {
        "prompt": prompt,
        "negative_prompt": negative_prompt,
        "width": width,
        "height": height,
        "num_inference_steps": 4,
        "guidance_scale": 1.0,
        "use_pe": False,
        "output_format": "png",
    }
    if use_character:
        payload["seed"] = CHARACTER_SEED

    last_error: Exception | None = None
    for attempt in range(1, ERNIE_MAX_ATTEMPTS + 1):
        try:
            resp = requests.post(
                ERNIE_URL,
                json=payload,
                timeout=(ERNIE_CONNECT_TIMEOUT, ERNIE_READ_TIMEOUT),
                headers={"Accept": "application/json", "Content-Type": "application/json"},
            )
            resp.raise_for_status()
            data = resp.json()
            image_b64 = data.get("image_base64") or ""
            if not image_b64:
                raise ValueError(f"No image_base64 in ERNIE response: {data}")
            content = _validated_image_bytes(image_b64)
            output_path.parent.mkdir(parents=True, exist_ok=True)
            temporary = output_path.with_suffix(output_path.suffix + ".part")
            temporary.write_bytes(content)
            temporary.replace(output_path)
            return output_path
        except Exception as exc:
            last_error = exc
            if attempt >= ERNIE_MAX_ATTEMPTS or not _retryable_error(exc):
                break
            delay = ERNIE_RETRY_BACKOFF * attempt
            print(
                f"  [image] ERNIE attempt {attempt}/{ERNIE_MAX_ATTEMPTS} failed: {exc}; retrying in {delay:.0f}s",
                flush=True,
            )
            time.sleep(delay)
    raise RuntimeError(f"ERNIE image generation failed after {ERNIE_MAX_ATTEMPTS} attempts: {last_error}") from last_error


def generate_scene_images(scenes: list[dict], job_dir: Path) -> list[Path]:
    """Generate images for all scenes.

    Returns:
        List of image paths in scene order
    """
    import time
    assets_dir = job_dir / "assets"
    assets_dir.mkdir(parents=True, exist_ok=True)
    paths = []
    for scene in scenes:
        idx = scene.get("index", len(paths))
        prompt = scene.get("image_prompt", "cinematic vertical shot, beautiful scene")
        out = assets_dir / f"scene_{idx:02d}.png"
        print(f"  [image] scene {idx}: {prompt[:60]}...", flush=True)
        if idx > 0:
            time.sleep(3)
        path = generate_or_reuse_image(prompt, out)
        paths.append(path)
    return paths


if __name__ == "__main__":
    import sys
    prompt = sys.argv[1] if len(sys.argv) > 1 else "cinematic vertical 9:16, Japanese street at night, neon lights, rain"
    out = Path("/tmp/test_ernie.png")
    result = generate_image(prompt, out)
    print(f"Saved: {result} ({result.stat().st_size} bytes)")
