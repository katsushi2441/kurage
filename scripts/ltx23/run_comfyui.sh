#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
COMFYUI_ROOT="${COMFYUI_ROOT:-$ROOT/vendor/ComfyUI}"
COMFYUI_HOST="${COMFYUI_HOST:-127.0.0.1}"
COMFYUI_PORT="${COMFYUI_PORT:-18188}"

exec "$COMFYUI_ROOT/.venv/bin/python" "$COMFYUI_ROOT/main.py" \
  --listen "$COMFYUI_HOST" \
  --port "$COMFYUI_PORT" \
  --lowvram \
  --disable-auto-launch
