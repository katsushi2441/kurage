"""Core pipeline: tweet URL → script → images → video."""
from __future__ import annotations
import json
import re
import time
import traceback
from pathlib import Path

from config import JOBS_DIR
from tweet_fetch import fetch_tweet
from script_gen import generate_script, generate_news_script, generate_blog_script, generate_entertainment_short_script
from image_gen import generate_scene_images, generate_image
from video_gen import generate_video, generate_thumbnail
from video_styles import apply_video_style, resolve_video_style
from static_media import publish_static_media
import wan_gen


def job_path(job_id: str) -> Path:
    return JOBS_DIR / f"{job_id}.json"


def load_job(job_id: str) -> dict | None:
    p = job_path(job_id)
    if not p.exists():
        return None
    return json.loads(p.read_text(encoding="utf-8"))


def update_job(job_id: str, **kwargs):
    JOBS_DIR.mkdir(parents=True, exist_ok=True)
    p = job_path(job_id)
    data = {}
    if p.exists():
        data = json.loads(p.read_text(encoding="utf-8"))
    data.update(kwargs)
    data["updated_at"] = time.strftime("%Y-%m-%d %H:%M:%S")
    p.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")


def mark_job_done(job_id: str, video_path: Path, thumb_path: Path):
    """Mark a job complete and best-effort publish SEO-friendly static media."""
    update_job(job_id, status="done", progress=100, video_file=str(video_path),
               thumbnail_file=str(thumb_path) if thumb_path.exists() else "")
    try:
        result = publish_static_media(job_id)
        if result.get("ok"):
            update_job(job_id, static_media_status="synced")
        else:
            update_job(job_id, static_media_status="error", static_media_error=str(result.get("error") or result))
            print(f"[{job_id}] static media sync skipped/failed: {result}", flush=True)
    except Exception as exc:
        update_job(job_id, static_media_status="error", static_media_error=str(exc))
        print(f"[{job_id}] static media sync failed: {exc}", flush=True)


def script_summary_text(script: dict, limit: int = 220) -> str:
    """Build a Japanese description from generated narration scenes."""
    scenes = script.get("scenes") if isinstance(script, dict) else []
    lines: list[str] = []
    if isinstance(scenes, list):
        for scene in scenes:
            if not isinstance(scene, dict):
                continue
            narration = str(scene.get("narration") or "").strip()
            if narration:
                lines.append(narration)
    text = " ".join(lines)
    text = " ".join(text.split())
    if len(text) <= limit:
        return text
    return text[:limit].rstrip() + "…"


def normalize_provided_script(script: dict, video_style: str = "auto", scene_duration: int = 10) -> dict:
    """Validate a caller-provided script without asking the LLM to rewrite it.

    This is used by tools such as kmontage where the upstream analysis already
    produced a faithful reference-video script. Kurage should render it, not
    dilute it through the generic news-script generator.
    """
    if not isinstance(script, dict):
        raise ValueError("script must be a JSON object")
    scenes = script.get("scenes")
    if not isinstance(scenes, list) or not scenes:
        raise ValueError("script.scenes is required")
    out = {
        "title": str(script.get("title") or "Kurage動画").strip()[:80],
        "scenes": [],
    }

    def _split_long_narration(text: str, max_chars: int = 75) -> list[str]:
        """Keep externally supplied scripts watchable by avoiding one giant scene."""
        text = str(text or "").strip()
        if len(text) <= max_chars:
            return [text] if text else []

        # Prefer semantic breaks first, then fall back to fixed-size chunks.
        parts = [
            " ".join(p.split())
            for p in re.split(r"(?<=[。！？!?])\s*|[\n\r]+", text)
            if p.strip()
        ]
        if len(parts) <= 1:
            parts = [p.strip() for p in re.split(r"\s+", text) if p.strip()]

        chunks: list[str] = []
        current = ""
        for part in parts:
            if not current:
                current = part
            elif len(current) + len(part) + 1 <= max_chars:
                current = f"{current} {part}"
            else:
                chunks.append(current)
                current = part

            while len(current) > max_chars:
                chunks.append(current[:max_chars].rstrip())
                current = current[max_chars:].lstrip()
        if current:
            chunks.append(current)
        return chunks

    for i, scene in enumerate(scenes):
        if not isinstance(scene, dict):
            continue
        narration = str(scene.get("narration") or "").strip()
        if not narration:
            continue
        prompt = str(scene.get("image_prompt") or "clean vertical explainer visual, 9:16").strip()
        for chunk in _split_long_narration(narration):
            out["scenes"].append({
                "index": len(out["scenes"]),
                "narration": chunk,
                "image_prompt": prompt,
                "duration": int(scene.get("duration") or scene_duration),
            })
    if not out["scenes"]:
        raise ValueError("script.scenes has no usable narration")
    return apply_video_style(out, video_style)


def run_pipeline_from_script(job_id: str, request: dict, vtuber_mode: bool = False, video_style: str = "auto"):
    """Render a caller-provided script directly.

    Unlike run_pipeline_from_news, this path does not call Ollama for script
    generation. It preserves concrete facts and workflow details extracted by
    upstream reference-analysis tools.
    """
    job_dir = JOBS_DIR / job_id
    job_dir.mkdir(parents=True, exist_ok=True)

    try:
        source_url = request.get("source_url") or request.get("url") or ""
        source_title = request.get("source_title") or request.get("title") or "参照動画"
        resolved_style = resolve_video_style(video_style, content_type="reference_script", vtuber_mode=vtuber_mode, title=source_title)
        script = normalize_provided_script(request.get("script") or {}, resolved_style)
        summary = script_summary_text(script)

        update_job(job_id, status="imaging", progress=35, source=request.get("source") or "kmontage",
                   content_type="reference_video_summary",
                   vtuber_mode=vtuber_mode,
                   video_style=resolved_style,
                   tweet_url=source_url,
                   original_url=source_url,
                   source_title=source_title,
                   source_platform=request.get("source_platform") or "",
                   tweet_text=summary,
                   tweet_author=request.get("source_name") or "Kurage Montage",
                   tweet_author_name=source_title,
                   script=script,
                   title=script.get("title"),
                   display_title=script.get("title"),
                   summary_title=script.get("title"),
                   summary=summary,
                   display_summary=summary)
        print(f"[{job_id}] provided script: {script.get('title')} ({len(script.get('scenes', []))} scenes)", flush=True)

        scenes = script.get("scenes") or []
        assets_dir = job_dir / "assets"
        assets_dir.mkdir(parents=True, exist_ok=True)

        image_paths = []
        for scene in scenes:
            idx = scene.get("index", len(image_paths))
            out = assets_dir / f"scene_{idx:02d}.png"
            prompt = scene.get("image_prompt", "clean vertical explainer visual, 9:16")
            print(f"  [script image] scene {idx}: {prompt[:60]}...", flush=True)
            if idx > 0:
                time.sleep(3)
            path = generate_image(prompt, out)
            image_paths.append(path)
        update_job(job_id, image_count=len(image_paths))

        update_job(job_id, status="rendering", progress=75)
        video_path = generate_video(script, image_paths, job_dir, vtuber_mode=vtuber_mode)
        thumb_path = job_dir / "thumbnail.jpg"
        mark_job_done(job_id, video_path, thumb_path)
        print(f"[{job_id}] script done: {video_path}", flush=True)

    except Exception as exc:
        tb = traceback.format_exc()
        print(f"[{job_id}] ERROR: {exc}\n{tb}", flush=True)
        update_job(job_id, status="error", error=str(exc), traceback=tb)


def run_pipeline_from_news(job_id: str, news: dict, vtuber_mode: bool = False, video_style: str = "auto"):
    """Run pipeline from multiple news articles (skip tweet fetch)."""
    job_dir = JOBS_DIR / job_id
    job_dir.mkdir(parents=True, exist_ok=True)

    try:
        news_items = news.get("news_items") or []
        first = news_items[0] if news_items else {}
        tweet_text = "、".join(i.get("title", "") for i in news_items[:3])[:120]
        resolved_style = resolve_video_style(video_style, content_type="news", vtuber_mode=vtuber_mode, title=news.get("title", tweet_text[:50]))

        update_job(job_id, status="scripting", progress=25, source="horizon",
                   vtuber_mode=vtuber_mode,
                   video_style=resolved_style,
                   tweet_url=first.get("url", ""),
                   tweet_text=tweet_text,
                   tweet_author="Horizon",
                   tweet_author_name=news.get("title", tweet_text[:50]))
        print(f"[{job_id}] news: {len(news_items)}件 {tweet_text[:60]}", flush=True)

        NEWS_EXPECTED_SCENES = 12
        script = generate_news_script(news_items, video_style=resolved_style)
        summary = script_summary_text(script)
        update_job(
            job_id,
            script=script,
            title=script.get("title"),
            display_title=script.get("title"),
            summary_title=script.get("title"),
            tweet_text=summary,
            summary=summary,
            display_summary=summary,
        )
        print(f"[{job_id}] script: {script.get('title')} ({len(script.get('scenes', []))} scenes)", flush=True)

        scenes = script.get("scenes") or []
        assets_dir = job_dir / "assets"
        assets_dir.mkdir(parents=True, exist_ok=True)

        update_job(job_id, status="imaging", progress=40)
        image_paths = []
        for scene in scenes:
            idx = scene.get("index", len(image_paths))
            out = assets_dir / f"scene_{idx:02d}.png"
            prompt = scene.get("image_prompt", "cinematic vertical shot, news broadcast style")
            print(f"  [image] scene {idx}: {prompt[:60]}...", flush=True)
            if idx > 0:
                time.sleep(3)
            path = generate_image(prompt, out)
            image_paths.append(path)
        update_job(job_id, image_count=len(image_paths))

        update_job(job_id, status="rendering", progress=75)
        video_path = generate_video(script, image_paths, job_dir, vtuber_mode=vtuber_mode)
        thumb_path = job_dir / "thumbnail.jpg"
        mark_job_done(job_id, video_path, thumb_path)
        print(f"[{job_id}] done: {video_path}", flush=True)

    except Exception as exc:
        tb = traceback.format_exc()
        print(f"[{job_id}] ERROR: {exc}\n{tb}", flush=True)
        update_job(job_id, status="error", error=str(exc), traceback=tb)


def run_pipeline_from_blog(job_id: str, article: dict, vtuber_mode: bool = False, video_style: str = "auto"):
    """Run 2-minute commentary video pipeline from one blog article."""
    job_dir = JOBS_DIR / job_id
    job_dir.mkdir(parents=True, exist_ok=True)

    try:
        article_title = article.get("title") or "ブログ考察動画"
        article_url = article.get("url") or ""
        article_text = article.get("content") or ""
        resolved_style = resolve_video_style(video_style, content_type="blog", vtuber_mode=vtuber_mode, title=article_title)

        update_job(job_id, status="scripting", progress=25, source="blog",
                   vtuber_mode=vtuber_mode,
                   video_style=resolved_style,
                   tweet_url=article_url,
                   tweet_text=article_text[:240],
                   tweet_author=article.get("source_name") or "Blog",
                   tweet_author_name=article_title)
        print(f"[{job_id}] blog: {article_title[:80]}", flush=True)

        script = generate_blog_script(article, video_style=resolved_style, vtuber_mode=vtuber_mode)
        update_job(job_id, script=script, title=script.get("title") or article_title)
        print(f"[{job_id}] blog script: {script.get('title')} ({len(script.get('scenes', []))} scenes)", flush=True)

        scenes = script.get("scenes") or []
        assets_dir = job_dir / "assets"
        assets_dir.mkdir(parents=True, exist_ok=True)

        update_job(job_id, status="imaging", progress=40)
        image_paths = []
        for scene in scenes:
            idx = scene.get("index", len(image_paths))
            out = assets_dir / f"scene_{idx:02d}.png"
            prompt = scene.get("image_prompt", "cinematic vertical 9:16 blog commentary scene")
            print(f"  [blog image] scene {idx}: {prompt[:60]}...", flush=True)
            if idx > 0:
                time.sleep(3)
            path = generate_image(prompt, out)
            image_paths.append(path)
        update_job(job_id, image_count=len(image_paths))

        update_job(job_id, status="rendering", progress=75)
        video_path = generate_video(script, image_paths, job_dir, vtuber_mode=vtuber_mode)
        thumb_path = job_dir / "thumbnail.jpg"
        mark_job_done(job_id, video_path, thumb_path)
        print(f"[{job_id}] blog done: {video_path}", flush=True)

    except Exception as exc:
        tb = traceback.format_exc()
        print(f"[{job_id}] ERROR: {exc}\n{tb}", flush=True)
        update_job(job_id, status="error", error=str(exc), traceback=tb)


def run_pipeline_from_entertainment_short(job_id: str, article: dict, vtuber_mode: bool = False, video_style: str = "auto"):
    """Run a 30-second safe entertainment-news short video pipeline."""
    job_dir = JOBS_DIR / job_id
    job_dir.mkdir(parents=True, exist_ok=True)

    try:
        article_title = article.get("title") or "芸能ニュース考察"
        article_url = article.get("url") or ""
        article_text = article.get("summary") or article.get("content") or ""
        resolved_style = resolve_video_style(video_style, content_type="entertainment_short", vtuber_mode=vtuber_mode, title=article_title)

        update_job(job_id, status="scripting", progress=25, source="entertainment",
                   content_type="entertainment_short",
                   vtuber_mode=vtuber_mode,
                   video_style=resolved_style,
                   tweet_url=article_url,
                   article_url=article_url,
                   source_url=article.get("source_url") or "",
                   tweet_text=article_text[:240],
                   tweet_author=article.get("source_name") or "Kurage Entertainment",
                   tweet_author_name=article_title)
        print(f"[{job_id}] entertainment short: {article_title[:80]}", flush=True)

        script = generate_entertainment_short_script(article, video_style=resolved_style, vtuber_mode=vtuber_mode)
        update_job(job_id, script=script, title=script.get("title") or article_title)
        print(f"[{job_id}] entertainment script: {script.get('title')} ({len(script.get('scenes', []))} scenes)", flush=True)

        scenes = script.get("scenes") or []
        assets_dir = job_dir / "assets"
        assets_dir.mkdir(parents=True, exist_ok=True)

        update_job(job_id, status="imaging", progress=40)
        image_paths = []
        for scene in scenes:
            idx = scene.get("index", len(image_paths))
            out = assets_dir / f"scene_{idx:02d}.png"
            prompt = scene.get("image_prompt", "abstract entertainment news vertical 9:16")
            print(f"  [entertainment image] scene {idx}: {prompt[:60]}...", flush=True)
            if idx > 0:
                time.sleep(3)
            path = generate_image(prompt, out)
            image_paths.append(path)
        update_job(job_id, image_count=len(image_paths))

        update_job(job_id, status="rendering", progress=75)
        video_path = generate_video(script, image_paths, job_dir, vtuber_mode=vtuber_mode)
        thumb_path = job_dir / "thumbnail.jpg"
        mark_job_done(job_id, video_path, thumb_path)
        print(f"[{job_id}] entertainment done: {video_path}", flush=True)

    except Exception as exc:
        tb = traceback.format_exc()
        print(f"[{job_id}] ERROR: {exc}\n{tb}", flush=True)
        update_job(job_id, status="error", error=str(exc), traceback=tb)


def run_pipeline(job_id: str, tweet_url: str, mode: str = "hyperframes", vtuber_mode: bool = False, video_style: str = "auto"):
    """Run the full pipeline. Resumes from last successful step if data exists."""
    job_dir = JOBS_DIR / job_id
    job_dir.mkdir(parents=True, exist_ok=True)

    try:
        saved = load_job(job_id) or {}
        resolved_style = resolve_video_style(video_style or saved.get("video_style"), content_type="tweet", vtuber_mode=vtuber_mode, title=saved.get("tweet_text", ""))
        update_job(job_id, video_style=resolved_style)

        # Step 1: Fetch tweet（保存済みならスキップ）
        if saved.get("tweet_text"):
            tweet = {
                "text": saved["tweet_text"],
                "author": saved.get("tweet_author", ""),
                "author_name": saved.get("tweet_author_name", saved.get("tweet_author", "")),
            }
            print(f"[{job_id}] tweet: reusing cached data", flush=True)
        else:
            print(f"[{job_id}] fetching tweet: {tweet_url}", flush=True)
            update_job(job_id, status="fetching", progress=10, tweet_url=tweet_url)
            tweet = fetch_tweet(tweet_url)
            update_job(job_id, tweet_text=tweet["text"], tweet_author=tweet["author"],
                       tweet_author_name=tweet.get("author_name", ""))
            print(f"[{job_id}] tweet: {tweet['text'][:80]}", flush=True)

        # Step 2: Generate script（保存済みかつシーン数が一致すればスキップ）
        EXPECTED_SCENES = 8
        cached_script = saved.get("script")
        cached_scenes_count = len(cached_script.get("scenes", [])) if cached_script else 0
        if cached_script and cached_scenes_count == EXPECTED_SCENES:
            script = cached_script
            print(f"[{job_id}] script: reusing cached '{script.get('title')}' ({cached_scenes_count} scenes)", flush=True)
        else:
            if cached_script:
                print(f"[{job_id}] script: cached has {cached_scenes_count} scenes (expected {EXPECTED_SCENES}), regenerating", flush=True)
            print(f"[{job_id}] generating script...", flush=True)
            update_job(job_id, status="scripting", progress=25)
            script = generate_script(tweet, video_style=resolved_style)
            update_job(job_id, script=script, title=script.get("title"))
            print(f"[{job_id}] script: {script.get('title')} ({len(script.get('scenes', []))} scenes)", flush=True)
            # スクリプトが変わったので画像キャッシュをクリア
            import shutil as _shutil
            assets_dir_old = job_dir / "assets"
            if assets_dir_old.exists():
                _shutil.rmtree(assets_dir_old)
                print(f"[{job_id}] cleared old image cache", flush=True)

        scenes = script.get("scenes") or []

        if mode == "wan":
            # Step 3 (Wan): TTS narration
            from tts_gen import generate_scene_narration_audio
            print(f"[{job_id}] generating TTS narration...", flush=True)
            update_job(job_id, status="imaging", progress=35)
            narration_path = job_dir / "narration.mp3"
            generate_scene_narration_audio(scenes, job_dir)

            # Step 4 (Wan): Wan2.1 AI video generation
            print(f"[{job_id}] generating Wan2.1 videos ({len(scenes)} scenes)...", flush=True)
            update_job(job_id, status="imaging", progress=40)
            video_urls = wan_gen.generate_wan_videos(scenes)
            update_job(job_id, image_count=len(video_urls), wan_video_urls=video_urls)
            print(f"[{job_id}] wan videos: {len(video_urls)} urls", flush=True)

            # Step 5 (Wan): ffmpeg concat + audio
            print(f"[{job_id}] ffmpeg concat...", flush=True)
            update_job(job_id, status="rendering", progress=75)
            output_path = job_dir / "output.mp4"
            wan_gen.concat_with_audio(video_urls, narration_path, output_path, script)

            thumb_path = job_dir / "thumbnail.jpg"
            try:
                generate_thumbnail(output_path, thumb_path, title=script.get("title"))
            except Exception as exc:
                print(f"[{job_id}] thumbnail skipped: {exc}", flush=True)
            mark_job_done(job_id, output_path, thumb_path)
            print(f"[{job_id}] done (wan): {output_path}", flush=True)

        else:
            # Step 3: Generate images（シーンごとに既存ファイルがあればスキップ）
            assets_dir = job_dir / "assets"
            assets_dir.mkdir(parents=True, exist_ok=True)

            image_paths = []
            needs_new_image = False
            for scene in scenes:
                idx = scene.get("index", len(image_paths))
                out = assets_dir / f"scene_{idx:02d}.png"
                if out.exists() and out.stat().st_size > 0:
                    image_paths.append(out)
                else:
                    needs_new_image = True
                    break

            if not needs_new_image and len(image_paths) == len(scenes):
                print(f"[{job_id}] images: reusing {len(image_paths)} cached files", flush=True)
            else:
                print(f"[{job_id}] generating images ({len(image_paths)}/{len(scenes)} cached)...", flush=True)
                update_job(job_id, status="imaging", progress=40)
                image_paths = []
                for scene in scenes:
                    idx = scene.get("index", len(image_paths))
                    out = assets_dir / f"scene_{idx:02d}.png"
                    if out.exists() and out.stat().st_size > 0:
                        print(f"  [image] scene {idx}: reusing cached", flush=True)
                        image_paths.append(out)
                    else:
                        prompt = scene.get("image_prompt", "cinematic vertical shot, beautiful scene")
                        print(f"  [image] scene {idx}: {prompt[:60]}...", flush=True)
                        if idx > 0:
                            time.sleep(3)
                        path = generate_image(prompt, out)
                        image_paths.append(path)
                update_job(job_id, image_count=len(image_paths))
                print(f"[{job_id}] images: {len(image_paths)} files", flush=True)

            # Step 4: Render video
            print(f"[{job_id}] rendering video...", flush=True)
            update_job(job_id, status="rendering", progress=75)
            video_path = generate_video(script, image_paths, job_dir, vtuber_mode=vtuber_mode)
            thumb_path = job_dir / "thumbnail.jpg"

            mark_job_done(job_id, video_path, thumb_path)
            print(f"[{job_id}] done: {video_path}", flush=True)

    except Exception as exc:
        tb = traceback.format_exc()
        print(f"[{job_id}] ERROR: {exc}\n{tb}", flush=True)
        update_job(job_id, status="error", error=str(exc), traceback=tb)
