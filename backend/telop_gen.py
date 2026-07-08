"""telop_gen — テロップ・システムv2の編集判断(EDL)を作る。

EDL(Edit Decision List)は「各シーンをどのテンプレートで、どの文節割り・
強調語で見せるか」だけを持つ純データ。CSS/レイアウトはvideo_gen.py側に
固定実装されたテンプレート(A〜D)であり、LLMにも本モジュールにも書かせない。

  A kinetic     … 文節同期字幕(全シーンの基本)
  B marker      … キーワード・マーカー(結論の言い切り、1動画3回まで)
  C lower_third … ローワーサード+バッジ(シーン0のオープニング)
  D data_card   … データカード(数字が主役のシーン)

editor_mode:
  normal … ここにある決定的ヒューリスティックのみ(LLM不使用)
  llm    … Claude CLI(OAuth) → 失敗時 Ollama gemma4 → 失敗時 normal と
           同じヒューリスティックへフォールバック(fail-open)
"""
from __future__ import annotations

import glob
import json
import os
import re
import shutil
import subprocess
from pathlib import Path

import requests

from config import OLLAMA_URL, OLLAMA_MODEL

CLAUDE_MODEL = os.environ.get("KURAGE_EDITOR_CLAUDE_MODEL", "sonnet")
CLAUDE_TIMEOUT = int(os.environ.get("KURAGE_EDITOR_CLAUDE_TIMEOUT", "180"))
OLLAMA_TIMEOUT = int(os.environ.get("KURAGE_EDITOR_OLLAMA_TIMEOUT", "300"))

TEMPLATES = {"kinetic", "marker", "lower_third", "data_card"}
MARKER_BUDGET = 3
CHUNK_TARGET = 17   # 1文節の目安文字数
CHUNK_MAX = 24

_SENTENCE_SPLIT = re.compile(r"(?<=[。！？!?])")
_SOFT_SPLIT = re.compile(r"(?<=[、,])")
_NUM_RE = re.compile(r"([+＋\-−]?\d[\d,．.]*)\s*(%|％|倍|人|件|円|ドル|億|兆|万|年|日|時間|分|本|社|カ国|か国|GW|TB|GB)?")
_KATAKANA_LATIN = re.compile(r"[ァ-ヴーA-Za-z0-9][ァ-ヴーA-Za-z0-9\s\-\.]{2,}")
_QUOTED = re.compile(r"[「『]([^」』]{2,18})[」』]")


# ---------------------------------------------------------------- chunks
def split_chunks(text: str) -> list[str]:
    """ナレーションを表示単位(8〜24字目安)の文節に割る。語句は改変しない。"""
    text = " ".join(str(text or "").split())
    if not text:
        return []
    parts: list[str] = []
    for sent in _SENTENCE_SPLIT.split(text):
        sent = sent.strip()
        if not sent:
            continue
        if len(sent) <= CHUNK_MAX:
            parts.append(sent)
            continue
        buf = ""
        for piece in _SOFT_SPLIT.split(sent):
            if not piece:
                continue
            if buf and len(buf) + len(piece) > CHUNK_MAX:
                parts.append(buf)
                buf = piece
            else:
                buf += piece
        if buf:
            parts.append(buf)
    # 長すぎる残りは助詞などの自然な切れ目で折る(単語の途中で切らない)
    out: list[str] = []
    for p in parts:
        while len(p) > CHUNK_MAX + 4:
            cut = _natural_cut(p)
            out.append(p[:cut])
            p = p[cut:]
        out.append(p)
    # 短すぎる断片は前と結合(先頭の場合は次と結合)
    merged: list[str] = []
    for p in out:
        if merged and len(p) <= 5 and len(merged[-1]) + len(p) <= CHUNK_MAX + 4:
            merged[-1] += p
        elif merged and len(merged[-1]) <= 5 and len(merged[-1]) + len(p) <= CHUNK_MAX + 4:
            merged[-1] += p
        else:
            merged.append(p)
    return merged


_PARTICLES = "のをにがはでとへもや、"


def _natural_cut(text: str) -> int:
    """CHUNK_TARGET付近で最も自然な折り返し位置を返す(助詞・読点の直後)。"""
    lo = max(6, CHUNK_TARGET - 7)
    hi = min(len(text) - 4, CHUNK_MAX)
    best = -1
    for i in range(lo, hi):
        ch = text[i]
        nxt = text[i + 1] if i + 1 < len(text) else ""
        # 助詞の直後、かつ次がASCII/カタカナ語の途中でない位置を優先
        if ch in _PARTICLES and not _mid_word(nxt):
            best = i + 1
    if best > 0:
        return best
    # 見つからなければ英数・カタカナ語の途中だけは避けて折る
    for i in range(min(len(text) - 1, CHUNK_MAX), lo, -1):
        if not _mid_word(text[i]) or not _mid_word(text[i - 1]):
            return i
    return CHUNK_MAX


def _mid_word(ch: str) -> bool:
    if not ch:
        return False
    return bool(re.match(r"[ァ-ヴーA-Za-z0-9]", ch))


def heuristic_emphasis(chunk: str) -> str:
    """文節内の強調語を1つだけ選ぶ。無ければ空文字。"""
    m = _QUOTED.search(chunk)
    if m:
        return m.group(1)
    m = _NUM_RE.search(chunk)
    if m and any(ch.isdigit() for ch in m.group(0)):
        token = (m.group(1) + (m.group(2) or "")).strip()
        if len(token) >= 2:
            return token
    m = _KATAKANA_LATIN.search(chunk)
    if m:
        token = m.group(0).strip()
        if 3 <= len(token) <= 16:
            return token
    return ""


def _scene_number(narration: str) -> dict | None:
    """データカード向けに「数字+単位」を1つ抽出。弱い数字(年号だけ等)は使わない。"""
    best = None
    for m in _NUM_RE.finditer(narration):
        num, unit = m.group(1), m.group(2) or ""
        if not any(ch.isdigit() for ch in num):
            continue
        if not unit:
            continue
        if unit in ("年", "日") and len(num) >= 4:
            continue  # 日付・年号はカードにしない
        score = len(num) + (3 if unit in ("%", "％", "倍", "億", "兆", "万") else 1)
        if best is None or score > best[0]:
            best = (score, num, unit)
    if best is None:
        return None
    return {"number": best[1], "unit": best[2]}


# ---------------------------------------------------------------- heuristic EDL
def build_edl(script: dict) -> dict:
    """決定的ヒューリスティックEDL(通常モード/フォールバック共用)。"""
    scenes = script.get("scenes") or []
    edl_scenes: list[dict] = []
    for i, scene in enumerate(scenes):
        narration = str(scene.get("narration") or "").strip()
        chunks = [{"text": c, "emphasis": heuristic_emphasis(c)} for c in split_chunks(narration)]
        entry: dict = {"template": "kinetic", "chunks": chunks}
        if i == 0:
            headline = str(scene.get("overlay_headline") or script.get("title") or "").strip()
            entry["template"] = "lower_third"
            entry["headline"] = headline[:30]
            entry["subtitle"] = str(scene.get("overlay_subtitle") or "").strip()[:44]
            entry["badge"] = str(scene.get("overlay_badge") or "").strip()[:8]
        else:
            num = _scene_number(narration)
            if num is not None:
                entry["template"] = "data_card"
                entry.update(num)
                entry["label"] = ""
        edl_scenes.append(entry)
    return {"version": 2, "editor": "heuristic", "scenes": edl_scenes}


# ---------------------------------------------------------------- LLM editor
def _resolve_claude_bin() -> str:
    found = shutil.which("claude")
    if found:
        return found
    candidates = sorted(glob.glob(os.path.expanduser(
        "~/.vscode-server/extensions/anthropic.claude-code-*-linux-x64/resources/native-binary/claude"
    )), reverse=True)
    for path in candidates:
        if os.path.exists(path) and os.access(path, os.X_OK):
            return path
    return ""


def _editor_prompt(script: dict, base: dict) -> str:
    scenes = script.get("scenes") or []
    lines = []
    for i, (scene, e) in enumerate(zip(scenes, base["scenes"])):
        lines.append(f"scene {i}: {str(scene.get('narration') or '').strip()}")
    scenes_block = "\n".join(lines)
    return f"""あなたはショート動画のテロップ編集者です。以下の台本に対する編集指示(EDL)を改善してください。

# 台本(ナレーションは一切改変禁止)
タイトル: {script.get('title') or ''}
{scenes_block}

# 現在のEDL(ヒューリスティック生成。これを土台に改善する)
{json.dumps(base, ensure_ascii=False)}

# テンプレートの意味
- kinetic: 文節同期字幕。chunksのtextはナレーションを順に区切ったもの(結合するとナレーション全文に一致すること)。emphasisは各chunk内の強調語(そのchunkの部分文字列、1つだけ、無ければ"")
- marker: kineticと同じ+marker_phraseに指定した句がマーカー強調される。結論・言い切りのシーンにのみ使い、動画全体で{MARKER_BUDGET}回まで。eyebrowは12字以内の眉ラベル
- lower_third: オープニング用。headline(15字前後、最大30字)/subtitle(最大44字)/badge(最大8字、例:【衝撃】)。scene 0以外では使わない
- data_card: 数字が主役のシーン。number/unit/label(20字以内の説明)。ナレーションに実在する数字のみ

# 改善の観点
- 文節の区切りを読みやすく(8〜24字、意味の切れ目で)
- 強調語は「そのシーンで一番伝えたい語」1つだけ。無理に付けない
- 数字が主役のシーンはdata_cardに、結論の言い切りはmarkerに
- headline/badgeは煽りすぎず具体的に

# 出力(このJSONだけを出力。他の文章・コードフェンス禁止)
{json.dumps({"version": 2, "scenes": [{"template": "...", "chunks": [{"text": "...", "emphasis": ""}]}]}, ensure_ascii=False)}
"""


def _call_claude(prompt: str) -> str:
    claude_bin = _resolve_claude_bin()
    if not claude_bin:
        raise RuntimeError("claude binary not found")
    cmd = [claude_bin, "-p", "--output-format", "text", "--tools", "",
           "--model", CLAUDE_MODEL, prompt]
    result = subprocess.run(cmd, capture_output=True, text=True, timeout=CLAUDE_TIMEOUT)
    if result.returncode != 0:
        detail = (result.stderr or result.stdout or "").strip()
        raise RuntimeError(f"claude cli rc={result.returncode} {detail[:200]!r}")
    return result.stdout.strip()


def _call_ollama(prompt: str) -> str:
    payload = {
        "model": OLLAMA_MODEL,
        # gemma4は思考型: think無効化しないと隠れ推論がnum_predictを食い潰す
        "think": False,
        "prompt": prompt,
        "stream": False,
        "options": {"temperature": 0.1, "num_predict": 4000},
    }
    resp = requests.post(f"{OLLAMA_URL}/api/generate", json=payload, timeout=OLLAMA_TIMEOUT)
    resp.raise_for_status()
    return (resp.json().get("response") or "").strip()


def _extract_json(text: str) -> dict | None:
    text = text.strip()
    if text.startswith("```"):
        text = re.sub(r"^```[a-zA-Z]*\s*", "", text)
        text = re.sub(r"\s*```$", "", text)
    start = text.find("{")
    if start < 0:
        return None
    depth = 0
    for i in range(start, len(text)):
        if text[i] == "{":
            depth += 1
        elif text[i] == "}":
            depth -= 1
            if depth == 0:
                try:
                    return json.loads(text[start:i + 1])
                except json.JSONDecodeError:
                    return None
    return None


def _norm(text: str) -> str:
    return re.sub(r"\s+", "", str(text or ""))


def sanitize_edl(candidate: dict, script: dict, base: dict) -> dict:
    """LLM出力を検証し、危険/不正な指定はヒューリスティック側の値に差し戻す。"""
    scenes = script.get("scenes") or []
    cand_scenes = candidate.get("scenes")
    if not isinstance(cand_scenes, list) or len(cand_scenes) != len(scenes):
        return base
    out_scenes: list[dict] = []
    marker_used = 0
    for i, (scene, cand, fallback) in enumerate(zip(scenes, cand_scenes, base["scenes"])):
        narration = str(scene.get("narration") or "").strip()
        if not isinstance(cand, dict):
            out_scenes.append(fallback)
            continue
        template = str(cand.get("template") or "").strip()
        if template not in TEMPLATES:
            template = fallback["template"]
        if template == "lower_third" and i != 0:
            template = "kinetic"

        # chunks: 結合がナレーションに一致しなければフォールバックの区切りを使う
        chunks_ok = False
        chunks: list[dict] = []
        raw_chunks = cand.get("chunks")
        if isinstance(raw_chunks, list) and raw_chunks:
            texts = [str(c.get("text") or "") for c in raw_chunks if isinstance(c, dict)]
            if _norm("".join(texts)) == _norm(narration) and all(0 < len(t) <= CHUNK_MAX + 8 for t in texts):
                chunks_ok = True
                for c in raw_chunks:
                    text = str(c.get("text") or "")
                    emphasis = str(c.get("emphasis") or "")
                    if emphasis and emphasis not in text:
                        emphasis = ""
                    chunks.append({"text": text, "emphasis": emphasis})
        if not chunks_ok:
            chunks = [dict(c) for c in fallback["chunks"]]
            # 強調だけはLLM案を部分文字列チェックの上で反映
            if isinstance(raw_chunks, list):
                for c, fc in zip(raw_chunks, chunks):
                    if isinstance(c, dict):
                        emphasis = str(c.get("emphasis") or "")
                        if emphasis and emphasis in fc["text"]:
                            fc["emphasis"] = emphasis

        entry: dict = {"template": template, "chunks": chunks}
        if template == "marker":
            phrase = str(cand.get("marker_phrase") or "").strip()
            if marker_used >= MARKER_BUDGET or not phrase or phrase not in narration:
                entry["template"] = "kinetic"
            else:
                marker_used += 1
                entry["marker_phrase"] = phrase[:20]
                entry["eyebrow"] = str(cand.get("eyebrow") or "").strip()[:14]
        if template == "lower_third":
            entry["headline"] = (str(cand.get("headline") or "").strip() or fallback.get("headline", ""))[:30]
            entry["subtitle"] = str(cand.get("subtitle") or fallback.get("subtitle", "")).strip()[:44]
            entry["badge"] = str(cand.get("badge") or fallback.get("badge", "")).strip()[:8]
        if template == "data_card":
            number = str(cand.get("number") or "").strip()
            unit = str(cand.get("unit") or "").strip()[:6]
            if not number or _norm(number) not in _norm(narration):
                fb_num = fallback if fallback.get("template") == "data_card" else None
                if fb_num is None:
                    entry["template"] = "kinetic"
                else:
                    number, unit = fb_num["number"], fb_num["unit"]
            if entry["template"] == "data_card":
                entry["number"] = number[:10]
                entry["unit"] = unit
                entry["label"] = str(cand.get("label") or "").strip()[:20]
        out_scenes.append(entry)
    return {"version": 2, "editor": candidate.get("editor", "llm"), "scenes": out_scenes}


def generate_edl(script: dict, editor_mode: str, job_dir: Path | None = None) -> dict:
    """editor_modeに応じてEDLを生成。llm失敗時はヒューリスティックへfail-open。"""
    base = build_edl(script)
    if editor_mode != "llm":
        return base
    prompt = _editor_prompt(script, base)
    raw, editor = "", ""
    try:
        raw = _call_claude(prompt)
        editor = f"claude-{CLAUDE_MODEL}"
    except Exception as exc:
        print(f"  [editor] claude failed ({exc}); falling back to ollama", flush=True)
        try:
            raw = _call_ollama(prompt)
            editor = f"ollama-{OLLAMA_MODEL}"
        except Exception as exc2:
            print(f"  [editor] ollama failed ({exc2}); using heuristic EDL", flush=True)
            return base
    candidate = _extract_json(raw)
    if candidate is None:
        print(f"  [editor] {editor} returned unparsable EDL; using heuristic", flush=True)
        return base
    candidate["editor"] = editor
    edl = sanitize_edl(candidate, script, base)
    if job_dir is not None:
        try:
            (job_dir / "edl.json").write_text(
                json.dumps(edl, ensure_ascii=False, indent=2), encoding="utf-8")
        except Exception:
            pass
    print(f"  [editor] EDL by {edl.get('editor')}", flush=True)
    return edl
