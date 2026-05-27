"""Core pipeline: tweet URL → script → images → video."""
from __future__ import annotations
import json
import time
import traceback
from pathlib import Path

from config import JOBS_DIR
from tweet_fetch import fetch_tweet
from script_gen import generate_script
from image_gen import generate_scene_images, generate_image
from video_gen import generate_video


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


def run_pipeline(job_id: str, tweet_url: str):
    """Run the full pipeline. Resumes from last successful step if data exists."""
    job_dir = JOBS_DIR / job_id
    job_dir.mkdir(parents=True, exist_ok=True)

    try:
        saved = load_job(job_id) or {}

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
        EXPECTED_SCENES = 6
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
            script = generate_script(tweet)
            update_job(job_id, script=script, title=script.get("title"))
            print(f"[{job_id}] script: {script.get('title')} ({len(script.get('scenes', []))} scenes)", flush=True)
            # スクリプトが変わったので画像キャッシュをクリア
            import shutil as _shutil
            assets_dir_old = job_dir / "assets"
            if assets_dir_old.exists():
                _shutil.rmtree(assets_dir_old)
                print(f"[{job_id}] cleared old image cache", flush=True)

        # Step 3: Generate images（シーンごとに既存ファイルがあればスキップ）
        scenes = script.get("scenes") or []
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
        video_path = generate_video(script, image_paths, job_dir)

        # Done
        update_job(job_id, status="done", progress=100, video_file=str(video_path))
        print(f"[{job_id}] done: {video_path}", flush=True)

    except Exception as exc:
        tb = traceback.format_exc()
        print(f"[{job_id}] ERROR: {exc}\n{tb}", flush=True)
        update_job(job_id, status="error", error=str(exc), traceback=tb)
