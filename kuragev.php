<?php
/**
 * kuragev.php — Kurage 動画ビューワー
 * Default: card list (white bg, ustoryv.php style)
 * Toggle: fullscreen reel viewer
 * Detail: ?id=JOB_ID
 */
require_once __DIR__ . '/auth_common.php';
date_default_timezone_set('Asia/Tokyo');

$KURAGE_API = 'http://exbridge.ddns.net:18303';
$BASE_URL   = 'https://kurage.exbridge.jp';
$THIS_FILE  = 'kuragev.php';
$ADMIN      = 'xb_bittensor';

$auth         = url2ai_auth_bootstrap();
$logged_in    = $auth['logged_in'];
$session_user = $auth['session_user'];
$is_admin     = $auth['is_admin'];

if (isset($_GET['login']))  { header('Location: ' . url2ai_auth_login_url($BASE_URL . '/' . $THIS_FILE)); exit; }
if (isset($_GET['logout'])) { header('Location: ' . url2ai_auth_logout_url($BASE_URL . '/' . $THIS_FILE)); exit; }
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

// 削除処理（管理者のみ）
if (isset($_POST['delete_job']) && $is_admin) {
    $del_id = preg_replace('/[^a-zA-Z0-9]/', '', (string)$_POST['delete_job']);
    $ch = curl_init($KURAGE_API . '/jobs/' . $del_id);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_exec($ch);
    curl_close($ch);
    header('Location: ' . $THIS_FILE); exit;
}
$SITE_NAME  = 'Kurage';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function is_voice_pro_job($job) {
    return (($job['source'] ?? '') === 'kuragevp') || (($job['content_type'] ?? '') === 'voice_pro_translation') || !empty($job['kuragevp_job_id']);
}

function is_entertainment_job($job) {
    return (($job['source'] ?? '') === 'entertainment')
        || (($job['content_type'] ?? '') === 'entertainment_short')
        || (strpos((string)($job['tweet_url'] ?? ''), '/entertainment.php') !== false);
}

function related_article_url($job) {
    foreach (array('article_url', 'tweet_url') as $key) {
        $url = trim((string)($job[$key] ?? ''));
        if ($url !== '' && strpos($url, '/entertainment.php') !== false) {
            return $url;
        }
    }
    return '';
}

function share_text_for_job($job, $share_url) {
    $title = trim((string)($job['title'] ?? 'Kurage動画'));
    if ($title === '') { $title = 'Kurage動画'; }
    if (is_voice_pro_job($job)) {
        $translated = trim((string)($job['translated_text'] ?? ''));
        if ($translated === '' && !empty($job['script']['scenes']) && is_array($job['script']['scenes'])) {
            $parts = [];
            foreach ($job['script']['scenes'] as $scene) {
                $narration = trim((string)($scene['narration'] ?? ''));
                if ($narration !== '') { $parts[] = $narration; }
            }
            $translated = trim(implode("\n", $parts));
        }
        $text = $title . "\n\n翻訳字幕・吹替動画\n" . $share_url . "\nPowered by Kurage Voice-Pro";
        if ($translated !== '') {
            $text .= "\n\n" . $translated;
        }
        return $text;
    }
    return $title . "\n\n" . $share_url . "\n#Kurage #AI動画";
}

function copy_text_for_job($job, $share_url) {
    if (is_voice_pro_job($job)) {
        return share_text_for_job($job, $share_url);
    }
    return ($job['title'] ?? '') . "\n\n" . ($job['tweet_text'] ?? '') . "\n\n" . $share_url . "\n#Kurage #AI動画";
}

/* ── 動画プロキシ（Range リクエスト対応） ────────────── */
if (isset($_GET['proxy']) && $_GET['proxy'] === 'thumbnail' && !empty($_GET['job_id'])) {
    $jid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['job_id']);
    $ch = curl_init($KURAGE_API . '/thumbnail/' . $jid);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = substr((string)$response, $header_size);
    http_response_code($code ?: 404);
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    if ($code >= 200 && $code < 300) {
        echo $data;
    }
    exit;
}

if (isset($_GET['proxy']) && $_GET['proxy'] === 'video' && !empty($_GET['job_id'])) {
    $jid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['job_id']);
    $ch = curl_init($KURAGE_API . '/video/' . $jid);
    $req_headers = ['Accept: */*'];
    if (!empty($_SERVER['HTTP_RANGE'])) {
        $req_headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $req_headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $resp_headers = substr($response, 0, $header_size);
    $data = substr($response, $header_size);
    http_response_code($code);
    header('Content-Type: video/mp4');
    header('Accept-Ranges: bytes');
    header('Content-Disposition: inline; filename="kurage_' . $jid . '.mp4"');
    foreach (explode("\r\n", $resp_headers) as $line) {
        if (preg_match('/^(Content-Range|Content-Length):\s*(.+)$/i', $line, $m)) {
            header($m[0]);
        }
    }
    echo $data;
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

function kurage_post($path, $timeout = 15) {
    global $KURAGE_API;
    $ch = curl_init($KURAGE_API . $path);
    curl_setopt($ch, CURLOPT_POST, true);
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
    if ($detail_job && empty($detail_job['source'])) {
        $detail_jobs_res = kurage_get('/jobs?limit=100');
        foreach (($detail_jobs_res['jobs'] ?? []) as $meta_job) {
            if (($meta_job['job_id'] ?? '') === $detail_id) {
                $detail_job = array_merge($meta_job, $detail_job);
                break;
            }
        }
    }
    $view_res = kurage_post('/view/' . $detail_id);
    if ($detail_job && !empty($view_res['views'])) {
        $detail_job['views'] = $view_res['views'];
    }
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
    foreach ($videos as $idx => $video) {
        if (is_voice_pro_job($video) && empty($video['translated_text']) && empty($video['script'])) {
            $full_job = kurage_get('/status/' . urlencode($video['job_id'] ?? ''), 8);
            if (is_array($full_job)) {
                $videos[$idx] = array_merge($full_job, $video);
            }
        }
    }
}

$sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'created';
if (!in_array($sort, ['created', 'views'], true)) { $sort = 'created'; }
if (!$detail_id && $videos) {
    usort($videos, function($a, $b) use ($sort) {
        if ($sort === 'views') {
            $av = (int)($a['views'] ?? 9999);
            $bv = (int)($b['views'] ?? 9999);
            if ($bv !== $av) return $bv <=> $av;
        }
        $ad = (string)($a['created_at'] ?? $a['updated_at'] ?? '');
        $bd = (string)($b['created_at'] ?? $b['updated_at'] ?? '');
        return strcmp($bd, $ad);
    });
}

/* ── SEO ─────────────────────────────────────────────── */
if ($detail_job) {
    $page_title = ($detail_job['title'] ?? 'Kurage動画') . ' | ' . $SITE_NAME;
    $page_desc  = mb_substr(str_replace("\n", ' ', $detail_job['tweet_text'] ?? ''), 0, 160);
    $page_url   = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_id);
    $thumb_ver  = urlencode($detail_job['updated_at'] ?? $detail_job['created_at'] ?? '1');
    $page_image = $BASE_URL . '/' . $THIS_FILE . '?proxy=thumbnail&job_id=' . urlencode($detail_id) . '&v=' . $thumb_ver;
} else {
    $page_title = $SITE_NAME . ' — AIが作るXショート動画';
    $page_desc  = 'Xの投稿をAIが短編縦型動画に自動生成。';
    $page_url   = $BASE_URL . '/' . $THIS_FILE;
    $page_image = $BASE_URL . '/images/kurage.png';
}
$header_amazon_kw = trim((string)($detail_job['title'] ?? '動画編集 撮影機材 YouTube 本'));
$header_amazon_from = '/' . $THIS_FILE . ($detail_id ? ('?id=' . $detail_id) : '');
$header_amazon_url = '/go.php?' . http_build_query(array(
    'to' => 'amazon',
    'kw' => $header_amazon_kw,
    'cat' => $detail_job ? 'books' : 'video',
    'from' => $header_amazon_from,
));
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
<meta property="og:image" content="<?php echo h($page_image); ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Kurage — AIが作るXショート動画">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:site" content="@xb_bittensor">
<meta name="twitter:title" content="<?php echo h($page_title); ?>">
<meta name="twitter:description" content="<?php echo h($page_desc); ?>">
<meta name="twitter:image" content="<?php echo h($page_image); ?>">
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
    $jsonld['thumbnailUrl'] = $page_image;
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
    s.src='https://kurage.exbridge.jp/simpletrack.php'
        +'?url='+encodeURIComponent(location.href)
        +'&ref='+encodeURIComponent(document.referrer);
    document.head.appendChild(s);
})();
</script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#fff;color:#222;font-family:-apple-system,'Helvetica Neue',sans-serif;font-size:14px;}

/* ── ヘッダー ── */
.header{background:rgba(255,255,255,.96);border-bottom:1px solid #e5e7eb;padding:.85rem 1.4rem;position:sticky;top:0;z-index:100;display:flex;justify-content:space-between;align-items:center;box-shadow:0 1px 4px rgba(19,35,41,.06);}
.brand{display:flex;align-items:center;gap:.65rem;}
.brand-icon{width:44px;height:44px;border-radius:50%;object-fit:cover;box-shadow:0 2px 8px rgba(0,127,150,.18);}
.brand-logo{font-weight:900;font-size:1.08rem;text-decoration:none;color:#111;display:block;line-height:1.15;}
.brand-logo span{color:#007f96;}
.brand-sub{display:block;font-size:.72rem;color:#888;margin-top:.18rem;}
.header-right{display:flex;align-items:center;gap:8px;}
.amazon-mini{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,#ffb84d,#ff9900);border:1px solid #e58a00;color:#1f2933;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;font-weight:1000;font-size:18px;font-family:Georgia,serif;line-height:1;box-shadow:0 8px 18px rgba(255,153,0,.28);transform:rotate(-2deg);transition:transform .15s,box-shadow .15s;}
.amazon-mini:hover{transform:rotate(2deg) translateY(-1px);box-shadow:0 10px 22px rgba(255,153,0,.38);}
.back-btn{font-size:13px;color:#007f96;text-decoration:none;padding:5px 12px;border:1px solid #007f96;border-radius:6px;}
.back-btn:hover{background:#e0f5f8;}
.reel-btn{background:#007f96;color:#fff;font-size:12px;padding:5px 14px;border:none;border-radius:6px;cursor:pointer;white-space:nowrap;}
.reel-btn:hover{background:#006880;}
.gen-link{font-size:12px;color:#007f96;text-decoration:none;padding:5px 12px;border:1px solid #007f96;border-radius:6px;white-space:nowrap;}
.kv-link.danger{background:#fff0f0;border-color:#fca5a5;color:#dc2626;}
.kv-link.danger:hover{background:#fee2e2;}
.user-tag{font-size:12px;color:#888;margin-left:4px;}
.gen-link:hover{background:#e0f5f8;}

/* ── コンテナ ── */
.container{max-width:640px;margin:0 auto;padding:0 0 80px;}
.count-bar{padding:10px 20px;font-size:13px;color:#888;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;}
.sorts{display:flex;gap:6px;align-items:center;}
.sort-link{border:1px solid #d6e3e8;border-radius:999px;padding:5px 10px;color:#53636b;text-decoration:none;font-size:12px;font-weight:800;background:#fff;}
.sort-link.active{background:#007f96;border-color:#007f96;color:#fff;}
.views{display:inline-flex;align-items:center;gap:4px;color:#007f96;font-weight:900;}

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
<header class="header">
  <div class="brand">
    <img class="brand-icon" src="images/kurage-icon.png" alt="Kurage">
    <a class="brand-logo" href="kuragev.php">
      <span>Kurageプロジェクト</span>
      <span class="brand-sub">AI Short Video</span>
    </a>
  </div>
  <div class="header-right">
    <a class="amazon-mini" href="<?php echo h($header_amazon_url); ?>" target="_blank" rel="sponsored nofollow noopener" aria-label="Amazonで関連商品を見る" title="Amazonで関連商品を見る">a</a>
    <?php if ($detail_job): ?>
    <a class="back-btn" href="<?php echo h($THIS_FILE); ?>">← 一覧</a>
    <?php else: ?>
    <?php if (!empty($videos)): ?>
    <button class="reel-btn" onclick="openReel(0)">🎬 リール表示</button>
    <?php endif; ?>
    <?php if ($is_admin): ?><a href="kurage.php" class="gen-link">＋ 生成</a><?php endif; ?>
    <?php if ($logged_in): ?>
    <span class="user-tag">@<?php echo h($session_user); ?> <a href="?logout=1" style="color:#007f96;font-size:11px;">logout</a></span>
    <?php else: ?>
    <a href="?login=1" class="gen-link">ログイン</a>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</header>

<?php if ($detail_job): ?>
<!-- ============ 詳細ページ ============ -->
<div class="container">
  <div class="detail-header">
    <div class="detail-meta">
      <span><?php echo h($detail_job['created_at'] ?? ''); ?></span>
      <span class="views">表示<?php echo h((string)($detail_job['views'] ?? 9999)); ?></span>
    </div>
    <?php if (!empty($detail_job['tweet_url'])): ?>
    <div class="detail-url-box">
      <?php echo is_entertainment_job($detail_job) ? '関連する考察記事:' : '元のXの投稿:'; ?>
      <a href="<?php echo h($detail_job['tweet_url']); ?>" target="_blank" rel="noopener"><?php echo h($detail_job['tweet_url']); ?></a>
    </div>
    <?php endif; ?>
  </div>
  <div class="detail-body">

    <div class="video-wrap">
      <video src="<?php echo h($THIS_FILE . '?proxy=video&job_id=' . urlencode($detail_id)); ?>"
             poster="<?php echo h($THIS_FILE . '?proxy=thumbnail&job_id=' . urlencode($detail_id) . '&v=' . urlencode($detail_job['updated_at'] ?? $detail_job['created_at'] ?? '1')); ?>"
             controls playsinline preload="metadata"></video>
    </div>

    <?php if (!empty($detail_job['tweet_text'])): ?>
    <div class="section-title"><?php echo is_entertainment_job($detail_job) ? '📣 関連記事の要約' : '📣 元のXの投稿'; ?></div>
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

    <?php $article_url = related_article_url($detail_job); ?>
    <?php if ($article_url !== ''): ?>
    <div class="detail-url-box" style="margin-top:14px">
      この動画の詳しい考察記事:
      <a href="<?php echo h($article_url); ?>" target="_blank" rel="noopener"><?php echo h($article_url); ?></a>
    </div>
    <?php endif; ?>

    <?php
    $share_url_d = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_id);
    $copy_d      = copy_text_for_job($detail_job, $share_url_d);
    $x_text_d    = urlencode(share_text_for_job($detail_job, $share_url_d));
    ?>
    <div class="action-row">
      <button id="detail-copy-btn" class="btn-primary"
              data-text="<?php echo h($copy_d); ?>">📋 コピー</button>
      <a class="kv-link" href="https://twitter.com/intent/tweet?text=<?php echo $x_text_d; ?>"
         target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" style="width:13px;height:13px;fill:currentColor;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
        Xに投稿
      </a>
      <?php if (!empty($detail_job['tweet_url'])): ?>
      <a class="kv-link" href="<?php echo h($detail_job['tweet_url']); ?>" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" style="width:13px;height:13px;fill:currentColor;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
        <?php echo is_entertainment_job($detail_job) ? '考察記事を開く' : '元の投稿を開く'; ?>
      </a>
      <?php endif; ?>
      <?php if ($is_admin): ?>
      <form method="post" style="display:inline;" onsubmit="return confirm('この動画を削除しますか？')">
        <input type="hidden" name="delete_job" value="<?php echo h($detail_id); ?>">
        <button type="submit" class="kv-link danger">🗑 削除</button>
      </form>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php else: ?>
<!-- ============ 一覧ページ ============ -->
<div class="container">
  <div class="count-bar">
    <span><?php echo count($videos); ?> 件の動画</span>
    <span class="sorts">
      <a class="sort-link <?php echo $sort === 'created' ? 'active' : ''; ?>" href="<?php echo h($THIS_FILE . '?sort=created'); ?>">作成日順</a>
      <a class="sort-link <?php echo $sort === 'views' ? 'active' : ''; ?>" href="<?php echo h($THIS_FILE . '?sort=views'); ?>">表示回数順</a>
    </span>
  </div>
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
      $r_thumb  = h($THIS_FILE . '?proxy=thumbnail&job_id=' . urlencode($v['job_id']) . '&v=' . urlencode($v['updated_at'] ?? $v['created_at'] ?? '1'));
      $r_title  = h($v['title'] ?? '(無題)');
      $r_author = h($v['tweet_author'] ?? '');
      $r_share  = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($v['job_id']);
      $r_xtext  = urlencode(share_text_for_job($v, $r_share));
      $r_copy   = h(copy_text_for_job($v, $r_share));
    ?>
    <div class="reel-slide" data-job="<?php echo h($v['job_id']); ?>">
      <video src="<?php echo $r_vid; ?>"
             poster="<?php echo $r_thumb; ?>"
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
var IS_ADMIN = <?php echo $is_admin ? 'true' : 'false'; ?>;
var PAGE_SIZE = 20;
var curPage = 0;

var X_SVG = '<svg viewBox="0 0 24 24" style="width:13px;height:13px;fill:currentColor;vertical-align:middle;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>';

function isVoiceProJob(v) {
    return (v.source === 'kuragevp') || (v.content_type === 'voice_pro_translation') || !!v.kuragevp_job_id;
}

function shareTextForJob(v, shareUrl) {
    var title = (v.title || 'Kurage動画').trim() || 'Kurage動画';
    if (isVoiceProJob(v)) {
        var translated = (v.translated_text || '').trim();
        if (!translated && v.script && Array.isArray(v.script.scenes)) {
            translated = v.script.scenes.map(function(scene) {
                return (scene && scene.narration ? String(scene.narration).trim() : '');
            }).filter(Boolean).join('\n').trim();
        }
        var text = title + '\n\n翻訳字幕・吹替動画\n' + shareUrl + '\nPowered by Kurage Voice-Pro';
        if (translated) {
            text += '\n\n' + translated;
        }
        return text;
    }
    return title + '\n\n' + shareUrl + '\n#Kurage #AI動画';
}

function copyTextForJob(v, shareUrl) {
    if (isVoiceProJob(v)) {
        return shareTextForJob(v, shareUrl);
    }
    return (v.title || '') + '\n\n' + (v.tweet_text || '') + '\n\n' + shareUrl + '\n#Kurage #AI動画';
}

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
        var views  = v.views || 9999;
        var av     = author ? author.replace(/^@/, '').charAt(0).toUpperCase() : '🪼';

        var shareUrl  = '<?php echo $BASE_URL . '/' . $THIS_FILE; ?>?id=' + encodeURIComponent(jid);
        var copyText  = copyTextForJob(v, shareUrl);
        var xText     = encodeURIComponent(shareTextForJob(v, shareUrl));

        var tweetHtml = tweet
            ? '<div class="tweet-block">' + esc(tweet) + '</div>'
            : '';

        var turlBtn = turl
            ? '<a class="kv-link" href="' + esc(turl) + '" target="_blank" rel="noopener">' + X_SVG + '&nbsp;元の投稿</a>'
            : '';

        var videoSrc = 'kuragev.php?proxy=video&job_id=' + encodeURIComponent(jid);
        var thumbVer = encodeURIComponent(v.updated_at || v.created_at || '1');
        var thumbSrc = 'kuragev.php?proxy=thumbnail&job_id=' + encodeURIComponent(jid) + '&v=' + thumbVer;
        var html = '<div class="post-card">'
            + '<div style="display:flex;gap:12px;align-items:flex-start;">'
            + '<div class="card-video-wrap" data-jid="' + esc(jid) + '">'
            + '<video class="thumb-video" src="' + videoSrc + '" poster="' + thumbSrc + '" playsinline muted preload="metadata" loop></video>'
            + '<div class="card-video-play">▶</div>'
            + '</div>'
            + '<div class="card-content">'
            + '<div class="post-meta" style="margin-bottom:6px;">'
            + '<div class="post-title">' + esc(title) + '</div>'
            + '<div class="post-time">' + esc(date) + '<br><span class="views">表示' + esc(views) + '</span></div>'
            + '</div>'
            + tweetHtml
            + '<div class="card-links">'
            + '<a class="kv-link primary" href="kuragev.php?id=' + encodeURIComponent(jid) + '">📄 詳細</a>'
            + '<button class="kv-link reel-open-btn" data-idx="' + i + '">🎬 リール</button>'
            + '<button class="kv-link kv-copy-btn" data-text="' + esc(copyText) + '">📋 コピー</button>'
            + '<a class="kv-link" href="https://twitter.com/intent/tweet?text=' + xText + '" target="_blank" rel="noopener">' + X_SVG + '&nbsp;Xに投稿</a>'
            + turlBtn
            + (IS_ADMIN ? '<button class="kv-link danger kv-delete-btn" data-jid="' + esc(jid) + '">🗑</button>' : '')
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
        kvCopyText(text, function() {
            copyBtn.textContent = '✓ コピー済';
            setTimeout(function() { copyBtn.textContent = '📋 コピー'; }, 2000);
        });
        return;
    }
    var detailCopyBtn = e.target.closest('#detail-copy-btn');
    if (detailCopyBtn) {
        kvCopyBtn(detailCopyBtn, detailCopyBtn.dataset.text || '');
        return;
    }
    var reelBtn = e.target.closest('.reel-open-btn');
    if (reelBtn) {
        openReel(parseInt(reelBtn.dataset.idx || '0', 10));
        return;
    }
    var delBtn = e.target.closest('.kv-delete-btn');
    if (delBtn && confirm('この動画を削除しますか？')) {
        var jid = delBtn.dataset.jid;
        fetch('kuragev.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'delete_job='+encodeURIComponent(jid)})
            .then(function(){ location.reload(); });
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
        pl.textContent = 'まだ動画がありません。';
    }
} else {
    renderCards(0, PAGE_SIZE);
}

/* ── コピーヘルパー（詳細ページ用） ── */
function kvCopyText(text, onSuccess) {
    function done() {
        if (typeof onSuccess === 'function') onSuccess();
    }
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done).catch(function() {
            kvFallbackCopy(text);
            done();
        });
        return;
    }
    kvFallbackCopy(text);
    done();
}
function kvFallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    ta.style.top = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
}
function kvCopyBtn(btn, text) {
    kvCopyText(text, function() {
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
                kvCopyText(text, function() {
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
<?php
$amazon_kw = trim((string)($detail_job['title'] ?? 'AI 動画生成 マイク 動画編集'));
$amazon_from = '/' . $THIS_FILE . ($detail_id ? ('?id=' . $detail_id) : '');
$amazon_cta_url = '/go.php?' . http_build_query(array(
    'to' => 'amazon',
    'kw' => $amazon_kw,
    'from' => $amazon_from,
));
?>
<footer class="affiliate-disclosure" style="max-width:1120px;margin:28px auto 18px;padding:16px;color:#647884;font-size:12px;line-height:1.7;text-align:center;">
  <div style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:center;border:1px solid #f2d39a;background:linear-gradient(135deg,#fffaf0,#fff);border-radius:999px;padding:9px 12px;box-shadow:0 10px 24px rgba(146,95,0,.08);">
    <span style="font-weight:900;color:#9a5b00;">Amazon Associate Partner</span>
    <span>この動画テーマに近い本・機材をAmazonで探せます。</span>
    <a href="<?php echo h($amazon_cta_url); ?>" target="_blank" rel="sponsored nofollow noopener" style="display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#ff9900;color:#1f2933;text-decoration:none;font-weight:900;padding:7px 12px;">Amazonで関連アイテムを見る</a>
  </div>
  <div style="margin-top:8px;">Amazonアソシエイトとして適格販売により収入を得ています。</div>
</footer>

</body>
</html>
