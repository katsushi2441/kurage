"""Fetch X (Twitter) post content via fxtwitter API (no auth required)."""
from __future__ import annotations
import re
import requests


def extract_tweet_id(url: str) -> str:
    """Extract numeric tweet ID from X/Twitter URL."""
    patterns = [
        r'(?:https?://)?(?:www\.)?(?:x|twitter)\.com/(?:i/web/)?[^/?#]+/status(?:es)?/(\d{15,20})',
        r'(?:https?://)?(?:www\.)?(?:x|twitter)\.com/i/status/(\d{15,20})',
    ]
    for pat in patterns:
        m = re.search(pat, url, re.IGNORECASE)
        if m:
            return m.group(1)
    # fallback: any long number
    m = re.search(r'(\d{15,20})', url)
    if m:
        return m.group(1)
    raise ValueError(f"Could not extract tweet ID from: {url}")


def fetch_tweet(url: str) -> dict:
    """Fetch tweet data from fxtwitter API.

    Returns:
        {
            "id": "...",
            "text": "...",
            "author": "@username",
            "author_name": "Display Name",
            "url": "https://x.com/...",
            "created_at": "...",
        }
    """
    tweet_id = extract_tweet_id(url)
    api_url = f"https://api.fxtwitter.com/i/status/{tweet_id}"
    resp = requests.get(api_url, timeout=15, headers={"User-Agent": "Kurage/1.0"})
    resp.raise_for_status()
    data = resp.json()

    tweet = data.get("tweet") or {}
    if not tweet:
        raise ValueError(f"No tweet data returned for ID {tweet_id}")

    author = tweet.get("author") or {}
    return {
        "id": tweet_id,
        "text": tweet.get("text") or "",
        "author": "@" + (author.get("screen_name") or ""),
        "author_name": author.get("name") or "",
        "url": url,
        "created_at": tweet.get("created_at") or "",
    }


if __name__ == "__main__":
    import sys, json
    url = sys.argv[1] if len(sys.argv) > 1 else "https://x.com/elonmusk/status/1"
    result = fetch_tweet(url)
    print(json.dumps(result, ensure_ascii=False, indent=2))
