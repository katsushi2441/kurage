"""Shared visual identity for ERNIE-generated Kurage scenes."""
from __future__ import annotations

import os
import re


CHARACTER_SEED = int(os.environ.get("KURAGE_CHARACTER_SEED", "2441"))
CHARACTER_ENABLED = os.environ.get("KURAGE_CHARACTER_IDENTITY", "1").lower() not in {
    "0", "false", "no", "off",
}

KURAGE_CHARACTER_PROMPT = (
    "recurring original Kurage heroine, young adult Japanese anime woman, "
    "short silver-white bob haircut with soft side bangs, vivid green eyes, "
    "two coral-orange geometric hair clips on her left temple, "
    "white futuristic jacket with pale aqua teal panels and high collar, "
    "clean luminous anime editorial style, consistent character design"
)

# Apply the identity only when the scene already asks for a female character or
# for Kurage as its presenter. Subject matter remains the primary image prompt.
CHARACTER_CUES = (
    r"\bgirl\b", r"\bwoman\b", r"\bwomen\b", r"\bfemale\b",
    r"\bheroine\b", r"\banime character\b", r"\bvtuber\b",
    r"\bavatar\b", r"\bkurage\b", r"\bpresenter\b", r"\bhost\b",
    r"少女", r"女性", r"女の子", r"キャラクター", r"アバター",
)


def should_use_kurage_character(prompt: str) -> bool:
    """Return true only when the requested scene already calls for a character."""
    value = " ".join(str(prompt or "").replace("\n", " ").split())
    lowered = value.lower()
    if not CHARACTER_ENABLED or "no character" in lowered or "without people" in lowered:
        return False
    return any(re.search(cue, lowered, flags=re.IGNORECASE) for cue in CHARACTER_CUES)


def with_kurage_character(prompt: str) -> str:
    """Add the canonical heroine while preserving the requested scene context."""
    value = " ".join(str(prompt or "").replace("\n", " ").split())
    if not should_use_kurage_character(value):
        return value
    if "recurring original kurage heroine" not in value.lower():
        value = f"{KURAGE_CHARACTER_PROMPT}, {value}"
    return value[:900]
