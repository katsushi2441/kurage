<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_common.php';
date_default_timezone_set('Asia/Tokyo');

$THIS_FILE = 'klofi.php';
$SITE_NAME = 'Kurage Lo-Fi — 60分BGM動画生成';
$KURAGE_API = rtrim(getenv('KURAGE_API') ?: 'http://exbridge.ddns.net:18303', '/');

if (isset($_GET['klofi_logout'])) {
    header('Location: ' . url2ai_auth_logout_url('/' . $THIS_FILE));
    exit;
}
if (isset($_GET['klofi_login'])) {
    header('Location: ' . url2ai_auth_login_url('/' . $THIS_FILE));
    exit;
}

$auth = url2ai_auth_bootstrap();
$logged_in = $auth['logged_in'];
$session_user = $auth['session_user'];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function klofi_api_json($method, $path, $payload = null, $timeout = 30) {
    global $KURAGE_API;
    $ch = curl_init($KURAGE_API . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Accept: application/json'));
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err) return array('ok'=>false, 'error'=>$err ?: 'request failed');
    $json = json_decode($body, true);
    if (!is_array($json)) $json = array('raw'=>$body);
    if ($status < 200 || $status >= 300) $json['ok'] = false;
    return $json;
}

function klofi_api_upload($timeout = 60) {
    global $KURAGE_API;
    if (empty($_FILES['audio']) || !is_uploaded_file($_FILES['audio']['tmp_name'])) {
        return array('ok'=>false, 'error'=>'mp3ファイルを選択してください');
    }
    $fields = array(
        'audio' => new CURLFile($_FILES['audio']['tmp_name'], $_FILES['audio']['type'] ?: 'audio/mpeg', $_FILES['audio']['name']),
        'title' => isset($_POST['title']) ? (string)$_POST['title'] : '',
        'duration_minutes' => isset($_POST['duration_minutes']) ? (string)$_POST['duration_minutes'] : '60',
        'image_prompt' => isset($_POST['image_prompt']) ? (string)$_POST['image_prompt'] : '',
    );
    $ch = curl_init($KURAGE_API . '/lofi/generate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err) return array('ok'=>false, 'error'=>$err ?: 'upload failed');
    $json = json_decode($body, true);
    if (!is_array($json)) $json = array('raw'=>$body);
    if ($status < 200 || $status >= 300) $json['ok'] = false;
    return $json;
}

$proxy = isset($_GET['proxy']) ? $_GET['proxy'] : '';
if ($proxy !== '') {
    if (!$logged_in) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok'=>false, 'error'=>'login required'), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($proxy === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(klofi_api_upload(90), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($proxy === 'status' && isset($_GET['job_id'])) {
        header('Content-Type: application/json; charset=utf-8');
        $jid = preg_replace('/[^A-Za-z0-9]/', '', $_GET['job_id']);
        echo json_encode(klofi_api_json('GET', '/lofi/status/' . $jid, null, 20), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($proxy === 'jobs') {
        header('Content-Type: application/json; charset=utf-8');
        $limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 20;
        echo json_encode(klofi_api_json('GET', '/lofi/jobs?limit=' . $limit, null, 20), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($proxy === 'delete' && isset($_GET['job_id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        $jid = preg_replace('/[^A-Za-z0-9]/', '', $_GET['job_id']);
        echo json_encode(klofi_api_json('DELETE', '/lofi/jobs/' . $jid, null, 20), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($proxy === 'file' && isset($_GET['job_id'], $_GET['name'])) {
        $jid = preg_replace('/[^A-Za-z0-9]/', '', $_GET['job_id']);
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '', $_GET['name']);
        header('Location: ' . $KURAGE_API . '/lofi/file/' . rawurlencode($jid) . '/' . rawurlencode($name));
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok'=>false, 'error'=>'unknown action'), JSON_UNESCAPED_UNICODE);
    exit;
}

$health = klofi_api_json('GET', '/health', null, 5);
$api_ok = !empty($health['ok']);
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($SITE_NAME); ?></title>
<style>
:root{--bg:#f7fbfc;--surface:#fff;--ink:#14262d;--muted:#657b83;--line:#d6e8ec;--accent:#078aa6;--accent2:#f4b84d;--green:#2f9d62;--red:#bd4b4b;--soft:#eaf8fb;--shadow:0 18px 55px rgba(17,54,64,.09)}
*{box-sizing:border-box;margin:0;padding:0}body{min-height:100vh;background:radial-gradient(circle at 14% 8%,rgba(109,211,231,.25),transparent 30%),radial-gradient(circle at 88% 0%,rgba(255,218,144,.36),transparent 28%),linear-gradient(180deg,#fff 0%,#f7fbfc 54%,#eaf7fa 100%);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;font-size:14px}header{position:sticky;top:0;z-index:10;background:rgba(255,255,255,.88);backdrop-filter:blur(14px);border-bottom:1px solid var(--line);padding:.72rem 1.2rem;display:flex;align-items:center;justify-content:space-between;gap:1rem}.brand{color:var(--ink);text-decoration:none;display:flex;gap:.75rem;align-items:center}.logo{width:44px;height:44px;border-radius:16px;background:linear-gradient(135deg,#dff8ff,#fff4d6);display:grid;place-items:center;box-shadow:0 10px 26px rgba(7,138,166,.14);font-weight:950;color:var(--accent)}.brand strong{display:block;font-size:1rem;font-weight:950}.brand span{display:block;color:var(--muted);font-size:.74rem;margin-top:.08rem}.userbar{display:flex;align-items:center;gap:.55rem;color:var(--muted);font-size:.78rem}.badge{display:inline-flex;align-items:center;border-radius:999px;padding:.18rem .58rem;font-size:.72rem;font-weight:900;border:1px solid transparent}.api-ok{background:#e7f7ed;color:var(--green);border-color:#ccebd8}.api-err{background:#fff0f0;color:var(--red);border-color:#f2cccc}.btn-sm{color:var(--muted);text-decoration:none;border:1px solid var(--line);border-radius:999px;padding:.25rem .7rem;background:#fff}.wrap{max-width:1080px;margin:0 auto;padding:1.15rem}.hero,.panel{background:rgba(255,255,255,.88);border:1px solid var(--line);border-radius:24px;box-shadow:var(--shadow);overflow:hidden;margin-bottom:1rem}.hero{padding:1.45rem}.eyebrow{font-size:.72rem;font-weight:950;letter-spacing:.14em;text-transform:uppercase;color:var(--accent);margin-bottom:.55rem}.hero h1{font-size:clamp(1.7rem,4.2vw,3rem);line-height:1.06;letter-spacing:-.045em;margin-bottom:.8rem}.lead{color:var(--muted);line-height:1.85;max-width:860px}.tips{display:grid;grid-template-columns:repeat(3,1fr);gap:.65rem;margin-top:1rem}.tip{padding:.75rem;border-radius:16px;background:#f7fcfd;border:1px solid var(--line);line-height:1.6}.tip strong{display:block;color:#24434c;margin-bottom:.18rem}.panel-head{padding:.85rem 1rem;background:rgba(246,252,253,.9);border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;gap:.8rem;font-weight:950}.panel-head small{font-weight:700;color:var(--muted)}.panel-body{padding:1rem}.grid{display:grid;grid-template-columns:1.2fr .8fr;gap:.8rem}.field{margin-bottom:.72rem}label{display:block;font-weight:900;margin-bottom:.34rem;color:#24434c}input,textarea,select{width:100%;border:1px solid #bfd7dd;border-radius:14px;background:#fff;padding:.78rem .9rem;font:inherit;outline:none}textarea{min-height:86px;resize:vertical}input:focus,textarea:focus,select:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(7,138,166,.1)}button,.btn{border:0;border-radius:14px;padding:.78rem 1.15rem;font-weight:950;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:.45rem;font-family:inherit}.btn-primary{background:linear-gradient(135deg,var(--accent),#0b7085);color:#fff;box-shadow:0 10px 24px rgba(7,138,166,.22)}.btn-muted{background:#eef8fa;color:#31545d;border:1px solid #cce5ea}.btn-danger{background:#fff0f0;color:var(--red);border:1px solid #efcaca}button:disabled{opacity:.5;cursor:not-allowed}.hint{color:var(--muted);font-size:.8rem;line-height:1.7;margin-top:.45rem}.progress{height:10px;background:#deedf0;border-radius:999px;overflow:hidden;margin:.8rem 0}.bar{height:100%;width:0;background:linear-gradient(90deg,var(--accent),var(--green));transition:width .35s}.status-line{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}.status-pill{background:var(--soft);color:var(--accent);border-color:#c7e9ef}.done{background:#e7f7ed;color:var(--green);border-color:#ccebd8}.error{background:#fff0f0;color:var(--red);border-color:#f2cccc}.preview{display:grid;grid-template-columns:260px 1fr;gap:.8rem;margin-top:.8rem}.cover{border-radius:18px;overflow:hidden;border:1px solid var(--line);background:#f8fcfd;min-height:180px}.cover img{width:100%;height:100%;object-fit:cover;display:block}.video{border-radius:18px;overflow:hidden;border:1px solid var(--line);background:#f8fcfd;min-height:180px}.video video{width:100%;display:block;background:#fff}.actions{display:flex;gap:.55rem;flex-wrap:wrap;margin-top:.8rem}.history{display:grid;gap:.55rem}.job{display:grid;grid-template-columns:1fr auto;gap:.7rem;align-items:center;border:1px solid var(--line);background:#fff;border-radius:14px;padding:.72rem}.job button{text-align:left;justify-content:flex-start;background:#fff;color:var(--ink);border:1px solid transparent;padding:.5rem}.job button:hover{background:#f9fdfe;border-color:#bfdae1}.job strong{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.job small{display:block;color:var(--muted);font-size:.72rem;margin-top:.15rem}.login{text-align:center;padding:2.2rem}.login p{color:var(--muted);margin:.7rem 0 1rem}.toast{min-height:1.2rem;color:var(--muted);font-size:.8rem;margin-top:.6rem}@media(max-width:780px){header{padding:.65rem .8rem}.wrap{padding:.8rem}.grid,.tips,.preview{grid-template-columns:1fr}.userbar .name{display:none}}
</style>
</head>
<body>
<header>
  <a class="brand" href="<?php echo h($THIS_FILE); ?>" aria-label="Kurage Lo-Fi トップへ"><div class="logo">Lo</div><div><strong>Kurage Lo-Fi</strong><span>mp3アップロードから長尺BGM動画生成</span></div></a>
  <div class="userbar">
    <span class="badge <?php echo $api_ok ? 'api-ok' : 'api-err'; ?>"><?php echo $api_ok ? 'API ●' : 'API ×'; ?></span>
    <?php if ($logged_in): ?><span class="name">@<?php echo h($session_user); ?></span><a class="btn-sm" href="?klofi_logout=1">logout</a><?php else: ?><a class="btn-sm" href="?klofi_login=1">Xでログイン</a><?php endif; ?>
  </div>
</header>
<div class="wrap">
  <section class="hero">
    <div class="eyebrow">Long-form focus video</div>
    <h1>SunoのBGMを、60分のlo-fi動画にする。</h1>
    <p class="lead">mp3をアップロードすると、ファイル名やタイトルに合わせてERNIEで静止画像を生成し、HyperFrames構成HTMLと長尺mp4を作成します。YouTubeの作業用BGM、睡眠用BGM、学習用BGMの土台にできます。</p>
    <div class="tips">
      <div class="tip"><strong>30秒ループ</strong>試作は可能。ただし60分公開用では反復感が出やすいです。</div>
      <div class="tip"><strong>推奨</strong>Sunoの2〜5分以上の曲をループ。理想は5〜10分です。</div>
      <div class="tip"><strong>動画化</strong>ERNIE画像をゆっくりズームし、白系の上品なlo-fi画面にします。</div>
    </div>
  </section>

  <?php if (!$logged_in): ?>
  <section class="panel login"><h2>ログインが必要です</h2><p>lo-fi動画を生成するにはログインしてください。</p><a class="btn btn-primary" href="?klofi_login=1">Xでログインして始める</a></section>
  <?php else: ?>
  <section class="panel">
    <div class="panel-head"><span>lo-fi動画生成</span><small>mp3 / m4a / wav</small></div>
    <div class="panel-body">
      <form id="create-form" enctype="multipart/form-data">
        <div class="grid">
          <div>
            <div class="field"><label for="audio">BGMファイル</label><input id="audio" name="audio" type="file" accept="audio/mpeg,audio/mp3,.mp3" required><div class="hint">Sunoで作ったmp3を想定。30秒でも可、公開用は2〜5分以上推奨です。</div></div>
            <div class="field"><label for="title">タイトル（空ならファイル名から生成）</label><input id="title" name="title" type="text" placeholder="例: Deep Work Ocean Lo-Fi"></div>
          </div>
          <div>
            <div class="field"><label for="duration_minutes">動画時間</label><select id="duration_minutes" name="duration_minutes"><option value="60" selected>60分</option><option value="30">30分</option><option value="10">10分（テスト）</option><option value="3">3分（確認用）</option><option value="1">1分（最速テスト）</option></select></div>
            <div class="field"><label for="image_prompt">画像の追加指示（任意）</label><textarea id="image_prompt" name="image_prompt" placeholder="例: ocean desk, pale aqua daylight, jellyfish headphones"></textarea></div>
          </div>
        </div>
        <button id="generate" class="btn-primary" type="submit">生成する</button>
        <span id="message" class="toast"></span>
      </form>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><span>生成状況</span><small id="job-id">未開始</small></div>
    <div class="panel-body">
      <div class="status-line"><span id="status" class="badge status-pill">idle</span><strong id="job-title">タイトルはここに表示されます</strong></div>
      <div class="progress"><div id="progress" class="bar"></div></div>
      <div id="job-message" class="hint">mp3をアップロードすると、ERNIE画像生成、構成HTML作成、長尺動画レンダーの順で進みます。</div>
      <div class="preview">
        <div id="cover" class="cover"></div>
        <div id="video" class="video"></div>
      </div>
      <div class="actions">
        <a id="video-link" class="btn btn-primary" href="#" target="_blank" rel="noopener">動画を開く</a>
        <a id="html-link" class="btn btn-muted" href="#" target="_blank" rel="noopener">HyperFrames HTML</a>
        <button id="delete" class="btn-danger" disabled>削除</button>
      </div>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><span>最近の生成</span><button id="reload" class="btn-muted" type="button" style="padding:.38rem .7rem">更新</button></div>
    <div class="panel-body"><div id="history" class="history"></div></div>
  </section>
  <?php endif; ?>
</div>
<?php if ($logged_in): ?>
<script>
let currentJobId = null;
let pollTimer = null;
const $ = id => document.getElementById(id);
const esc = s => String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function msg(s){ $('message').textContent = s || ''; }
function pct(job){ return Math.max(0, Math.min(100, Number(job.progress || 0))); }
function label(job){ const m={queued:'待機中',image:'ERNIE画像生成中',composition:'構成HTML作成中',rendering:'動画レンダー中',done:'完了',error:'エラー'}; return m[job.status] || job.status || '不明'; }
function fileUrl(jobId, name){ return `<?php echo h($THIS_FILE); ?>?proxy=file&job_id=${encodeURIComponent(jobId)}&name=${encodeURIComponent(name)}`; }
async function json(url, opt){ const r = await fetch(url, opt || {}); const d = await r.json(); if(!r.ok || d.ok === false) throw new Error(d.detail || d.error || 'request failed'); return d; }
function render(job){
  currentJobId = job.job_id || currentJobId;
  $('job-id').textContent = currentJobId || '未開始';
  $('job-title').textContent = job.title || 'Kurage Lo-Fi';
  $('status').textContent = `${label(job)} / ${pct(job)}%`;
  $('status').className = 'badge ' + (job.status === 'done' ? 'done' : job.status === 'error' ? 'error' : 'status-pill');
  $('progress').style.width = pct(job) + '%';
  $('job-message').textContent = job.error || job.message || '';
  if (currentJobId && (job.cover_url || job.status === 'done')) $('cover').innerHTML = `<img src="${fileUrl(currentJobId,'cover.png')}" alt="cover">`;
  if (job.status === 'done' && currentJobId) {
    const v = fileUrl(currentJobId, 'output.mp4');
    $('video').innerHTML = `<video src="${v}" controls playsinline preload="metadata"></video>`;
    $('video-link').href = v;
    $('html-link').href = fileUrl(currentJobId, 'composition.html');
    $('delete').disabled = false;
  } else if (job.status === 'error') {
    $('video').innerHTML = `<div class="hint" style="padding:1rem;color:#bd4b4b">${esc(job.error || '生成に失敗しました')}</div>`;
    $('delete').disabled = false;
  } else {
    $('video').innerHTML = `<div class="hint" style="padding:1rem">動画生成中です。60分動画はレンダーに時間がかかります。</div>`;
    $('delete').disabled = !currentJobId;
  }
}
async function poll(jobId){ const job = await json(`<?php echo h($THIS_FILE); ?>?proxy=status&job_id=${encodeURIComponent(jobId)}`); render(job); history.replaceState(null,'',`?job=${encodeURIComponent(jobId)}`); if(job.status === 'done' || job.status === 'error'){ clearInterval(pollTimer); pollTimer = null; await loadHistory(); } return job; }
$('create-form').addEventListener('submit', async e => {
  e.preventDefault();
  $('generate').disabled = true; msg('アップロードしています...');
  try {
    const fd = new FormData(e.currentTarget);
    const data = await json('<?php echo h($THIS_FILE); ?>?proxy=create', {method:'POST', body:fd});
    currentJobId = data.job_id; msg(`ジョブ開始: ${currentJobId}`); clearInterval(pollTimer); const job = await poll(currentJobId); if(!['done','error'].includes(job.status)) pollTimer = setInterval(()=>poll(currentJobId).catch(err=>msg(err.message)), 5000);
  } catch(err) { msg(err.message || String(err)); }
  finally { $('generate').disabled = false; }
});
$('delete').addEventListener('click', async () => { if(!currentJobId || !confirm('このlo-fi生成ジョブを削除しますか？')) return; await json(`<?php echo h($THIS_FILE); ?>?proxy=delete&job_id=${encodeURIComponent(currentJobId)}`, {method:'POST'}); currentJobId=null; $('job-id').textContent='削除済み'; $('status').textContent='deleted'; $('progress').style.width='0%'; $('cover').innerHTML=''; $('video').innerHTML=''; await loadHistory(); });
async function openJob(id){ clearInterval(pollTimer); const job = await poll(id); if(!['done','error'].includes(job.status)) pollTimer = setInterval(()=>poll(id).catch(err=>msg(err.message)), 5000); }
async function loadHistory(){
  const data = await json('<?php echo h($THIS_FILE); ?>?proxy=jobs&limit=20');
  const box = $('history'); box.innerHTML = '';
  for (const job of data.jobs || []) {
    const div = document.createElement('div'); div.className='job';
    div.innerHTML = `<button type="button"><strong>${esc(job.title || 'Kurage Lo-Fi')}</strong><small>${esc(label(job))} / ${esc(job.progress || 0)}% / ${esc(job.duration_minutes || '-')}分 / ${esc(job.created_at || '')}</small></button><small>${esc(job.original_filename || '')}</small>`;
    div.querySelector('button').addEventListener('click', () => openJob(job.job_id));
    box.appendChild(div);
  }
  if(!box.innerHTML) box.innerHTML = '<div class="hint">まだ生成履歴がありません。</div>';
}
$('reload').addEventListener('click', loadHistory);
(async()=>{ await loadHistory(); const id = new URLSearchParams(location.search).get('job'); if(id) await openJob(id); })().catch(e=>msg(e.message || String(e)));
</script>
<?php endif; ?>
</body>
</html>
