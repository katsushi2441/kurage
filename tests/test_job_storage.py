import sys
import tempfile
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path


sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "backend"))

import pipeline


def test_concurrent_job_updates_preserve_fields(monkeypatch):
    with tempfile.TemporaryDirectory() as directory:
        monkeypatch.setattr(pipeline, "JOBS_DIR", Path(directory))
        pipeline.update_job("job", status="rendering")

        with ThreadPoolExecutor(max_workers=8) as executor:
            list(executor.map(lambda index: pipeline.update_job("job", **{f"field_{index}": index}), range(20)))

        saved = pipeline.load_job("job") or {}
        for index in range(20):
            assert saved[f"field_{index}"] == index


def test_concurrent_view_increments_are_not_lost(monkeypatch):
    with tempfile.TemporaryDirectory() as directory:
        monkeypatch.setattr(pipeline, "JOBS_DIR", Path(directory))
        pipeline.update_job("job", status="done", views=0)

        with ThreadPoolExecutor(max_workers=8) as executor:
            list(executor.map(lambda _: pipeline.increment_job_views("job"), range(30)))

        assert (pipeline.load_job("job") or {})["views"] == 30
