#!/usr/bin/env python3
"""考察ショートの場面の画像を、ブログ記事の見出しごとに切り抜く（1060×858・題名だけは1060×236）。

  /usr/bin/python3 kurage/kosatsu/shot_sections.py <記事のURL> <slug> 見出しの文字の一部 [見出しの文字の一部 ...]
  → kurage/kosatsu/assets/<slug>/s1.png, s2.png …（引数の順）と title.png

11/26 のカブアンドの考察ショートと同じ見た目（スマホ幅 530px・2倍で撮る）。
見出し（h2）の上端から 429px（2倍で 858px）を切り抜く。
"""
import sys
from pathlib import Path
from playwright.sync_api import sync_playwright

url, slug, keys = sys.argv[1], sys.argv[2], sys.argv[3:]
out = Path("/home/kojima/work/kurage/kosatsu/assets") / slug
out.mkdir(parents=True, exist_ok=True)
with sync_playwright() as p:
    b = p.chromium.launch()
    pg = b.new_page(viewport={"width": 530, "height": 900}, device_scale_factor=2)
    pg.goto(url, wait_until="networkidle")
    # 右下に固定の相談ボタンなど、場面に要らないものは隠す
    pg.add_style_tag(content="#kai-fab,.kai-fab{display:none!important}")
    h1 = pg.query_selector("h1")
    bb = h1.bounding_box()
    pg.screenshot(path=str(out / "title.png"), clip={"x": 0, "y": bb["y"] - 8, "width": 530, "height": 118})
    for i, k in enumerate(keys, 1):
        h = pg.locator("article h2", has_text=k).first
        h.scroll_into_view_if_needed()
        bb = h.bounding_box()
        y = pg.evaluate("window.scrollY") + bb["y"] - 6
        pg.screenshot(path=str(out / f"s{i}.png"), full_page=True, clip={"x": 0, "y": y, "width": 530, "height": 429})
        print(f"s{i}.png ← {k}")
    b.close()
print(out)
