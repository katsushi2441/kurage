<?php
require_once __DIR__ . '/config.php';
if (is_file(__DIR__ . '/kmontage_config.php')) { require_once __DIR__ . '/kmontage_config.php'; }
require_once __DIR__ . '/auth_common.php';
require_once __DIR__ . '/kmontage_billing.php';
date_default_timezone_set('Asia/Tokyo');

$THIS_FILE     = 'kmontage.php';
$SITE_NAME     = 'Kurage Montage — URL要約ショート生成';
$KMONTAGE_API  = rtrim(getenv('KMONTAGE_API') ?: 'http://exbridge.ddns.net:18305', '/');
$KMONTAGE_INTERNAL_TOKEN = defined('KMONTAGE_INTERNAL_TOKEN') ? KMONTAGE_INTERNAL_TOKEN : (getenv('KMONTAGE_INTERNAL_TOKEN') ?: '');

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
$csrf         = isset($_SESSION['kmontage_csrf']) ? (string)$_SESSION['kmontage_csrf'] : '';
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(24));
    $_SESSION['kmontage_csrf'] = $csrf;
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function kmontage_api($method, $path, $payload = null, $timeout = 30) {
    global $KMONTAGE_API, $KMONTAGE_INTERNAL_TOKEN, $session_user;
    $url = $KMONTAGE_API . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    $headers = array('Content-Type: application/json', 'Accept: application/json');
    if ($KMONTAGE_INTERNAL_TOKEN !== '') { $headers[] = 'X-KMontage-Token: ' . $KMONTAGE_INTERNAL_TOKEN; }
    if ($session_user !== '') { $headers[] = 'X-KMontage-User: ' . $session_user; }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
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

function kmontage_json_response($data, $status = 200) {
    http_response_code((int)$status);
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function kmontage_require_csrf($expected) {
    $sent = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string)$_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    if ($sent === '' || !hash_equals((string)$expected, $sent)) {
        kmontage_json_response(array('ok' => false, 'error' => 'CSRF検証に失敗しました'), 403);
    }
}

$proxy_action = isset($_GET['proxy']) ? $_GET['proxy'] : '';
if ($proxy_action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    if (!$logged_in) {
        kmontage_json_response(array('ok' => false, 'error' => 'login required'), 401);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { kmontage_require_csrf($csrf); }
    if ($proxy_action === 'billing_status') {
        $billing = kmo_bill_state($session_user);
        kmontage_json_response(array(
            'ok' => true,
            'first_free' => !$billing['free_used'],
            'free_used' => $billing['free_used'],
            'credits' => $billing['credits'],
            'generation_count' => $billing['generation_count'],
            'price_jpy' => KMO_PRICE_JPY,
            'price_urlai' => KMO_PRICE_URLAI,
            'urlai_receiver' => KMO_URLAI_RECEIVER,
            'paypal_client_id' => KMO_PAYPAL_CLIENT_ID,
            'admin_bypass' => $is_admin,
        ));
    }
    if (($proxy_action === 'billing_paypal' || $proxy_action === 'billing_urlai') && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($input)) { $input = array(); }
        if ($proxy_action === 'billing_paypal') {
            list($ok, $msg) = kmo_bill_grant_paypal($session_user, $input['order_id'] ?? '');
        } else {
            list($ok, $msg) = kmo_bill_grant_urlai($session_user, $input['wallet'] ?? '');
        }
        $billing = kmo_bill_state($session_user);
        kmontage_json_response(array('ok' => $ok, 'message' => $msg, 'credits' => $billing['credits']), $ok ? 200 : 400);
    }
    if ($proxy_action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = file_get_contents('php://input');
        $payload = json_decode($body, true);
        if (!is_array($payload)) { $payload = array(); }
        $billing_reservation = $is_admin ? array('ok' => true, 'mode' => 'admin', 'reservation' => '') : kmo_bill_reserve_generation($session_user);
        if (empty($billing_reservation['ok'])) {
            kmontage_json_response(array('ok' => false, 'error' => 'PAYMENT_REQUIRED'), 402);
        }
        $payload['publish_to_kuragev'] = !empty($payload['publish_to_kuragev']);
        if ($is_admin) {
            $payload['vtuber_mode'] = !empty($payload['vtuber_mode']);
            $payload['video_style'] = $payload['vtuber_mode'] ? 'ai_avatar_explainer' : 'faceless_documentary';
            $payload['image_provider'] = (isset($payload['image_provider']) && $payload['image_provider'] === 'codex_subscription') ? 'codex_subscription' : 'ernie';
            $payload['editor_mode'] = (isset($payload['editor_mode']) && $payload['editor_mode'] === 'llm') ? 'llm' : 'normal';
        } else {
            $payload['vtuber_mode'] = false;
            $payload['video_style'] = 'faceless_documentary';
            $payload['image_provider'] = 'ernie';
            $payload['editor_mode'] = 'normal';
        }
        $res = kmontage_api('POST', '/api/jobs', $payload, 60);
        $data = isset($res['data']) ? $res['data'] : array('ok'=>false,'error'=>isset($res['error'])?$res['error']:'API unreachable');
        if (!$is_admin) {
            $reservation_id = (string)($billing_reservation['reservation'] ?? '');
            if (!$res['ok'] || empty($data['ok']) || !empty($data['duplicate'])) {
                kmo_bill_cancel_reservation($session_user, $reservation_id);
            } elseif (!kmo_bill_finish_reservation($session_user, $reservation_id, $data['job_id'] ?? '')) {
                kmontage_json_response(array('ok' => false, 'error' => '課金台帳の更新に失敗しました'), 500);
            }
        }
        kmontage_json_response($data, $res['status'] ?: 502);
    } elseif ($proxy_action === 'regenerate' && isset($_GET['job_id']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $jid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['job_id']);
        $body = file_get_contents('php://input');
        $payload = json_decode($body, true);
        if (!is_array($payload)) { $payload = array(); }
        $payload['publish_to_kuragev'] = !empty($payload['publish_to_kuragev']);
        $payload['vtuber_mode'] = $is_admin ? !empty($payload['vtuber_mode']) : false;
        $payload['video_style'] = $payload['vtuber_mode'] ? 'ai_avatar_explainer' : 'faceless_documentary';
        $payload['image_provider'] = ($is_admin && isset($payload['image_provider']) && $payload['image_provider'] === 'codex_subscription') ? 'codex_subscription' : 'ernie';
        $payload['editor_mode'] = ($is_admin && isset($payload['editor_mode']) && $payload['editor_mode'] === 'llm') ? 'llm' : 'normal';
        $res = kmontage_api('POST', '/api/jobs/' . $jid . '/regenerate', $payload, 60);
        kmontage_json_response(isset($res['data']) ? $res['data'] : array('ok'=>false,'error'=>isset($res['error'])?$res['error']:'API unreachable'), $res['status'] ?: 502);
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
.editor-mode{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-top:.7rem}
.mode-pill{display:inline-flex;cursor:pointer}
.mode-pill input{position:absolute;opacity:0;pointer-events:none}
.mode-pill span{display:inline-flex;align-items:center;gap:.3rem;border:1px solid var(--line);border-radius:999px;padding:.34rem .85rem;font-size:.8rem;font-weight:800;color:var(--muted);background:#fff;transition:all .15s}
.mode-pill em{font-style:normal;font-size:.62rem;font-weight:900;color:#fff;background:var(--accent2);border-radius:6px;padding:.06rem .32rem}
.mode-pill input:checked+span{border-color:var(--accent);color:var(--accent);background:var(--soft);box-shadow:0 0 0 3px rgba(7,138,166,.08)}
.mode-hint{color:var(--muted);font-size:.74rem}
.provider-note{width:100%;padding:.5rem .7rem;border:1px solid #c7e9ef;border-radius:12px;background:#f3fbfd;color:#3c626c;font-size:.74rem;line-height:1.6}
.option-row{display:flex;gap:1rem;flex-wrap:wrap;margin-top:.8rem}.check-option{display:flex;align-items:center;gap:.5rem;font-weight:800;color:#31525c}.check-option input{width:18px;height:18px;accent-color:var(--accent)}
.billing-bar{display:flex;align-items:center;justify-content:space-between;gap:.8rem;flex-wrap:wrap;margin-top:.8rem;padding:.7rem .8rem;border:1px solid #c7e9ef;border-radius:14px;background:#fff}.billing-bar strong{color:var(--accent)}
dialog{width:min(620px,calc(100% - 2rem));border:0;border-radius:22px;padding:0;box-shadow:0 28px 80px rgba(19,35,41,.28)}dialog::backdrop{background:rgba(16,43,52,.35);backdrop-filter:blur(3px)}.billing-dialog{padding:1.25rem}.billing-dialog h2{margin-bottom:.55rem}.billing-grid{display:grid;grid-template-columns:1fr 1fr;gap:.8rem;margin-top:1rem}.billing-card{border:1px solid var(--line);border-radius:16px;padding:1rem;background:#f9fdfe}.billing-card h3{margin-bottom:.5rem}.billing-card input{width:100%;border:1px solid #bdd4da;border-radius:12px;padding:.7rem;margin:.6rem 0}.billing-address{font-size:.68rem;word-break:break-all;color:var(--muted);margin:.45rem 0}.billing-msg{min-height:1.4rem;margin-top:.8rem;color:var(--muted)}@media(max-width:680px){.billing-grid{grid-template-columns:1fr}}
</style>
<link rel="stylesheet" href="assets/kurage-avatar.css?v=20260704a">
</head>
<body>
<header>
  <a class="brand" href="<?php echo h($THIS_FILE); ?>" aria-label="Kurage Montage トップへ">
    <span class="kurage-avatar-stage kurage-avatar-mini" role="img" aria-label="Kurage avatar"><img class="kurage-avatar-still" src="images/kurage_avatar_face.webp" alt=""></span>
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
      <div class="option-row">
        <label class="check-option"><input id="publish-kuragev" type="checkbox" <?php echo $is_admin ? 'checked' : ''; ?>>Kurage動画一覧に掲載する</label>
        <?php if ($is_admin): ?><label class="check-option"><input id="vtuber-mode" type="checkbox" checked>VTuberモード</label><?php endif; ?>
      </div>
      <?php if ($is_admin): ?>
      <div class="editor-mode" role="radiogroup" aria-label="テロップ編集モード">
        <label class="mode-pill"><input type="radio" name="editor-mode" value="normal" checked><span>通常モード</span></label>
        <label class="mode-pill"><input type="radio" name="editor-mode" value="llm"><span>LLM編集者モード <em>β</em></span></label>
        <span class="mode-hint" id="mode-hint">テロップの文節割り・強調は自動ルールで決めます。</span>
      </div>
      <div class="editor-mode" role="radiogroup" aria-label="画像生成プロバイダー">
        <label class="mode-pill"><input type="radio" name="image-provider" value="ernie" checked><span>ERNIEローカル <em>既定</em></span></label>
        <label class="mode-pill"><input type="radio" name="image-provider" value="codex_subscription"><span>ChatGPT画像生成</span></label>
        <div class="provider-note" id="provider-hint">192.168.0.11のERNIEで画像を生成します。</div>
      </div>
      <?php else: ?>
      <div class="provider-note">台本は192.168.0.14のGemma 4 12BをRQDB4AIキュー経由で実行し、画像は192.168.0.11のERNIEで生成します。VTuberモードは使用しません。</div>
      <?php endif; ?>
      <div class="billing-bar"><span id="billing-summary">料金情報を確認中…</span><?php if (!$is_admin): ?><button id="buy-credit" class="btn-muted" type="button">生成クレジットを購入</button><?php endif; ?></div>
      <div class="hint">1回目は無料。2回目以降は1回500円または50,000 URLAIです。長い動画や資料は生成に時間がかかります。一覧掲載を選ばない動画は自分の履歴だけに表示されます。</div>
      <div id="message" class="toast"></div>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><span>生成状況</span><small id="job-id">未開始</small></div>
    <div class="panel-body">
      <div class="status-line"><span id="status" class="badge status-pill">idle</span><span id="image-provider-status" class="badge status-pill">画像: 未選択</span><strong id="title">タイトルはここに表示されます</strong></div>
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

<?php if ($logged_in && !$is_admin): ?>
<dialog id="billing-dialog"><div class="billing-dialog">
  <h2>動画生成クレジット</h2>
  <p>2回目以降は1回 <strong>500円</strong> または <strong>50,000 URLAI</strong>です。購入したクレジットに有効期限はありません。</p>
  <div class="billing-grid">
    <div class="billing-card"><h3>PayPal</h3><p>500円の決済完了後、自動で1回分を追加します。</p><div id="paypal-buttons"></div></div>
    <div class="billing-card"><h3>URLAI（Base）</h3><p>次の受取先へ50,000 URLAIを送金してください。</p><div id="billing-receiver" class="billing-address">-</div><input id="billing-wallet" placeholder="送金元ウォレット 0x…"><button id="verify-urlai" class="btn-primary" type="button">支払い確認</button></div>
  </div>
  <div id="billing-message" class="billing-msg"></div>
  <div class="actions"><button id="billing-close" class="btn-muted" type="button">閉じる</button></div>
</div></dialog>
<?php endif; ?>

<?php if ($logged_in): ?>
<script>
const KMONTAGE_CSRF = <?php echo json_encode($csrf, JSON_UNESCAPED_SLASHES); ?>;
const KMONTAGE_IS_ADMIN = <?php echo $is_admin ? 'true' : 'false'; ?>;
let currentJobId = null;
let currentJobUrl = '';
let pollTimer = null;
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s || '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
function message(text){ $('message').textContent = text || ''; }
function editorMode(){ const el = document.querySelector('input[name="editor-mode"]:checked'); return el && el.value === 'llm' ? 'llm' : 'normal'; }
function imageProvider(){ const el = document.querySelector('input[name="image-provider"]:checked'); return el && el.value === 'codex_subscription' ? 'codex_subscription' : 'ernie'; }
function vtuberMode(){ const el = $('vtuber-mode'); return KMONTAGE_IS_ADMIN && !!el && el.checked; }
function publishToKuragev(){ const el = $('publish-kuragev'); return !!el && el.checked; }
document.querySelectorAll('input[name="editor-mode"]').forEach(r => r.addEventListener('change', () => {
  $('mode-hint').textContent = editorMode() === 'llm'
    ? 'Claudeが編集者としてテロップの文節・強調・演出テンプレートを決めます（制限時はローカルgemma4に自動フォールバック）。'
    : 'テロップの文節割り・強調は自動ルールで決めます。';
}));
document.querySelectorAll('input[name="image-provider"]').forEach(r => r.addEventListener('change', () => {
  $('provider-hint').textContent = imageProvider() === 'ernie'
    ? 'RTX 3080上のERNIE-Image-Turboで生成します。ローカル処理のためChatGPTサブスク枠は使いません。'
    : 'ChatGPT/Codexサブスクの組み込みImageGenを1枚ずつ利用します。認証・制限・タイムアウト時はERNIEへ自動フォールバックします。';
}));
function setActions(enabled){ $('copy').disabled = !enabled; $('post-x').disabled = !enabled; $('delete').disabled = !currentJobId; }
function scriptLines(job){ const script = job.kurage_script || job.script || {}; const scenes = Array.isArray(script.scenes) ? script.scenes : []; if (scenes.length) return scenes.map(s => s.narration || '').filter(Boolean); return Array.isArray(job.script_outline) ? job.script_outline : []; }
function statusLabel(job){ const labels = {queued:'待機中',analyzing:'URL解析中',downloading:'元動画取得中',transcribing:'文字起こし中',planning:'台本生成中',generating:'Kurage動画生成中',done:'完了',error:'エラー'}; return labels[job.status] || job.status || '不明'; }
function displayProgress(job){ return job.status === 'done' ? 100 : Math.max(0, Math.min(100, Number(job.failed_at_progress ?? job.progress ?? 0))); }
function progressText(job){ const p = displayProgress(job); return job.status === 'error' ? `エラー（${p}%で停止）` : `${statusLabel(job)} / ${p}%`; }
function jobTitle(job){ return job.kurage_title || job.title || job.source_title || job.url || '生成中'; }
function renderJob(job){
  currentJobId = job.id || currentJobId;
  currentJobUrl = job.url || '';
  if (job.url) $('source-url').value = job.url;
  if (job.image_provider) {
    const selectedProvider = job.image_provider === 'ernie' ? 'ernie' : 'codex_subscription';
    const radio = document.querySelector(`input[name="image-provider"][value="${selectedProvider}"]`);
    if (radio) radio.checked = true;
  }
  $('job-id').textContent = currentJobId || '未開始';
  const st = job.status || 'unknown';
  $('status').textContent = progressText(job);
  $('status').className = 'badge ' + (st === 'done' ? 'done' : st === 'error' ? 'error' : 'status-pill');
  const requestedProvider = job.image_provider === 'ernie' ? 'ERNIE' : 'ChatGPT/Codex';
  const actualProvider = job.image_provider_actual === 'ernie' ? 'ERNIE' : job.image_provider_actual === 'codex_subscription' ? 'ChatGPT/Codex' : job.image_provider_actual === 'mixed' ? '混在' : '';
  const fallbackCount = Number(job.image_provider_fallbacks || 0);
  $('image-provider-status').textContent = `画像: ${requestedProvider}${actualProvider ? ` → ${actualProvider}` : ''}${fallbackCount ? `（代替${fallbackCount}枚）` : ''}`;
  $('progress').style.width = `${displayProgress(job)}%`;
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
async function fetchJson(url, options){
  const opts = Object.assign({}, options || {}); opts.headers = Object.assign({}, opts.headers || {});
  if (String(opts.method || 'GET').toUpperCase() !== 'GET') opts.headers['X-CSRF-Token'] = KMONTAGE_CSRF;
  const res = await fetch(url, opts); const data = await res.json();
  if (!res.ok || data.ok === false) { const error = new Error(data.detail || data.error || 'request failed'); error.status = res.status; throw error; }
  return data;
}
async function poll(jobId){ const job = await fetchJson(`<?php echo h($THIS_FILE); ?>?proxy=status&job_id=${encodeURIComponent(jobId)}`); renderJob(job); history.replaceState(null, '', `?job=${encodeURIComponent(jobId)}`); if (job.status === 'done' || job.status === 'error') { clearInterval(pollTimer); pollTimer = null; await loadHistory(); } return job; }
$('generate').addEventListener('click', async () => {
  const url = $('source-url').value.trim(); if (!url) return message('URLを入力してください');
  $('generate').disabled = true; message('生成ジョブを開始しています...');
  try { const sameLoadedUrl = currentJobId && currentJobUrl && url === currentJobUrl; const endpoint = sameLoadedUrl ? `<?php echo h($THIS_FILE); ?>?proxy=regenerate&job_id=${encodeURIComponent(currentJobId)}` : '<?php echo h($THIS_FILE); ?>?proxy=create'; const vtuber = vtuberMode(); const data = await fetchJson(endpoint, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({url, vtuber_mode:vtuber, video_style:vtuber?'ai_avatar_explainer':'faceless_documentary', image_provider:imageProvider(), editor_mode:editorMode(), publish_to_kuragev:publishToKuragev()})}); currentJobId = data.job_id; currentJobUrl = url; message(`${sameLoadedUrl ? '上書き再生成' : 'ジョブ開始'}: ${currentJobId}`); clearInterval(pollTimer); await loadBilling(); const job = await poll(currentJobId); if (!['done','error'].includes(job.status)) pollTimer = setInterval(() => poll(currentJobId), 5000); }
  catch(e){ if (e.status === 402) { message('2回目以降は生成クレジットが必要です。'); openBilling(); } else { message(e.message || String(e)); } }
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
    const provider = job.image_provider === 'ernie' ? 'ERNIE' : 'ChatGPT/Codex';
    div.innerHTML = `<button class="job-main" data-id="${esc(job.id)}" type="button"><strong>${esc(jobTitle(job))}</strong><small>${esc(progressText(job))} / 画像: ${esc(provider)} / ${esc(job.url || '')}</small>${kurage}</button>`;
    div.querySelector('button').addEventListener('click', async () => openJob(job));
    box.appendChild(div);
  }
  if (!box.innerHTML) box.innerHTML = '<div class="hint">まだ生成履歴がありません。</div>';
}
$('reload').addEventListener('click', loadHistory);

let billingInfo = null;
async function loadBilling(){
  billingInfo = await fetchJson('<?php echo h($THIS_FILE); ?>?proxy=billing_status');
  $('billing-summary').innerHTML = billingInfo.admin_bypass
    ? '<strong>管理者</strong>：課金対象外'
    : billingInfo.first_free
      ? '<strong>初回無料</strong>：最初の1本を生成できます'
      : `<strong>残り${Number(billingInfo.credits || 0)}回</strong>：1回500円 / 50,000 URLAI`;
  return billingInfo;
}
async function openBilling(){
  if (KMONTAGE_IS_ADMIN) return;
  await loadBilling();
  $('billing-receiver').textContent = billingInfo.urlai_receiver || '-';
  $('billing-message').textContent = '';
  $('billing-dialog').showModal();
  mountPayPal();
}
function billingSay(text, ok){ const el=$('billing-message'); if(!el)return; el.textContent=text||''; el.style.color=ok?'#2f9d62':'#bd4b4b'; }
function mountPayPal(){
  if (!billingInfo || KMONTAGE_IS_ADMIN) return;
  const box=$('paypal-buttons'); if(!box || box.dataset.mounted)return;
  const boot=()=>{ if(!window.paypal||!window.paypal.Buttons)return; box.dataset.mounted='1'; window.paypal.Buttons({
    createOrder:(data,actions)=>actions.order.create({purchase_units:[{amount:{currency_code:'JPY',value:String(billingInfo.price_jpy)}}]}),
    onApprove:async(data,actions)=>{ const order=await actions.order.capture(); try{ const result=await fetchJson('<?php echo h($THIS_FILE); ?>?proxy=billing_paypal',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({order_id:order.id})}); billingSay(result.message,true); await loadBilling(); }catch(error){billingSay(error.message,false);} },
    onError:()=>billingSay('PayPal決済でエラーが発生しました。',false)
  }).render('#paypal-buttons'); };
  if(window.paypal){boot();return;} const script=document.createElement('script'); script.src='https://www.paypal.com/sdk/js?client-id='+encodeURIComponent(billingInfo.paypal_client_id)+'&currency=JPY'; script.onload=boot; document.head.appendChild(script);
}
if (!KMONTAGE_IS_ADMIN) {
  $('buy-credit').addEventListener('click',()=>openBilling().catch(e=>message(e.message)));
  $('billing-close').addEventListener('click',()=>$('billing-dialog').close());
  $('verify-urlai').addEventListener('click',async()=>{ const wallet=$('billing-wallet').value.trim(); if(!wallet)return billingSay('送金元ウォレットを入力してください。',false); billingSay('Baseチェーンで確認中…',true); try{ const result=await fetchJson('<?php echo h($THIS_FILE); ?>?proxy=billing_urlai',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({wallet})}); billingSay(result.message,true); await loadBilling(); }catch(error){billingSay(error.message,false);} });
}

$('source-url').addEventListener('input', () => { if ($('source-url').value.trim() !== currentJobUrl) currentJobId = null; });
async function openInitialJob(){ await Promise.all([loadHistory(), loadBilling()]); const jobId = new URLSearchParams(location.search).get('job'); if (jobId) { const job = await poll(jobId); clearInterval(pollTimer); if (!['done','error'].includes(job.status)) pollTimer = setInterval(() => poll(jobId), 5000); } }
openInitialJob().catch(e => message(e.message || String(e)));
</script>
<?php endif; ?>
</body>
</html>
