"""Kurage configuration."""
import os
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
STORAGE_DIR = ROOT / "storage"
JOBS_DIR = STORAGE_DIR / "jobs"
VIDEOS_DIR = STORAGE_DIR / "videos"

OLLAMA_URL = os.environ.get("OLLAMA_URL", "http://192.168.0.14:11434")
OLLAMA_MODEL = os.environ.get("OLLAMA_MODEL", "gemma4:e4b")

ERNIE_URL = os.environ.get("ERNIE_URL", "http://192.168.0.3:8010/image/generate")
WAN_API = os.environ.get("WAN_API", "http://192.168.0.14:8091")
WAN_TEST_MODE = os.environ.get("WAN_TEST_MODE", "1")

# HyperFrames CLI (npx hyperframes render) — no REST API used
NVM_NODE = os.environ.get("NVM_NODE", "/home/kojima/.nvm/versions/node/v22.22.3/bin")
HYPERFRAMES_VERSION = "0.4.44"

PORT = int(os.environ.get("KURAGE_PORT", "8025"))
