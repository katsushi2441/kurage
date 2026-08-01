from __future__ import annotations

import base64
import io
import sys
from pathlib import Path

import pytest
import requests
from PIL import Image

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "backend"))
import image_gen  # noqa: E402


class FakeResponse:
    def __init__(self, payload: dict, status_code: int = 200):
        self.payload = payload
        self.status_code = status_code

    def raise_for_status(self) -> None:
        if self.status_code >= 400:
            error = requests.HTTPError(f"HTTP {self.status_code}")
            error.response = self
            raise error

    def json(self) -> dict:
        return self.payload


def png_base64() -> str:
    buffer = io.BytesIO()
    Image.effect_noise((128, 128), 100).convert("RGB").save(buffer, format="PNG")
    return base64.b64encode(buffer.getvalue()).decode("ascii")


def test_generate_image_retries_timeout_and_writes_atomically(tmp_path, monkeypatch: pytest.MonkeyPatch) -> None:
    attempts = 0

    def fake_post(*args, **kwargs):
        nonlocal attempts
        attempts += 1
        if attempts == 1:
            raise requests.ReadTimeout("busy")
        return FakeResponse({"image_base64": png_base64()})

    monkeypatch.setattr(image_gen.requests, "post", fake_post)
    monkeypatch.setattr(image_gen, "ERNIE_MAX_ATTEMPTS", 2)
    monkeypatch.setattr(image_gen, "ERNIE_RETRY_BACKOFF", 0)
    output = tmp_path / "scene.png"

    image_gen.generate_image("test scene", output, 64, 64)

    assert attempts == 2
    assert image_gen.is_valid_image(output)
    assert not output.with_suffix(".png.part").exists()


def test_generate_or_reuse_image_requires_matching_prompt(tmp_path, monkeypatch: pytest.MonkeyPatch) -> None:
    calls = 0

    def fake_generate(prompt, output_path, width=384, height=384, *, provider="ernie"):
        nonlocal calls
        calls += 1
        Image.effect_noise((128, 128), 100).convert("RGB").save(output_path, format="PNG")
        return output_path

    monkeypatch.setattr(image_gen, "generate_image", fake_generate)
    output = tmp_path / "scene.png"

    image_gen.generate_or_reuse_image("same prompt", output)
    image_gen.generate_or_reuse_image("same prompt", output)
    image_gen.generate_or_reuse_image("changed prompt", output)

    assert calls == 2


def test_cache_is_scoped_to_image_provider(tmp_path, monkeypatch: pytest.MonkeyPatch) -> None:
    providers = []

    def fake_generate(prompt, output_path, width=384, height=384, *, provider="ernie"):
        providers.append(provider)
        Image.effect_noise((128, 128), 100).convert("RGB").save(output_path, format="PNG")
        return output_path

    monkeypatch.setattr(image_gen, "generate_image", fake_generate)
    output = tmp_path / "scene.png"

    image_gen.generate_or_reuse_image("same prompt", output, provider="ernie")
    image_gen.generate_or_reuse_image("same prompt", output, provider="ernie")
    image_gen.generate_or_reuse_image("same prompt", output, provider="codex_subscription")

    assert providers == ["ernie", "codex_subscription"]


def test_codex_retry_does_not_reuse_ernie_fallback_cache(tmp_path, monkeypatch: pytest.MonkeyPatch) -> None:
    calls = 0

    def fake_generate(prompt, output_path, width=384, height=384, *, provider="ernie"):
        nonlocal calls
        calls += 1
        Image.effect_noise((128, 128), 100).convert("RGB").save(output_path, format="PNG")
        image_gen._write_provider_metadata(
            output_path,
            requested=provider,
            actual="ernie",
            fallback_reason="temporary failure",
        )
        return output_path

    monkeypatch.setattr(image_gen, "generate_image", fake_generate)
    output = tmp_path / "scene.png"

    image_gen.generate_or_reuse_image("same prompt", output, provider="codex_subscription")
    image_gen.generate_or_reuse_image("same prompt", output, provider="codex_subscription")

    assert calls == 2


def test_codex_failure_falls_back_to_ernie_and_records_provider(tmp_path, monkeypatch: pytest.MonkeyPatch) -> None:
    def fail_codex(*args, **kwargs):
        raise RuntimeError("hosted image tool unavailable")

    def fake_ernie(prompt, output_path, width, height, use_character):
        Image.effect_noise((128, 128), 100).convert("RGB").save(output_path, format="PNG")
        return output_path

    monkeypatch.setattr(image_gen, "_generate_with_codex_subscription", fail_codex)
    monkeypatch.setattr(image_gen, "_generate_with_ernie", fake_ernie)
    monkeypatch.setattr(image_gen, "CODEX_IMAGE_FALLBACK", "ernie")
    output = tmp_path / "scene.png"

    image_gen.generate_image("clean city diagram", output, provider="codex_subscription")

    metadata = image_gen.image_generation_metadata(output)
    assert image_gen.is_valid_image(output)
    assert metadata["requested_provider"] == "codex_subscription"
    assert metadata["actual_provider"] == "ernie"
    assert metadata["fallback"] is True


def test_codex_prompt_precedes_variadic_image_argument(tmp_path, monkeypatch: pytest.MonkeyPatch) -> None:
    reference = tmp_path / "kurage.png"
    Image.effect_noise((128, 128), 100).convert("RGB").save(reference, format="PNG")
    captured: list[str] = []

    class FailedRun:
        returncode = 1
        stdout = ""
        stderr = "intentional test failure"

    def fake_run(command, **kwargs):
        captured.extend(command)
        return FailedRun()

    monkeypatch.setattr(image_gen.shutil, "which", lambda value: value)
    monkeypatch.setattr(image_gen.subprocess, "run", fake_run)
    monkeypatch.setattr(image_gen, "CODEX_IMAGE_REFERENCE", reference)
    monkeypatch.setattr(image_gen, "_CODEX_UNAVAILABLE_UNTIL", 0)

    with pytest.raises(RuntimeError, match="intentional test failure"):
        image_gen._generate_with_codex_subscription(
            "Kurage VTuber explaining open source",
            tmp_path / "scene.png",
            384,
            384,
            True,
        )

    prompt_index = next(index for index, value in enumerate(captured) if value.startswith("Use the installed imagegen skill"))
    assert prompt_index < captured.index("--image")


@pytest.mark.parametrize(
    ("value", "expected"),
    [
        ("codex", "codex_subscription"),
        ("chatgpt", "codex_subscription"),
        ("codex-subscription", "codex_subscription"),
        ("ernie", "ernie"),
        ("unknown", "ernie"),
    ],
)
def test_normalize_image_provider(value, expected) -> None:
    assert image_gen.normalize_image_provider(value) == expected
