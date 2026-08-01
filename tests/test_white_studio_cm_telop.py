import sys
import tempfile
from pathlib import Path


sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "backend"))

import pipeline
import static_media
from video_gen import _overlay_thumbnail_title, build_html_v2


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


def test_white_studio_thumbnail_normalizes_legacy_square_art(tmp_path):
    from PIL import Image

    thumbnail = tmp_path / "thumbnail.jpg"
    Image.new("RGB", (384, 384), (225, 248, 250)).save(thumbnail)

    _overlay_thumbnail_title(thumbnail, "OSSで食べていく方法：30年越しの正解")

    result = Image.open(thumbnail)
    assert result.size == (1080, 1920)
    assert result.getpixel((10, 10)) != (0, 0, 0)


def test_kmontage_generates_dedicated_thumbnail_assets(monkeypatch, tmp_path):
    calls = {}

    def fake_generate(prompt, output, width=384, height=384, provider="ernie"):
        calls.update(prompt=prompt, width=width, height=height, provider=provider)
        output.write_bytes(b"generated-thumbnail")
        return output

    monkeypatch.setattr(pipeline, "generate_or_reuse_image", fake_generate)
    monkeypatch.setattr(
        pipeline,
        "image_generation_metadata",
        lambda path: {"actual_provider": "codex_subscription", "fallback": False},
    )
    monkeypatch.setattr(pipeline, "update_job", lambda *args, **kwargs: calls.update(job=kwargs))
    assets = tmp_path / "assets"
    assets.mkdir()
    request = {
        "source": "kmontage",
        "thumbnail": {
            "enabled": True,
            "headline": "OSSで食べる：30年越しの答え",
            "topic_label": "OPEN SOURCE  ×  x402",
            "image_prompt": "Kurage AI, white studio, no readable text",
        },
    }

    result = pipeline.generate_kmontage_thumbnail_assets(
        "job", request, _script(), assets, "codex_subscription"
    )

    assert result["status"] == "generated"
    assert calls["width"] == 1080
    assert calls["height"] == 1920
    assert calls["provider"] == "codex_subscription"
    assert (assets / "thumbnail_base_generated.png").is_file()
    assert (assets / "thumbnail_title.txt").read_text().strip() == "OSSで食べる：30年越しの答え"
    assert (assets / "thumbnail_topic.txt").read_text().strip() == "OPEN SOURCE  ×  x402"


def test_kmontage_thumbnail_failure_falls_back_without_raising(monkeypatch, tmp_path):
    monkeypatch.setattr(
        pipeline,
        "generate_or_reuse_image",
        lambda *args, **kwargs: (_ for _ in ()).throw(RuntimeError("provider unavailable")),
    )
    monkeypatch.setattr(pipeline, "update_job", lambda *args, **kwargs: None)
    assets = tmp_path / "assets"
    assets.mkdir()

    result = pipeline.generate_kmontage_thumbnail_assets(
        "job", {"source": "kmontage"}, _script(), assets, "ernie"
    )

    assert result["status"] == "fallback_scene_0"
    assert "provider unavailable" in result["error"]
