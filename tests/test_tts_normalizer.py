from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "backend"))

from tts_normalizer import normalize_tts_text


def test_product_names_are_normalized():
    text = "Kurage AI VTuberがVWorkとkdeckでAIxSNSへ投稿する"
    out = normalize_tts_text(text, convert_numbers=False)
    assert "クラゲ エーアイ ブイチューバー" in out
    assert "ブイワーク" in out
    assert "ケーデック" in out
    assert "エーアイエックス エスエヌエス" in out


def test_numbers_and_common_phrase_are_normalized():
    out = normalize_tts_text("2026年に5本の深掘り動画")
    assert "にせんにじゅうろく年" in out
    assert "ご本" in out
    assert "詳しい考察" in out
