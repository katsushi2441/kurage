<?php
/**
 * kuragev.php — Kurage 動画ビューワー
 * Default: card list (white bg, ustoryv.php style)
 * Toggle: fullscreen reel viewer
 * Detail: ?id=JOB_ID
 */
date_default_timezone_set('Asia/Tokyo');

$KURAGE_API = 'http://exbridge.ddns.net:18200';
$BASE_URL   = 'https://aiknowledgecms.exbridge.jp';
$THIS_FILE  = 'kuragev.php';
$SITE_NAME  = 'Kurage';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ── 動画プロキシ ────────────────────────────────────── */
if (isset($_GET['proxy']) && $_GET['proxy'] === 'video' && !empty($_GET['job_id'])) {
    $jid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['job_id']);
    $ch = curl_init($KURAGE_API . '/video/' . $jid);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($data && $code === 200) {
        header('Content-Type: video/mp4');
        header('Content-Disposition: inline; filename="kurage_' . $jid . '.mp4"');
        echo $data;
    } else {
        http_response_code(404);
    }
    exit;
}

/* ── API ヘルパー ────────────────────────────────────── */
function kurage_get($path, $timeout = 15) {
    global $KURAGE_API;
    $ch = curl_init($KURAGE_API . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$res || $code >= 400) return null;
    return json_decode($res, true);
}

/* ── 詳細ページ (?id=JOB_ID) ─────────────────────────── */
$detail_id  = isset($_GET['id']) ? preg_replace('/[^a-zA-Z0-9]/', '', $_GET['id']) : '';
$detail_job = null;
if ($detail_id) {
    $detail_job = kurage_get('/status/' . $detail_id);
}

/* ── 一覧データ（詳細以外） ──────────────────────────── */
$videos = [];
if (!$detail_id) {
    $jobs_res = kurage_get('/jobs?limit=100');
    $all_jobs = (!empty($jobs_res['jobs'])) ? $jobs_res['jobs'] : [];

    /* done のみ、tweet_url ごとに最新1件 */
    $seen = [];
    foreach ($all_jobs as $j) {
        if (($j['status'] ?? '') !== 'done') continue;
        $key = !empty($j['tweet_url']) ? $j['tweet_url'] : ('_' . $j['job_id']);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $videos[] = $j;
    }
}

/* ── SEO ─────────────────────────────────────────────── */
if ($detail_job) {
    $page_title = ($detail_job['title'] ?? 'Kurage動画') . ' | ' . $SITE_NAME;
    $page_desc  = mb_substr(str_replace("\n", ' ', $detail_job['tweet_text'] ?? ''), 0, 160);
    $page_url   = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_id);
} else {
    $page_title = $SITE_NAME . ' — AIが作るXショート動画';
    $page_desc  = 'Xの投稿をAIが短編縦型動画に自動生成。';
    $page_url   = $BASE_URL . '/' . $THIS_FILE;
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($page_title); ?></title>
<meta name="description" content="<?php echo h($page_desc); ?>">
<meta name="keywords" content="AI動画生成,ショート動画,縦型動画,X,Twitter,自動生成,Kurage,クラゲ,AI">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?php echo h($page_url); ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?php echo h($page_title); ?>">
<meta property="og:description" content="<?php echo h($page_desc); ?>">
<meta property="og:url" content="<?php echo h($page_url); ?>">
<meta property="og:site_name" content="<?php echo h($SITE_NAME); ?>">
<meta property="og:locale" content="ja_JP">
<meta property="og:image" content="<?php echo $BASE_URL; ?>/images/kurage.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Kurage — AIが作るXショート動画">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:site" content="@xb_bittensor">
<meta name="twitter:title" content="<?php echo h($page_title); ?>">
<meta name="twitter:description" content="<?php echo h($page_desc); ?>">
<meta name="twitter:image" content="<?php echo $BASE_URL; ?>/images/kurage.png">
<script type="application/ld+json">
<?php
$jsonld = array(
    '@context'    => 'https://schema.org',
    '@type'       => $detail_job ? 'VideoObject' : 'CollectionPage',
    'name'        => $page_title,
    'description' => $page_desc,
    'url'         => $page_url,
    'publisher'   => array('@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/'),
);
if ($detail_job) {
    $jsonld['thumbnailUrl'] = $BASE_URL . '/images/kurage.png';
    if (!empty($detail_job['created_at'])) { $jsonld['uploadDate'] = $detail_job['created_at']; }
}
echo json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
</script>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-BP0650KDFR');</script>
<script>
(function(){
    var s=document.createElement('script');
    s.src='https://aiknowledgecms.exbridge.jp/simpletrack.php'
        +'?url='+encodeURIComponent(location.href)
        +'&ref='+encodeURIComponent(document.referrer);
    document.head.appendChild(s);
})();
</script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#fff;color:#222;font-family:-apple-system,'Helvetica Neue',sans-serif;font-size:14px;}

/* ── ヘッダー ── */
.header{background:#fff;border-bottom:1px solid #e5e7eb;padding:14px 20px;position:sticky;top:0;z-index:100;display:flex;align-items:center;gap:10px;}
.header h1{font-size:17px;font-weight:700;color:#111;}
.header h1 a{text-decoration:none;color:inherit;}
.badge{background:#007f96;color:#fff;font-size:11px;padding:2px 8px;border-radius:10px;flex-shrink:0;}
.back-btn{margin-left:auto;font-size:13px;color:#007f96;text-decoration:none;padding:5px 12px;border:1px solid #007f96;border-radius:6px;}
.back-btn:hover{background:#e0f5f8;}
.header-right{margin-left:auto;display:flex;align-items:center;gap:8px;}
.reel-btn{background:#007f96;color:#fff;font-size:12px;padding:5px 14px;border:none;border-radius:6px;cursor:pointer;white-space:nowrap;}
.reel-btn:hover{background:#006880;}
.gen-link{font-size:12px;color:#007f96;text-decoration:none;padding:5px 12px;border:1px solid #007f96;border-radius:6px;white-space:nowrap;}
.gen-link:hover{background:#e0f5f8;}

/* ── コンテナ ── */
.container{max-width:640px;margin:0 auto;padding:0 0 80px;}
.count-bar{padding:10px 20px;font-size:13px;color:#888;border-bottom:1px solid #f0f0f0;}

/* ── カード ── */
.post-card{border-bottom:1px solid #f0f0f0;padding:20px;transition:background .15s;}
.post-card:hover{background:#fafafa;}
.post-meta{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.avatar{width:40px;height:40px;background:linear-gradient(135deg,#007f96,#00bcd4);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;color:#fff;flex-shrink:0;}
.post-title{font-weight:700;color:#111;font-size:14px;margin-bottom:2px;}
.post-author{color:#888;font-size:12px;}
.post-time{color:#aaa;font-size:12px;margin-left:auto;white-space:nowrap;}
.tweet-block{background:#e8f8fb;border-left:3px solid #007f96;border-radius:0 8px 8px 0;padding:10px 14px;margin-bottom:10px;font-size:13px;line-height:1.7;color:#444;white-space:pre-wrap;max-height:72px;overflow:hidden;position:relative;}
.tweet-block::after{content:'';position:absolute;bottom:0;left:0;right:0;height:24px;background:linear-gradient(transparent,#e8f8fb);pointer-events:none;}
.card-links{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.card-video-wrap{position:relative;width:80px;height:142px;flex-shrink:0;border-radius:8px;overflow:hidden;background:#000;cursor:pointer;}
.card-video-wrap video{width:100%;height:100%;object-fit:cover;display:block;}
.card-video-play{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.35);font-size:22px;transition:background .15s;}
.card-video-wrap:hover .card-video-play{background:rgba(0,0,0,.15);}
.card-video-wrap.playing .card-video-play{display:none;}
.card-content{flex:1;min-width:0;}
.kv-link{display:inline-flex;align-items:center;gap:5px;background:#f5f5f5;border:1px solid #e5e7eb;border-radius:8px;padding:5px 11px;text-decoration:none;color:#555;font-size:12px;cursor:pointer;transition:all .15s;font-family:inherit;}
.kv-link:hover{background:#e0f5f8;border-color:#007f96;color:#007f96;}
.kv-link.primary{background:#e0f5f8;border-color:#b2dde8;color:#007f96;}
.kv-link.primary:hover{background:#c4e8f0;}

/* ── 空の状態 ── */
.empty{text-align:center;color:#bbb;padding:80px 20px;font-size:15px;}
.empty a{color:#007f96;text-decoration:none;}

/* ── 詳細ページ ── */
.detail-header{padding:24px 20px 16px;border-bottom:1px solid #f0f0f0;}
.detail-meta{font-size:13px;color:#888;display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px;}
.detail-body{padding:20px;}
.section-title{font-size:12px;font-weight:700;color:#007f96;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;margin-top:20px;}
.tweet-body{background:#e8f8fb;border-left:3px solid #007f96;border-radius:0 8px 8px 0;padding:14px 16px;font-size:14px;line-height:1.8;color:#333;white-space:pre-wrap;margin-bottom:8px;}
.video-wrap{width:100%;max-width:320px;margin:0 auto 4px;background:#000;border-radius:12px;overflow:hidden;aspect-ratio:9/16;}
.video-wrap video{width:100%;height:100%;object-fit:contain;display:block;}
.scene-list{display:flex;flex-direction:column;gap:8px;}
.scene-row{background:#e8f8fb;border-radius:8px;padding:10px 14px;}
.scene-idx{font-size:11px;color:#007f96;font-weight:700;margin-bottom:4px;}
.narration{font-size:14px;color:#222;line-height:1.5;margin-bottom:4px;}
.image-prompt{font-size:11px;color:#999;font-style:italic;}
.detail-url-box{background:#f7f7f7;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#555;word-break:break-all;}
.detail-url-box a{color:#007f96;}
.action-row{margin-top:20px;display:flex;gap:8px;flex-wrap:wrap;}
.btn-primary{background:#007f96;color:#fff;border:none;border-radius:8px;padding:10px 20px;font-size:14px;cursor:pointer;font-family:inherit;}
.btn-primary:hover{background:#006880;}

/* ── リールオーバーレイ ── */
.reel-overlay{display:none;position:fixed;inset:0;z-index:500;background:#000;}
.reel-overlay.open{display:flex;align-items:stretch;justify-content:center;}
.reel-close{position:fixed;top:14px;right:14px;z-index:600;background:rgba(0,0,0,.6);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:20px;padding:7px 16px;font-size:13px;cursor:pointer;backdrop-filter:blur(4px);}
.reel-feed{width:100%;max-width:420px;height:100dvh;overflow-y:scroll;scroll-snap-type:y mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none;}
.reel-feed::-webkit-scrollbar{display:none;}
.reel-slide{position:relative;height:100dvh;scroll-snap-align:start;background:#000;display:flex;align-items:center;justify-content:center;overflow:hidden;}
.reel-slide video{width:100%;height:100%;object-fit:contain;display:block;}
.reel-grad{position:absolute;inset:0;background:linear-gradient(to bottom,rgba(0,0,0,.2) 0%,transparent 30%,transparent 55%,rgba(0,0,0,.75) 100%);pointer-events:none;}
.reel-info{position:absolute;bottom:0;left:0;right:60px;padding:12px 14px calc(env(safe-area-inset-bottom,0px) + 44px);}
.reel-title{font-size:14px;font-weight:700;color:#fff;margin-bottom:4px;line-height:1.4;}
.reel-author{font-size:12px;color:rgba(255,255,255,.6);}
.reel-side{position:absolute;right:8px;bottom:calc(env(safe-area-inset-bottom,0px) + 48px);display:flex;flex-direction:column;gap:12px;align-items:center;}
.reel-side-btn{background:rgba(0,0,0,.5);border:none;border-radius:50%;width:44px;height:44px;color:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:1px;backdrop-filter:blur(4px);text-decoration:none;}
.reel-side-btn span{font-size:9px;color:rgba(255,255,255,.65);}
</style>
</head>
<body>

<!-- ── ヘッダー ── -->
<div class="header">
  <div style="font-size:22px">🪼</div>
  <?php if ($detail_job): ?>
  <h1><a href="<?php echo h($THIS_FILE); ?>">Kurage</a></h1>
  <span class="badge">AI動画</span>
  <a class="back-btn" href="<?php echo h($THIS_FILE); ?>">← 一覧</a>
  <?php else: ?>
  <h1><a href="kuragev.php" style="text-decoration:none;color:inherit;">Kurage</a></h1>
  <span class="badge">AI動画</span>
  <div class="header-right">
    <?php if (!empty($videos)): ?>
    <button class="reel-btn" onclick="openReel(0)">🎬 リール表示</button>
    <?php endif; ?>
    <a href="kurage.php" class="gen-link">＋ 生成</a>
  </div>
  <?php endif; ?>
</div>

<?php if ($detail_job): ?>
<!-- ============ 詳細ページ ============ -->
<div class="container">
  <div class="detail-header">
    <div class="detail-meta">
      <span><?php echo h($detail_job['created_at'] ?? ''); ?></span>
      <span><?php echo h($detail_job['created_at'] ?? ''); ?></span>
    </div>
    <?php if (!empty($detail_job['tweet_url'])): ?>
    <div class="detail-url-box">
      元のXの投稿:
      <a href="<?php echo h($detail_job['tweet_url']); ?>" target="_blank" rel="noopener"><?php echo h($detail_job['tweet_url']); ?></a>
    </div>
    <?php endif; ?>
  </div>
  <div class="detail-body">

    <div class="video-wrap">
      <video src="<?php echo h($THIS_FILE . '?proxy=video&job_id=' . urlencode($detail_id)); ?>"
             controls playsinline preload="metadata"></video>
    </div>

    <?php if (!empty($detail_job['tweet_text'])): ?>
    <div class="section-title">📣 元のXの投稿</div>
    <div class="tweet-body"><?php echo h($detail_job['tweet_text']); ?></div>
    <?php endif; ?>

    <?php
    $scenes = (!empty($detail_job['script']['scenes'])) ? $detail_job['script']['scenes'] : [];
    if ($scenes):
    ?>
    <div class="section-title">🎬 脚本</div>
    <div class="scene-list">
      <?php foreach ($scenes as $si => $sc): ?>
      <div class="scene-row">
        <div class="scene-idx">シーン <?php echo $si + 1; ?></div>
        <div class="narration"><?php echo h($sc['narration'] ?? ''); ?></div>
        <div class="image-prompt"><?php echo h($sc['image_prompt'] ?? ''); ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php
    $share_url_d = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_id);
    $copy_d      = ($detail_job['title'] ?? '') . "\n\n" . ($detail_job['tweet_text'] ?? '') . "\n\n" . $share_url_d . "\n#Kurage #AI動画";
    $x_text_d    = urlencode(($detail_job['title'] ?? 'Kurage動画') . "\n\n" . $share_url_d . "\n#Kurage #AI動画");
    ?>
    <div class="action-row">
      <button id="detail-copy-btn" class="btn-primary"
              onclick="kvCopyBtn(this, <?php echo json_encode($copy_d, JSON_UNESCAPED_UNICODE); ?>)">📋 コピー</button>
      <a class="kv-link" href="https://twitter.com/intent/tweet?text=<?php echo $x_text_d; ?>"
         target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" style="width:13px;height:13px;fill:currentColor;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
        Xに投稿
      </a>
      <?php if (!empty($detail_job['tweet_url'])): ?>
      <a class="kv-link" href="<?php echo h($detail_job['tweet_url']); ?>" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" style="width:13px;height:13px;fill:currentColor;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
        元の投稿を開く
      </a>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php else: ?>
<!-- ============ 一覧ページ ============ -->
<div class="container">
  <div class="count-bar"><?php echo count($videos); ?> 件の動画</div>
  <div id="post-list"></div>
  <div id="load-sentinel" style="height:1px;"></div>
  <div id="load-indicator" style="display:none;text-align:center;padding:16px;font-size:13px;color:#888;">読み込み中…</div>
</div>

<?php if (!empty($videos)): ?>
<!-- ============ リールオーバーレイ ============ -->
<div class="reel-overlay" id="reel-overlay">
  <button class="reel-close" onclick="closeReel()">✕ 一覧に戻る</button>
  <div class="reel-feed" id="reel-feed">
    <?php foreach ($videos as $ri => $v): ?>
    <?php
      $r_vid    = h($THIS_FILE . '?proxy=video&job_id=' . urlencode($v['job_id']));
      $r_title  = h($v['title'] ?? '(無題)');
      $r_author = h($v['tweet_author'] ?? '');
      $r_share  = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($v['job_id']);
      $r_xtext  = urlencode(($v['title'] ?? 'Kurage動画') . "\n\n" . $r_share . "\n#Kurage #AI動画");
      $r_copy   = h(($v['title'] ?? '') . "\n\n" . ($v['tweet_text'] ?? '') . "\n\n" . $r_share . "\n#Kurage #AI動画");
    ?>
    <div class="reel-slide" data-job="<?php echo h($v['job_id']); ?>">
      <video src="<?php echo $r_vid; ?>"
             playsinline muted loop preload="<?php echo $ri === 0 ? 'metadata' : 'none'; ?>"></video>
      <div class="reel-grad"></div>
      <div class="reel-info">
        <div class="reel-title"><?php echo $r_title; ?></div>
        </div>
      <div class="reel-side">
        <button class="reel-side-btn reel-mute-btn" onclick="reelMuteToggle()">🔇<span>音声</span></button>
        <button class="reel-side-btn reel-copy-btn" data-text="<?php echo $r_copy; ?>">📋<span>コピー</span></button>
        <a class="reel-side-btn" style="text-decoration:none;"
           href="https://twitter.com/intent/tweet?text=<?php echo $r_xtext; ?>"
           target="_blank" rel="noopener">𝕏<span>投稿</span></a>
        <a class="reel-side-btn" style="text-decoration:none;"
           href="<?php echo h($THIS_FILE . '?id=' . urlencode($v['job_id'])); ?>">📄<span>詳細</span></a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<script>
/* ──────────────────────────────────────────
   一覧データ（PHPから）
────────────────────────────────────────── */
var kvVideos = <?php echo json_encode(array_values($videos), JSON_UNESCAPED_UNICODE); ?>;
var PAGE_SIZE = 20;
var curPage = 0;

var X_SVG = '<svg viewBox="0 0 24 24" style="width:13px;height:13px;fill:currentColor;vertical-align:middle;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>';

function primeThumbVideos(root) {
    (root || document).querySelectorAll('video.thumb-video').forEach(function(v) {
        if (v.dataset.thumbReady) return;
        v.dataset.thumbReady = '1';
        v.muted = true;
        v.addEventListener('loadedmetadata', function() {
            try {
                var t = Math.min(1, Math.max(0, (v.duration || 2) - 0.1));
                if (isFinite(t)) v.currentTime = t;
            } catch (e) {}
        }, { once: true });
        v.addEventListener('seeked', function() { v.pause(); }, { once: true });
    });
}

function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderCards(from, to) {
    var list = document.getElementById('post-list');
    if (!list) return;
    for (var i = from; i < to && i < kvVideos.length; i++) {
        var v      = kvVideos[i];
        var jid    = v.job_id     || '';
        var title  = v.title      || '(無題)';
        var author = v.tweet_author || '';
        var tweet  = v.tweet_text || '';
        var turl   = v.tweet_url  || '';
        var date   = v.created_at || '';
        var av     = author ? author.replace(/^@/, '').charAt(0).toUpperCase() : '🪼';

        var shareUrl  = '<?php echo $BASE_URL . '/' . $THIS_FILE; ?>?id=' + encodeURIComponent(jid);
        var copyText  = title + '\n\n' + tweet + '\n\n' + shareUrl + '\n#Kurage #AI動画';
        var xText     = encodeURIComponent(title + '\n\n' + shareUrl + '\n#Kurage #AI動画');

        var tweetHtml = tweet
            ? '<div class="tweet-block">' + esc(tweet) + '</div>'
            : '';

        var turlBtn = turl
            ? '<a class="kv-link" href="' + esc(turl) + '" target="_blank" rel="noopener">' + X_SVG + '&nbsp;元の投稿</a>'
            : '';

        var videoSrc = 'kuragev.php?proxy=video&job_id=' + encodeURIComponent(jid);
        var html = '<div class="post-card">'
            + '<div style="display:flex;gap:12px;align-items:flex-start;">'
            + '<div class="card-video-wrap" data-jid="' + esc(jid) + '">'
            + '<video class="thumb-video" src="' + videoSrc + '" playsinline muted preload="metadata" loop></video>'
            + '<div class="card-video-play">▶</div>'
            + '</div>'
            + '<div class="card-content">'
            + '<div class="post-meta" style="margin-bottom:6px;">'
            + '<div class="post-title">' + esc(title) + '</div>'
            + '<div class="post-time">' + esc(date) + '</div>'
            + '</div>'
            + tweetHtml
            + '<div class="card-links">'
            + '<a class="kv-link primary" href="kuragev.php?id=' + encodeURIComponent(jid) + '">📄 詳細</a>'
            + '<button class="kv-link reel-open-btn" data-idx="' + i + '">🎬 リール</button>'
            + '<button class="kv-link kv-copy-btn" data-text="' + esc(copyText) + '">📋 コピー</button>'
            + '<a class="kv-link" href="https://twitter.com/intent/tweet?text=' + xText + '" target="_blank" rel="noopener">' + X_SVG + '&nbsp;Xに投稿</a>'
            + turlBtn
            + '</div>'
            + '</div>'
            + '</div>'
            + '</div>';
        list.insertAdjacentHTML('beforeend', html);
    }
    curPage++;
    primeThumbVideos(list);
}

/* イベント委任（コピー・リール・カード動画再生） */
document.addEventListener('click', function(e) {
    var vwrap = e.target.closest('.card-video-wrap');
    if (vwrap) {
        var vid = vwrap.querySelector('video');
        if (!vid) return;
        if (vid.paused) {
            vid.play();
            vwrap.classList.add('playing');
        } else {
            vid.pause();
            vwrap.classList.remove('playing');
        }
        return;
    }
    var copyBtn = e.target.closest('.kv-copy-btn');
    if (copyBtn) {
        var text = copyBtn.dataset.text || '';
        navigator.clipboard.writeText(text).then(function() {
            copyBtn.textContent = '✓ コピー済';
            setTimeout(function() { copyBtn.textContent = '📋 コピー'; }, 2000);
        });
        return;
    }
    var reelBtn = e.target.closest('.reel-open-btn');
    if (reelBtn) {
        openReel(parseInt(reelBtn.dataset.idx || '0', 10));
    }
});

/* 無限スクロール */
var sentinel = document.getElementById('load-sentinel');
if (sentinel) {
    var obs = new IntersectionObserver(function(entries) {
        if (entries[0].isIntersecting) {
            var from = curPage * PAGE_SIZE;
            if (from < kvVideos.length) {
                renderCards(from, from + PAGE_SIZE);
            }
        }
    }, { rootMargin: '200px' });
    obs.observe(sentinel);
}

if (kvVideos.length === 0) {
    var pl = document.getElementById('post-list');
    if (pl) {
        pl.innerHTML = '<div class="empty">まだ動画がありません。<br><br><a href="kurage.php">最初の動画を生成する →</a></div>';
    }
} else {
    renderCards(0, PAGE_SIZE);
}

/* ── コピーヘルパー（詳細ページ用） ── */
function kvCopyBtn(btn, text) {
    navigator.clipboard.writeText(text).then(function() {
        var orig = btn.textContent;
        btn.textContent = '✓ コピー済';
        btn.style.background = '#059669';
        setTimeout(function() { btn.textContent = orig; btn.style.background = '#007f96'; }, 2000);
    });
}

/* ──────────────────────────────────────────
   リールオーバーレイ
────────────────────────────────────────── */
var reelMuted   = true;
var reelCurrent = 0;
var reelSlides  = [];
var reelObs     = null;
var reelTimers  = new WeakMap();
var reelReady   = false;

function openReel(idx) {
    var overlay = document.getElementById('reel-overlay');
    if (!overlay) return;
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';

    if (!reelReady) {
        reelReady  = true;
        reelSlides = Array.from(overlay.querySelectorAll('.reel-slide'));

        /* コピーボタン */
        overlay.querySelectorAll('.reel-copy-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var text = btn.dataset.text || '';
                navigator.clipboard.writeText(text).then(function() {
                    btn.innerHTML = '✓<span>コピー済</span>';
                    setTimeout(function() { btn.innerHTML = '📋<span>コピー</span>'; }, 2000);
                });
            });
        });

        /* 自動再生 */
        var feed = document.getElementById('reel-feed');
        reelObs = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                var vid = entry.target.querySelector('video');
                if (!vid) return;
                if (entry.isIntersecting) {
                    reelCurrent = reelSlides.indexOf(entry.target);
                    var t = setTimeout(function() {
                        vid.muted = reelMuted;
                        vid.currentTime = 0;
                        vid.play().catch(function() { vid.muted = true; reelMuted = true; vid.play(); });
                    }, 500);
                    reelTimers.set(entry.target, t);
                } else {
                    var t2 = reelTimers.get(entry.target);
                    if (t2) { clearTimeout(t2); reelTimers.delete(entry.target); }
                    vid.pause();
                    vid.currentTime = 0;
                }
            });
        }, { root: feed, threshold: 0.75 });
        reelSlides.forEach(function(s) { reelObs.observe(s); });
    }

    if (idx >= 0 && idx < reelSlides.length) {
        setTimeout(function() {
            reelSlides[idx].scrollIntoView({ behavior: 'instant', block: 'start' });
        }, 50);
    }
}

function closeReel() {
    var overlay = document.getElementById('reel-overlay');
    if (!overlay) return;
    overlay.classList.remove('open');
    document.body.style.overflow = '';
    overlay.querySelectorAll('video').forEach(function(v) { v.pause(); v.currentTime = 0; });
}

function reelMuteToggle() {
    reelMuted = !reelMuted;
    var overlay = document.getElementById('reel-overlay');
    if (overlay) {
        overlay.querySelectorAll('video').forEach(function(v) { v.muted = reelMuted; });
        overlay.querySelectorAll('.reel-mute-btn').forEach(function(btn) {
            btn.innerHTML = (reelMuted ? '🔇' : '🔊') + '<span>音声</span>';
        });
    }
}

/* キーボード */
document.addEventListener('keydown', function(e) {
    var overlay = document.getElementById('reel-overlay');
    if (!overlay || !overlay.classList.contains('open')) return;
    if (e.key === 'Escape') { closeReel(); return; }
    if (e.key === 'ArrowDown' && reelSlides.length) {
        var next = reelSlides[reelCurrent + 1];
        if (next) next.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    if (e.key === 'ArrowUp' && reelSlides.length) {
        var prev = reelSlides[reelCurrent - 1];
        if (prev) prev.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});

/* ?v= で直接リール表示 */
(function() {
    var params = new URLSearchParams(location.search);
    var v = params.get('v');
    if (!v) return;
    var overlay = document.getElementById('reel-overlay');
    if (!overlay) return;
    var slides = overlay.querySelectorAll('.reel-slide');
    var idx = -1;
    slides.forEach(function(s, i) { if (s.dataset.job === v) idx = i; });
    if (idx >= 0) setTimeout(function() { openReel(idx); }, 200);
})();
</script>
</body>
</html>
