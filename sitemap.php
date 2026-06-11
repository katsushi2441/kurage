<?php
header('Content-Type: application/xml; charset=utf-8');
date_default_timezone_set('Asia/Tokyo');

$base = 'https://kurage.exbridge.jp';
$urls = array(
    array('loc' => $base . '/', 'priority' => '1.0', 'changefreq' => 'weekly'),
    array('loc' => $base . '/kurage.php', 'priority' => '0.9', 'changefreq' => 'weekly'),
    array('loc' => $base . '/kuragev.php', 'priority' => '0.9', 'changefreq' => 'daily'),
    array('loc' => $base . '/horizon.php', 'priority' => '0.8', 'changefreq' => 'weekly'),
    array('loc' => $base . '/horizonv.php', 'priority' => '0.9', 'changefreq' => 'daily'),
    array('loc' => $base . '/entertainment.php', 'priority' => '0.85', 'changefreq' => 'hourly'),
    array('loc' => $base . '/kuragevp.php', 'priority' => '0.7', 'changefreq' => 'monthly'),
    array('loc' => $base . '/kdeck.php', 'priority' => '0.7', 'changefreq' => 'monthly'),
    array('loc' => $base . '/rqdb4ai.php', 'priority' => '0.7', 'changefreq' => 'monthly'),
);

function sitemap_fetch_json($url) {
    $ctx = stream_context_create(array(
        'http' => array(
            'timeout' => 8,
            'header' => "Accept: application/json\r\n",
        ),
    ));
    $json = @file_get_contents($url, false, $ctx);
    if (!$json) { return null; }
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

function add_job_urls(&$urls, $base) {
    $api = 'http://exbridge.ddns.net:18303/jobs?limit=500';
    $data = sitemap_fetch_json($api);
    if (empty($data['jobs']) || !is_array($data['jobs'])) { return; }
    $seen = array();
    foreach ($data['jobs'] as $job) {
        if (($job['status'] ?? '') !== 'done' || empty($job['job_id'])) { continue; }
        $source = (string)($job['source'] ?? '');
        $file = $source === 'horizon' ? 'horizonv.php' : 'kuragev.php';
        $loc = $base . '/' . $file . '?id=' . rawurlencode($job['job_id']);
        if (isset($seen[$loc])) { continue; }
        $seen[$loc] = true;
        $lastmod = '';
        foreach (array('updated_at', 'created_at') as $key) {
            if (!empty($job[$key])) {
                $ts = strtotime($job[$key]);
                if ($ts) { $lastmod = date('c', $ts); }
                break;
            }
        }
        $urls[] = array(
            'loc' => $loc,
            'priority' => $source === 'horizon' ? '0.75' : '0.7',
            'changefreq' => 'monthly',
            'lastmod' => $lastmod,
        );
    }
}

add_job_urls($urls, $base);

$entertainment_file = __DIR__ . '/data/entertainment_articles.json';
if (file_exists($entertainment_file)) {
    $entertainment = json_decode(file_get_contents($entertainment_file), true);
    if (is_array($entertainment)) {
        foreach (array_slice($entertainment, 0, 500) as $article) {
            if (empty($article['slug'])) { continue; }
            $lastmod = '';
            foreach (array('updated_at', 'created_at') as $key) {
                if (!empty($article[$key])) {
                    $ts = strtotime($article[$key]);
                    if ($ts) { $lastmod = date('c', $ts); }
                    break;
                }
            }
            $urls[] = array(
                'loc' => $base . '/entertainment.php?id=' . rawurlencode($article['slug']),
                'priority' => '0.72',
                'changefreq' => 'weekly',
                'lastmod' => $lastmod,
            );
        }
    }
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $u): ?>
  <url>
    <loc><?php echo htmlspecialchars($u['loc'], ENT_XML1, 'UTF-8'); ?></loc>
<?php if (!empty($u['lastmod'])): ?>
    <lastmod><?php echo htmlspecialchars($u['lastmod'], ENT_XML1, 'UTF-8'); ?></lastmod>
<?php endif; ?>
    <changefreq><?php echo htmlspecialchars($u['changefreq'], ENT_XML1, 'UTF-8'); ?></changefreq>
    <priority><?php echo htmlspecialchars($u['priority'], ENT_XML1, 'UTF-8'); ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
