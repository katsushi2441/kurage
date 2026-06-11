<?php
date_default_timezone_set('Asia/Tokyo');

define('SIMPLETRACK_INTERNAL_KEY', 'kurage-track-v1');
define('AMAZON_ASSOCIATE_TAG', 'bittensorman-22');

function go_clean($value) {
    return str_replace(array('|', "\n", "\r"), array('', '', ''), trim((string)$value));
}

function go_is_bot_ua($ua) {
    $ua = strtolower(trim((string)$ua));
    if ($ua === '') return true;
    $bot_words = array('bot','crawler','spider','slurp','crawl','mediapartners','curl','wget','python','httpclient','scrapy','headless','phantom','selenium','playwright','puppeteer','facebookexternalhit','meta-externalagent','twitterbot','slackbot','discordbot','linebot','googlebot','googleother','bingbot','duckduckbot','baiduspider','yandexbot','ahrefsbot','semrushbot','mj12bot','petalbot','bytespider','claudebot','gptbot','oai-searchbot','ccbot','perplexitybot','applebot','amazonbot','kgrowth','kgrowth-kurage');
    foreach ($bot_words as $word) if (strpos($ua, $word) !== false) return true;
    return false;
}

function go_recent_seen_cookie() {
    if (empty($_COOKIE['kurage_st_seen'])) return false;
    $seen = preg_replace('/\D/', '', (string)$_COOKIE['kurage_st_seen']);
    if ($seen === '') return false;
    $ts = intval($seen);
    return $ts > 0 && $ts >= (time() - 86400);
}

function go_valid_referrer($ref) {
    $parts = parse_url(trim((string)$ref));
    if (empty($parts['host'])) return false;
    $host = strtolower($parts['host']);
    return $host === 'kurage.exbridge.jp' || substr($host, -12) === '.exbridge.jp';
}

function amazon_category_url($category) {
    $category = strtolower(trim((string)$category));
    $paths = array(
        'books' => '/gp/bestsellers/books',
        'music' => '/gp/bestsellers/music',
        'dvd' => '/gp/bestsellers/dvd',
        'electronics' => '/gp/bestsellers/electronics',
        'hobby' => '/gp/bestsellers/hobby',
        'camera' => '/gp/bestsellers/electronics/3210991',
        'business' => '/s?k=%E3%83%93%E3%82%B8%E3%83%8D%E3%82%B9%E6%9B%B8',
        'ai' => '/s?k=AI+%E3%83%93%E3%82%B8%E3%83%8D%E3%82%B9%E6%9B%B8',
        'video' => '/s?k=%E5%8B%95%E7%94%BB%E7%B7%A8%E9%9B%86+%E6%92%AE%E5%BD%B1%E6%A9%9F%E6%9D%90',
    );
    $path = isset($paths[$category]) ? $paths[$category] : $paths['books'];
    $separator = (strpos($path, '?') === false) ? '?' : '&';
    return 'https://www.amazon.co.jp' . $path . $separator . 'tag=' . rawurlencode(AMAZON_ASSOCIATE_TAG);
}

function amazon_url($kw, $asin, $direct_url, $category) {
    $asin = strtoupper(trim((string)$asin));
    $direct_url = trim((string)$direct_url);
    $category = trim((string)$category);
    if ($direct_url !== '' && preg_match('#^https://(?:www\.)?amazon\.co\.jp/#i', $direct_url)) {
        $parts = parse_url($direct_url);
        $query = array();
        if (!empty($parts['query'])) parse_str($parts['query'], $query);
        $query['tag'] = AMAZON_ASSOCIATE_TAG;
        $path = isset($parts['path']) ? $parts['path'] : '/';
        return 'https://www.amazon.co.jp' . $path . '?' . http_build_query($query);
    }
    if (preg_match('/^[A-Z0-9]{10}$/', $asin)) {
        return 'https://www.amazon.co.jp/dp/' . rawurlencode($asin) . '?tag=' . rawurlencode(AMAZON_ASSOCIATE_TAG);
    }
    if ($kw === '') return amazon_category_url($category === '' ? 'books' : $category);
    return 'https://www.amazon.co.jp/s?k=' . rawurlencode($kw) . '&tag=' . rawurlencode(AMAZON_ASSOCIATE_TAG);
}

$ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
if (go_is_bot_ua($ua)) {
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    http_response_code(204);
    exit;
}

$purpose = isset($_SERVER['HTTP_PURPOSE']) ? strtolower(trim($_SERVER['HTTP_PURPOSE'])) : '';
$sec_purpose = isset($_SERVER['HTTP_SEC_PURPOSE']) ? strtolower(trim($_SERVER['HTTP_SEC_PURPOSE'])) : '';
$x_moz = isset($_SERVER['HTTP_X_MOZ']) ? strtolower(trim($_SERVER['HTTP_X_MOZ'])) : '';
if ($purpose === 'prefetch' || $sec_purpose === 'prefetch' || $x_moz === 'prefetch') {
    header('Cache-Control: no-store');
    http_response_code(204);
    exit;
}

$to = strtolower(trim((string)($_GET['to'] ?? 'amazon')));
$kw = mb_substr(trim((string)($_GET['kw'] ?? '')), 0, 200, 'UTF-8');
$asin = strtoupper(trim((string)($_GET['asin'] ?? '')));
$direct_url = trim((string)($_GET['url'] ?? ''));
$category = trim((string)($_GET['cat'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$ref = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';

if ($from === '' && $ref === '') {
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    http_response_code(204);
    exit;
}

if ($to !== 'amazon') {
    header('HTTP/1.1 400 Bad Request');
    echo 'bad request';
    exit;
}

$dest = amazon_url($kw, $asin, $direct_url, $category);
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'kurage.exbridge.jp';
$request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/go.php';
$click_url = 'https://' . $host . $request_uri;
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
if ($from === '' && $ref !== '') {
    $ref_parts = parse_url($ref);
    $ref_path = isset($ref_parts['path']) ? $ref_parts['path'] : '';
    $ref_query = isset($ref_parts['query']) ? ('?' . $ref_parts['query']) : '';
    $from = trim($ref_path . $ref_query);
}

$valid_ref = go_valid_referrer($ref);
$seen_cookie = go_recent_seen_cookie();
$click_url .= (strpos($click_url, '?') === false ? '?' : '&')
    . 'click=' . rawurlencode($to)
    . '&asin=' . rawurlencode($asin)
    . '&kw=' . rawurlencode($kw)
    . '&cat=' . rawurlencode($category)
    . '&from=' . rawurlencode($from)
    . '&click_quality=' . rawurlencode(($valid_ref || $seen_cookie) ? 'likely_human' : 'raw')
    . '&click_signal=' . rawurlencode($valid_ref ? 'referrer' : ($seen_cookie ? 'seen_cookie' : 'none'));

$track_url = 'https://kurage.exbridge.jp/simpletrack.php?' . http_build_query(array(
    'url' => $click_url,
    'ref' => $ref,
    'ip' => $ip,
    'ua' => $ua,
    'st_key' => SIMPLETRACK_INTERNAL_KEY,
));
$track_ctx = stream_context_create(array('http' => array('method' => 'GET', 'timeout' => 2, 'header' => 'User-Agent: ' . go_clean($ua) . "\r\n", 'ignore_errors' => true)));
@file_get_contents($track_url, false, $track_ctx);

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Location: ' . $dest, true, 302);
exit;
