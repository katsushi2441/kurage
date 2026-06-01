# Kurage 運用メモ

## Kurage と Horizon の使い分け

ユーザーが「Kurageで生成して」と言った場合は、`kurage.php` の通常生成ルートを使う。

- 画面: `https://aiknowledgecms.exbridge.jp/kurage.php`
- API: `POST /generate`
- 入力: X投稿URL
- 生成内容: X投稿向けの短編動画
- 標準尺: 約30秒
- 標準方式: `ERNIE静止画 + HyperFrames`
- 実験方式: `Wan2.1 AI動画生成`

`generate_from_url` は Kurage 通常生成ではなく、記事URLから動画を作る Horizon 系のルートとして扱う。

- API: `POST /generate_from_url`
- 入力: ブログ記事、ニュース記事などのURL
- 生成内容: 記事解説・ニュース動画
- 標準尺: 約2分
- シーン数: 12シーン
- 表示先: `horizonv.php` または `kuragev.php` でも再生は可能

## 重要

「Kurageで生成して」と言われた時に、記事URLだからといって勝手に `generate_from_url` を使わない。

記事URLをKurage 30秒動画にしたい場合は、X投稿URL向けの `POST /generate` では扱えないため、先にユーザーへ確認するか、Kurage用の30秒記事変換ルートを別途実装してから使う。

今回の誤り:

- 対象URL: `https://katsushi2441.github.io/vwork/episodes/2026-06-01-%E8%97%A4%E7%94%B0%E6%99%8B-ai-episode.html`
- 実行したAPI: `POST /generate_from_url`
- 結果: Horizon系の約2分動画を生成した
- 次回からは「Kurage」は `kurage.php` / `POST /generate` を優先する。

