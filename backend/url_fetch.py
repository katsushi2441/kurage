"""Fetch article title and content from a URL."""
from __future__ import annotations
from urllib.parse import urlparse

import httpx
from bs4 import BeautifulSoup

HEADERS = {
    "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
                  "(KHTML, like Gecko) Chrome/124.0 Safari/537.36",
    "Accept-Language": "ja,en;q=0.9",
}


# 出典名は台本のナレーションにそのまま乗る。ドメインの先頭ラベルを機械的に使うと、
# ホスト名がアカウント名の場合に個人名として読み上げられる
# （katsushi2441.github.io → 「Katsushi2441さん」）。自社の媒体は正式名で固定する。
SOURCE_NAMES = {
    "katsushi2441.github.io": "AI OSS技術解説",
    "kurage.exbridge.jp": "Kurage",
    "kappstore.exbridge.jp": "Kurage App Store",
    "exbridge.jp": "エクスブリッジ",
    "proto.exbridge.jp": "エクスブリッジ",
    "xb-bittensor.hatenablog.com": "AI OSS技術解説",
    "bittensorman.blogspot.com": "AI OSS技術解説",
    "xb4g.com": "エクスブリッジ",
}


def url_to_source(url: str) -> str:
    domain = urlparse(url).netloc.replace("www.", "").lower()
    if domain in SOURCE_NAMES:
        return SOURCE_NAMES[domain]
    # ホスト名がアカウント名になる媒体は、その名前を出典にしない
    # （個人名として読み上げられるため）。媒体名の側を使う。
    for suffix, label in (
        (".github.io", "GitHub Pages"),
        (".hatenablog.com", "はてなブログ"),
        (".hatenadiary.jp", "はてなブログ"),
        (".blogspot.com", "Blogger"),
        (".note.com", "note"),
        (".substack.com", "Substack"),
        (".medium.com", "Medium"),
    ):
        if domain.endswith(suffix):
            return label
    return domain.split(".")[0].capitalize()


def fetch_article(url: str) -> dict:
    """URLから記事タイトルと本文を取得する。"""
    resp = httpx.get(url, headers=HEADERS, follow_redirects=True, timeout=15)
    resp.raise_for_status()
    soup = BeautifulSoup(resp.text, "html.parser")

    # タイトル: og:title → title タグ → URL
    title = ""
    og = soup.find("meta", property="og:title")
    if og and og.get("content"):
        title = og["content"].strip()
    if not title:
        t = soup.find("title")
        if t:
            title = t.get_text(strip=True)
    if not title:
        title = url

    # 本文: article → main → body の順
    content = ""
    for selector in ["article", "main", '[role="main"]', "body"]:
        el = soup.select_one(selector)
        if el:
            # script/style を除去
            for tag in el(["script", "style", "nav", "header", "footer"]):
                tag.decompose()
            content = el.get_text(" ", strip=True)
            if len(content) > 100:
                break

    content = content[:1500]

    return {
        "title": title[:100],
        "content": content,
        "url": url,
        "source_name": url_to_source(url),
    }
