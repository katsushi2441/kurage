#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB_DIR="${KURAGE_WEB_DIR:-/home/kojima/work/kurage_web}"
ENV_FILE="${AIXEC_ENV_FILE:-/home/kojima/work/aixec/.env}"
REMOTE_DIR="${KURAGE_FTP_REMOTE:-/web/kurage_exbridge_jp}"
TARGET_PER_DAY="${KURAGE_ENTERTAINMENT_TARGET_PER_DAY:-30}"
MAX_NEW="${KURAGE_ENTERTAINMENT_MAX_NEW:-3}"
MAX_VIDEOS="${KURAGE_ENTERTAINMENT_MAX_VIDEOS:-3}"
VIDEO_API="${KURAGE_VIDEO_API:-http://127.0.0.1:18303}"
QUERY="${KURAGE_ENTERTAINMENT_QUERY:-芸能人 OR 俳優 OR 女優 OR アイドル OR 歌手 OR タレント OR 映画 OR ドラマ OR 番組}"

cd "$ROOT"
python3 backend/entertainment_pipeline.py \
  --target-per-day "$TARGET_PER_DAY" \
  --max-new "$MAX_NEW" \
  --query "$QUERY" \
  --auto-video \
  --video-api "$VIDEO_API" \
  --max-videos "$MAX_VIDEOS"

mkdir -p "$WEB_DIR/data"
cp "$ROOT/data/entertainment_articles.json" "$WEB_DIR/data/entertainment_articles.json"

if [[ -f "$ENV_FILE" ]]; then
  set -a
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  set +a
fi

if [[ -n "${FTP_HOST:-}" && -n "${FTP_USER:-}" && -n "${FTP_PASS:-}" ]]; then
  curl --fail --ftp-create-dirs \
    -T "$WEB_DIR/data/entertainment_articles.json" \
    "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${REMOTE_DIR%/}/data/entertainment_articles.json"
fi
