<?php
date_default_timezone_set('Asia/Tokyo');
$BASE_URL = 'https://kurage.exbridge.jp';
$slug = isset($_GET['slug']) ? preg_replace('/[^a-z0-9\-_]/i', '', (string)$_GET['slug']) : '';
$DATA_FILE = __DIR__ . '/storage/knowledge/topics/' . $slug . '.json';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function read_json_file($path) {
    if (!is_file($path)) { return null; }
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : null;
}

$topic = $slug ? read_json_file($DATA_FILE) : null;
$title = $topic['title'] ?? 'Kurage Knowledge Topic';
$desc = $topic['lead'] ?? 'Kurage動画をテーマ別に整理したナレッジページです。';
$videos = is_array($topic['videos'] ?? null) ? $topic['videos'] : [];
$featured = is_array($topic['featured_videos'] ?? null) ? $topic['featured_videos'] : array_slice($videos, 0, 6);
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($title); ?> | Kurage Knowledge Library</title>
<meta name="description" content="<?php echo h($desc); ?>">
<meta name="robots" content="<?php echo $topic ? 'index, follow' : 'noindex, follow'; ?>">
<link rel="canonical" href="<?php echo h($BASE_URL); ?>/kurage_topic.php?slug=<?php echo h($slug); ?>">
<meta property="og:type" content="article">
<meta property="og:title" content="<?php echo h($title); ?>">
<meta property="og:description" content="<?php echo h($desc); ?>">
<meta property="og:image" content="<?php echo h($BASE_URL); ?>/avatar/lipsync/kurage_mouth_0.png">
<style>
:root{--ink:#17324d;--muted:#66839a;--sea:#55c7da;--line:#cbeef4;--accent:#2aa8c7;--accent2:#1e8fa8;--soft:#eef9fc;--paper:rgba(255,255,255,.92)}
*{box-sizing:border-box;margin:0;padding:0}
body{color:var(--ink);font-family:"Hiragino Sans","Yu Gothic",Meiryo,sans-serif;background:radial-gradient(1300px 500px at 15% -8%,rgba(85,199,218,.18),transparent 55%),radial-gradient(900px 400px at 92% 5%,rgba(146,230,250,.14),transparent 50%),linear-gradient(160deg,#fff 0%,#edfbff 50%,#f5fff9 100%);min-height:100vh}
a{text-decoration:none;color:inherit}
header{position:sticky;top:0;z-index:40;background:rgba(255,255,255,.86);backdrop-filter:blur(16px);border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:10px 24px;gap:12px}
.hbrand{display:flex;align-items:center;gap:10px;font-weight:900;font-size:16px}.orb{width:32px;height:32px;border-radius:50%;background:radial-gradient(circle at 35% 30%,#cdf5fb,#62c8de 55%,#2aa8c7);box-shadow:0 4px 12px rgba(42,168,199,.3)}.hbrand sub{font-size:11px;font-weight:700;color:var(--muted);display:block;margin-top:-2px}
.btn{border-radius:999px;padding:10px 18px;font-weight:900;font-size:13px;display:inline-flex;align-items:center;gap:7px}.btn-primary{background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;box-shadow:0 8px 20px rgba(42,168,199,.26)}.btn-ghost{background:#fff;border:1.5px solid var(--line);color:var(--muted)}
.hero{max-width:1120px;margin:0 auto;padding:44px 24px 26px;display:grid;grid-template-columns:1.15fr .85fr;gap:26px;align-items:start}
.eyebrow{display:inline-flex;align-items:center;gap:8px;background:#fff;border:1.5px solid var(--line);border-radius:999px;padding:7px 14px;font-size:12px;font-weight:900;color:var(--accent);margin-bottom:18px}
h1{font-size:clamp(30px,4.4vw,52px);font-weight:900;line-height:1.14;letter-spacing:-.03em;margin-bottom:16px}
.lead{font-size:16px;line-height:1.9;color:#35536a;margin-bottom:20px}
.summary-card,.side-card,.video-card{background:var(--paper);border:1.5px solid var(--line);border-radius:24px;box-shadow:0 12px 36px rgba(19,50,61,.06)}
.summary-card{padding:22px}.summary-card h2,.side-card h2{font-size:18px;margin-bottom:10px}.summary-card p{font-size:14px;color:#3f627a;line-height:1.85}
.side-card{padding:22px}.editor{display:flex;align-items:center;gap:14px;margin-bottom:16px}.editor img{width:64px;height:64px;object-fit:cover;border-radius:50%;border:4px solid #fff;box-shadow:0 10px 28px rgba(42,168,199,.18)}.editor b{display:block;font-size:16px}.editor span{font-size:12px;color:var(--muted);font-weight:800}
.stats{display:grid;grid-template-columns:1fr 1fr;gap:10px}.stat{background:#fff;border:1px solid #d7edf3;border-radius:16px;padding:12px}.stat b{display:block;font-size:22px;color:var(--accent);line-height:1}.stat span{font-size:12px;color:var(--muted);font-weight:800}
.chips{display:flex;gap:7px;flex-wrap:wrap;margin-top:14px}.chip{font-size:11px;font-weight:900;color:#526f83;background:#fff;border:1px solid #d7edf3;border-radius:999px;padding:4px 8px}
main{max-width:1120px;margin:0 auto;padding:8px 24px 70px}.section-title{font-size:clamp(22px,3vw,32px);font-weight:900;margin:28px 0 16px}
.featured{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.videos{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.video-card{display:grid;grid-template-columns:96px 1fr;gap:14px;padding:14px;transition:transform .16s,box-shadow .16s}.video-card:hover{transform:translateY(-2px);box-shadow:0 18px 42px rgba(42,168,199,.14)}
.video-card img{width:96px;aspect-ratio:9/16;object-fit:cover;border-radius:14px;border:1px solid var(--line);background:#eaf8fb}
.video-card h3{font-size:15px;line-height:1.45;margin-bottom:7px}.video-card p{font-size:12.5px;color:#3f627a;line-height:1.65;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}.meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:9px}.meta span{font-size:11px;font-weight:900;color:var(--accent2);background:#e6f8fb;border:1px solid #b8e8f1;border-radius:999px;padding:4px 8px}
.empty{max-width:800px;margin:50px auto;background:#fff;border:1.5px solid var(--line);border-radius:24px;padding:34px;line-height:1.8;color:#3f627a}
footer{border-top:1px solid var(--line);background:rgba(255,255,255,.72);padding:24px;color:var(--muted);font-size:13px;text-align:center}
@media(max-width:900px){header{padding:10px 16px}.hero{grid-template-columns:1fr;padding:32px 18px 18px}main{padding:8px 18px 56px}.featured,.videos{grid-template-columns:1fr}.btn-ghost{display:none}}
</style>
</head>
<body>
<header>
  <a class="hbrand" href="kurage_knowledge.php">
    <span class="orb"></span>
    <span>Kurage<sub>Knowledge Library</sub></span>
  </a>
  <div style="display:flex;gap:10px">
    <a class="btn btn-ghost" href="kuragev.php">動画一覧</a>
    <a class="btn btn-primary" href="kurage_knowledge.php">テーマ一覧</a>
  </div>
</header>

<?php if (!$topic): ?>
<div class="empty">
  テーマが見つかりませんでした。<br>
  <a href="kurage_knowledge.php" style="color:#1e8fa8;font-weight:900">テーマ一覧へ戻る</a>
</div>
<?php else: ?>
<section class="hero">
  <div>
    <div class="eyebrow">Kurage編集テーマ</div>
    <h1><?php echo h($title); ?></h1>
    <p class="lead"><?php echo h($desc); ?></p>
    <div class="summary-card">
      <h2>Kurage編集者の要約</h2>
      <p><?php echo h($topic['editor_summary'] ?? ''); ?></p>
      <div class="chips">
        <?php foreach (array_slice((array)($topic['keywords'] ?? []), 0, 10) as $kw): ?>
          <span class="chip"><?php echo h($kw); ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <aside class="side-card">
    <div class="editor">
      <img src="avatar/lipsync/kurage_mouth_0.png" alt="Kurage editor">
      <div><b>Kurage編集者</b><span>動画を知識に整理中</span></div>
    </div>
    <div class="stats">
      <div class="stat"><b><?php echo h((int)($topic['video_count'] ?? count($videos))); ?></b><span>動画</span></div>
      <div class="stat"><b><?php echo h((int)($topic['total_views'] ?? 0)); ?></b><span>表示回数</span></div>
    </div>
    <div class="chips">
      <span class="chip">更新 <?php echo h($topic['updated_at'] ?? ''); ?></span>
    </div>
  </aside>
</section>

<main>
  <h2 class="section-title">まず見る代表動画</h2>
  <div class="featured">
    <?php foreach (array_slice($featured, 0, 6) as $video): ?>
    <a class="video-card" href="<?php echo h($video['page_url'] ?? '#'); ?>">
      <img src="<?php echo h($video['thumbnail_url'] ?? ''); ?>" alt="">
      <div>
        <h3><?php echo h($video['title'] ?? 'Kurage動画'); ?></h3>
        <p><?php echo h($video['excerpt'] ?? ''); ?></p>
        <div class="meta"><span><?php echo h((int)($video['views'] ?? 0)); ?> views</span></div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>

  <h2 class="section-title">関連動画</h2>
  <div class="videos">
    <?php foreach ($videos as $video): ?>
    <a class="video-card" href="<?php echo h($video['page_url'] ?? '#'); ?>">
      <img src="<?php echo h($video['thumbnail_url'] ?? ''); ?>" alt="">
      <div>
        <h3><?php echo h($video['title'] ?? 'Kurage動画'); ?></h3>
        <p><?php echo h($video['excerpt'] ?? ''); ?></p>
        <div class="meta">
          <span><?php echo h($video['source'] ?? 'kurage'); ?></span>
          <span><?php echo h((int)($video['views'] ?? 0)); ?> views</span>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</main>
<?php endif; ?>

<footer>Kurage Knowledge Library / 株式会社エクスブリッジ</footer>
</body>
</html>
