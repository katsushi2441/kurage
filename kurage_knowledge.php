<?php
date_default_timezone_set('Asia/Tokyo');
$BASE_URL = 'https://kurage.exbridge.jp';
$DATA_FILE = __DIR__ . '/storage/knowledge/index.json';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function read_json_file($path) {
    if (!is_file($path)) { return null; }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : null;
}
function topic_url($slug) { return 'kurage_topic.php?slug=' . rawurlencode((string)$slug); }

$data = read_json_file($DATA_FILE);
$topics = is_array($data['topics'] ?? null) ? $data['topics'] : [];
$page_title = 'Kurage Knowledge Library — 動画から育つ知識ページ';
$page_desc = 'Kurage編集者がAIショート動画をテーマ別に分類し、複数の動画が伝える学びをまとめるナレッジライブラリです。';
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($page_title); ?></title>
<meta name="description" content="<?php echo h($page_desc); ?>">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?php echo h($BASE_URL); ?>/kurage_knowledge.php">
<meta property="og:type" content="website">
<meta property="og:title" content="<?php echo h($page_title); ?>">
<meta property="og:description" content="<?php echo h($page_desc); ?>">
<meta property="og:image" content="<?php echo h($BASE_URL); ?>/avatar/lipsync/kurage_mouth_0.png">
<meta property="og:url" content="<?php echo h($BASE_URL); ?>/kurage_knowledge.php">
<style>
:root{--ink:#17324d;--muted:#66839a;--sea:#55c7da;--line:#cbeef4;--accent:#2aa8c7;--accent2:#1e8fa8;--soft:#eef9fc;--paper:rgba(255,255,255,.92)}
*{box-sizing:border-box;margin:0;padding:0}
body{color:var(--ink);font-family:"Hiragino Sans","Yu Gothic",Meiryo,sans-serif;background:radial-gradient(1300px 500px at 15% -8%,rgba(85,199,218,.18),transparent 55%),radial-gradient(900px 400px at 92% 5%,rgba(146,230,250,.14),transparent 50%),linear-gradient(160deg,#fff 0%,#edfbff 50%,#f5fff9 100%);min-height:100vh}
a{text-decoration:none;color:inherit}
header{position:sticky;top:0;z-index:40;background:rgba(255,255,255,.86);backdrop-filter:blur(16px);border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:10px 24px;gap:12px}
.hbrand{display:flex;align-items:center;gap:10px;font-weight:900;font-size:16px}
.orb{width:32px;height:32px;border-radius:50%;background:radial-gradient(circle at 35% 30%,#cdf5fb,#62c8de 55%,#2aa8c7);box-shadow:0 4px 12px rgba(42,168,199,.3)}
.hbrand sub{font-size:11px;font-weight:700;color:var(--muted);display:block;margin-top:-2px}
.btn{border-radius:999px;padding:10px 18px;font-weight:900;font-size:13px;display:inline-flex;align-items:center;gap:7px}
.btn-primary{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;box-shadow:0 8px 20px rgba(42,168,199,.26)}
.btn-ghost{background:#fff;border:1.5px solid var(--line);color:var(--muted)}
.hero{max-width:1120px;margin:0 auto;padding:52px 24px 34px;display:grid;grid-template-columns:1.25fr .75fr;gap:34px;align-items:center}
.eyebrow{display:inline-flex;align-items:center;gap:8px;background:#fff;border:1.5px solid var(--line);border-radius:999px;padding:7px 14px;font-size:12px;font-weight:900;color:var(--accent);margin-bottom:20px}
.dot{width:7px;height:7px;border-radius:50%;background:var(--sea);animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.8)}}
h1{font-size:clamp(31px,4.6vw,56px);font-weight:900;line-height:1.12;letter-spacing:-.03em;margin-bottom:18px}
h1 em{font-style:normal;color:var(--accent)}
.lead{font-size:16px;line-height:1.9;color:#35536a;max-width:680px;margin-bottom:22px}
.stats{display:flex;gap:12px;flex-wrap:wrap}
.stat{background:#fff;border:1.5px solid var(--line);border-radius:16px;padding:12px 16px;box-shadow:0 10px 28px rgba(19,50,61,.05)}
.stat b{font-size:22px;color:var(--accent);display:block;line-height:1}.stat span{font-size:12px;color:var(--muted);font-weight:800}
.editor-card{background:var(--paper);border:1.5px solid var(--line);border-radius:28px;padding:24px;text-align:center;box-shadow:0 22px 70px rgba(42,168,199,.16)}
.editor-card img{width:150px;height:150px;object-fit:cover;border-radius:50%;border:5px solid #fff;box-shadow:0 16px 42px rgba(42,168,199,.18);margin-bottom:14px}
.editor-card h2{font-size:20px;margin-bottom:8px}.editor-card p{font-size:13.5px;color:#3f627a;line-height:1.75}
main{max-width:1120px;margin:0 auto;padding:10px 24px 70px}
.section-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin:18px 0 18px}
.section-eyebrow{font-size:12px;font-weight:900;letter-spacing:.1em;color:var(--accent);text-transform:uppercase;margin-bottom:6px}
.section-head h2{font-size:clamp(24px,3vw,34px);font-weight:900}
.updated{font-size:12px;color:var(--muted);font-weight:800}
.topics{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
.topic{background:var(--paper);border:1.5px solid var(--line);border-radius:24px;padding:22px;box-shadow:0 12px 36px rgba(19,50,61,.06);transition:transform .16s,box-shadow .16s}
.topic:hover{transform:translateY(-2px);box-shadow:0 18px 42px rgba(42,168,199,.14)}
.topic-top{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px}
.topic h3{font-size:20px;line-height:1.35;font-weight:900}
.badge{background:#e6f8fb;color:var(--accent2);border:1px solid #b8e8f1;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:900;white-space:nowrap}
.summary{font-size:13.5px;color:#3f627a;line-height:1.8;margin:12px 0 14px}
.chips{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px}
.chip{font-size:11px;font-weight:900;color:#526f83;background:#fff;border:1px solid #d7edf3;border-radius:999px;padding:4px 8px}
.featured{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px}
.mini img{width:100%;aspect-ratio:9/13;object-fit:cover;border-radius:12px;border:1px solid var(--line);display:block;background:#eaf8fb}
.mini span{display:block;font-size:11px;color:#536a7a;line-height:1.45;margin-top:5px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.empty{background:#fff;border:1.5px solid var(--line);border-radius:24px;padding:34px;line-height:1.8;color:#3f627a}
footer{border-top:1px solid var(--line);background:rgba(255,255,255,.72);padding:24px;color:var(--muted);font-size:13px;text-align:center}
@media(max-width:860px){header{padding:10px 16px}.hero{grid-template-columns:1fr;padding:34px 18px 24px}.topics{grid-template-columns:1fr}main{padding:10px 18px 56px}.featured{grid-template-columns:repeat(3,1fr)}.section-head{align-items:flex-start;flex-direction:column}.btn-ghost{display:none}}
</style>
</head>
<body>
<header>
  <a class="hbrand" href="https://kurage.exbridge.jp/">
    <span class="orb"></span>
    <span>Kurage<sub>Knowledge Library</sub></span>
  </a>
  <div style="display:flex;gap:10px">
    <a class="btn btn-ghost" href="kuragev.php">動画一覧</a>
    <a class="btn btn-primary" href="#topics">テーマを見る</a>
  </div>
</header>

<section class="hero">
  <div>
    <div class="eyebrow"><span class="dot"></span>動画から育つ知識ライブラリ</div>
    <h1>Kurage動画を<br><em>テーマ別の知識</em>へ</h1>
    <p class="lead">
      時系列に流れていく動画を、Kurage編集者がテーマごとに整理します。
      複数の動画が伝えている学び、実装の流れ、見るべき順番をまとめ、
      Kurageの動画アーカイブを知識の宝庫として育てていきます。
    </p>
    <div class="stats">
      <div class="stat"><b><?php echo h((int)($data['video_count'] ?? 0)); ?></b><span>整理対象動画</span></div>
      <div class="stat"><b><?php echo h((int)($data['topic_count'] ?? count($topics))); ?></b><span>テーマ</span></div>
      <div class="stat"><b>AI</b><span>Kurage編集者</span></div>
    </div>
  </div>
  <div class="editor-card">
    <img src="avatar/lipsync/kurage_mouth_0.png" alt="Kurage editor">
    <h2>Kurageが編集します</h2>
    <p>新しい動画が増えるたびに、テーマ分類と要約を更新。動画単体では見えにくい知識のつながりを案内します。</p>
  </div>
</section>

<main id="topics">
  <div class="section-head">
    <div>
      <div class="section-eyebrow">Topics</div>
      <h2>育っているテーマ</h2>
    </div>
    <div class="updated">更新: <?php echo h($data['updated_at'] ?? '未生成'); ?></div>
  </div>

  <?php if (!$data || !$topics): ?>
    <div class="empty">
      まだナレッジデータが生成されていません。<br>
      `scripts/build-kurage-knowledge.py` を実行すると、動画からテーマ別ページが生成されます。
    </div>
  <?php else: ?>
    <div class="topics">
      <?php foreach ($topics as $topic): ?>
      <a class="topic" href="<?php echo h(topic_url($topic['slug'] ?? '')); ?>">
        <div class="topic-top">
          <h3><?php echo h($topic['title'] ?? 'テーマ'); ?></h3>
          <span class="badge"><?php echo h((int)($topic['video_count'] ?? 0)); ?> videos</span>
        </div>
        <p class="summary"><?php echo h($topic['lead'] ?? $topic['editor_summary'] ?? ''); ?></p>
        <div class="chips">
          <?php foreach (array_slice((array)($topic['keywords'] ?? []), 0, 5) as $kw): ?>
            <span class="chip"><?php echo h($kw); ?></span>
          <?php endforeach; ?>
        </div>
        <div class="featured">
          <?php foreach (array_slice((array)($topic['featured_videos'] ?? []), 0, 3) as $video): ?>
          <div class="mini">
            <img src="<?php echo h($video['thumbnail_url'] ?? ''); ?>" alt="">
            <span><?php echo h($video['title'] ?? 'Kurage動画'); ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>

<footer>Kurage Knowledge Library / 株式会社エクスブリッジ</footer>
</body>
</html>
