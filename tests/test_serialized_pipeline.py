import sys
import threading
import time
from pathlib import Path


sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "backend"))

import main


def test_video_pipelines_do_not_overlap(monkeypatch):
    monkeypatch.setattr(main, "VIDEO_GENERATION_LOCK", threading.Lock())
    state = {"active": 0, "maximum": 0}
    state_lock = threading.Lock()

    def pipeline(_: str) -> None:
        with state_lock:
            state["active"] += 1
            state["maximum"] = max(state["maximum"], state["active"])
        time.sleep(0.03)
        with state_lock:
            state["active"] -= 1

    threads = [
        threading.Thread(target=main.run_serialized_video_pipeline, args=(pipeline, f"job-{index}"))
        for index in range(4)
    ]
    for thread in threads:
        thread.start()
    for thread in threads:
        thread.join()

    assert state["maximum"] == 1
