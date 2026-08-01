"""Generate video from script + images using HyperFrames CLI."""
from __future__ import annotations
import html
import json
import math
import os
import shutil
import subprocess
import sys
from pathlib import Path
from config import (
    NVM_NODE, HYPERFRAMES_VERSION, ROOT,
    COMMERCIAL_OUTRO_ENABLED, COMMERCIAL_OUTRO_FILE,
    CHANNEL_OUTRO_ENABLED, CHANNEL_OUTRO_FILE,
)
from tts_gen import generate_scene_narration_audio


HF_TEMPLATE = ROOT / "hyperframes" / "aixec-health-book" / "hyperframes.json"
KVTUBER_SHARED_DIR = Path(os.environ.get("KURAGE_AVATAR_SHARED_DIR", "/home/kojima/work/kvtuber/shared"))
if str(KVTUBER_SHARED_DIR) not in sys.path:
    sys.path.insert(0, str(KVTUBER_SHARED_DIR))

from kurage_avatar_overlay import (  # noqa: E402
    avatar_frames,
    available_avatar_frames,
    build_hyperframes_vtuber_overlay,
)

AVATAR_FRAMES = avatar_frames()
AVATAR_OVERLAY_DEFAULT = os.environ.get("KURAGE_AVATAR_OVERLAY_DEFAULT", "1").lower() not in {"0", "false", "no", "off"}


def _avatar_asset_paths() -> list[Path]:
    """Return avatar assets when the local PNG-tuber set is available."""
    return available_avatar_frames()


def _should_show_avatar(vtuber_mode: bool) -> bool:
    """Kurage/Horizon videos show the canonical Kurage avatar by default."""
    return bool(vtuber_mode or AVATAR_OVERLAY_DEFAULT)


def _build_vtuber_overlay(total_dur: float, title: str) -> tuple[str, str, str]:
    """HTML/CSS/GSAP snippets for the branded Kurage VTuber explainer layer."""
    return build_hyperframes_vtuber_overlay(total_dur, title)


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


def compute_scene_timing(scenes: list[dict], total_dur: float,
                         narration_duration: float) -> list[tuple[float, float]]:
    """シーンの(開始, 長さ)を計算する。

    ナレーションがある場合、従来は均等割りだったため長短のあるシーンで
    音声とテロップ/絵の切り替えがズレていた。TTSがシーンごとに実測した
    tts_duration(tts_gen.pyが書き込む)があればそれに比例配分し、無ければ
    ナレーション文字数に比例配分する。
    """
    if not scenes:
        return []
    if narration_duration > 0:
        weights = []
        for scene in scenes:
            w = float(scene.get("tts_duration") or 0)
            if w <= 0:
                w = max(1.0, float(len(str(scene.get("narration") or ""))))
            weights.append(w)
        total_w = sum(weights) or float(len(scenes))
        timing = []
        t = 0.0
        for w in weights:
            dur = total_dur * (w / total_w)
            timing.append((t, dur))
            t += dur
        return timing
    timing = []
    t = 0.0
    for scene in scenes:
        dur = float(scene.get("duration") or 6)
        timing.append((t, dur))
        t += dur
    return timing


def build_html(script: dict, image_paths: list[Path], total_dur: float,
               narration_duration: float = 0.0, vtuber_mode: bool = False,
               scene_video_indexes: set[int] | None = None) -> str:
    """Build HyperFrames index.html for a short drama video (576x1024 vertical)."""
    scenes = script.get("scenes") or []
    raw_title = script.get("title") or "Kurage Video"
    title = html.escape(raw_title)

    scene_timing = compute_scene_timing(scenes, total_dur, narration_duration)

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
       data-start="0"
       data-duration="{total_dur:.2f}"
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


V2_CSS = """
    .scene::after {
      background: linear-gradient(180deg, rgba(2,8,12,0.14) 0%, rgba(2,8,12,0.0) 30%,
                                  rgba(2,8,12,0.0) 54%, rgba(3,9,13,0.58) 100%) !important;
    }
    .kin {
      position: absolute; left: 44px; right: 44px; bottom: 96px; height: 240px;
      z-index: 6; pointer-events: none;
    }
    /* 字幕: 小さめ・文単位で情報量を確保(ナレーションの読み物レイヤ) */
    .kin-chunk {
      position: absolute; left: 0; right: 0; bottom: 26px; opacity: 0;
      text-align: left; font-size: 27px; font-weight: 800; color: #ffffff;
      line-height: 1.55; letter-spacing: 0.01em;
      text-shadow: 0 2px 4px rgba(0,0,0,0.8), 0 0 24px rgba(0,0,0,0.55);
      -webkit-text-stroke: 6px rgba(8,16,20,0.85);
      paint-order: stroke fill;
    }
    .kin-chunk .kem {
      font-style: normal; color: #ffc95e;
      -webkit-text-stroke: 6px rgba(30,18,2,0.9);
    }
    /* 強調キーワード: 字幕の上に出す大テロップレイヤ */
    .kw-pop {
      position: absolute; left: 0; right: 0; bottom: 168px; opacity: 0;
      text-align: left; font-size: 46px; font-weight: 900; color: #ffb224;
      line-height: 1.3; letter-spacing: 0.01em;
      text-shadow: 0 4px 10px rgba(0,0,0,0.7), 0 0 40px rgba(0,0,0,0.45);
      -webkit-text-stroke: 10px rgba(30,18,2,0.9);
      paint-order: stroke fill;
    }
    .kw-pop.kw-marker { color: #161006; -webkit-text-stroke: 0; }
    .kw-pop.kw-marker .mkh-t {
      position: relative; display: inline-block; padding: 4px 16px;
      text-shadow: none;
    }
    .kw-pop.kw-marker .mkh-bg {
      position: absolute; inset: 0; border-radius: 10px;
      background: linear-gradient(100deg, #ffb224 0%, #ffc95e 100%);
      transform: skewX(-6deg) scaleX(0); transform-origin: left center;
      box-shadow: 0 10px 30px rgba(0,0,0,0.4);
    }
    .kw-pop.kw-marker .mkh-in { position: relative; }
    .kin-tick {
      position: absolute; left: 2px; bottom: 0; width: 120px; height: 6px;
      border-radius: 3px; background: rgba(255,255,255,0.22);
      overflow: hidden;
    }
    .kin-tick i {
      position: absolute; inset: 0; border-radius: 3px; background: #1cb8d8;
      transform: scaleX(0); transform-origin: left center; display: block;
    }
    .kin-eyebrow {
      position: absolute; left: 0; right: 0; bottom: 252px; opacity: 0;
      text-align: left; font-size: 18px; font-weight: 800; letter-spacing: 0.2em;
      color: #4fd3ee; text-shadow: 0 2px 8px rgba(0,0,0,0.8);
    }
    .lt {
      position: absolute; z-index: 7; top: 112px; left: 32px; right: 32px;
      padding: 26px 30px 22px; border-radius: 20px; opacity: 0;
      background: rgba(9,20,26,0.78); backdrop-filter: blur(14px);
      border: 1px solid rgba(255,255,255,0.14); border-left: 10px solid #1cb8d8;
      box-shadow: 0 24px 60px rgba(0,0,0,0.45);
    }
    .lt-badge {
      position: absolute; top: -26px; left: 20px;
      font-size: 23px; font-weight: 900; letter-spacing: 0.08em; color: #1a1204;
      background: linear-gradient(120deg, #ffb224, #ffca62);
      padding: 8px 18px; border-radius: 12px; transform: rotate(-2deg);
      box-shadow: 0 10px 26px rgba(0,0,0,0.35);
    }
    .lt-h {
      font-size: 39px; font-weight: 900; color: #ffffff; line-height: 1.32;
      letter-spacing: -0.01em;
    }
    .lt-s { margin-top: 10px; font-size: 22px; font-weight: 600; color: rgba(233,242,245,0.74); line-height: 1.5; }
    .lt-meta {
      margin-top: 16px; display: flex; align-items: center; gap: 14px;
      font-size: 15px; font-weight: 700; color: #4fd3ee; letter-spacing: 0.12em;
    }
    .lt-bar { flex: 1; height: 5px; border-radius: 3px; background: rgba(255,255,255,0.16); overflow: hidden; }
    .lt-bar i { display: block; height: 100%; background: #1cb8d8; border-radius: 3px; }
    .dc {
      position: absolute; z-index: 7; left: 56px; right: 56px; top: 292px;
      padding: 36px 34px 30px; border-radius: 26px; opacity: 0; text-align: center;
      background: rgba(9,22,28,0.72); backdrop-filter: blur(16px);
      border: 1px solid rgba(28,184,216,0.35);
      box-shadow: 0 30px 70px rgba(0,0,0,0.5);
    }
    .dc-lab {
      font-size: 19px; font-weight: 800; color: #4fd3ee;
      letter-spacing: 0.18em; margin-bottom: 8px; min-height: 1em;
    }
    .dc-num {
      font-size: 108px; font-weight: 900; color: #ffffff; line-height: 1.05;
      letter-spacing: -0.03em; font-variant-numeric: tabular-nums;
      text-shadow: 0 0 60px rgba(28,184,216,0.4);
    }
    .dc-num small { font-size: 42px; font-weight: 900; color: rgba(255,255,255,0.85); margin-left: 6px; }
"""


WHITE_STUDIO_CM_CSS = """
    body.telop-white-studio-cm,
    body.telop-white-studio-cm #composition {
      background: #f7fbf8;
      color: #143844;
    }
    body.telop-white-studio-cm .scene-bg {
      filter: saturate(0.88) brightness(1.04) contrast(0.94);
    }
    body.telop-white-studio-cm .scene::after {
      background:
        linear-gradient(180deg, rgba(247,251,248,0.92) 0%, rgba(247,251,248,0.64) 27%,
                        rgba(247,251,248,0.08) 54%, rgba(247,251,248,0.55) 76%,
                        rgba(247,251,248,0.92) 100%) !important;
    }
    .cm-frame {
      position: absolute; inset: 20px; z-index: 3; pointer-events: none;
      border: 1px solid rgba(22,175,196,0.26);
    }
    .cm-frame::before, .cm-frame::after {
      content: ""; position: absolute; width: 34px; height: 4px; background: #f6b73c;
    }
    .cm-frame::before { left: -1px; top: -2px; }
    .cm-frame::after { right: -1px; bottom: -2px; }
    .cm-copy {
      position: absolute; z-index: 6; top: 78px; left: 36px; right: 34px;
      color: #143844; pointer-events: none;
    }
    .cm-eyebrow {
      font-family: "Montserrat", "Noto Sans CJK JP", sans-serif;
      font-size: 12px; line-height: 1.2; font-weight: 800; letter-spacing: 0.18em;
      color: #16afc4; text-transform: uppercase; opacity: 0;
    }
    .cm-headline {
      max-width: 490px; margin-top: 11px; font-size: 42px; line-height: 1.12;
      font-weight: 900; letter-spacing: -0.035em; color: #143844;
      text-wrap: balance; opacity: 0;
    }
    .cm-headline.cm-headline-long { font-size: 34px; line-height: 1.18; max-width: 500px; }
    .cm-headline.cm-headline-short { font-size: 47px; }
    .cm-headline strong { color: #ff725e; font-weight: 900; }
    .cm-rule { margin-top: 16px; width: 104px; height: 5px; background: rgba(22,175,196,0.18); }
    .cm-rule i {
      display: block; width: 100%; height: 100%; background: #16afc4;
      transform: scaleX(0); transform-origin: left center;
    }
    .cm-ghost {
      position: absolute; z-index: 2; left: 26px; bottom: 222px;
      font-family: "Montserrat", sans-serif; font-size: 86px; line-height: 0.8;
      font-weight: 900; letter-spacing: -0.06em; color: rgba(22,175,196,0.15);
      opacity: 0; pointer-events: none; white-space: nowrap;
    }
    .cm-diamond {
      position: absolute; z-index: 6; right: 38px; top: 278px; width: 13px; height: 13px;
      border: 3px solid #f6b73c; transform: rotate(45deg); opacity: 0;
    }
    .cm-scan {
      position: absolute; z-index: 4; left: 26px; right: 26px; top: 346px;
      height: 1px; background: rgba(22,175,196,0.28); transform: scaleX(0);
      transform-origin: left center;
    }
    .cm-brand {
      position: absolute; z-index: 12; top: 22px; right: 22px; width: 246px;
      min-height: 47px; padding: 9px 14px 8px 17px;
      background: rgba(255,255,255,0.94); border: 1px solid rgba(22,175,196,0.28);
      border-left: 5px solid #16afc4; box-shadow: 0 10px 26px rgba(20,56,68,0.12);
      opacity: 0; pointer-events: none;
    }
    .cm-brand-name {
      font-family: "Montserrat", "Noto Sans CJK JP", sans-serif;
      font-size: 17px; line-height: 1; font-weight: 900; color: #143844;
      letter-spacing: -0.02em;
    }
    .cm-brand-url {
      margin-top: 5px; font-family: "Montserrat", sans-serif;
      font-size: 10px; line-height: 1; font-weight: 800; color: #ff725e;
      letter-spacing: 0.04em;
    }
    body.telop-white-studio-cm .kin {
      left: 32px; right: 32px; bottom: 42px; height: 142px; z-index: 8;
    }
    body.telop-white-studio-cm .kin-chunk {
      left: 0; right: 0; bottom: 0; min-height: 118px;
      display: block; padding: 20px 24px 22px 28px;
      color: #143844; background: rgba(255,255,255,0.94);
      border: 1px solid rgba(22,175,196,0.22); border-left: 6px solid #16afc4;
      border-radius: 2px; box-shadow: 0 16px 42px rgba(20,56,68,0.16);
      font-size: 23px; line-height: 1.48; font-weight: 800;
      letter-spacing: 0; text-shadow: none; -webkit-text-stroke: 0;
    }
    body.telop-white-studio-cm .kin-chunk .kem {
      color: #ff725e; font-style: normal; -webkit-text-stroke: 0;
    }
    body.telop-white-studio-cm .kin-tick {
      left: 0; bottom: -8px; width: 100%; height: 3px; border-radius: 0;
      background: rgba(22,175,196,0.14);
    }
    body.telop-white-studio-cm .kin-tick i { background: #16afc4; border-radius: 0; }
    body.telop-white-studio-cm .kin-eyebrow { display: none; }
    body.telop-white-studio-cm .dc {
      left: 48px; right: 48px; top: 360px; padding: 26px 28px 24px;
      border-radius: 3px; background: rgba(255,255,255,0.91);
      border: 1px solid rgba(22,175,196,0.35); border-top: 6px solid #16afc4;
      box-shadow: 0 22px 52px rgba(20,56,68,0.16); backdrop-filter: blur(8px);
    }
    body.telop-white-studio-cm .dc-lab { color: #16afc4; font-size: 16px; }
    body.telop-white-studio-cm .dc-num {
      color: #143844; font-size: 86px; text-shadow: none;
    }
    body.telop-white-studio-cm .dc-num small { color: #ff725e; font-size: 34px; }
    body.telop-white-studio-cm.vtuber-enabled .vtuber-card {
      right: 18px; bottom: 176px; transform: scale(0.48); transform-origin: bottom right;
    }
    body.telop-white-studio-cm.vtuber-enabled .vtuber-badge { display: none; }
    body.telop-white-studio-cm.vtuber-enabled .kin { left: 32px; right: 32px; }
    body.telop-white-studio-cm.vtuber-enabled .kin-chunk { font-size: 23px; }
"""


def _v2_chunk_html(scene_index: int, chunk_index: int, chunk: dict, marker_phrase: str,
                   include_keyword_pop: bool = True) -> list[str]:
    """字幕(小)と強調キーワード大テロップ(別レイヤ)のHTMLを返す。

    字幕内の強調語はインラインの色替えのみ(サイズは変えない)。
    大テロップは kw-pop 要素として字幕の上に出し、markerシーンでは
    マーカー(黄帯)スタイルになる。
    """
    text = str(chunk.get("text") or "")
    emphasis = str(chunk.get("emphasis") or "")
    keyword = ""
    if marker_phrase and marker_phrase in text:
        keyword = marker_phrase
    elif emphasis and emphasis in text:
        keyword = emphasis

    if keyword:
        pre, _, post = text.partition(keyword)
        inner = f"{html.escape(pre)}<em class=\"kem\">{html.escape(keyword)}</em>{html.escape(post)}"
    else:
        inner = html.escape(text)
    parts = [f'<div class="kin-chunk" id="kin-{scene_index}-{chunk_index}">{inner}</div>']

    if keyword and include_keyword_pop:
        if marker_phrase and keyword == marker_phrase:
            parts.append(
                f'<div class="kw-pop kw-marker" id="kw-{scene_index}-{chunk_index}">'
                f'<span class="mkh-t"><i class="mkh-bg" id="mkh-{scene_index}-{chunk_index}"></i>'
                f'<span class="mkh-in">{html.escape(keyword)}</span></span></div>')
        else:
            parts.append(
                f'<div class="kw-pop" id="kw-{scene_index}-{chunk_index}">{html.escape(keyword)}</div>')
    return parts


def _v2_chunk_times(chunks: list[dict], start: float, dur: float) -> list[float]:
    """シーン内の各文節の表示開始時刻(文字数比例)。"""
    total_chars = sum(max(1, len(str(c.get("text") or ""))) for c in chunks) or 1
    usable = max(0.8, dur - 0.6)
    times = []
    cum = 0
    for c in chunks:
        times.append(start + 0.12 + usable * (cum / total_chars))
        cum += max(1, len(str(c.get("text") or "")))
    return times


def _cm_headline(chunks: list[dict], fallback: str) -> tuple[str, str]:
    """Return compact, escaped White Studio headline HTML and its length class."""
    first = chunks[0] if chunks else {"text": fallback, "emphasis": ""}
    source = str(first.get("text") or fallback).strip()
    for separator in ("。", "！", "？"):
        if separator in source:
            source = source.split(separator, 1)[0] + separator
            break
    if len(source) > 32:
        source = source[:31].rstrip("、，, ") + "…"
    emphasis = str(first.get("emphasis") or "").strip()
    if emphasis and emphasis in source:
        before, _, after = source.partition(emphasis)
        rendered = f"{html.escape(before)}<strong>{html.escape(emphasis)}</strong>{html.escape(after)}"
    else:
        rendered = html.escape(source)
    length_class = "cm-headline-short" if len(source) <= 12 else "cm-headline-long" if len(source) >= 23 else ""
    return rendered, length_class


def build_html_v2(script: dict, image_paths: list[Path], total_dur: float,
                  narration_duration: float = 0.0, vtuber_mode: bool = False,
                  scene_video_indexes: set[int] | None = None,
                  edl: dict | None = None) -> str:
    """テロップ・システムv2のHyperFrames index.htmlを生成する。

    デザインはテンプレートA〜D(docs/telop-v2デザイン案)に固定。EDL(telop_gen)は
    テンプレート選択と文節・強調語・文言だけを与える。ここに来るEDLは
    sanitize済みである前提だが、欠損時は安全側(素のkinetic)に倒す。
    """
    scenes = script.get("scenes") or []
    raw_title = script.get("title") or "Kurage Video"
    title = html.escape(raw_title)
    edl_scenes = (edl or {}).get("scenes") or []
    visual_preset = str((edl or {}).get("visual_preset") or "").strip().lower()
    white_studio_cm = visual_preset == "white_studio_cm"
    scene_timing = compute_scene_timing(scenes, total_dur, narration_duration)
    scene_video_indexes = scene_video_indexes or set()
    n_scenes = max(1, len(scenes))

    scene_blocks: list[str] = []
    gsap_parts: list[str] = []

    for i, (scene, (start, dur)) in enumerate(zip(scenes, scene_timing)):
        e = edl_scenes[i] if i < len(edl_scenes) and isinstance(edl_scenes[i], dict) else {}
        template = e.get("template") or "kinetic"
        chunks = [c for c in (e.get("chunks") or []) if str(c.get("text") or "").strip()]
        marker_phrase = str(e.get("marker_phrase") or "") if template == "marker" else ""
        end = start + dur
        fade_out = end - 0.5

        img_src = f"assets/scene_{i:02d}.png"
        video_src = f"assets/scene_{i:02d}.mp4"
        media_html = (
            f'<video class="scene-bg" src="{video_src}" muted playsinline preload="auto"></video>'
            if i in scene_video_indexes
            else f'<img class="scene-bg" src="{img_src}" alt="scene {i}">'
        )
        stickman_html = _build_stickman_overlay(i) if scene.get("stickman_overlay") else ""

        overlay_html = ""
        if template == "lower_third" and not white_studio_cm:
            badge = html.escape(str(e.get("badge") or "").strip())
            headline = html.escape(str(e.get("headline") or raw_title).strip())
            subtitle = html.escape(str(e.get("subtitle") or "").strip())
            badge_html = f'<div class="lt-badge">{badge}</div>' if badge else ""
            subtitle_html = f'<div class="lt-s">{subtitle}</div>' if subtitle else ""
            bar_pct = int(100 * (i + 1) / n_scenes)
            overlay_html = f"""
      <div class="lt" id="lt-{i}">{badge_html}
        <div class="lt-h">{headline}</div>{subtitle_html}
        <div class="lt-meta">SCENE {i + 1:02d}<span class="lt-bar"><i style="width:{bar_pct}%"></i></span>{len(scenes):02d}</div>
      </div>"""
        elif template == "data_card":
            number = html.escape(str(e.get("number") or "").strip())
            unit = html.escape(str(e.get("unit") or "").strip())
            label = html.escape(str(e.get("label") or "").strip())
            if number:
                unit_html = f"<small>{unit}</small>" if unit else ""
                overlay_html = f"""
      <div class="dc" id="dc-{i}">
        <div class="dc-lab">{label}</div>
        <div class="dc-num">{number}{unit_html}</div>
      </div>"""

        kin_html = ""
        if chunks:
            chunk_divs = "\n      ".join(
                part for k, c in enumerate(chunks)
                for part in _v2_chunk_html(i, k, c, marker_phrase,
                                           include_keyword_pop=not white_studio_cm))
            eyebrow = html.escape(str(e.get("eyebrow") or "").strip()) if template == "marker" else ""
            eyebrow_html = f'<div class="kin-eyebrow" id="kin-eb-{i}">{eyebrow}</div>' if eyebrow else ""
            kin_html = f"""
      <div class="kin">{eyebrow_html}
      {chunk_divs}
      <div class="kin-tick"><i id="kin-tick-{i}"></i></div>
      </div>"""

        cm_html = ""
        if white_studio_cm:
            cm_headline, cm_headline_class = _cm_headline(chunks, str(scene.get("narration") or raw_title))
            cm_html = f"""
      <div class="cm-frame"></div>
      <div class="cm-copy" id="cm-copy-{i}">
        <div class="cm-eyebrow" id="cm-eyebrow-{i}">KURAGE MONTAGE / SCENE {i + 1:02d}</div>
        <div class="cm-headline {cm_headline_class}" id="cm-headline-{i}">{cm_headline}</div>
        <div class="cm-rule"><i id="cm-rule-{i}"></i></div>
      </div>
      <div class="cm-ghost" id="cm-ghost-{i}">INSIGHT {i + 1:02d}</div>
      <i class="cm-diamond" id="cm-diamond-{i}"></i>
      <i class="cm-scan" id="cm-scan-{i}"></i>"""

        scene_blocks.append(f"""
    <!-- Scene {i} ({start:.1f}s - {end:.1f}s) [{template}] -->
    <div class="scene clip" id="scene-{i}"
         data-start="{start:.2f}" data-duration="{dur:.2f}">
      {media_html}
      {stickman_html}{cm_html}{overlay_html}{kin_html}
    </div>""")

        # ---- GSAP ----
        direction = -1 if i % 2 else 1
        js = [
            f'tl.to("#scene-{i}", {{opacity:1, duration:0.5}}, {start:.2f})',
            f'.fromTo("#scene-{i} .scene-bg", {{scale:1.035, x:{-18 * direction}, y:{-10 if i % 3 == 0 else 8}}},'
            f' {{scale:1.12, x:{18 * direction}, y:{10 if i % 3 == 0 else -8}, duration:{dur:.2f}, ease:"none"}}, {start:.2f})',
        ]
        if scene.get("stickman_overlay"):
            js.append(f'.to("#scene-{i} .stickman-layer", {{opacity:1, y:0, scale:1, duration:0.5, ease:"back.out(1.35)"}}, {start + 0.12:.2f})')
        if template == "lower_third" and overlay_html:
            js.append(f'.fromTo("#lt-{i}", {{opacity:0, y:18, scale:0.98}}, {{opacity:1, y:0, scale:1, duration:0.48, ease:"power3.out"}}, {start + 0.18:.2f})')
        if template == "data_card" and overlay_html:
            card_ease = "power3.out" if white_studio_cm else "back.out(1.4)"
            js.append(f'.fromTo("#dc-{i}", {{opacity:0, scale:0.92, y:14}}, {{opacity:1, scale:1, y:0, duration:0.5, ease:"{card_ease}"}}, {start + 0.28:.2f})')
        if white_studio_cm:
            js.extend([
                f'.fromTo("#cm-eyebrow-{i}", {{opacity:0, x:-46}}, {{opacity:1, x:0, duration:0.34, ease:"expo.out"}}, {start + 0.08:.2f})',
                f'.fromTo("#cm-headline-{i}", {{opacity:0.18, x:-18, clipPath:"inset(0 100% 0 0)"}}, {{opacity:1, x:0, clipPath:"inset(0 0% 0 0)", duration:0.54, ease:"power4.out"}}, {start + 0.16:.2f})',
                f'.fromTo("#cm-rule-{i}", {{scaleX:0}}, {{scaleX:1, duration:0.42, ease:"power3.out"}}, {start + 0.36:.2f})',
                f'.fromTo("#cm-ghost-{i}", {{opacity:0, x:-50}}, {{opacity:1, x:0, duration:0.7, ease:"sine.out"}}, {start + 0.18:.2f})',
                f'.fromTo("#cm-diamond-{i}", {{opacity:0, scale:0}}, {{opacity:1, scale:1, duration:0.38, ease:"power3.out"}}, {start + 0.42:.2f})',
                f'.fromTo("#cm-scan-{i}", {{scaleX:0}}, {{scaleX:1, duration:0.7, ease:"power3.out"}}, {start + 0.30:.2f})',
            ])
        if chunks:
            times = _v2_chunk_times(chunks, start, dur)
            for k in range(len(chunks)):
                ck = times[k]
                next_at = times[k + 1] if k + 1 < len(chunks) else fade_out
                # 字幕: クロスフェードで文単位に切替(退避スタックは廃止)
                js.append(
                    f'.fromTo("#kin-{i}-{k}", {{opacity:0, y:12}},'
                    f' {{opacity:1, y:0, duration:0.22, ease:"power2.out"}}, {ck:.2f})')
                if k + 1 < len(chunks):
                    js.append(f'.to("#kin-{i}-{k}", {{opacity:0, duration:0.16}}, {max(ck + 0.2, next_at - 0.16):.2f})')
                text_k = str(chunks[k].get("text") or "")
                emphasis_k = str(chunks[k].get("emphasis") or "")
                is_marker_chunk = bool(marker_phrase and marker_phrase in text_k)
                has_kw = (is_marker_chunk or bool(emphasis_k and emphasis_k in text_k)) and not white_studio_cm
                if has_kw:
                    # 強調キーワードの大テロップ: 字幕より少し遅れてポップ
                    js.append(
                        f'.fromTo("#kw-{i}-{k}", {{opacity:0, scale:0.72, y:10}},'
                        f' {{opacity:1, scale:1, y:0, duration:0.32, ease:"back.out(1.6)"}}, {ck + 0.1:.2f})')
                    if k + 1 < len(chunks):
                        js.append(f'.to("#kw-{i}-{k}", {{opacity:0, duration:0.16}}, {max(ck + 0.3, next_at - 0.16):.2f})')
                    if is_marker_chunk:
                        js.append(f'.to("#mkh-{i}-{k}", {{scaleX:1, duration:0.4, ease:"power3.out"}}, {ck + 0.16:.2f})')
                        if e.get("eyebrow"):
                            js.append(f'.to("#kin-eb-{i}", {{opacity:1, duration:0.3}}, {ck:.2f})')
            js.append(f'.fromTo("#kin-tick-{i}", {{scaleX:0}}, {{scaleX:1, duration:{max(0.4, dur - 0.3):.2f}, ease:"none"}}, {start + 0.1:.2f})')
        js.append(f'.to("#scene-{i}", {{opacity:0, duration:0.5}}, {fade_out:.2f});')
        gsap_parts.append("\n  " + "\n    ".join(p for p in js if p))

    scenes_html = "\n".join(scene_blocks)
    gsap_js = "".join(gsap_parts)

    vtuber_html = ""
    vtuber_css = ""
    vtuber_js = ""
    body_classes = []
    if vtuber_mode:
        body_classes.append("vtuber-enabled")
    if white_studio_cm:
        body_classes.append("telop-white-studio-cm")
    body_class = f' class="{" ".join(body_classes)}"' if body_classes else ""
    if vtuber_mode:
        vtuber_html, vtuber_css, vtuber_js = _build_vtuber_overlay(total_dur, raw_title)

    audio_tag = ""
    if narration_duration > 0:
        audio_tag = (
            f'\n    <audio id="narration" src="narration.mp3"'
            f'\n           data-start="0" data-duration="{total_dur:.2f}"'
            f'\n           data-track-index="5" data-volume="1"></audio>'
        )

    cm_brand_html = ""
    cm_brand_js = ""
    title_overlay_html = f'''<div id="title-overlay">
      <h1>{title}</h1>
    </div>'''
    title_timeline_js = '''tl.to("#title-overlay", {opacity:1, duration:0.4}, 0)
      .to("#title-overlay", {opacity:0, duration:0.4}, 1.5);'''
    if white_studio_cm:
        cm_brand_html = '''<div class="cm-brand" id="cm-brand">
      <div class="cm-brand-name">Kurage Montage</div>
      <div class="cm-brand-url">kurage.exbridge.jp</div>
    </div>'''
        cm_brand_js = '''tl.fromTo("#cm-brand", {opacity:0, y:-22},
      {opacity:1, y:0, duration:0.42, ease:"power3.out"}, 0.08);'''
        title_overlay_html = ""
        title_timeline_js = ""

    return f'''<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>{title}</title>
  <script src="https://cdn.jsdelivr.net/npm/gsap@3.14.2/dist/gsap.min.js"></script>
  <style>
    * {{ margin: 0; padding: 0; box-sizing: border-box; }}
    html, body {{
      width: 576px; height: 1024px; overflow: hidden; background: #0b1216;
      font-family: "Noto Sans CJK JP", "Noto Sans JP", sans-serif;
    }}
    #composition {{
      position: relative; width: 576px; height: 1024px; overflow: hidden;
      background: #0b1216;
    }}
    .scene {{
      position: absolute; top: 0; left: 0; width: 576px; height: 1024px;
      opacity: 0; overflow: hidden;
    }}
    .scene-bg {{
      width: 100%; height: 100%; object-fit: cover; display: block;
      filter: saturate(0.98);
      transform-origin: center center;
      will-change: transform;
    }}
    .scene::after {{
      content: ""; position: absolute; inset: 0; z-index: 1; pointer-events: none;
    }}
    {V2_CSS}
    {WHITE_STUDIO_CM_CSS if white_studio_cm else ""}
    {_stickman_css()}
    #title-overlay {{
      position: absolute; top: 0; left: 0; width: 576px; height: 1024px;
      display: flex; align-items: center; justify-content: center;
      background: radial-gradient(circle at 50% 35%, #12242e, #0b1216 70%);
      z-index: 10; opacity: 0;
    }}
    #title-overlay h1 {{
      color: #eaf6f9; font-size: 32px; font-weight: 900;
      text-align: center; padding: 0 32px; line-height: 1.4;
    }}
    {vtuber_css}
    /* v2: アバターカードを縮小し、テロップと衝突しないよう左へ寄せる */
    body.vtuber-enabled .vtuber-card {{
      transform: scale(0.6); transform-origin: bottom right;
      right: 12px; bottom: 12px;
    }}
    body.vtuber-enabled .kin {{ left: 28px; right: 186px; }}
    body.vtuber-enabled .kin-chunk {{ font-size: 24px; }}
    body.vtuber-enabled .kw-pop {{ font-size: 40px; }}
  </style>
</head>
<body{body_class}>
  <div id="composition"
       data-composition-id="main"
       data-start="0"
       data-duration="{total_dur:.2f}"
       data-width="576"
       data-height="1024">

    {title_overlay_html}

    {scenes_html}
    {cm_brand_html}
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

    {title_timeline_js}
    {cm_brand_js}
    {gsap_js}

    {vtuber_js}

    window.__timelines = window.__timelines || {{}};
    window.__timelines["main"] = tl;
  }})();
  </script>
</body>
</html>
'''


def create_hf_project(job_dir: Path, script: dict, image_paths: list[Path], vtuber_mode: bool = False,
                      edl: dict | None = None) -> Path:
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

    # Write index.html — EDLがあればテロップ・システムv2、無ければ従来テンプレート
    if edl:
        html_content = build_html_v2(script, image_paths, total_dur, narration_duration,
                                     vtuber_mode=show_avatar, scene_video_indexes=scene_video_indexes,
                                     edl=edl)
    else:
        html_content = build_html(script, image_paths, total_dur, narration_duration,
                                  vtuber_mode=show_avatar, scene_video_indexes=scene_video_indexes)
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


def _overlay_thumbnail_title(image_path: Path, title: str | None,
                             topic_label: str | None = None) -> None:
    """Apply the vertical White Studio poster system used by Kurage Montage.

    Text stays code-rendered for exact Japanese. The generated illustration is
    only the art layer, so image-model spelling errors never reach production.
    """
    from PIL import Image, ImageDraw, ImageFilter, ImageFont, ImageOps

    source = Image.open(image_path).convert("RGB")
    target_size = (1080, 1920)
    resampling = getattr(Image, "Resampling", Image)
    lanczos = resampling.LANCZOS
    source_ratio = source.width / max(1, source.height)
    target_ratio = target_size[0] / target_size[1]

    if abs(source_ratio - target_ratio) < 0.04:
        canvas = ImageOps.fit(source, target_size, method=lanczos)
    else:
        # Square legacy scene art remains usable without black bars: a soft,
        # bright cover layer fills 9:16 and the intact art sits above it.
        backdrop = ImageOps.fit(source, target_size, method=lanczos)
        backdrop = backdrop.filter(ImageFilter.GaussianBlur(34)).convert("RGBA")
        white_veil = Image.new("RGBA", target_size, (247, 251, 248, 92))
        canvas = Image.alpha_composite(backdrop, white_veil)
        foreground = ImageOps.contain(source, (1000, 1180), method=lanczos)
        fx = (target_size[0] - foreground.width) // 2
        fy = target_size[1] - foreground.height - 80
        canvas.alpha_composite(foreground.convert("RGBA"), (fx, fy))

    img = canvas.convert("RGBA")
    wash = Image.new("RGBA", target_size, (0, 0, 0, 0))
    wd = ImageDraw.Draw(wash)
    # Bright reading field: no black scrim or heavy outline.
    for y in range(0, 1080):
        t = y / 1080
        alpha = int(245 * (1 - t) + 28 * t)
        wd.line([(0, y), (1080, y)], fill=(247, 251, 248, alpha))
    wd.rounded_rectangle((38, 38, 1042, 1882), radius=30,
                         outline=(22, 175, 196, 72), width=2)
    wd.rectangle((38, 38, 150, 47), fill=(246, 183, 60, 255))
    wd.rectangle((930, 1873, 1042, 1882), fill=(246, 183, 60, 255))
    img = Image.alpha_composite(img, wash)
    draw = ImageDraw.Draw(img)

    font_path = _find_japanese_font()
    eyebrow_font = ImageFont.truetype(font_path, 30)
    micro_font = ImageFont.truetype(font_path, 27)
    body_font = ImageFont.truetype(font_path, 43)
    main_font = ImageFont.truetype(font_path, 100)
    accent_font = ImageFont.truetype(font_path, 112)
    ink = (20, 56, 68, 255)
    aqua = (22, 175, 196, 255)
    coral = (255, 114, 94, 255)

    # Brand rail and editorial eyebrow.
    draw.rounded_rectangle((64, 76, 560, 146), radius=8,
                           fill=(255, 255, 255, 238), outline=(22, 175, 196, 76), width=2)
    draw.rectangle((64, 76, 76, 146), fill=aqua)
    draw.ellipse((98, 100, 116, 118), fill=coral)
    draw.text((134, 92), "KURAGE MONTAGE", font=eyebrow_font, fill=ink)
    draw.text((68, 184), "AI / TECHNOLOGY INSIGHT", font=micro_font, fill=aqua)

    text = (title or "Kurage Video").replace(" | ", " ").replace("｜", " ")
    text = text.split("\n", 1)[0].strip()
    primary, separator, accent = text.partition("：")
    if not separator:
        primary, separator, accent = text.partition(":")
    if not accent:
        split_at = max(1, min(len(text), 12))
        primary, accent = text[:split_at], text[split_at:]

    max_width = 920
    primary_lines = _wrap_text(draw, primary, main_font, max_width)
    if len(primary_lines) > 2:
        main_font = ImageFont.truetype(font_path, 82)
        primary_lines = _wrap_text(draw, primary, main_font, max_width)[:2]
    if len(primary_lines) >= 2 and len(primary_lines[-1]) == 1 and len(primary_lines[-2]) >= 3:
        primary_lines[-1] = primary_lines[-2][-1] + primary_lines[-1]
        primary_lines[-2] = primary_lines[-2][:-1]
    y = 270
    line_h = int(main_font.size * 1.18)
    for line in primary_lines:
        draw.text((70, y), line, font=main_font, fill=ink)
        y += line_h

    if accent:
        accent_lines = _wrap_text(draw, accent, accent_font, max_width)[:2]
        for line in accent_lines:
            draw.text((70, y + 12), line, font=accent_font, fill=coral)
            y += int(accent_font.size * 1.16)

    # Cyan rule, compact topic card, and small lower signature.
    rule_y = min(910, y + 42)
    draw.rectangle((70, rule_y, 340, rule_y + 12), fill=aqua)
    card_y = rule_y + 42
    draw.rounded_rectangle((70, card_y, 610, card_y + 94), radius=16,
                           fill=(255, 255, 255, 226), outline=(22, 175, 196, 82), width=2)
    topic = str(topic_label or "AI TECHNOLOGY / EXPLAINER").strip()[:42]
    draw.text((102, card_y + 20), topic, font=body_font, fill=ink)
    draw.text((72, 1790), "WHITE STUDIO EDITION", font=micro_font, fill=(20, 56, 68, 155))
    draw.rectangle((72, 1838, 340, 1845), fill=aqua)

    img.convert("RGB").save(image_path, "JPEG", quality=94, optimize=True, progressive=True)


def generate_thumbnail(video_path: Path, output_path: Path, seek: float = 3.0, title: str | None = None) -> Path:
    """Generate a poster image.

    テロップが焼き込まれた動画フレームに重ねるとタイトルが二重写りするため、
    シーン0の元画像(テロップなし)があればそれをベースに使う。無い場合のみ
    従来どおり動画からフレームを抜く。
    """
    output_path.parent.mkdir(parents=True, exist_ok=True)
    generated_base = output_path.parent / "assets" / "thumbnail_base_generated.png"
    custom_base = output_path.parent / "assets" / "thumbnail_base_codex.png"
    base_image = (
        generated_base if generated_base.is_file()
        else custom_base if custom_base.is_file()
        else output_path.parent / "assets" / "scene_00.png"
    )
    if base_image.exists() and base_image.stat().st_size > 0:
        from PIL import Image
        Image.open(base_image).convert("RGB").save(output_path, "JPEG", quality=92)
    else:
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
    title_file = output_path.parent / "assets" / "thumbnail_title.txt"
    if title_file.is_file():
        title = title_file.read_text(encoding="utf-8").strip() or title
    topic_file = output_path.parent / "assets" / "thumbnail_topic.txt"
    topic_label = topic_file.read_text(encoding="utf-8").strip() if topic_file.is_file() else None
    _overlay_thumbnail_title(output_path, title, topic_label=topic_label)
    return output_path


def append_commercial_outro(video_path: Path) -> Path:
    """Append the canonical outros: Kurage FreqAI Trade commercial, then the channel outro."""
    outros: list[Path] = []
    for enabled, outro_file in (
        (COMMERCIAL_OUTRO_ENABLED, COMMERCIAL_OUTRO_FILE),
        (CHANNEL_OUTRO_ENABLED, CHANNEL_OUTRO_FILE),
    ):
        if not enabled:
            continue
        if not outro_file.is_file():
            print(f"  [commercial] skipped; asset not found: {outro_file}", flush=True)
            continue
        outros.append(outro_file)
    if not outros:
        return video_path

    merged = video_path.with_name(f"{video_path.stem}.commercial.mp4")
    inputs = [video_path, *outros]
    cmd = ["ffmpeg", "-y", "-loglevel", "error"]
    for input_file in inputs:
        cmd += ["-i", str(input_file)]
    filters = []
    concat_refs = ""
    for i in range(len(inputs)):
        filters.append(
            f"[{i}:v]scale=1080:1920:force_original_aspect_ratio=decrease,"
            f"pad=1080:1920:(ow-iw)/2:(oh-ih)/2:color=#f7fbf8,fps=30,setsar=1[v{i}]"
        )
        filters.append(
            f"[{i}:a]aresample=48000,aformat=sample_fmts=fltp:channel_layouts=stereo[a{i}]"
        )
        concat_refs += f"[v{i}][a{i}]"
    cmd += [
        "-filter_complex",
        ";".join(filters) + f";{concat_refs}concat=n={len(inputs)}:v=1:a=1[v][a]",
        "-map", "[v]", "-map", "[a]", "-c:v", "libx264", "-preset", "medium",
        "-crf", "20", "-c:a", "aac", "-b:a", "192k", "-movflags", "+faststart", str(merged),
    ]
    result = subprocess.run(cmd, capture_output=True, text=True, timeout=1800)
    if result.returncode != 0 or not merged.is_file() or merged.stat().st_size == 0:
        raise RuntimeError(f"commercial outro concat failed: {result.stderr[-1200:]}")
    merged.replace(video_path)
    print(f"  [commercial] appended outros: {', '.join(o.name for o in outros)}", flush=True)
    return video_path


def generate_video(script: dict, image_paths: list[Path], job_dir: Path, vtuber_mode: bool = False,
                   edl: dict | None = None, include_promotional_outros: bool = True) -> Path:
    """Full video generation pipeline: project setup + render.

    Returns:
        Path to the output MP4 file
    """
    output_path = job_dir / "output.mp4"
    project_dir = create_hf_project(job_dir, script, image_paths, vtuber_mode=vtuber_mode, edl=edl)
    print(f"  [video] HyperFrames project: {project_dir}", flush=True)
    render_video(project_dir, output_path)
    print(f"  [video] Rendered: {output_path} ({output_path.stat().st_size} bytes)", flush=True)
    if include_promotional_outros:
        append_commercial_outro(output_path)
    else:
        print("  [commercial] skipped for general-user video", flush=True)
    thumb_path = generate_thumbnail(output_path, job_dir / "thumbnail.jpg", title=script.get("title"))
    print(f"  [video] Thumbnail: {thumb_path} ({thumb_path.stat().st_size} bytes)", flush=True)
    return output_path
