#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import time
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
JOBS_DIR = Path(os.environ.get("KURAGE_JOBS_DIR", ROOT / "storage" / "jobs")).expanduser()
BASE_URL = os.environ.get("KURAGE_BASE_URL", "https://kurage.exbridge.jp").rstrip("/")
REMOTE_DIR = os.environ.get("KURAGE_FTP_REMOTE", "/web/kurage_exbridge_jp").rstrip("/")


def load_job(path: Path) -> dict[str, Any] | None:
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return None
    return data if isinstance(data, dict) else None


def save_job(path: Path, data: dict[str, Any]) -> None:
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")
    tmp.replace(path)


def job_id_from(path: Path, data: dict[str, Any]) -> str:
    raw = str(data.get("job_id") or path.stem)
    return "".join(ch for ch in raw if ch.isalnum())


def ftp_upload(local: Path, remote_name: str, timeout: int) -> None:
    host = os.environ.get("FTP_HOST", "").strip()
    user = os.environ.get("FTP_USER", "").strip()
    password = os.environ.get("FTP_PASS", "").strip()
    if not (host and user and password):
        raise RuntimeError("FTP_HOST/FTP_USER/FTP_PASS are required")
    url = f"ftp://{user}:{password}@{host}{REMOTE_DIR}/{remote_name}"
    proc = subprocess.run(
        ["curl", "--fail", "--ftp-create-dirs", "-T", str(local), url],
        cwd=str(ROOT),
        text=True,
        capture_output=True,
        timeout=timeout,
        check=False,
    )
    if proc.returncode != 0:
        tail = (proc.stderr or proc.stdout or "FTP upload failed")[-1200:]
        raise RuntimeError(tail)


def sync_one(job_file: Path, dry_run: bool, no_ftp: bool, timeout: int) -> dict[str, Any]:
    job = load_job(job_file)
    if not job:
        return {"ok": False, "job_file": str(job_file), "error": "invalid-json"}
    job_id = job_id_from(job_file, job)
    if not job_id or job.get("status") != "done":
        return {"ok": False, "job_id": job_id, "skipped": True, "reason": "not-done"}
    expected_video_url = f"{BASE_URL}/videos/{job_id}.mp4"
    expected_thumb_url = f"{BASE_URL}/thumbs/{job_id}.jpg"
    if (
        not dry_run
        and not no_ftp
        and job.get("static_video_url") == expected_video_url
        and job.get("static_thumbnail_url") == expected_thumb_url
    ):
        return {
            "ok": True,
            "job_id": job_id,
            "skipped": True,
            "reason": "already-synced",
            "video": expected_video_url,
            "thumbnail": expected_thumb_url,
        }

    video = Path(str(job.get("video_file") or JOBS_DIR / job_id / "output.mp4")).expanduser()
    thumb = Path(str(job.get("thumbnail_file") or JOBS_DIR / job_id / "thumbnail.jpg")).expanduser()
    files: list[tuple[Path, str, str]] = []
    if video.is_file() and video.stat().st_size > 0:
        files.append((video, f"videos/{job_id}.mp4", "static_video_url"))
    if thumb.is_file() and thumb.stat().st_size > 0:
        files.append((thumb, f"thumbs/{job_id}.jpg", "static_thumbnail_url"))
    if not files:
        return {"ok": False, "job_id": job_id, "skipped": True, "reason": "no-media-files"}

    uploaded: list[str] = []
    if not dry_run and not no_ftp:
        for local, remote_name, _key in files:
            ftp_upload(local, remote_name, timeout)
            uploaded.append(remote_name)

    if not dry_run:
        changed = False
        for _local, remote_name, key in files:
            value = f"{BASE_URL}/{remote_name}"
            if job.get(key) != value:
                job[key] = value
                changed = True
        if job.get("static_media_status") != "done":
            job["static_media_status"] = "done"
            changed = True
        if job.pop("static_media_error", None) is not None:
            changed = True
        if changed or uploaded:
            job["static_media_synced_at"] = time.strftime("%Y-%m-%d %H:%M:%S")
            save_job(job_file, job)

    return {
        "ok": True,
        "job_id": job_id,
        "uploaded": uploaded,
        "video": expected_video_url if any(key == "static_video_url" for *_rest, key in files) else "",
        "thumbnail": expected_thumb_url if any(key == "static_thumbnail_url" for *_rest, key in files) else "",
        "dry_run": dry_run,
        "no_ftp": no_ftp,
    }


def iter_job_files(job_ids: list[str], limit: int) -> list[Path]:
    if job_ids:
        return [JOBS_DIR / f"{''.join(ch for ch in jid if ch.isalnum())}.json" for jid in job_ids]
    files = sorted(JOBS_DIR.glob("*.json"), key=lambda p: p.stat().st_mtime, reverse=True)
    return files if limit <= 0 else files[:limit]


def main() -> int:
    parser = argparse.ArgumentParser(description="Upload Kurage MP4/thumbnail files to static /videos and /thumbs URLs.")
    parser.add_argument("--job-id", action="append", default=[])
    parser.add_argument("--limit", type=int, default=50, help="0 means all jobs")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--no-ftp", action="store_true", help="Only write static URL metadata")
    parser.add_argument("--quiet", action="store_true")
    parser.add_argument("--timeout", type=int, default=600)
    args = parser.parse_args()

    results = []
    ok_count = 0
    for job_file in iter_job_files(args.job_id, args.limit):
        result = sync_one(job_file, args.dry_run, args.no_ftp, args.timeout)
        results.append(result)
        if result.get("ok"):
            ok_count += 1
            if not args.quiet:
                print(json.dumps(result, ensure_ascii=False), flush=True)
    summary = {"ok": True, "synced": ok_count, "checked": len(results), "dry_run": args.dry_run, "no_ftp": args.no_ftp}
    print(json.dumps(summary, ensure_ascii=False), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
