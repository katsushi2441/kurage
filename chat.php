<?php
/**
 * Kurage.AI — システム開発相談チャット（X認証つき）。
 * 未ログイン: LP（システム概要・使い方・対象者）／ログイン後: チャット。
 * POST /ask はログイン必須。利用記録はバックエンド(serve.py)へXユーザー名を渡して行う。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_common.php';
@include __DIR__ . '/chat_secret.php';
if (!defined('KOPENKB_BACKEND')) { http_response_code(500); exit('config missing'); }
date_default_timezone_set('Asia/Tokyo');

if (isset($_GET['login']))  { header('Location: ' . url2ai_auth_login_url('/chat.php'));  exit; }
if (isset($_GET['logout'])) { header('Location: ' . url2ai_auth_logout_url('/chat.php')); exit; }

$auth = url2ai_auth_bootstrap();
$logged_in = !empty($auth['logged_in']);
$user = $logged_in ? trim((string)$auth['session_user']) : '';
$is_admin = $logged_in && !empty($auth['is_admin']);  // 管理者(xb_bittensor)。音声＝管理者限定・回答はClaude

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ---- 音声読み上げ(Audio8/kurage話者クローン)プロキシ: chat.php?tts=1 → audio/wav ----
    if (isset($_GET['tts'])) {
        if (!$is_admin) { http_response_code(403); exit; }   // 音声読み上げは管理者のみ
        // TTS用レート制限（ユーザーごと 80回/時・GPU保護）
        $tkey = preg_replace('/[^0-9A-Za-z_.:-]/', '', $user) ?: 'x';
        $trl = sys_get_temp_dir() . '/kopenkb_tts_' . md5($tkey);
        $tn = time(); $tfp = fopen($trl, 'c+');
        if ($tfp) {
            flock($tfp, LOCK_EX);
            $th = array_filter(array_map('intval', explode(',', stream_get_contents($tfp))), function ($t) use ($tn) { return $t > $tn - 3600; });
            if (count($th) >= 80) { flock($tfp, LOCK_UN); fclose($tfp); http_response_code(429); exit; }
            $th[] = $tn; ftruncate($tfp, 0); rewind($tfp); fwrite($tfp, implode(',', $th));
            flock($tfp, LOCK_UN); fclose($tfp);
        }
        $in = json_decode(file_get_contents('php://input'), true);
        $text = mb_substr(trim((string)($in['text'] ?? '')), 0, 1200, 'UTF-8');
        if ($text === '') { http_response_code(400); exit; }
        $ch = curl_init(KOPENKB_BACKEND . '/tts');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 155,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-KOPENKB-TOKEN: ' . KOPENKB_TOKEN, 'X-KOPENKB-USER: ' . $user],
            CURLOPT_POSTFIELDS => json_encode(['text' => $text], JSON_UNESCAPED_UNICODE),
        ]);
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($res === false || $code !== 200) { http_response_code(502); exit; }
        header('Content-Type: audio/wav');
        header('Content-Length: ' . strlen($res));
        echo $res; exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    if (!$logged_in) {
        http_response_code(401);
        echo json_encode(['answer' => 'ログインが必要です。ページを再読み込みしてXでログインしてください。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // レート制限（ユーザーごと 40回/時）
    $rlkey = preg_replace('/[^0-9A-Za-z_.:-]/', '', $user) ?: 'x';
    $rl = sys_get_temp_dir() . '/kopenkb_rl_' . md5($rlkey);
    $now = time();
    $fp = fopen($rl, 'c+');
    if ($fp) {
        flock($fp, LOCK_EX);
        $hits = array_filter(array_map('intval', explode(',', stream_get_contents($fp))), function ($t) use ($now) { return $t > $now - 3600; });
        if (count($hits) >= 40) {
            flock($fp, LOCK_UN); fclose($fp);
            http_response_code(429);
            echo json_encode(['answer' => '本日の利用が集中しています。しばらくしてからお試しください。'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $hits[] = $now; ftruncate($fp, 0); rewind($fp); fwrite($fp, implode(',', $hits));
        flock($fp, LOCK_UN); fclose($fp);
    }

    $in = json_decode(file_get_contents('php://input'), true);
    $q = mb_substr(trim((string)($in['question'] ?? '')), 0, 500, 'UTF-8');
    if ($q === '') { echo json_encode(['answer' => '質問を入力してください。'], JSON_UNESCAPED_UNICODE); exit; }

    $ch = curl_init(KOPENKB_BACKEND . '/ask');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-KOPENKB-TOKEN: ' . KOPENKB_TOKEN, 'X-KOPENKB-USER: ' . $user, 'X-KOPENKB-TIER: ' . ($is_admin ? 'admin' : 'user')],
        CURLOPT_POSTFIELDS => json_encode(['question' => $q], JSON_UNESCAPED_UNICODE),
    ]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($res === false || $code >= 500 || $code === 0) {
        echo json_encode(['answer' => 'ただいま混み合っています。少し待って再度お試しください。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo $res; exit;
}

$H = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

// ===== 管理者専用: 一般ユーザーの利用状況ダッシュボード =====
if ($is_admin && isset($_GET['admin'])) {
    $detailUser = isset($_GET['user']) ? trim((string)$_GET['user']) : '';
    $qs = ($detailUser !== '') ? '?user=' . rawurlencode($detailUser) . '&limit=500' : '';
    $ch = curl_init(KOPENKB_BACKEND . '/usage' . $qs);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['X-KOPENKB-TOKEN: ' . KOPENKB_TOKEN]]);
    $res = curl_exec($ch); curl_close($ch);
    $data = json_decode($res ?: 'null', true);
    $fmt  = function ($ts) { return $ts ? substr(str_replace('T', ' ', (string)$ts), 0, 16) : '—'; };
    $xurl = function ($u) { return 'https://x.com/' . rawurlencode((string)$u); };
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html lang="ja"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>利用状況 — Kurage.AI 管理</title>
<style>
:root{--ink:#12202f;--mut:#55697a;--bg:#f5fbfb;--panel:#fff;--line:#cde5e2;--teal:#0a726b;--teal2:#12a99f;--me:#dff1ef}
@media(prefers-color-scheme:dark){:root{--ink:#eaf3f3;--mut:#9fb3ba;--bg:#0c1720;--panel:#12242a;--line:#1f3a3f;--teal:#2bd4c6;--teal2:#2bd4c6;--me:#12343a}}
*{box-sizing:border-box}body{margin:0;font-family:"Hiragino Sans","Yu Gothic",Meiryo,sans-serif;background:var(--bg);color:var(--ink);line-height:1.7}
header{position:sticky;top:0;z-index:5;background:var(--panel);border-bottom:1px solid var(--line);padding:11px 18px;display:flex;align-items:center;gap:11px}
header .t{font-weight:900;font-size:15px}header .t small{color:var(--mut);font-weight:700;font-size:11px;display:block}
header .sp{margin-left:auto}header a.nav{color:var(--teal);font-weight:800;text-decoration:none;font-size:13px}
.wrap{max-width:1000px;margin:0 auto;padding:18px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:14px 16px;margin-bottom:14px}
.stat{display:flex;gap:22px;flex-wrap:wrap}.stat b{font-size:22px;color:var(--teal)}.stat span{color:var(--mut);font-size:12px;display:block}
.overflow{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13.5px;min-width:640px}
th,td{text-align:left;padding:10px 9px;border-bottom:1px solid var(--line);vertical-align:top}
th{color:var(--mut);font-size:11.5px;font-weight:800;white-space:nowrap}
.x{display:inline-flex;align-items:center;gap:5px;background:#111;color:#fff;border-radius:999px;padding:5px 11px;font-weight:800;font-size:12px;text-decoration:none;white-space:nowrap}
.pill{display:inline-block;background:var(--bg);border:1px solid var(--line);border-radius:999px;padding:1px 9px;font-size:11px;font-weight:800;color:var(--teal);margin-right:4px}
.n{font-weight:900;color:var(--teal)}.mut{color:var(--mut)}.q{color:var(--ink)}
a.detail{color:var(--teal);font-weight:800;text-decoration:none;white-space:nowrap}
.uhead{display:flex;align-items:center;gap:14px}
.uhead img{width:64px;height:64px;border-radius:50%;border:2px solid var(--teal);object-fit:cover;flex:none;background:var(--bg)}
.uhead h2{margin:0;font-size:20px}.uhead .h{color:var(--mut);font-size:13px}
.btnrow{display:flex;gap:9px;flex-wrap:wrap;margin-top:8px}
.back{display:inline-block;margin-bottom:12px;color:var(--teal);font-weight:800;text-decoration:none;font-size:13px}
.empty{color:var(--mut);text-align:center;padding:30px}
</style></head><body>
<header>
  <span class="t">📊 利用状況<small>Kurage.AI 管理 / @<?php echo $H($user); ?></small></span>
  <span class="sp"></span>
  <a class="nav" href="chat.php">← チャットに戻る</a>
</header>
<div class="wrap">
<?php if (!is_array($data)) { echo '<div class="card empty">利用データを取得できませんでした。</div></div></body></html>'; exit; } ?>
<?php if ($detailUser !== ''):
    $events = is_array($data['events'] ?? null) ? $data['events'] : [];
    $avatar = 'https://unavatar.io/x/' . rawurlencode($detailUser); ?>
  <a class="back" href="?admin=1">← ユーザー一覧へ</a>
  <div class="card">
    <div class="uhead">
      <img src="<?php echo $H($avatar); ?>" alt="" onerror="this.style.visibility='hidden'">
      <div>
        <h2>@<?php echo $H($detailUser); ?></h2>
        <div class="h"><?php echo (int)($data['count'] ?? 0); ?> 件の問い合わせ</div>
        <div class="btnrow">
          <a class="x" href="<?php echo $H($xurl($detailUser)); ?>" target="_blank" rel="noopener">𝕏 プロフィール / DM</a>
          <a class="x" style="background:var(--teal2)" href="https://x.com/intent/user?screen_name=<?php echo rawurlencode($detailUser); ?>" target="_blank" rel="noopener">フォローする</a>
        </div>
      </div>
    </div>
  </div>
  <div class="card overflow">
    <table><thead><tr><th>日時</th><th>種別</th><th>問い合わせ内容</th></tr></thead><tbody>
    <?php if (!$events): ?><tr><td colspan="3" class="empty">履歴なし</td></tr><?php endif; ?>
    <?php foreach ($events as $e): ?>
      <tr><td class="mut" style="white-space:nowrap"><?php echo $H($fmt($e['ts'] ?? '')); ?></td>
      <td><span class="pill"><?php echo $H($e['tier'] ?? 'user'); ?></span></td>
      <td class="q"><?php echo $H($e['q'] ?? ''); ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
<?php else:
    $users = is_array($data['users'] ?? null) ? $data['users'] : [];
    $list = array_values(array_filter($users, function ($a) use ($user) {
        $u = $a['user'] ?? ''; return $u !== '' && $u !== '?' && $u !== $user;   // 管理者自身と不明は除外
    })); ?>
  <div class="card"><div class="stat">
    <div><b><?php echo count($list); ?></b><span>一般ユーザー</span></div>
    <div><b><?php echo (int)($data['event_count'] ?? 0); ?></b><span>総イベント</span></div>
  </div></div>
  <div class="card overflow">
    <table><thead><tr><th>ユーザー（X）</th><th>問い合わせ</th><th>種別</th><th>最終利用</th><th>最新の質問</th><th></th></tr></thead><tbody>
    <?php if (!$list): ?><tr><td colspan="6" class="empty">まだ一般ユーザーの利用がありません。</td></tr><?php endif; ?>
    <?php foreach ($list as $a): $uu = $a['user']; ?>
      <tr>
        <td><a class="x" href="<?php echo $H($xurl($uu)); ?>" target="_blank" rel="noopener">𝕏 @<?php echo $H($uu); ?></a></td>
        <td><span class="n"><?php echo (int)$a['questions']; ?></span><?php if (!empty($a['tts'])): ?> <span class="mut">/🔊<?php echo (int)$a['tts']; ?></span><?php endif; ?></td>
        <td><?php foreach (($a['tiers'] ?? []) as $t): ?><span class="pill"><?php echo $H($t); ?></span><?php endforeach; ?></td>
        <td class="mut" style="white-space:nowrap"><?php echo $H($fmt($a['last_ts'] ?? '')); ?></td>
        <td class="q"><?php echo $H(mb_substr((string)($a['last_q'] ?? ''), 0, 48, 'UTF-8')); ?></td>
        <td><a class="detail" href="?admin=1&user=<?php echo rawurlencode($uu); ?>">照会 →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
  <p class="mut" style="font-size:12px">💡 @ユーザー名をクリックするとXプロフィールが開き、フォロー・DMで直接ご提案できます。</p>
<?php endif; ?>
</div></body></html>
<?php
    exit;
}
?><!doctype html><html lang="ja"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kurage.AI システム開発相談 — バイブコーディング相談＆代理店収益化のAI窓口</title>
<meta name="description" content="Kurage.AIは、業務システムをAIで作りたい人・Kurageの代理店として収益化したい人のためのAI相談窓口。商品・料金・デモ・LP・代理店制度・AI技術記事を、Xログインだけで何でも案内します。">
<link rel="canonical" href="https://kurage.exbridge.jp/chat.php">
<meta property="og:title" content="Kurage.AI システム開発相談">
<meta property="og:description" content="AIで業務システムを作りたい人・Kurage代理店で収益化したい人のためのAI相談窓口。Xログインで無料。">
<meta property="og:url" content="https://kurage.exbridge.jp/chat.php">
<meta property="og:type" content="website">
<meta property="og:image" content="https://kurage.exbridge.jp/chat-ogp.png">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Kurage.AI システム開発相談">
<meta name="twitter:description" content="AIで業務システムを作りたい人・Kurage代理店で収益化したい人のためのAI相談窓口。">
<meta name="twitter:image" content="https://kurage.exbridge.jp/chat-ogp.png">
<link rel="icon" href="kurage-face-384.webp">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<!-- モバイル軽量化: 本文は端末標準の日本語フォント。見出しだけZen Maru(2ウェイト)を読み込む -->
<link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@700;900&display=swap" rel="stylesheet">
<style>
:root{--abyss:#12202f;--abyss-soft:#55697a;--foam:#f5fbfb;--panel:#e7f3f2;--panel-line:#cde5e2;
  --teal:#12a99f;--teal-deep:#0a726b;--gold:#c98a1e;--me:#dff1ef;--shadow:0 14px 40px rgba(10,40,45,.10)}
@media (prefers-color-scheme:dark){:root{--abyss:#eaf3f3;--abyss-soft:#9fb3ba;--foam:#0c1720;--panel:#12242a;
  --panel-line:#1f3a3f;--teal:#2bd4c6;--teal-deep:#1c9e93;--me:#12343a}}
*{box-sizing:border-box;margin:0;padding:0}
body{color:var(--abyss);background:var(--foam);font-family:"Hiragino Sans","Hiragino Kaku Gothic ProN","Yu Gothic","Noto Sans JP",Meiryo,sans-serif;line-height:1.85;overflow-x:hidden}
h1,h2,h3{font-family:"Zen Maru Gothic","Hiragino Sans",sans-serif;text-wrap:balance}
a{color:var(--teal-deep)}
header.site{position:sticky;top:0;z-index:40;background:color-mix(in srgb,var(--foam) 88%,transparent);backdrop-filter:blur(16px);border-bottom:1px solid var(--panel-line)}
header.site .wrap{max-width:960px;margin:0 auto;display:flex;align-items:center;gap:11px;padding:11px 20px}
header .ico{width:38px;height:38px;border-radius:50%;overflow:hidden;border:2px solid var(--teal);flex:none}
header .ico img{width:100%;height:100%;object-fit:cover;object-position:50% 12%}
header strong{font-size:15px;font-weight:900;display:block;line-height:1.15}
header span.sub{font-size:11px;color:var(--abyss-soft);font-weight:700}
header .right{margin-left:auto;display:flex;align-items:center;gap:10px}
.xbtn{border-radius:999px;padding:9px 16px;font-weight:900;font-size:12.5px;background:#111;color:#fff;display:inline-flex;align-items:center;gap:7px}
.xbtn.teal{background:linear-gradient(135deg,var(--teal),var(--teal-deep))}
.uinfo{font-size:12px;color:var(--abyss-soft);font-weight:700}
.wrap{max-width:960px;margin:0 auto;padding:0 20px}
/* LP */
.hero{display:grid;grid-template-columns:1.2fr .8fr;gap:32px;align-items:center;padding:44px 0 26px}
.eyebrow{display:inline-flex;align-items:center;gap:8px;background:var(--panel);border:1.5px solid var(--panel-line);border-radius:999px;padding:6px 14px;font-size:12px;font-weight:900;color:var(--teal-deep);margin-bottom:14px}
.dot{width:7px;height:7px;border-radius:50%;background:var(--teal)}
.hero h1{font-size:clamp(26px,4.6vw,40px);font-weight:900;line-height:1.3;margin-bottom:12px}
.hero h1 em{font-style:normal;color:var(--teal-deep)}
.hero .lead{font-size:15px;color:var(--abyss-soft);margin-bottom:20px;max-width:560px}
.hero-card{background:var(--panel);border:1.5px solid var(--panel-line);border-radius:26px;padding:18px;text-align:center;box-shadow:var(--shadow)}
.hero-card img{width:100%;max-width:220px;border-radius:18px;object-fit:cover;object-position:50% 6%}
.cta-lg{display:inline-flex;align-items:center;gap:9px;border-radius:999px;padding:14px 28px;font-weight:900;font-size:16px;background:#111;color:#fff;box-shadow:0 12px 28px rgba(0,0,0,.22)}
.cta-note{font-size:12px;color:var(--abyss-soft);margin-top:10px}
section.blk{padding:30px 0}
section.blk>h2{font-size:clamp(20px,3vw,26px);font-weight:900;margin-bottom:4px;text-align:center}
section.blk>.sub{color:var(--abyss-soft);font-size:14px;text-align:center;margin-bottom:22px}
.two{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.aud{background:var(--panel);border:1.5px solid var(--panel-line);border-radius:22px;padding:24px;box-shadow:var(--shadow)}
.aud .ic{font-size:30px}
.aud h3{font-size:18px;font-weight:900;margin:8px 0 6px}
.aud p{font-size:13.5px;color:var(--abyss-soft)}
.aud ul{list-style:none;margin:12px 0 0}
.aud li{font-size:13px;padding-left:22px;position:relative;margin:6px 0}
.aud li::before{content:"✓";position:absolute;left:0;color:var(--teal);font-weight:900}
.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;counter-reset:st}
.step{position:relative;background:var(--foam);border:1.5px solid var(--panel-line);border-radius:18px;padding:22px 18px 18px;box-shadow:var(--shadow)}
.step::before{counter-increment:st;content:counter(st);position:absolute;top:-15px;left:18px;width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--teal),var(--teal-deep));color:#fff;font-weight:900;display:grid;place-items:center}
.step b{display:block;margin:2px 0 4px;font-size:15px}
.step p{font-size:12.5px;color:var(--abyss-soft)}
.exs{background:var(--panel);border:1.5px solid var(--panel-line);border-radius:20px;padding:22px;box-shadow:var(--shadow)}
.exs li{font-size:14px;padding:7px 0 7px 24px;position:relative;border-top:1px dashed var(--panel-line)}
.exs li:first-child{border-top:0}
.exs li::before{content:"💬";position:absolute;left:0}
.center{text-align:center;padding:12px 0 6px}
footer.site{text-align:center;color:var(--abyss-soft);font-size:12.5px;padding:34px 20px 48px;border-top:1px solid var(--panel-line);margin-top:16px}
footer.site a{font-weight:700}
@media(max-width:760px){.hero{grid-template-columns:1fr;gap:18px}.hero-card{order:-1}.two{grid-template-columns:1fr}.steps{grid-template-columns:1fr}}
/* Chat */
main.chat{max-width:820px;margin:0 auto;padding:16px 18px 96px}
.intro{display:flex;gap:16px;align-items:center;background:var(--panel);border:1.5px solid var(--panel-line);border-radius:22px;padding:16px 18px;box-shadow:var(--shadow);margin-bottom:14px}
.intro img{width:80px;height:96px;object-fit:cover;object-position:50% 8%;border-radius:14px;flex:none}
.intro h2{font-size:18px;font-weight:900;margin-bottom:4px}
.intro p{font-size:12.5px;color:var(--abyss-soft)}
#log{display:flex;flex-direction:column;gap:12px}
.msg{padding:13px 16px;border-radius:16px;line-height:1.75;font-size:14px;box-shadow:var(--shadow)}
.me{background:var(--me);align-self:flex-end;max-width:86%;border:1.5px solid var(--panel-line)}
.ai{background:var(--foam);border:1.5px solid var(--panel-line)}
.ai h3{font-size:15px;color:var(--teal-deep);margin:.6em 0 .2em}.ai a{color:var(--teal-deep);word-break:break-all}.ai b{color:var(--abyss)}
.ai code{background:var(--panel);padding:1px 6px;border-radius:6px}
.foot{color:var(--abyss-soft);font-size:11px;text-align:center;margin-top:16px}
.bar{position:fixed;left:0;right:0;bottom:0;z-index:30;background:color-mix(in srgb,var(--foam) 92%,transparent);backdrop-filter:blur(14px);border-top:1px solid var(--panel-line)}
.bar .wrap{max-width:820px;margin:0 auto;display:flex;gap:9px;padding:11px 18px}
.bar textarea{flex:1;border:1.5px solid var(--panel-line);border-radius:14px;padding:12px 14px;font:inherit;font-size:14px;resize:none;height:48px;background:var(--foam);color:var(--abyss)}
.bar textarea:focus{outline:none;border-color:var(--teal)}
.bar button{border:0;border-radius:14px;background:linear-gradient(135deg,var(--teal),var(--teal-deep));color:#fff;font-weight:900;font-size:14px;padding:0 22px;cursor:pointer}
.bar button:disabled{opacity:.5}
.bar .iconbtn{border:1.5px solid var(--panel-line);background:var(--foam);color:var(--abyss);border-radius:14px;font-size:19px;width:48px;height:48px;flex:none;cursor:pointer;display:grid;place-items:center;padding:0}
.bar .iconbtn.on{background:linear-gradient(135deg,var(--teal),var(--teal-deep));color:#fff;border-color:transparent}
.bar .iconbtn.rec{background:#e5484d;color:#fff;border-color:transparent;animation:pulse 1s infinite}
@keyframes pulse{50%{opacity:.5}}
.spk{margin-top:9px}
.spkbtn{border:1.5px solid var(--panel-line);background:var(--foam);color:var(--teal-deep);border-radius:999px;font-size:12px;font-weight:800;padding:5px 13px;cursor:pointer}
.spkbtn:disabled{opacity:.55}
@media(max-width:520px){.bar .iconbtn{width:44px;height:44px;font-size:17px}.bar button{padding:0 16px}}
</style></head><body>
<header class="site"><div class="wrap">
  <span class="ico"><img src="kurage-face-384.webp" alt="Kurage"></span>
  <div><strong>Kurage.AI</strong><span class="sub">システム開発相談</span></div>
  <div class="right">
<?php if ($logged_in): ?>
    <span class="uinfo">@<?php echo $H($user); ?></span>
<?php if ($is_admin): ?>
    <a class="xbtn teal" href="?admin=1" title="一般ユーザーの利用状況">📊 利用状況</a>
<?php endif; ?>
    <a class="xbtn" style="background:transparent;color:var(--abyss-soft);border:1.5px solid var(--panel-line)" href="?logout">ログアウト</a>
<?php else: ?>
    <a class="xbtn" href="?login">𝕏 でログイン</a>
<?php endif; ?>
  </div>
</div></header>

<?php if (!$logged_in): /* ===== 未ログイン: LP ===== */ ?>
<main>
  <section class="wrap hero">
    <div>
      <span class="eyebrow"><span class="dot"></span>KURAGE.AI ／ Xログインで無料</span>
      <h1>AIで<em>業務システムを作りたい</em>人と、<br><em>Kurage代理店で稼ぎたい</em>人の相談窓口</h1>
      <p class="lead">Kurageの商品・料金・デモ・LP・代理店制度・AI技術記事を、AIが何でも案内します。
        「こんな業務を自動化したい」「この客に何を勧める？」——書くだけで、最適な答えと次の一歩が返ってきます。</p>
      <a class="cta-lg" href="?login">𝕏 でログインして無料で使う</a>
      <p class="cta-note">Xアカウントでログインするだけ（無料）。どなたが使ったかは記録されます。</p>
    </div>
    <div class="hero-card">
      <img src="kurage-ecosystem-avatar-600.webp" alt="Kurage">
      <p style="font-size:12px;color:var(--abyss-soft);margin-top:8px">Kurageのことなら何でも聞ける🪼</p>
    </div>
  </section>

  <section class="blk wrap">
    <h2>こんな人のためのAIです</h2>
    <p class="sub">2つの使い方で、あなたの「作りたい」「稼ぎたい」を前に進めます</p>
    <div class="two">
      <div class="aud">
        <div class="ic">🛠️</div>
        <h3>バイブコーディングで作りたい人</h3>
        <p>「AIと一緒に、自社の業務システムを安く作りたい」。何から始めればいいか、どのプロトタイプ・OSSが近いか、AIエージェントでどう育てるか——を相談できます。</p>
        <ul>
          <li>やりたい業務に近いKurage商品・プロトタイプを提案</li>
          <li>料金・デモ・作り方（AIで改変・拡張）まで案内</li>
          <li>ローカルLLM・自動化・OSS活用の技術記事も検索</li>
        </ul>
      </div>
      <div class="aud">
        <div class="ic">🤝</div>
        <h3>代理店になって収益化したい人</h3>
        <p>「Kurageの商品を紹介して、紹介手数料で稼ぎたい」。目の前のお客様の悩みに、どの商品を・いくらで・どう勧めるかを、AIがその場で組み立てます。</p>
        <ul>
          <li>顧客の悩み→最適商品＋LP＋デモ＋価格＋<b>紹介手数料</b>を即案内</li>
          <li>代理店登録は無料・成果報酬（プロトタイプ紹介30%／App Store商品10%）</li>
          <li>商品を全部覚えなくても、その場で正しい提案ができる</li>
        </ul>
      </div>
    </div>
  </section>

  <section class="blk wrap">
    <h2>使い方はかんたん</h2>
    <p class="sub">Xでログインして、聞くだけ</p>
    <div class="steps">
      <div class="step"><b>Xでログイン</b><p>右上（または下のボタン）の「𝕏 でログイン」からXアカウントで入るだけ。無料です。</p></div>
      <div class="step"><b>困りごと・目的を書く</b><p>「サロンの予約管理を探してる客がいる」「AIで請求書を自動化したい」など、自然な言葉でOK。</p></div>
      <div class="step"><b>答えと次の一歩が返る</b><p>最適なKurage商品・LP・デモ・価格・（代理店なら）紹介手数料まで、まとめて返ってきます。</p></div>
    </div>
  </section>

  <section class="blk wrap">
    <h2>たとえば、こんなことが聞けます</h2>
    <div class="exs" style="margin-top:8px"><ul>
      <li>サロンの予約管理に困っている客がいる。何を勧める？いくら？</li>
      <li>請求書の発行と集金をAIで自動化したい。合う商品は？</li>
      <li>AIエージェントで業務システムを作るって、どういうこと？</li>
      <li>Kurageの代理店って、どうやって稼ぐの？手数料は？</li>
      <li>ローカルLLM（gemma / DeepSeek）の技術記事はある？</li>
    </ul></div>
    <div class="center" style="margin-top:22px"><a class="cta-lg" href="?login">𝕏 でログインして無料で使う</a></div>
  </section>

  <section class="blk wrap">
    <div style="background:var(--panel);border:1.5px solid var(--panel-line);border-radius:24px;padding:24px;box-shadow:var(--shadow);display:flex;gap:20px;align-items:center;flex-wrap:wrap">
      <img src="xb_bittensor-icon.jpg" alt="@xb_bittensor" loading="lazy" decoding="async" width="96" height="96" style="width:96px;height:96px;border-radius:50%;border:3px solid var(--teal);object-fit:cover;flex:none">
      <div style="flex:1;min-width:260px">
        <span class="eyebrow"><span class="dot"></span>開発・運営 ／ 𝕏 @xb_bittensor</span>
        <h3 style="font-size:clamp(18px,3.4vw,22px);font-weight:900;margin:8px 0 6px">𝕏 <em style="font-style:normal;color:var(--teal-deep)">@xb_bittensor</em> をフォローしてください</h3>
        <p style="font-size:13.5px;color:var(--abyss-soft);margin-bottom:14px">
          Kurageの開発・運営者です。フォローいただくと、新サービスや活用のヒントをお届けします。
          また、いただいたご相談の内容を拝見し、<b style="color:var(--abyss)">@xb_bittensor からフォロー・DMで、あなたに合ったシステムのご提案をさせていただく場合があります</b>。お気軽にご相談ください。
        </p>
        <a class="cta-lg" style="background:#111" href="https://x.com/intent/follow?screen_name=xb_bittensor" target="_blank" rel="noopener">𝕏 @xb_bittensor をフォロー</a>
      </div>
    </div>
  </section>
</main>
<footer class="site"><div class="wrap">
  <a href="/wiki/">Kurage Wiki</a> ・ <a href="https://kappstore.exbridge.jp/">Kurage App Store</a> ・ <a href="https://kurage.exbridge.jp/">Kurage</a><br>
  <small>回答はKurageの公開情報に基づくAI生成です。最終確認は各公式ページをご覧ください。</small>
</div></footer>

<?php else: /* ===== ログイン後: チャット ===== */ ?>
<main class="chat">
  <div class="intro">
    <img src="kurage-ecosystem-avatar-600.webp" alt="Kurage">
    <div>
      <h2>こんにちは、@<?php echo $H($user); ?> さん🪼</h2>
      <p>困りごと・目的を書いてください。最適なKurage商品・LP・デモ・価格・代理店手数料までご案内します。</p>
    </div>
  </div>
  <div id="log"></div>
  <p class="foot">回答は <a href="/wiki/">Kurage Wiki</a> のナレッジベースに基づきます（AI生成のため、最終確認は公式サイトをご覧ください）。</p>
</main>
<div class="bar"><div class="wrap">
<?php if ($is_admin): ?>
  <button id="micBtn" class="iconbtn" type="button" title="音声で質問する" onclick="toggleMic()">🎤</button>
  <button id="autoBtn" class="iconbtn" type="button" title="回答を自動で読み上げ" onclick="toggleAuto()">🔈</button>
<?php endif; ?>
  <textarea id="q" placeholder="<?php echo $is_admin ? '質問を入力（🎤で音声入力も可）' : '質問を入力'; ?>" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();send()}"></textarea>
  <button id="sendBtn" onclick="send()">送信</button>
</div></div>
<script>
const IS_ADMIN=<?php echo $is_admin ? 'true' : 'false'; ?>;   /* 音声機能は管理者のみ */
function esc(s){return s.replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))}
function md(t){return esc(t)
  .replace(/^###?\s*(.+)$/gm,'<h3>$1</h3>').replace(/\*\*(.+?)\*\*/g,'<b>$1</b>')
  .replace(/\[\[[^\]]*\|?([^\]]*)\]\]/g,'')
  .replace(/(https?:\/\/[^\s<)]+)/g,'<a href="$1" target=_blank rel=noopener>$1</a>')
  .replace(/^[-•]\s*(.+)$/gm,'• $1').replace(/\n/g,'<br>');}
const log=document.getElementById('log'),btn=document.getElementById('sendBtn');
function add(cls,html){const d=document.createElement('div');d.className='msg '+cls;d.innerHTML=html;log.appendChild(d);d.scrollIntoView({block:'end'});return d;}
let busy=false;
async function send(){
  if(busy)return;const el=document.getElementById('q'),q=el.value.trim();if(!q)return;el.value='';
  add('me',esc(q));const wait=add('ai','考え中…🪼');busy=true;btn.disabled=true;
  if(IS_ADMIN&&autoRead())playAck();  // 会話モード: まず相槌を即再生「お調べしますね」
  try{const r=await fetch('chat.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({question:q})});
    if(r.status===401){wait.innerHTML='セッションが切れました。<a href="?login">再ログイン</a>してください。';busy=false;btn.disabled=false;return;}
    const j=await r.json();const ans=j.answer||'（空）',speech=j.speech||'';wait.innerHTML=md(ans);
    if(IS_ADMIN){addSpeaker(wait,ans,speech);if(autoRead())speak(speech||ans,null);}}  // 音声は要約のみ
  catch(e){wait.innerHTML='通信エラー: '+esc(String(e));}
  busy=false;btn.disabled=false;wait.scrollIntoView({block:'end'});
}

/* ===== 音声読み上げ（Audio8 / kurage話者クローン） ===== */
let curAudio=null;
function stripTts(t){return t.replace(/https?:\/\/[^\s<)]+/g,'').replace(/\[\[[^\]]*\]\]/g,'').replace(/\*\*(.+?)\*\*/g,'$1').replace(/[#*`>|_~]/g,'').replace(/^[-•]\s*/gm,'').replace(/\n{2,}/g,'\n').trim();}
async function speak(text,b){
  const clean=stripTts(text);if(!clean)return;
  if(curAudio){try{curAudio.pause()}catch(e){}curAudio=null;}
  if(b){b.disabled=true;b.textContent='🔊 読み込み中…';}
  try{
    const r=await fetch('chat.php?tts=1',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({text:clean})});
    if(!r.ok)throw new Error('tts '+r.status);
    const url=URL.createObjectURL(await r.blob());
    curAudio=new Audio(url);
    curAudio.onended=curAudio.onerror=()=>{URL.revokeObjectURL(url);if(b){b.disabled=false;b.textContent='🔊 要点を読み上げ';}};
    await curAudio.play();if(b)b.textContent='⏸ 再生中';
  }catch(e){if(b){b.disabled=false;b.textContent='🔊 要点を読み上げ';}}
}
function addSpeaker(bubble,full,speech){
  const bar=document.createElement('div');bar.className='spk';
  const b=document.createElement('button');b.type='button';b.className='spkbtn';b.textContent='🔊 要点を読み上げ';
  b.onclick=()=>speak(speech||full,b);bar.appendChild(b);bubble.appendChild(bar);
}
function autoRead(){return localStorage.getItem('kchat_auto')==='1';}
function toggleAuto(){localStorage.setItem('kchat_auto',autoRead()?'0':'1');updateAuto();}
function updateAuto(){const b=document.getElementById('autoBtn');if(!b)return;const on=autoRead();b.classList.toggle('on',on);b.textContent=on?'🗣️':'🔈';b.title='音声で会話（相槌＋要点を読み上げ）: '+(on?'ON':'OFF');}

/* ===== 音声入力（ブラウザ音声認識・端末側で無料） ===== */
let rec=null,recOn=false;const SR=window.SpeechRecognition||window.webkitSpeechRecognition;
function toggleMic(){
  if(!SR){alert('この端末・ブラウザは音声入力に未対応です（Google Chrome推奨）。');return;}
  if(recOn){try{rec.stop()}catch(e){}return;}
  rec=new SR();rec.lang='ja-JP';rec.interimResults=true;rec.continuous=false;rec.maxAlternatives=1;
  const el=document.getElementById('q'),mic=document.getElementById('micBtn'),base=el.value;
  rec.onstart=()=>{recOn=true;mic.classList.add('rec');mic.textContent='⏺';};
  rec.onresult=(e)=>{let t='';for(let i=e.resultIndex;i<e.results.length;i++)t+=e.results[i][0].transcript;el.value=(base?base+' ':'')+t;};
  rec.onend=()=>{recOn=false;mic.classList.remove('rec');mic.textContent='🎤';if(el.value.trim())send();};
  rec.onerror=()=>{recOn=false;mic.classList.remove('rec');mic.textContent='🎤';};
  rec.start();
}
/* 相槌クリップ（kurage声・事前生成）を先読みし、質問直後に即再生 */
let ACKS=[];
function playAck(){if(!ACKS.length)return;try{const a=ACKS[Math.floor(Math.random()*ACKS.length)];a.currentTime=0;a.play().catch(()=>{});}catch(e){}}
(function(){if(!IS_ADMIN)return;
  ACKS=['chat-ack-1.mp3','chat-ack-2.mp3','chat-ack-3.mp3'].map(u=>{const a=new Audio(u);a.preload='auto';return a;});
  updateAuto();if(!SR){const m=document.getElementById('micBtn');if(m)m.style.display='none';}})();
</script>
<?php endif; ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','G-BP0650KDFR');</script>
<script>(function(){var s=document.createElement('script');s.src='https://kurage.exbridge.jp/simpletrack.php?url='+encodeURIComponent(location.href)+'&ref='+encodeURIComponent(document.referrer);document.head.appendChild(s)})();</script>
</body></html>
