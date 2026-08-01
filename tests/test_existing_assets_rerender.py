import sys
import tempfile
from pathlib import Path
from unittest.mock import patch

import pytest
from fastapi import HTTPException


sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "backend"))

import main
import pipeline


def test_rerender_rejects_incomplete_images(monkeypatch):
    with tempfile.TemporaryDirectory() as directory:
        jobs_dir = Path(directory)
        monkeypatch.setattr(main, "JOBS_DIR", jobs_dir)
        monkeypatch.setattr(pipeline, "JOBS_DIR", jobs_dir)
        pipeline.update_job(
            "job",
            status="error",
            script={"scenes": [{"index": 0}, {"index": 1}]},
        )
        assets = jobs_dir / "job" / "assets"
        assets.mkdir(parents=True)
        (assets / "scene_00.png").write_bytes(b"x" * 2048)

        with pytest.raises(HTTPException) as error:
            main.rerender_existing_job("job")

        assert error.value.status_code == 409
        assert "scene_01.png" in error.value.detail["missing"]


def test_rerender_queues_complete_images_without_regeneration(monkeypatch):
    with tempfile.TemporaryDirectory() as directory:
        jobs_dir = Path(directory)
        monkeypatch.setattr(main, "JOBS_DIR", jobs_dir)
        monkeypatch.setattr(pipeline, "JOBS_DIR", jobs_dir)
        pipeline.update_job("job", status="error", script={"scenes": [{"index": 0}]})
        assets = jobs_dir / "job" / "assets"
        assets.mkdir(parents=True)
        (assets / "scene_00.png").write_bytes(b"x" * 2048)

        with patch("main.threading.Thread") as thread:
            result = main.rerender_existing_job("job")

        assert result["rerender"] is True
        assert (pipeline.load_job("job") or {})["status"] == "queued"
        thread.return_value.start.assert_called_once()
