#!/usr/bin/env python3
"""既存の done ジョブに duration_seconds を一括付与する。

video-sitemap.php / kuragev.php のJSON-LDが <video:duration> を出せるようにする
一回限りのbackfill。以後の新規ジョブは sync-static-media.py が自動で付与する。
"""
from __future__ import annotations

import json
import os
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
JOBS_DIR = Path(os.environ.get("KURAGE_JOBS_DIR", ROOT / "storage" / "jobs")).expanduser()


def probe(video: Path) -> int:
    try:
        proc = subprocess.run(
            ["ffprobe", "-v", "error", "-show_entries", "format=duration",
             "-of", "csv=p=0", str(video)],
            text=True, capture_output=True, timeout=60, check=False,
        )
        return int(round(float(proc.stdout.strip())))
    except Exception:
        return 0


def main() -> int:
    done = skipped = failed = 0
    for jf in sorted(JOBS_DIR.glob("*.json")):
        try:
            job = json.loads(jf.read_text(encoding="utf-8"))
        except Exception:
            continue
        if not isinstance(job, dict) or job.get("status") != "done":
            continue
        if job.get("duration_seconds"):
            skipped += 1
            continue
        video = Path(str(job.get("video_file") or JOBS_DIR / jf.stem / "output.mp4"))
        if not video.is_file():
            failed += 1
            continue
        dur = probe(video)
        if dur <= 0:
            failed += 1
            continue
        job["duration_seconds"] = dur
        tmp = jf.with_suffix(jf.suffix + ".tmp")
        tmp.write_text(json.dumps(job, ensure_ascii=False, indent=2), encoding="utf-8")
        tmp.replace(jf)
        done += 1
        if done % 100 == 0:
            print(f"...{done} done", flush=True)
    print(f"backfilled={done} already={skipped} failed={failed}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
