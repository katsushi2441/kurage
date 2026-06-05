"""TTS narration generation using edge-tts."""
from __future__ import annotations
import asyncio
import re
import subprocess
from pathlib import Path

TTS_VOICE = "ja-JP-NanamiNeural"
TTS_RATE  = "+10%"
TTS_PITCH = "-15Hz"


def numerals_to_jp(text: str) -> str:
    """アラビア数字を日本語読みに変換（TTS前処理）"""
    _UNIT = ['', 'いち', 'に', 'さん', 'よん', 'ご', 'ろく', 'なな', 'はち', 'きゅう']
    _SEN  = {1:'せん', 2:'にせん', 3:'さんぜん', 4:'よんせん',
             5:'ごせん', 6:'ろくせん', 7:'ななせん', 8:'はっせん', 9:'きゅうせん'}
    _HYAK = {1:'ひゃく', 2:'にひゃく', 3:'さんびゃく', 4:'よんひゃく',
             5:'ごひゃく', 6:'ろっぴゃく', 7:'ななひゃく', 8:'はっぴゃく', 9:'きゅうひゃく'}

    def _int_to_jp(n: int) -> str:
        if n == 0: return 'ゼロ'
        if n < 0:  return 'マイナス' + _int_to_jp(-n)
        r = ''
        if n >= 100_000_000: r += _int_to_jp(n // 100_000_000) + 'おく'; n %= 100_000_000
        if n >= 10_000:      r += _int_to_jp(n // 10_000) + 'まん';       n %= 10_000
        if n >= 1_000:       r += _SEN[n // 1_000];                        n %= 1_000
        if n >= 100:         r += _HYAK[n // 100];                         n %= 100
        if n >= 10:
            k = n // 10
            r += ('' if k == 1 else _UNIT[k]) + 'じゅう'
            n %= 10
        if n > 0: r += _UNIT[n]
        return r

    return re.sub(r'\d+', lambda m: _int_to_jp(int(m.group(0))), text)


def get_audio_duration(path: Path) -> float:
    """ffprobe で音声の長さを取得（秒）"""
    try:
        r = subprocess.run(
            ['ffprobe', '-v', 'error', '-show_entries', 'format=duration',
             '-of', 'default=noprint_wrappers=1:nokey=1', str(path)],
            capture_output=True, text=True,
        )
        return float(r.stdout.strip())
    except Exception:
        return 0.0


def run_tts(text: str, output_path: Path) -> float:
    """edge-tts でナレーション音声を生成。成功時は秒数、失敗時は 0.0 を返す"""
    import edge_tts

    text = numerals_to_jp(text)
    print(f"  [tts] generating ({TTS_VOICE}): {text[:60]}...", flush=True)

    async def _gen():
        communicate = edge_tts.Communicate(text, voice=TTS_VOICE, rate=TTS_RATE, pitch=TTS_PITCH)
        await communicate.save(str(output_path))

    asyncio.run(_gen())

    if not output_path.exists():
        print("  [tts] failed: output not created", flush=True)
        return 0.0

    duration = get_audio_duration(output_path)
    print(f"  [tts] {output_path.name} ({duration:.1f}s)", flush=True)
    return duration


def normalize_tts_text(text: str) -> str:
    text = text or ""
    for src in ("深堀り", "深掘り", "深堀", "ふかぼり"):
        text = text.replace(src, "詳しい考察")
    return text


def generate_scene_narration_audio(scenes: list[dict], project_dir: Path) -> float:
    """全シーンのナレーションを連結して1つのmp3を生成。秒数を返す"""
    narration_text = "　".join(
        normalize_tts_text(scene.get("narration", "")) for scene in scenes if scene.get("narration")
    )
    if not narration_text.strip():
        return 0.0

    output_path = project_dir / "narration.mp3"
    return run_tts(narration_text, output_path)
