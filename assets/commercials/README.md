# Kurage commercial outro

`kfreqai-trade-short.mp4` is the canonical 15-second Kurage FreqAI Trade
commercial appended by `backend.video_gen.generate_video()`.

`kurage_shorts_outro.mp4` is the 8-second channel outro (Kurageキャラの
高評価・チャンネル登録・コメント誘導, produced by the kliveportrait
pipeline) appended AFTER the commercial.

The outros are shared by Kurage Montage, Kurage Montage News, Kurage Horizon,
and Kurage Entertainment because those pipelines all finish through the same
video generator. Set `KURAGE_COMMERCIAL_OUTRO_ENABLED=0` /
`KURAGE_CHANNEL_OUTRO_ENABLED=0` only for isolated render tests that must
omit them.
