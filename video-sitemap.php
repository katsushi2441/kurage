<?php
header('Content-Type: application/xml; charset=utf-8');
date_default_timezone_set('Asia/Tokyo');

$base = 'https://kurage.exbridge.jp';
$api = 'http://exbridge.ddns.net:18303/jobs?limit=500';

function x($value) {
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function fetch_json($url) {
    $ctx = stream_context_create(array(
        'http' => array(
            'timeout' => 10,
            'header' => "Accept: application/json\r\n",
        ),
    ));
    $body = @file_get_contents($url, false, $ctx);
    if (!$body) { return array(); }
    $data = json_decode($body, true);
    return is_array($data) ? $data : array();
}

function clean_text($text, $fallback, $limit = 900) {
    $text = html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    $text = trim($text);
    if ($text === '') { $text = $fallback; }
    if (mb_strlen($text, 'UTF-8') > $limit) {
        $text = mb_substr($text, 0, $limit, 'UTF-8') . '...';
    }
    return $text;
}

function job_view_file($job) {
    $source = (string)($job['source'] ?? '');
    return $source === 'horizon' ? 'horizonv.php' : 'kuragev.php';
}

$data = fetch_json($api);
$jobs = !empty($data['jobs']) && is_array($data['jobs']) ? $data['jobs'] : array();
$seen = array();

usort($jobs, function($a, $b) {
    $ad = (string)($a['created_at'] ?? $a['updated_at'] ?? '');
    $bd = (string)($b['created_at'] ?? $b['updated_at'] ?? '');
    return strcmp($bd, $ad);
});

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">
<?php foreach ($jobs as $job): ?>
<?php
    $job_id = preg_replace('/[^a-zA-Z0-9]/', '', (string)($job['job_id'] ?? ''));
    if ($job_id === '' || ($job['status'] ?? '') !== 'done') { continue; }
    if (isset($seen[$job_id])) { continue; }
    $seen[$job_id] = true;
    $file = job_view_file($job);
    $loc = $base . '/' . $file . '?id=' . rawurlencode($job_id);
    $video = $base . '/' . $file . '?proxy=video&job_id=' . rawurlencode($job_id);
    $thumb_ver = rawurlencode((string)($job['updated_at'] ?? $job['created_at'] ?? '1'));
    $thumb = $base . '/' . $file . '?proxy=thumbnail&job_id=' . rawurlencode($job_id) . '&v=' . $thumb_ver;
    $title = trim((string)($job['title'] ?? 'Kurage AI動画'));
    if ($title === '') { $title = 'Kurage AI動画'; }
    $desc = clean_text($job['tweet_text'] ?? '', $title . ' のAI生成ショート動画です。');
    $publication_date = '';
    foreach (array('created_at', 'updated_at') as $key) {
        if (!empty($job[$key])) {
            $ts = strtotime((string)$job[$key]);
            if ($ts) { $publication_date = date('c', $ts); }
            break;
        }
    }
?>
  <url>
    <loc><?php echo x($loc); ?></loc>
    <video:video>
      <video:thumbnail_loc><?php echo x($thumb); ?></video:thumbnail_loc>
      <video:title><?php echo x($title); ?></video:title>
      <video:description><?php echo x($desc); ?></video:description>
      <video:content_loc><?php echo x($video); ?></video:content_loc>
<?php if ($publication_date !== ''): ?>
      <video:publication_date><?php echo x($publication_date); ?></video:publication_date>
<?php endif; ?>
      <video:family_friendly>yes</video:family_friendly>
    </video:video>
  </url>
<?php endforeach; ?>
</urlset>
