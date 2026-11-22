#!/usr/bin/env python3
"""Kurage 考察ショート（2026-11-22 ユーザー指示「派手な見出し、強いテロップが今までの Kurage に足りない」）。

ブログの考察を 30〜60 秒の縦型ショートにする。参考はイチゼロシステムの考察動画:
テーマに合った実写の背景の上に、光る縁取りの大見出しと、強いテロップを載せて、Kurage が話す。

  /usr/bin/python3 kurage/backend/kosatsu_short.py kurage/kosatsu/specs/<slug>.json [--register] [--no-tts-cache]

spec（JSON）:
  title, blog_url, summary, keywords, scenes[]:
    bg（背景の動画・/home/kojima/work からの相対パス）, bg_start（秒）, kicker（見出しの上の小見出し）,
    headline（大見出し）, narration（字幕の元の文）, reading（読み上げ用。数字・英字をかなにしたもの）,
    telops（テロップの文。【】で囲んだ語を黄色で強調）
出力: kurage/outputs/kosatsu/<slug>/short.mp4・thumbnail.jpg
--register で Kurage のジョブ（storage/jobs/<id>.json と <id>/output.mp4）として登録し、静的メディアへ同期する。

作り: 背景は ffmpeg で場面ごとに 1080×1920 へ切り出してつなぐ。見出し・テロップ・Kurage のカードは
PIL で1コマずつ重ね（弾むように出す）、Kurage の動画（白背景）はカードの中に ffmpeg で重ねる。声は Audio8（Kurage の声）。
"""
from __future__ import annotations

import argparse
import json
import math
import os
import re
import secrets
import shutil
import subprocess
import sys
import time
from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter, ImageFont

WORK = Path("/home/kojima/work")
KURAGE = WORK / "kurage"
sys.path.insert(0, str(KURAGE / "backend"))

W, H, FPS = 1080, 1920, 30
SANS = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"
SERIF = "/usr/share/fonts/opentype/noto/NotoSerifCJK-Black.ttc"
MASCOT = WORK / "kvtuber/public/avatar/mascot/kurage_mascot_h3_loop.mp4"
LEAD, TAIL = 0.25, 0.35            # 場面の頭と終わりの間（秒）
CARD = (726, 1478, 330, 392)       # Kurage のカード（x, y, 幅, 高さ）
MASCOT_BOX = (CARD[0] + 15, CARD[1] + 77, 300, 300)
TELOP_Y = 1270                     # テロップの中心の高さ（カードより上）
HEAD_Y = 170                       # 見出しの上端


def font(path: str, size: int) -> ImageFont.FreeTypeFont:
    return ImageFont.truetype(path, size, index=0)


def run(cmd: list[str], **kw) -> subprocess.CompletedProcess:
    return subprocess.run(cmd, check=True, **kw)


def duration_of(p: Path) -> float:
    out = subprocess.run(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", str(p)],
                         capture_output=True, text=True, check=True).stdout.strip()
    return float(out)


# ------------------------------------------------------------------ 文字の部品

def parse_marks(s: str) -> list[tuple[str, bool]]:
    """「あ【い】う」→ [(あ,False),(い,True),(う,False)]"""
    out, pos = [], 0
    for m in re.finditer(r"【(.+?)】", s):
        if m.start() > pos:
            out.append((s[pos:m.start()], False))
        out.append((m.group(1), True))
        pos = m.end()
    if pos < len(s):
        out.append((s[pos:], False))
    return out


def plain(s: str) -> str:
    return re.sub(r"[【】]", "", s)


def text_w(f: ImageFont.FreeTypeFont, s: str) -> int:
    b = f.getbbox(s)
    return b[2] - b[0]


def glow_text(text: str, fnt: ImageFont.FreeTypeFont, fill, layers: list[tuple[int, tuple, int]], pad: int = 60) -> Image.Image:
    """縁取りを重ねた文字。layers=[(縁の太さ, 色, ぼかし)] を外側から順に。"""
    bw, bh = text_w(fnt, text) + pad * 2, fnt.size + pad * 2 + int(fnt.size * 0.3)
    img = Image.new("RGBA", (bw, bh), (0, 0, 0, 0))
    org = (pad, pad - fnt.getbbox(text)[1] + int(fnt.size * 0.05))
    for width, color, blur in layers:
        lay = Image.new("RGBA", img.size, (0, 0, 0, 0))
        ImageDraw.Draw(lay).text(org, text, font=fnt, fill=color, stroke_width=width, stroke_fill=color)
        if blur:
            lay = lay.filter(ImageFilter.GaussianBlur(blur))
        img = Image.alpha_composite(img, lay)
    top = Image.new("RGBA", img.size, (0, 0, 0, 0))
    ImageDraw.Draw(top).text(org, text, font=fnt, fill=fill)
    return Image.alpha_composite(img, top)


def headline_image(kicker: str, headline: str) -> Image.Image:
    """大見出し（明朝の白・光る縁）と小見出し（黄色のゴシック・赤の縁）。"""
    size = 168
    while size > 90 and text_w(font(SERIF, size), headline) > W - 120:
        size -= 6
    main = glow_text(headline, font(SERIF, size), (255, 255, 255, 255),
                     [(30, (255, 40, 210, 200), 14), (22, (60, 255, 40, 255), 2), (11, (0, 0, 0, 255), 0)])
    ks = 70
    while ks > 44 and text_w(font(SANS, ks), kicker) > W - 200:
        ks -= 4
    kick = glow_text(kicker, font(SANS, ks), (255, 228, 40, 255),
                     [(16, (0, 0, 0, 230), 6), (10, (220, 20, 30, 255), 0), (4, (0, 0, 0, 255), 0)], pad=40)
    img = Image.new("RGBA", (W, kick.height + main.height - 40), (0, 0, 0, 0))
    img.alpha_composite(kick, ((W - kick.width) // 2, 0))
    img.alpha_composite(main, ((W - main.width) // 2, kick.height - 40))
    return img


def telop_image(s: str) -> Image.Image:
    """強いテロップ: 極太のゴシック・白と黄色・太い黒縁・影。1行に収まらなければ2行。"""
    parts = parse_marks(s)
    txt = plain(s)
    size = 96
    while size > 70 and text_w(font(SANS, size), txt) > W - 90:
        size -= 4
    lines = [parts]
    if text_w(font(SANS, size), txt) > W - 90:   # 2行に分ける（真ん中に近い区切りで）
        cut = len(txt) // 2
        acc, a, b = 0, [], []
        for seg, hl in parts:
            if acc + len(seg) <= cut:
                a.append((seg, hl))
            elif acc >= cut:
                b.append((seg, hl))
            else:
                k = cut - acc
                a.append((seg[:k], hl)); b.append((seg[k:], hl))
            acc += len(seg)
        lines = [a, b]
    f = font(SANS, size)
    pad, lh = 40, int(size * 1.22)
    width = max(sum(text_w(f, t) for t, _ in ln) for ln in lines) + pad * 2
    img = Image.new("RGBA", (min(W, width + 20), lh * len(lines) + pad * 2), (0, 0, 0, 0))
    for li, ln in enumerate(lines):
        lw = sum(text_w(f, t) for t, _ in ln)
        x = (img.width - lw) // 2
        y = pad + li * lh - f.getbbox("あ")[1]
        for seg, hl in ln:
            for (dx, dy, wdt, col, blur) in [(8, 10, 16, (0, 0, 0, 170), 6), (0, 0, 14, (0, 0, 0, 255), 0)]:
                lay = Image.new("RGBA", img.size, (0, 0, 0, 0))
                ImageDraw.Draw(lay).text((x + dx, y + dy), seg, font=f, fill=col, stroke_width=wdt, stroke_fill=col)
                if blur:
                    lay = lay.filter(ImageFilter.GaussianBlur(blur))
                img.alpha_composite(lay)
            d = ImageDraw.Draw(img)
            if hl:   # 強調: 黄色の文字に赤い内縁
                d.text((x, y), seg, font=f, fill=(255, 232, 0, 255), stroke_width=5, stroke_fill=(200, 20, 20, 255))
            else:
                d.text((x, y), seg, font=f, fill=(255, 255, 255, 255))
            x += text_w(f, seg)
    return img


def static_layer() -> Image.Image:
    """全コマ共通: 上下の暗い帯（文字を読みやすく）と Kurage のカード。"""
    img = Image.new("RGBA", (W, H), (0, 0, 0, 0))
    g = Image.new("L", (1, H))
    for y in range(H):
        a = 0
        if y < 700:
            a = int(150 * (1 - y / 700) ** 1.3)
        elif y > 1000:
            a = int(170 * ((y - 1000) / 920) ** 1.2)
        g.putpixel((0, y), a)
    img.paste((0, 0, 0, 255), (0, 0, W, H), g.resize((W, H)))
    x, y, w, h = CARD
    d = ImageDraw.Draw(img)
    d.rounded_rectangle((x + 6, y + 8, x + w + 6, y + h + 8), 26, fill=(0, 0, 0, 120))
    d.rounded_rectangle((x, y, x + w, y + h), 26, fill=(255, 255, 255, 255), outline=(10, 154, 143, 255), width=6)
    d.rounded_rectangle((x + 18, y + 14, x + w - 18, y + 66), 18, fill=(10, 154, 143, 255))
    nf = font(SANS, 34)
    d.text((x + w // 2, y + 40), "Kurage の考察", font=nf, fill=(255, 255, 255, 255), anchor="mm")
    return img


def ease_out(t: float) -> float:
    return 1 - (1 - max(0.0, min(1.0, t))) ** 3


def place_pop(frame: Image.Image, img: Image.Image, cx: int, top_or_cy: int, t: float, dur: float, s0: float, center_y: bool) -> None:
    """img を弾むように出す（dur 秒で s0 倍→1倍・透明→不透明）。"""
    k = ease_out(t / dur) if dur > 0 else 1.0
    s = s0 + (1 - s0) * k
    im = img if abs(s - 1) < 1e-3 else img.resize((max(1, int(img.width * s)), max(1, int(img.height * s))), Image.BILINEAR)
    if k < 1:
        a = im.getchannel("A").point(lambda v: int(v * min(1.0, k * 1.6)))
        im = im.copy(); im.putalpha(a)
    x = cx - im.width // 2
    y = (top_or_cy - im.height // 2) if center_y else top_or_cy - (im.height - img.height) // 2
    frame.paste(im, (x, y), im)


# ------------------------------------------------------------------ 声と背景

def make_voice(scenes: list[dict], out_dir: Path, cache: bool) -> list[float]:
    from tts_gen import run_audio8_tts
    durs = []
    for i, sc in enumerate(scenes):
        mp3 = out_dir / f"voice_{i:02d}.mp3"
        if not (cache and mp3.exists()):
            d = run_audio8_tts(sc.get("reading") or sc["narration"], mp3)
            if d <= 0:
                raise SystemExit(f"声を作れませんでした（場面 {i}）")
        durs.append(duration_of(mp3))
    return durs


def make_audio(n: int, voice_durs: list[float], scene_durs: list[float], out_dir: Path) -> Path:
    parts = []
    for i in range(n):
        p = out_dir / f"voice_{i:02d}_pad.wav"
        run(["ffmpeg", "-y", "-loglevel", "error", "-i", str(out_dir / f"voice_{i:02d}.mp3"),
             "-af", f"adelay={int(LEAD * 1000)}:all=1,apad=whole_dur={scene_durs[i]:.3f},atrim=0:{scene_durs[i]:.3f}",
             "-ar", "44100", "-ac", "2", str(p)])
        parts.append(p)
    lst = out_dir / "audio_list.txt"
    lst.write_text("".join(f"file '{p.name}'\n" for p in parts))
    out = out_dir / "narration.wav"
    run(["ffmpeg", "-y", "-loglevel", "error", "-f", "concat", "-safe", "0", "-i", str(lst), "-c", "copy", str(out)])
    return out


def make_background(scenes: list[dict], scene_durs: list[float], out_dir: Path) -> Path:
    segs = []
    for i, sc in enumerate(scenes):
        src = WORK / sc["bg"]
        if not src.exists():
            raise SystemExit(f"背景の動画がありません: {src}")
        n = round(scene_durs[i] * FPS)
        seg = out_dir / f"bg_{i:02d}.mp4"
        # 縦に合わせて切り出し、ゆっくり寄りながら少し左右に揺らす（静止画のように見せない）
        vf = (f"scale={W + 120}:{H + 214}:force_original_aspect_ratio=increase,"
              f"crop={W}:{H}:x='(in_w-{W})/2+40*sin(t*0.6)':y='(in_h-{H})/2-30*t/{max(1.0, scene_durs[i]):.2f}',"
              f"fps={FPS},eq=saturation=1.12:contrast=1.05,format=yuv420p")
        run(["ffmpeg", "-y", "-loglevel", "error", "-stream_loop", "-1", "-ss", str(sc.get("bg_start", 0)), "-i", str(src),
             "-vf", vf, "-frames:v", str(n), "-an", "-c:v", "libx264", "-preset", "veryfast", "-crf", "16", str(seg)])
        segs.append(seg)
    lst = out_dir / "bg_list.txt"
    lst.write_text("".join(f"file '{p.name}'\n" for p in segs))
    out = out_dir / "background.mp4"
    run(["ffmpeg", "-y", "-loglevel", "error", "-f", "concat", "-safe", "0", "-i", str(lst), "-c", "copy", str(out)])
    return out


# ------------------------------------------------------------------ 組み立て

def render(spec: dict, out_dir: Path, cache: bool = True) -> tuple[Path, Path]:
    scenes = spec["scenes"]
    out_dir.mkdir(parents=True, exist_ok=True)
    voice = make_voice(scenes, out_dir, cache)
    scene_durs = [LEAD + v + TAIL for v in voice]
    starts = [sum(scene_durs[:i]) for i in range(len(scenes))]
    total = sum(scene_durs)
    audio = make_audio(len(scenes), voice, scene_durs, out_dir)
    bg = make_background(scenes, scene_durs, out_dir)

    # 場面ごとの見出しと、テロップの出る時間（声の長さを文字数で割り振る）
    heads = [headline_image(sc["kicker"], sc["headline"]) for sc in scenes]
    tel = []   # (開始, 終了, 画像)
    for i, sc in enumerate(scenes):
        ts = sc["telops"]
        lens = [max(1, len(plain(t))) for t in ts]
        t0 = starts[i] + LEAD * 0.6
        span = voice[i] + LEAD * 0.4
        acc = 0
        for t, ln in zip(ts, lens):
            a = t0 + span * acc / sum(lens)
            acc += ln
            b = t0 + span * acc / sum(lens)
            tel.append((a, b if acc < sum(lens) else starts[i] + scene_durs[i], telop_image(t)))
    base = static_layer()

    out = out_dir / "short.mp4"
    nframes = round(total * FPS)
    dec = subprocess.Popen(["ffmpeg", "-loglevel", "error", "-i", str(bg), "-f", "rawvideo", "-pix_fmt", "rgb24", "-"],
                           stdout=subprocess.PIPE)
    mx, my, mw, mh = MASCOT_BOX
    enc = subprocess.Popen([
        "ffmpeg", "-y", "-loglevel", "error",
        "-f", "rawvideo", "-pix_fmt", "rgb24", "-s", f"{W}x{H}", "-r", str(FPS), "-i", "-",
        "-stream_loop", "-1", "-i", str(MASCOT), "-i", str(audio),
        "-filter_complex", f"[1:v]scale={mw}:{mh},setsar=1,fps={FPS}[m];[0:v][m]overlay=x={mx}:y={my}:eof_action=pass[v]",
        "-map", "[v]", "-map", "2:a", "-t", f"{total:.3f}",
        "-c:v", "libx264", "-preset", "medium", "-crf", "19", "-pix_fmt", "yuv420p",
        "-c:a", "aac", "-b:a", "160k", "-movflags", "+faststart", str(out)], stdin=subprocess.PIPE)
    fsize = W * H * 3
    thumb_frame = None
    for fi in range(nframes):
        buf = dec.stdout.read(fsize)
        if len(buf) < fsize:
            break
        t = fi / FPS
        frame = Image.frombytes("RGB", (W, H), buf)
        frame.paste(base, (0, 0), base)
        si = max(i for i in range(len(scenes)) if starts[i] <= t + 1e-6)
        place_pop(frame, heads[si], W // 2, HEAD_Y, t - starts[si], 0.32, 1.35, False)
        for a, b, im in tel:
            if a <= t < b:
                place_pop(frame, im, W // 2, TELOP_Y, t - a, 0.14, 1.22, True)
        # 小さな進み具合の線（いまどこを見ているか）
        ImageDraw.Draw(frame).rectangle((0, H - 10, int(W * t / total), H), fill=(255, 228, 40))
        if thumb_frame is None and t >= 1.2:
            thumb_frame = frame.copy()
        enc.stdin.write(frame.tobytes())
    enc.stdin.close(); enc.wait(); dec.wait()
    if enc.returncode != 0:
        raise SystemExit("書き出しに失敗しました")
    thumb = out_dir / "thumbnail.jpg"
    (thumb_frame or frame).convert("RGB").save(thumb, quality=90)
    print("書き出し", out, f"{total:.1f}秒")
    return out, thumb


def register(spec: dict, video: Path, thumb: Path) -> str:
    """Kurage のジョブとして登録（storage/jobs/<id>.json と <id>/output.mp4・thumbnail.jpg）し、静的メディアへ送る。"""
    jobs = KURAGE / "storage" / "jobs"
    jid = spec.get("kurage_job_id") or secrets.token_hex(8)
    d = jobs / jid
    d.mkdir(parents=True, exist_ok=True)
    shutil.copy2(video, d / "output.mp4")
    shutil.copy2(thumb, d / "thumbnail.jpg")
    now = time.strftime("%Y-%m-%d %H:%M:%S")
    narr = "".join(sc["narration"] for sc in spec["scenes"])
    job = {
        "status": "done", "progress": 100, "source": "blog", "content_type": "kosatsu_short",
        "vtuber_mode": True, "video_style": "kosatsu_short",
        "tweet_url": spec.get("blog_url", ""), "tweet_text": spec.get("summary", "")[:240],
        "tweet_author": "Kurage 考察", "tweet_author_name": spec["title"],
        "title": spec["title"], "created_at": now, "updated_at": now,
        "video_file": str(d / "output.mp4"), "thumbnail_file": str(d / "thumbnail.jpg"),
        "duration": round(duration_of(d / "output.mp4"), 1), "orientation": "vertical",
        "error": None, "traceback": None,
        "script": {"title": spec["title"], "scenes": [{"index": i, "narration": sc["narration"], "headline": sc["headline"]}
                                                       for i, sc in enumerate(spec["scenes"])]},
        "seo_title": spec["title"], "seo_description": spec.get("summary", "")[:160],
        "seo_body": narr, "seo_keywords": spec.get("keywords", []),
        "seo_generated_by": "kosatsu_short.py", "seo_generated_at": time.strftime("%Y-%m-%dT%H:%M:%S+0900"),
    }
    tmp = jobs / f"{jid}.json.tmp"
    tmp.write_text(json.dumps(job, ensure_ascii=False, indent=2), encoding="utf-8")
    tmp.replace(jobs / f"{jid}.json")
    env = dict(os.environ)   # 静的メディアの FTP の設定は aixec/.env にある
    for line in open(WORK / "aixec" / ".env", encoding="utf-8"):
        if line.startswith("FTP_") and "=" in line:
            k, v = line.rstrip("\n").split("=", 1); env.setdefault(k, v.strip().strip("'\""))
    run(["/usr/bin/python3", str(KURAGE / "scripts" / "sync-static-media.py"), "--job-id", jid, "--force"], cwd=str(KURAGE), env=env)
    print("Kurage に登録", jid, f"https://kurage.exbridge.jp/kuragev.php?id={jid}")
    return jid


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("spec")
    ap.add_argument("--register", action="store_true")
    ap.add_argument("--no-tts-cache", action="store_true")
    a = ap.parse_args()
    spec_path = Path(a.spec).resolve()
    spec = json.loads(spec_path.read_text(encoding="utf-8"))
    out_dir = KURAGE / "outputs" / "kosatsu" / spec["slug"]
    video, thumb = render(spec, out_dir, cache=not a.no_tts_cache)
    if a.register:
        jid = register(spec, video, thumb)
        spec["kurage_job_id"] = jid   # 作り直したときに同じジョブを上書きする
        spec_path.write_text(json.dumps(spec, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


if __name__ == "__main__":
    main()
