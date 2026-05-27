"""Kurage FastAPI backend — port 8025."""
from __future__ import annotations
import threading
import time
import uuid
from pathlib import Path

from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import FileResponse, JSONResponse
from pydantic import BaseModel

from config import JOBS_DIR, PORT, ERNIE_URL, NVM_NODE, HYPERFRAMES_VERSION, OLLAMA_URL, OLLAMA_MODEL
from tts_gen import TTS_VOICE, TTS_RATE, TTS_PITCH
from pipeline import run_pipeline, load_job, update_job

app = FastAPI(title="Kurage API", version="1.0.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)


class GenerateRequest(BaseModel):
    tweet_url: str


@app.get("/health")
def health():
    return {"ok": True, "service": "kurage", "time": time.strftime("%Y-%m-%d %H:%M:%S")}


def _mask_url(url: str) -> str:
    """Replace scheme+host+port with localhost for public display."""
    import re
    return re.sub(r'https?://[^/]+', lambda m: 'http://localhost', url)


@app.get("/config")
def config():
    """Return current service configuration (domains masked)."""
    return {
        "script": {
            "label": f"Ollama ({OLLAMA_MODEL})",
            "api": _mask_url(f"{OLLAMA_URL}/api/generate"),
        },
        "image": {
            "label": "ERNIE-Image-Turbo",
            "api": _mask_url(ERNIE_URL),
        },
        "tts": {
            "label": f"edge-tts ({TTS_VOICE})",
            "api": f"rate={TTS_RATE} pitch={TTS_PITCH}",
        },
        "video": {
            "label": f"HyperFrames v{HYPERFRAMES_VERSION}",
            "api": "CLI (npx hyperframes render)",
        },
    }


def _find_resumable_job(tweet_url: str) -> str | None:
    """Find an existing failed job for the same tweet_url that can be resumed."""
    import json
    JOBS_DIR.mkdir(parents=True, exist_ok=True)
    files = sorted(JOBS_DIR.glob("*.json"), key=lambda p: p.stat().st_mtime, reverse=True)
    for f in files[:30]:
        try:
            d = json.loads(f.read_text(encoding="utf-8"))
            if d.get("tweet_url") == tweet_url and d.get("status") == "error":
                return f.stem
        except Exception:
            pass
    return None


@app.post("/generate")
def generate(req: GenerateRequest):
    """Start a new video generation job from an X URL."""
    tweet_url = req.tweet_url.strip()
    if not tweet_url:
        raise HTTPException(status_code=400, detail="tweet_url is required")

    # Resume existing failed job if available
    job_id = _find_resumable_job(tweet_url)
    if job_id:
        print(f"[{job_id}] resuming failed job", flush=True)
        update_job(job_id, status="queued", progress=0, error=None, traceback=None,
                   created_at=time.strftime("%Y-%m-%d %H:%M:%S"))
    else:
        job_id = str(uuid.uuid4()).replace("-", "")[:16]
        JOBS_DIR.mkdir(parents=True, exist_ok=True)
        update_job(job_id, status="queued", progress=0, tweet_url=tweet_url,
                   created_at=time.strftime("%Y-%m-%d %H:%M:%S"))

    # Run pipeline in background thread
    t = threading.Thread(target=run_pipeline, args=(job_id, tweet_url), daemon=True)
    t.start()

    return {"ok": True, "job_id": job_id}


@app.get("/status/{job_id}")
def status(job_id: str):
    """Get job status and progress."""
    job = load_job(job_id)
    if job is None:
        raise HTTPException(status_code=404, detail="Job not found")

    resp = {
        "job_id": job_id,
        "status": job.get("status") or "unknown",
        "progress": job.get("progress") or 0,
        "title": job.get("title"),
        "tweet_url": job.get("tweet_url"),
        "tweet_text": job.get("tweet_text"),
        "tweet_author": job.get("tweet_author"),
        "image_count": job.get("image_count"),
        "created_at": job.get("created_at"),
        "updated_at": job.get("updated_at"),
        "script": job.get("script"),
    }

    if job.get("status") == "done":
        resp["video_url"] = f"/video/{job_id}"

    if job.get("status") == "error":
        resp["error"] = job.get("error")

    return resp


@app.get("/video/{job_id}")
def video(job_id: str):
    """Download the generated MP4 video."""
    job = load_job(job_id)
    if job is None:
        raise HTTPException(status_code=404, detail="Job not found")
    if job.get("status") != "done":
        raise HTTPException(status_code=400, detail=f"Job not done (status={job.get('status')})")

    video_file = job.get("video_file") or ""
    path = Path(video_file)
    if not path.exists():
        raise HTTPException(status_code=404, detail="Video file not found")

    return FileResponse(
        path=str(path),
        media_type="video/mp4",
        filename=f"kurage_{job_id}.mp4",
    )


@app.get("/jobs")
def list_jobs(limit: int = 20):
    """List recent jobs."""
    JOBS_DIR.mkdir(parents=True, exist_ok=True)
    files = list(JOBS_DIR.glob("*.json"))
    jobs = []
    for f in files:
        try:
            import json
            d = json.loads(f.read_text(encoding="utf-8"))
            tweet_text_full = d.get("tweet_text") or ""
            jobs.append({
                "job_id": f.stem,
                "status": d.get("status"),
                "title": d.get("title"),
                "tweet_url": d.get("tweet_url"),
                "tweet_text": tweet_text_full[:120] if tweet_text_full else "",
                "tweet_author": d.get("tweet_author"),
                "created_at": d.get("created_at"),
                "has_video": d.get("status") == "done",
            })
        except Exception:
            pass
    # created_at 降順（新しい順）
    jobs.sort(key=lambda j: j.get("created_at") or "", reverse=True)
    return {"ok": True, "jobs": jobs[:limit]}


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=PORT)
