"""Publish generated Kurage media to static public URLs for video SEO."""
from __future__ import annotations

import subprocess
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
SYNC_SCRIPT = ROOT / "scripts" / "sync-static-media.py"


def publish_static_media(job_id: str, timeout: int = 900) -> dict[str, Any]:
    """Upload one completed job's MP4 and thumbnail to /videos and /thumbs.

    The uploader is intentionally best-effort: callers should record failures
    but must not turn an otherwise valid video generation into an error.
    """
    clean_job_id = "".join(ch for ch in str(job_id) if ch.isalnum())
    if not clean_job_id:
        return {"ok": False, "error": "empty job id"}
    if not SYNC_SCRIPT.is_file():
        return {"ok": False, "error": f"sync script not found: {SYNC_SCRIPT}"}
    proc = subprocess.run(
        ["python3", str(SYNC_SCRIPT), "--job-id", clean_job_id, "--limit", "0"],
        cwd=str(ROOT),
        text=True,
        capture_output=True,
        timeout=timeout,
        check=False,
    )
    if proc.returncode != 0:
        return {"ok": False, "error": (proc.stderr or proc.stdout or "static media sync failed")[-2000:]}
    return {"ok": True, "stdout": proc.stdout[-4000:]}
