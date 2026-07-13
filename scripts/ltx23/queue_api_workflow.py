#!/usr/bin/env python3
"""Submit a ComfyUI API workflow and wait for its final result."""

import argparse
import json
import time
import uuid
from pathlib import Path
from urllib.request import Request, urlopen


def request_json(url: str, payload: dict | None = None) -> dict:
    data = json.dumps(payload).encode("utf-8") if payload is not None else None
    request = Request(
        url,
        data=data,
        headers={"Content-Type": "application/json"} if data else {},
    )
    with urlopen(request, timeout=60) as response:
        return json.load(response)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("workflow", type=Path)
    parser.add_argument("--server", default="http://127.0.0.1:18188")
    parser.add_argument("--timeout", type=int, default=900)
    args = parser.parse_args()

    prompt = json.loads(args.workflow.read_text(encoding="utf-8"))
    result = request_json(
        f"{args.server.rstrip('/')}/prompt",
        {"prompt": prompt, "client_id": f"kurage-ltx23-{uuid.uuid4().hex}"},
    )
    prompt_id = result.get("prompt_id")
    if not prompt_id:
        raise SystemExit(json.dumps(result, ensure_ascii=False))
    print(f"prompt_id={prompt_id}", flush=True)

    deadline = time.monotonic() + args.timeout
    while time.monotonic() < deadline:
        history = request_json(
            f"{args.server.rstrip('/')}/history/{prompt_id}"
        ).get(prompt_id)
        if history:
            status = history.get("status", {})
            if status.get("status_str") == "error":
                raise SystemExit(json.dumps(status, ensure_ascii=False))
            if status.get("completed"):
                if status.get("status_str") != "success":
                    raise SystemExit(json.dumps(status, ensure_ascii=False))
                print(json.dumps(history.get("outputs", {}), ensure_ascii=False))
                return
        time.sleep(2)
    raise SystemExit(f"timed out waiting for {prompt_id}")


if __name__ == "__main__":
    main()
