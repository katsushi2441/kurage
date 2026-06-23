<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_common.php';
date_default_timezone_set('Asia/Tokyo');

$THIS_FILE    = 'horizon.php';
$SITE_NAME    = 'Kurageプロジェクト Horizon-AI生成ニュース動画';
$KURAGE_API   = rtrim(getenv('KURAGE_API') ?: 'http://exbridge.ddns.net:18303', '/');

if (isset($_GET['horizon_logout'])) {
    header('Location: ' . url2ai_auth_logout_url('/' . $THIS_FILE));
    exit;
}
if (isset($_GET['horizon_login'])) {
    header('Location: ' . url2ai_auth_login_url('/' . $THIS_FILE));
    exit;
}

$auth         = url2ai_auth_bootstrap();
$logged_in    = $auth['logged_in'];
$session_user = $auth['session_user'];
$is_admin     = $auth['is_admin'];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function kurage_api($method, $path, $payload = null, $timeout = 20) {
    global $KURAGE_API;
    $url = $KURAGE_API . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Accept: application/json'));
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err) {
        return array('ok' => false, 'error' => $err ?: 'request failed', 'status' => 0);
    }
    $json = json_decode($body, true);
    if (!is_array($json)) { $json = array('raw' => $body); }
    return array('ok' => ($status >= 200 && $status < 300), 'status' => $status, 'data' => $json);
}

// ── PHP API プロキシ（ブラウザから直接 HTTP バックエンドを叩けないため） ──
$proxy_action = isset($_GET['proxy']) ? $_GET['proxy'] : '';
if ($proxy_action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if ($proxy_action === 'generate_url' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = file_get_contents('php://input');
        $res  = kurage_api('POST', '/generate_from_url', json_decode($body, true), 30);
        echo json_encode(isset($res['data']) ? $res['data'] : array('ok'=>false,'error'=>isset($res['error'])?$res['error']:'API unreachable'), JSON_UNESCAPED_UNICODE);
    } elseif ($proxy_action === 'status' && isset($_GET['job_id'])) {
        $jid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['job_id']);
        $res = kurage_api('GET', '/status/' . $jid, null, 15);
        echo json_encode(isset($res['data']) ? $res['data'] : array('ok'=>false,'error'=>isset($res['error'])?$res['error']:'API unreachable'), JSON_UNESCAPED_UNICODE);
    } elseif ($proxy_action === 'config') {
        $res = kurage_api('GET', '/config', null, 10);
        echo json_encode(isset($res['data']) ? $res['data'] : array('ok'=>false), JSON_UNESCAPED_UNICODE);
    } elseif ($proxy_action === 'jobs') {
        $res = kurage_api('GET', '/jobs?source=horizon&limit=20', null, 10);
        echo json_encode(isset($res['data']) ? $res['data'] : array('ok'=>false,'jobs'=>array()), JSON_UNESCAPED_UNICODE);
    } elseif ($proxy_action === 'video' && isset($_GET['job_id'])) {
        $jid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['job_id']);
        $url = $KURAGE_API . '/video/' . $jid;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $data = curl_exec($ch);
        $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data && $code === 200) {
            header('Content-Type: video/mp4');
            header('Content-Disposition: attachment; filename="kurage_' . $jid . '.mp4"');
            echo $data;
        } else {
            header('HTTP/1.1 404 Not Found');
            echo json_encode(array('ok' => false, 'error' => 'video not found'));
        }
    } else {
        echo json_encode(array('ok' => false, 'error' => 'unknown action'));
    }
    exit;
}

// API health check
$api_ok = false;
$health = kurage_api('GET', '/health', null, 5);
if ($health['ok'] && isset($health['data']['ok'])) { $api_ok = true; }

// Recent jobs for admin
$recent_jobs = array();
if ($is_admin) {
    $jobs_res = kurage_api('GET', '/jobs?source=horizon&limit=20', null, 10);
    if ($jobs_res['ok']) {
        $recent_jobs = isset($jobs_res['data']['jobs']) ? $jobs_res['data']['jobs'] : array();
    }
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($SITE_NAME); ?></title>
<style>
:root {
  --bg:#f4f7f7; --surface:#ffffff; --border:#dbe5e8; --border2:#c4d4d8;
  --accent:#007f96; --accent-h:#006578; --green:#3a9e1f; --red:#b2473f;
  --text:#132329; --muted:#5a6a72;
}
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
body { background:var(--bg); color:var(--text); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif; min-height:100vh; font-size:14px; }
header { background:rgba(255,255,255,.95); border-bottom:1px solid var(--border); padding:.85rem 1.5rem; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:10; backdrop-filter:blur(8px); box-shadow:0 1px 4px rgba(19,35,41,.06); }
.brand { display:flex; align-items:center; gap:.65rem; }
.brand-icon { width:44px; height:44px; border-radius:50%; object-fit:cover; box-shadow:0 2px 8px rgba(0,127,150,.18); }
.brand-logo { font-weight:900; font-size:1.08rem; text-decoration:none; color:var(--text); display:block; line-height:1.15; }
.brand-logo span { color:var(--accent); }
.brand-sub { display:block; font-size:.72rem; color:var(--muted); margin-top:.18rem; }
.userbar { display:flex; align-items:center; gap:.75rem; font-size:.8rem; color:var(--muted); }
.userbar strong { color:var(--green); }
.btn-sm { background:none; border:1px solid var(--border2); color:var(--muted); padding:.2rem .7rem; border-radius:4px; font-size:.75rem; cursor:pointer; text-decoration:none; }
.btn-sm:hover { border-color:var(--accent); color:var(--accent); }
.container { max-width:900px; margin:0 auto; padding:1.5rem; }
.card { background:var(--surface); border:1px solid var(--border); border-radius:12px; margin-bottom:1rem; overflow:hidden; box-shadow:0 2px 8px rgba(19,35,41,.05); }
.card-head { padding:.7rem 1.25rem; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:.5rem; font-size:.78rem; font-weight:800; color:var(--muted); background:#f8fbfb; text-transform:uppercase; letter-spacing:.04em; }
.card-head .dot { width:7px; height:7px; border-radius:50%; background:var(--accent); }
.card-body { padding:1.25rem; }
.input-row { display:flex; gap:.6rem; }
input[type=text] { flex:1; background:#fff; border:1px solid var(--border2); border-radius:8px; padding:.65rem 1rem; font-size:.9rem; color:var(--text); outline:none; transition:border .15s; }
input[type=text]:focus { border-color:var(--accent); box-shadow:0 0 0 3px rgba(0,127,150,.1); }
.btn { display:inline-flex; align-items:center; gap:.4rem; padding:.6rem 1.4rem; border-radius:8px; font-size:.82rem; font-weight:800; cursor:pointer; border:none; transition:all .15s; font-family:inherit; white-space:nowrap; }
.btn-primary { background:var(--accent); color:#fff; }
.btn-primary:hover { background:var(--accent-h); }
.btn:disabled { opacity:.45; cursor:not-allowed; }
.hint { font-size:.78rem; color:var(--muted); margin-top:.6rem; line-height:1.75; }
.mode-row { margin-top:.9rem; display:flex; gap:.6rem; align-items:center; flex-wrap:wrap; }
.mode-label { font-size:.78rem; font-weight:800; color:var(--muted); }
.mode-select { min-width:260px; max-width:100%; border:1px solid var(--border2); border-radius:8px; padding:.58rem .75rem; background:#fff; color:var(--text); font-size:.86rem; font-weight:700; }
.vtuber-toggle { margin-top:.8rem; display:flex; align-items:flex-start; gap:.65rem; padding:.75rem .85rem; border:1px solid #bfe9ef; border-radius:12px; background:linear-gradient(135deg,#f2fdff,#fff); }
.vtuber-toggle input { margin-top:.22rem; accent-color:var(--accent); }
.vtuber-toggle strong { display:block; color:var(--text); font-size:.86rem; }
.vtuber-toggle span { display:block; margin-top:.2rem; color:var(--muted); font-size:.76rem; line-height:1.55; }
/* Status */
#status-box { display:none; }
.progress-bar { width:100%; height:6px; background:var(--border); border-radius:3px; margin:.75rem 0; overflow:hidden; }
.progress-fill { height:100%; background:linear-gradient(90deg,var(--accent),var(--green)); border-radius:3px; transition:width .4s; }
.status-text { font-size:.82rem; color:var(--muted); }
.status-badge { display:inline-flex; align-items:center; gap:.3rem; padding:.2rem .65rem; border-radius:4px; font-size:.72rem; font-weight:800; }
.badge-queued,.badge-fetching,.badge-scripting,.badge-imaging,.badge-rendering { background:#e0f2f7; color:var(--accent); }
.badge-done { background:#e6f4e0; color:var(--green); }
.badge-error { background:#fbeaea; color:var(--red); }
/* Video player */
#video-box { display:none; margin-top:1.25rem; text-align:center; }
#video-box video { max-width:280px; border-radius:12px; border:1px solid var(--border); box-shadow:0 4px 16px rgba(19,35,41,.12); }
.video-title { font-size:1rem; font-weight:700; margin-bottom:.75rem; color:var(--text); }
/* Tweet preview */
.tweet-preview { background:#f0f8fa; border:1px solid #c8e6ec; border-radius:8px; padding:.85rem 1rem; margin:.75rem 0; font-size:.82rem; line-height:1.75; color:#2a4a52; }
.tweet-preview .author { color:var(--accent); font-weight:700; margin-bottom:.3rem; font-size:.78rem; }
/* Jobs list */
.jobs-list { display:grid; gap:.5rem; }
.job-row { display:flex; align-items:center; gap:.75rem; padding:.65rem .85rem; background:#f8fbfb; border:1px solid var(--border); border-radius:8px; font-size:.78rem; cursor:pointer; transition:all .15s; }
.job-row:hover { border-color:var(--accent); background:#eef7fa; }
.job-row .job-title { flex:1; font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.job-row .job-meta { color:var(--muted); white-space:nowrap; }
/* API status */
.api-status { font-size:.72rem; padding:.2rem .55rem; border-radius:4px; font-weight:700; }
.api-ok { background:#e6f4e0; color:var(--green); }
.api-err { background:#fbeaea; color:var(--red); }
/* Spinner */
.spinner { display:none; width:14px; height:14px; border:2px solid rgba(255,255,255,.4); border-top-color:#fff; border-radius:50%; animation:spin .6s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
.loading .spinner { display:inline-block; }
.loading .btn-label { display:none; }
/* Hero */
.hero { text-align:center; padding:2.5rem 1rem 1.75rem; }
.hero .emoji { font-size:3rem; line-height:1; margin-bottom:.75rem; }
.hero h1 { font-size:1.6rem; font-weight:900; margin-bottom:.5rem; letter-spacing:-.02em; }
.hero p { color:var(--muted); font-size:.88rem; line-height:1.8; }
@media(max-width:600px) { .input-row { flex-wrap:wrap; } .container { padding:1rem; } }
</style>
</head>
<body>
<header>
  <div class="brand">
    <img class="brand-icon" src="images/kurage-icon.png" alt="Kurage">
    <a class="brand-logo" href="horizon.php">
      <span>Kurageプロジェクト</span>
      <span class="brand-sub">Horizon-AI生成ニュース動画</span>
    </a>
  </div>
  <div class="userbar">
    <span class="api-status <?php echo $api_ok ? 'api-ok' : 'api-err'; ?>">
      <?php echo $api_ok ? 'API ●' : 'API ✕'; ?>
    </span>
    <?php if ($logged_in): ?>
    <span>@<strong><?php echo h($session_user); ?></strong></span>
    <a href="?horizon_logout=1" class="btn-sm">logout</a>
    <?php else: ?>
    <a href="?horizon_login=1" class="btn-sm">X でログイン</a>
    <?php endif; ?>
  </div>
</header>

<div class="container">

  <?php if (!$logged_in): ?>
  <div class="hero">
    <div class="emoji">📰</div>
    <h1>ブログ・ニュース記事から2分の動画を自動生成</h1>
    <p>URLを貼るだけ。<br><span id="hero-pipeline">AIが脚本を書き、画像を生成し、動画を合成します。</span></p>
  </div>
  <div class="card">
    <div class="card-body" style="text-align:center;padding:2rem 1rem;">
      <a href="?horizon_login=1" class="btn btn-primary" style="font-size:.9rem;padding:.75rem 2rem;">X でログインして始める</a>
      <div class="hint" style="margin-top:1rem;">Xアカウントでログインするだけで利用できます。</div>
    </div>
  </div>
  <?php else: ?>

  <!-- Generate form -->
  <div class="card">
    <div class="card-head"><span class="dot"></span> ブログ・ニュース記事のURLを入力</div>
    <div class="card-body">
      <div class="input-row">
        <input type="text" id="tweet-url" placeholder="https://techcrunch.com/... などのURL" autocomplete="off">
        <button class="btn btn-primary" id="btn-generate" onclick="startGenerate()">
          <span class="btn-label">動画生成</span>
          <span class="spinner"></span>
        </button>
      </div>
      <div class="hint">
        ブログ・ニュース記事のURLを貼り付けてください。約2分の動画を生成します。<br>
        <span id="hint-pipeline">読込中...</span>
      </div>
      <div class="mode-row">
        <label class="mode-label" for="video-style">演出</label>
        <select id="video-style" class="mode-select">
          <option value="auto" selected>自動選択</option>
          <option value="faceless_documentary">Faceless Documentary</option>
          <option value="ai_avatar_explainer">AI Avatar Explainer</option>
          <option value="saas_launch">SaaS Launch</option>
          <option value="course_promo">Course Promo</option>
          <option value="podcast_visual">Podcast Visual</option>
        </select>
      </div>
      <label class="vtuber-toggle">
        <input type="checkbox" id="vtuber-mode" checked>
        <span>
          <strong>VTuber解説モードで生成</strong>
          <span>Kurage AI Navigatorが右下で解説する、投稿向けの商品デモ風レイアウトにします。</span>
        </span>
      </label>
    </div>
  </div>

  <!-- Status -->
  <div class="card" id="status-box">
    <div class="card-head"><span class="dot"></span> 生成状況</div>
    <div class="card-body">
      <div id="status-tweet-preview" class="tweet-preview" style="display:none;"></div>
      <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.4rem;">
        <span id="status-badge" class="status-badge badge-queued">queued</span>
        <span id="status-title" style="font-size:.85rem;font-weight:600;"></span>
      </div>
      <div class="progress-bar"><div class="progress-fill" id="progress-fill" style="width:0%"></div></div>
      <div id="status-text" class="status-text">処理待ち...</div>
      <div id="status-error" style="color:var(--red);font-size:.8rem;margin-top:.5rem;display:none;"></div>

      <div id="video-box">
        <div id="video-title" class="video-title"></div>
        <video controls playsinline></video>
        <div style="margin-top:.75rem;">
          <a id="video-download" href="#" class="btn btn-primary" download>⬇ 動画をダウンロード</a>
        </div>
      </div>
    </div>
  </div>

  <!-- AI Services Info -->
  <div class="card" id="config-card">
    <div class="card-head"><span class="dot"></span> 使用中のAIサービス</div>
    <div class="card-body" id="config-body">
      <div style="color:#aaa;font-size:.8rem;">読込中...</div>
    </div>
  </div>

  <?php if ($is_admin && count($recent_jobs) > 0): ?>
  <!-- Recent jobs -->
  <div class="card">
    <div class="card-head"><span class="dot"></span> 最近のジョブ</div>
    <div class="card-body">
      <div class="jobs-list">
        <?php foreach ($recent_jobs as $job): ?>
        <div class="job-row" onclick="loadJob('<?php echo h($job['job_id']); ?>')">
          <span class="status-badge badge-<?php echo h($job['status'] ?: 'queued'); ?>"><?php echo h($job['status'] ?: '?'); ?></span>
          <span class="job-title"><?php echo h($job['title'] ?: $job['tweet_author'] ?: '(処理中)'); ?></span>
          <?php if (!empty($job['has_video'])): ?>
          <span style="color:var(--green);">●</span>
          <?php endif; ?>
          <span class="job-meta"><?php echo h(substr($job['created_at'] ?: '', 5, 11)); ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php endif; ?>

</div>

<script>
var PROXY = '<?php echo h($THIS_FILE); ?>';
var currentJobId = null;
var pollTimer = null;

function selectedVideoStyle() {
    var el = document.getElementById('video-style');
    return el ? el.value : 'auto';
}

function startGenerate() {
    var url = document.getElementById('tweet-url').value.trim();
    if (!url) { alert('URLを入力してください'); return; }

    var btn = document.getElementById('btn-generate');
    btn.disabled = true;
    btn.classList.add('loading');
    var vtuberMode = !!(document.getElementById('vtuber-mode') && document.getElementById('vtuber-mode').checked);
    var videoStyle = selectedVideoStyle();

    fetch(PROXY + '?proxy=generate_url', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({url: url, vtuber_mode: vtuberMode, video_style: videoStyle}),
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok && data.job_id) {
            currentJobId = data.job_id;
            showStatus();
            startPolling(data.job_id);
        } else {
            alert('エラー: ' + JSON.stringify(data));
            btn.disabled = false;
            btn.classList.remove('loading');
        }
    })
    .catch(function(e) {
        alert('APIエラー: ' + e.message);
        btn.disabled = false;
        btn.classList.remove('loading');
    });
}

function showStatus() {
    document.getElementById('status-box').style.display = 'block';
    document.getElementById('video-box').style.display = 'none';
    document.getElementById('progress-fill').style.width = '0%';
    document.getElementById('status-error').style.display = 'none';
    document.getElementById('status-title').textContent = '';
    document.getElementById('status-tweet-preview').style.display = 'none';
}

function startPolling(jobId) {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(function() { pollStatus(jobId); }, 2500);
}

function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}

function pollStatus(jobId) {
    fetch(PROXY + '?proxy=status&job_id=' + encodeURIComponent(jobId))
    .then(function(r) { return r.json(); })
    .then(function(data) { updateStatusUI(data); })
    .catch(function(e) { console.error('poll error', e); });
}

var STATUS_LABELS = {
    queued:    '待機中...',
    fetching:  '記事を取得中...',
    scripting: 'AI で脚本・プロンプトを生成中...',
    imaging:   '画像生成中...',
    rendering: '動画レンダリング中...',
    done:      '完成！',
    error:     'エラーが発生しました',
};

function updateStatusUI(data) {
    var badge = document.getElementById('status-badge');
    badge.textContent = data.status;
    badge.className = 'status-badge badge-' + (data.status || 'queued');

    var progress = data.progress || 0;
    document.getElementById('progress-fill').style.width = progress + '%';
    document.getElementById('status-text').textContent = STATUS_LABELS[data.status] || data.status;

    if (data.title) {
        document.getElementById('status-title').textContent = data.title;
    }
    if (data.tweet_text && data.tweet_author) {
        var preview = document.getElementById('status-tweet-preview');
        preview.style.display = 'block';
        preview.innerHTML = '<div class="author">' + escapeHtml(data.tweet_author) + '</div>' + escapeHtml(data.tweet_text);
    }

    if (data.status === 'done' && data.video_url) {
        stopPolling();
        showVideo(data);
        var btn = document.getElementById('btn-generate');
        btn.disabled = false;
        btn.classList.remove('loading');
    }

    if (data.status === 'error') {
        stopPolling();
        document.getElementById('status-error').style.display = 'block';
        document.getElementById('status-error').textContent = data.error || 'Unknown error';
        var btn = document.getElementById('btn-generate');
        btn.disabled = false;
        btn.classList.remove('loading');
    }
}

function showVideo(data) {
    var box = document.getElementById('video-box');
    box.style.display = 'block';
    document.getElementById('video-title').textContent = data.title || '動画が完成しました';
    var videoSrc = PROXY + '?proxy=video&job_id=' + data.job_id;
    var videoEl = box.querySelector('video');
    videoEl.src = videoSrc;
    document.getElementById('video-download').href = videoSrc;
    document.getElementById('video-download').download = 'horizon_' + data.job_id + '.mp4';
}

function loadJob(jobId) {
    currentJobId = jobId;
    showStatus();
    pollStatus(jobId);
    // If not done/error, start polling
    fetch(PROXY + '?proxy=status&job_id=' + encodeURIComponent(jobId))
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status !== 'done' && data.status !== 'error') {
            startPolling(jobId);
        }
    });
}

function escapeHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Enter key on input
document.addEventListener('DOMContentLoaded', function() {
    var input = document.getElementById('tweet-url');
    if (input) {
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { startGenerate(); }
        });
    }

    // Load config panel
    var configCard = document.getElementById('config-card');
    if (configCard) {
        fetch(PROXY + '?proxy=config')
            .then(function(r) { return r.json(); })
            .then(function(cfg) {
                var html = '<table style="width:100%;font-size:.78rem;border-collapse:collapse;">';
                html += '<tr style="border-bottom:1px solid #e0e8ea;">'
                      + '<th style="text-align:left;padding:.35rem .5rem;color:#666;font-weight:600;width:6rem;">ステップ</th>'
                      + '<th style="text-align:left;padding:.35rem .5rem;color:#666;font-weight:600;">サービス</th>'
                      + '<th style="text-align:left;padding:.35rem .5rem;color:#666;font-weight:600;">エンドポイント</th>'
                      + '</tr>';
                // 動的テキスト更新
                var sl = cfg.script && cfg.script.label || 'AI';
                var il = cfg.image  && cfg.image.label  || '画像生成AI';
                var vl = cfg.video  && cfg.video.label  || 'HyperFrames';
                var pipeline = sl + ' が脚本を書き、' + il + ' が画像を生成し、' + vl + ' が動画を合成します。';
                var hint     = sl + ' が脚本を作成 → ' + il + ' が画像生成 → ' + vl + ' が動画合成します。';
                if (document.getElementById('hero-pipeline')) document.getElementById('hero-pipeline').textContent = pipeline;
                if (document.getElementById('hint-pipeline')) document.getElementById('hint-pipeline').textContent = hint;
                function maskUrl(url) {
                    return url ? url.replace(/https?:\/\/[^\/]+/, 'http://localhost') : '-';
                }
                var tl2 = cfg.tts && cfg.tts.label || 'edge-tts';
                var ta  = cfg.tts && cfg.tts.api   || '';
                var vt = cfg.vtuber && cfg.vtuber.label || 'Kurage VTuber解説モード';
                var va = cfg.vtuber && cfg.vtuber.api   || '';
                STATUS_LABELS.scripting = sl  + ' で脚本・プロンプトを生成中...';
                STATUS_LABELS.imaging   = il  + ' で画像生成中...';
                STATUS_LABELS.rendering = vl  + ' で動画レンダリング中...';
                var rows = [
                    ['📝 脚本生成', sl,  maskUrl(cfg.script && cfg.script.api)],
                    ['🖼️ 画像生成', il,  maskUrl(cfg.image  && cfg.image.api)],
                    ['🔊 音声合成', tl2, ta],
                    ['🪼 VTuber演出', vt, va],
                    ['🎬 動画合成', vl,  cfg.video && cfg.video.api],
                ];
                rows.forEach(function(r) {
                    html += '<tr style="border-bottom:1px solid #f0f4f5;">'
                          + '<td style="padding:.35rem .5rem;color:#444;">' + r[0] + '</td>'
                          + '<td style="padding:.35rem .5rem;font-weight:600;color:var(--accent);">' + (r[1]||'-') + '</td>'
                          + '<td style="padding:.35rem .5rem;color:#666;word-break:break-all;">' + (r[2]||'-') + '</td>'
                          + '</tr>';
                });
                html += '</table>';
                document.getElementById('config-body').innerHTML = html;
            })
            .catch(function() {
                document.getElementById('config-body').innerHTML = '<div style="color:#aaa;font-size:.8rem;">設定情報を取得できませんでした</div>';
            });
    }
});
</script>
<?php
$amazon_cta_url = '/go.php?' . http_build_query(array(
    'to' => 'amazon',
    'kw' => 'AI ニュース 動画制作 マイク',
    'from' => '/horizon.php',
));
?>
<footer class="affiliate-disclosure" style="max-width:1120px;margin:28px auto 18px;padding:16px;color:#647884;font-size:12px;line-height:1.7;text-align:center;">
  <div style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:center;border:1px solid #f2d39a;background:linear-gradient(135deg,#fffaf0,#fff);border-radius:999px;padding:9px 12px;box-shadow:0 10px 24px rgba(146,95,0,.08);">
    <span style="font-weight:900;color:#9a5b00;">Amazon Associate Partner</span>
    <span>HorizonのAIニュース動画制作に役立つ資料・機材をAmazonで探せます。</span>
    <a href="<?php echo h($amazon_cta_url); ?>" target="_blank" rel="sponsored nofollow noopener" style="display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#ff9900;color:#1f2933;text-decoration:none;font-weight:900;padding:7px 12px;">Amazonで見る</a>
  </div>
  <div style="margin-top:8px;">Amazonアソシエイトとして適格販売により収入を得ています。</div>
</footer>

</body>
</html>
