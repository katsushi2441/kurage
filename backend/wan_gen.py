"""Wan2.1 video generation + ffmpeg concat with title/subtitle overlays."""
from __future__ import annotations
import math
import os
import shutil
import subprocess
import time
import urllib.request
from pathlib import Path

import requests

WAN_API = os.environ.get("WAN_API", "http://192.168.0.14:8091")
WAN_TEST_MODE = os.environ.get("WAN_TEST_MODE", "1")  # "1"=test, "0"=production

FONT_PATH = "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc"


def generate_wan_videos(scenes: list[dict]) -> list[str]:
    """Call Wan2.1 API and return list of video_url strings."""
    endpoint = f"{WAN_API}/api/test/story" if WAN_TEST_MODE == "1" else f"{WAN_API}/api/story"
    wan_scenes = [
        {"prompt": s.get("image_prompt", "cinematic vertical shot"), "label": s.get("label", str(i))}
        for i, s in enumerate(scenes)
    ]
    print(f"  [wan] POST {endpoint} ({len(wan_scenes)} scenes, test={WAN_TEST_MODE})", flush=True)
    resp = requests.post(endpoint, json={
        "scenes": wan_scenes,
        "size": "480*832",
        "frame_num": 49,
        "sample_steps": 50,
    }, timeout=30)
    resp.raise_for_status()
    data = resp.json()

    if data["status"] == "completed":
        urls = [s["video_url"] for s in data["scenes"]]
        print(f"  [wan] completed immediately: {len(urls)} scenes", flush=True)
        return urls

    story_id = data["story_id"]
    for _ in range(200):
        time.sleep(5)
        s = requests.get(f"{WAN_API}/api/story/{story_id}", timeout=10).json()
        print(f"  [wan] {s.get('progress')} {s.get('status')}", flush=True)
        if s["status"] == "completed":
            return [sc["video_url"] for sc in s["scenes"]]
        if s["status"] == "failed":
            raise RuntimeError(f"Wan生成失敗: {s}")
    raise TimeoutError("Wan動画生成タイムアウト")


def _fmt_ass_time(seconds: float) -> str:
    h = int(seconds // 3600)
    m = int((seconds % 3600) // 60)
    s = seconds % 60
    return f"{h}:{m:02d}:{s:05.2f}"


def _make_ass(script: dict, scene_timing: list[tuple], out_path: Path):
    """Write ASS subtitle file: title overlay + per-scene narration."""
    scenes = script.get("scenes") or []
    title = (script.get("title") or "").replace("\\", "\\\\").replace("{", "\\{")

    ass_lines = [
        "[Script Info]",
        "ScriptType: v4.00+",
        "PlayResX: 480",
        "PlayResY: 832",
        "WrapStyle: 0",
        "",
        "[V4+ Styles]",
        "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding",
        # Narration: bottom-center, white bold with black outline
        "Style: Narr,Noto Sans CJK JP,24,&H00FFFFFF,&H000000FF,&H00000000,&H80000000,-1,0,0,0,100,100,0,0,1,2,3,2,12,12,60,1",
        # Title: middle-center, white bold with dark semi-transparent box
        "Style: Title,Noto Sans CJK JP,30,&H00FFFFFF,&H000000FF,&H00000000,&HA0000000,-1,0,0,0,100,100,0,0,1,3,0,5,40,40,40,1",
        "",
        "[Events]",
        "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text",
    ]

    # Title overlay: 0 → 1.5s
    if title:
        ass_lines.append(
            f"Dialogue: 1,0:00:00.00,0:00:01.50,Title,,0,0,0,,{{\\an5}}{title}"
        )

    # Per-scene narration
    for scene, (start, dur) in zip(scenes, scene_timing):
        narration = (scene.get("narration") or "").strip()
        if not narration:
            continue
        narration = narration.replace("\\", "\\\\").replace("{", "\\{").replace("\n", "\\N")
        t_start = _fmt_ass_time(start)
        t_end = _fmt_ass_time(start + dur)
        ass_lines.append(
            f"Dialogue: 0,{t_start},{t_end},Narr,,0,0,0,,{narration}"
        )

    out_path.write_text("\n".join(ass_lines) + "\n", encoding="utf-8-sig")


def _get_duration(path: Path) -> float:
    r = subprocess.run(
        ["ffprobe", "-v", "error", "-show_entries", "format=duration",
         "-of", "default=noprint_wrappers=1:nokey=1", str(path)],
        capture_output=True, text=True,
    )
    try:
        return float(r.stdout.strip())
    except ValueError:
        return 0.0


def _extend_scene(src: Path, dst: Path, target_dur: float):
    """Extend video to target_dur seconds by freezing the last frame."""
    current = _get_duration(src)
    extra = target_dur - current
    if extra <= 0.05:
        shutil.copy2(str(src), str(dst))
        return
    r = subprocess.run([
        "ffmpeg", "-y",
        "-i", str(src),
        "-vf", f"tpad=stop_mode=clone:stop_duration={extra:.3f}",
        "-c:v", "libx264", "-crf", "23", "-preset", "fast",
        "-an",
        str(dst),
    ], capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(f"tpad failed: {r.stderr[-300:]}")


def concat_with_audio(
    video_urls: list[str],
    audio_path: Path,
    output_path: Path,
    script: dict,
):
    """Download Wan scenes, extend, concat, overlay title+subtitles, mix TTS audio."""
    tmp_dir = output_path.parent / "wan_scenes"
    tmp_dir.mkdir(parents=True, exist_ok=True)

    # Total duration from TTS audio
    narration_dur = _get_duration(audio_path) if audio_path.exists() else 0.0
    total_dur = math.ceil(narration_dur) + 1 if narration_dur > 0 else 30.0
    scenes = script.get("scenes") or []
    num_scenes = len(scenes) if scenes else len(video_urls)
    per_scene = total_dur / num_scenes if num_scenes > 0 else 5.0

    print(f"  [wan] narration={narration_dur:.1f}s total={total_dur}s per_scene={per_scene:.2f}s", flush=True)

    # Download + extend each scene
    extended = []
    for i, url in enumerate(video_urls):
        if url.startswith("/"):
            url = WAN_API + url
        raw = tmp_dir / f"raw_{i:02d}.mp4"
        ext = tmp_dir / f"ext_{i:02d}.mp4"
        print(f"  [wan] dl scene {i}: {url}", flush=True)
        urllib.request.urlretrieve(url, str(raw))
        _extend_scene(raw, ext, per_scene)
        extended.append(ext)

    # Concat
    concat_list = tmp_dir / "concat.txt"
    concat_list.write_text("\n".join(f"file '{p}'" for p in extended), encoding="utf-8")
    concat_mp4 = tmp_dir / "concat_only.mp4"
    r = subprocess.run([
        "ffmpeg", "-y",
        "-f", "concat", "-safe", "0",
        "-i", str(concat_list),
        "-c:v", "libx264", "-crf", "23", "-preset", "fast",
        str(concat_mp4),
    ], capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(f"ffmpeg concat failed: {r.stderr[-300:]}")

    # Build scene timing
    scene_timing = [(i * per_scene, per_scene) for i in range(num_scenes)]

    # ASS subtitle
    ass_path = tmp_dir / "subtitles.ass"
    _make_ass(script, scene_timing, ass_path)

    # Burn subtitles
    subtitled_mp4 = tmp_dir / "subtitled.mp4"
    sub_filter = f"subtitles='{ass_path}':fontsdir=/usr/share/fonts/opentype/noto"
    r = subprocess.run([
        "ffmpeg", "-y",
        "-i", str(concat_mp4),
        "-vf", sub_filter,
        "-c:v", "libx264", "-crf", "23", "-preset", "fast",
        str(subtitled_mp4),
    ], capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(f"ffmpeg subtitles failed: {r.stderr[-500:]}")

    # Mix audio
    if audio_path.exists() and narration_dur > 0:
        r = subprocess.run([
            "ffmpeg", "-y",
            "-i", str(subtitled_mp4),
            "-i", str(audio_path),
            "-c:v", "copy",
            "-c:a", "aac", "-b:a", "128k",
            "-shortest",
            str(output_path),
        ], capture_output=True, text=True)
        if r.returncode != 0:
            raise RuntimeError(f"ffmpeg audio mix failed: {r.stderr[-300:]}")
    else:
        shutil.copy2(str(subtitled_mp4), str(output_path))

    print(f"  [wan] done: {output_path} ({output_path.stat().st_size // 1024}KB)", flush=True)
