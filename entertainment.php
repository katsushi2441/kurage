<?php
date_default_timezone_set('Asia/Tokyo');

$BASE_URL = 'https://kurage.exbridge.jp';
$DATA_FILE = __DIR__ . '/data/entertainment_articles.json';
$logged_in = false;
$session_user = '';
$is_admin = false;
if (file_exists(__DIR__ . '/auth_common.php')) {
    require_once __DIR__ . '/auth_common.php';
    if (function_exists('url2ai_auth_bootstrap')) {
        $auth = url2ai_auth_bootstrap();
        $logged_in = !empty($auth['logged_in']);
        $session_user = (string)($auth['session_user'] ?? '');
        $is_admin = !empty($auth['is_admin']);
    }
    if (isset($_GET['login']) && function_exists('url2ai_auth_login_url')) {
        header('Location: ' . url2ai_auth_login_url('/entertainment.php'));
        exit;
    }
    if (isset($_GET['logout']) && function_exists('url2ai_auth_logout_url')) {
        header('Location: ' . url2ai_auth_logout_url('/entertainment.php'));
        exit;
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function load_entertainment_articles($path) {
    if (!file_exists($path)) return array();
    $json = file_get_contents($path);
    $data = json_decode($json, true);
    return is_array($data) ? $data : array();
}

function short_text($s, $limit = 120) {
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$s)));
    return mb_strlen($s, 'UTF-8') > $limit ? mb_substr($s, 0, $limit, 'UTF-8') . '…' : $s;
}

$articles = load_entertainment_articles($DATA_FILE);
$slug = isset($_GET['id']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$_GET['id']) : '';
$detail = null;
foreach ($articles as $article) {
    if (($article['slug'] ?? '') === $slug) {
        $detail = $article;
        break;
    }
}

$page_title = $detail ? (($detail['title'] ?? '芸能ニュース考察') . ' | Kurage Entertainment') : 'Kurage Entertainment | 芸能ニュースAI記事とショート動画';
$page_desc = $detail ? short_text($detail['summary'] ?? $detail['source_title'] ?? '', 150) : '芸能ニュースを安全なSEO記事、Amazon関連導線、Kurageショート動画へつなげるKurage Entertainment。';
$canonical = $BASE_URL . '/entertainment.php' . ($detail ? ('?id=' . rawurlencode($detail['slug'])) : '');

if (isset($_GET['feed'])) {
    header('Content-Type: application/rss+xml; charset=UTF-8');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<rss version=\"2.0\"><channel>\n";
    echo "<title>Kurage Entertainment</title>\n";
    echo "<link>" . h($BASE_URL . '/entertainment.php') . "</link>\n";
    echo "<description>芸能ニュースAI記事とKurageショート動画</description>\n";
    foreach (array_slice($articles, 0, 30) as $a) {
        $url = $BASE_URL . '/entertainment.php?id=' . rawurlencode($a['slug'] ?? '');
        echo "<item><title>" . h($a['title'] ?? '') . "</title><link>" . h($url) . "</link><guid>" . h($url) . "</guid>";
        if (!empty($a['created_at']) && ($ts = strtotime($a['created_at']))) echo "<pubDate>" . date(DATE_RSS, $ts) . "</pubDate>";
        echo "<description>" . h($a['summary'] ?? '') . "</description></item>\n";
    }
    echo "</channel></rss>\n";
    exit;
}
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo h($page_title); ?></title>
<meta name="description" content="<?php echo h($page_desc); ?>">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?php echo h($canonical); ?>">
<link rel="alternate" type="application/rss+xml" title="Kurage Entertainment RSS" href="<?php echo h($BASE_URL); ?>/entertainment.php?feed=1">
<meta property="og:type" content="<?php echo $detail ? 'article' : 'website'; ?>">
<meta property="og:title" content="<?php echo h($page_title); ?>">
<meta property="og:description" content="<?php echo h($page_desc); ?>">
<meta property="og:url" content="<?php echo h($canonical); ?>">
<meta property="og:image" content="<?php echo h($BASE_URL); ?>/images/kurage.png">
<meta name="twitter:card" content="summary_large_image">
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-BP0650KDFR');</script>
<script>
(function(){
  var s=document.createElement('script');
  s.src='https://kurage.exbridge.jp/simpletrack.php?url='+encodeURIComponent(location.href)+'&ref='+encodeURIComponent(document.referrer);
  document.head.appendChild(s);
})();
</script>
<?php
$schema = $detail ? array(
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => $detail['title'] ?? $page_title,
    'description' => $page_desc,
    'url' => $canonical,
    'datePublished' => $detail['created_at'] ?? '',
    'dateModified' => $detail['updated_at'] ?? $detail['created_at'] ?? '',
    'author' => array('@type' => 'Organization', 'name' => 'Kurage Entertainment'),
    'publisher' => array('@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/'),
) : array(
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => 'Kurage Entertainment',
    'description' => $page_desc,
    'url' => $canonical,
);
?>
<script type="application/ld+json"><?php echo json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
<style>
:root{--ink:#18262e;--muted:#637985;--line:#d8e7eb;--brand:#007f96;--gold:#ff9900;--soft:#f6fbfc}
*{box-sizing:border-box}body{margin:0;background:linear-gradient(180deg,#fffaf0 0%,#f4fbfd 34%,#fff 100%);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;line-height:1.75}
a{color:inherit}.top{position:sticky;top:0;z-index:5;background:rgba(255,255,255,.92);backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}.bar{max-width:1120px;margin:0 auto;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px}.brand{display:flex;align-items:center;gap:10px;text-decoration:none;font-weight:900}.brand img{width:38px;height:38px;border-radius:50%}.nav{display:flex;gap:8px;flex-wrap:wrap}.nav a{font-size:12px;text-decoration:none;border:1px solid var(--line);background:#fff;border-radius:999px;padding:7px 10px;font-weight:800;color:#36515c}
.hero{max-width:1120px;margin:0 auto;padding:42px 16px 24px}.eyebrow{display:inline-flex;border:1px solid #f2d39a;background:#fff7e6;color:#9a5b00;border-radius:999px;padding:6px 12px;font-weight:900;font-size:12px}.hero h1{font-size:40px;line-height:1.18;letter-spacing:-.04em;margin:14px 0 10px}.hero p{max-width:780px;color:var(--muted);font-size:16px;margin:0}.wrap{max-width:1120px;margin:0 auto;padding:8px 16px 48px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.card,.article,.sidebox{background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 14px 34px rgba(30,84,96,.08)}.card{display:block;text-decoration:none;padding:18px}.card h2{font-size:21px;line-height:1.45;margin:8px 0}.meta{color:var(--muted);font-size:12px}.pill{display:inline-flex;border:1px solid #f2d39a;background:#fff8e8;color:#9a5b00;border-radius:999px;padding:4px 9px;font-size:11px;font-weight:900}.card p{color:#526b76;margin:0}.layout{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:16px;align-items:start}.article{padding:24px}.article h1{font-size:34px;line-height:1.25;letter-spacing:-.03em;margin:10px 0}.article p{font-size:15px}.source{border-left:4px solid var(--brand);background:#f4fbfd;border-radius:0 10px 10px 0;padding:12px 14px;color:#526b76}.cta{display:grid;gap:10px;margin:18px 0}.btn{display:inline-flex;align-items:center;justify-content:center;text-align:center;border-radius:999px;padding:10px 14px;text-decoration:none;font-weight:900;border:1px solid var(--line);background:#fff}.btn.amazon{background:var(--gold);border-color:#e58a00;color:#1f2933}.btn.kurage{background:var(--brand);border-color:var(--brand);color:#fff}.sidebox{padding:16px;margin-bottom:14px}.sidebox h3{margin:0 0 8px;font-size:16px}.script-list{padding-left:1.2em;margin:8px 0}.script-list li{margin:6px 0}.disclosure{max-width:1120px;margin:24px auto 18px;padding:12px 16px;color:#647884;font-size:12px;text-align:center}@media(max-width:760px){.bar{align-items:flex-start;flex-direction:column}.hero h1{font-size:30px}.grid,.layout{grid-template-columns:1fr}.article{padding:18px}}
</style>
</head>
<body>
<header class="top"><div class="bar">
  <a class="brand" href="/"><img src="/images/kurage-icon.png" alt="Kurage"><span>Kurage Entertainment</span></a>
  <nav class="nav">
    <a href="/">Kurage</a><a href="/entertainment.php">芸能ニュース</a><a href="/kuragev.php">動画一覧</a>
    <?php if ($is_admin): ?><a href="/horizon.php">動画生成</a><a href="/simpletrack.php?dashboard=1">Analytics</a><?php endif; ?>
  </nav>
</div></header>

<?php if ($detail): ?>
<main class="wrap" style="padding-top:26px">
  <div class="layout">
    <article class="article">
      <span class="pill">Entertainment SEO x Kurage</span>
      <h1><?php echo h($detail['title'] ?? '芸能ニュース考察'); ?></h1>
      <div class="meta"><?php echo h($detail['created_at'] ?? ''); ?> / <?php echo h(implode('、', $detail['celebrity_names'] ?? array())); ?></div>
      <p><?php echo h($detail['summary'] ?? ''); ?></p>
      <?php foreach (($detail['body'] ?? array()) as $p): ?><p><?php echo h($p); ?></p><?php endforeach; ?>
      <div class="cta">
        <a class="btn amazon" href="<?php echo h($detail['amazon_url'] ?? '#'); ?>" target="_blank" rel="sponsored nofollow noopener">Amazonで関連作品・資料を見る</a>
        <a class="btn" href="/kuragev.php">Kurageの公開動画を見る</a>
        <?php if (!empty($detail['video_job_id'])): ?><a class="btn kurage" href="/kuragev.php?id=<?php echo h($detail['video_job_id']); ?>">この話題の30秒動画を見る</a><?php endif; ?>
        <?php if ($is_admin): ?><a class="btn kurage" href="/kurage.php">管理者: AIショート動画を作る</a><?php endif; ?>
      </div>
      <p class="source"><strong>参考にした元コンテンツ:</strong> <?php echo h($detail['source_title'] ?? ''); ?><br>
        <a href="<?php echo h($detail['source_url'] ?? '#'); ?>" target="_blank" rel="nofollow noopener">元ニュース・元動画を確認する</a>
      </p>
      <p class="meta"><?php echo h($detail['safety_note'] ?? ''); ?></p>
    </article>
    <aside>
      <div class="sidebox">
        <h3>30秒Kurage動画台本</h3>
        <ol class="script-list">
          <?php foreach (($detail['video_script_30s'] ?? array()) as $line): ?><li><?php echo h($line); ?></li><?php endforeach; ?>
        </ol>
        <?php if (!empty($detail['video_job_id'])): ?>
          <a class="btn kurage" href="/kuragev.php?id=<?php echo h($detail['video_job_id']); ?>">生成済み動画を見る</a>
        <?php elseif ($is_admin): ?>
          <a class="btn kurage" href="/horizon.php">管理者: Kurage Blogで動画化する</a>
        <?php endif; ?>
      </div>
      <div class="sidebox">
        <h3>Kurageへの回遊</h3>
        <p>芸能ニュースで集まった検索流入を、公開動画、Kurage Project、KDeck、RQDB4AIの認知へつなげます。</p>
        <a class="btn" href="/">Kurage Projectを見る</a>
      </div>
    </aside>
  </div>
</main>
<?php else: ?>
<section class="hero">
  <span class="eyebrow">Entertainment SEO Loop</span>
  <h1>芸能ニュースをKurageのアクセスと知名度につなげる</h1>
  <p>芸能ニュースの検索流入を、安全な記事、Amazon関連導線、30秒Kurageショート動画、Kurage本体への回遊に変換する実験ページです。</p>
</section>
<main class="wrap">
  <div class="grid">
    <?php if (empty($articles)): ?>
      <div class="card"><h2>記事はまだありません</h2><p>収集ジョブが記事を生成するとここに表示されます。</p></div>
    <?php else: ?>
      <?php foreach ($articles as $a): ?>
      <a class="card" href="/entertainment.php?id=<?php echo h($a['slug'] ?? ''); ?>">
        <span class="pill"><?php echo h(implode('、', $a['celebrity_names'] ?? array()) ?: '芸能ニュース'); ?></span>
        <h2><?php echo h($a['title'] ?? ''); ?></h2>
        <div class="meta"><?php echo h($a['created_at'] ?? ''); ?> / <?php echo h($a['source_name'] ?? ''); ?></div>
        <p><?php echo h(short_text($a['summary'] ?? '', 130)); ?></p>
      </a>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</main>
<?php endif; ?>
<footer class="disclosure">Amazonアソシエイトとして適格販売により収入を得ています。Amazonリンクは関連作品・関連資料の検索導線であり、芸能人本人の推奨を示すものではありません。</footer>
</body>
</html>
