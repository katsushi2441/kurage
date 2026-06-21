# Kurage 運用メモ

## Design Rule: White Studio VTuber

Kurage VTuberモード、ブログ動画、YouTube投稿用動画では、黒背景・ダークモード背景を使わない。白系・薄い水色系・明るい紙面系のWhite Studioデザインを基本にする。`background: #000`、黒いタイトルオーバーレイ、黒い半透明字幕箱、暗紺の全面背景は禁止。

## Demo Quality Rule

Kurage/kargov/kvtuberの公開デモ動画では、偽物・やっつけ・中身のない動画を公開しない。

- 実録デモと書くなら、実際のブラウザ操作、viewer、音声を収録する
- セミナー部分は本物のviewer音声を残し、解説ナレーションを被せない
- 前後の解説ナレーションはKurage/Horizon/HyperFrames品質で作る
- 空のサンプル文、偽画面、無音録画、口パクずれ、字幕崩れを公開しない
- 公開前に `ffprobe`、音量確認、目視確認、ページ表示確認を行う
- 実録できない場合は、代用品を出さず、先に生成・録画ワークフローを修正する


## YouTube投稿

Kurage/Horizonで生成した動画をYouTubeへ投稿する場合は、`docs/youtube-upload-workflow.md` を読む。

kdeck.phpでスマホから作業するときも同じ。YouTube投稿ツール本体は `airadio-scripted-mv` リポジトリにあり、認証が失効している場合は `youtube_auth_paste.py` のURL貼り付け方式で再認証する。

## Kurage と Horizon の使い分け

ユーザーが「Kurageで生成して」と言った場合は、`kurage.php` の通常生成ルートを使う。

- 画面: `https://aiknowledgecms.exbridge.jp/kurage.php`
- API: `POST /generate`
- 入力: X投稿URL
- 生成内容: X投稿向けの短編動画
- 標準尺: 約40秒
- 標準構成: 8画像・8シーン・各5秒
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

記事URLをKurage 40秒動画にしたい場合は、X投稿URL向けの `POST /generate` ではそのまま扱えないため、先にユーザーへ確認するか、Kurage用の40秒記事変換ルートを別途実装してから使う。

今回の誤り:

- 対象URL: `https://katsushi2441.github.io/vwork/episodes/2026-06-01-%E8%97%A4%E7%94%B0%E6%99%8B-ai-episode.html`
- 実行したAPI: `POST /generate_from_url`
- 結果: Horizon系の約2分動画を生成した
- 次回からは「Kurage」は `kurage.php` / `POST /generate` を優先する。
