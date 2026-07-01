#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import time
from pathlib import Path
from typing import Any

from redis import Redis
from rq import Queue
from rq.job import Job


REDIS_URL = "redis://127.0.0.1:6379/0"


def slug(value: str) -> str:
    return "".join(ch if ch.isalnum() else "-" for ch in value.strip()).strip("-").lower()


def queue_name(voicebox_api: str, queue_class: str) -> str:
    host = voicebox_api.replace("http://", "").replace("https://", "").split("/", 1)[0].split(":", 1)[0]
    return f"voicebox-{slug(host)}-{queue_class}"


def job_result(job: Job) -> Any:
    try:
        return job.return_value()
    except TypeError:
        return job.result


def main() -> int:
    parser = argparse.ArgumentParser(description="Enqueue Voicebox TTS through rqdb4ai/RQ.")
    parser.add_argument("--text-file", required=True)
    parser.add_argument("--output-path", required=True)
    parser.add_argument("--result-file", required=True)
    parser.add_argument("--voicebox-api", default="http://192.168.0.11:17493")
    parser.add_argument("--profile-id", required=True)
    parser.add_argument("--engine", default="qwen")
    parser.add_argument("--language", default="ja")
    parser.add_argument("--queue-class", default="web")
    parser.add_argument("--timeout", type=int, default=600)
    parser.add_argument("--source", default="kurage_tts")
    args = parser.parse_args()

    text = Path(args.text_file).read_text(encoding="utf-8")
    output = str(Path(args.output_path).expanduser().resolve())
    result_file = Path(args.result_file)
    redis = Redis.from_url(REDIS_URL)
    qname = queue_name(args.voicebox_api, args.queue_class)
    queue = Queue(qname, connection=redis)
    job = queue.enqueue(
        "kurage_voicebox_jobs.voicebox_tts_job",
        text=text,
        output_path=output,
        voicebox_api=args.voicebox_api,
        profile_id=args.profile_id,
        engine=args.engine,
        language=args.language,
        timeout_seconds=args.timeout,
        meta={
            "project": "kurage",
            "app": "kurage",
            "kind": "tts",
            "resource": "voicebox",
            "resource_key": f"voicebox:{args.voicebox_api}:{args.engine}",
            "voicebox_endpoint": args.voicebox_api,
            "voicebox_engine": args.engine,
            "source": args.source,
            "queue_class": args.queue_class,
            "priority_class": "interactive" if args.queue_class == "web" else "background",
        },
        job_timeout=args.timeout + 180,
        result_ttl=86400,
        failure_ttl=604800,
    )

    deadline = time.time() + args.timeout + 120
    while time.time() < deadline:
        job.refresh()
        status = job.get_status(refresh=False)
        if status == "finished":
            result = job_result(job)
            if not isinstance(result, dict):
                raise RuntimeError(f"unexpected rqdb4ai result: {result!r}")
            result_file.write_text(json.dumps({"rq_job_id": job.id, "queue": qname, **result}, ensure_ascii=False, indent=2), encoding="utf-8")
            print(json.dumps({"ok": True, "rq_job_id": job.id, "queue": qname, "output_path": output}, ensure_ascii=False))
            return 0
        if status in {"failed", "stopped", "canceled"}:
            raise RuntimeError(f"rqdb4ai Voicebox job {job.id} failed status={status} exc={job.exc_info}")
        time.sleep(2)

    raise TimeoutError(f"rqdb4ai Voicebox job timed out job_id={job.id} queue={qname}")


if __name__ == "__main__":
    raise SystemExit(main())
