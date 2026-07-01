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
# kvtuber owns the canonical Kurage avatar. Other Kurage products reference it
# directly instead of carrying stale copies.
AVATAR_LIPSYNC_DIR = Path(os.environ.get("KURAGE_AVATAR_LIPSYNC_DIR", "/home/kojima/work/kvtuber/public/avatar/lipsync"))
AVATAR_FRAMES = [AVATAR_LIPSYNC_DIR / f"kurage_mouth_{i}.png" for i in range(5)]
AVATAR_OVERLAY_DEFAULT = os.environ.get("KURAGE_AVATAR_OVERLAY_DEFAULT", "1").lower() not in {"0", "false", "no", "off"}


def _avatar_asset_paths() -> list[Path]:
    """Return avatar assets when the local PNG-tuber set is available."""
    return [p for p in AVATAR_FRAMES if p.exists()]


def _should_show_avatar(vtuber_mode: bool) -> bool:
    """Kurage/Horizon videos show the canonical Kurage avatar by default."""
    return bool(vtuber_mode or AVATAR_OVERLAY_DEFAULT)


def _build_vtuber_overlay(total_dur: float, title: str) -> tuple[str, str, str]:
    """HTML/CSS/GSAP snippets for the branded Kurage VTuber explainer layer."""
    safe_title = html.escape(title)
    overlay_html = f"""
    <div id="vtuber-layer" aria-label="Kurage VTuber explainer overlay">
      <div class="vtuber-badge">Kurage解説</div>
      <div class="vtuber-card">
        <div class="vtuber-copy">
          <div class="vtuber-name">Kurage AI Navigator</div>
          <div class="vtuber-topic">{safe_title}</div>
        </div>
        <div class="vtuber-avatar-wrap">
          <div class="vtuber-glow"></div>
          <img id="vtuber-frame-0" class="vtuber-avatar vtuber-avatar-frame" src="assets/avatar_lipsync_0.png" alt="Kurage avatar">
          <img id="vtuber-frame-1" class="vtuber-avatar vtuber-avatar-frame" src="assets/avatar_lipsync_1.png" alt="">
          <img id="vtuber-frame-2" class="vtuber-avatar vtuber-avatar-frame" src="assets/avatar_lipsync_2.png" alt="">
          <img id="vtuber-frame-3" class="vtuber-avatar vtuber-avatar-frame" src="assets/avatar_lipsync_3.png" alt="">
          <img id="vtuber-frame-4" class="vtuber-avatar vtuber-avatar-frame" src="assets/avatar_lipsync_4.png" alt="">
        </div>
      </div>
    </div>"""

    overlay_css = """
    #vtuber-layer {
      position: absolute; inset: 0; z-index: 22; pointer-events: none;
      font-family: "Noto Sans JP", sans-serif;
    }
    .vtuber-badge {
      position: absolute; top: 24px; right: 22px;
      padding: 8px 13px; border-radius: 999px;
      color: #07536a; background: rgba(255,255,255,0.94);
      border: 1px solid rgba(7,138,166,0.22);
      box-shadow: 0 10px 28px rgba(49, 121, 139, 0.16);
      font-size: 15px; font-weight: 900; letter-spacing: 0.04em;
    }
    .vtuber-card {
      position: absolute; right: 18px; bottom: 22px;
      width: 252px; min-height: 248px; border-radius: 28px;
      background:
        radial-gradient(circle at 78% 12%, rgba(255,255,255,0.92), transparent 28%),
        linear-gradient(145deg, rgba(255,255,255,0.94), rgba(231, 249, 255, 0.9));
      border: 1px solid rgba(7,138,166,0.22);
      box-shadow: 0 20px 48px rgba(49, 121, 139, 0.18), inset 0 1px 0 rgba(255,255,255,0.86);
      overflow: hidden;
    }
    .vtuber-card::before {
      content: ""; position: absolute; inset: auto -40px -70px -30px; height: 142px;
      background: radial-gradient(ellipse at center, rgba(111, 218, 236, 0.34), transparent 70%);
    }
    .vtuber-copy {
      position: absolute; left: 17px; right: 17px; top: 15px; z-index: 2;
      color: #17313a; text-shadow: none;
    }
    .vtuber-name {
      display: inline-block; padding: 5px 9px; border-radius: 999px;
      background: #e5faff; border: 1px solid rgba(7,138,166,0.18);
      color: #078aa6;
      font-size: 13px; font-weight: 900; letter-spacing: 0.03em;
    }
    .vtuber-topic {
      margin-top: 8px; max-height: 45px; overflow: hidden;
      font-size: 16px; font-weight: 900; line-height: 1.35;
    }
    .vtuber-avatar-wrap {
      position: absolute; right: -6px; bottom: -10px;
      width: 224px; height: 224px; z-index: 1;
    }
    .vtuber-glow {
      position: absolute; left: 26px; right: 12px; bottom: 20px; height: 92px;
      border-radius: 999px; background: rgba(102, 211, 230, 0.26);
      filter: blur(16px);
    }
    .vtuber-avatar {
      position: absolute; inset: 0; width: 100%; height: 100%;
      object-fit: contain; filter: drop-shadow(0 16px 22px rgba(49, 121, 139, 0.22));
      transform-origin: 54% 66%;
    }
    .vtuber-avatar-frame {
      opacity: 0;
      transition: none;
    }
    #vtuber-frame-0 {
      opacity: 1;
    }
    body.vtuber-enabled .scene-text {
      bottom: 292px; padding: 0 30px;
    }"""

    mouth_js = []
    t = 2.0
    while t < total_dur:
        level = int((round(t * 100) // 28) % 5)
        mouth_js.append(
            f'    tl.set(".vtuber-avatar-frame", {{opacity:0}}, {t:.2f})'
            f'.set("#vtuber-frame-{level}", {{opacity:1}}, {t:.2f});'
        )
        t += 0.28
    mouth_js.append(f'    tl.set(".vtuber-avatar-frame", {{opacity:0}}, {total_dur:.2f}).set("#vtuber-frame-0", {{opacity:1}}, {total_dur:.2f});')

    overlay_js = f"""
    tl.from("#vtuber-layer", {{opacity:0, y:38, scale:0.96, duration:0.55, ease:"power3.out"}}, 1.15);
{chr(10).join(mouth_js)}"""

    return overlay_html, overlay_css, overlay_js


def _build_stickman_overlay(scene_index: int) -> str:
    """Deterministic SVG stickman overlay for opening explainer scenes."""
    return f"""
      <div class="stickman-layer stickman-layer-{scene_index}" aria-label="stickman explainer animation">
        <div class="stickman-stage">
          <div class="stickman-orbit"></div>
          <svg class="stickman-svg" viewBox="0 0 300 380" role="img" aria-label="棒人間アニメーション">
            <g class="stickman-board">
              <rect x="132" y="34" width="132" height="92" rx="18" />
              <line x1="154" y1="64" x2="238" y2="64" />
              <line x1="154" y1="88" x2="224" y2="88" />
              <line x1="154" y1="112" x2="202" y2="112" />
            </g>
            <path class="stickman-pointer stickman-draw" d="M132 152 C170 132 202 128 244 108" />
            <g class="stickman-body">
              <circle class="stick-head" cx="100" cy="96" r="34" />
              <line class="stick-neck" x1="100" y1="130" x2="100" y2="168" />
              <line class="stick-torso" x1="100" y1="168" x2="100" y2="238" />
              <line class="stick-arm stick-arm-left" x1="100" y1="166" x2="50" y2="206" />
              <line class="stick-arm stick-arm-right" x1="100" y1="166" x2="154" y2="148" />
              <line class="stick-leg stick-leg-left" x1="100" y1="238" x2="62" y2="312" />
              <line class="stick-leg stick-leg-right" x1="100" y1="238" x2="142" y2="312" />
              <circle class="stick-hand" cx="154" cy="148" r="7" />
              <circle class="stick-hand" cx="50" cy="206" r="7" />
            </g>
            <g class="stickman-bars">
              <rect class="stick-bar stick-bar-1" x="178" y="240" width="22" height="72" rx="6" />
              <rect class="stick-bar stick-bar-2" x="210" y="204" width="22" height="108" rx="6" />
              <rect class="stick-bar stick-bar-3" x="242" y="166" width="22" height="146" rx="6" />
            </g>
          </svg>
          <div class="stickman-label">Stickman Explainer</div>
        </div>
      </div>"""


def _stickman_css() -> str:
    """CSS for code-rendered stickman opening animation."""
    return """
    .stickman-layer {
      position: absolute; z-index: 3; left: 22px; top: 308px;
      width: 278px; height: 358px; opacity: 0;
      transform: translateY(18px) scale(0.96);
      pointer-events: none;
    }
    .stickman-stage {
      position: relative; width: 100%; height: 100%;
      border-radius: 34px;
      background:
        radial-gradient(circle at 38% 20%, rgba(255,255,255,0.96), transparent 38%),
        linear-gradient(150deg, rgba(255,255,255,0.94), rgba(231,249,255,0.86));
      border: 2px solid rgba(7,138,166,0.23);
      box-shadow: 0 26px 58px rgba(49,121,139,0.2), inset 0 1px 0 rgba(255,255,255,0.9);
      overflow: hidden;
    }
    .stickman-orbit {
      position: absolute; left: -46px; top: -50px; width: 180px; height: 180px;
      border-radius: 50%; border: 18px solid rgba(7,138,166,0.08);
    }
    .stickman-svg {
      position: absolute; inset: 12px 6px 32px 4px;
      width: calc(100% - 10px); height: calc(100% - 44px);
      overflow: visible;
    }
    .stickman-svg line,
    .stickman-svg path,
    .stickman-svg circle {
      vector-effect: non-scaling-stroke;
    }
    .stick-head {
      fill: #ffffff;
      stroke: #17313a;
      stroke-width: 8;
    }
    .stick-neck,
    .stick-torso,
    .stick-arm,
    .stick-leg {
      stroke: #17313a;
      stroke-width: 9;
      stroke-linecap: round;
    }
    .stick-hand {
      fill: #17313a;
      stroke: #17313a;
      stroke-width: 4;
    }
    .stickman-board rect {
      fill: rgba(255,255,255,0.82);
      stroke: rgba(7,138,166,0.38);
      stroke-width: 4;
    }
    .stickman-board line {
      stroke: rgba(7,138,166,0.56);
      stroke-width: 7;
      stroke-linecap: round;
    }
    .stickman-pointer {
      fill: none;
      stroke: #f59e0b;
      stroke-width: 8;
      stroke-linecap: round;
      stroke-dasharray: 150;
      stroke-dashoffset: 150;
    }
    .stick-bar {
      fill: rgba(7,138,166,0.68);
      transform-box: fill-box;
      transform-origin: 50% 100%;
    }
    .stick-bar-2 { fill: rgba(34,197,94,0.62); }
    .stick-bar-3 { fill: rgba(245,158,11,0.68); }
    .stickman-label {
      position: absolute; left: 24px; right: 24px; bottom: 18px;
      padding: 8px 10px; border-radius: 999px;
      text-align: center; color: #07536a;
      background: rgba(255,255,255,0.9);
      border: 1px solid rgba(7,138,166,0.2);
      font-size: 15px; font-weight: 900; letter-spacing: 0.03em;
    }
    body.vtuber-enabled .stickman-layer {
      top: 276px;
      width: 250px;
      height: 326px;
    }"""


def build_html(script: dict, image_paths: list[Path], total_dur: float,
               narration_duration: float = 0.0, vtuber_mode: bool = False,
               scene_video_indexes: set[int] | None = None) -> str:
    """Build HyperFrames index.html for a short drama video (576x1024 vertical)."""
    scenes = script.get("scenes") or []
    raw_title = script.get("title") or "Kurage Video"
    title = html.escape(raw_title)

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
    scene_video_indexes = scene_video_indexes or set()
    for i, (scene, (start, dur)) in enumerate(zip(scenes, scene_timing)):
        img_src = f"assets/scene_{i:02d}.png"
        video_src = f"assets/scene_{i:02d}.mp4"
        narration = html.escape(scene.get("narration") or "")
        media_html = (
            f'<video class="scene-bg" src="{video_src}" muted playsinline preload="auto"></video>'
            if i in scene_video_indexes
            else f'<img class="scene-bg" src="{img_src}" alt="scene {i}">'
        )
        overlay_headline = html.escape(str(scene.get("overlay_headline") or "").strip())
        overlay_subtitle = html.escape(str(scene.get("overlay_subtitle") or "").strip())
        overlay_badge = html.escape(str(scene.get("overlay_badge") or "").strip())
        overlay_html = ""
        if overlay_headline or overlay_subtitle:
            overlay_html = f"""
      <div class="opening-card">
        {f'<div class="opening-badge">{overlay_badge}</div>' if overlay_badge else ''}
        {f'<div class="opening-headline">{overlay_headline}</div>' if overlay_headline else ''}
        {f'<div class="opening-subtitle">{overlay_subtitle}</div>' if overlay_subtitle else ''}
      </div>"""
        stickman_html = _build_stickman_overlay(i) if scene.get("stickman_overlay") else ""
        scene_blocks.append(f"""
    <!-- Scene {i} ({start:.1f}s - {start+dur:.1f}s) -->
    <div class="scene clip" id="scene-{i}"
         data-start="{start:.2f}" data-duration="{dur:.2f}">
      {media_html}
      {stickman_html}
      {overlay_html}
      <div class="scene-text">{narration}</div>
    </div>""")

    scenes_html = "\n".join(scene_blocks)
    vtuber_html = ""
    vtuber_css = ""
    vtuber_js = ""
    body_class = ' class="vtuber-enabled"' if vtuber_mode else ""
    if vtuber_mode:
        vtuber_html, vtuber_css, vtuber_js = _build_vtuber_overlay(total_dur, raw_title)

    # GSAP animation: cross-fade between scenes
    gsap_scenes = []
    for i, (start, dur) in enumerate(scene_timing):
        end = start + dur
        fade_in = start
        fade_out = end - 0.5
        direction = -1 if i % 2 else 1
        start_x = -18 * direction
        end_x = 18 * direction
        start_y = -10 if i % 3 == 0 else 8
        end_y = 10 if i % 3 == 0 else -8
        gsap_scenes.append(f"""
  // Scene {i}
  tl.to("#scene-{i}", {{opacity:1, duration:0.5}}, {fade_in:.2f})
    .fromTo("#scene-{i} .scene-bg",
      {{scale:1.035, x:{start_x}, y:{start_y}}},
      {{scale:1.12, x:{end_x}, y:{end_y}, duration:{dur:.2f}, ease:"none"}},
      {fade_in:.2f})
    .to("#scene-{i} .stickman-layer", {{opacity:1, y:0, scale:1, duration:0.5, ease:"back.out(1.35)"}}, {fade_in + 0.12:.2f})
    .fromTo("#scene-{i} .stick-head", {{scale:0.82, transformOrigin:"100px 96px"}}, {{scale:1, duration:0.42, ease:"elastic.out(1,0.55)"}}, {fade_in + 0.22:.2f})
    .to("#scene-{i} .stickman-pointer", {{strokeDashoffset:0, duration:0.7, ease:"power2.out"}}, {fade_in + 0.56:.2f})
    .fromTo("#scene-{i} .stick-bar", {{scaleY:0.08}}, {{scaleY:1, duration:0.62, stagger:0.12, ease:"expo.out"}}, {fade_in + 0.64:.2f})
    .to("#scene-{i} .stick-arm-right", {{rotation:-16, transformOrigin:"100px 166px", duration:0.34, yoyo:true, repeat:{max(1, math.ceil(dur / 0.72) - 1)}, ease:"sine.inOut"}}, {fade_in + 0.74:.2f})
    .to("#scene-{i} .stickman-body", {{y:-5, duration:0.8, yoyo:true, repeat:{max(1, math.ceil(dur / 1.6) - 1)}, ease:"sine.inOut"}}, {fade_in + 0.92:.2f})
    .to("#scene-{i} .opening-card", {{opacity:1, y:0, scale:1, duration:0.48, ease:"power3.out"}}, {fade_in + 0.18:.2f})
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
      width: 576px; height: 1024px; overflow: hidden;
      background:
        radial-gradient(circle at 18% 10%, rgba(135,224,239,0.28), transparent 30%),
        radial-gradient(circle at 88% 0%, rgba(255,222,170,0.46), transparent 30%),
        linear-gradient(180deg, #ffffff 0%, #f7fcff 52%, #eaf8fb 100%);
      font-family: "Noto Sans JP", "Noto Sans JP", sans-serif;
    }}
    #composition {{
      position: relative; width: 576px; height: 1024px; overflow: hidden;
      background:
        radial-gradient(circle at 18% 10%, rgba(135,224,239,0.28), transparent 30%),
        radial-gradient(circle at 88% 0%, rgba(255,222,170,0.46), transparent 30%),
        linear-gradient(180deg, #ffffff 0%, #f7fcff 52%, #eaf8fb 100%);
    }}
    #composition::before {{
      content: ""; position: absolute; inset: 0; z-index: 2; pointer-events: none;
      background-image:
        linear-gradient(rgba(7,138,166,0.045) 1px, transparent 1px),
        linear-gradient(90deg, rgba(7,138,166,0.045) 1px, transparent 1px);
      background-size: 42px 42px;
      mask-image: linear-gradient(180deg, rgba(0,0,0,0.5), transparent 74%);
    }}
    .scene {{
      position: absolute; top: 0; left: 0; width: 576px; height: 1024px;
      opacity: 0; overflow: hidden;
    }}
    .scene-bg {{
      width: 100%; height: 100%; object-fit: cover; display: block;
      filter: saturate(0.96) brightness(1.08);
      transform-origin: center center;
      will-change: transform;
    }}
    .scene::after {{
      content: ""; position: absolute; inset: 0; z-index: 1; pointer-events: none;
      background:
        linear-gradient(180deg, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0.02) 52%, rgba(245,252,255,0.78) 100%);
    }}
    .scene-text {{
      position: absolute; bottom: 80px; left: 0; right: 0; padding: 0 28px;
      opacity: 0; transform: translateY(20px); z-index: 4;
      text-align: center; font-size: 28px; font-weight: 700;
      color: #17313a; text-shadow: 0 2px 0 rgba(255,255,255,0.92);
      line-height: 1.5; letter-spacing: 0.02em;
    }}
    .scene-text::before {{
      content: ""; position: absolute; z-index: -1;
      inset: -14px 18px;
      border-radius: 22px;
      background: rgba(255,255,255,0.9);
      border: 1px solid rgba(7,138,166,0.18);
      box-shadow: 0 18px 48px rgba(49,121,139,0.18);
    }}
    .opening-card {{
      position: absolute; z-index: 5; top: 118px; left: 34px; right: 34px;
      padding: 22px 22px 20px; border-radius: 28px;
      background: rgba(255,255,255,0.92);
      border: 1px solid rgba(7,138,166,0.22);
      box-shadow: 0 22px 60px rgba(49,121,139,0.2);
      backdrop-filter: blur(10px); opacity: 0;
      transform: translateY(18px) scale(0.98);
    }}
    .opening-badge {{
      display: inline-flex; width: max-content; border-radius: 999px;
      padding: 5px 11px; margin-bottom: 12px;
      background: #e7f8fb; color: #007f96; border: 1px solid #bae4ec;
      font-size: 15px; font-weight: 900; letter-spacing: 0.05em;
    }}
    .opening-headline {{
      color: #102f38; font-size: 35px; font-weight: 1000; line-height: 1.25;
      letter-spacing: -0.02em; text-shadow: 0 2px 0 rgba(255,255,255,0.95);
    }}
    .opening-subtitle {{
      margin-top: 12px; color: #345a64; font-size: 20px; font-weight: 800;
      line-height: 1.48;
    }}
    {_stickman_css()}
    #title-overlay {{
      position: absolute; top: 0; left: 0; width: 576px; height: 1024px;
      display: flex; align-items: center; justify-content: center;
      background:
        radial-gradient(circle at 50% 35%, rgba(255,255,255,0.96), rgba(235,249,253,0.9) 58%, rgba(214,241,248,0.88));
      z-index: 10; opacity: 0;
    }}
    #title-overlay h1 {{
      color: #17313a; font-size: 32px; font-weight: 900;
      text-align: center; padding: 0 32px; line-height: 1.4;
      text-shadow: 0 2px 0 rgba(255,255,255,0.95);
    }}
    {vtuber_css}
  </style>
</head>
<body{body_class}>
  <div id="composition"
       data-composition-id="main"
       data-width="576"
       data-height="1024">

    <div id="title-overlay">
      <h1>{title}</h1>
    </div>

    {scenes_html}
    {vtuber_html}
    {audio_tag}
  </div>

  <script>
  (function() {{
    document.querySelectorAll("video.scene-bg").forEach((video) => {{
      video.playbackRate = 1;
      video.currentTime = 0;
      video.play().catch(() => {{}});
    }});
    const tl = gsap.timeline({{ paused: true }});

    // Title
    tl.to("#title-overlay", {{opacity:1, duration:0.4}}, 0)
      .to("#title-overlay", {{opacity:0, duration:0.4}}, 1.5);

    // Scenes
    {gsap_js}

    // Optional Kurage VTuber explainer overlay
    {vtuber_js}

    // HyperFrames timeline registry
    window.__timelines = window.__timelines || {{}};
    window.__timelines["main"] = tl;
  }})();
  </script>
</body>
</html>
'''


def create_hf_project(job_dir: Path, script: dict, image_paths: list[Path], vtuber_mode: bool = False) -> Path:
    """Create a HyperFrames project directory ready for rendering."""
    project_dir = job_dir / "hf_project"
    project_dir.mkdir(parents=True, exist_ok=True)
    show_avatar = _should_show_avatar(vtuber_mode)

    # Copy assets
    assets_dir = project_dir / "assets"
    assets_dir.mkdir(exist_ok=True)
    for i, img in enumerate(image_paths):
        dest = assets_dir / f"scene_{i:02d}.png"
        shutil.copy(img, dest)
    scene_video_indexes: set[int] = set()
    for i in range(len(image_paths)):
        src_video = job_dir / "assets" / f"scene_{i:02d}.mp4"
        if src_video.exists() and src_video.stat().st_size > 0:
            shutil.copy(src_video, assets_dir / f"scene_{i:02d}.mp4")
            scene_video_indexes.add(i)

    if show_avatar:
        avatar_paths = _avatar_asset_paths()
        if len(avatar_paths) != len(AVATAR_FRAMES):
            print("  [video] Kurage avatar overlay requested but avatar PNGs are missing; rendering without avatar", flush=True)
            show_avatar = False
        else:
            for i, src in enumerate(avatar_paths):
                shutil.copy(src, assets_dir / f"avatar_lipsync_{i}.png")

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
    html_content = build_html(script, image_paths, total_dur, narration_duration, vtuber_mode=show_avatar, scene_video_indexes=scene_video_indexes)
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


def generate_video(script: dict, image_paths: list[Path], job_dir: Path, vtuber_mode: bool = False) -> Path:
    """Full video generation pipeline: project setup + render.

    Returns:
        Path to the output MP4 file
    """
    output_path = job_dir / "output.mp4"
    project_dir = create_hf_project(job_dir, script, image_paths, vtuber_mode=vtuber_mode)
    print(f"  [video] HyperFrames project: {project_dir}", flush=True)
    render_video(project_dir, output_path)
    print(f"  [video] Rendered: {output_path} ({output_path.stat().st_size} bytes)", flush=True)
    thumb_path = generate_thumbnail(output_path, job_dir / "thumbnail.jpg", title=script.get("title"))
    print(f"  [video] Thumbnail: {thumb_path} ({thumb_path.stat().st_size} bytes)", flush=True)
    return output_path
