<?php
/**
 * Kurage.AI — システム開発相談チャット（公開）。
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
    if ($q === '') { echo json_encode(['answer' => '質問を入力してください。'], JSON_UNESCAPED_UNICODE); exit; }

    $ch = curl_init(KOPENKB_BACKEND . '/ask');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-KOPENKB-TOKEN: ' . KOPENKB_TOKEN],
        CURLOPT_POSTFIELDS => json_encode(['question' => $q], JSON_UNESCAPED_UNICODE),
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
<title>Kurage.AI システム開発相談 — Kurageのことを何でも聞けるAI</title>
<meta name="description" content="Kurage.AIのシステム開発相談チャット。業務システム・料金・デモ・代理店制度・AI技術記事について、AIが何でも案内します。困りごとから最適な商品・デモ・価格までご提案。">
<link rel="canonical" href="https://kurage.exbridge.jp/chat.php">
<meta property="og:title" content="Kurage.AI システム開発相談">
<meta property="og:description" content="Kurageのことを何でも聞けるAIチャット。商品・料金・デモ・技術記事を案内します。">
<meta property="og:url" content="https://kurage.exbridge.jp/chat.php">
<meta property="og:type" content="website">
<meta property="og:image" content="https://kurage.exbridge.jp/chat-ogp.png">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Kurage.AI システム開発相談">
<meta name="twitter:description" content="Kurageのことを何でも聞けるAIチャット。商品・料金・デモ・技術記事を案内します。">
<meta name="twitter:image" content="https://kurage.exbridge.jp/chat-ogp.png">
<link rel="icon" href="kurage-face-384.webp">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Zen+Kaku+Gothic+New:wght@400;500;700;900&family=Zen+Maru+Gothic:wght@700;900&display=swap" rel="stylesheet">
<style>
:root{--abyss:#12202f;--abyss-soft:#55697a;--foam:#f5fbfb;--panel:#e7f3f2;--panel-line:#cde5e2;
  --teal:#12a99f;--teal-deep:#0a726b;--me:#dff1ef;--shadow:0 14px 40px rgba(10,40,45,.10)}
@media (prefers-color-scheme:dark){:root{--abyss:#eaf3f3;--abyss-soft:#9fb3ba;--foam:#0c1720;--panel:#12242a;
  --panel-line:#1f3a3f;--teal:#2bd4c6;--teal-deep:#1c9e93;--me:#12343a;--shadow:0 14px 40px rgba(0,0,0,.38)}}
*{box-sizing:border-box;margin:0;padding:0}
body{color:var(--abyss);background:var(--foam);font-family:"Zen Kaku Gothic New","Hiragino Sans","Yu Gothic",Meiryo,sans-serif;line-height:1.8;min-height:100vh;overflow-x:hidden;padding-bottom:96px}
h1,h2,h3{font-family:"Zen Maru Gothic","Zen Kaku Gothic New",sans-serif}
a{color:var(--teal-deep);word-break:break-all}
header.site{position:sticky;top:0;z-index:40;background:color-mix(in srgb,var(--foam) 88%,transparent);backdrop-filter:blur(16px);border-bottom:1px solid var(--panel-line)}
header.site .wrap{max-width:820px;margin:0 auto;display:flex;align-items:center;gap:11px;padding:11px 18px}
header .ico{width:40px;height:40px;border-radius:50%;overflow:hidden;border:2px solid var(--teal);flex:none}
header .ico img{width:100%;height:100%;object-fit:cover;object-position:50% 12%;display:block}
header strong{font-size:16px;font-weight:900;display:block;line-height:1.15}
header span{font-size:11px;color:var(--abyss-soft);font-weight:700}
main{max-width:820px;margin:0 auto;padding:16px 18px}
.intro{display:flex;gap:16px;align-items:center;background:var(--panel);border:1.5px solid var(--panel-line);border-radius:24px;padding:16px 18px;box-shadow:var(--shadow);margin-bottom:14px}
.intro img{width:94px;height:110px;object-fit:cover;object-position:50% 8%;border-radius:16px;flex:none;background:var(--foam)}
.intro .eyebrow{display:inline-flex;align-items:center;gap:7px;background:var(--foam);border:1.5px solid var(--panel-line);border-radius:999px;padding:5px 12px;font-size:11px;font-weight:900;color:var(--teal-deep);margin-bottom:7px}
.intro .dot{width:7px;height:7px;border-radius:50%;background:var(--teal)}
.intro h1{font-size:clamp(19px,4.4vw,24px);font-weight:900;line-height:1.35;margin-bottom:5px}
.intro h1 em{font-style:normal;color:var(--teal-deep)}
.intro p{font-size:12.5px;color:var(--abyss-soft)}
.chips{display:flex;flex-wrap:wrap;gap:8px;margin:0 2px 14px}
.chip{background:var(--panel);border:1.5px solid var(--panel-line);border-radius:12px;padding:8px 13px;font-size:12.5px;font-weight:700;color:var(--teal-deep);cursor:pointer}
.chip:hover{border-color:var(--teal)}
#log{display:flex;flex-direction:column;gap:12px}
.msg{padding:13px 16px;border-radius:16px;line-height:1.75;font-size:14px;box-shadow:var(--shadow)}
.me{background:var(--me);align-self:flex-end;max-width:86%;border:1.5px solid var(--panel-line)}
.ai{background:var(--foam);border:1.5px solid var(--panel-line)}
.ai h3{font-size:15px;color:var(--teal-deep);margin:.6em 0 .2em}
.ai a{color:var(--teal-deep)}.ai b{color:var(--abyss)}
.ai code{background:var(--panel);padding:1px 6px;border-radius:6px;font-size:12.5px}
.foot{color:var(--abyss-soft);font-size:11px;text-align:center;margin-top:16px}
.bar{position:fixed;left:0;right:0;bottom:0;z-index:30;background:color-mix(in srgb,var(--foam) 92%,transparent);backdrop-filter:blur(14px);border-top:1px solid var(--panel-line)}
.bar .wrap{max-width:820px;margin:0 auto;display:flex;gap:9px;padding:11px 18px}
.bar textarea{flex:1;border:1.5px solid var(--panel-line);border-radius:14px;padding:12px 14px;font:inherit;font-size:14px;resize:none;height:48px;background:var(--foam);color:var(--abyss)}
.bar textarea:focus{outline:none;border-color:var(--teal)}
.bar button{border:0;border-radius:14px;background:linear-gradient(135deg,var(--teal),var(--teal-deep));color:#fff;font-weight:900;font-size:14px;padding:0 22px;cursor:pointer;box-shadow:0 10px 24px rgba(18,169,159,.28)}
.bar button:disabled{opacity:.5}
</style></head><body>
<header class="site"><div class="wrap">
  <span class="ico"><img src="kurage-face-384.webp" alt="Kurage"></span>
  <div><strong>Kurage.AI</strong><span>システム開発相談</span></div>
</div></header>
<main>
  <div class="intro">
    <img src="kurage-ecosystem-avatar-600.webp" alt="Kurage">
    <div>
      <span class="eyebrow"><span class="dot"></span>KURAGE.AI</span>
      <h1><em>システム開発</em>のこと、<br>Kurageに何でも聞いてください</h1>
      <p>業務システム・料金・デモ・代理店制度・AI技術記事まで。困りごとを書けば、最適な商品とデモ・価格をご案内します。</p>
    </div>
  </div>
  <div id="log"></div>
  <p class="foot">回答は <a href="/wiki/" style="color:var(--teal-deep);font-weight:700">Kurage Wiki</a> のナレッジベースに基づきます（AI生成のため、最終確認は公式サイトをご覧ください）。</p>
</main>
<div class="bar"><div class="wrap">
  <textarea id="q" placeholder="質問を入力（例: サロンの予約管理を探しています）" onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();send()}"></textarea>
  <button id="sendBtn" onclick="send()">送信</button>
</div></div>
<script>
function esc(s){return s.replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))}
function md(t){return esc(t)
  .replace(/^###?\s*(.+)$/gm,'<h3>$1</h3>')
  .replace(/\*\*(.+?)\*\*/g,'<b>$1</b>')
  .replace(/\[\[[^\]]*\|?([^\]]*)\]\]/g,'')
  .replace(/(https?:\/\/[^\s<)]+)/g,'<a href="$1" target=_blank rel=noopener>$1</a>')
  .replace(/^[-•]\s*(.+)$/gm,'• $1').replace(/\n/g,'<br>');}
const log=document.getElementById('log'),btn=document.getElementById('sendBtn');
function add(cls,html){const d=document.createElement('div');d.className='msg '+cls;d.innerHTML=html;log.appendChild(d);d.scrollIntoView({block:'end'});return d;}
function ex(t){document.getElementById('q').value=t;send();}
let busy=false;
async function send(){
  if(busy)return;
  const el=document.getElementById('q'),q=el.value.trim();if(!q)return;el.value='';
  add('me',esc(q));const wait=add('ai','考え中…🪼');busy=true;btn.disabled=true;
  try{const r=await fetch('chat.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({question:q})});
    const j=await r.json();wait.innerHTML=md(j.answer||'（空）');}
  catch(e){wait.innerHTML='通信エラー: '+esc(String(e));}
  busy=false;btn.disabled=false;wait.scrollIntoView({block:'end'});
}
</script>
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','G-BP0650KDFR');</script>
<script>(function(){var s=document.createElement('script');s.src='https://kurage.exbridge.jp/simpletrack.php?url='+encodeURIComponent(location.href)+'&ref='+encodeURIComponent(document.referrer);document.head.appendChild(s)})();</script>
</body></html>
