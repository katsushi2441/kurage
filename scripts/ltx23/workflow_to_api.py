#!/usr/bin/env python3
"""Convert the pinned LTX 2.3 UI workflow into a ComfyUI API prompt."""

import argparse
import json
from pathlib import Path
from urllib.request import urlopen


def load_json(path_or_url: str) -> dict:
    if path_or_url.startswith(("http://", "https://")):
        with urlopen(path_or_url, timeout=30) as response:
            return json.load(response)
    return json.loads(Path(path_or_url).read_text(encoding="utf-8"))


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("workflow")
    parser.add_argument("destination", type=Path)
    parser.add_argument(
        "--object-info", default="http://127.0.0.1:18188/object_info"
    )
    args = parser.parse_args()

    workflow = load_json(args.workflow)
    object_info = load_json(args.object_info)
    nodes = {node["id"]: node for node in workflow["nodes"]}
    links = {link[0]: link for link in workflow["links"]}
    setters = {
        node["widgets_values"][0]: node
        for node in workflow["nodes"]
        if node["type"] == "SetNode"
    }

    def resolve_origin(node_id: int, slot: int) -> list:
        node = nodes[node_id]
        if node["type"] == "GetNode":
            setter = setters[node["widgets_values"][0]]
            source = links[setter["inputs"][0]["link"]]
            return resolve_origin(source[1], source[2])
        if node.get("mode", 0) == 4:
            output_type = node["outputs"][slot]["type"]
            source_input = next(
                item
                for item in node.get("inputs", [])
                if item.get("link") is not None
                and (item.get("type") == output_type or output_type == "*")
            )
            source = links[source_input["link"]]
            return resolve_origin(source[1], source[2])
        if node["type"] not in object_info:
            raise ValueError(f"cannot resolve frontend node {node_id}: {node['type']}")
        return [str(node_id), slot]

    prompt = {}
    for node in workflow["nodes"]:
        if node.get("mode", 0) in (2, 4) or node["type"] not in object_info:
            continue

        backend = object_info[node["type"]]
        valid_inputs = {
            name
            for group in ("required", "optional")
            for name in backend.get("input", {}).get(group, {})
        }
        inputs = {}

        widget_inputs = [
            item["name"] for item in node.get("inputs", []) if "widget" in item
        ]
        values = node.get("widgets_values", [])
        if isinstance(values, dict):
            widget_values = values
        else:
            widget_values = dict(zip(widget_inputs, values))

        for name, value in widget_values.items():
            if (name in valid_inputs or "." in name) and value is not None:
                inputs[name] = value

        for item in node.get("inputs", []):
            # Dynamic/autogrow sockets (for example calculator a/b variables)
            # do not appear in the top-level object_info input list.
            if item.get("link") is None:
                continue
            source = links[item["link"]]
            inputs[item["name"]] = resolve_origin(source[1], source[2])

        prompt[str(node["id"])] = {
            "class_type": node["type"],
            "inputs": inputs,
            "_meta": {"title": node.get("title") or node["type"]},
        }

    args.destination.parent.mkdir(parents=True, exist_ok=True)
    args.destination.write_text(
        json.dumps(prompt, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )


if __name__ == "__main__":
    main()
