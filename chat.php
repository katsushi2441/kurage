<?php
/**
 * Kurage AI — Kurageなんでも相談チャット（公開）。
 * 前面(GET)＋バックエンド(kopenkb serve.py)へのサーバー側プロキシ(POST)。
 * トークン/バックエンドURLは chat_secret.php(git管理外) から読む。
 */
@include __DIR__ . '/chat_secret.php';
if (!defined('KOPENKB_BACKEND')) { http_response_code(500); exit('config missing'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    // --- 簡易レート制限（IPごと 30回/時） ---
    $ip = preg_replace('/[^0-9A-Fa-f.:]/', '', $_SERVER['REMOTE_ADDR'] ?? 'x');
    $rl = sys_get_temp_dir() . '/kopenkb_rl_' . md5($ip);
    $now = time();
    $fp = fopen($rl, 'c+');
    if ($fp) {
        flock($fp, LOCK_EX);
        $hits = array_filter(array_map('intval', explode(',', stream_get_contents($fp))), function ($t) use ($now) { return $t > $now - 3600; });
        if (count($hits) >= 30) {
            flock($fp, LOCK_UN); fclose($fp);
            http_response_code(429);
            echo json_encode(['answer' => 'アクセスが集中しています。しばらくしてからお試しください。'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $hits[] = $now;
        ftruncate($fp, 0); rewind($fp); fwrite($fp, implode(',', $hits));
        flock($fp, LOCK_UN); fclose($fp);
    }

    $in = json_decode(file_get_contents('php://input'), true);
    $q = mb_substr(trim((string)($in['question'] ?? '')), 0, 500, 'UTF-8');
    $mode = (($in['mode'] ?? 'agency') === 'consult') ? 'consult' : 'agency';
    if ($q === '') { echo json_encode(['answer' => '質問を入力してください。'], JSON_UNESCAPED_UNICODE); exit; }

    $ch = curl_init(KOPENKB_BACKEND . '/ask');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-KOPENKB-TOKEN: ' . KOPENKB_TOKEN],
        CURLOPT_POSTFIELDS => json_encode(['question' => $q, 'mode' => $mode], JSON_UNESCAPED_UNICODE),
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code >= 500 || $code === 0) {
        echo json_encode(['answer' => 'ただいま混み合っています。少し待って再度お試しください。'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo $res;
    exit;
}
?><!doctype html><html lang="ja"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kurage AI — Kurageのことなら何でも相談できるAIチャット</title>
<meta name="description" content="Kurageの業務システム・LP・デモ・料金・代理店制度・AI技術記事について、AIが何でも答えます。顧客の悩みから最適な商品と紹介手数料まで案内。">
<link rel="canonical" href="https://kurage.exbridge.jp/chat.php">
<meta property="og:title" content="Kurage AI — Kurageなんでも相談">
<meta property="og:description" content="Kurageの商品・料金・デモ・代理店制度・技術記事を、AIが何でも案内します。">
<meta property="og:url" content="https://kurage.exbridge.jp/chat.php">
<meta property="og:type" content="website">
<style>
:root{--c:#0b91a7;--a:#2f6bd8;--ink:#17324d;--mut:#64788a;--bd:#dbe6ee}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,"Hiragino Sans","Noto Sans JP",sans-serif;background:#eef2f5;color:#22303c}
header{background:linear-gradient(120deg,var(--c),var(--a));color:#fff;padding:14px 16px;font-weight:900;display:flex;align-items:center;gap:10px}
header .em{font-size:22px}
main{max-width:760px;margin:0 auto;padding:14px 14px 96px}
.modes{display:flex;gap:8px;margin-bottom:10px}
.modes button{flex:1;border:1px solid var(--bd);background:#fff;border-radius:999px;padding:9px;font-weight:800;color:#42566a;cursor:pointer}
.modes button.on{background:var(--a);color:#fff;border-color:var(--a)}
#log{display:flex;flex-direction:column;gap:12px;margin-bottom:12px}
.msg{padding:13px 15px;border-radius:14px;line-height:1.7;font-size:14px}
.me{background:#e7eefc;align-self:flex-end;max-width:85%}
.ai{background:#fff;border:1px solid var(--bd);box-shadow:0 5px 14px rgba(20,40,60,.05)}
.ai a{color:var(--a);word-break:break-all}.ai h2,.ai h3{font-size:15px;margin:.6em 0 .2em}
.ai code{background:#f3f6f8;padding:1px 5px;border-radius:5px}
.bar{display:flex;gap:8px;position:fixed;left:0;right:0;bottom:0;background:#eef2f5;padding:10px;max-width:760px;margin:0 auto}
.bar textarea{flex:1;border:1px solid var(--bd);border-radius:12px;padding:11px;font:inherit;resize:none;height:46px;background:#fff}
.bar button{border:0;border-radius:12px;background:var(--a);color:#fff;font-weight:800;padding:0 18px;cursor:pointer}
.hint{color:var(--mut);font-size:12px;margin:2px 2px 12px}
.ex{color:var(--a);font-size:12.5px;cursor:pointer;text-decoration:underline;margin-right:12px}
.foot{color:#9aa8b5;font-size:11px;text-align:center;margin-top:8px}
</style></head><body>
<header><span class="em">🪼</span>Kurage AI — Kurageなんでも相談</header>
<main>
  <div class="modes">
    <button id="m_agency" class="on" onclick="setMode('agency')">代理店・営業支援</button>
    <button id="m_consult" onclick="setMode('consult')">Kurage相談</button>
  </div>
  <p class="hint">Kurageの商品・LP・デモ・料金・代理店制度・AI技術記事について答えます。
    <span class="ex" onclick="ex('サロンの予約管理に困ってる客がいる。何を勧める？')">例: サロンの予約管理</span>
    <span class="ex" onclick="ex('AIで請求書を自動化したい客に合う商品は？')">例: 請求書自動化</span></p>
  <div id="log"></div>
  <p class="foot">回答はKurageのナレッジベースに基づきます（AI生成のため誤りが含まれる場合があります）。</p>
</main>
<div class="bar">
  <textarea id="q" placeholder="質問を入力（例: サロンの予約管理を探してる客がいる）" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();send()}"></textarea>
  <button onclick="send()">送信</button>
</div>
<script>
let mode='agency';
function setMode(m){mode=m;document.getElementById('m_agency').className=m=='agency'?'on':'';document.getElementById('m_consult').className=m=='consult'?'on':'';}
function ex(t){document.getElementById('q').value=t;send();}
function esc(s){return s.replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))}
function md(t){return esc(t)
  .replace(/^###?\s*(.+)$/gm,'<h3>$1</h3>')
  .replace(/\*\*(.+?)\*\*/g,'<b>$1</b>')
  .replace(/\[\[[^\]]+\]\]/g,'')
  .replace(/(https?:\/\/[^\s<)]+)/g,'<a href="$1" target=_blank rel=noopener>$1</a>')
  .replace(/^\-\s*(.+)$/gm,'• $1').replace(/\n/g,'<br>');}
const log=document.getElementById('log');
function add(cls,html){const d=document.createElement('div');d.className='msg '+cls;d.innerHTML=html;log.appendChild(d);d.scrollIntoView();return d;}
let busy=false;
async function send(){
  if(busy)return;
  const el=document.getElementById('q');const q=el.value.trim();if(!q)return;el.value='';
  add('me',esc(q));const wait=add('ai','考え中…🪼');busy=true;
  try{const r=await fetch('chat.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({question:q,mode})});
    const j=await r.json();wait.innerHTML=md(j.answer||'（空）');}
  catch(e){wait.innerHTML='通信エラー: '+esc(String(e));}
  busy=false;
}
</script>
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','G-BP0650KDFR');</script>
<script>(function(){var s=document.createElement('script');s.src='https://kurage.exbridge.jp/simpletrack.php?url='+encodeURIComponent(location.href)+'&ref='+encodeURIComponent(document.referrer);document.head.appendChild(s)})();</script>
</body></html>
