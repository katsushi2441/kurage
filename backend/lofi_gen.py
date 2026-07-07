"""Lo-fi long-form video generation for Kurage."""
from __future__ import annotations

import json
import html
import re
import shutil
import subprocess
import time
import uuid
from pathlib import Path
from typing import Any

from config import ROOT, STORAGE_DIR, JOBS_DIR
from image_gen import generate_image

LOFI_DIR = STORAGE_DIR / "lofi"
LOFI_JOBS_DIR = LOFI_DIR / "jobs"


def _now() -> str:
    return time.strftime("%Y-%m-%d %H:%M:%S")


def _safe_title(text: str) -> str:
    text = (text or "").strip()
    text = re.sub(r"\.[A-Za-z0-9]{2,5}$", "", text)
    text = re.sub(r"[_\-]+", " ", text)
    text = re.sub(r"\s+", " ", text)
    return text[:80].strip() or "Kurage Lo-Fi"


def _safe_id(value: str) -> str:
    value = re.sub(r"[^A-Za-z0-9]", "", value or "")
    if 8 <= len(value) <= 32:
        return value
    return uuid.uuid4().hex[:16]


def job_dir(job_id: str) -> Path:
    return LOFI_JOBS_DIR / _safe_id(job_id)


def job_path(job_id: str) -> Path:
    return LOFI_JOBS_DIR / f"{_safe_id(job_id)}.json"


def update_lofi_job(job_id: str, **kwargs: Any) -> dict[str, Any]:
    LOFI_JOBS_DIR.mkdir(parents=True, exist_ok=True)
    path = job_path(job_id)
    data: dict[str, Any] = {}
    if path.exists():
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except Exception:
            data = {}
    data.update(kwargs)
    data["job_id"] = _safe_id(job_id)
    data["updated_at"] = _now()
    tmp = path.with_suffix(".json.tmp")
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    tmp.replace(path)
    return data


def load_lofi_job(job_id: str) -> dict[str, Any] | None:
    path = job_path(job_id)
    if not path.exists():
        return None
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return None


def list_lofi_jobs(limit: int = 20) -> list[dict[str, Any]]:
    LOFI_JOBS_DIR.mkdir(parents=True, exist_ok=True)
    rows: list[dict[str, Any]] = []
    for path in LOFI_JOBS_DIR.glob("*.json"):
        try:
            rows.append(json.loads(path.read_text(encoding="utf-8")))
        except Exception:
            continue
    rows.sort(key=lambda j: j.get("created_at") or j.get("updated_at") or "", reverse=True)
    return rows[: max(1, min(100, limit))]


def delete_lofi_job(job_id: str) -> bool:
    jid = _safe_id(job_id)
    found = False
    p = job_path(jid)
    d = job_dir(jid)
    if p.exists():
        p.unlink()
        found = True
    if d.exists():
        shutil.rmtree(d)
        found = True
    public_job_path = JOBS_DIR / f"{jid}.json"
    public_job_dir = JOBS_DIR / jid
    if public_job_path.exists():
        public_job_path.unlink()
        found = True
    if public_job_dir.exists():
        shutil.rmtree(public_job_dir)
        found = True
    return found


def lofi_public_file(job_id: str, name: str) -> Path | None:
    allowed = {"output.mp4", "cover.png", "music.mp3", "composition.html"}
    if name not in allowed:
        return None
    path = job_dir(job_id) / name
    return path if path.exists() else None


def _build_image_prompt(title: str, image_prompt: str = "") -> str:
    if image_prompt.strip():
        subject = image_prompt.strip()
    else:
        subject = title
    return (
        "bright clean lo-fi anime illustration, cozy study room, soft daylight, "
        "gentle ocean-blue and cream palette, relaxing ambience, premium YouTube background, "
        "a cute jellyfish-inspired girl mascot subtly present, headphones, desk, plants, warm coffee, "
        f"theme inspired by: {subject}, "
        "no text, no letters, no watermark, no logo, no dark horror, high detail, calm and polished"
    )


def _write_hyperframes_composition(job_id: str, title: str, duration_seconds: int, cover_name: str, audio_name: str) -> Path:
    """Save a simple HyperFrames-compatible composition for inspection/provenance.

    The 60-minute production file is rendered by ffmpeg for stability, but this
    HTML keeps the lo-fi visual direction inspectable and reusable.
    """
    safe_title = html.escape(title, quote=True)
    html_doc = f"""<!doctype html>
<html lang=\"ja\">
<head>
<meta charset=\"utf-8\">
<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">
<title>{safe_title}</title>
<style>
*{{box-sizing:border-box}}body{{margin:0;background:#f8fcfb;color:#14313a;font-family:'Noto Sans JP','Avenir Next',sans-serif;overflow:hidden}}
[data-composition-id=\"klofi\"]{{width:100%;height:100%;position:relative;background:linear-gradient(180deg,#fff 0%,#f2fbfd 100%);overflow:hidden}}
.cover{{position:absolute;inset:-4%;background:url('{cover_name}') center/cover no-repeat;filter:saturate(1.05) brightness(1.03);transform-origin:center}}
.veil{{position:absolute;inset:0;background:radial-gradient(circle at 20% 10%,rgba(255,255,255,.8),transparent 34%),linear-gradient(90deg,rgba(255,255,255,.34),rgba(255,255,255,.06))}}
.card{{position:absolute;left:72px;bottom:64px;right:72px;padding:28px 34px;border-radius:28px;background:rgba(255,255,255,.78);border:1px solid rgba(160,205,214,.75);box-shadow:0 18px 70px rgba(21,62,72,.14);backdrop-filter:blur(14px)}}
.eyebrow{{font-size:22px;font-weight:900;letter-spacing:.18em;text-transform:uppercase;color:#078aa6;margin-bottom:10px}}
.title{{font-size:54px;line-height:1.08;font-weight:950;letter-spacing:-.04em;text-wrap:balance}}
.note{{margin-top:12px;font-size:22px;color:#5f7680}}
</style>
</head>
<body>
<div data-composition-id=\"klofi\" data-width=\"1920\" data-height=\"1080\" data-duration=\"{duration_seconds}\">
  <div id=\"bg\" class=\"cover\"></div>
  <div class=\"veil\"></div>
  <div class=\"card\">
    <div class=\"eyebrow\">Kurage Lo-Fi</div>
    <div class=\"title\">{safe_title}</div>
    <div class=\"note\">Suno BGM loop / ERNIE cover / long-form focus video</div>
  </div>
  <audio id=\"music\" data-start=\"0\" data-duration=\"{duration_seconds}\" data-track-index=\"2\" src=\"{audio_name}\" data-volume=\"1\"></audio>
</div>
<script src=\"https://cdn.jsdelivr.net/npm/gsap@3.14.2/dist/gsap.min.js\"></script>
<script>
window.__timelines = window.__timelines || {{}};
const tl = gsap.timeline({{ paused: true }});
tl.from('.card', {{ y: 40, opacity: 0, duration: 1.2, ease: 'power3.out' }}, 0.25);
tl.to('#bg', {{ scale: 1.08, duration: {duration_seconds}, ease: 'none' }}, 0);
window.__timelines['klofi'] = tl;
</script>
</body>
</html>
"""
    out = job_dir(job_id) / "composition.html"
    out.write_text(html_doc, encoding="utf-8")
    return out


def _run_ffmpeg(job_id: str, duration_seconds: int) -> Path:
    d = job_dir(job_id)
    cover = d / "cover.png"
    audio = d / "music.mp3"
    out = d / "output.mp4"
    fade_out_start = max(0, duration_seconds - 4)
    vf = (
        "scale=2200:1240:force_original_aspect_ratio=increase,"
        "crop=2200:1240,setsar=1,"
        "zoompan=z='min(zoom+0.000012,1.08)':"
        "x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=1:s=1920x1080:fps=30,"
        "format=yuv420p"
    )
    af = f"afade=t=in:st=0:d=2,afade=t=out:st={fade_out_start}:d=4"
    cmd = [
        "ffmpeg", "-y",
        "-loop", "1", "-framerate", "30", "-i", str(cover),
        "-stream_loop", "-1", "-i", str(audio),
        "-t", str(duration_seconds),
        "-vf", vf,
        "-af", af,
        "-map", "0:v:0", "-map", "1:a:0",
        "-c:v", "libx264", "-preset", "veryfast", "-crf", "23",
        "-c:a", "aac", "-b:a", "192k",
        "-movflags", "+faststart",
        "-shortest", str(out),
    ]
    proc = subprocess.run(cmd, cwd=str(ROOT), text=True, capture_output=True, timeout=max(600, duration_seconds * 4))
    if proc.returncode != 0 or not out.exists() or out.stat().st_size < 100_000:
        raise RuntimeError((proc.stderr or proc.stdout or "ffmpeg failed")[-4000:])
    return out


def _write_public_kurage_job(
    job_id: str,
    *,
    title: str,
    original_filename: str,
    duration_minutes: int,
    duration_seconds: int,
    video: Path,
    cover: Path,
    composition: Path,
    created_at: str,
) -> None:
    """Expose a completed lo-fi render in the standard kuragev.php list."""
    import hashlib
    from PIL import Image

    jid = _safe_id(job_id)
    public_dir = JOBS_DIR / jid
    public_dir.mkdir(parents=True, exist_ok=True)
    thumb = public_dir / "thumbnail.jpg"
    with Image.open(cover) as img:
        img = img.convert("RGB")
        img.thumbnail((1280, 720))
        canvas = Image.new("RGB", (1280, 720), (248, 252, 253))
        x = (1280 - img.width) // 2
        y = (720 - img.height) // 2
        canvas.paste(img, (x, y))
        canvas.save(thumb, "JPEG", quality=90)

    summary = (
        f"{duration_minutes}分の作業用lo-fi BGM動画です。勉強、仕事、コーディング、深い集中、"
        "コーヒータイム、リラックス、睡眠前のBGMとして使いやすい落ち着いた映像にしています。\n\n"
        "この動画はKurage Lo-Fiで生成しました。\n"
        "- BGM: Sunoなどで作成したlo-fi音源\n"
        "- ビジュアル: ERNIEで生成したlo-fiアート\n"
        "- 動画生成: Kurage / HyperFrames系の長尺動画パイプライン\n\n"
        "Kurage Project:\n"
        "https://kurage.exbridge.jp/"
    )
    youtube_description = (
        f"A calm {duration_minutes}-minute lo-fi mix for studying, working, coding, relaxing, or sleeping.\n\n"
        "This video was created with Kurage Lo-Fi:\n"
        "- Music: Suno-generated lo-fi BGM\n"
        "- Visual: ERNIE-generated lo-fi artwork\n"
        "- Video: Kurage / HyperFrames long-form video pipeline\n\n"
        "Use it as background music for:\n"
        "study / work / coding / deep focus / coffee time / sleep\n\n"
        "Kurage Project:\n"
        "https://kurage.exbridge.jp/\n\n"
        "Generated by Kurage Lo-Fi."
    )
    now = _now()
    public_job = {
        "job_id": jid,
        "status": "done",
        "progress": 100,
        "source": "klofi",
        "content_type": "lofi_longform",
        "tool_key": "klofi",
        "tool_label": "Kurage Lo-Fi",
        "title": title,
        "display_title": title,
        "summary_title": title,
        "source_title": "",
        "lofi_original_filename": original_filename,
        "tweet_author": "Kurage Lo-Fi",
        "tweet_author_name": "Kurage Lo-Fi",
        "tweet_text": summary,
        "summary": summary,
        "display_summary": summary,
        "youtube_description": youtube_description,
        "duration_seconds": duration_seconds,
        "duration_minutes": duration_minutes,
        "video_file": str(video),
        "thumbnail_file": str(thumb),
        "lofi_audio_file": str(job_dir(jid) / "music.mp3"),
        "lofi_cover_file": str(cover),
        "lofi_composition_file": str(composition),
        "created_at": created_at,
        "updated_at": now,
        "completed_at": now,
        "views": 0,
        "static_media_status": "local",
        "source_hash": hashlib.sha256((original_filename + title).encode("utf-8")).hexdigest()[:16],
    }
    path = JOBS_DIR / f"{jid}.json"
    tmp = path.with_suffix(".json.tmp")
    tmp.write_text(json.dumps(public_job, ensure_ascii=False, indent=2), encoding="utf-8")
    tmp.replace(path)


def run_lofi_job(job_id: str, title: str, duration_minutes: int, image_prompt: str = "") -> None:
    title = _safe_title(title)
    duration_minutes = max(1, min(180, int(duration_minutes or 60)))
    duration_seconds = duration_minutes * 60
    d = job_dir(job_id)
    try:
        update_lofi_job(job_id, status="image", progress=15, message="ERNIEでlo-fi静止画像を生成中")
        cover = d / "cover.png"
        generate_image(_build_image_prompt(title, image_prompt), cover, width=1024, height=1024)

        update_lofi_job(job_id, status="composition", progress=35, message="HyperFrames構成HTMLを作成中")
        comp = _write_hyperframes_composition(job_id, title, duration_seconds, "cover.png", "music.mp3")

        update_lofi_job(job_id, status="rendering", progress=55, message=f"{duration_minutes}分のlo-fi動画をレンダリング中")
        video = _run_ffmpeg(job_id, duration_seconds)

        update_lofi_job(
            job_id,
            status="done",
            progress=100,
            message="完了",
            title=title,
            duration_minutes=duration_minutes,
            duration_seconds=duration_seconds,
            cover_file=str(cover),
            composition_file=str(comp),
            video_file=str(video),
            video_url=f"/lofi/file/{job_id}/output.mp4",
            cover_url=f"/lofi/file/{job_id}/cover.png",
            composition_url=f"/lofi/file/{job_id}/composition.html",
            completed_at=_now(),
        )
        lofi_state = load_lofi_job(job_id) or {}
        _write_public_kurage_job(
            job_id,
            title=title,
            original_filename=str(lofi_state.get("original_filename") or title),
            duration_minutes=duration_minutes,
            duration_seconds=duration_seconds,
            video=video,
            cover=cover,
            composition=comp,
            created_at=str(lofi_state.get("created_at") or _now()),
        )
    except Exception as exc:
        update_lofi_job(job_id, status="error", progress=80, error=str(exc), message="生成に失敗しました")


def publish_existing_lofi_job(job_id: str) -> dict[str, Any]:
    """Publish an already completed lo-fi job into the standard Kurage listing."""
    job = load_lofi_job(job_id)
    if not job:
        raise FileNotFoundError(f"lo-fi job not found: {job_id}")
    if job.get("status") != "done":
        raise ValueError(f"lo-fi job is not done: {job.get('status')}")
    video = Path(str(job.get("video_file") or job_dir(job_id) / "output.mp4"))
    cover = Path(str(job.get("cover_file") or job_dir(job_id) / "cover.png"))
    comp = Path(str(job.get("composition_file") or job_dir(job_id) / "composition.html"))
    _write_public_kurage_job(
        job_id,
        title=_safe_title(str(job.get("title") or job.get("original_filename") or "Kurage Lo-Fi")),
        original_filename=str(job.get("original_filename") or ""),
        duration_minutes=int(job.get("duration_minutes") or 60),
        duration_seconds=int(job.get("duration_seconds") or int(job.get("duration_minutes") or 60) * 60),
        video=video,
        cover=cover,
        composition=comp,
        created_at=str(job.get("created_at") or _now()),
    )
    return load_lofi_job(job_id) or {}


def create_lofi_job(audio_tmp: Path, original_filename: str, title: str = "", duration_minutes: int = 60, image_prompt: str = "") -> str:
    job_id = uuid.uuid4().hex[:16]
    d = job_dir(job_id)
    d.mkdir(parents=True, exist_ok=True)
    display_title = _safe_title(title or original_filename)
    audio_path = d / "music.mp3"
    shutil.copy2(audio_tmp, audio_path)
    update_lofi_job(
        job_id,
        status="queued",
        progress=0,
        title=display_title,
        original_filename=original_filename,
        duration_minutes=max(1, min(180, int(duration_minutes or 60))),
        image_prompt=image_prompt,
        audio_file=str(audio_path),
        created_at=_now(),
        message="待機中",
    )
    return job_id
