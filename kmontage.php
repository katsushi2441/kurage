<?php
require_once __DIR__ . '/config.php';
if (is_file(__DIR__ . '/kmontage_config.php')) { require_once __DIR__ . '/kmontage_config.php'; }
require_once __DIR__ . '/auth_common.php';
require_once __DIR__ . '/kmontage_billing.php';
date_default_timezone_set('Asia/Tokyo');

$THIS_FILE     = 'kmontage.php';
$KMONTAGE_API  = rtrim(getenv('KMONTAGE_API') ?: 'http://exbridge.ddns.net:18305', '/');
$KMONTAGE_INTERNAL_TOKEN = defined('KMONTAGE_INTERNAL_TOKEN') ? KMONTAGE_INTERNAL_TOKEN : (getenv('KMONTAGE_INTERNAL_TOKEN') ?: '');

// 言語判定: ?lang=en/ja で切替＆Cookieに保存。以降はCookieで維持（url2pubと同方式）。
$lang = 'ja';
if (isset($_GET['lang'])) {
    $lang = ($_GET['lang'] === 'en') ? 'en' : 'ja';
    setcookie('kmo_lang', $lang, time() + 31536000, '/');
    $_COOKIE['kmo_lang'] = $lang;
} elseif (isset($_COOKIE['kmo_lang']) && $_COOKIE['kmo_lang'] === 'en') {
    $lang = 'en';
}

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

// UI文言（日本語 / English）。ロジックは1本、文言だけ差し替える（url2pubと同方式）。
$T_ALL = array(
'ja' => array(
  'title' => 'Kurage Montage — URL要約ショート生成',
  'meta_desc' => 'X投稿・YouTube・ブログ/記事・PDFのURLを渡すと、AI VTuberのKurageさんが読み解いて日本語の解説ショート動画にします。',
  'brand_sub' => 'X・YouTube・ブログ・PDFからショート動画生成',
  'nav_login' => '𝕏 でログイン',
  'nav_logout' => 'ログアウト',
  'nav_watch' => '動画を見る',
  'eyebrow' => 'AI VTuber ・ 解説ショート動画',
  'h1' => 'URLを1つ渡すだけ。<em>Kurageさんが解説ショート動画にします。</em>',
  'lead' => '<b>X投稿・YouTube・ブログ/記事・PDF</b> のURLを貼るだけで、<b>Kurageさん</b>がその内容を読み解き、シーンを構成し、台本を書いて、<b>日本語の解説ショート動画</b>に仕上げます。編集も台本書きも不要です。',
  'hero_name' => 'Kurageさん',
  'hero_desc' => 'エクスブリッジのクラゲAI VTuber。読み解いて、台本を書いて、話します。',
  'login_h2' => 'ログインが必要です',
  'login_p' => 'X・YouTube・ブログ・PDFからショート動画を生成するにはログインしてください。',
  'login_cta' => '𝕏 でログインして始める',
  'gen_head' => '要約ショート生成',
  'gen_small' => 'X / YouTube / ブログ / PDF URL',
  'url_placeholder' => 'https://x.com/... または https://example.com/article.pdf',
  'generate' => '生成する',
  'opt_publish' => 'Kurage動画一覧に掲載する',
  'opt_vtuber' => 'VTuberモード',
  'mode_group_label' => 'テロップ編集モード',
  'mode_normal' => '通常モード',
  'mode_llm' => 'LLM編集者モード',
  'provider_group_label' => '画像生成プロバイダー',
  'provider_ernie' => 'ERNIEローカル',
  'provider_default' => '既定',
  'provider_codex' => 'ChatGPT画像生成',
  'provider_hint_default' => '192.168.0.11のERNIEで画像を生成します。',
  'user_note' => '台本は192.168.0.14のGemma 4 12BをRQDB4AIキュー経由で実行し、画像は192.168.0.11のERNIEで生成します。VTuberモードは使用しません。',
  'billing_checking' => '料金情報を確認中…',
  'buy_credit' => '生成クレジットを購入',
  'hint' => '1回目は無料。2回目以降は1回500円または50,000 URLAIです。長い動画や資料は生成に時間がかかります。一覧掲載を選ばない動画は自分の履歴だけに表示されます。',
  'status_head' => '生成状況',
  'job_none' => '未開始',
  'img_none' => '画像: 未選択',
  'title_placeholder' => 'タイトルはここに表示されます',
  'summary_placeholder' => '動画の要点と生成された台本がここに表示されます。',
  'open_kurage' => 'Kurageで開く',
  'copy' => 'コピー',
  'post_x' => '𝕏 投稿',
  'delete' => '削除',
  'hist_head' => '最近の生成',
  'reload' => '更新',
  'bd_title' => '動画生成クレジット',
  'bd_lead' => '2回目以降は1回 <strong>500円</strong> または <strong>50,000 URLAI</strong>です。購入したクレジットに有効期限はありません。',
  'bd_paypal_p' => '500円の決済完了後、自動で1回分を追加します。',
  'bd_urlai_h' => 'URLAI（Base）',
  'bd_urlai_p' => '次の受取先へ50,000 URLAIを送金してください。',
  'bd_wallet_ph' => '送金元ウォレット 0x…',
  'bd_verify' => '支払い確認',
  'bd_close' => '閉じる',
  'footer_product' => '株式会社エクスブリッジのプロダクト',
  'footer_lp' => 'Kurage Montageについて',
  'footer_contact' => 'お問い合わせ',
  'js' => array(
    'status' => array('queued'=>'待機中','analyzing'=>'URL解析中','downloading'=>'元動画取得中','transcribing'=>'文字起こし中','planning'=>'台本生成中','generating'=>'Kurage動画生成中','done'=>'完了','error'=>'エラー'),
    'status_unknown' => '不明',
    'err_stop' => 'エラー（{p}%で停止）',
    'job_generating' => '生成中',
    'job_none' => '未開始',
    'img_prefix' => '画像: ',
    'img_mixed' => '混在',
    'img_alt' => '（代替{n}枚）',
    'msg_need_url' => 'URLを入力してください',
    'msg_starting' => '生成ジョブを開始しています...',
    'msg_regen' => '上書き再生成',
    'msg_started' => 'ジョブ開始',
    'msg_payment' => '2回目以降は生成クレジットが必要です。',
    'msg_copied' => 'コピーしました',
    'confirm_delete' => 'この生成ジョブとKurage動画を削除しますか？',
    'deleted' => '削除済み',
    'title_placeholder' => 'タイトルはここに表示されます',
    'summary_placeholder' => '動画の要点と生成された台本がここに表示されます。',
    'analyzing_placeholder' => '解析中です。',
    'error_prefix' => 'エラー: ',
    'mode_hint_normal' => 'テロップの文節割り・強調は自動ルールで決めます。',
    'mode_hint_llm' => 'Claudeが編集者としてテロップの文節・強調・演出テンプレートを決めます（制限時はローカルgemma4に自動フォールバック）。',
    'provider_hint_ernie' => 'RTX 3080上のERNIE-Image-Turboで生成します。ローカル処理のためChatGPTサブスク枠は使いません。',
    'provider_hint_codex' => 'ChatGPT/Codexサブスクの組み込みImageGenを1枚ずつ利用します。認証・制限・タイムアウト時はERNIEへ自動フォールバックします。',
    'billing_admin' => '<strong>管理者</strong>：課金対象外',
    'billing_first' => '<strong>初回無料</strong>：最初の1本を生成できます',
    'billing_left' => '<strong>残り{n}回</strong>：1回500円 / 50,000 URLAI',
    'paypal_err' => 'PayPal決済でエラーが発生しました。',
    'urlai_checking' => 'Baseチェーンで確認中…',
    'need_wallet' => '送金元ウォレットを入力してください。',
    'no_history' => 'まだ生成履歴がありません。',
  ),
),
'en' => array(
  'title' => 'Kurage Montage — Turn any URL into an explainer short',
  'meta_desc' => 'Paste an X post, YouTube, blog/article, or PDF URL and Kurage, the AI VTuber, reads it and turns it into a Japanese explainer short video.',
  'brand_sub' => 'Short videos from X, YouTube, blogs & PDFs',
  'nav_login' => 'Sign in with 𝕏',
  'nav_logout' => 'Logout',
  'nav_watch' => 'Watch videos',
  'eyebrow' => 'AI VTuber ・ Explainer shorts',
  'h1' => 'Paste one URL. <em>Kurage turns it into an explainer short.</em>',
  'lead' => 'Paste a URL of an <b>X post, YouTube video, blog/article, or PDF</b> and <b>Kurage</b> reads it, structures the scenes, writes the script, and delivers a <b>Japanese explainer short video</b>. No editing, no script-writing needed.',
  'hero_name' => 'Kurage',
  'hero_desc' => 'EXBRIDGE\'s jellyfish AI VTuber. Reads, writes the script, and speaks.',
  'login_h2' => 'Login required',
  'login_p' => 'Please sign in to generate short videos from X, YouTube, blogs, and PDFs.',
  'login_cta' => 'Sign in with 𝕏 to start',
  'gen_head' => 'Generate a summary short',
  'gen_small' => 'X / YouTube / blog / PDF URL',
  'url_placeholder' => 'https://x.com/... or https://example.com/article.pdf',
  'generate' => 'Generate',
  'opt_publish' => 'List on the Kurage video page',
  'opt_vtuber' => 'VTuber mode',
  'mode_group_label' => 'Caption editing mode',
  'mode_normal' => 'Normal mode',
  'mode_llm' => 'LLM editor mode',
  'provider_group_label' => 'Image provider',
  'provider_ernie' => 'ERNIE local',
  'provider_default' => 'default',
  'provider_codex' => 'ChatGPT images',
  'provider_hint_default' => 'Images are generated with ERNIE on 192.168.0.11.',
  'user_note' => 'Scripts run on Gemma 4 12B (192.168.0.14) via the RQDB4AI queue, and images are generated with ERNIE (192.168.0.11). VTuber mode is not used.',
  'billing_checking' => 'Checking pricing…',
  'buy_credit' => 'Buy generation credits',
  'hint' => 'Your first video is free. After that, each video costs ¥500 or 50,000 URLAI. Long videos and documents take longer to generate. Videos not listed publicly appear only in your own history.',
  'status_head' => 'Generation status',
  'job_none' => 'Not started',
  'img_none' => 'Images: not selected',
  'title_placeholder' => 'The title will appear here',
  'summary_placeholder' => 'The video summary and generated script will appear here.',
  'open_kurage' => 'Open on Kurage',
  'copy' => 'Copy',
  'post_x' => 'Post to 𝕏',
  'delete' => 'Delete',
  'hist_head' => 'Recent generations',
  'reload' => 'Reload',
  'bd_title' => 'Video generation credits',
  'bd_lead' => 'After your first free video, each video costs <strong>¥500</strong> or <strong>50,000 URLAI</strong>. Purchased credits never expire.',
  'bd_paypal_p' => 'One credit is added automatically after a ¥500 payment.',
  'bd_urlai_h' => 'URLAI (Base)',
  'bd_urlai_p' => 'Send 50,000 URLAI to the receiving address below.',
  'bd_wallet_ph' => 'Sender wallet 0x…',
  'bd_verify' => 'Verify payment',
  'bd_close' => 'Close',
  'footer_product' => 'A product of EXBRIDGE, Inc.',
  'footer_lp' => 'About Kurage Montage',
  'footer_contact' => 'Contact',
  'js' => array(
    'status' => array('queued'=>'Queued','analyzing'=>'Analyzing URL','downloading'=>'Fetching source video','transcribing'=>'Transcribing','planning'=>'Writing script','generating'=>'Rendering Kurage video','done'=>'Done','error'=>'Error'),
    'status_unknown' => 'Unknown',
    'err_stop' => 'Error (stopped at {p}%)',
    'job_generating' => 'Generating',
    'job_none' => 'Not started',
    'img_prefix' => 'Images: ',
    'img_mixed' => 'mixed',
    'img_alt' => ' ({n} fallback)',
    'msg_need_url' => 'Please enter a URL',
    'msg_starting' => 'Starting the generation job...',
    'msg_regen' => 'Regenerating',
    'msg_started' => 'Job started',
    'msg_payment' => 'Generation credits are required after your first video.',
    'msg_copied' => 'Copied',
    'confirm_delete' => 'Delete this job and its Kurage video?',
    'deleted' => 'Deleted',
    'title_placeholder' => 'The title will appear here',
    'summary_placeholder' => 'The video summary and generated script will appear here.',
    'analyzing_placeholder' => 'Analyzing.',
    'error_prefix' => 'Error: ',
    'mode_hint_normal' => 'Caption phrasing and emphasis are decided by automatic rules.',
    'mode_hint_llm' => 'Claude acts as the editor and decides caption phrasing, emphasis, and templates (falls back to local gemma4 when rate-limited).',
    'provider_hint_ernie' => 'Generated with ERNIE-Image-Turbo on an RTX 3080. Runs locally, so it never uses your ChatGPT subscription quota.',
    'provider_hint_codex' => 'Uses the built-in ImageGen of a ChatGPT/Codex subscription one image at a time. Falls back to ERNIE on auth, limit, or timeout errors.',
    'billing_admin' => '<strong>Admin</strong>: no billing',
    'billing_first' => '<strong>First video free</strong>: you can generate your first short now',
    'billing_left' => '<strong>{n} credit(s) left</strong>: ¥500 / 50,000 URLAI per video',
    'paypal_err' => 'The PayPal payment failed.',
    'urlai_checking' => 'Verifying on Base chain…',
    'need_wallet' => 'Please enter the sender wallet.',
    'no_history' => 'No generations yet.',
  ),
),
);
$T = $T_ALL[$lang];
$other_lang = $lang === 'en' ? 'ja' : 'en';
$SITE_NAME = $T['title'];

$api_ok = false;
$health = kmontage_api('GET', '/api/health', null, 5);
if ($health['ok'] && isset($health['data']['ok'])) { $api_ok = true; }
?><!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($SITE_NAME); ?></title>
<meta name="description" content="<?php echo h($T['meta_desc']); ?>">
<link rel="alternate" hreflang="ja" href="https://kurage.exbridge.jp/kmontage.php?lang=ja">
<link rel="alternate" hreflang="en" href="https://kurage.exbridge.jp/kmontage.php?lang=en">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&family=Zen+Kaku+Gothic+New:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root {
  --abyss:#12202f; --abyss-soft:#55697a; --foam:#f5fbfb; --panel:#e7f3f2; --panel-line:#cde5e2;
  --card:#ffffff; --teal:#12a99f; --teal-deep:#0a726b; --violet:#6f5fd8;
  --gold:#c98a1e; --gold-bg:#fbf2db; --gold-line:#ecd9a8;
  --green:#2f9d62; --green-bg:#e7f7ed; --green-line:#ccebd8;
  --red:#bd4b4b; --red-bg:#fff0f0; --red-line:#f2cccc;
  --shadow:0 14px 40px rgba(10,40,45,.10);
}
@media (prefers-color-scheme:dark){:root{
  --abyss:#eaf3f3; --abyss-soft:#9fb3ba; --foam:#0c1720; --panel:#12242a; --panel-line:#1f3a3f;
  --card:#101f27; --teal:#2bd4c6; --teal-deep:#1c9e93; --violet:#9186ea;
  --gold:#f2c766; --gold-bg:#241b08; --gold-line:#4c3c17;
  --green:#5fd191; --green-bg:#0d271a; --green-line:#1e4930;
  --red:#e88a8a; --red-bg:#2b1414; --red-line:#5a2626;
  --shadow:0 14px 40px rgba(0,0,0,.38);
}}
* { box-sizing:border-box; margin:0; padding:0; }
html { scroll-behavior:smooth; }
body { color:var(--abyss); font-family:"Zen Kaku Gothic New","Hiragino Sans","Yu Gothic",Meiryo,sans-serif; background:var(--foam); min-height:100vh; line-height:1.8; font-size:14px; overflow-x:hidden; }
a { color:var(--teal-deep); text-decoration:none; }
a:hover { color:var(--teal); }
img { max-width:100%; }
h1,h2,h3 { font-family:"Zen Maru Gothic","Zen Kaku Gothic New",sans-serif; text-wrap:balance; }
.wrap { max-width:1000px; margin:0 auto; padding:0 24px; }
header.site { position:sticky; top:0; z-index:40; background:color-mix(in srgb,var(--foam) 88%,transparent); backdrop-filter:blur(16px); border-bottom:1px solid var(--panel-line); }
header.site .wrap { display:flex; align-items:center; gap:12px; padding:10px 24px; flex-wrap:wrap; }
.hbrand { display:flex; gap:12px; align-items:center; color:var(--abyss); }
.hbrand .ico { width:40px; height:40px; border-radius:50%; overflow:hidden; border:2px solid var(--teal); flex:none; }
.hbrand .ico img { width:100%; height:100%; object-fit:cover; object-position:50% 15%; display:block; }
.hbrand strong { font-size:15px; font-weight:900; display:block; line-height:1.2; }
.hbrand span { font-size:11px; color:var(--abyss-soft); }
.hnav { display:flex; gap:8px; align-items:center; margin-left:auto; flex-wrap:wrap; }
.langswitch { display:inline-flex; border:1.5px solid var(--panel-line); border-radius:999px; overflow:hidden; }
.langswitch a { padding:6px 12px; font-size:12px; font-weight:800; color:var(--abyss-soft); }
.langswitch a.on { background:var(--teal); color:#fff; }
.badge { display:inline-flex; align-items:center; border-radius:999px; padding:4px 12px; font-size:12px; font-weight:900; border:1.5px solid transparent; }
.api-ok { background:var(--green-bg); color:var(--green); border-color:var(--green-line); }
.api-err { background:var(--red-bg); color:var(--red); border-color:var(--red-line); }
.uname { font-size:12px; color:var(--abyss-soft); font-weight:700; }
button,.btn { border-radius:999px; padding:10px 18px; font-weight:900; font-size:13px; display:inline-flex; align-items:center; justify-content:center; gap:7px; border:1.5px solid transparent; cursor:pointer; font-family:inherit; text-decoration:none; }
.btn-primary { background:linear-gradient(135deg,var(--teal),var(--teal-deep)); color:#fff; box-shadow:0 10px 24px rgba(18,169,159,.28); }
.btn-ghost { background:transparent; border-color:var(--panel-line); color:var(--abyss-soft); }
.btn-ghost:hover { border-color:var(--teal); color:var(--teal-deep); }
.btn-danger { background:var(--red-bg); color:var(--red); border-color:var(--red-line); }
.btn-sm { padding:6px 13px; font-size:12px; }
button:disabled { opacity:.5; cursor:not-allowed; }
.hero { display:grid; grid-template-columns:1.25fr .75fr; gap:36px; align-items:center; padding:44px 0 20px; }
.eyebrow { display:inline-flex; align-items:center; gap:8px; background:var(--panel); border:1.5px solid var(--panel-line); border-radius:999px; padding:7px 14px; font-size:12px; font-weight:900; color:var(--teal-deep); margin-bottom:16px; }
.dot { width:7px; height:7px; border-radius:50%; background:var(--teal); }
.hero h1 { font-size:clamp(24px,4vw,38px); font-weight:900; line-height:1.3; letter-spacing:-.01em; margin-bottom:14px; }
.hero h1 em { font-style:normal; color:var(--teal-deep); }
.lead { font-size:15px; color:var(--abyss-soft); max-width:600px; }
.lead b { color:var(--abyss); }
.hero-card { background:var(--panel); border:1.5px solid var(--panel-line); border-radius:28px; padding:22px; text-align:center; box-shadow:var(--shadow); }
.hero-card img { width:160px; height:160px; object-fit:cover; object-position:50% 8%; border-radius:22px; }
.hero-card h2 { font-size:15px; margin:12px 0 4px; }
.hero-card p { font-size:12.5px; color:var(--abyss-soft); }
main section { padding:14px 0; }
.panel { background:var(--card); border:1.5px solid var(--panel-line); border-radius:24px; box-shadow:var(--shadow); overflow:hidden; }
.panel-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 20px; background:var(--panel); border-bottom:1.5px solid var(--panel-line); font-weight:900; font-family:"Zen Maru Gothic","Zen Kaku Gothic New",sans-serif; font-size:15px; }
.panel-head small { font-weight:700; color:var(--abyss-soft); font-family:"Zen Kaku Gothic New",sans-serif; font-size:12px; }
.panel-body { padding:20px; }
.login-panel { padding:40px 24px; text-align:center; }
.login-panel h2 { font-size:20px; margin-bottom:8px; }
.login-panel p { color:var(--abyss-soft); margin-bottom:18px; }
.input-row { display:flex; gap:10px; }
input[type=url] { flex:1; border:1.5px solid var(--panel-line); border-radius:999px; background:var(--foam); color:var(--abyss); padding:12px 18px; font-size:14px; outline:none; font-family:inherit; }
input[type=url]:focus { border-color:var(--teal); box-shadow:0 0 0 4px rgba(18,169,159,.12); }
.option-row { display:flex; gap:18px; flex-wrap:wrap; margin-top:14px; }
.check-option { display:flex; align-items:center; gap:8px; font-weight:800; color:var(--abyss); font-size:13px; cursor:pointer; }
.check-option input { width:18px; height:18px; accent-color:var(--teal); }
.editor-mode { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:12px; }
.mode-pill { display:inline-flex; cursor:pointer; position:relative; }
.mode-pill input { position:absolute; opacity:0; pointer-events:none; }
.mode-pill span { display:inline-flex; align-items:center; gap:5px; border:1.5px solid var(--panel-line); border-radius:999px; padding:6px 15px; font-size:12.5px; font-weight:800; color:var(--abyss-soft); background:var(--card); transition:all .15s; }
.mode-pill em { font-style:normal; font-size:10px; font-weight:900; color:#fff; background:var(--violet); border-radius:6px; padding:1px 6px; }
.mode-pill input:checked+span { border-color:var(--teal); color:var(--teal-deep); background:var(--panel); box-shadow:0 0 0 3px rgba(18,169,159,.10); }
.mode-hint { color:var(--abyss-soft); font-size:12px; }
.provider-note { width:100%; padding:10px 14px; border:1.5px solid var(--panel-line); border-radius:14px; background:var(--panel); color:var(--abyss-soft); font-size:12px; line-height:1.7; margin-top:12px; }
.billing-bar { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; margin-top:14px; padding:12px 16px; border:1.5px solid var(--gold-line); border-radius:16px; background:var(--gold-bg); font-size:13px; }
.billing-bar strong { color:var(--gold); }
.hint { margin-top:12px; color:var(--abyss-soft); font-size:12.5px; line-height:1.8; }
.toast { min-height:1.3rem; color:var(--teal-deep); font-size:13px; font-weight:700; margin-top:10px; }
.status-line { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.status-line strong { font-size:14px; }
.status-pill { background:var(--panel); color:var(--teal-deep); border-color:var(--panel-line); }
.done { background:var(--green-bg); color:var(--green); border-color:var(--green-line); }
.error { background:var(--red-bg); color:var(--red); border-color:var(--red-line); }
.progress { height:10px; background:var(--panel); border:1px solid var(--panel-line); border-radius:999px; overflow:hidden; margin:14px 0; }
.bar { height:100%; width:0; background:linear-gradient(90deg,var(--teal),var(--green)); transition:width .35s; border-radius:999px; }
.summary { background:var(--panel); border:1.5px solid var(--panel-line); border-radius:16px; padding:14px 16px; margin-top:12px; color:var(--abyss); line-height:1.8; white-space:pre-wrap; font-size:13px; }
.script { margin:12px 0 0 20px; color:var(--abyss); line-height:1.8; font-size:13px; }
.player { margin-top:14px; border-radius:20px; overflow:hidden; border:1.5px solid var(--panel-line); background:var(--panel); min-height:0; }
.player video { display:block; width:100%; max-height:520px; background:#000; }
.actions { display:flex; gap:8px; flex-wrap:wrap; margin-top:14px; }
.history { display:grid; gap:10px; }
.job { border:1.5px solid var(--panel-line); background:var(--card); border-radius:16px; padding:6px; }
.job-main { display:block; width:100%; text-align:left; border:1.5px solid transparent; border-radius:12px; background:transparent; color:var(--abyss); box-shadow:none; padding:10px 12px; font-weight:400; font-size:13px; }
.job-main:hover { border-color:var(--teal); background:var(--panel); }
.job strong { display:block; font-size:13.5px; font-weight:800; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.job small { display:block; margin-top:3px; color:var(--abyss-soft); font-size:11.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
dialog { width:min(620px,calc(100% - 2rem)); border:1.5px solid var(--panel-line); border-radius:24px; padding:0; box-shadow:0 28px 80px rgba(10,40,45,.30); background:var(--card); color:var(--abyss); }
dialog::backdrop { background:rgba(12,32,47,.4); backdrop-filter:blur(3px); }
.billing-dialog { padding:24px; }
.billing-dialog h2 { margin-bottom:8px; font-size:19px; }
.billing-dialog > p { font-size:13px; color:var(--abyss-soft); }
.billing-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:16px; }
.billing-card { border:1.5px solid var(--panel-line); border-radius:18px; padding:16px; background:var(--panel); }
.billing-card h3 { margin-bottom:6px; font-size:14.5px; }
.billing-card p { font-size:12px; color:var(--abyss-soft); }
.billing-card input { width:100%; border:1.5px solid var(--panel-line); border-radius:12px; padding:10px 12px; margin:10px 0; background:var(--foam); color:var(--abyss); font-family:inherit; font-size:13px; }
.billing-address { font-size:11px; word-break:break-all; color:var(--abyss-soft); margin:8px 0; }
.billing-msg { min-height:1.4rem; margin-top:12px; color:var(--abyss-soft); font-size:13px; font-weight:700; }
footer.site { text-align:center; color:var(--abyss-soft); font-size:12.5px; padding:40px 20px 50px; border-top:1px solid var(--panel-line); margin-top:30px; }
footer.site a { font-weight:700; }
@media (max-width:760px){
  header.site .wrap { padding:10px 16px; }
  .wrap { padding:0 16px; }
  .hero { grid-template-columns:1fr; gap:20px; padding:28px 0 12px; }
  .hero-card { order:-1; }
  .hero-card img { width:120px; height:120px; }
  .input-row { flex-direction:column; }
  .player video { max-height:460px; }
  .billing-grid { grid-template-columns:1fr; }
  .uname { display:none; }
}
</style>
<link rel="stylesheet" href="assets/kurage-avatar.css?v=20260704a">
</head>
<body>

<header class="site"><div class="wrap">
  <a class="hbrand" href="<?php echo h($THIS_FILE); ?>" aria-label="Kurage Montage">
    <span class="ico kurage-avatar-stage kurage-avatar-mini" role="img" aria-label="Kurage avatar"><img class="kurage-avatar-still" src="images/kurage_avatar_face.webp" alt=""></span>
    <div><strong>Kurage Montage</strong><span><?php echo h($T['brand_sub']); ?></span></div>
  </a>
  <nav class="hnav">
    <span class="badge <?php echo $api_ok ? 'api-ok' : 'api-err'; ?>"><?php echo $api_ok ? 'API ●' : 'API ×'; ?></span>
    <div class="langswitch">
      <a href="?lang=ja"<?php echo $lang === 'ja' ? ' class="on"' : ''; ?>>日本語</a>
      <a href="?lang=en"<?php echo $lang === 'en' ? ' class="on"' : ''; ?>>English</a>
    </div>
    <?php if ($logged_in): ?>
      <span class="uname">@<?php echo h($session_user); ?></span>
      <a class="btn btn-ghost btn-sm" href="?kmontage_logout=1"><?php echo h($T['nav_logout']); ?></a>
    <?php else: ?>
      <a class="btn btn-primary btn-sm" href="?kmontage_login=1"><?php echo h($T['nav_login']); ?></a>
    <?php endif; ?>
  </nav>
</div></header>

<main class="wrap">
  <section class="hero">
    <div>
      <span class="eyebrow"><span class="dot"></span><?php echo h($T['eyebrow']); ?></span>
      <h1><?php echo $T['h1']; ?></h1>
      <p class="lead"><?php echo $T['lead']; ?></p>
    </div>
    <div class="hero-card">
      <img src="images/kurage-ecosystem-avatar.png" alt="Kurage">
      <h2><?php echo h($T['hero_name']); ?></h2>
      <p><?php echo h($T['hero_desc']); ?></p>
    </div>
  </section>

  <?php if (!$logged_in): ?>
  <section><div class="panel login-panel">
    <h2><?php echo h($T['login_h2']); ?></h2>
    <p><?php echo h($T['login_p']); ?></p>
    <a class="btn btn-primary" href="?kmontage_login=1"><?php echo h($T['login_cta']); ?></a>
  </div></section>
  <?php else: ?>

  <section><div class="panel">
    <div class="panel-head"><span><?php echo h($T['gen_head']); ?></span><small><?php echo h($T['gen_small']); ?></small></div>
    <div class="panel-body">
      <div class="input-row">
        <input id="source-url" type="url" placeholder="<?php echo h($T['url_placeholder']); ?>">
        <button id="generate" class="btn-primary"><?php echo h($T['generate']); ?></button>
      </div>
      <div class="option-row">
        <label class="check-option"><input id="publish-kuragev" type="checkbox" <?php echo $is_admin ? 'checked' : ''; ?>><?php echo h($T['opt_publish']); ?></label>
        <?php if ($is_admin): ?><label class="check-option"><input id="vtuber-mode" type="checkbox" checked><?php echo h($T['opt_vtuber']); ?></label><?php endif; ?>
      </div>
      <?php if ($is_admin): ?>
      <div class="editor-mode" role="radiogroup" aria-label="<?php echo h($T['mode_group_label']); ?>">
        <label class="mode-pill"><input type="radio" name="editor-mode" value="normal" checked><span><?php echo h($T['mode_normal']); ?></span></label>
        <label class="mode-pill"><input type="radio" name="editor-mode" value="llm"><span><?php echo h($T['mode_llm']); ?> <em>β</em></span></label>
        <span class="mode-hint" id="mode-hint"><?php echo h($T_ALL[$lang]['js']['mode_hint_normal']); ?></span>
      </div>
      <div class="editor-mode" role="radiogroup" aria-label="<?php echo h($T['provider_group_label']); ?>">
        <label class="mode-pill"><input type="radio" name="image-provider" value="ernie" checked><span><?php echo h($T['provider_ernie']); ?> <em><?php echo h($T['provider_default']); ?></em></span></label>
        <label class="mode-pill"><input type="radio" name="image-provider" value="codex_subscription"><span><?php echo h($T['provider_codex']); ?></span></label>
        <div class="provider-note" id="provider-hint"><?php echo h($T['provider_hint_default']); ?></div>
      </div>
      <?php else: ?>
      <div class="provider-note"><?php echo h($T['user_note']); ?></div>
      <?php endif; ?>
      <div class="billing-bar"><span id="billing-summary"><?php echo h($T['billing_checking']); ?></span><?php if (!$is_admin): ?><button id="buy-credit" class="btn-ghost btn-sm" type="button"><?php echo h($T['buy_credit']); ?></button><?php endif; ?></div>
      <div class="hint"><?php echo h($T['hint']); ?></div>
      <div id="message" class="toast"></div>
    </div>
  </div></section>

  <section><div class="panel">
    <div class="panel-head"><span><?php echo h($T['status_head']); ?></span><small id="job-id"><?php echo h($T['job_none']); ?></small></div>
    <div class="panel-body">
      <div class="status-line"><span id="status" class="badge status-pill">idle</span><span id="image-provider-status" class="badge status-pill"><?php echo h($T['img_none']); ?></span><strong id="title"><?php echo h($T['title_placeholder']); ?></strong></div>
      <div class="progress"><div id="progress" class="bar"></div></div>
      <div id="summary" class="summary"><?php echo h($T['summary_placeholder']); ?></div>
      <ol id="script" class="script"></ol>
      <div id="player" class="player" style="display:none;"></div>
      <div class="actions">
        <a id="kurage-link" class="btn btn-primary" href="#" target="_blank" rel="noopener"><?php echo h($T['open_kurage']); ?></a>
        <button id="copy" class="btn-ghost" disabled><?php echo h($T['copy']); ?></button>
        <button id="post-x" class="btn-ghost" disabled><?php echo h($T['post_x']); ?></button>
        <button id="delete" class="btn-danger" disabled><?php echo h($T['delete']); ?></button>
      </div>
    </div>
  </div></section>

  <section><div class="panel">
    <div class="panel-head"><span><?php echo h($T['hist_head']); ?></span><button id="reload" class="btn-ghost btn-sm"><?php echo h($T['reload']); ?></button></div>
    <div class="panel-body"><div id="history" class="history"></div></div>
  </div></section>

  <?php endif; ?>
</main>

<footer class="site"><div class="wrap">
  Kurage Montage — <a href="https://exbridge.jp/"><?php echo h($T['footer_product']); ?></a> ·
  <a href="https://kmontage.exbridge.jp/<?php echo $lang === 'en' ? '' : 'kmontage.html'; ?>" target="_blank" rel="noopener"><?php echo h($T['footer_lp']); ?></a> ·
  <a href="https://kurage.exbridge.jp/kuragev.php" target="_blank" rel="noopener"><?php echo h($T['nav_watch']); ?></a> ·
  <a href="https://exbridge.jp/contact.php"><?php echo h($T['footer_contact']); ?></a>
  <br><br>&copy; <span id="y"></span> EXBRIDGE, Inc.
</div></footer>
<script>document.getElementById('y').textContent=new Date().getFullYear();</script>

<?php if ($logged_in && !$is_admin): ?>
<dialog id="billing-dialog"><div class="billing-dialog">
  <h2><?php echo h($T['bd_title']); ?></h2>
  <p><?php echo $T['bd_lead']; ?></p>
  <div class="billing-grid">
    <div class="billing-card"><h3>PayPal</h3><p><?php echo h($T['bd_paypal_p']); ?></p><div id="paypal-buttons"></div></div>
    <div class="billing-card"><h3><?php echo h($T['bd_urlai_h']); ?></h3><p><?php echo h($T['bd_urlai_p']); ?></p><div id="billing-receiver" class="billing-address">-</div><input id="billing-wallet" placeholder="<?php echo h($T['bd_wallet_ph']); ?>"><button id="verify-urlai" class="btn-primary" type="button"><?php echo h($T['bd_verify']); ?></button></div>
  </div>
  <div id="billing-message" class="billing-msg"></div>
  <div class="actions"><button id="billing-close" class="btn-ghost" type="button"><?php echo h($T['bd_close']); ?></button></div>
</div></dialog>
<?php endif; ?>

<?php if ($logged_in): ?>
<script>
const KMONTAGE_CSRF = <?php echo json_encode($csrf, JSON_UNESCAPED_SLASHES); ?>;
const KMONTAGE_IS_ADMIN = <?php echo $is_admin ? 'true' : 'false'; ?>;
const T = <?php echo json_encode($T['js'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
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
  $('mode-hint').textContent = editorMode() === 'llm' ? T.mode_hint_llm : T.mode_hint_normal;
}));
document.querySelectorAll('input[name="image-provider"]').forEach(r => r.addEventListener('change', () => {
  $('provider-hint').textContent = imageProvider() === 'ernie' ? T.provider_hint_ernie : T.provider_hint_codex;
}));
function setActions(enabled){ $('copy').disabled = !enabled; $('post-x').disabled = !enabled; $('delete').disabled = !currentJobId; }
function scriptLines(job){ const script = job.kurage_script || job.script || {}; const scenes = Array.isArray(script.scenes) ? script.scenes : []; if (scenes.length) return scenes.map(s => s.narration || '').filter(Boolean); return Array.isArray(job.script_outline) ? job.script_outline : []; }
function statusLabel(job){ return T.status[job.status] || job.status || T.status_unknown; }
function displayProgress(job){ return job.status === 'done' ? 100 : Math.max(0, Math.min(100, Number(job.failed_at_progress ?? job.progress ?? 0))); }
function progressText(job){ const p = displayProgress(job); return job.status === 'error' ? T.err_stop.replace('{p}', p) : `${statusLabel(job)} / ${p}%`; }
function jobTitle(job){ return job.kurage_title || job.title || job.source_title || job.url || T.job_generating; }
function renderJob(job){
  currentJobId = job.id || currentJobId;
  currentJobUrl = job.url || '';
  if (job.url) $('source-url').value = job.url;
  if (job.image_provider) {
    const selectedProvider = job.image_provider === 'ernie' ? 'ernie' : 'codex_subscription';
    const radio = document.querySelector(`input[name="image-provider"][value="${selectedProvider}"]`);
    if (radio) radio.checked = true;
  }
  $('job-id').textContent = currentJobId || T.job_none;
  const st = job.status || 'unknown';
  $('status').textContent = progressText(job);
  $('status').className = 'badge ' + (st === 'done' ? 'done' : st === 'error' ? 'error' : 'status-pill');
  const requestedProvider = job.image_provider === 'ernie' ? 'ERNIE' : 'ChatGPT/Codex';
  const actualProvider = job.image_provider_actual === 'ernie' ? 'ERNIE' : job.image_provider_actual === 'codex_subscription' ? 'ChatGPT/Codex' : job.image_provider_actual === 'mixed' ? T.img_mixed : '';
  const fallbackCount = Number(job.image_provider_fallbacks || 0);
  $('image-provider-status').textContent = `${T.img_prefix}${requestedProvider}${actualProvider ? ` → ${actualProvider}` : ''}${fallbackCount ? T.img_alt.replace('{n}', fallbackCount) : ''}`;
  $('progress').style.width = `${displayProgress(job)}%`;
  $('title').textContent = jobTitle(job);
  $('summary').textContent = job.summary || job.reference_analysis?.core_claim || job.analysis?.reference_analysis?.core_claim || job.transcript_preview || T.analyzing_placeholder;
  const list = $('script'); list.innerHTML = '';
  for (const line of scriptLines(job)) { const li = document.createElement('li'); li.textContent = line; list.appendChild(li); }
  const link = job.video_url || job.kurage_url || '#'; $('kurage-link').href = link;
  if (st === 'done' && job.kurage_job_id) {
    $('player').style.display = 'block';
    const videoUrl = `https://kurage.exbridge.jp/kuragev.php?proxy=video&job_id=${encodeURIComponent(job.kurage_job_id)}`;
    $('player').innerHTML = `<video src="${videoUrl}" controls playsinline preload="metadata"></video>`;
    setActions(true);
  } else if (st === 'error') {
    $('player').style.display = 'block'; $('player').innerHTML = `<div style="padding:1rem;color:var(--red);">${esc(T.error_prefix)}${esc(job.error || '')}</div>`; setActions(false);
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
  const url = $('source-url').value.trim(); if (!url) return message(T.msg_need_url);
  $('generate').disabled = true; message(T.msg_starting);
  try { const sameLoadedUrl = currentJobId && currentJobUrl && url === currentJobUrl; const endpoint = sameLoadedUrl ? `<?php echo h($THIS_FILE); ?>?proxy=regenerate&job_id=${encodeURIComponent(currentJobId)}` : '<?php echo h($THIS_FILE); ?>?proxy=create'; const vtuber = vtuberMode(); const data = await fetchJson(endpoint, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({url, vtuber_mode:vtuber, video_style:vtuber?'ai_avatar_explainer':'faceless_documentary', image_provider:imageProvider(), editor_mode:editorMode(), publish_to_kuragev:publishToKuragev()})}); currentJobId = data.job_id; currentJobUrl = url; message(`${sameLoadedUrl ? T.msg_regen : T.msg_started}: ${currentJobId}`); clearInterval(pollTimer); await loadBilling(); const job = await poll(currentJobId); if (!['done','error'].includes(job.status)) pollTimer = setInterval(() => poll(currentJobId), 5000); }
  catch(e){ if (e.status === 402) { message(T.msg_payment); openBilling(); } else { message(e.message || String(e)); } }
  finally { $('generate').disabled = false; }
});
function shareText(){ return `${$('title').textContent}\n${$('summary').textContent}\n${$('kurage-link').href}`; }
$('copy').addEventListener('click', async () => { const text = shareText(); await navigator.clipboard.writeText(text); message(T.msg_copied); });
$('post-x').addEventListener('click', () => { const text = shareText(); window.open(`https://x.com/intent/tweet?text=${encodeURIComponent(text)}`, '_blank', 'noopener'); });
$('delete').addEventListener('click', async () => { if (!currentJobId || !confirm(T.confirm_delete)) return; await fetchJson(`<?php echo h($THIS_FILE); ?>?proxy=delete&job_id=${encodeURIComponent(currentJobId)}`, {method:'POST'}); currentJobId = null; currentJobUrl = ''; $('job-id').textContent=T.deleted; $('status').textContent='deleted'; $('title').textContent=T.title_placeholder; $('summary').textContent=T.summary_placeholder; $('script').innerHTML=''; $('player').style.display='none'; setActions(false); await loadHistory(); });
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
    div.innerHTML = `<button class="job-main" data-id="${esc(job.id)}" type="button"><strong>${esc(jobTitle(job))}</strong><small>${esc(progressText(job))} / ${esc(T.img_prefix)}${esc(provider)} / ${esc(job.url || '')}</small>${kurage}</button>`;
    div.querySelector('button').addEventListener('click', async () => openJob(job));
    box.appendChild(div);
  }
  if (!box.innerHTML) box.innerHTML = `<div class="hint">${esc(T.no_history)}</div>`;
}
$('reload').addEventListener('click', loadHistory);

let billingInfo = null;
async function loadBilling(){
  billingInfo = await fetchJson('<?php echo h($THIS_FILE); ?>?proxy=billing_status');
  $('billing-summary').innerHTML = billingInfo.admin_bypass
    ? T.billing_admin
    : billingInfo.first_free
      ? T.billing_first
      : T.billing_left.replace('{n}', Number(billingInfo.credits || 0));
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
function billingSay(text, ok){ const el=$('billing-message'); if(!el)return; el.textContent=text||''; el.style.color=ok?'var(--green)':'var(--red)'; }
function mountPayPal(){
  if (!billingInfo || KMONTAGE_IS_ADMIN) return;
  const box=$('paypal-buttons'); if(!box || box.dataset.mounted)return;
  const boot=()=>{ if(!window.paypal||!window.paypal.Buttons)return; box.dataset.mounted='1'; window.paypal.Buttons({
    createOrder:(data,actions)=>actions.order.create({purchase_units:[{amount:{currency_code:'JPY',value:String(billingInfo.price_jpy)}}]}),
    onApprove:async(data,actions)=>{ const order=await actions.order.capture(); try{ const result=await fetchJson('<?php echo h($THIS_FILE); ?>?proxy=billing_paypal',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({order_id:order.id})}); billingSay(result.message,true); await loadBilling(); }catch(error){billingSay(error.message,false);} },
    onError:()=>billingSay(T.paypal_err,false)
  }).render('#paypal-buttons'); };
  if(window.paypal){boot();return;} const script=document.createElement('script'); script.src='https://www.paypal.com/sdk/js?client-id='+encodeURIComponent(billingInfo.paypal_client_id)+'&currency=JPY'; script.onload=boot; document.head.appendChild(script);
}
if (!KMONTAGE_IS_ADMIN) {
  $('buy-credit').addEventListener('click',()=>openBilling().catch(e=>message(e.message)));
  $('billing-close').addEventListener('click',()=>$('billing-dialog').close());
  $('verify-urlai').addEventListener('click',async()=>{ const wallet=$('billing-wallet').value.trim(); if(!wallet)return billingSay(T.need_wallet,false); billingSay(T.urlai_checking,true); try{ const result=await fetchJson('<?php echo h($THIS_FILE); ?>?proxy=billing_urlai',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({wallet})}); billingSay(result.message,true); await loadBilling(); }catch(error){billingSay(error.message,false);} });
}

$('source-url').addEventListener('input', () => { if ($('source-url').value.trim() !== currentJobUrl) currentJobId = null; });
async function openInitialJob(){ await Promise.all([loadHistory(), loadBilling()]); const jobId = new URLSearchParams(location.search).get('job'); if (jobId) { const job = await poll(jobId); clearInterval(pollTimer); if (!['done','error'].includes(job.status)) pollTimer = setInterval(() => poll(jobId), 5000); } }
openInitialJob().catch(e => message(e.message || String(e)));
</script>
<?php endif; ?>
</body>
</html>
