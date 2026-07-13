# LTX 2.3 workflow

Kurage can run the community LTX 2.3 I2V/T2V ComfyUI workflow locally on the
RTX 3090. The first verified path uses the GGUF model to fit the 24 GB card.

## Model roles

- `gemma4:12b-it-qat` on `192.168.0.14`: writes a concise motion prompt from a
  Japanese or English brief.
- Gemma 3 12B GGUF: LTX 2.3's required text encoder. It is not the script-writing
  LLM and cannot be replaced with Gemma 4.
- `ltx-2.3_text_projection_bf16.safetensors`: projects the Gemma 3 embeddings
  into the representation expected by LTX 2.3.
- LTX 2.3 22B distilled Q4 GGUF: generates the video and audio latents.
- LTX video/audio VAEs: decode the generated latents.

The complete path is therefore:

```text
Japanese brief -> Gemma 4 12B -> English motion prompt
              -> Gemma 3 12B text encoder + projection
              -> LTX 2.3 22B GGUF -> video/audio VAEs -> MP4
```

## Local layout

ComfyUI, custom nodes, model weights, the third-party workflow, and generated
media live below ignored `vendor/ComfyUI/` or `storage/ltx23/`. The upstream
workflow is not committed because its attachment does not include an explicit
redistribution license.

Required custom nodes:

- ComfyUI-LTXVideo
- ComfyUI-GGUF
- ComfyUI-KJNodes
- ComfyUI-VideoHelperSuite
- ComfyUI_essentials
- rgthree-comfy

## Reproduce the test

Start ComfyUI:

```bash
scripts/ltx23/run_comfyui.sh
```

Generate the motion prompt with the shared Gemma 4 service:

```bash
scripts/ltx23/generate_motion_prompt.py \
  'Kurage AI VTuberが自然にまばたきし、髪と飾りが軽く揺れる3秒動画' \
  storage/ltx23/gemma4_prompt.txt
```

Prepare a Linux-friendly, low-VRAM test workflow:

```bash
scripts/ltx23/prepare_test_workflow.py \
  storage/ltx23/workflows/LTX-2.3_I2V_T2V_Basic_GGUF_MYUNG.json \
  storage/ltx23/workflows/ltx23_kurage_gemma4_480x832_3s.json \
  --prompt-file storage/ltx23/gemma4_prompt.txt \
  --filename-prefix ltx23_kurage_gemma4
```

Convert it to API format:

```bash
scripts/ltx23/workflow_to_api.py \
  storage/ltx23/workflows/ltx23_kurage_gemma4_480x832_3s.json \
  storage/ltx23/workflows/ltx23_kurage_gemma4_480x832_3s_api.json
```

## Verified result

- GPU: NVIDIA GeForce RTX 3090, 24 GB
- PyTorch: 2.10.0 + CUDA 12.8
- Input: canonical Kurage avatar PNG
- Requested size: 480 x 832; generated size: 448 x 832
- Duration: 3.042 seconds at 24 fps
- Video: H.264, 73 distinct frames
- Audio: AAC stereo, 48 kHz
- Warm execution time: 77.77 seconds
- Motion checked at start/middle/end: stable identity, blinking, smile, hair and
  ornament movement

Verified output:

```text
vendor/ComfyUI/output/ltx23_kurage_gemma4_00001-audio.mp4
```
