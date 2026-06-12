<?php
date_default_timezone_set('Asia/Tokyo');

$BASE_URL = 'https://kurage.exbridge.jp';
$KURAGE_API = 'http://exbridge.ddns.net:18303';
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

function fetch_kurage_job($api_base, $job_id) {
    $job_id = preg_replace('/[^a-zA-Z0-9]/', '', (string)$job_id);
    if ($job_id === '') return null;
    $ch = curl_init(rtrim($api_base, '/') . '/status/' . $job_id);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300 || !$body) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
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

$detail_video_job = null;
if ($detail && !empty($detail['video_job_id'])) {
    $candidate_job = fetch_kurage_job($KURAGE_API, $detail['video_job_id']);
    if ($candidate_job && (($candidate_job['status'] ?? '') === 'done')) {
        $detail_video_job = $candidate_job;
    }
}

$page_title = $detail ? (($detail['title'] ?? '芸能ニュース考察') . ' | Kurage Entertainment') : 'Kurage Entertainment | 芸能・有名人ニュース考察';
$page_desc = $detail ? short_text($detail['summary'] ?? $detail['source_title'] ?? '', 150) : '芸能人・有名人のニュースを、背景や作品情報とあわせて読み解く考察ページです。';
$canonical = $BASE_URL . '/entertainment.php' . ($detail ? ('?id=' . rawurlencode($detail['slug'])) : '');
$page_image = $detail_video_job
    ? ($BASE_URL . '/kuragev.php?proxy=thumbnail&job_id=' . rawurlencode($detail['video_job_id']) . '&v=' . rawurlencode($detail_video_job['updated_at'] ?? $detail_video_job['created_at'] ?? '1'))
    : ($BASE_URL . '/images/kurage.png');
$header_amazon_url = $detail && !empty($detail['amazon_url']) ? $detail['amazon_url'] : ('/go.php?' . http_build_query(array(
    'to' => 'amazon',
    'kw' => 'AI ビジネス書 動画編集 CD DVD',
    'cat' => 'books',
    'from' => '/entertainment.php' . ($detail ? ('?id=' . ($detail['slug'] ?? '')) : ''),
)));

if (isset($_GET['feed'])) {
    header('Content-Type: application/rss+xml; charset=UTF-8');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<rss version=\"2.0\"><channel>\n";
    echo "<title>Kurage Entertainment</title>\n";
    echo "<link>" . h($BASE_URL . '/entertainment.php') . "</link>\n";
    echo "<description>芸能・有名人ニュース考察</description>\n";
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
<meta property="og:image" content="<?php echo h($page_image); ?>">
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
a{color:inherit}.top{position:sticky;top:0;z-index:5;background:rgba(255,255,255,.92);backdrop-filter:blur(12px);border-bottom:1px solid var(--line)}.bar{max-width:1120px;margin:0 auto;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px}.brand{display:flex;align-items:center;gap:10px;text-decoration:none;font-weight:900}.brand img{width:38px;height:38px;border-radius:50%}.nav{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.nav a{font-size:12px;text-decoration:none;border:1px solid var(--line);background:#fff;border-radius:999px;padding:7px 10px;font-weight:800;color:#36515c}.amazon-mini{width:30px;height:30px;padding:0!important;border-radius:9px!important;background:linear-gradient(135deg,#ffb84d,#ff9900)!important;border-color:#e58a00!important;color:#1f2933!important;display:inline-flex!important;align-items:center;justify-content:center;font-weight:1000!important;font-size:18px!important;font-family:Georgia,serif;box-shadow:0 8px 18px rgba(255,153,0,.28);transform:rotate(-2deg)}.amazon-mini:hover{transform:rotate(2deg) translateY(-1px);box-shadow:0 10px 22px rgba(255,153,0,.38)}
.hero{max-width:1120px;margin:0 auto;padding:42px 16px 24px}.eyebrow{display:inline-flex;border:1px solid #f2d39a;background:#fff7e6;color:#9a5b00;border-radius:999px;padding:6px 12px;font-weight:900;font-size:12px}.hero h1{font-size:40px;line-height:1.18;letter-spacing:-.04em;margin:14px 0 10px}.hero p{max-width:780px;color:var(--muted);font-size:16px;margin:0}.wrap{max-width:1120px;margin:0 auto;padding:8px 16px 48px}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.card,.article{background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 14px 34px rgba(30,84,96,.08)}.card{display:block;text-decoration:none;padding:18px}.card h2{font-size:21px;line-height:1.45;margin:8px 0}.meta{color:var(--muted);font-size:12px}.pill{display:inline-flex;border:1px solid #f2d39a;background:#fff8e8;color:#9a5b00;border-radius:999px;padding:4px 9px;font-size:11px;font-weight:900}.card p{color:#526b76;margin:0}.layout{display:block}.article{padding:24px}.article h1{font-size:34px;line-height:1.25;letter-spacing:-.03em;margin:10px 0}.article p{font-size:15px}.source{border-left:4px solid var(--brand);background:#f4fbfd;border-radius:0 10px 10px 0;padding:12px 14px;color:#526b76}.cta{display:grid;gap:10px;margin:18px 0}.btn{display:inline-flex;align-items:center;justify-content:center;text-align:center;border-radius:999px;padding:10px 14px;text-decoration:none;font-weight:900;border:1px solid var(--line);background:#fff}.btn.amazon{background:var(--gold);border-color:#e58a00;color:#1f2933}.btn.kurage{background:var(--brand);border-color:var(--brand);color:#fff}.script-list{padding-left:1.2em;margin:8px 0}.script-list li{margin:6px 0}.disclosure{max-width:1120px;margin:24px auto 18px;padding:12px 16px;color:#647884;font-size:12px;text-align:center}.footer-links{margin-top:8px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap}.footer-links a{font-weight:800}@media(max-width:760px){.bar{align-items:flex-start;flex-direction:column}.hero h1{font-size:30px}.grid{grid-template-columns:1fr}.article{padding:18px}}
.embedded-video{margin:18px 0 20px;border:1px solid var(--line);border-radius:16px;overflow:hidden;background:#0c1c22;box-shadow:0 14px 34px rgba(30,84,96,.12)}.embedded-video video{display:block;width:100%;max-height:70vh;background:#000}.video-caption{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;background:#fff;padding:10px 12px;color:#526b76;font-size:13px}.video-caption a{font-weight:900;color:var(--brand)}
</style>
</head>
<body>
<header class="top"><div class="bar">
  <a class="brand" href="/"><img src="/images/kurage-icon.png" alt="Kurage"><span>Kurage Entertainment</span></a>
  <nav class="nav">
    <a href="https://kurage.exbridge.jp/">kurage.exbridge.jp</a><a href="https://exbridge.jp/">exbridge.jp</a><a href="/entertainment.php">芸能ニュース</a><a href="/kuragev.php">動画一覧</a>
    <?php if ($is_admin): ?><a href="/horizon.php">動画生成</a><a href="/simpletrack.php?dashboard=1">Analytics</a><?php endif; ?>
    <a class="amazon-mini" href="<?php echo h($header_amazon_url); ?>" target="_blank" rel="sponsored nofollow noopener" aria-label="Amazonで関連商品を見る" title="Amazonで関連商品を見る">a</a>
  </nav>
</div></header>

<?php if ($detail): ?>
<main class="wrap" style="padding-top:26px">
  <div class="layout">
    <article class="article">
      <span class="pill">芸能・有名人ニュース考察</span>
      <h1><?php echo h($detail['title'] ?? '芸能ニュース考察'); ?></h1>
      <div class="meta"><?php echo h($detail['created_at'] ?? ''); ?> / <?php echo h(implode('、', $detail['celebrity_names'] ?? array())); ?></div>
      <?php if ($detail_video_job): ?>
      <div class="embedded-video">
        <video src="/kuragev.php?proxy=video&job_id=<?php echo h($detail['video_job_id']); ?>"
               poster="/kuragev.php?proxy=thumbnail&job_id=<?php echo h($detail['video_job_id']); ?>&v=<?php echo h($detail_video_job['updated_at'] ?? $detail_video_job['created_at'] ?? '1'); ?>"
               controls playsinline preload="metadata"></video>
        <div class="video-caption">
          <span>この記事から生成した30秒動画です。</span>
          <a href="/kuragev.php?id=<?php echo h($detail['video_job_id']); ?>">動画詳細を開く</a>
        </div>
      </div>
      <?php endif; ?>
      <p><?php echo h($detail['summary'] ?? ''); ?></p>
      <?php foreach (($detail['body'] ?? array()) as $p): ?><p><?php echo h($p); ?></p><?php endforeach; ?>
      <div class="cta">
        <a class="btn amazon" href="<?php echo h($detail['amazon_url'] ?? '#'); ?>" target="_blank" rel="sponsored nofollow noopener">関連する本・作品をAmazonで見る</a>
      </div>
      <p class="source"><strong>参考にした元コンテンツ:</strong> <?php echo h($detail['source_title'] ?? ''); ?><br>
        <a href="<?php echo h($detail['source_url'] ?? '#'); ?>" target="_blank" rel="nofollow noopener">元ニュース・元動画を確認する</a>
      </p>
      <p class="meta"><?php echo h($detail['safety_note'] ?? ''); ?></p>
    </article>
  </div>
</main>
<?php else: ?>
<section class="hero">
  <span class="eyebrow">Entertainment Notes</span>
  <h1>芸能人・有名人ニュースを、背景まで読み解く</h1>
  <p>話題になった発言、作品、ニュースをそのまま流さず、なぜ注目されたのか、どんな作品や資料とあわせて見ると理解しやすいのかを整理します。</p>
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
<footer class="disclosure">Amazonアソシエイトとして適格販売により収入を得ています。Amazonリンクは関連作品・関連資料の参考リンクであり、芸能人本人の推奨を示すものではありません。<div class="footer-links"><a href="https://kurage.exbridge.jp/">kurage.exbridge.jp</a><a href="https://exbridge.jp/">exbridge.jp</a></div></footer>
</body>
</html>
