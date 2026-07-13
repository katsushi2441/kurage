#!/usr/bin/env python3
"""Create an LTX motion prompt with the shared Gemma 4 Ollama service."""

import argparse
import json
import os
from pathlib import Path
from urllib.request import Request, urlopen


SYSTEM_PROMPT = """You are the motion director for an image-to-video model.
Convert the brief into one concise English LTX-2.3 prompt. Preserve character
identity and face. Describe only visible motion, camera motion, lighting, and
ambient sound. Do not add dialogue, text, scene cuts, or unrelated objects.
Return only the prompt."""


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("brief", help="Motion brief, in Japanese or English")
    parser.add_argument("output", type=Path)
    parser.add_argument(
        "--ollama-url",
        default=os.environ.get("KURAGE_OLLAMA_URL", "http://192.168.0.14:11434"),
    )
    parser.add_argument(
        "--model",
        default=os.environ.get("KURAGE_OLLAMA_MODEL", "gemma4:12b-it-qat"),
    )
    args = parser.parse_args()

    payload = json.dumps(
        {
            "model": args.model,
            "stream": False,
            "think": False,
            "prompt": f"{SYSTEM_PROMPT}\n\nBrief: {args.brief}",
        }
    ).encode("utf-8")
    request = Request(
        f"{args.ollama_url.rstrip('/')}/api/generate",
        data=payload,
        headers={"Content-Type": "application/json"},
    )
    with urlopen(request, timeout=180) as response:
        result = json.load(response)

    prompt = result.get("response", "").strip()
    if not prompt:
        raise SystemExit("Gemma 4 returned an empty prompt")
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(prompt + "\n", encoding="utf-8")
    print(prompt)


if __name__ == "__main__":
    main()
