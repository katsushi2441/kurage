#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import shutil
import subprocess
import time
from pathlib import Path
from urllib.parse import quote

import requests
from PIL import Image, ImageDraw, ImageFont, ImageFilter


ROOT = Path(__file__).resolve().parents[1]
JOBS_DIR = ROOT / "storage" / "jobs"
STOCK_DIR = ROOT / "storage" / "stock_intro"

ARCHIVE_ID = "a-guy-checks-his-computer-on-new-years-night-in-2000"
ARCHIVE_SOURCE_URL = f"https://archive.org/details/{ARCHIVE_ID}"
ARCHIVE_LICENSE = "Archive.org public-domain / CC item; verify per item metadata"

W, H = 576, 1024


def run(cmd: list[str], *, cwd: Path | None = None, timeout: int = 900) -> None:
    proc = subprocess.run(
        cmd,
        cwd=str(cwd or ROOT),
        text=True,
        capture_output=True,
        timeout=timeout,
        check=False,
    )
    if proc.returncode != 0:
        raise RuntimeError(
            "command failed: "
            + " ".join(cmd)
            + f"\nstdout:\n{proc.stdout[-2000:]}\nstderr:\n{proc.stderr[-2000:]}"
        )


def probe_duration(path: Path) -> float:
    proc = subprocess.run(
        ["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", str(path)],
        text=True,
        capture_output=True,
        timeout=60,
        check=False,
    )
    if proc.returncode != 0:
        return 0.0
    try:
        return float(proc.stdout.strip())
    except Exception:
        return 0.0


def font_path() -> str:
    candidates = [
        "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc",
        "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc",
        "/usr/share/fonts/truetype/noto/NotoSansCJK-Bold.ttc",
        "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
    ]
    for candidate in candidates:
        if Path(candidate).is_file():
            return candidate
    return "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"


def wrap_text(draw: ImageDraw.ImageDraw, text: str, font: ImageFont.FreeTypeFont, max_width: int) -> list[str]:
    lines: list[str] = []
    current = ""
    for ch in text:
        trial = current + ch
        if not current or draw.textbbox((0, 0), trial, font=font)[2] <= max_width:
            current = trial
        else:
            lines.append(current)
            current = ch
    if current:
        lines.append(current)
    return lines[:4]


def archive_download_url(identifier: str) -> str:
    meta = requests.get(f"https://archive.org/metadata/{identifier}", timeout=45)
    meta.raise_for_status()
    data = meta.json()
    for item in data.get("files") or []:
        name = str(item.get("name") or "")
        fmt = str(item.get("format") or "")
        if name.lower().endswith(".mp4") or "mpeg4" in fmt.lower() or "h.264" in fmt.lower():
            return f"https://archive.org/download/{identifier}/{quote(name)}"
    raise RuntimeError(f"no mp4 found in archive.org item: {identifier}")


def download_stock_video() -> Path:
    STOCK_DIR.mkdir(parents=True, exist_ok=True)
    out = STOCK_DIR / f"{ARCHIVE_ID}.mp4"
    meta_out = STOCK_DIR / f"{ARCHIVE_ID}.json"
    if out.is_file() and out.stat().st_size > 100_000:
        return out
    url = archive_download_url(ARCHIVE_ID)
    with requests.get(url, stream=True, timeout=300) as res:
        res.raise_for_status()
        tmp = out.with_suffix(".mp4.tmp")
        with tmp.open("wb") as f:
            for chunk in res.iter_content(chunk_size=1 << 16):
                if chunk:
                    f.write(chunk)
        tmp.replace(out)
    meta_out.write_text(
        json.dumps(
            {
                "source": "archive_org",
                "archive_id": ARCHIVE_ID,
                "source_url": ARCHIVE_SOURCE_URL,
                "download_url": url,
                "license": ARCHIVE_LICENSE,
                "downloaded_at": time.strftime("%Y-%m-%d %H:%M:%S"),
            },
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )
    return out


def make_keyframe_image(base_frame: Path, output: Path, title: str) -> None:
    img = Image.open(base_frame).convert("RGB").resize((W, H))
    veil = Image.new("RGBA", (W, H), (255, 255, 255, 0))
    vd = ImageDraw.Draw(veil)
    vd.rectangle((0, 0, W, H), fill=(255, 255, 255, 84))
    vd.rounded_rectangle((36, 90, W - 36, 562), radius=42, fill=(255, 255, 255, 212), outline=(27, 145, 170, 70), width=2)
    vd.rounded_rectangle((56, 112, 222, 158), radius=23, fill=(8, 138, 166, 230))
    veil = veil.filter(ImageFilter.GaussianBlur(radius=0.2))
    img = Image.alpha_composite(img.convert("RGBA"), veil)

    draw = ImageDraw.Draw(img)
    fp = font_path()
    badge_font = ImageFont.truetype(fp, 22)
    title_font = ImageFont.truetype(fp, 44)
    sub_font = ImageFont.truetype(fp, 21)
    small_font = ImageFont.truetype(fp, 18)

    draw.text((78, 120), "THUMBNAIL KEYFRAME", font=badge_font, fill=(255, 255, 255, 255))
    headline = "AIでYouTube成功の型を再現"
    y = 204
    for line in wrap_text(draw, headline, title_font, W - 120):
        draw.text((58, y), line, font=title_font, fill=(23, 49, 58, 255), stroke_width=2, stroke_fill=(255, 255, 255, 220))
        y += 58
    draw.text((60, y + 12), "初期投資ゼロ / Claude分析 / 収益化", font=sub_font, fill=(6, 95, 116, 255))
    draw.text((60, H - 98), title[:34], font=small_font, fill=(23, 49, 58, 230))
    output.parent.mkdir(parents=True, exist_ok=True)
    img.convert("RGB").save(output, "JPEG", quality=94)


def make_motion_overlay(output: Path) -> None:
    img = Image.new("RGBA", (W, H), (255, 255, 255, 0))
    draw = ImageDraw.Draw(img)
    fp = font_path()
    badge_font = ImageFont.truetype(fp, 25)
    title_font = ImageFont.truetype(fp, 33)
    sub_font = ImageFont.truetype(fp, 25)

    draw.rounded_rectangle((26, 50, W - 26, 246), radius=32, fill=(255, 255, 255, 202), outline=(7, 138, 166, 72), width=3)
    draw.text((46, 74), "Free stock footage opening", font=badge_font, fill=(7, 83, 106, 255))
    draw.text((46, 120), "AI収益化の仕組みを4秒で掴む", font=title_font, fill=(23, 49, 58, 255))
    draw.text((46, 190), "この後、Kurage本編へ", font=sub_font, fill=(6, 95, 116, 255))
    output.parent.mkdir(parents=True, exist_ok=True)
    img.save(output)


def make_intro(stock: Path, work_dir: Path, title: str) -> Path:
    work_dir.mkdir(parents=True, exist_ok=True)
    raw_frame = work_dir / "stock_first_frame.jpg"
    key_jpg = work_dir / "keyframe.jpg"
    key_mp4 = work_dir / "intro_keyframe.mp4"
    overlay_png = work_dir / "motion_overlay.png"
    motion_mp4 = work_dir / "intro_motion.mp4"
    intro_mp4 = work_dir / "intro.mp4"

    vf_cover = f"scale={W}:{H}:force_original_aspect_ratio=increase,crop={W}:{H},fps=30,format=yuv420p"
    # The motion segment is the same stock clip with a gentle push-in.
    vf_zoom = (
        f"scale={W}:{H}:force_original_aspect_ratio=increase,crop={W}:{H},"
        "zoompan=z='min(zoom+0.0015,1.11)':d=1:x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':"
        f"s={W}x{H}:fps=30,format=yuv420p"
    )
    run(["ffmpeg", "-y", "-ss", "0.2", "-i", str(stock), "-frames:v", "1", "-vf", vf_cover, str(raw_frame)], timeout=120)
    make_keyframe_image(raw_frame, key_jpg, title)
    run(
        [
            "ffmpeg", "-y", "-loop", "1", "-t", "0.5", "-i", str(key_jpg),
            "-f", "lavfi", "-t", "0.5", "-i", "anullsrc=channel_layout=stereo:sample_rate=44100",
            "-r", "30", "-c:v", "libx264", "-pix_fmt", "yuv420p", "-c:a", "aac", "-shortest", str(key_mp4),
        ],
        timeout=120,
    )

    make_motion_overlay(overlay_png)
    filter_complex = (
        f"[0:v]{vf_zoom},eq=brightness=0.08:saturation=0.94[bg];"
        "[1:v]format=rgba[ol];"
        "[bg][ol]overlay=0:0:format=auto[v]"
    )
    run(
        [
            "ffmpeg", "-y", "-stream_loop", "-1", "-i", str(stock),
            "-loop", "1", "-t", "3.5", "-i", str(overlay_png),
            "-f", "lavfi", "-t", "3.5", "-i", "anullsrc=channel_layout=stereo:sample_rate=44100",
            "-t", "3.5", "-filter_complex", filter_complex, "-map", "[v]", "-map", "2:a",
            "-r", "30", "-c:v", "libx264", "-preset", "medium", "-crf", "20",
            "-c:a", "aac", "-shortest", str(motion_mp4),
        ],
        timeout=240,
    )
    concat = work_dir / "intro_concat.txt"
    concat.write_text(f"file '{key_mp4}'\nfile '{motion_mp4}'\n", encoding="utf-8")
    run(["ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", str(concat), "-c", "copy", str(intro_mp4)], timeout=120)
    return intro_mp4


def normalize_body(source_video: Path, out: Path) -> Path:
    vf = f"scale={W}:{H}:force_original_aspect_ratio=increase,crop={W}:{H},fps=30,format=yuv420p"
    run(
        [
            "ffmpeg", "-y", "-i", str(source_video), "-vf", vf, "-r", "30",
            "-c:v", "libx264", "-preset", "medium", "-crf", "20",
            "-c:a", "aac", "-ar", "44100", "-ac", "2", "-movflags", "+faststart", str(out),
        ],
        timeout=900,
    )
    return out


def concat_videos(parts: list[Path], output: Path) -> None:
    list_file = output.with_suffix(".concat.txt")
    list_file.write_text("".join(f"file '{p}'\n" for p in parts), encoding="utf-8")
    run(["ffmpeg", "-y", "-f", "concat", "-safe", "0", "-i", str(list_file), "-c", "copy", "-movflags", "+faststart", str(output)], timeout=300)


def make_thumbnail(video: Path, output: Path) -> None:
    run(["ffmpeg", "-y", "-ss", "0.15", "-i", str(video), "-frames:v", "1", "-q:v", "3", str(output)], timeout=60)


def main() -> int:
    parser = argparse.ArgumentParser(description="Regenerate a Kurage video with a stock-footage opening template.")
    parser.add_argument("source_job_id")
    parser.add_argument("--new-job-id", default="", help="Create a separate job only when this is explicitly set.")
    parser.add_argument("--title-suffix", default="", help="Optional title suffix, normally unused for overwrite regeneration.")
    parser.add_argument("--stock-file", default="", help="Use this local stock video instead of the default Archive.org clip.")
    parser.add_argument("--stock-source-url", default="", help="Source page URL for provenance when --stock-file is used.")
    parser.add_argument("--stock-license", default="", help="License note for provenance when --stock-file is used.")
    parser.add_argument("--body-file", default="", help="Use this body video instead of the job output. Useful for overwrite regeneration.")
    args = parser.parse_args()

    src_id = "".join(ch for ch in args.source_job_id if ch.isalnum())
    src_json = JOBS_DIR / f"{src_id}.json"
    if not src_json.is_file():
        raise SystemExit(f"source job json not found: {src_json}")
    src_job = json.loads(src_json.read_text(encoding="utf-8"))
    body_candidate = Path(args.body_file).expanduser() if args.body_file else JOBS_DIR / src_id / "body.mp4"
    src_video = body_candidate if body_candidate.is_file() else Path(str(src_job.get("video_file") or JOBS_DIR / src_id / "output.mp4"))
    if not src_video.is_file():
        raise SystemExit(f"source video not found: {src_video}")

    target_id = "".join(ch for ch in (args.new_job_id or src_id) if ch.isalnum())
    create_new = target_id != src_id
    job_dir = JOBS_DIR / target_id
    if create_new and (job_dir.exists() or (JOBS_DIR / f"{target_id}.json").exists()):
        raise SystemExit(f"target job already exists: {target_id}")
    job_dir.mkdir(parents=True, exist_ok=True)
    work_dir = job_dir / "_stock_intro_work"
    if work_dir.exists():
        shutil.rmtree(work_dir)
    work_dir.mkdir(parents=True)

    title = str(src_job.get("title") or src_job.get("display_title") or "Kurage動画")
    out_title = title + args.title_suffix if create_new else title
    stock = Path(args.stock_file).expanduser() if args.stock_file else download_stock_video()
    if not stock.is_file():
        raise SystemExit(f"stock video not found: {stock}")
    intro = make_intro(stock, work_dir / "intro_template", title)
    body = normalize_body(src_video, work_dir / "body.mp4")
    output_tmp = work_dir / "output.mp4"
    concat_videos([intro, body], output_tmp)
    thumb_tmp = work_dir / "thumbnail.jpg"
    make_thumbnail(output_tmp, thumb_tmp)

    output = job_dir / "output.mp4"
    thumb = job_dir / "thumbnail.jpg"
    shutil.copy2(output_tmp, output)
    shutil.copy2(thumb_tmp, thumb)
    intro_dest = job_dir / "intro_template"
    if intro_dest.exists():
        shutil.rmtree(intro_dest)
    shutil.copytree(work_dir / "intro_template", intro_dest)
    shutil.copy2(work_dir / "body.mp4", job_dir / "body.mp4")

    now = time.strftime("%Y-%m-%d %H:%M:%S")
    new_job = dict(src_job)
    new_job.update(
        {
            "status": "done",
            "progress": 100,
            "updated_at": now,
            "title": out_title,
            "display_title": out_title,
            "summary_title": out_title,
            "video_file": str(output),
            "thumbnail_file": str(thumb),
            "duration_seconds": int(round(probe_duration(output))),
            "source": src_job.get("source") or "kurage",
            "regenerated_from_job_id": src_id if create_new else src_job.get("regenerated_from_job_id", ""),
            "regenerated_at": now,
            "regenerated_method": "overwrite_stock_intro_v1" if not create_new else "new_stock_intro_v1",
            "opening_template": {
                "type": "stock_intro_v1",
                "thumbnail_keyframe_seconds": 0.5,
                "stock_motion_seconds": 3.5,
                "body_start_seconds": 4.0,
                "stock_source_url": args.stock_source_url or ARCHIVE_SOURCE_URL,
                "stock_license": args.stock_license or ARCHIVE_LICENSE,
                "stock_file": str(stock),
                "body_file": str(src_video),
            },
        }
    )
    if create_new:
        new_job["created_at"] = now
        new_job["views"] = 0
    for key in ("static_video_url", "static_thumbnail_url", "static_media_synced_at", "static_media_error"):
        new_job.pop(key, None)
    new_job["static_media_status"] = "pending"
    (JOBS_DIR / f"{target_id}.json").write_text(json.dumps(new_job, ensure_ascii=False, indent=2), encoding="utf-8")

    # Keep a small provenance bundle inside the job dir for future debugging.
    shutil.copy2(src_json, job_dir / "source_job.json")
    shutil.rmtree(work_dir)
    print(json.dumps({"ok": True, "job_id": target_id, "mode": "new" if create_new else "overwrite", "video": str(output), "thumbnail": str(thumb)}, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
