"""Generate images per scene using ERNIE-Image-Turbo."""
from __future__ import annotations
import base64
import requests
from pathlib import Path
from config import ERNIE_URL
from character_identity import CHARACTER_SEED, with_kurage_character


def generate_image(prompt: str, output_path: Path, width: int = 384, height: int = 384) -> Path:
    """Generate a single image using ERNIE-Image-Turbo.

    Args:
        prompt: English image prompt
        output_path: Where to save the PNG file

    Returns:
        Path to saved PNG file
    """
    prompt = with_kurage_character(prompt)
    payload = {
        "prompt": prompt,
        "negative_prompt": (
            "horror, creepy, ghost, grotesque, gore, blood, bad anatomy, "
            "blurry, low quality, dark horror, zombie, uncanny, watermark, text, "
            "different character, different hair color, long hair, blue eyes, missing hair clips"
        ),
        "width": width,
        "height": height,
        "num_inference_steps": 4,
        "guidance_scale": 1.0,
        "use_pe": False,
        "seed": CHARACTER_SEED,
        "output_format": "png",
    }

    resp = requests.post(
        ERNIE_URL,
        json=payload,
        timeout=300,
        headers={"Accept": "application/json", "Content-Type": "application/json"},
    )
    resp.raise_for_status()
    data = resp.json()

    image_b64 = data.get("image_base64") or ""
    if not image_b64:
        raise ValueError(f"No image_base64 in ERNIE response: {data}")

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_bytes(base64.b64decode(image_b64))
    return output_path


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
        path = generate_image(prompt, out)
        paths.append(path)
    return paths


if __name__ == "__main__":
    import sys
    prompt = sys.argv[1] if len(sys.argv) > 1 else "cinematic vertical 9:16, Japanese street at night, neon lights, rain"
    out = Path("/tmp/test_ernie.png")
    result = generate_image(prompt, out)
    print(f"Saved: {result} ({result.stat().st_size} bytes)")
