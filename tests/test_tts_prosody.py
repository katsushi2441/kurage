import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "backend"))

from tts_gen import prepare_prosody_text


def test_prepare_prosody_adds_japanese_pauses_and_pronunciations():
    out = prepare_prosody_text(
        "想像してみてください 一言話すだけで AIが動画制作をすべて仕上げてくれます これがOpenMontageです"
    )
    assert "ください。" in out
    assert "仕上げてくれます。" in out
    assert "オープンモンタージュ" in out
    assert out.endswith("。")


def test_prepare_prosody_reads_tool_names():
    out = prepare_prosody_text("CursorやCopilot Windsurf Flux Kling ElevenLabs Piper")
    assert "カーソル" in out
    assert "コパイロット" in out
    assert "ウィンドサーフ" in out
    assert "イレブンラボ" in out
