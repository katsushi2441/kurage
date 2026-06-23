"""Kurage FastAPI backend."""
from __future__ import annotations
import json
import threading
import time
import uuid
from pathlib import Path

from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import FileResponse, JSONResponse
from pydantic import BaseModel

from config import JOBS_DIR, PORT, ERNIE_URL, NVM_NODE, HYPERFRAMES_VERSION, OLLAMA_URL, OLLAMA_MODEL, WAN_API, WAN_TEST_MODE
from tts_gen import TTS_VOICE, TTS_RATE, TTS_PITCH
from pipeline import run_pipeline, run_pipeline_from_news, run_pipeline_from_blog, run_pipeline_from_entertainment_short, run_pipeline_from_script, load_job, update_job
from video_styles import STYLE_PRESETS, resolve_video_style, style_names
from typing import Any

app = FastAPI(title="Kurage API", version="1.0.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)


class GenerateRequest(BaseModel):
    tweet_url: str
    mode: str = "hyperframes"  # "hyperframes" or "wan"
    vtuber_mode: bool = False
    video_style: str = "auto"


class NewsRequest(BaseModel):
    news_items: list[Any]    # [{"title": str, "content": str, "url": str, "source_name": str}, ...]
    title: str = ""          # 動画全体タイトル（省略時はLLMが生成）
    vtuber_mode: bool = False
    video_style: str = "auto"


class UrlRequest(BaseModel):
    url: str
    vtuber_mode: bool = False
    video_style: str = "auto"


class EntertainmentShortRequest(BaseModel):
    title: str
    summary: str = ""
    content: str = ""
    url: str = ""
    source_url: str = ""
    source_name: str = "Kurage Entertainment"
    celebrity_names: list[str] = []
    vtuber_mode: bool = False
    video_style: str = "auto"


class ScriptVideoRequest(BaseModel):
    title: str = ""
    script: dict[str, Any]
    source_url: str = ""
    source_title: str = ""
    source_name: str = "Kurage Montage"
    source_platform: str = ""
    source: str = "kmontage"
    vtuber_mode: bool = False
    video_style: str = "auto"


@app.get("/health")
def health():
    return {"ok": True, "service": "kurage", "time": time.strftime("%Y-%m-%d %H:%M:%S")}


def _mask_url(url: str) -> str:
    """Replace scheme+host+port with localhost for public display."""
    import re
    return re.sub(r'https?://[^/]+', lambda m: 'http://localhost', url)


def _request_data(req: BaseModel) -> dict:
    """Return request data on both Pydantic v1 and v2."""
    if hasattr(req, "model_dump"):
        return req.model_dump()
    return req.dict()


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
        "video_styles": {
            "available": style_names(),
            "default": "auto",
            "presets": {
                name: {"label": data["label"], "best_for": data["best_for"]}
                for name, data in STYLE_PRESETS.items()
            },
        },
        "vtuber": {
            "label": "Kurage VTuber解説モード",
            "api": "PNG avatar overlay + deterministic mouth switching",
        },
        "wan": {
            "label": "Wan2.1 AI Video",
            "api": _mask_url(f"{WAN_API}/api/test/story" if WAN_TEST_MODE == "1" else f"{WAN_API}/api/story"),
            "mode": "test" if WAN_TEST_MODE == "1" else "production",
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


def _job_views(job: dict) -> int:
    try:
        return max(0, int(job.get("views", 0)))
    except Exception:
        return 0


@app.post("/generate")
def generate(req: GenerateRequest):
    """Start a new video generation job from an X URL."""
    tweet_url = req.tweet_url.strip()
    if not tweet_url:
        raise HTTPException(status_code=400, detail="tweet_url is required")
    resolved_style = resolve_video_style(req.video_style, content_type="tweet", vtuber_mode=req.vtuber_mode, title=tweet_url)

    # Resume existing failed job if available
    job_id = _find_resumable_job(tweet_url)
    if job_id:
        print(f"[{job_id}] resuming failed job", flush=True)
        update_job(job_id, status="queued", progress=0, error=None, traceback=None,
                   video_style=resolved_style,
                   created_at=time.strftime("%Y-%m-%d %H:%M:%S"))
    else:
        job_id = str(uuid.uuid4()).replace("-", "")[:16]
        JOBS_DIR.mkdir(parents=True, exist_ok=True)
        update_job(job_id, status="queued", progress=0, tweet_url=tweet_url,
                   vtuber_mode=req.vtuber_mode,
                   video_style=resolved_style,
                   created_at=time.strftime("%Y-%m-%d %H:%M:%S"))

    # Run pipeline in background thread
    mode = req.mode if req.mode in ("hyperframes", "wan") else "hyperframes"
    update_job(job_id, mode=mode, vtuber_mode=req.vtuber_mode, video_style=resolved_style)
    t = threading.Thread(target=run_pipeline, args=(job_id, tweet_url, mode, req.vtuber_mode, resolved_style), daemon=True)
    t.start()

    return {"ok": True, "job_id": job_id}


@app.post("/generate_from_news")
def generate_from_news(req: NewsRequest):
    """Start a video generation job from multiple news articles."""
    if not req.news_items:
        raise HTTPException(status_code=400, detail="news_items is required")
    job_id = str(uuid.uuid4()).replace("-", "")[:16]
    JOBS_DIR.mkdir(parents=True, exist_ok=True)
    first = req.news_items[0] if req.news_items else {}
    tweet_text = "、".join(i.get("title", "") for i in req.news_items[:3])[:120]
    resolved_style = resolve_video_style(req.video_style, content_type="news", vtuber_mode=req.vtuber_mode, title=req.title or tweet_text)
    update_job(job_id, status="queued", progress=0, source="horizon",
               vtuber_mode=req.vtuber_mode,
               video_style=resolved_style,
               tweet_url=first.get("url", ""),
               tweet_text=tweet_text,
               tweet_author="Horizon",
               tweet_author_name=req.title or tweet_text[:50],
               created_at=time.strftime("%Y-%m-%d %H:%M:%S"))
    t = threading.Thread(target=run_pipeline_from_news, args=(job_id, _request_data(req), req.vtuber_mode, resolved_style), daemon=True)
    t.start()
    return {"ok": True, "job_id": job_id}


@app.post("/generate_from_script")
def generate_from_script(req: ScriptVideoRequest):
    """Start a video generation job from a completed script JSON.

    This endpoint intentionally skips Kurage's generic news LLM step. It is for
    upstream tools that already performed faithful source analysis and need
    Kurage to render exactly that plan.
    """
    script = req.script or {}
    scenes = script.get("scenes") if isinstance(script, dict) else None
    if not isinstance(scenes, list) or not scenes:
        raise HTTPException(status_code=400, detail="script.scenes is required")
    job_id = str(uuid.uuid4()).replace("-", "")[:16]
    JOBS_DIR.mkdir(parents=True, exist_ok=True)
    source_title = req.source_title or req.title or script.get("title") or "参照動画"
    resolved_style = resolve_video_style(req.video_style, content_type="reference_script", vtuber_mode=req.vtuber_mode, title=source_title)
    data = _request_data(req)
    data["video_style"] = resolved_style
    update_job(job_id, status="queued", progress=0, source=req.source or "kmontage",
               content_type="reference_video_summary",
               vtuber_mode=req.vtuber_mode,
               video_style=resolved_style,
               tweet_url=req.source_url,
               original_url=req.source_url,
               source_title=source_title,
               source_platform=req.source_platform,
               tweet_text=" ".join(str(s.get("narration") or "") for s in scenes[:3])[:240],
               tweet_author=req.source_name or "Kurage Montage",
               tweet_author_name=source_title,
               title=script.get("title") or source_title,
               script=script,
               created_at=time.strftime("%Y-%m-%d %H:%M:%S"))
    t = threading.Thread(target=run_pipeline_from_script, args=(job_id, data, req.vtuber_mode, resolved_style), daemon=True)
    t.start()
    return {"ok": True, "job_id": job_id}


@app.post("/generate_from_url")
def generate_from_url(req: UrlRequest):
    """Start a video generation job from a blog/news article URL."""
    from url_fetch import fetch_article
    url = req.url.strip()
    if not url:
        raise HTTPException(status_code=400, detail="url is required")
    try:
        article = fetch_article(url)
    except Exception as e:
        raise HTTPException(status_code=422, detail=f"URL取得失敗: {e}")
    job_id = str(uuid.uuid4()).replace("-", "")[:16]
    JOBS_DIR.mkdir(parents=True, exist_ok=True)
    resolved_style = resolve_video_style(req.video_style, content_type="news", vtuber_mode=req.vtuber_mode, title=article["title"])
    update_job(job_id, status="queued", progress=0, source="horizon",
               vtuber_mode=req.vtuber_mode,
               video_style=resolved_style,
               tweet_url=article["url"],
               tweet_text=article["content"][:120],
               tweet_author=article["source_name"],
               tweet_author_name=article["title"],
               created_at=time.strftime("%Y-%m-%d %H:%M:%S"))
    news = {"news_items": [article], "title": article["title"]}
    t = threading.Thread(target=run_pipeline_from_news, args=(job_id, news, req.vtuber_mode, resolved_style), daemon=True)
    t.start()
    return {"ok": True, "job_id": job_id}


@app.post("/generate_from_blog_url")
def generate_from_blog_url(req: UrlRequest):
    """Start a 2-minute commentary video job from a blog article URL."""
    from url_fetch import fetch_article
    url = req.url.strip()
    if not url:
        raise HTTPException(status_code=400, detail="url is required")
    try:
        article = fetch_article(url)
    except Exception as e:
        raise HTTPException(status_code=422, detail=f"URL取得失敗: {e}")
    job_id = str(uuid.uuid4()).replace("-", "")[:16]
    JOBS_DIR.mkdir(parents=True, exist_ok=True)
    resolved_style = resolve_video_style(req.video_style, content_type="blog", vtuber_mode=req.vtuber_mode, title=article["title"])
    update_job(job_id, status="queued", progress=0, source="horizon", content_type="blog",
               vtuber_mode=req.vtuber_mode,
               video_style=resolved_style,
               tweet_url=article["url"],
               tweet_text=article["content"][:240],
               tweet_author=article["source_name"],
               tweet_author_name=article["title"],
               title=article["title"],
               created_at=time.strftime("%Y-%m-%d %H:%M:%S"))
    t = threading.Thread(target=run_pipeline_from_blog, args=(job_id, article, req.vtuber_mode, resolved_style), daemon=True)
    t.start()
    return {"ok": True, "job_id": job_id}


@app.post("/generate_entertainment_short")
def generate_entertainment_short(req: EntertainmentShortRequest):
    """Start a 30-second Kurage short video job from an entertainment article."""
    title = req.title.strip()
    if not title:
        raise HTTPException(status_code=400, detail="title is required")
    article = _request_data(req)
    job_id = str(uuid.uuid4()).replace("-", "")[:16]
    JOBS_DIR.mkdir(parents=True, exist_ok=True)
    resolved_style = resolve_video_style(req.video_style, content_type="entertainment_short", vtuber_mode=req.vtuber_mode, title=title)
    update_job(job_id, status="queued", progress=0, source="entertainment",
               content_type="entertainment_short",
               vtuber_mode=req.vtuber_mode,
               video_style=resolved_style,
               tweet_url=article.get("url", ""),
               article_url=article.get("url", ""),
               source_url=article.get("source_url", ""),
               tweet_text=(article.get("summary") or article.get("content") or "")[:240],
               tweet_author=article.get("source_name") or "Kurage Entertainment",
               tweet_author_name=title,
               title=title,
               created_at=time.strftime("%Y-%m-%d %H:%M:%S"))
    t = threading.Thread(target=run_pipeline_from_entertainment_short, args=(job_id, article, req.vtuber_mode, resolved_style), daemon=True)
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
        "display_title": job.get("display_title"),
        "summary_title": job.get("summary_title"),
        "article_title": job.get("article_title"),
        "tweet_url": job.get("tweet_url"),
        "tweet_text": job.get("tweet_text"),
        "tweet_author": job.get("tweet_author"),
        "tweet_author_name": job.get("tweet_author_name"),
        "summary": job.get("summary"),
        "display_summary": job.get("display_summary"),
        "article_url": job.get("article_url"),
        "related_article_url": job.get("related_article_url"),
        "source_url": job.get("source_url"),
        "original_url": job.get("original_url"),
        "source_title": job.get("source_title"),
        "source_platform": job.get("source_platform"),
        "content_type": job.get("content_type"),
        "source": job.get("source"),
        "vtuber_mode": bool(job.get("vtuber_mode")),
        "video_style": job.get("video_style"),
        "translated_text": job.get("translated_text"),
        "kuragevp_job_id": job.get("kuragevp_job_id"),
        "image_count": job.get("image_count"),
        "created_at": job.get("created_at"),
        "updated_at": job.get("updated_at"),
        "views": _job_views(job),
        "script": job.get("script"),
    }

    if job.get("status") == "done":
        resp["video_url"] = f"/video/{job_id}"
        if (JOBS_DIR / job_id / "thumbnail.jpg").exists():
            resp["thumbnail_url"] = f"/thumbnail/{job_id}"

    if job.get("status") == "error":
        resp["error"] = job.get("error")

    return resp


@app.post("/view/{job_id}")
def record_view(job_id: str):
    """Increment a public detail-page view count."""
    path = JOBS_DIR / f"{job_id}.json"
    job = load_job(job_id)
    if job is None:
        raise HTTPException(status_code=404, detail="Job not found")
    job["views"] = _job_views(job) + 1
    job["viewed_at"] = time.strftime("%Y-%m-%d %H:%M:%S")
    tmp = path.with_suffix(".json.tmp")
    tmp.write_text(json.dumps(job, ensure_ascii=False, indent=2), encoding="utf-8")
    tmp.replace(path)
    return {"ok": True, "job_id": job_id, "views": job["views"]}


@app.delete("/jobs/{job_id}")
def delete_job(job_id: str):
    """Delete a job and its associated files."""
    import shutil
    job = load_job(job_id)
    if job is None:
        raise HTTPException(status_code=404, detail="Job not found")
    job_dir = JOBS_DIR / job_id
    if job_dir.exists():
        shutil.rmtree(job_dir)
    job_path = JOBS_DIR / f"{job_id}.json"
    if job_path.exists():
        job_path.unlink()
    return {"ok": True, "job_id": job_id}


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


@app.get("/thumbnail/{job_id}")
def thumbnail(job_id: str):
    """Return the generated poster thumbnail."""
    job = load_job(job_id)
    if job is None:
        raise HTTPException(status_code=404, detail="Job not found")

    path = JOBS_DIR / job_id / "thumbnail.jpg"
    if not path.exists():
        raise HTTPException(status_code=404, detail="Thumbnail not found")

    return FileResponse(
        path=str(path),
        media_type="image/jpeg",
        filename=f"kurage_{job_id}_thumbnail.jpg",
    )


@app.get("/jobs")
def list_jobs(limit: int = 20, source: str | None = None):
    """List jobs. ?source=horizon filters by source. limit<=0 returns all."""
    JOBS_DIR.mkdir(parents=True, exist_ok=True)
    files = list(JOBS_DIR.glob("*.json"))
    jobs = []
    for f in files:
        try:
            import json
            d = json.loads(f.read_text(encoding="utf-8"))
            job_source = d.get("source") or "tweet"
            if source and job_source != source:
                continue
            tweet_text_full = d.get("tweet_text") or ""
            jobs.append({
                "job_id": f.stem,
                "status": d.get("status"),
                "title": d.get("title"),
                "display_title": d.get("display_title"),
                "summary_title": d.get("summary_title"),
                "article_title": d.get("article_title"),
                "tweet_url": d.get("tweet_url"),
                "tweet_text": tweet_text_full[:120] if tweet_text_full else "",
                "tweet_author": d.get("tweet_author"),
                "tweet_author_name": d.get("tweet_author_name"),
                "summary": d.get("summary"),
                "display_summary": d.get("display_summary"),
                "article_url": d.get("article_url"),
                "related_article_url": d.get("related_article_url"),
                "source_url": d.get("source_url"),
                "original_url": d.get("original_url"),
                "source_title": d.get("source_title"),
                "source_platform": d.get("source_platform"),
                "content_type": d.get("content_type"),
                "kuragevp_job_id": d.get("kuragevp_job_id"),
                "source": job_source,
                "vtuber_mode": bool(d.get("vtuber_mode")),
                "video_style": d.get("video_style"),
                "created_at": d.get("created_at"),
                "updated_at": d.get("updated_at"),
                "views": _job_views(d),
                "has_video": d.get("status") == "done",
                "has_thumbnail": (JOBS_DIR / f.stem / "thumbnail.jpg").exists(),
            })
        except Exception:
            pass
    jobs.sort(key=lambda j: j.get("created_at") or "", reverse=True)
    if limit and limit > 0:
        jobs = jobs[:limit]
    return {"ok": True, "jobs": jobs, "count": len(jobs)}


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=PORT)
