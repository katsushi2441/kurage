"""Shared visual identity for ERNIE-generated Kurage scenes."""
from __future__ import annotations

import os


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


def with_kurage_character(prompt: str) -> str:
    """Add the canonical heroine while preserving the requested scene context."""
    value = " ".join(str(prompt or "").replace("\n", " ").split())
    if not CHARACTER_ENABLED or "no character" in value.lower():
        return value
    if "recurring original kurage heroine" not in value.lower():
        value = f"{KURAGE_CHARACTER_PROMPT}, {value}"
    return value[:900]
