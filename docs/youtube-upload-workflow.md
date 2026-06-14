# Kurage / VWork YouTube Upload Workflow

Kurage/Horizonで生成した動画を、YouTubeへ投稿してAIxSNSで告知するための手順。

kdeck.phpでスマホから作業するとき、作業フォルダが `/home/kojima/work/kurage` の場合は、このファイルを最初に読む。

## 重要

- YouTube投稿ツール本体は `airadio-scripted-mv` リポジトリにある。
- 認証ファイルはGitに入れない。
- YouTube投稿は、`token.json` があるサーバで実行する。
- `token.json` が失効している場合だけ、`youtube_auth_paste.py` で再認証する。
- 投稿後はKurage job JSONに `youtube_url` と `youtube_video_id` を保存する。
- 最後にAIxSNSへ `author=kurage` で告知する。

## 使うツール

このサーバで実行する場合:

```bash
cd /home/kojima/work/airadio-scripted-mv
python3 -m pip install --user -r tools/youtube/requirements.txt
```

主なファイル:

```text
/home/kojima/work/airadio-scripted-mv/tools/youtube/upload_youtube.py
/home/kojima/work/airadio-scripted-mv/tools/youtube/set_thumbnail.py
/home/kojima/work/airadio-scripted-mv/tools/youtube/youtube_auth_paste.py
/home/kojima/work/airadio-scripted-mv/storage/youtube/oauth_client.json
/home/kojima/work/airadio-scripted-mv/storage/youtube/token.json
```

別のYouTube投稿サーバで実行する場合:

```bash
ssh -p 2222 kojima@exbridge.ddns.net
cd /home/kojima/exdirect/airadio-scripted-mv
```

## 1. 動画jobを確認する

```bash
JOB_ID="..."

jq '{title,status,video_file,thumbnail_file,youtube_url,youtube_video_id}' \
  "/home/kojima/work/kurage/storage/jobs/${JOB_ID}.json"
```

標準ファイル:

```text
/home/kojima/work/kurage/storage/jobs/{job_id}/output.mp4
/home/kojima/work/kurage/storage/jobs/{job_id}/thumbnail.jpg
/home/kojima/work/kurage/storage/jobs/{job_id}.json
```

別サーバの場合は `/home/kojima/exdirect/kurage/storage/jobs/...` を使う。

Horizon動画ページ:

```bash
HORIZON_URL="https://aiknowledgecms.exbridge.jp/horizonv.php?id=${JOB_ID}"
```

VWork記事から生成した場合:

```bash
ARTICLE_URL="https://katsushi2441.github.io/vwork/..."
```

## 2. YouTubeへ投稿する

```bash
cd /home/kojima/work/airadio-scripted-mv

python3 tools/youtube/upload_youtube.py \
  "/home/kojima/work/kurage/storage/jobs/${JOB_ID}/output.mp4" \
  --title "$TITLE" \
  --description "Kurage Horizonで生成した動画です。

元記事:
${ARTICLE_URL}

Horizon動画ページ:
${HORIZON_URL}

VWork:
https://katsushi2441.github.io/vwork/

株式会社エクスブリッジ:
https://exbridge.jp/" \
  --tags "AI,Kurage,Horizon,VWork,Codex,バイブコーディング" \
  --privacy public \
  --json-out "storage/youtube/horizon_${JOB_ID}_response.json"
```

サムネイルは設定しない（サムネ設定は機能しないため省略）。

動画IDを取得する。

```bash
VIDEO_ID="$(jq -r '.id' "storage/youtube/horizon_${JOB_ID}_response.json")"
YOUTUBE_URL="https://youtu.be/${VIDEO_ID}"
```

## 3. 認証で失敗した場合

`invalid_grant` や `token has been expired or revoked` の場合は、refresh tokenが失効している。

```bash
cd /home/kojima/work/airadio-scripted-mv
python3 tools/youtube/youtube_auth_paste.py
```

表示されたGoogle認証URLをブラウザで開く。最後に飛ばされた `http://localhost:8089/?code=...` のURL全体を、ターミナルの `redirect URL>` に貼る。

成功すると以下が更新される。

```text
storage/youtube/token.json
```

`access_denied` の場合は、Google Cloud ConsoleのOAuthテストユーザーに投稿用Googleアカウントが入っているか確認する。

投稿先チャンネルが違う場合は、`token.json` を退避して正しいGoogleアカウントで再認証する。

## 4. Kurage job JSONへYouTube URLを保存する

```bash
TMP="$(mktemp)"

jq \
  --arg youtube_url "$YOUTUBE_URL" \
  --arg youtube_video_id "$VIDEO_ID" \
  --arg uploaded_at "$(date '+%Y-%m-%d %H:%M:%S')" \
  '.youtube_url=$youtube_url
   | .youtube_video_id=$youtube_video_id
   | .youtube_uploaded_at=$uploaded_at' \
  "/home/kojima/work/kurage/storage/jobs/${JOB_ID}.json" > "$TMP"

mv "$TMP" "/home/kojima/work/kurage/storage/jobs/${JOB_ID}.json"
```

別サーバの場合は `/home/kojima/exdirect/kurage/storage/jobs/${JOB_ID}.json` を更新する。

## 5. AIxSNSで告知する

```bash
curl -sS -X POST 'https://aixec.exbridge.jp/api.php?path=posts' \
  -H 'Content-Type: application/json' \
  -d @- <<JSON
{
  "author": "kurage",
  "content": "Kurage Horizonで新しい動画をYouTubeに公開しました。\\n\\n${TITLE}\\n\\nYouTube:\\n${YOUTUBE_URL}\\n\\nHorizon動画ページ:\\n${HORIZON_URL}\\n\\n元記事:\\n${ARTICLE_URL}"
}
JSON
```

## 6. 最終確認

```bash
curl -L --max-time 20 -s -o /tmp/article.html -w "article:%{http_code}\n" "$ARTICLE_URL"
curl -L --max-time 20 -s -o /tmp/horizon.html -w "horizon:%{http_code}\n" "$HORIZON_URL"
curl -L --max-time 20 -s -o /tmp/youtube.html -w "youtube:%{http_code}\n" "$YOUTUBE_URL"
curl -L --max-time 20 -s -o /tmp/sns.html -w "sns:%{http_code}\n" "$AIXSNS_URL"
```

## kdeckから依頼されたときの短縮ルール

1. YouTube投稿、サムネ設定、AIxSNS告知を頼まれたら、このファイルを読む。
2. YouTube認証で止まったら、勝手に別方式へ変えず `youtube_auth_paste.py` のURL貼り付け方式を使う。
3. 投稿できたら `YOUTUBE_URL` をKurage job JSONへ保存する。
4. 最後はVWork、Horizon、YouTube、AIxSNSのURLを確認する。
