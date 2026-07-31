import sys
from pathlib import Path


sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "backend"))

import tts_reading
from tts_normalizer import normalize_tts_text


def test_llm_reading_is_opt_in(monkeypatch):
    monkeypatch.setattr(tts_reading, "READING_ENABLED", False)
    original = "熊本地震への616億円支援。"

    assert tts_reading.to_reading_text(original) == original


def test_deterministic_normalizer_reads_large_yen_amount():
    assert normalize_tts_text("616億円") == "ろっぴゃくじゅうろく億円"
