<?php
date_default_timezone_set('Asia/Tokyo');

$logfile = __DIR__ . '/access.log';
define('SIMPLETRACK_INTERNAL_KEY', 'kurage-track-v1');
define('KURAGE_API', 'http://exbridge.ddns.net:18303');

function st_admin_allowed() {
    $auth = st_auth_state();
    return !empty($auth['is_admin']);
}

function st_auth_state() {
    if (!file_exists(__DIR__ . '/auth_common.php')) return array('logged_in' => false, 'is_admin' => false, 'session_user' => '');
    require_once __DIR__ . '/auth_common.php';
    if (!function_exists('url2ai_auth_bootstrap')) return array('logged_in' => false, 'is_admin' => false, 'session_user' => '');
    $auth = url2ai_auth_bootstrap();
    return is_array($auth) ? $auth : array('logged_in' => false, 'is_admin' => false, 'session_user' => '');
}

function st_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function st_is_bot_ua($ua) {
    $ua = strtolower(trim((string)$ua));
    if ($ua === '') return true;
    $bot_words = array(
        'bot', 'crawler', 'spider', 'slurp', 'crawl', 'mediapartners',
        'curl', 'wget', 'python', 'httpclient', 'scrapy', 'headless',
        'phantom', 'selenium', 'playwright', 'puppeteer',
        'facebookexternalhit', 'meta-externalagent', 'twitterbot', 'slackbot', 'discordbot',
        'linebot', 'googlebot', 'googleother', 'google-read-aloud', 'bingbot', 'duckduckbot', 'baiduspider',
        'yandexbot', 'ahrefsbot', 'semrushbot', 'mj12bot', 'petalbot',
        'bytespider', 'claudebot', 'gptbot', 'oai-searchbot', 'ccbot', 'perplexitybot',
        'applebot', 'amazonbot', 'kgrowth', 'kgrowth-kurage'
    );
    foreach ($bot_words as $word) {
        if (strpos($ua, $word) !== false) return true;
    }
    return false;
}

function st_set_seen_cookie() {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $cookie = 'kurage_st_seen=' . time()
        . '; Path=/; Max-Age=2592000; SameSite=Lax'
        . ($secure ? '; Secure' : '')
        . '; HttpOnly';
    header('Set-Cookie: ' . $cookie, false);
}

function st_sanitize_field($value) {
    return str_replace(array('|', "\n", "\r"), array('', '', ''), trim((string)$value));
}

function st_clean_url_label($url) {
    $decoded = urldecode((string)$url);
    $parts = parse_url($decoded);
    if (!$parts) return $decoded;
    $path = isset($parts['path']) ? $parts['path'] : '/';
    $query = isset($parts['query']) ? ('?' . $parts['query']) : '';
    return $path . $query;
}

function st_content_meta_from_url($url) {
    $parts = parse_url(urldecode((string)$url));
    if (!$parts) return null;
    $path = isset($parts['path']) ? $parts['path'] : '';
    $params = array();
    if (!empty($parts['query'])) parse_str($parts['query'], $params);

    $id = '';
    if (!empty($params['id'])) {
        $id = (string)$params['id'];
    } elseif (!empty($params['job_id'])) {
        $id = (string)$params['job_id'];
    }
    $id = preg_replace('/[^a-zA-Z0-9]/', '', $id);
    if ($id === '') return null;

    if (in_array($path, array('/kuragev.php', '/kurage.php'), true)) {
        return array('id' => $id, 'type' => 'Kurage動画');
    }
    if (in_array($path, array('/horizonv.php', '/horizon.php'), true)) {
        return array('id' => $id, 'type' => 'Horizon動画');
    }
    return null;
}

function st_collect_content_id(&$ids, $url) {
    $meta = st_content_meta_from_url($url);
    if ($meta && !empty($meta['id'])) $ids[$meta['id']] = true;

    $parts = parse_url(urldecode((string)$url));
    if (!$parts) return;
    $path = isset($parts['path']) ? $parts['path'] : '';
    if ($path !== '/go.php' || empty($parts['query'])) return;
    $params = array();
    parse_str($parts['query'], $params);
    if (!empty($params['from'])) st_collect_content_id($ids, (string)$params['from']);
}

function st_fetch_json($url, $timeout = 4) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!$res || $code >= 400) return null;
        return json_decode($res, true);
    }
    $ctx = stream_context_create(array('http' => array('timeout' => $timeout, 'header' => "Accept: application/json\r\n")));
    $res = @file_get_contents($url, false, $ctx);
    return $res ? json_decode($res, true) : null;
}

function st_build_title_map($ids) {
    $title_map = array();
    if (empty($ids)) return $title_map;

    $list = st_fetch_json(KURAGE_API . '/jobs?limit=1000', 5);
    if (is_array($list) && !empty($list['jobs']) && is_array($list['jobs'])) {
        foreach ($list['jobs'] as $job) {
            $jid = isset($job['job_id']) ? preg_replace('/[^a-zA-Z0-9]/', '', (string)$job['job_id']) : '';
            if ($jid === '' || empty($ids[$jid])) continue;
            $title = trim((string)($job['title'] ?? $job['tweet_author_name'] ?? $job['tweet_author'] ?? ''));
            if ($title !== '') $title_map[$jid] = $title;
        }
    }

    $missing = array_values(array_diff(array_keys($ids), array_keys($title_map)));
    foreach (array_slice($missing, 0, 80) as $jid) {
        $job = st_fetch_json(KURAGE_API . '/status/' . rawurlencode($jid), 4);
        if (!is_array($job)) continue;
        $title = trim((string)($job['title'] ?? $job['tweet_author_name'] ?? $job['tweet_author'] ?? ''));
        if ($title !== '') $title_map[$jid] = $title;
    }
    return $title_map;
}

function st_content_title_for_url($url, $title_map) {
    $meta = st_content_meta_from_url($url);
    if (!$meta) return '';
    $title = isset($title_map[$meta['id']]) ? trim((string)$title_map[$meta['id']]) : '';
    if ($title === '') return $meta['type'] . ' ID: ' . $meta['id'];
    return $title;
}

function st_label_url_with_title($url, $title_map) {
    $label = st_clean_url_label($url);
    $title = st_content_title_for_url($url, $title_map);
    return $title !== '' ? ($label . ' - ' . $title) : $label;
}

function st_is_kurage_detail_url($url) {
    $meta = st_content_meta_from_url($url);
    return $meta !== null;
}

function st_track_go_click($url, $ref, &$go_totals, &$go_products, &$go_sources, $at) {
    $parsed = parse_url((string)$url);
    $path = isset($parsed['path']) ? $parsed['path'] : '';
    if ($path !== '/go.php') return;
    $params = array();
    if (!empty($parsed['query'])) parse_str($parsed['query'], $params);
    $to = strtolower(trim((string)($params['to'] ?? $params['click'] ?? '(unknown)')));
    $kw = trim((string)($params['kw'] ?? ''));
    $asin = strtoupper(trim((string)($params['asin'] ?? '')));
    $from = trim((string)($params['from'] ?? ''));
    $quality = strtolower(trim((string)($params['click_quality'] ?? '')));
    $likely_human = $quality === 'likely_human';
    if ($quality === '') $likely_human = ($ref !== '');
    if ($from === '' && $ref !== '') {
        $ref_parts = parse_url($ref);
        $ref_path = isset($ref_parts['path']) ? $ref_parts['path'] : '';
        $ref_query = isset($ref_parts['query']) ? ('?' . $ref_parts['query']) : '';
        $from = trim($ref_path . $ref_query);
    }
    if ($from === '' && $ref === '') return;
    if ($from === '') $from = '(unknown)';
    $product = $kw !== '' ? $kw : ($asin !== '' ? 'ASIN:' . $asin : '(unknown)');

    if (!isset($go_totals[$to])) $go_totals[$to] = array('clicks' => 0, 'raw_clicks' => 0);
    $go_totals[$to]['raw_clicks']++;
    if ($likely_human) $go_totals[$to]['clicks']++;

    $source_key = $to . '|' . $from;
    if (!isset($go_sources[$source_key])) {
        $go_sources[$source_key] = array('to' => $to, 'from' => $from, 'clicks' => 0, 'raw_clicks' => 0, 'latest_at' => '');
    }
    $go_sources[$source_key]['raw_clicks']++;
    if ($likely_human) $go_sources[$source_key]['clicks']++;
    if ($go_sources[$source_key]['latest_at'] === '' || $at > $go_sources[$source_key]['latest_at']) $go_sources[$source_key]['latest_at'] = $at;

    $product_key = $to . '|' . $asin . '|' . $from . '|' . $product;
    if (!isset($go_products[$product_key])) {
        $go_products[$product_key] = array('to' => $to, 'product' => $product, 'asin' => $asin, 'from' => $from, 'clicks' => 0, 'raw_clicks' => 0, 'latest_at' => '');
    }
    $go_products[$product_key]['raw_clicks']++;
    if ($likely_human) $go_products[$product_key]['clicks']++;
    if ($go_products[$product_key]['latest_at'] === '' || $at > $go_products[$product_key]['latest_at']) $go_products[$product_key]['latest_at'] = $at;
}

if (isset($_GET['dashboard'])) {
    $auth = st_auth_state();
    if (empty($auth['logged_in'])) {
        if (function_exists('url2ai_auth_login_url')) {
            header('Location: ' . url2ai_auth_login_url('/simpletrack.php?dashboard=1'));
            exit;
        }
    }
    if (empty($auth['is_admin'])) {
        http_response_code(403);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><meta charset="utf-8"><title>403 Forbidden</title><p>Analytics dashboard is available to administrators only.</p><p>logged in user: ' . st_h($auth['session_user'] ?? '') . '</p>';
        exit;
    }
    clearstatcache();
    if (!file_exists($logfile)) {
        die('log not found');
    }

    $range = isset($_GET['range']) ? $_GET['range'] : 'all';
    $range_days = array('1d' => 1, '7d' => 7, '30d' => 30, '90d' => 90);
    if (!isset($range_days[$range]) && $range !== 'all') $range = 'all';
    $range_start_ts = null;
    if ($range !== 'all') {
        $range_start_ts = ($range === '1d') ? strtotime('-24 hours') : strtotime('-' . ($range_days[$range] - 1) . ' days 00:00:00');
    }
    $range_labels = array(
        '1d' => '直近24時間',
        '7d' => '直近1週間',
        '30d' => '直近30日',
        '90d' => '直近3か月',
        'all' => 'すべて',
    );

    $pv_per_day = array();
    $url_count = array();
    $ref_count = array();
    $detail_count = array();
    $go_totals = array();
    $go_products = array();
    $go_sources = array();
    $content_ids = array();
    $voice_pro_count = 0;
    $horizon_count = 0;
    $kurage_count = 0;

    $lines = file($logfile);
    foreach ($lines as $line) {
        $parts = explode(' | ', trim($line));
        if (count($parts) < 5) continue;

        $ts = strtotime($parts[0]);
        if ($range_start_ts !== null && (!$ts || $ts < $range_start_ts)) continue;

        $date = substr($parts[0], 0, 10);
        $url = $parts[2];
        $ref = $parts[3];
        $ua = isset($parts[4]) ? $parts[4] : '';
        if (st_is_bot_ua($ua)) continue;
        st_collect_content_id($content_ids, $url);
        st_collect_content_id($content_ids, $ref);

        if (!isset($pv_per_day[$date])) $pv_per_day[$date] = 0;
        $pv_per_day[$date]++;

        if ($url !== '') {
            $skip = (strpos($url, 'simpletrack.php') !== false) || (strpos($url, 'dashboard=1') !== false);
            if (!$skip) {
                if (!isset($url_count[$url])) $url_count[$url] = 0;
                $url_count[$url]++;
                if (st_is_kurage_detail_url($url)) {
                    if (!isset($detail_count[$url])) $detail_count[$url] = 0;
                    $detail_count[$url]++;
                }
                $parsed_url = parse_url(urldecode((string)$url));
                if (($parsed_url['path'] ?? '') === '/go.php' && !empty($parsed_url['query'])) {
                    $go_params = array();
                    parse_str($parsed_url['query'], $go_params);
                    if (!empty($go_params['from'])) st_collect_content_id($content_ids, (string)$go_params['from']);
                }
                st_track_go_click($url, $ref, $go_totals, $go_products, $go_sources, $parts[0]);
                $path = parse_url($url, PHP_URL_PATH);
                if ($path === '/horizonv.php' || $path === '/horizon.php') $horizon_count++;
                elseif ($path === '/kuragevp.php') $voice_pro_count++;
                elseif ($path === '/kuragev.php' || $path === '/kurage.php') $kurage_count++;
            }
        }

        if ($ref !== '' && strpos($ref, 'simpletrack.php') === false) {
            if (!isset($ref_count[$ref])) $ref_count[$ref] = 0;
            $ref_count[$ref]++;
        }
    }

    ksort($pv_per_day);
    arsort($url_count);
    arsort($ref_count);
    arsort($detail_count);
    uasort($go_sources, function($a, $b) {
        if (($a['latest_at'] ?? '') !== ($b['latest_at'] ?? '')) return strcmp($b['latest_at'], $a['latest_at']);
        if ($a['clicks'] !== $b['clicks']) return ($a['clicks'] > $b['clicks']) ? -1 : 1;
        return ($a['raw_clicks'] > $b['raw_clicks']) ? -1 : 1;
    });
    uasort($go_products, function($a, $b) {
        if (($a['latest_at'] ?? '') !== ($b['latest_at'] ?? '')) return strcmp($b['latest_at'], $a['latest_at']);
        if ($a['clicks'] !== $b['clicks']) return ($a['clicks'] > $b['clicks']) ? -1 : 1;
        return ($a['raw_clicks'] > $b['raw_clicks']) ? -1 : 1;
    });
    $title_map = st_build_title_map($content_ids);

    $top_urls = array_slice($url_count, 0, 20, true);
    $top_refs = array_slice($ref_count, 0, 20, true);
    $top_details = array_slice($detail_count, 0, 50, true);
    $top_go_sources = array_slice($go_sources, 0, 50, true);
    $top_go_products = array_slice($go_products, 0, 50, true);
    $amazon_clicks = isset($go_totals['amazon']) ? (int)$go_totals['amazon']['clicks'] : 0;
    $amazon_raw_clicks = isset($go_totals['amazon']) ? (int)$go_totals['amazon']['raw_clicks'] : 0;

    $all_urls_array = array();
    foreach ($url_count as $u => $c) {
        $all_urls_array[] = array(
            'url' => st_clean_url_label($u),
            'title' => st_content_title_for_url($u, $title_map),
            'pv' => $c
        );
    }
    $all_urls = json_encode($all_urls_array, JSON_UNESCAPED_UNICODE);

    $dates = json_encode(array_keys($pv_per_day));
    $pv_counts = json_encode(array_values($pv_per_day));
    $url_labels = json_encode(array_map(function($u) use ($title_map) { return st_label_url_with_title($u, $title_map); }, array_keys($top_urls)), JSON_UNESCAPED_UNICODE);
    $url_counts = json_encode(array_values($top_urls));
    $ref_labels = json_encode(array_map(function($u) use ($title_map) { return st_label_url_with_title($u, $title_map); }, array_keys($top_refs)), JSON_UNESCAPED_UNICODE);
    $ref_counts = json_encode(array_values($top_refs));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kurage Web Analytics</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
:root{--ink:#17242c;--muted:#647884;--line:#d6e5ea;--soft:#f6fbfc;--accent:#007f96;--accent2:#39b7c6}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif;color:var(--ink);background:linear-gradient(180deg,#f7fcfd 0%,#edf8fa 48%,#fff 100%);line-height:1.75;word-break:break-all;overflow-wrap:break-word}a{color:inherit}.top{background:rgba(255,255,255,.92);backdrop-filter:blur(12px);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:5}.wrap{max-width:1120px;margin:0 auto;padding:12px 16px}.bar{display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{display:flex;align-items:center;gap:12px;text-decoration:none}.mark{width:38px;height:38px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900}.brand b{display:block;font-size:22px;line-height:1}.brand span{display:block;color:var(--muted);font-size:12px;margin-top:3px}.dash-link{border:1px solid var(--line);background:#fff;border-radius:999px;padding:7px 11px;color:var(--muted);font-size:12px;text-decoration:none}.hero{max-width:1120px;margin:0 auto;padding:32px 16px 18px}.hero h1{font-size:32px;line-height:1.35;margin:0 0 8px}.lead{color:var(--muted);font-size:15px;margin:0;max-width:760px}.main{max-width:1120px;margin:0 auto;padding:16px 16px 48px}.range-nav{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}.range-nav a{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:6px 12px;border:1px solid var(--line);border-radius:999px;color:var(--muted);text-decoration:none;background:#fff;font-size:13px;font-weight:800}.range-nav a.active{background:var(--accent);color:#fff;border-color:var(--accent)}.range-note{color:var(--muted);font-size:13px;margin:-8px 0 16px}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}.stat{background:#fff;border:1px solid var(--line);border-radius:10px;padding:14px;box-shadow:0 10px 28px rgba(20,74,91,.06)}.stat small{display:block;color:var(--muted);font-size:12px}.stat strong{display:block;margin-top:4px;font-size:22px;line-height:1.2}.canvasBox{background:#fff;border:1px solid var(--line);border-radius:10px;padding:18px;margin-bottom:16px;box-shadow:0 10px 28px rgba(20,74,91,.06)}.canvasBox h2{font-size:18px;line-height:1.4;margin:0 0 12px}table{width:100%;border-collapse:collapse}th,td{border:1px solid var(--line);padding:8px;font-size:13px;background:#fff}th{background:#f8fcfd;color:var(--muted);text-align:left}canvas{background:#fff;border-radius:4px}@media(max-width:760px){.stats{grid-template-columns:1fr 1fr}.hero h1{font-size:27px}.bar{align-items:flex-start}.brand span{display:none}.canvasBox{padding:12px 8px}.dash-link{display:none}}
</style>
</head>
<body>
<header class="top"><div class="wrap"><div class="bar"><a class="brand" href="./"><div class="mark">K</div><div><b>Kurage</b><span>AI video and agent systems</span></div></a><a class="dash-link" href="./simpletrack.php?dashboard=1">Analytics</a></div></div></header>
<section class="hero"><h1>Kurage Web Analytics</h1><p class="lead">Kurageのページ閲覧、動画詳細ページ、流入元を確認します。</p></section>
<main class="main">
<div class="range-nav"><?php foreach($range_labels as $key => $label): ?><a class="<?php echo $range === $key ? 'active' : ''; ?>" href="./simpletrack.php?dashboard=1&range=<?php echo st_h($key); ?>"><?php echo st_h($label); ?></a><?php endforeach; ?></div>
<div class="range-note">表示期間: <?php echo st_h($range_labels[$range]); ?></div>
<div class="stats">
  <div class="stat"><small>Total PV</small><strong><?php echo number_format(array_sum($pv_per_day)); ?></strong></div>
  <div class="stat"><small>Kurage</small><strong><?php echo number_format($kurage_count); ?></strong></div>
  <div class="stat"><small>Horizon</small><strong><?php echo number_format($horizon_count); ?></strong></div>
  <div class="stat"><small>Voice-Pro</small><strong><?php echo number_format($voice_pro_count); ?></strong></div>
  <div class="stat"><small>Amazon 実クリック / raw</small><strong><?php echo number_format($amazon_clicks); ?> / <?php echo number_format($amazon_raw_clicks); ?></strong></div>
</div>
<div class="canvasBox"><h2>Daily PV</h2><canvas id="pvChart"></canvas></div>
<div class="canvasBox"><h2>Top URLs</h2><canvas id="urlChart"></canvas></div>
<div class="canvasBox"><h2>Top Referrers</h2><canvas id="refChart"></canvas></div>
<div class="canvasBox"><h2>動画詳細ページ</h2><table><thead><tr><th>#</th><th>URL</th><th>投稿・動画タイトル</th><th>PV</th></tr></thead><tbody><?php if(empty($top_details)): ?><tr><td colspan="4">動画詳細ページのアクセスはありません。</td></tr><?php else: ?><?php $i=1; foreach($top_details as $u => $c): ?><tr><td><?php echo $i++; ?></td><td><?php echo st_h(st_clean_url_label($u)); ?></td><td><?php echo st_h(st_content_title_for_url($u, $title_map)); ?></td><td><?php echo number_format($c); ?></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div>
<div class="canvasBox"><h2>Amazonクリック 呼び出し元ページ</h2><table><thead><tr><th>#</th><th>呼び出し元</th><th>投稿・動画タイトル</th><th>遷移先</th><th>最新クリック日時</th><th>実クリック</th><th>raw</th></tr></thead><tbody><?php if(empty($top_go_sources)): ?><tr><td colspan="7">Amazonクリックはありません。</td></tr><?php else: ?><?php $i=1; foreach($top_go_sources as $row): ?><tr><td><?php echo $i++; ?></td><td><?php echo st_h($row['from']); ?></td><td><?php echo st_h(st_content_title_for_url($row['from'], $title_map)); ?></td><td><?php echo st_h($row['to']); ?></td><td><?php echo st_h($row['latest_at']); ?></td><td><?php echo number_format($row['clicks']); ?></td><td><?php echo number_format($row['raw_clicks']); ?></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div>
<div class="canvasBox"><h2>Amazonクリック キーワード/ASIN</h2><table><thead><tr><th>#</th><th>キーワード/ASIN</th><th>ASIN</th><th>呼び出し元</th><th>投稿・動画タイトル</th><th>最新クリック日時</th><th>実クリック</th><th>raw</th></tr></thead><tbody><?php if(empty($top_go_products)): ?><tr><td colspan="8">Amazonクリックはありません。</td></tr><?php else: ?><?php $i=1; foreach($top_go_products as $row): ?><tr><td><?php echo $i++; ?></td><td><?php echo st_h($row['product']); ?></td><td><?php echo st_h($row['asin']); ?></td><td><?php echo st_h($row['from']); ?></td><td><?php echo st_h(st_content_title_for_url($row['from'], $title_map)); ?></td><td><?php echo st_h($row['latest_at']); ?></td><td><?php echo number_format($row['clicks']); ?></td><td><?php echo number_format($row['raw_clicks']); ?></td></tr><?php endforeach; ?><?php endif; ?></tbody></table></div>
<div class="canvasBox"><h2>Access URL Details</h2><table><thead><tr><th>#</th><th>URL</th><th>投稿・動画タイトル</th><th>PV</th></tr></thead><tbody id="detailBody"></tbody></table></div>
<script>
const allData = <?php echo $all_urls; ?>;
let rendered = 0;
const tbody = document.getElementById('detailBody');
function renderRows(){
  const next = Math.min(rendered + 50, allData.length);
  for(let i = rendered; i < next; i++){
    const tr = document.createElement('tr');
    [String(i + 1), allData[i].url || '', allData[i].title || '', String(allData[i].pv || 0)].forEach(function(value){
      const td = document.createElement('td');
      td.textContent = value;
      tr.appendChild(td);
    });
    tbody.appendChild(tr);
  }
  rendered = next;
}
renderRows();
window.addEventListener('scroll', function(){ if(window.innerHeight + window.scrollY >= document.body.offsetHeight - 200 && rendered < allData.length){ renderRows(); } });
Chart.defaults.color = 'rgba(0,0,0,.7)';
Chart.defaults.borderColor = '#d6e5ea';
var isMobile = window.innerWidth < 760;
function truncLabel(s, len){ return String(s).length > len ? String(s).slice(0, len) + '...' : String(s); }
var labelLen = isMobile ? 32 : 72;
var rawUrlLabels = <?php echo $url_labels; ?>;
var rawRefLabels = <?php echo $ref_labels; ?>;
var urlLabels = rawUrlLabels.map(function(s){ return truncLabel(s, labelLen); });
var refLabels = rawRefLabels.map(function(s){ return truncLabel(s, labelLen); });
var urlCounts = <?php echo $url_counts; ?>;
var refCounts = <?php echo $ref_counts; ?>;
if(isMobile){ rawUrlLabels=rawUrlLabels.slice(0,10); rawRefLabels=rawRefLabels.slice(0,10); urlLabels=urlLabels.slice(0,10); urlCounts=urlCounts.slice(0,10); refLabels=refLabels.slice(0,10); refCounts=refCounts.slice(0,10); }
new Chart(document.getElementById('pvChart'),{type:'line',data:{labels:<?php echo $dates; ?>,datasets:[{label:'Daily PV',data:<?php echo $pv_counts; ?>,borderColor:'#007f96',backgroundColor:'rgba(0,127,150,.14)',tension:.3,fill:true}]},options:{responsive:true,plugins:{legend:{labels:{font:{size:11}}}},scales:{y:{beginAtZero:true},x:{ticks:{maxTicksLimit:isMobile?7:20}}}}});
function makeBarChart(id, labels, fullLabels, data, color){
  var itemH = isMobile ? 30 : 28;
  var wrap = document.getElementById(id).parentNode;
  wrap.style.position = 'relative';
  wrap.style.height = (labels.length * itemH + 60) + 'px';
  return new Chart(document.getElementById(id),{type:'bar',data:{labels:labels,datasets:[{data:data,backgroundColor:color,borderRadius:3}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{title:function(items){var idx=items[0].dataIndex;return fullLabels[idx]||labels[idx];}}}},scales:{x:{beginAtZero:true,ticks:{font:{size:isMobile?10:11}}},y:{afterFit:function(axis){axis.width=isMobile?150:260;},ticks:{font:{size:isMobile?10:11}}}}}});
}
makeBarChart('urlChart', urlLabels, rawUrlLabels, urlCounts, '#007f96');
makeBarChart('refChart', refLabels, rawRefLabels, refCounts, '#39b7c6');
</script>
</main>
</body>
</html>
<?php
exit;
}

if (isset($_GET['url']) && $_GET['url'] !== '') {
    $url = filter_var($_GET['url'], FILTER_SANITIZE_URL);
    if (!preg_match('#^https?://#i', $url)) $url = '';
} else {
    $url = isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') : '';
}

if (isset($_GET['ref']) && $_GET['ref'] !== '') {
    $ref = filter_var($_GET['ref'], FILTER_SANITIZE_URL);
    if (!preg_match('#^https?://#i', $ref)) $ref = '';
} else {
    $ref = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
}

$internal_key = isset($_GET['st_key']) ? (string)$_GET['st_key'] : '';
$internal_ok = ($internal_key !== '' && $internal_key === SIMPLETRACK_INTERNAL_KEY);

$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
if ($internal_ok && isset($_GET['ip']) && $_GET['ip'] !== '') $ip = $_GET['ip'];
$ip = st_sanitize_field($ip);

$ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
if ($internal_ok && isset($_GET['ua']) && $_GET['ua'] !== '') $ua = $_GET['ua'];
$ua = st_sanitize_field($ua);
$ref = st_sanitize_field($ref);
$url = st_sanitize_field($url);

if (st_is_bot_ua($ua)) {
    header('Content-Type: application/javascript');
    echo '// ignored';
    exit;
}

if (!$internal_ok) {
    st_set_seen_cookie();
}

$line = date('Y-m-d H:i:s') . ' | ' . $ip . ' | ' . $url . ' | ' . $ref . ' | ' . $ua . "\n";
file_put_contents($logfile, $line, FILE_APPEND | LOCK_EX);

header('Content-Type: application/javascript');
echo '// tracked';
exit;
