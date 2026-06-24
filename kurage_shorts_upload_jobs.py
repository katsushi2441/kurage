from __future__ import annotations

import json
import os
import subprocess
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parent
SCRIPT = ROOT / "scripts" / "watch-kurage-shorts-upload.mjs"


def _parse_json_output(stdout: str) -> dict[str, Any]:
    text = stdout.strip()
    for index, char in enumerate(text):
        if char != "{":
            continue
        try:
            value = json.loads(text[index:])
        except json.JSONDecodeError:
            continue
        if isinstance(value, dict):
            return value
    return {"stdout": text[-4000:]}


def run_kurage_shorts_upload_job(force: bool = False, **kwargs: Any) -> dict[str, Any]:
    """RQDB4AI entrypoint for posting high-view Kurage shorts to YouTube.

    kdeck owns the 8-hour cadence and daily target. This job performs one
    policy-aware upload attempt using the Kurage-owned script.
    """
    node = os.environ.get("KURAGE_SHORTS_UPLOAD_NODE", "/home/kojima/.nvm/versions/node/v20.20.2/bin/node")
    cmd = [node, str(SCRIPT), "run-once"]
    if force or kwargs.get("force"):
        cmd.append("--force")
    env = {
        **os.environ,
        "KURAGE_JOBS_DIR": os.environ.get("KURAGE_JOBS_DIR", "/home/kojima/work/kurage/storage/jobs"),
        "YOUTUBE_UPLOAD_CWD": os.environ.get("YOUTUBE_UPLOAD_CWD", "/home/kojima/work/airadio-scripted-mv"),
        "YOUTUBE_UPLOAD_TOOL": os.environ.get("YOUTUBE_UPLOAD_TOOL", "/home/kojima/work/airadio-scripted-mv/tools/youtube/upload_youtube.py"),
        "YOUTUBE_TOKEN_PATH": os.environ.get("YOUTUBE_TOKEN_PATH", "/home/kojima/work/airadio-scripted-mv/storage/youtube/token.json"),
        "KURAGE_SHORTS_UPLOAD_COOLDOWN_HOURS": str(kwargs.get("cooldown_hours") or os.environ.get("KURAGE_SHORTS_UPLOAD_COOLDOWN_HOURS", "8")),
        "KURAGE_SHORTS_UPLOAD_MAX_PER_DAY": str(kwargs.get("max_per_day") or os.environ.get("KURAGE_SHORTS_UPLOAD_MAX_PER_DAY", "3")),
        "KURAGE_SHORTS_UPLOAD_TIME_ZONE": str(kwargs.get("time_zone") or os.environ.get("KURAGE_SHORTS_UPLOAD_TIME_ZONE", "Asia/Tokyo")),
        "KURAGE_SHORTS_UPLOAD_PRIVACY": str(kwargs.get("privacy") or os.environ.get("KURAGE_SHORTS_UPLOAD_PRIVACY", "public")),
        "KURAGE_SHORTS_UPLOAD_ANNOUNCE_AIXSNS": str(kwargs.get("announce_aixsns") if "announce_aixsns" in kwargs else os.environ.get("KURAGE_SHORTS_UPLOAD_ANNOUNCE_AIXSNS", "1")),
        "KURAGE_SHORTS_UPLOAD_ANNOUNCE_X": str(kwargs.get("announce_x") if "announce_x" in kwargs else os.environ.get("KURAGE_SHORTS_UPLOAD_ANNOUNCE_X", "0")),
        "KURAGE_SHORTS_UPLOAD_X_BROWSER_USE": str(kwargs.get("x_browser_use") if "x_browser_use" in kwargs else os.environ.get("KURAGE_SHORTS_UPLOAD_X_BROWSER_USE", "1")),
        "BROWSER_AGENT_PYTHON": os.environ.get("BROWSER_AGENT_PYTHON", "/home/kojima/work/browser_agent/.venv/bin/python"),
    }
    proc = subprocess.run(
        cmd,
        cwd=str(ROOT),
        text=True,
        capture_output=True,
        timeout=int(kwargs.get("timeout_seconds") or 2400),
        env=env,
    )
    parsed = _parse_json_output(proc.stdout)
    uploaded = bool(parsed.get("uploaded"))
    ok = proc.returncode == 0 and bool(parsed.get("ok", False))
    result: dict[str, Any] = {
        "ok": ok,
        "status": "ok" if ok else "error",
        "uploaded": uploaded,
        "items": 1 if uploaded else 0,
        "created": 1 if uploaded else 0,
        "source": str(kwargs.get("source") or "rqdb4ai"),
        "youtube_url": parsed.get("youtubeUrl") or "",
        "reason": parsed.get("reason") or "",
        "result": parsed,
    }
    if proc.stderr.strip():
        result["stderr"] = proc.stderr[-4000:]
    if not ok:
        raise RuntimeError(json.dumps(result, ensure_ascii=False))
    return result


def kurage_shorts_upload_status_job(**kwargs: Any) -> dict[str, Any]:
    node = os.environ.get("KURAGE_SHORTS_UPLOAD_NODE", "/home/kojima/.nvm/versions/node/v20.20.2/bin/node")
    proc = subprocess.run(
        [node, str(SCRIPT), "status"],
        cwd=str(ROOT),
        text=True,
        capture_output=True,
        timeout=int(kwargs.get("timeout_seconds") or 120),
    )
    parsed = _parse_json_output(proc.stdout)
    if proc.returncode != 0 or not parsed.get("ok"):
        raise RuntimeError(proc.stderr or proc.stdout)
    return {"ok": True, "items": 0, "created": 0, "status": parsed}
