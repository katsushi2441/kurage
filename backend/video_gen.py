"""Generate video from script + images using HyperFrames CLI."""
from __future__ import annotations
import html
import json
import math
import os
import shutil
import subprocess
from pathlib import Path
from config import NVM_NODE, HYPERFRAMES_VERSION, ROOT
from tts_gen import generate_scene_narration_audio


HF_TEMPLATE = ROOT / "hyperframes" / "aixec-health-book" / "hyperframes.json"


def build_html(script: dict, image_paths: list[Path], total_dur: float,
               narration_duration: float = 0.0) -> str:
    """Build HyperFrames index.html for a short drama video (576x1024 vertical)."""
    scenes = script.get("scenes") or []
    title = html.escape(script.get("title") or "Kurage Video")

    # Cumulative timing — ナレーションがある場合はtotal_durを均等割り
    scene_timing = []
    if narration_duration > 0 and len(scenes) > 0:
        per_scene = total_dur / len(scenes)
        t = 0.0
        for scene in scenes:
            scene_timing.append((t, per_scene))
            t += per_scene
    else:
        t = 0.0
        for scene in scenes:
            dur = float(scene.get("duration") or 6)
            scene_timing.append((t, dur))
            t += dur

    # Build scene HTML blocks
    scene_blocks = []
    for i, (scene, (start, dur)) in enumerate(zip(scenes, scene_timing)):
        img_src = f"assets/scene_{i:02d}.png"
        narration = html.escape(scene.get("narration") or "")
        scene_blocks.append(f"""
    <!-- Scene {i} ({start:.1f}s - {start+dur:.1f}s) -->
    <div class="scene clip" id="scene-{i}"
         data-start="{start:.2f}" data-duration="{dur:.2f}">
      <img class="scene-bg" src="{img_src}" alt="scene {i}">
      <div class="scene-text">{narration}</div>
    </div>""")

    scenes_html = "\n".join(scene_blocks)

    # GSAP animation: cross-fade between scenes
    gsap_scenes = []
    for i, (start, dur) in enumerate(scene_timing):
        end = start + dur
        fade_in = start
        fade_out = end - 0.5
        gsap_scenes.append(f"""
  // Scene {i}
  tl.to("#scene-{i}", {{opacity:1, duration:0.5}}, {fade_in:.2f})
    .to("#scene-{i} .scene-text", {{opacity:1, y:0, duration:0.4}}, {fade_in + 0.3:.2f})
    .to("#scene-{i}", {{opacity:0, duration:0.5}}, {fade_out:.2f});""")

    gsap_js = "\n".join(gsap_scenes)

    audio_tag = ""
    if narration_duration > 0:
        audio_tag = (
            f'\n    <audio id="narration" src="narration.mp3"'
            f'\n           data-start="0" data-duration="{total_dur:.2f}"'
            f'\n           data-track-index="5" data-volume="1"></audio>'
        )

    return f'''<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>{title}</title>
  <script src="https://cdn.jsdelivr.net/npm/gsap@3.14.2/dist/gsap.min.js"></script>
  <style>
    * {{ margin: 0; padding: 0; box-sizing: border-box; }}
    html, body {{
      width: 576px; height: 1024px; overflow: hidden; background: #000;
      font-family: "Noto Sans JP", "Noto Sans JP", sans-serif;
    }}
    #composition {{
      position: relative; width: 576px; height: 1024px; overflow: hidden; background: #000;
    }}
    .scene {{
      position: absolute; top: 0; left: 0; width: 576px; height: 1024px;
      opacity: 0; overflow: hidden;
    }}
    .scene-bg {{
      width: 100%; height: 100%; object-fit: cover; display: block;
    }}
    .scene-text {{
      position: absolute; bottom: 80px; left: 0; right: 0; padding: 0 28px;
      opacity: 0; transform: translateY(20px);
      text-align: center; font-size: 28px; font-weight: 700;
      color: #fff; text-shadow: 0 2px 12px rgba(0,0,0,0.9), 0 0 30px rgba(0,0,0,0.7);
      line-height: 1.5; letter-spacing: 0.02em;
    }}
    #title-overlay {{
      position: absolute; top: 0; left: 0; width: 576px; height: 1024px;
      display: flex; align-items: center; justify-content: center;
      background: rgba(0,0,0,0.6); z-index: 10; opacity: 0;
    }}
    #title-overlay h1 {{
      color: #fff; font-size: 32px; font-weight: 900;
      text-align: center; padding: 0 32px; line-height: 1.4;
      text-shadow: 0 2px 8px rgba(0,0,0,0.8);
    }}
  </style>
</head>
<body>
  <div id="composition"
       data-composition-id="main"
       data-width="576"
       data-height="1024">

    <div id="title-overlay">
      <h1>{title}</h1>
    </div>

    {scenes_html}
    {audio_tag}
  </div>

  <script>
  (function() {{
    const tl = gsap.timeline({{ paused: true }});

    // Title
    tl.to("#title-overlay", {{opacity:1, duration:0.4}}, 0)
      .to("#title-overlay", {{opacity:0, duration:0.4}}, 1.5);

    // Scenes
    {gsap_js}

    // HyperFrames timeline registry
    window.__timelines = window.__timelines || {{}};
    window.__timelines["main"] = tl;
  }})();
  </script>
</body>
</html>
'''


def create_hf_project(job_dir: Path, script: dict, image_paths: list[Path]) -> Path:
    """Create a HyperFrames project directory ready for rendering."""
    project_dir = job_dir / "hf_project"
    project_dir.mkdir(parents=True, exist_ok=True)

    # Copy assets
    assets_dir = project_dir / "assets"
    assets_dir.mkdir(exist_ok=True)
    for i, img in enumerate(image_paths):
        dest = assets_dir / f"scene_{i:02d}.png"
        shutil.copy(img, dest)

    # Total duration
    scenes = script.get("scenes") or []
    scene_dur = sum(float(s.get("duration") or 6) for s in scenes)

    # TTS narration
    narration_duration = generate_scene_narration_audio(scenes, project_dir)
    if narration_duration > 0:
        total_dur = math.ceil(narration_duration) + 1
        print(f"  [video] narration {narration_duration:.1f}s → total {total_dur}s", flush=True)
    else:
        total_dur = scene_dur

    # Write index.html
    html_content = build_html(script, image_paths, total_dur, narration_duration)
    (project_dir / "index.html").write_text(html_content, encoding="utf-8")

    # Copy hyperframes.json template
    if HF_TEMPLATE.exists():
        shutil.copy(HF_TEMPLATE, project_dir / "hyperframes.json")
    else:
        (project_dir / "hyperframes.json").write_text(json.dumps({
            "$schema": "https://hyperframes.heygen.com/schema/hyperframes.json",
            "registry": "https://raw.githubusercontent.com/heygen-com/hyperframes/main/registry",
            "paths": {"blocks": "compositions", "components": "compositions/components", "assets": "assets"},
        }, indent=2), encoding="utf-8")

    # package.json
    (project_dir / "package.json").write_text(json.dumps({
        "name": f"kurage-{job_dir.name}",
        "private": True,
        "type": "module",
        "scripts": {
            "render": f"npx --yes hyperframes@{HYPERFRAMES_VERSION} render",
        },
    }, indent=2), encoding="utf-8")

    # meta.json
    (project_dir / "meta.json").write_text(json.dumps({
        "id": job_dir.name,
        "name": script.get("title") or "Kurage Video",
    }, indent=2), encoding="utf-8")

    return project_dir


def render_video(project_dir: Path, output_path: Path) -> Path:
    """Run hyperframes render and copy output to output_path."""
    renders_dir = project_dir / "renders"
    renders_dir.mkdir(exist_ok=True)

    env = dict(os.environ)
    env["PATH"] = NVM_NODE + ":" + env.get("PATH", "")

    cmd = (
        f'source ~/.nvm/nvm.sh 2>/dev/null; '
        f'cd "{project_dir}" && '
        f'npx --yes hyperframes@{HYPERFRAMES_VERSION} render --output "{output_path}"'
    )
    result = subprocess.run(
        ["bash", "-c", cmd],
        cwd=str(project_dir),
        env=env,
        capture_output=True,
        text=True,
        timeout=300,
    )

    if result.returncode != 0:
        raise RuntimeError(
            f"hyperframes render failed (rc={result.returncode})\n"
            f"stdout: {result.stdout[-2000:]}\n"
            f"stderr: {result.stderr[-2000:]}"
        )

    if not output_path.exists():
        # Try to find any MP4 in renders/
        mp4s = sorted(renders_dir.glob("*.mp4"), key=lambda p: p.stat().st_mtime)
        if mp4s:
            shutil.copy(mp4s[-1], output_path)
        else:
            raise RuntimeError(f"No MP4 found after render in {renders_dir}")

    return output_path


def _find_japanese_font() -> str:
    candidates = [
        "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc",
        "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc",
        "/usr/share/fonts/truetype/noto/NotoSansCJK-Bold.ttc",
        "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
    ]
    for path in candidates:
        if Path(path).exists():
            return path
    return "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"


def _wrap_text(draw, text: str, font, max_width: int) -> list[str]:
    chars = list((text or "Kurage Video").strip())
    lines: list[str] = []
    cur = ""
    for ch in chars:
        trial = cur + ch
        if draw.textbbox((0, 0), trial, font=font)[2] <= max_width or not cur:
            cur = trial
        else:
            lines.append(cur)
            cur = ch
    if cur:
        lines.append(cur)
    return lines[:3]


def _overlay_thumbnail_title(image_path: Path, title: str | None) -> None:
    from PIL import Image, ImageDraw, ImageFont, ImageFilter

    img = Image.open(image_path).convert("RGB")
    w, h = img.size
    overlay = Image.new("RGBA", img.size, (0, 0, 0, 0))
    od = ImageDraw.Draw(overlay)

    od.rectangle((0, int(h * 0.58), w, h), fill=(0, 0, 0, 115))
    od.rectangle((0, 0, w, int(h * 0.15)), fill=(0, 0, 0, 75))
    overlay = overlay.filter(ImageFilter.GaussianBlur(radius=1.5))
    img = Image.alpha_composite(img.convert("RGBA"), overlay)
    draw = ImageDraw.Draw(img)

    font_path = _find_japanese_font()
    main_font = ImageFont.truetype(font_path, max(40, int(w * 0.115)))
    sub_font = ImageFont.truetype(font_path, max(18, int(w * 0.045)))
    badge_font = ImageFont.truetype(font_path, max(16, int(w * 0.04)))

    badge = "Kurage AI Video"
    bx, by = int(w * 0.06), int(h * 0.055)
    bb = draw.textbbox((0, 0), badge, font=badge_font)
    draw.rounded_rectangle(
        (bx - 12, by - 8, bx + (bb[2] - bb[0]) + 12, by + (bb[3] - bb[1]) + 10),
        radius=10,
        fill=(0, 127, 150, 230),
    )
    draw.text((bx, by), badge, font=badge_font, fill=(255, 255, 255, 255), stroke_width=1, stroke_fill=(0, 0, 0, 180))

    text = (title or "Kurage Video").replace(" | ", " ").replace("｜", " ")
    text = text.split("\n", 1)[0][:34]
    lines = _wrap_text(draw, text, main_font, int(w * 0.88))
    line_h = int(main_font.size * 1.22)
    total_h = line_h * len(lines)
    y = int(h * 0.70) - total_h // 2

    for i, line in enumerate(lines):
        tb = draw.textbbox((0, 0), line, font=main_font, stroke_width=4)
        x = (w - (tb[2] - tb[0])) // 2
        fill = (255, 230, 48, 255) if i == 0 else (255, 255, 255, 255)
        draw.text((x, y + i * line_h), line, font=main_font, fill=fill, stroke_width=5, stroke_fill=(0, 0, 0, 235))

    sub = "AIが動画化"
    sb = draw.textbbox((0, 0), sub, font=sub_font)
    sx = (w - (sb[2] - sb[0])) // 2
    sy = min(h - int(h * 0.105), y + total_h + int(h * 0.025))
    draw.rounded_rectangle((sx - 18, sy - 8, sx + (sb[2] - sb[0]) + 18, sy + (sb[3] - sb[1]) + 10), radius=12, fill=(255, 255, 255, 220))
    draw.text((sx, sy), sub, font=sub_font, fill=(18, 24, 32, 255))

    img.convert("RGB").save(image_path, "JPEG", quality=92, optimize=True)


def generate_thumbnail(video_path: Path, output_path: Path, seek: float = 3.0, title: str | None = None) -> Path:
    """Extract a stable poster frame from a generated video."""
    output_path.parent.mkdir(parents=True, exist_ok=True)
    cmd = [
        "ffmpeg",
        "-y",
        "-ss",
        f"{seek:.2f}",
        "-i",
        str(video_path),
        "-frames:v",
        "1",
        "-q:v",
        "3",
        str(output_path),
    ]
    result = subprocess.run(cmd, capture_output=True, text=True, timeout=60)
    if result.returncode != 0 or not output_path.exists() or output_path.stat().st_size <= 0:
        raise RuntimeError(
            f"thumbnail generation failed (rc={result.returncode})\n"
            f"stdout: {result.stdout[-1000:]}\n"
            f"stderr: {result.stderr[-1000:]}"
        )
    _overlay_thumbnail_title(output_path, title)
    return output_path


def generate_video(script: dict, image_paths: list[Path], job_dir: Path) -> Path:
    """Full video generation pipeline: project setup + render.

    Returns:
        Path to the output MP4 file
    """
    output_path = job_dir / "output.mp4"
    project_dir = create_hf_project(job_dir, script, image_paths)
    print(f"  [video] HyperFrames project: {project_dir}", flush=True)
    render_video(project_dir, output_path)
    print(f"  [video] Rendered: {output_path} ({output_path.stat().st_size} bytes)", flush=True)
    thumb_path = generate_thumbnail(output_path, job_dir / "thumbnail.jpg", title=script.get("title"))
    print(f"  [video] Thumbnail: {thumb_path} ({thumb_path.stat().st_size} bytes)", flush=True)
    return output_path
