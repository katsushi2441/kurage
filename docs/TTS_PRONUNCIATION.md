# Kurage Shared TTS Pronunciation

Kurage / Kurage Voice Pro / Kurage AI VTuber share the same Japanese TTS normalization layer.

## Purpose

Display text must stay unchanged, but text sent to TTS should be normalized so product names, AI/OSS terms, and mixed English/Japanese words are read consistently.

Examples:

- `Kurage AI VTuber` -> `クラゲ エーアイ ブイチューバー`
- `VWork` -> `ブイワーク`
- `kdeck` -> `ケーデック`
- `AIxSNS` -> `エーアイエックス エスエヌエス`
- `VOICEVOX` -> `ボイスボックス`

## Files

- Normalizer: `backend/tts_normalizer.py`
- Shared dictionary: `config/tts_pronunciation.json`
- Tests: `tests/test_tts_normalizer.py`

## How Other Products Use It

`kuragevp` and `kvtuber` import the normalizer from:

```bash
/home/kojima/work/kurage/backend
```

The path can be overridden with:

```bash
KURAGE_TTS_NORMALIZER_DIR=/path/to/kurage/backend
KURAGE_TTS_DICTIONARY=/path/to/tts_pronunciation.json
```

## Verify

```bash
cd /home/kojima/work/kurage
python3 backend/tts_normalizer.py 'Kurage AI VTuberがVWorkとkdeckでAIxSNSへ投稿。2026年のVOICEVOX対策' --json
python3 -m pytest -q tests/test_tts_normalizer.py
```

## Rule

When a new Kurage product name, OSS name, customer product name, or technical acronym is added, update `config/tts_pronunciation.json` first. Do not hard-code separate pronunciation fixes in each product unless it is a temporary fallback.
