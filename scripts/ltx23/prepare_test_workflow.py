#!/usr/bin/env python3
"""Create a small Linux-friendly LTX 2.3 I2V test workflow."""

import argparse
import json
from pathlib import Path


NODE_WIDGETS = {
    121: [
        "The anime girl gently blinks, smiles softly, and turns her head slightly "
        "toward the camera. Her hair and jellyfish ornaments sway naturally in a "
        "light breeze. Stable face and consistent character, calm cinematic motion, "
        "soft studio ambience, no dialogue."
    ],
    167: ["kurage_bishoujo_idle.png", "image"],
    184: ["LTX23_video_vae_bf16.safetensors"],
    189: ["ltx-2.3-spatial-upscaler-x2-1.1.safetensors"],
    196: ["LTX23_audio_vae_bf16.safetensors", "main_device", "bf16"],
    291: [3],
    292: [480],
    293: [832],
    330: ["taeltx2_3.safetensors"],
    345: ["LTX 2.3/LTX-2.3-22B-distilled-1.1-Q4_K_M.gguf"],
    361: ["disabled", False],
}


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("source", type=Path)
    parser.add_argument("destination", type=Path)
    parser.add_argument("--prompt-file", type=Path)
    parser.add_argument("--filename-prefix", default="ltx23_kurage_test")
    parser.add_argument("--input-image", default="kurage_bishoujo_idle.png")
    parser.add_argument("--duration-seconds", type=int, default=3)
    parser.add_argument("--width", type=int, default=480)
    parser.add_argument("--height", type=int, default=832)
    args = parser.parse_args()

    workflow = json.loads(args.source.read_text(encoding="utf-8"))
    nodes = {node["id"]: node for node in workflow["nodes"]}
    missing = sorted(set(NODE_WIDGETS) - set(nodes))
    if missing:
        raise SystemExit(f"required workflow nodes are missing: {missing}")

    for node_id, widgets in NODE_WIDGETS.items():
        nodes[node_id]["widgets_values"] = widgets

    nodes[167]["widgets_values"] = [args.input_image, "image"]
    nodes[291]["widgets_values"] = [max(1, args.duration_seconds)]
    nodes[292]["widgets_values"] = [max(64, args.width)]
    nodes[293]["widgets_values"] = [max(64, args.height)]

    if args.prompt_file:
        prompt = args.prompt_file.read_text(encoding="utf-8").strip()
        if not prompt:
            raise SystemExit("prompt file is empty")
        nodes[121]["widgets_values"] = [prompt]

    video_widgets = nodes[140]["widgets_values"]
    video_widgets["filename_prefix"] = args.filename_prefix
    video_widgets["pingpong"] = False
    video_widgets.pop("videopreview", None)

    args.destination.parent.mkdir(parents=True, exist_ok=True)
    args.destination.write_text(
        json.dumps(workflow, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


if __name__ == "__main__":
    main()
