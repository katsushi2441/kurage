#!/usr/bin/env bash
set -euo pipefail

INTERVAL="${KURAGE_ENTERTAINMENT_INTERVAL:-300}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cd "$ROOT"
mkdir -p logs

echo "$(date '+%Y-%m-%d %H:%M:%S') entertainment pipeline service started interval=${INTERVAL}s"

while true; do
  start_ts="$(date '+%Y-%m-%d %H:%M:%S')"
  echo "${start_ts} entertainment pipeline cycle start"
  if flock -n /tmp/kurage_entertainment_pipeline.lock "$ROOT/scripts/entertainment_pipeline_deploy.sh"; then
    echo "$(date '+%Y-%m-%d %H:%M:%S') entertainment pipeline cycle ok"
  else
    echo "$(date '+%Y-%m-%d %H:%M:%S') entertainment pipeline cycle skipped_or_failed"
  fi
  sleep "$INTERVAL"
done
