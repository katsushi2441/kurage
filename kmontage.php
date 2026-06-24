<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_common.php';
date_default_timezone_set('Asia/Tokyo');

$THIS_FILE     = 'kmontage.php';
$SITE_NAME     = 'Kurage Montage — 参照動画ショート生成';
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
*{box-sizing:border-box;margin:0;padding:0}body{min-height:100vh;background:radial-gradient(circle at 14% 8%,rgba(105,210,230,.26),transparent 30%),radial-gradient(circle at 90% 0%,rgba(255,211,130,.36),transparent 28%),linear-gradient(180deg,#fff 0%,#f6fbfc 55%,#eaf7f9 100%);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;font-size:14px;}header{position:sticky;top:0;z-index:20;background:rgba(255,255,255,.88);backdrop-filter:blur(12px);border-bottom:1px solid var(--line);padding:.75rem 1.2rem;display:flex;align-items:center;justify-content:space-between;gap:1rem}.brand{display:flex;align-items:center;gap:.7rem}.brand img{width:42px;height:42px;border-radius:50%;object-fit:cover;box-shadow:0 6px 18px rgba(7,138,166,.16)}.brand strong{display:block;font-size:1rem;font-weight:900}.brand span{display:block;color:var(--muted);font-size:.74rem;margin-top:.1rem}.userbar{display:flex;align-items:center;gap:.55rem;color:var(--muted);font-size:.78rem}.badge{display:inline-flex;align-items:center;border-radius:999px;padding:.18rem .58rem;font-size:.72rem;font-weight:800;border:1px solid transparent}.api-ok{background:#e7f7ed;color:var(--green);border-color:#ccebd8}.api-err{background:#fff0f0;color:var(--red);border-color:#f2cccc}.btn-sm{color:var(--muted);text-decoration:none;border:1px solid var(--line);border-radius:999px;padding:.25rem .7rem;background:#fff}.wrap{max-width:1040px;margin:0 auto;padding:1.2rem}.hero{display:grid;grid-template-columns:1.1fr .9fr;gap:1rem;align-items:stretch;margin:.5rem 0 1rem}.hero-card,.panel{background:rgba(255,255,255,.86);border:1px solid var(--line);border-radius:20px;box-shadow:0 12px 40px rgba(19,35,41,.07);overflow:hidden}.hero-card{padding:1.4rem}.eyebrow{font-size:.72rem;font-weight:900;letter-spacing:.12em;text-transform:uppercase;color:var(--accent);margin-bottom:.6rem}.hero h1{font-size:clamp(1.65rem,4vw,2.8rem);line-height:1.08;letter-spacing:-.04em;margin-bottom:.8rem}.lead{color:var(--muted);line-height:1.85;font-size:.92rem}.mini{padding:1.1rem;background:linear-gradient(135deg,#eafdff,#fff7e4);display:flex;flex-direction:column;justify-content:center}.mini strong{font-size:1.05rem}.mini ul{margin-top:.8rem;display:grid;gap:.42rem;list-style:none;color:#385760;font-size:.83rem}.mini li:before{content:"";display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--accent2);margin-right:.45rem}.panel{margin-bottom:1rem}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:.8rem;padding:.8rem 1rem;background:rgba(245,251,252,.9);border-bottom:1px solid var(--line);font-weight:900}.panel-head small{font-weight:700;color:var(--muted)}.panel-body{padding:1rem}.input-row{display:flex;gap:.6rem}input[type=url]{flex:1;border:1px solid #bdd4da;border-radius:14px;background:#fff;padding:.8rem .9rem;font-size:.9rem;outline:none}input[type=url]:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(7,138,166,.1)}button,.btn{border:0;border-radius:14px;padding:.78rem 1.15rem;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:.45rem;font-family:inherit}.btn-primary{background:linear-gradient(135deg,var(--accent),#0a6f84);color:#fff;box-shadow:0 8px 20px rgba(7,138,166,.2)}.btn-muted{background:#eef7f9;color:#31525c;border:1px solid #cce5ea}.btn-danger{background:#fff0f0;color:var(--red);border:1px solid #efcaca}button:disabled{opacity:.5;cursor:not-allowed}.hint{margin-top:.6rem;color:var(--muted);font-size:.78rem;line-height:1.7}.status-line{display:flex;align-items:center;gap:.55rem;flex-wrap:wrap}.progress{height:9px;background:#dcecef;border-radius:999px;overflow:hidden;margin:.8rem 0}.bar{height:100%;width:0;background:linear-gradient(90deg,var(--accent),var(--green));transition:width .35s}.status-pill{background:var(--soft);color:var(--accent);border-color:#c7e9ef}.done{background:#e7f7ed;color:var(--green);border-color:#ccebd8}.error{background:#fff0f0;color:var(--red);border-color:#f2cccc}.summary{background:#f7fcfd;border:1px solid var(--line);border-radius:14px;padding:.8rem;margin-top:.8rem;color:#29464e;line-height:1.75;white-space:pre-wrap}.script{margin:.8rem 0 0 1.2rem;color:#304f57;line-height:1.7}.player{margin-top:.9rem;border-radius:18px;overflow:hidden;border:1px solid var(--line);background:#f9fdfe;min-height:360px}.player iframe{display:block;width:100%;height:620px;border:0}.actions{display:flex;gap:.55rem;flex-wrap:wrap;margin-top:.8rem}.history{display:grid;gap:.55rem}.job{display:grid;grid-template-columns:1fr auto;gap:.7rem;align-items:center;border:1px solid var(--line);background:#fff;border-radius:14px;padding:.75rem}.job strong{display:block;font-size:.86rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.job small{display:block;margin-top:.18rem;color:var(--muted);font-size:.72rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.login{padding:2rem;text-align:center}.login p{color:var(--muted);margin:.7rem 0 1rem}.toast{min-height:1.2rem;color:var(--muted);font-size:.8rem;margin-top:.65rem}@media(max-width:760px){header{padding:.65rem .8rem}.wrap{padding:.8rem}.hero{grid-template-columns:1fr}.input-row{flex-direction:column}.player iframe{height:560px}.userbar .name{display:none}}
</style>
</head>
<body>
<header>
  <div class="brand">
    <img src="images/kurage-icon.png" alt="Kurage">
    <div><strong>Kurage Montage</strong><span>参照動画から日本語ショート考察動画へ</span></div>
  </div>
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
      <div class="eyebrow">Reference Video To Kurage Short</div>
      <h1>バズったX/YouTube動画を、忠実な日本語ショート解説へ。</h1>
      <p class="lead">元動画の数字、手順、ツール、注意点を抽出し、OpenMontage型の中間成果物を残しながら、Kurage VTuber動画として生成します。</p>
    </div>
    <div class="mini panel">
      <strong>今回の設計ポイント</strong>
      <ul>
        <li>generate_from_newsで薄めず、scriptを直接Kurageへ渡す</li>
        <li>reference_analysis / scene_plan / qa を保存</li>
        <li>生成後はkuragev.phpの動画として表示</li>
      </ul>
    </div>
  </section>

  <?php if (!$logged_in): ?>
  <section class="panel login">
    <h2>ログインが必要です</h2>
    <p>X/YouTube URLからKurage Montage動画を生成するにはログインしてください。</p>
    <a class="btn btn-primary" href="?kmontage_login=1">Xでログインして始める</a>
  </section>
  <?php else: ?>

  <section class="panel">
    <div class="panel-head"><span>要約ショート生成</span><small>X URL / YouTube URL</small></div>
    <div class="panel-body">
      <div class="input-row">
        <input id="source-url" type="url" placeholder="https://x.com/... または https://www.youtube.com/watch?v=...">
        <button id="generate" class="btn-primary">生成する</button>
      </div>
      <div class="hint">47分のような長尺動画は、取得・文字起こし・分析に数分かかります。途中で止めずに待ちます。</div>
      <div id="message" class="toast"></div>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><span>生成状況</span><small id="job-id">未開始</small></div>
    <div class="panel-body">
      <div class="status-line"><span id="status" class="badge status-pill">idle</span><strong id="title">タイトルはここに表示されます</strong></div>
      <div class="progress"><div id="progress" class="bar"></div></div>
      <div id="summary" class="summary">参照動画の要点、数字、手順、注意点がここに表示されます。</div>
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
let pollTimer = null;
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s || '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
function message(text){ $('message').textContent = text || ''; }
function setActions(enabled){ $('copy').disabled = !enabled; $('post-x').disabled = !enabled; $('delete').disabled = !currentJobId; }
function scriptLines(job){ const script = job.kurage_script || job.script || {}; const scenes = Array.isArray(script.scenes) ? script.scenes : []; if (scenes.length) return scenes.map(s => s.narration || '').filter(Boolean); return Array.isArray(job.script_outline) ? job.script_outline : []; }
function renderJob(job){
  currentJobId = job.id || currentJobId;
  $('job-id').textContent = currentJobId || '未開始';
  const st = job.status || 'unknown';
  $('status').textContent = `${st} ${job.progress ?? 0}%`;
  $('status').className = 'badge ' + (st === 'done' ? 'done' : st === 'error' ? 'error' : 'status-pill');
  $('progress').style.width = `${Math.max(0, Math.min(100, Number(job.progress || 0)))}%`;
  $('title').textContent = job.kurage_title || job.title || '生成中';
  $('summary').textContent = job.summary || job.reference_analysis?.core_claim || job.analysis?.reference_analysis?.core_claim || job.transcript_preview || '解析中です。';
  const list = $('script'); list.innerHTML = '';
  for (const line of scriptLines(job)) { const li = document.createElement('li'); li.textContent = line; list.appendChild(li); }
  const link = job.video_url || job.kurage_url || '#'; $('kurage-link').href = link;
  if (st === 'done' && job.kurage_job_id) {
    $('player').style.display = 'block';
    $('player').innerHTML = `<iframe src="https://kurage.exbridge.jp/kuragev.php?id=${esc(job.kurage_job_id)}" loading="lazy" allow="autoplay; fullscreen"></iframe>`;
    setActions(true);
  } else if (st === 'error') {
    $('player').style.display = 'block'; $('player').innerHTML = `<div style="padding:1rem;color:#bd4b4b;">エラー: ${esc(job.error || '')}</div>`; setActions(false);
  } else { $('player').style.display = 'none'; $('player').innerHTML = ''; setActions(false); }
}
async function fetchJson(url, options){ const res = await fetch(url, options || {}); const data = await res.json(); if (!res.ok || data.ok === false) throw new Error(data.detail || data.error || 'request failed'); return data; }
async function poll(jobId){ const job = await fetchJson(`<?php echo h($THIS_FILE); ?>?proxy=status&job_id=${encodeURIComponent(jobId)}`); renderJob(job); if (job.status === 'done' || job.status === 'error') { clearInterval(pollTimer); pollTimer = null; await loadHistory(); } }
$('generate').addEventListener('click', async () => {
  const url = $('source-url').value.trim(); if (!url) return message('URLを入力してください');
  $('generate').disabled = true; message('生成ジョブを開始しています...');
  try { const data = await fetchJson('<?php echo h($THIS_FILE); ?>?proxy=create', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({url, vtuber_mode:true, video_style:'ai_avatar_explainer'})}); currentJobId = data.job_id; message(`ジョブ開始: ${currentJobId}`); clearInterval(pollTimer); await poll(currentJobId); pollTimer = setInterval(() => poll(currentJobId), 5000); }
  catch(e){ message(e.message || String(e)); }
  finally { $('generate').disabled = false; }
});
$('copy').addEventListener('click', async () => { const text = `${$('title').textContent}\n${$('summary').textContent}\n${$('kurage-link').href}`; await navigator.clipboard.writeText(text); message('コピーしました'); });
$('post-x').addEventListener('click', () => { const text = `${$('title').textContent}\n${$('kurage-link').href}`; window.open(`https://x.com/intent/tweet?text=${encodeURIComponent(text)}`, '_blank', 'noopener'); });
$('delete').addEventListener('click', async () => { if (!currentJobId || !confirm('この生成ジョブとKurage動画を削除しますか？')) return; await fetchJson(`<?php echo h($THIS_FILE); ?>?proxy=delete&job_id=${encodeURIComponent(currentJobId)}`, {method:'POST'}); currentJobId = null; $('job-id').textContent='削除済み'; $('status').textContent='deleted'; $('title').textContent='タイトルはここに表示されます'; $('summary').textContent='参照動画の要点、数字、手順、注意点がここに表示されます。'; $('script').innerHTML=''; $('player').style.display='none'; setActions(false); await loadHistory(); });
async function loadHistory(){ const data = await fetchJson('<?php echo h($THIS_FILE); ?>?proxy=jobs&limit=20'); const box = $('history'); box.innerHTML = ''; for (const job of data.jobs || []) { const div = document.createElement('div'); div.className = 'job'; div.innerHTML = `<div><strong>${esc(job.kurage_title || job.title || job.url)}</strong><small>${esc(job.status)} / ${esc(job.url || '')}</small></div><button class="btn-muted" data-id="${esc(job.id)}">表示</button>`; div.querySelector('button').addEventListener('click', async () => { currentJobId = job.id; await poll(job.id); }); box.appendChild(div); } if (!box.innerHTML) box.innerHTML = '<div class="hint">まだ生成履歴がありません。</div>'; }
$('reload').addEventListener('click', loadHistory);
loadHistory().catch(e => message(e.message || String(e)));
</script>
<?php endif; ?>
</body>
</html>
