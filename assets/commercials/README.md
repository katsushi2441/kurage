# Kurage commercial outro

`kfreqai-trade-short.mp4` is the canonical 15-second Kurage FreqAI Trade
commercial appended by `backend.video_gen.generate_video()`.

The outro is shared by Kurage Montage, Kurage Montage News, Kurage Horizon,
and Kurage Entertainment because those pipelines all finish through the same
video generator. Set `KURAGE_COMMERCIAL_OUTRO_ENABLED=0` only for isolated
render tests that must omit the commercial.
