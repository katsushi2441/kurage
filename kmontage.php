<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_common.php';
date_default_timezone_set('Asia/Tokyo');

$THIS_FILE     = 'kmontage.php';
$SITE_NAME     = 'Kurage Montage — URL要約ショート生成';
$KMONTAGE_API  = rtrim(getenv('KMONTAGE_API') ?: 'http://exbridge.ddns.net:18305', '/');

if (isset($_GET['kmontage_logout'])) {
    header('Location: ' . url2ai_auth_logout_url('/' . $THIS_FILE));
    exit;
}
if (isset($_GET['kmontage_login'])) {
    header('Location: ' . url2ai_auth_login_url('/' . $THIS_FILE));
    exit;
}

$auth         = url2ai_auth_bootstrap();
$logged_in    = $auth['logged_in'];
$session_user = $auth['session_user'];
$is_admin     = $auth['is_admin'];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function kmontage_api($method, $path, $payload = null, $timeout = 30) {
    global $KMONTAGE_API;
    $url = $KMONTAGE_API . $path;
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

$proxy_action = isset($_GET['proxy']) ? $_GET['proxy'] : '';
if ($proxy_action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$logged_in) {
        echo json_encode(array('ok' => false, 'error' => 'login required'), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($proxy_action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = file_get_contents('php://input');
        $payload = json_decode($body, true);
        if (!is_array($payload)) { $payload = array(); }
        $payload['vtuber_mode'] = true;
        if (empty($payload['video_style'])) { $payload['video_style'] = 'ai_avatar_explainer'; }
        $res = kmontage_api('POST', '/api/jobs', $payload, 60);
        echo json_encode(isset($res['data']) ? $res['data'] : array('ok'=>false,'error'=>isset($res['error'])?$res['error']:'API unreachable'), JSON_UNESCAPED_UNICODE);
    } elseif ($proxy_action === 'regenerate' && isset($_GET['job_id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $jid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['job_id']);
        $body = file_get_contents('php://input');
        $payload = json_decode($body, true);
        if (!is_array($payload)) { $payload = array(); }
        $payload['vtuber_mode'] = true;
        if (empty($payload['video_style'])) { $payload['video_style'] = 'ai_avatar_explainer'; }
        $res = kmontage_api('POST', '/api/jobs/' . $jid . '/regenerate', $payload, 60);
        echo json_encode(isset($res['data']) ? $res['data'] : array('ok'=>false,'error'=>isset($res['error'])?$res['error']:'API unreachable'), JSON_UNESCAPED_UNICODE);
    } elseif ($proxy_action === 'status' && isset($_GET['job_id'])) {
        $jid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['job_id']);
        $res = kmontage_api('GET', '/api/jobs/' . $jid, null, 30);
        echo json_encode(isset($res['data']) ? $res['data'] : array('ok'=>false,'error'=>isset($res['error'])?$res['error']:'API unreachable'), JSON_UNESCAPED_UNICODE);
    } elseif ($proxy_action === 'jobs') {
        $limit = isset($_GET['limit']) ? max(1, min(50, (int)$_GET['limit'])) : 20;
        $res = kmontage_api('GET', '/api/jobs?limit=' . $limit, null, 20);
        echo json_encode(isset($res['data']) ? $res['data'] : array('ok'=>false,'jobs'=>array()), JSON_UNESCAPED_UNICODE);
    } elseif ($proxy_action === 'delete' && isset($_GET['job_id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $jid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['job_id']);
        $res = kmontage_api('DELETE', '/api/jobs/' . $jid, null, 30);
        echo json_encode(isset($res['data']) ? $res['data'] : array('ok'=>false,'error'=>isset($res['error'])?$res['error']:'API unreachable'), JSON_UNESCAPED_UNICODE);
    } elseif ($proxy_action === 'health') {
        $res = kmontage_api('GET', '/api/health', null, 10);
        echo json_encode(isset($res['data']) ? $res['data'] : array('ok'=>false), JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(array('ok' => false, 'error' => 'unknown action'), JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$api_ok = false;
$health = kmontage_api('GET', '/api/health', null, 5);
if ($health['ok'] && isset($health['data']['ok'])) { $api_ok = true; }
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($SITE_NAME); ?></title>
<style>
:root{--bg:#f6fbfc;--surface:#fff;--ink:#132329;--muted:#667982;--line:#d8e8ec;--accent:#078aa6;--accent2:#f7b955;--green:#2f9d62;--red:#bd4b4b;--soft:#e9f8fb;}
*{box-sizing:border-box;margin:0;padding:0}body{min-height:100vh;background:radial-gradient(circle at 14% 8%,rgba(105,210,230,.26),transparent 30%),radial-gradient(circle at 90% 0%,rgba(255,211,130,.36),transparent 28%),linear-gradient(180deg,#fff 0%,#f6fbfc 55%,#eaf7f9 100%);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;font-size:14px;}header{position:sticky;top:0;z-index:20;background:rgba(255,255,255,.88);backdrop-filter:blur(12px);border-bottom:1px solid var(--line);padding:.75rem 1.2rem;display:flex;align-items:center;justify-content:space-between;gap:1rem}.brand{display:flex;align-items:center;gap:.7rem;color:var(--ink);text-decoration:none}.brand img{width:42px;height:42px;border-radius:50%;object-fit:cover;box-shadow:0 6px 18px rgba(7,138,166,.16)}.brand strong{display:block;font-size:1rem;font-weight:900}.brand span{display:block;color:var(--muted);font-size:.74rem;margin-top:.1rem}.userbar{display:flex;align-items:center;gap:.55rem;color:var(--muted);font-size:.78rem}.badge{display:inline-flex;align-items:center;border-radius:999px;padding:.18rem .58rem;font-size:.72rem;font-weight:800;border:1px solid transparent}.api-ok{background:#e7f7ed;color:var(--green);border-color:#ccebd8}.api-err{background:#fff0f0;color:var(--red);border-color:#f2cccc}.btn-sm{color:var(--muted);text-decoration:none;border:1px solid var(--line);border-radius:999px;padding:.25rem .7rem;background:#fff}.wrap{max-width:1040px;margin:0 auto;padding:1.2rem}.hero{margin:.5rem 0 1rem}.hero-card,.panel{background:rgba(255,255,255,.86);border:1px solid var(--line);border-radius:20px;box-shadow:0 12px 40px rgba(19,35,41,.07);overflow:hidden}.hero-card{padding:1.35rem 1.45rem}.eyebrow{font-size:.72rem;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:var(--accent);margin-bottom:.6rem}.hero h1{font-size:clamp(1.65rem,4vw,2.8rem);line-height:1.08;letter-spacing:-.04em;margin-bottom:.8rem}.lead{color:var(--muted);line-height:1.85;font-size:.92rem}.mini{display:none}.panel{margin-bottom:1rem}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:.8rem;padding:.8rem 1rem;background:rgba(245,251,252,.9);border-bottom:1px solid var(--line);font-weight:900}.panel-head small{font-weight:700;color:var(--muted)}.panel-body{padding:1rem}.input-row{display:flex;gap:.6rem}input[type=url]{flex:1;border:1px solid #bdd4da;border-radius:14px;background:#fff;padding:.8rem .9rem;font-size:.9rem;outline:none}input[type=url]:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(7,138,166,.1)}button,.btn{border:0;border-radius:14px;padding:.78rem 1.15rem;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:.45rem;font-family:inherit}.btn-primary{background:linear-gradient(135deg,var(--accent),#0a6f84);color:#fff;box-shadow:0 8px 20px rgba(7,138,166,.2)}.btn-muted{background:#eef7f9;color:#31525c;border:1px solid #cce5ea}.btn-danger{background:#fff0f0;color:var(--red);border:1px solid #efcaca}button:disabled{opacity:.5;cursor:not-allowed}.hint{margin-top:.6rem;color:var(--muted);font-size:.78rem;line-height:1.7}.status-line{display:flex;align-items:center;gap:.55rem;flex-wrap:wrap}.progress{height:9px;background:#dcecef;border-radius:999px;overflow:hidden;margin:.8rem 0}.bar{height:100%;width:0;background:linear-gradient(90deg,var(--accent),var(--green));transition:width .35s}.status-pill{background:var(--soft);color:var(--accent);border-color:#c7e9ef}.done{background:#e7f7ed;color:var(--green);border-color:#ccebd8}.error{background:#fff0f0;color:var(--red);border-color:#f2cccc}.summary{background:#f7fcfd;border:1px solid var(--line);border-radius:14px;padding:.8rem;margin-top:.8rem;color:#29464e;line-height:1.75;white-space:pre-wrap}.script{margin:.8rem 0 0 1.2rem;color:#304f57;line-height:1.7}.player{margin-top:.9rem;border-radius:18px;overflow:hidden;border:1px solid var(--line);background:#f9fdfe;min-height:0}.player video{display:block;width:100%;max-height:520px;background:#000}.actions{display:flex;gap:.55rem;flex-wrap:wrap;margin-top:.8rem}.history{display:grid;gap:.55rem}.job{display:grid;grid-template-columns:1fr auto;gap:.7rem;align-items:center;border:1px solid var(--line);background:#fff;border-radius:14px;padding:.75rem}.job strong{display:block;font-size:.86rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.job small{display:block;margin-top:.18rem;color:var(--muted);font-size:.72rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.login{padding:2rem;text-align:center}.login p{color:var(--muted);margin:.7rem 0 1rem}.toast{min-height:1.2rem;color:var(--muted);font-size:.8rem;margin-top:.65rem}@media(max-width:760px){header{padding:.65rem .8rem}.wrap{padding:.8rem}.input-row{flex-direction:column}.player video{max-height:460px}.userbar .name{display:none}}
</style>
<style>
.job-main{display:block;width:100%;text-align:left;border:1px solid transparent;border-radius:12px;background:#fff;color:var(--ink);box-shadow:none;padding:.55rem}.job-main:hover{border-color:#b9d8e8;background:#f9fdfe}@media(max-width:760px){.job{grid-template-columns:1fr}}
</style>
</head>
<body>
<header>
  <a class="brand" href="<?php echo h($THIS_FILE); ?>" aria-label="Kurage Montage トップへ">
    <img src="images/kurage-icon.png" alt="Kurage">
    <div><strong>Kurage Montage</strong><span>X・YouTube・ブログ・PDFからショート動画生成</span></div>
  </a>
  <div class="userbar">
    <span class="badge <?php echo $api_ok ? 'api-ok' : 'api-err'; ?>"><?php echo $api_ok ? 'API ●' : 'API ×'; ?></span>
    <?php if ($logged_in): ?>
      <span class="name">@<?php echo h($session_user); ?></span><a class="btn-sm" href="?kmontage_logout=1">logout</a>
    <?php else: ?>
      <a class="btn-sm" href="?kmontage_login=1">Xでログイン</a>
    <?php endif; ?>
  </div>
</header>

<div class="wrap">
  <section class="hero">
    <div class="hero-card">
      <div class="eyebrow">Kurage Montage</div>
      <h1>URLから、要点が伝わるショート動画を生成。</h1>
      <p class="lead">X記事、YouTube動画、ブログ記事、PDF資料のURLを貼るだけで、内容を読み取り、日本語のKurageショート動画を作成します。長い資料や話題の投稿を、見やすい短尺コンテンツに変換できます。</p>
    </div>
  </section>

  <?php if (!$logged_in): ?>
  <section class="panel login">
    <h2>ログインが必要です</h2>
    <p>X・YouTube・ブログ・PDFからショート動画を生成するにはログインしてください。</p>
    <a class="btn btn-primary" href="?kmontage_login=1">Xでログインして始める</a>
  </section>
  <?php else: ?>

  <section class="panel">
    <div class="panel-head"><span>要約ショート生成</span><small>X / YouTube / ブログ / PDF URL</small></div>
    <div class="panel-body">
      <div class="input-row">
        <input id="source-url" type="url" placeholder="https://x.com/... または https://example.com/article.pdf">
        <button id="generate" class="btn-primary">生成する</button>
      </div>
      <div class="hint">長い動画や資料は、取得・文字起こし・本文解析に数分かかることがあります。生成完了後、Kurageの動画として表示されます。</div>
      <div id="message" class="toast"></div>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><span>生成状況</span><small id="job-id">未開始</small></div>
    <div class="panel-body">
      <div class="status-line"><span id="status" class="badge status-pill">idle</span><strong id="title">タイトルはここに表示されます</strong></div>
      <div class="progress"><div id="progress" class="bar"></div></div>
      <div id="summary" class="summary">動画の要点と生成された台本がここに表示されます。</div>
      <ol id="script" class="script"></ol>
      <div id="player" class="player" style="display:none;"></div>
      <div class="actions">
        <a id="kurage-link" class="btn btn-primary" href="#" target="_blank" rel="noopener">Kurageで開く</a>
        <button id="copy" class="btn-muted" disabled>コピー</button>
        <button id="post-x" class="btn-muted" disabled>X投稿</button>
        <button id="delete" class="btn-danger" disabled>削除</button>
      </div>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><span>最近の生成</span><button id="reload" class="btn-muted" style="padding:.38rem .7rem;">更新</button></div>
    <div class="panel-body"><div id="history" class="history"></div></div>
  </section>

  <?php endif; ?>
</div>

<?php if ($logged_in): ?>
<script>
let currentJobId = null;
let currentJobUrl = '';
let pollTimer = null;
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s || '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
function message(text){ $('message').textContent = text || ''; }
function setActions(enabled){ $('copy').disabled = !enabled; $('post-x').disabled = !enabled; $('delete').disabled = !currentJobId; }
function scriptLines(job){ const script = job.kurage_script || job.script || {}; const scenes = Array.isArray(script.scenes) ? script.scenes : []; if (scenes.length) return scenes.map(s => s.narration || '').filter(Boolean); return Array.isArray(job.script_outline) ? job.script_outline : []; }
function statusLabel(job){ const labels = {queued:'待機中',analyzing:'URL解析中',downloading:'元動画取得中',transcribing:'文字起こし中',planning:'台本生成中',generating:'Kurage動画生成中',done:'完了',error:'エラー'}; return labels[job.status] || job.status || '不明'; }
function progressText(job){ const p = Math.max(0, Math.min(100, Number(job.failed_at_progress ?? job.progress ?? 0))); return job.status === 'error' ? `エラー（${p}%で停止）` : `${statusLabel(job)} / ${p}%`; }
function jobTitle(job){ return job.kurage_title || job.title || job.source_title || job.url || '生成中'; }
function renderJob(job){
  currentJobId = job.id || currentJobId;
  currentJobUrl = job.url || '';
  if (job.url) $('source-url').value = job.url;
  $('job-id').textContent = currentJobId || '未開始';
  const st = job.status || 'unknown';
  $('status').textContent = progressText(job);
  $('status').className = 'badge ' + (st === 'done' ? 'done' : st === 'error' ? 'error' : 'status-pill');
  $('progress').style.width = `${Math.max(0, Math.min(100, Number(job.progress || 0)))}%`;
  $('title').textContent = jobTitle(job);
  $('summary').textContent = job.summary || job.reference_analysis?.core_claim || job.analysis?.reference_analysis?.core_claim || job.transcript_preview || '解析中です。';
  const list = $('script'); list.innerHTML = '';
  for (const line of scriptLines(job)) { const li = document.createElement('li'); li.textContent = line; list.appendChild(li); }
  const link = job.video_url || job.kurage_url || '#'; $('kurage-link').href = link;
  if (st === 'done' && job.kurage_job_id) {
    $('player').style.display = 'block';
    const videoUrl = `https://kurage.exbridge.jp/kuragev.php?proxy=video&job_id=${encodeURIComponent(job.kurage_job_id)}`;
    $('player').innerHTML = `<video src="${videoUrl}" controls playsinline preload="metadata"></video>`;
    setActions(true);
  } else if (st === 'error') {
    $('player').style.display = 'block'; $('player').innerHTML = `<div style="padding:1rem;color:#bd4b4b;">エラー: ${esc(job.error || '')}</div>`; setActions(false);
  } else { $('player').style.display = 'none'; $('player').innerHTML = ''; setActions(false); }
}
async function fetchJson(url, options){ const res = await fetch(url, options || {}); const data = await res.json(); if (!res.ok || data.ok === false) throw new Error(data.detail || data.error || 'request failed'); return data; }
async function poll(jobId){ const job = await fetchJson(`<?php echo h($THIS_FILE); ?>?proxy=status&job_id=${encodeURIComponent(jobId)}`); renderJob(job); history.replaceState(null, '', `?job=${encodeURIComponent(jobId)}`); if (job.status === 'done' || job.status === 'error') { clearInterval(pollTimer); pollTimer = null; await loadHistory(); } return job; }
$('generate').addEventListener('click', async () => {
  const url = $('source-url').value.trim(); if (!url) return message('URLを入力してください');
  $('generate').disabled = true; message('生成ジョブを開始しています...');
  try { const sameLoadedUrl = currentJobId && currentJobUrl && url === currentJobUrl; const endpoint = sameLoadedUrl ? `<?php echo h($THIS_FILE); ?>?proxy=regenerate&job_id=${encodeURIComponent(currentJobId)}` : '<?php echo h($THIS_FILE); ?>?proxy=create'; const data = await fetchJson(endpoint, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({url, vtuber_mode:true, video_style:'ai_avatar_explainer'})}); currentJobId = data.job_id; currentJobUrl = url; message(`${sameLoadedUrl ? '上書き再生成' : 'ジョブ開始'}: ${currentJobId}`); clearInterval(pollTimer); const job = await poll(currentJobId); if (!['done','error'].includes(job.status)) pollTimer = setInterval(() => poll(currentJobId), 5000); }
  catch(e){ message(e.message || String(e)); }
  finally { $('generate').disabled = false; }
});
function shareText(){ return `${$('title').textContent}\n${$('summary').textContent}\n${$('kurage-link').href}`; }
$('copy').addEventListener('click', async () => { const text = shareText(); await navigator.clipboard.writeText(text); message('コピーしました'); });
$('post-x').addEventListener('click', () => { const text = shareText(); window.open(`https://x.com/intent/tweet?text=${encodeURIComponent(text)}`, '_blank', 'noopener'); });
$('delete').addEventListener('click', async () => { if (!currentJobId || !confirm('この生成ジョブとKurage動画を削除しますか？')) return; await fetchJson(`<?php echo h($THIS_FILE); ?>?proxy=delete&job_id=${encodeURIComponent(currentJobId)}`, {method:'POST'}); currentJobId = null; currentJobUrl = ''; $('job-id').textContent='削除済み'; $('status').textContent='deleted'; $('title').textContent='タイトルはここに表示されます'; $('summary').textContent='動画の要点と生成された台本がここに表示されます。'; $('script').innerHTML=''; $('player').style.display='none'; setActions(false); await loadHistory(); });
async function openJob(job){ currentJobId = job.id; const latest = await poll(job.id); clearInterval(pollTimer); if (!['done','error'].includes(latest.status)) pollTimer = setInterval(() => poll(job.id), 5000); }
async function loadHistory(){
  const data = await fetchJson('<?php echo h($THIS_FILE); ?>?proxy=jobs&limit=20');
  const box = $('history');
  box.innerHTML = '';
  for (const job of data.jobs || []) {
    const div = document.createElement('div');
    div.className = 'job';
    const kurage = job.kurage_job_id ? `<small>Kurage: ${esc(job.kurage_status || '-')} / ${esc(job.kurage_progress ?? '-')}%</small>` : '';
    div.innerHTML = `<button class="job-main" data-id="${esc(job.id)}" type="button"><strong>${esc(jobTitle(job))}</strong><small>${esc(progressText(job))} / ${esc(job.url || '')}</small>${kurage}</button>`;
    div.querySelector('button').addEventListener('click', async () => openJob(job));
    box.appendChild(div);
  }
  if (!box.innerHTML) box.innerHTML = '<div class="hint">まだ生成履歴がありません。</div>';
}
$('reload').addEventListener('click', loadHistory);

$('source-url').addEventListener('input', () => { if ($('source-url').value.trim() !== currentJobUrl) currentJobId = null; });
async function openInitialJob(){ await loadHistory(); const jobId = new URLSearchParams(location.search).get('job'); if (jobId) { const job = await poll(jobId); clearInterval(pollTimer); if (!['done','error'].includes(job.status)) pollTimer = setInterval(() => poll(jobId), 5000); } }
openInitialJob().catch(e => message(e.message || String(e)));
</script>
<?php endif; ?>
</body>
</html>
