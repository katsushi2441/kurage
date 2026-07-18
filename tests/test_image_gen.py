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

    def fake_generate(prompt, output_path, width=384, height=384):
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
