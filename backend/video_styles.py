"""Video style presets inspired by AI video prompt-engineering skills.

These presets do not call external video generators directly. They translate
the practical parts of those skills into Kurage's script/image prompt pipeline:
hook design, camera vocabulary, lighting, motion, and sound notes.
"""
from __future__ import annotations

from copy import deepcopy


STYLE_PRESETS: dict[str, dict] = {
    "faceless_documentary": {
        "label": "Faceless Documentary",
        "best_for": "news, geopolitics, OSINT, business explainers, urban documentary",
        "system": (
            "Use a faceless documentary style. The first 2 seconds must work as a "
            "thumbnail: visual metaphor, data reveal, or pull-back reveal. Every "
            "scene needs visible motion. Use cinematic b-roll, documentary "
            "reconstruction, shallow depth of field, negative space for captions, "
            "teal-orange or desaturated color, and narration-first pacing."
        ),
        "image_suffixes": [
            "slow push-in, shallow depth of field, teal orange grade, negative space, narration-first",
            "data reveal, floating numbers, parallax layers, crisp caption-safe center",
            "documentary reconstruction, practical light, soft shadows, slow lateral drift",
            "pull-back reveal, atmospheric haze, rule of thirds, film grain",
        ],
    },
    "ai_avatar_explainer": {
        "label": "AI Avatar Explainer",
        "best_for": "VTuber, AI presenter, product education, synthetic spokesperson",
        "system": (
            "Use an AI avatar explainer style. Treat the Kurage avatar as a virtual "
            "presenter. Open with a direct-address hook or digital-birth moment. "
            "Use a clean virtual studio, bright white or light gradient background, "
            "professional key/fill/rim lighting, centered safe-zone composition, "
            "and concise educational narration."
        ),
        "image_suffixes": [
            "clean virtual studio, light gradient background, presenter safe zone, 5600K key light",
            "floating UI panels, soft rim light, friendly AI explainer atmosphere",
            "white tech studio, subtle data particles, caption-safe lower area",
            "professional educational set, calm motion graphics, bright optimistic lighting",
        ],
    },
    "saas_launch": {
        "label": "SaaS Launch",
        "best_for": "product launch, demos, tool announcement, enterprise software",
        "system": (
            "Use a premium SaaS launch style. Start with a clear product promise in "
            "the first scene. Use Apple-keynote-like minimalism, polished UI panels, "
            "smooth dolly/orbit motion, clean product lighting, white or light neutral "
            "backgrounds, and business-value narration."
        ),
        "image_suffixes": [
            "premium SaaS product launch, white studio, floating UI, smooth dolly",
            "clean dashboard interface, soft shadows, product keynote composition",
            "glassmorphism UI panels, precise grid layout, bright professional lighting",
            "enterprise software reveal, calm orbit camera, high-trust minimal design",
        ],
    },
    "course_promo": {
        "label": "Course Promo",
        "best_for": "seminars, education, coaching, learning roadmap, short lessons",
        "system": (
            "Use a course promo style. Open with a learning outcome, then move from "
            "problem to method to result. Use optimistic classroom/studio visuals, "
            "clear step cards, progress diagrams, energetic but readable motion, "
            "and narration that sells the transformation without hype."
        ),
        "image_suffixes": [
            "modern learning studio, step cards, bright white background, optimistic lighting",
            "course roadmap, progress path, clean Japanese edtech visual, centered composition",
            "before after learning transformation, warm daylight, subtle motion graphics",
            "seminar slide environment, clear hierarchy, caption-safe vertical layout",
        ],
    },
    "podcast_visual": {
        "label": "Podcast Visual",
        "best_for": "voice-first commentary, interview clips, audio essays",
        "system": (
            "Use a podcast visual style. The voice is primary, but never show a static "
            "audiogram. Turn ideas into cinematic metaphor, kinetic typography, sound-wave "
            "light, desk/studio atmosphere, slow camera movement, and synced visual beats."
        ),
        "image_suffixes": [
            "cinematic podcast desk, waveform light, kinetic typography space, warm practical lamp",
            "audio essay visual, floating quote fragments, slow push-in, shallow depth",
            "voice-first studio scene, sound waves as light trails, calm editorial mood",
            "minimal broadcast setup, text rhythm visualized, warm-cool lighting contrast",
        ],
    },
}

DEFAULT_STYLE = "faceless_documentary"


def style_names() -> list[str]:
    return ["auto", *STYLE_PRESETS.keys()]


def resolve_video_style(requested: str | None, *, content_type: str = "", vtuber_mode: bool = False, title: str = "") -> str:
    """Resolve a user supplied style name to a concrete preset."""
    value = (requested or "auto").strip().lower().replace("-", "_")
    if value in STYLE_PRESETS:
        return value
    if value and value != "auto":
        return DEFAULT_STYLE

    text = f"{content_type} {title}".lower()
    if vtuber_mode:
        return "ai_avatar_explainer"
    if any(word in text for word in ["seminar", "course", "セミナー", "講座", "教材", "入門"]):
        return "course_promo"
    if any(word in text for word in ["saas", "product", "プロダクト", "launch", "サービス"]):
        return "saas_launch"
    if any(word in text for word in ["podcast", "音声", "対談", "ラジオ"]):
        return "podcast_visual"
    return DEFAULT_STYLE


def style_prompt(video_style: str | None) -> str:
    preset = STYLE_PRESETS.get(video_style or "", STYLE_PRESETS[DEFAULT_STYLE])
    return (
        "\nVideo direction preset:\n"
        f"- style: {preset['label']}\n"
        f"- best_for: {preset['best_for']}\n"
        f"- direction: {preset['system']}\n"
        "- image_prompt must include concrete camera movement, lighting, mood, and safe-zone composition.\n"
        "- Avoid generic words like beautiful or stunning; describe the exact visual mechanism instead.\n"
    )


def apply_video_style(script: dict, video_style: str | None) -> dict:
    """Attach style metadata and enrich scene image prompts deterministically."""
    style = video_style if video_style in STYLE_PRESETS else DEFAULT_STYLE
    preset = STYLE_PRESETS[style]
    out = deepcopy(script)
    out["video_style"] = style
    out["video_style_label"] = preset["label"]
    scenes = out.get("scenes") or []
    suffixes = preset["image_suffixes"]
    for i, scene in enumerate(scenes):
        prompt = str(scene.get("image_prompt") or "cinematic vertical 9:16").strip()
        suffix = suffixes[i % len(suffixes)]
        if "vertical 9:16" not in prompt.lower():
            prompt = f"{prompt}, vertical 9:16"
        scene["image_prompt"] = _compact_prompt(f"{prompt}, {suffix}")
        scene["hook_pattern"] = _hook_pattern(style, i)
        scene["visual_direction"] = suffix
    return out


def _compact_prompt(value: str, limit: int = 180) -> str:
    value = " ".join(value.replace("\n", " ").split())
    if len(value) <= limit:
        return value
    return value[:limit].rsplit(",", 1)[0].strip() or value[:limit].strip()


def _hook_pattern(style: str, index: int) -> str:
    if index != 0:
        return ""
    return {
        "faceless_documentary": "2-second visual metaphor or data reveal",
        "ai_avatar_explainer": "direct-address avatar hook",
        "saas_launch": "product promise reveal",
        "course_promo": "learning outcome hook",
        "podcast_visual": "voice-first cinematic metaphor",
    }.get(style, "2-second hook")
