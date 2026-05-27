"""
batch_generate.py — ustoryv の全投稿URLから Kurage 動画をバックグラウンド生成

使い方:
    python3 batch_generate.py
    python3 batch_generate.py --dry-run   # 投稿URLだけ確認
    python3 batch_generate.py --interval 120  # 生成間隔(秒)を変更
"""
from __future__ import annotations
import argparse
import re
import time
import requests
import xml.etree.ElementTree as ET

USTORY_RSS   = "https://aiknowledgecms.exbridge.jp/ustoryv.php?feed"
KURAGE_API   = "http://localhost:18200"
INTERVAL_SEC = 90   # ジョブ投入間隔（秒）


def fetch_tweet_ids() -> list[str]:
    """RSS から tweet ID 一覧を取得する。"""
    resp = requests.get(USTORY_RSS, timeout=15)
    resp.raise_for_status()
    # <link>https://...?id=XXXXXXXXX</link> を抽出
    ids = re.findall(r'\?id=(\d+)', resp.text)
    # 重複除去・順序保持
    seen = set()
    result = []
    for tid in ids:
        if tid not in seen:
            seen.add(tid)
            result.append(tid)
    return result


def already_done(tweet_url: str) -> bool:
    """同じ tweet_url の done ジョブが既にあれば True。"""
    try:
        r = requests.get(f"{KURAGE_API}/jobs?limit=200", timeout=10)
        jobs = r.json().get("jobs", [])
        for j in jobs:
            if j.get("tweet_url") == tweet_url and j.get("status") == "done":
                return True
    except Exception:
        pass
    return False


def submit(tweet_url: str) -> str | None:
    """生成ジョブを投入し job_id を返す。"""
    r = requests.post(
        f"{KURAGE_API}/generate",
        json={"tweet_url": tweet_url},
        timeout=15,
    )
    r.raise_for_status()
    return r.json().get("job_id")


def wait_done(job_id: str, timeout: int = 600) -> bool:
    """ジョブが done/error になるまで待つ。"""
    deadline = time.time() + timeout
    while time.time() < deadline:
        try:
            r = requests.get(f"{KURAGE_API}/status/{job_id}", timeout=10)
            d = r.json()
            status = d.get("status", "")
            progress = d.get("progress", 0)
            print(f"  [{job_id}] {status} {progress}%", flush=True)
            if status == "done":
                return True
            if status == "error":
                print(f"  [{job_id}] error: {d.get('error')}", flush=True)
                return False
        except Exception as e:
            print(f"  poll error: {e}", flush=True)
        time.sleep(10)
    print(f"  [{job_id}] timeout", flush=True)
    return False


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--interval", type=int, default=INTERVAL_SEC)
    args = parser.parse_args()

    print("ustoryv RSS から投稿ID取得中...", flush=True)
    tweet_ids = fetch_tweet_ids()
    print(f"  {len(tweet_ids)} 件取得", flush=True)

    tweet_urls = [f"https://x.com/i/status/{tid}" for tid in tweet_ids]

    if args.dry_run:
        for url in tweet_urls:
            done = already_done(url)
            print(f"  {'[done]' if done else '[未生成]'} {url}")
        return

    success = 0
    skip = 0
    fail = 0

    for i, url in enumerate(tweet_urls):
        print(f"\n[{i+1}/{len(tweet_urls)}] {url}", flush=True)

        if already_done(url):
            print("  → 生成済みスキップ", flush=True)
            skip += 1
            continue

        try:
            job_id = submit(url)
            print(f"  → job_id: {job_id}", flush=True)
        except Exception as e:
            print(f"  → 投入失敗: {e}", flush=True)
            fail += 1
            continue

        ok = wait_done(job_id)
        if ok:
            success += 1
        else:
            fail += 1

        # 次のジョブまで待機（GPU冷却・連続投入防止）
        if i + 1 < len(tweet_urls):
            print(f"  {args.interval}秒待機...", flush=True)
            time.sleep(args.interval)

    print(f"\n完了: 成功={success} スキップ={skip} 失敗={fail}", flush=True)


if __name__ == "__main__":
    main()
