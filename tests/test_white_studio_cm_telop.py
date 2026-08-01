import sys
import tempfile
from pathlib import Path


sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "backend"))

import pipeline
import static_media
from video_gen import build_html_v2


def _script():
    return {
        "title": "x402時代のOSS収益化",
        "scenes": [{
            "index": 0,
            "duration": 6,
            "narration": "OSSは公開するだけでは、継続的な収益につながりません。",
        }],
    }


def _edl(preset=""):
    result = {
        "editor": "heuristic",
        "scenes": [{
            "template": "kinetic",
            "chunks": [{
                "text": "OSSは公開するだけでは、継続的な収益につながりません。",
                "emphasis": "継続的な収益",
            }],
        }],
    }
    if preset:
        result["visual_preset"] = preset
    return result


def test_white_studio_cm_builds_light_brand_template():
    rendered = build_html_v2(
        _script(), [Path("unused.png")], 6.0, narration_duration=5.0,
        edl=_edl("white_studio_cm"),
    )

    assert '<body class="telop-white-studio-cm">' in rendered
    assert "Kurage Montage" in rendered
    assert "kurage.exbridge.jp" in rendered
    assert "#f7fbf8" in rendered
    assert "cm-headline" in rendered
    assert "継続的な収益" in rendered
    assert "kw-0-0" not in rendered
    assert '<div id="title-overlay">' not in rendered


def test_default_telop_does_not_enable_white_studio_cm():
    rendered = build_html_v2(
        _script(), [Path("unused.png")], 6.0, narration_duration=5.0,
        edl=_edl(),
    )

    assert '<body class="telop-white-studio-cm">' not in rendered
    assert '<div id="title-overlay">' in rendered
    assert '<div class="cm-brand"' not in rendered


def test_kmontage_existing_assets_rerender_preserves_visual_preset(monkeypatch):
    with tempfile.TemporaryDirectory() as directory:
        jobs_dir = Path(directory)
        monkeypatch.setattr(pipeline, "JOBS_DIR", jobs_dir)
        pipeline.update_job(
            "job",
            status="error",
            source="kmontage_blog",
            editor_mode="normal",
            script=_script(),
        )
        assets = jobs_dir / "job" / "assets"
        assets.mkdir(parents=True)
        (assets / "scene_00.png").write_bytes(b"x" * 2048)
        captured = {}

        monkeypatch.setattr("telop_gen.generate_edl", lambda *args: _edl())

        def fake_generate_video(script, images, job_dir, vtuber_mode=False, edl=None):
            captured["edl"] = edl
            output = job_dir / "output.mp4"
            output.write_bytes(b"video")
            return output

        monkeypatch.setattr(pipeline, "generate_video", fake_generate_video)
        monkeypatch.setattr(pipeline, "mark_job_done", lambda *args, **kwargs: None)

        pipeline.run_render_existing_assets("job")

        assert captured["edl"]["visual_preset"] == "white_studio_cm"
        stored = pipeline.load_job("job") or {}
        assert stored["telop_visual_preset"] == "white_studio_cm"


def test_static_media_force_is_forwarded_to_sync_script(monkeypatch):
    captured = {}

    class Result:
        returncode = 0
        stdout = "ok"
        stderr = ""

    def fake_run(command, **kwargs):
        captured["command"] = command
        return Result()

    monkeypatch.setattr(static_media.subprocess, "run", fake_run)
    result = static_media.publish_static_media("a1b2c3", force=True)

    assert result["ok"] is True
    assert "--force" in captured["command"]
