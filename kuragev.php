<?php
/**
 * kuragev.php — Kurage 動画ビューワー
 * Default: card list (white bg, ustoryv.php style)
 * Toggle: fullscreen reel viewer
 * Detail: ?id=JOB_ID
 */
require_once __DIR__ . '/auth_common.php';
date_default_timezone_set('Asia/Tokyo');

$KURAGE_API = 'http://exbridge.ddns.net:18303';
$BASE_URL   = 'https://kurage.exbridge.jp';
$THIS_FILE  = 'kuragev.php';
$ADMIN      = 'xb_bittensor';

$auth         = url2ai_auth_bootstrap();
$logged_in    = $auth['logged_in'];
$session_user = $auth['session_user'];
$is_admin     = $auth['is_admin'];

if (isset($_GET['login']))  { header('Location: ' . url2ai_auth_login_url($BASE_URL . '/' . $THIS_FILE)); exit; }
if (isset($_GET['logout'])) { header('Location: ' . url2ai_auth_logout_url($BASE_URL . '/' . $THIS_FILE)); exit; }
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

// 削除処理（管理者のみ）
if (isset($_POST['delete_job']) && $is_admin) {
    $del_id = preg_replace('/[^a-zA-Z0-9]/', '', (string)$_POST['delete_job']);
    $ch = curl_init($KURAGE_API . '/jobs/' . $del_id);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_exec($ch);
    curl_close($ch);
    header('Location: ' . $THIS_FILE); exit;
}
$SITE_NAME  = 'Kurage';

function h($s) { return str_replace("\xEF\xBF\xBD", '', htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); }

function static_media_url_for_job($job, $job_id, $kind) {
    global $BASE_URL, $THIS_FILE;
    $jid = preg_replace('/[^a-zA-Z0-9]/', '', (string)$job_id);
    if ($jid === '') { return ''; }
    if ($kind === 'video') {
        $saved = trim((string)($job['static_video_url'] ?? ''));
        if ($saved !== '') { return $saved; }
        if (is_file(__DIR__ . '/videos/' . $jid . '.mp4')) {
            return $BASE_URL . '/videos/' . rawurlencode($jid) . '.mp4';
        }
        return $BASE_URL . '/' . $THIS_FILE . '?proxy=video&job_id=' . rawurlencode($jid);
    }
    $saved = trim((string)($job['static_thumbnail_url'] ?? ''));
    if ($saved !== '') { return $saved; }
    if (is_file(__DIR__ . '/thumbs/' . $jid . '.jpg')) {
        return $BASE_URL . '/thumbs/' . rawurlencode($jid) . '.jpg';
    }
    /* v= はGoogleが安定URLを要求するため、変動しない created_at を使う (updated_at はクロール毎にURLが変わり動画インデックスを阻害する) */
    $thumb_ver = rawurlencode((string)($job['created_at'] ?? '1'));
    return $BASE_URL . '/' . $THIS_FILE . '?proxy=thumbnail&job_id=' . rawurlencode($jid) . '&v=' . $thumb_ver;
}

function is_voice_pro_job($job) {
    return (($job['source'] ?? '') === 'kuragevp') || (($job['content_type'] ?? '') === 'voice_pro_translation') || !empty($job['kuragevp_job_id']);
}

function is_entertainment_job($job) {
    return (($job['source'] ?? '') === 'entertainment')
        || (($job['content_type'] ?? '') === 'entertainment_short')
        || (strpos((string)($job['tweet_url'] ?? ''), '/entertainment.php') !== false);
}

function job_tool_key($job) {
    $source = strtolower(trim((string)($job['source'] ?? '')));
    $content_type = strtolower(trim((string)($job['content_type'] ?? '')));
    if (is_voice_pro_job($job)) { return 'kuragevp'; }
    if ($source === 'kmontage') { return 'kmontage'; }
    if ($source === 'blog' || $content_type === 'blog') { return 'blog'; }
    if ($source === 'horizon') { return 'horizon'; }
    if (is_entertainment_job($job)) { return 'entertainment'; }
    if ($source !== '') { return $source; }
    return 'kurage';
}

function job_tool_label($job_or_key) {
    $key = is_array($job_or_key) ? job_tool_key($job_or_key) : strtolower(trim((string)$job_or_key));
    $labels = array(
        'kurage' => 'Kurage',
        'tweet' => 'Kurage',
        'horizon' => 'Horizon',
        'blog' => 'Kurage Blog',
        'kuragevp' => 'Kurage Voice Pro',
        'kmontage' => 'Kurage Montage',
        'entertainment' => 'Kurage Entertainment',
        'klofi' => 'Kurage Lo-Fi',
    );
    return $labels[$key] ?? ($key !== '' ? $key : 'Kurage');
}

function current_query_url($overrides = array()) {
    global $THIS_FILE;
    $params = $_GET;
    unset($params['id']);
    unset($params['author']);
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    $query = http_build_query($params);
    return $THIS_FILE . ($query ? ('?' . $query) : '');
}

function related_article_url($job) {
    foreach (array('article_url', 'tweet_url') as $key) {
        $url = trim((string)($job[$key] ?? ''));
        if ($url !== '' && strpos($url, '/entertainment.php') !== false) {
            return $url;
        }
    }
    return '';
}

function is_local_source_path($url) {
    $url = trim((string)$url);
    return $url === '' || strpos($url, '/home/') === 0 || strpos($url, 'file:') === 0;
}

function job_source_url($job) {
    foreach (array('source_url', 'original_url', 'tweet_url') as $key) {
        $url = trim((string)($job[$key] ?? ''));
        if ($url !== '' && !is_local_source_path($url)) {
            return $url;
        }
    }
    return '';
}

function job_source_platform($job) {
    $platform = strtolower(trim((string)($job['source_platform'] ?? '')));
    $url = strtolower(job_source_url($job));
    if ($platform === 'youtube' || strpos($url, 'youtube.com') !== false || strpos($url, 'youtu.be') !== false) {
        return 'youtube';
    }
    if ($platform === 'x' || $platform === 'twitter' || strpos($url, 'x.com') !== false || strpos($url, 'twitter.com') !== false) {
        return 'x';
    }
    return $platform ?: 'video';
}

function job_source_label($job) {
    if (is_entertainment_job($job)) { return '関連する考察記事:'; }
    if (is_voice_pro_job($job)) {
        $platform = job_source_platform($job);
        if ($platform === 'youtube') { return '元のYouTube動画:'; }
        if ($platform === 'x') { return '元のX投稿:'; }
        return '元動画:';
    }
    return '元の投稿:';
}

function job_source_button_label($job) {
    if (is_entertainment_job($job)) { return '考察記事を開く'; }
    if (is_voice_pro_job($job)) {
        $platform = job_source_platform($job);
        if ($platform === 'youtube') { return '元のYouTube動画'; }
        if ($platform === 'x') { return '元のX投稿'; }
        return '元動画を開く';
    }
    return '元の投稿を開く';
}

function job_body_label($job) {
    if (is_voice_pro_job($job)) { return '動画の説明'; }
    if (is_entertainment_job($job)) { return '関連記事の要約'; }
    return '元の投稿';
}

function job_display_title($job) {
    foreach (array('display_title', 'summary_title', 'article_title', 'title', 'source_title') as $key) {
        $title = trim((string)($job[$key] ?? ''));
        if ($title !== '') { return $title; }
    }
    return 'Kurage動画';
}

function voice_pro_label_for_job($job) {
    $label = trim((string)($job['voice_pro_label'] ?? ''));
    if ($label !== '') { return $label; }
    $title = job_display_title($job);
    if (preg_match('/\\[([^\\]]*(?:Dub|Subtitles)[^\\]]*)\\]/i', $title, $m)) { return trim($m[1]); }
    if (preg_match('/【([^】]*(?:字幕|吹替)[^】]*)】/u', $title, $m)) { return trim($m[1]); }
    $lang = strtolower((string)($job['target_lang'] ?? ''));
    $audio_mode = (string)($job['audio_mode'] ?? '');
    if (strpos($lang, 'en') === 0) { return $audio_mode === 'subtitle_only' ? 'English Subtitles' : 'English Dub/Subtitles'; }
    if (strpos($lang, 'ja') === 0) { return $audio_mode === 'subtitle_only' ? '日本語字幕' : '日本語吹替・日本語字幕'; }
    return $audio_mode === 'subtitle_only' ? '翻訳字幕' : '翻訳吹替・字幕';
}

function job_body_text($job) {
    if (is_voice_pro_job($job)) {
        foreach (array('display_summary', 'summary', 'tweet_text', 'source_title') as $key) {
            $summary = trim((string)($job[$key] ?? ''));
            if ($summary !== '') { return $summary; }
        }
    }
    return trim((string)($job['tweet_text'] ?? ''));
}

function search_normalize($text) {
    $text = trim((string)$text);
    if ($text === '') { return ''; }
    $text = preg_replace('!^https?://!i', '', $text);
    $text = preg_replace('!^www\.!i', '', $text);
    $text = preg_replace('!/$!', '', $text);
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function job_matches_query($job, $query) {
    $query = search_normalize($query);
    if ($query === '') { return true; }
    $fields = array(
        $job['job_id'] ?? '',
        job_display_title($job),
        job_body_text($job),
        job_tool_label($job),
        $job['source'] ?? '',
        $job['content_type'] ?? '',
        $job['source_title'] ?? '',
        $job['summary_title'] ?? '',
        $job['article_title'] ?? '',
        $job['display_summary'] ?? '',
        $job['summary'] ?? '',
        $job['tweet_text'] ?? '',
        $job['tweet_author'] ?? '',
        $job['tweet_author_name'] ?? '',
        $job['source_url'] ?? '',
        $job['original_url'] ?? '',
        $job['tweet_url'] ?? '',
        $job['article_url'] ?? '',
        $job['related_article_url'] ?? '',
    );
    $haystack = search_normalize(implode(' ', $fields));
    return strpos($haystack, $query) !== false;
}

function normalize_copy_compare_text($text) {
    return preg_replace('/\s+/u', '', trim((string)$text));
}

function copy_detail_candidate($title, $text) {
    $title = trim((string)$title);
    $text = trim((string)$text);
    if (function_exists('iconv')) {
        $clean_text = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if ($clean_text !== false) { $text = $clean_text; }
    }
    $text = str_replace("\xEF\xBF\xBD", '', trim($text));
    if ($text !== '' && preg_match('/」$/u', $text) && !preg_match('/「/u', $text)) { $text = '「' . $text; }
    if ($text !== '' && preg_match('/』$/u', $text) && !preg_match('/『/u', $text)) { $text = '『' . $text; }
    if ($text === '') { return ''; }
    $title_norm = normalize_copy_compare_text($title);
    $text_norm = normalize_copy_compare_text($text);
    if ($title_norm !== '' && $text_norm === $title_norm) { return ''; }
    if ($title !== '' && strpos($text, $title) === 0) {
        $trimmed = trim(substr($text, strlen($title)));
        $trimmed = preg_replace('/^[\s:：\-ー|｜。.]+/u', '', $trimmed);
        $trimmed = trim((string)$trimmed);
        if ($trimmed !== '') { return $trimmed; }
    }
    return $text;
}

function copy_detail_text_for_job($job) {
    $title = job_display_title($job);
    $keys = is_voice_pro_job($job)
        ? array('copy_summary', 'primary_description', 'translated_text', 'summary', 'tweet_text')
        : array('display_summary', 'summary', 'tweet_text', 'source_title', 'translated_text');
    foreach ($keys as $key) {
        $candidate = copy_detail_candidate($title, $job[$key] ?? '');
        if ($candidate !== '') { return list_text_excerpt($candidate); }
    }
    return '詳細は動画ページで確認できます。';
}

function shorten_share_detail($text, $limit = 90) {
    $text = preg_replace('/\s+/u', ' ', trim((string)$text));
    if ($text === '') { return ''; }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') . '…' : $text;
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

function copy_text_for_job($job, $share_url) {
    $title = trim((string)job_display_title($job));
    if ($title === '') { $title = 'Kurage動画'; }
    $detail = trim((string)copy_detail_text_for_job($job));
    if ($detail !== '' && preg_match('/」$/u', $detail) && !preg_match('/「/u', $detail)) { $detail = '「' . $detail; }
    if ($detail !== '' && preg_match('/』$/u', $detail) && !preg_match('/『/u', $detail)) { $detail = '『' . $detail; }
    $voice_pro_note = is_voice_pro_job($job) ? "\nKurage Voice Pro: " . voice_pro_label_for_job($job) : "";
    return "タイトル:\n" . $title . $voice_pro_note . "\n\n詳細:\n" . $detail . "\n\nURL:\n" . $share_url;
}

function list_text_excerpt($value, $limit = 240) {
    $text = trim((string)$value);
    if ($text === '') { return ''; }
    $text = preg_replace('/\s+/u', ' ', $text);
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit, 'UTF-8') . '…' : $text;
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

function client_video_for_list($job) {
    $keys = [
        'job_id', 'source', 'content_type', 'kuragevp_job_id', 'tool_key', 'tool_label',
        'display_title', 'summary_title', 'article_title', 'title', 'source_title',
        'voice_pro_label', 'target_lang', 'audio_mode', 'source_url', 'original_url',
        'tweet_url', 'source_platform', 'created_at', 'updated_at', 'views',
        'article_url', 'related_article_url', 'tweet_author'
    ];
    $out = [];
    foreach ($keys as $key) {
        if (array_key_exists($key, $job)) { $out[$key] = $job[$key]; }
    }
    foreach (['display_summary', 'summary', 'tweet_text'] as $key) {
        if (!empty($job[$key])) { $out[$key] = list_text_excerpt($job[$key]); }
    }
    return $out;
}

/* ── 動画プロキシ（Range リクエスト対応） ────────────── */
if (isset($_GET['proxy']) && $_GET['proxy'] === 'thumbnail' && !empty($_GET['job_id'])) {
    $jid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['job_id']);
    $ch = curl_init($KURAGE_API . '/thumbnail/' . $jid);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = substr((string)$response, $header_size);
    http_response_code($code ?: 404);
    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    if ($code >= 200 && $code < 300) {
        echo $data;
    }
    exit;
}

if (isset($_GET['proxy']) && $_GET['proxy'] === 'video' && !empty($_GET['job_id'])) {
    $jid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['job_id']);
    $ch = curl_init($KURAGE_API . '/video/' . $jid);
    $req_headers = ['Accept: */*'];
    if (!empty($_SERVER['HTTP_RANGE'])) {
        $req_headers[] = 'Range: ' . $_SERVER['HTTP_RANGE'];
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $req_headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 0);
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 1024 * 256);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $line) use ($jid) {
        $trimmed = trim($line);
        if ($trimmed === '') { return strlen($line); }
        if (preg_match('#^HTTP/\S+\s+(\d+)#i', $trimmed, $m)) {
            http_response_code((int)$m[1]);
            header('Content-Type: video/mp4');
            header('Accept-Ranges: bytes');
            header('Content-Disposition: inline; filename="kurage_' . $jid . '.mp4"');
            return strlen($line);
        }
        if (preg_match('/^(Content-Range|Content-Length|Accept-Ranges|Last-Modified|ETag):\s*(.+)$/i', $trimmed)) {
            header($trimmed);
        }
        return strlen($line);
    });
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $chunk) {
        echo $chunk;
        if (function_exists('ob_flush')) { @ob_flush(); }
        flush();
        return strlen($chunk);
    });
    while (ob_get_level() > 0) { @ob_end_flush(); }
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!$ok && $code <= 0) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Video proxy failed: ' . $err;
    }
    exit;
}

if (!headers_sent()
    && extension_loaded('zlib')
    && !ini_get('zlib.output_compression')
    && strpos((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') !== false) {
    ob_start('ob_gzhandler');
}

/* ── API ヘルパー ────────────────────────────────────── */
function kurage_get($path, $timeout = 15) {
    global $KURAGE_API;
    $ch = curl_init($KURAGE_API . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$res || $code >= 400) return null;
    return json_decode($res, true);
}

function kurage_post($path, $timeout = 15) {
    global $KURAGE_API;
    $ch = curl_init($KURAGE_API . $path);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$res || $code >= 400) return null;
    return json_decode($res, true);
}

/* ── 詳細ページ (?id=JOB_ID) ─────────────────────────── */
$detail_id  = isset($_GET['id']) ? preg_replace('/[^a-zA-Z0-9]/', '', $_GET['id']) : '';
$detail_job = null;
if ($detail_id) {
    $detail_job = kurage_get('/status/' . $detail_id);
    if ($detail_job) {
        $detail_jobs_res = kurage_get('/jobs?limit=0');
        foreach (($detail_jobs_res['jobs'] ?? []) as $meta_job) {
            if (($meta_job['job_id'] ?? '') === $detail_id) {
                $detail_job = array_merge($meta_job, $detail_job);
                break;
            }
        }
    }
    $view_res = kurage_post('/view/' . $detail_id);
    if ($detail_job && !empty($view_res['views'])) {
        $detail_job['views'] = $view_res['views'];
    }
}

/* ── 一覧データ（詳細以外） ──────────────────────────── */
$videos = [];
$tool_filter = isset($_GET['tool']) ? strtolower(trim((string)$_GET['tool'])) : '';
$search_query = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
if (function_exists('mb_strlen') && mb_strlen($search_query, 'UTF-8') > 120) {
    $search_query = mb_substr($search_query, 0, 120, 'UTF-8');
} elseif (!function_exists('mb_strlen') && strlen($search_query) > 240) {
    $search_query = substr($search_query, 0, 240);
}
$tool_options = [];
$total_done_videos = 0;
$total_filtered_before_search = 0;
if (!$detail_id) {
    $jobs_res = kurage_get('/jobs?limit=0');
    $all_jobs = (!empty($jobs_res['jobs'])) ? $jobs_res['jobs'] : [];

    /* done の全動画を無限スクロール対象にする */
    foreach ($all_jobs as $j) {
        if (($j['status'] ?? '') !== 'done') continue;
        $total_done_videos++;
        $tool_key = job_tool_key($j);
        $tool_options[$tool_key] = job_tool_label($tool_key);
        if ($tool_filter !== '' && $tool_key !== $tool_filter) continue;
        $total_filtered_before_search++;
        if ($search_query !== '' && !job_matches_query($j, $search_query)) continue;
        $j['tool_key'] = $tool_key;
        $j['tool_label'] = job_tool_label($tool_key);
        $videos[] = $j;
    }
    asort($tool_options, SORT_NATURAL | SORT_FLAG_CASE);
}

$sort = isset($_GET['sort']) ? (string)$_GET['sort'] : 'created';
if (!in_array($sort, ['created', 'views'], true)) { $sort = 'created'; }
if (!$detail_id && $videos) {
    usort($videos, function($a, $b) use ($sort) {
        if ($sort === 'views') {
            $av = (int)($a['views'] ?? 0);
            $bv = (int)($b['views'] ?? 0);
            if ($bv !== $av) return $bv <=> $av;
        }
        $ad = (string)($a['created_at'] ?? $a['updated_at'] ?? '');
        $bd = (string)($b['created_at'] ?? $b['updated_at'] ?? '');
        return strcmp($bd, $ad);
    });
}
$client_videos = (!$detail_id && $videos) ? array_map('client_video_for_list', $videos) : [];

/* ── SEO ─────────────────────────────────────────────── */
if ($detail_job) {
    $page_title = job_display_title($detail_job) . ' | ' . $SITE_NAME;
    $page_desc  = mb_substr(str_replace("\n", ' ', job_body_text($detail_job)), 0, 160);
    $page_url   = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_id);
    $page_image = static_media_url_for_job($detail_job, $detail_id, 'thumbnail');
    $page_video = static_media_url_for_job($detail_job, $detail_id, 'video');
} elseif ($search_query !== '') {
    $page_title = '動画検索: ' . $search_query . ' | ' . $SITE_NAME;
    $page_desc  = $search_query . ' に一致するKurageショート動画の検索結果です。';
    $page_url   = $BASE_URL . '/' . $THIS_FILE . '?' . http_build_query(array_filter(array('q' => $search_query, 'tool' => $tool_filter, 'sort' => $sort), 'strlen'));
    $page_image = $BASE_URL . '/avatar/lipsync/kurage_mouth_0.png';
    $page_video = '';
} else {
    $page_title = $SITE_NAME . ' — AIショート動画';
    $page_desc  = 'AIで生成・翻訳した短編縦型動画を公開しています。';
    $page_url   = $BASE_URL . '/' . $THIS_FILE;
    $page_image = $BASE_URL . '/avatar/lipsync/kurage_mouth_0.png';
    $page_video = '';
}
$header_amazon_kw = trim((string)($detail_job['title'] ?? '動画編集 撮影機材 YouTube 本'));
$header_amazon_from = '/' . $THIS_FILE . ($detail_id ? ('?id=' . $detail_id) : '');
$header_amazon_url = '/go.php?' . http_build_query(array(
    'to' => 'amazon',
    'kw' => $header_amazon_kw,
    'cat' => $detail_job ? 'books' : 'video',
    'from' => $header_amazon_from,
));
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($page_title); ?></title>
<meta name="description" content="<?php echo h($page_desc); ?>">
<meta name="keywords" content="AI動画生成,ショート動画,縦型動画,動画翻訳,自動生成,Kurage,クラゲ,AI">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?php echo h($page_url); ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?php echo h($page_title); ?>">
<meta property="og:description" content="<?php echo h($page_desc); ?>">
<meta property="og:url" content="<?php echo h($page_url); ?>">
<meta property="og:site_name" content="<?php echo h($SITE_NAME); ?>">
<meta property="og:locale" content="ja_JP">
<meta property="og:image" content="<?php echo h($page_image); ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Kurage — AIショート動画">
<?php if ($detail_job && $page_video !== ''): ?>
<meta property="og:video" content="<?php echo h($page_video); ?>">
<meta property="og:video:secure_url" content="<?php echo h($page_video); ?>">
<meta property="og:video:type" content="video/mp4">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:site" content="@xb_bittensor">
<meta name="twitter:title" content="<?php echo h($page_title); ?>">
<meta name="twitter:description" content="<?php echo h($page_desc); ?>">
<meta name="twitter:image" content="<?php echo h($page_image); ?>">
<script type="application/ld+json">
<?php
$jsonld = array(
    '@context'    => 'https://schema.org',
    '@type'       => $detail_job ? 'VideoObject' : 'CollectionPage',
    'name'        => $page_title,
    'description' => $page_desc,
    'url'         => $page_url,
    'publisher'   => array('@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/'),
);
if ($detail_job) {
    $jsonld['thumbnailUrl'] = array($page_image);
    $jsonld['contentUrl'] = $page_video;
    $jsonld['embedUrl'] = $page_url;
    $jsonld['inLanguage'] = 'ja-JP';
    $jsonld['isFamilyFriendly'] = true;
    if (!empty($detail_job['created_at'])) {
        $ts = strtotime((string)$detail_job['created_at']);
        $jsonld['uploadDate'] = $ts ? date('c', $ts) : (string)$detail_job['created_at'];
    }
    $dur_sec = (int)($detail_job['duration_seconds'] ?? 0);
    if ($dur_sec > 0) {
        $jsonld['duration'] = 'PT' . intdiv($dur_sec, 60) . 'M' . ($dur_sec % 60) . 'S';
    }
}
echo json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
</script>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-BP0650KDFR');</script>
<script>
(function(){
    var s=document.createElement('script');
    s.src='https://kurage.exbridge.jp/simpletrack.php'
        +'?url='+encodeURIComponent(location.href)
        +'&ref='+encodeURIComponent(document.referrer);
    document.head.appendChild(s);
})();
</script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#fff;color:#222;font-family:-apple-system,'Helvetica Neue',sans-serif;font-size:14px;}

/* ── ヘッダー ── */
.header{background:rgba(255,255,255,.96);border-bottom:1px solid #e5e7eb;padding:.85rem 1.4rem;position:sticky;top:0;z-index:100;display:flex;justify-content:space-between;align-items:center;box-shadow:0 1px 4px rgba(19,35,41,.06);}
.brand{display:flex;align-items:center;gap:.65rem;}
.brand-icon{width:44px;height:44px;border-radius:50%;object-fit:cover;box-shadow:0 2px 8px rgba(0,127,150,.18);}
.brand-logo{font-weight:900;font-size:1.08rem;text-decoration:none;color:#111;display:block;line-height:1.15;}
.brand-logo span{color:#007f96;}
.brand-sub{display:block;font-size:.72rem;color:#888;margin-top:.18rem;}
.header-right{display:flex;align-items:center;gap:8px;}
.amazon-mini{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,#ffb84d,#ff9900);border:1px solid #e58a00;color:#1f2933;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;font-weight:1000;font-size:18px;font-family:Georgia,serif;line-height:1;box-shadow:0 8px 18px rgba(255,153,0,.28);transform:rotate(-2deg);transition:transform .15s,box-shadow .15s;}
.amazon-mini:hover{transform:rotate(2deg) translateY(-1px);box-shadow:0 10px 22px rgba(255,153,0,.38);}
.back-btn{font-size:13px;color:#007f96;text-decoration:none;padding:5px 12px;border:1px solid #007f96;border-radius:6px;}
.back-btn:hover{background:#e0f5f8;}
.reel-btn{background:#007f96;color:#fff;font-size:12px;padding:5px 14px;border:none;border-radius:6px;cursor:pointer;white-space:nowrap;}
.reel-btn:hover{background:#006880;}
.gen-link{font-size:12px;color:#007f96;text-decoration:none;padding:5px 12px;border:1px solid #007f96;border-radius:6px;white-space:nowrap;}
.kv-link.danger{background:#fff0f0;border-color:#fca5a5;color:#dc2626;}
.kv-link.danger:hover{background:#fee2e2;}
.user-tag{font-size:12px;color:#888;margin-left:4px;}
.gen-link:hover{background:#e0f5f8;}

/* ── コンテナ ── */
.container{max-width:640px;margin:0 auto;padding:0 0 80px;}
.count-bar{padding:10px 20px;font-size:13px;color:#888;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;}
.sorts{display:flex;gap:6px;align-items:center;}
.sort-link{border:1px solid #d6e3e8;border-radius:999px;padding:5px 10px;color:#53636b;text-decoration:none;font-size:12px;font-weight:800;background:#fff;}
.sort-link.active{background:#007f96;border-color:#007f96;color:#fff;}
.search-panel{padding:14px 20px;border-bottom:1px solid #f0f0f0;background:linear-gradient(180deg,#fff,#fbfeff);}
.search-form{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:8px;align-items:center;}
.search-input{width:100%;border:1px solid #cbdde3;border-radius:14px;background:#fff;padding:10px 12px;font-size:14px;outline:none;color:#1f2933;}
.search-input:focus{border-color:#007f96;box-shadow:0 0 0 4px rgba(0,127,150,.1);}
.search-btn,.search-clear{border:1px solid #007f96;border-radius:14px;padding:10px 14px;font-size:13px;font-weight:900;text-decoration:none;white-space:nowrap;font-family:inherit;}
.search-btn{background:#007f96;color:#fff;cursor:pointer;}
.search-clear{background:#fff;color:#007f96;}
.search-hint{margin-top:8px;color:#64748b;font-size:12px;line-height:1.55;}
.tool-filter{padding:12px 20px;border-bottom:1px solid #f0f0f0;background:linear-gradient(180deg,#fbfeff,#fff);}
.all-videos-index{margin:14px 20px 20px;border:1px solid #e6eef1;border-radius:10px;background:#fbfeff;font-size:12.5px;color:#53636b;}
.all-videos-index summary{cursor:pointer;padding:10px 14px;font-weight:900;color:#64748b;}
.all-videos-index ol{margin:0;padding:4px 14px 12px 34px;max-height:320px;overflow:auto;}
.all-videos-index li{margin:3px 0;line-height:1.5;}
.all-videos-index a{color:#1c7990;text-decoration:none;}
.all-videos-index a:hover{text-decoration:underline;}
.tool-filter-title{font-size:11px;color:#64748b;font-weight:900;letter-spacing:.04em;margin-bottom:8px;}
.tool-filter-list{display:flex;gap:8px;overflow-x:auto;padding-bottom:2px;-webkit-overflow-scrolling:touch;}
.tool-filter-chip{display:inline-flex;align-items:center;gap:7px;border:1px solid #d6e3e8;border-radius:999px;background:#fff;color:#53636b;text-decoration:none;padding:7px 12px;font-size:12px;font-weight:900;white-space:nowrap;}
.tool-filter-chip::before{content:"";width:8px;height:8px;border-radius:50%;background:#b9cbd2;}
.tool-filter-chip.active{background:#007f96;border-color:#007f96;color:#fff;box-shadow:0 8px 18px rgba(0,127,150,.18);}
.tool-filter-chip.active::before{background:#fff;}
.tool-badge{display:inline-flex;align-items:center;width:max-content;border:1px solid #b2dde8;background:#e8f8fb;color:#007f96;border-radius:999px;padding:3px 8px;font-size:11px;font-weight:900;margin-bottom:6px;}
.views{display:inline-flex;align-items:center;gap:4px;color:#007f96;font-weight:900;}
@media (max-width:640px){.tool-filter{padding-right:0;}.tool-filter-list{padding-right:20px;}.search-form{grid-template-columns:1fr auto;}.search-clear{grid-column:1 / -1;text-align:center;}}

/* ── カード ── */
.post-card{border-bottom:1px solid #f0f0f0;padding:20px;transition:background .15s;}
.post-card:hover{background:#fafafa;}
.post-meta{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.avatar{width:40px;height:40px;background:linear-gradient(135deg,#007f96,#00bcd4);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;color:#fff;flex-shrink:0;}
.post-title{display:block;font-weight:700;color:#111;font-size:14px;margin-bottom:2px;cursor:pointer;text-decoration:none;}
.post-title:hover{text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:3px;}
.post-author{color:#888;font-size:12px;}
.post-time{color:#aaa;font-size:12px;margin-left:auto;white-space:nowrap;}
.tweet-block{background:#e8f8fb;border-left:3px solid #007f96;border-radius:0 8px 8px 0;padding:10px 14px;margin-bottom:10px;font-size:13px;line-height:1.7;color:#444;white-space:pre-wrap;max-height:72px;overflow:hidden;position:relative;cursor:pointer;}
.tweet-block::after{content:'';position:absolute;bottom:0;left:0;right:0;height:24px;background:linear-gradient(transparent,#e8f8fb);pointer-events:none;}
.card-links{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.card-video-wrap{position:relative;width:80px;height:142px;flex-shrink:0;border-radius:8px;overflow:hidden;background:#000;cursor:pointer;}
.card-video-wrap img{width:100%;height:100%;object-fit:cover;display:block;}
.card-video-play{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.35);font-size:22px;transition:background .15s;}
.card-video-wrap:hover .card-video-play{background:rgba(0,0,0,.15);}
.card-video-wrap.playing .card-video-play{display:none;}
.card-content{flex:1;min-width:0;}
.kv-link{display:inline-flex;align-items:center;gap:5px;background:#f5f5f5;border:1px solid #e5e7eb;border-radius:8px;padding:5px 11px;text-decoration:none;color:#555;font-size:12px;cursor:pointer;transition:all .15s;font-family:inherit;}
.kv-link:hover{background:#e0f5f8;border-color:#007f96;color:#007f96;}
.kv-link.primary{background:#e0f5f8;border-color:#b2dde8;color:#007f96;}
.kv-link.primary:hover{background:#c4e8f0;}

/* ── 空の状態 ── */
.empty{text-align:center;color:#bbb;padding:80px 20px;font-size:15px;}
.empty a{color:#007f96;text-decoration:none;}

/* ── 詳細ページ ── */
.detail-header{padding:24px 20px 16px;border-bottom:1px solid #f0f0f0;}
.detail-title{font-size:22px;line-height:1.35;margin:0 0 12px;color:#111;font-weight:900;}
.detail-meta{font-size:13px;color:#888;display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px;}
.detail-body{padding:20px;}
.section-title{font-size:12px;font-weight:700;color:#007f96;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;margin-top:20px;}
.tweet-body{background:#e8f8fb;border-left:3px solid #007f96;border-radius:0 8px 8px 0;padding:14px 16px;font-size:14px;line-height:1.8;color:#333;white-space:pre-wrap;margin-bottom:8px;}
.video-wrap{width:100%;max-width:320px;margin:0 auto 4px;background:#000;border-radius:12px;overflow:hidden;aspect-ratio:9/16;}
.video-wrap video{width:100%;height:100%;object-fit:contain;display:block;}
.scene-list{display:flex;flex-direction:column;gap:8px;}
.scene-row{background:#e8f8fb;border-radius:8px;padding:10px 14px;}
.scene-idx{font-size:11px;color:#007f96;font-weight:700;margin-bottom:4px;}
.narration{font-size:14px;color:#222;line-height:1.5;margin-bottom:4px;}
.image-prompt{font-size:11px;color:#999;font-style:italic;}
.detail-url-box{background:#f7f7f7;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:13px;color:#555;word-break:break-all;}
.detail-url-box a{color:#007f96;}
.action-row{margin-top:20px;display:flex;gap:8px;flex-wrap:wrap;}
.btn-primary{background:#007f96;color:#fff;border:none;border-radius:8px;padding:10px 20px;font-size:14px;cursor:pointer;font-family:inherit;}
.btn-primary:hover{background:#006880;}

/* ── リールオーバーレイ ── */
.reel-overlay{display:none;position:fixed;inset:0;z-index:500;background:#000;}
.reel-overlay.open{display:flex;align-items:stretch;justify-content:center;}
.reel-close{position:fixed;top:14px;right:14px;z-index:600;background:rgba(0,0,0,.6);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:20px;padding:7px 16px;font-size:13px;cursor:pointer;backdrop-filter:blur(4px);}
.reel-feed{width:100%;max-width:420px;height:100dvh;overflow-y:scroll;scroll-snap-type:y mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none;}
.reel-feed::-webkit-scrollbar{display:none;}
.reel-slide{position:relative;height:100dvh;scroll-snap-align:start;background:#000;display:flex;align-items:center;justify-content:center;overflow:hidden;}
.reel-slide video{width:100%;height:100%;object-fit:contain;display:block;}
.reel-grad{position:absolute;inset:0;background:linear-gradient(to bottom,rgba(0,0,0,.2) 0%,transparent 30%,transparent 55%,rgba(0,0,0,.75) 100%);pointer-events:none;}
.reel-info{position:absolute;bottom:0;left:0;right:60px;padding:12px 14px calc(env(safe-area-inset-bottom,0px) + 44px);}
.reel-title{font-size:14px;font-weight:700;color:#fff;margin-bottom:4px;line-height:1.4;}
.reel-author{font-size:12px;color:rgba(255,255,255,.6);}
.reel-side{position:absolute;right:8px;bottom:calc(env(safe-area-inset-bottom,0px) + 48px);display:flex;flex-direction:column;gap:12px;align-items:center;}
.reel-side-btn{background:rgba(0,0,0,.5);border:none;border-radius:50%;width:44px;height:44px;color:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:1px;backdrop-filter:blur(4px);text-decoration:none;}
.reel-side-btn span{font-size:9px;color:rgba(255,255,255,.65);}
</style>
<link rel="stylesheet" href="assets/kurage-avatar.css?v=20260704a">
</head>
<body>

<!-- ── ヘッダー ── -->
<header class="header">
  <div class="brand">
    <span class="kurage-avatar-stage kurage-avatar-mini" role="img" aria-label="Kurage avatar"><span class="kurage-avatar-motion"><span class="kurage-avatar-breath"><img class="kurage-avatar-frame kurage-avatar-frame-0" src="avatar/lipsync/kurage_mouth_0.png" alt=""><img class="kurage-avatar-frame kurage-avatar-frame-1" src="avatar/lipsync/kurage_mouth_1.png" alt=""><img class="kurage-avatar-frame kurage-avatar-frame-2" src="avatar/lipsync/kurage_mouth_2.png" alt=""><img class="kurage-avatar-frame kurage-avatar-frame-3" src="avatar/lipsync/kurage_mouth_3.png" alt=""><img class="kurage-avatar-frame kurage-avatar-frame-4" src="avatar/lipsync/kurage_mouth_4.png" alt=""></span></span></span>
    <a class="brand-logo" href="kuragev.php">
      <span>Kurageプロジェクト</span>
      <span class="brand-sub">AI Short Video</span>
    </a>
  </div>
  <div class="header-right">
    <a class="amazon-mini" href="<?php echo h($header_amazon_url); ?>" target="_blank" rel="sponsored nofollow noopener" aria-label="Amazonで関連商品を見る" title="Amazonで関連商品を見る">a</a>
    <a href="kurage_knowledge.php" class="gen-link">知識</a>
    <?php if ($detail_job): ?>
    <a class="back-btn" href="<?php echo h($THIS_FILE); ?>">← 一覧</a>
    <?php else: ?>
    <?php if (!empty($videos)): ?>
    <button class="reel-btn" onclick="openReel(0)">🎬 リール表示</button>
    <?php endif; ?>
    <?php if ($is_admin): ?><a href="kurage.php" class="gen-link">＋ 生成</a><?php endif; ?>
    <?php if ($logged_in): ?>
    <span class="user-tag">@<?php echo h($session_user); ?> <a href="?logout=1" style="color:#007f96;font-size:11px;">logout</a></span>
    <?php else: ?>
    <a href="?login=1" class="gen-link">ログイン</a>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</header>

<?php if ($detail_job): ?>
<!-- ============ 詳細ページ ============ -->
<?php
$detail_source_url = job_source_url($detail_job);
$detail_body_text = job_body_text($detail_job);
?>
<div class="container">
  <div class="detail-header">
    <h1 class="detail-title"><?php echo h(job_display_title($detail_job)); ?></h1>
    <div class="detail-meta">
      <span><?php echo h($detail_job['created_at'] ?? ''); ?></span>
      <span class="views">表示<?php echo h((string)($detail_job['views'] ?? 0)); ?></span>
    </div>
    <?php if ($detail_source_url !== ''): ?>
    <div class="detail-url-box">
      <?php echo h(job_source_label($detail_job)); ?>
      <a href="<?php echo h($detail_source_url); ?>" target="_blank" rel="noopener"><?php echo h($detail_source_url); ?></a>
    </div>
    <?php endif; ?>
  </div>
  <div class="detail-body">

    <div class="video-wrap">
      <video src="<?php echo h($page_video); ?>"
             poster="<?php echo h($page_image); ?>"
             controls playsinline preload="metadata"></video>
    </div>

    <?php if ($detail_body_text !== ''): ?>
    <div class="section-title">📣 <?php echo h(job_body_label($detail_job)); ?></div>
    <div class="tweet-body"><?php echo h($detail_body_text); ?></div>
    <?php endif; ?>

    <?php
    $scenes = (!empty($detail_job['script']['scenes'])) ? $detail_job['script']['scenes'] : [];
    if ($scenes):
    ?>
    <div class="section-title">🎬 脚本</div>
    <div class="scene-list">
      <?php foreach ($scenes as $si => $sc): ?>
      <div class="scene-row">
        <div class="scene-idx">シーン <?php echo $si + 1; ?></div>
        <div class="narration"><?php echo h($sc['narration'] ?? ''); ?></div>
        <div class="image-prompt"><?php echo h($sc['image_prompt'] ?? ''); ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php $article_url = related_article_url($detail_job); ?>
    <?php if ($article_url !== ''): ?>
    <div class="detail-url-box" style="margin-top:14px">
      この動画の詳しい考察記事:
      <a href="<?php echo h($article_url); ?>" target="_blank" rel="noopener"><?php echo h($article_url); ?></a>
    </div>
    <?php endif; ?>

    <?php
    $share_url_d = $BASE_URL . '/' . $THIS_FILE . '?id=' . urlencode($detail_id);
    $copy_d      = copy_text_for_job($detail_job, $share_url_d);
    $x_text_d    = urlencode($copy_d);
    ?>
    <div class="action-row">
      <button id="detail-copy-btn" class="btn-primary"
              data-text="<?php echo h($copy_d); ?>">📋 コピー</button>
      <a class="kv-link" href="https://twitter.com/intent/tweet?text=<?php echo $x_text_d; ?>"
         target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" style="width:13px;height:13px;fill:currentColor;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
        Xに投稿
      </a>
      <?php if ($detail_source_url !== ''): ?>
      <a class="kv-link" href="<?php echo h($detail_source_url); ?>" target="_blank" rel="noopener">
        🔗 <?php echo h(job_source_button_label($detail_job)); ?>
      </a>
      <?php endif; ?>
      <?php if ($is_admin): ?>
      <form method="post" style="display:inline;" onsubmit="return confirm('この動画を削除しますか？')">
        <input type="hidden" name="delete_job" value="<?php echo h($detail_id); ?>">
        <button type="submit" class="kv-link danger">🗑 削除</button>
      </form>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php else: ?>
<!-- ============ 一覧ページ ============ -->
<div class="container">
  <div class="count-bar">
    <span>
      <?php echo count($videos); ?> / <?php echo (int)($search_query !== '' || $tool_filter !== '' ? $total_filtered_before_search : $total_done_videos); ?> 件の動画
      <?php if ($search_query !== ''): ?>（検索: <?php echo h($search_query); ?>）<?php endif; ?>
    </span>
    <span class="sorts">
      <a class="sort-link <?php echo $sort === 'created' ? 'active' : ''; ?>" href="<?php echo h(current_query_url(array('sort' => 'created'))); ?>">作成日順</a>
      <a class="sort-link <?php echo $sort === 'views' ? 'active' : ''; ?>" href="<?php echo h(current_query_url(array('sort' => 'views'))); ?>">表示回数順</a>
    </span>
  </div>
  <div class="search-panel" aria-label="動画をキーワード検索">
    <form class="search-form" method="get" action="<?php echo h($THIS_FILE); ?>">
      <?php if ($tool_filter !== ''): ?><input type="hidden" name="tool" value="<?php echo h($tool_filter); ?>"><?php endif; ?>
      <?php if ($sort !== 'created'): ?><input type="hidden" name="sort" value="<?php echo h($sort); ?>"><?php endif; ?>
      <input class="search-input" type="search" name="q" value="<?php echo h($search_query); ?>" placeholder="タイトル・説明・元URLで検索" aria-label="キーワード検索">
      <button class="search-btn" type="submit">検索</button>
      <?php if ($search_query !== ''): ?><a class="search-clear" href="<?php echo h(current_query_url(array('q' => null))); ?>">クリア</a><?php endif; ?>
    </form>
    <div class="search-hint">タイトル、動画説明、元URL、生成ツール、job_id を検索します。</div>
  </div>
  <div class="tool-filter" aria-label="生成ツールで絞り込み">
    <div class="tool-filter-title">生成ツール</div>
    <div class="tool-filter-list">
      <a class="tool-filter-chip <?php echo $tool_filter === '' ? 'active' : ''; ?>" href="<?php echo h(current_query_url(array('tool' => null))); ?>">すべて</a>
      <?php foreach ($tool_options as $key => $label): ?>
      <a class="tool-filter-chip <?php echo $tool_filter === $key ? 'active' : ''; ?>" href="<?php echo h(current_query_url(array('tool' => $key))); ?>"><?php echo h($label); ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div id="post-list"></div>
  <div id="load-sentinel" style="height:1px;"></div>
  <div id="load-indicator" style="display:none;text-align:center;padding:16px;font-size:13px;color:#888;">読み込み中…</div>
  <?php if (!empty($videos)): ?>
  <?php /* カードはJS描画のため、初期HTMLにクローラが辿れる内部リンクが存在しない。
           動画ページのインデックス登録にはHTML上の内部リンクが必要なので、
           全動画へのテキストリンク一覧をサーバ側で出力する（Google動画SEO対応） */ ?>
  <details class="all-videos-index">
    <summary>全動画リンク一覧（<?php echo count($videos); ?>件）</summary>
    <ol>
      <?php foreach ($videos as $v): ?>
      <?php $vid = preg_replace('/[^a-zA-Z0-9]/', '', (string)($v['job_id'] ?? '')); if ($vid === '') { continue; } ?>
      <li><a href="<?php echo h($THIS_FILE . '?id=' . rawurlencode($vid)); ?>"><?php echo h(job_display_title($v)); ?></a></li>
      <?php endforeach; ?>
    </ol>
  </details>
  <?php endif; ?>
</div>

<?php if (!empty($videos)): ?>
<!-- ============ リールオーバーレイ ============ -->
<div class="reel-overlay" id="reel-overlay">
  <button class="reel-close" onclick="closeReel()">✕ 一覧に戻る</button>
  <div class="reel-feed" id="reel-feed"></div>
</div>
<?php endif; ?>

<?php endif; ?>

<script>
/* ──────────────────────────────────────────
   一覧データ（PHPから）
────────────────────────────────────────── */
var kvVideos = <?php echo json_encode(array_values($client_videos), JSON_UNESCAPED_UNICODE); ?>;
var IS_ADMIN = <?php echo $is_admin ? 'true' : 'false'; ?>;
var SEARCH_QUERY = <?php echo json_encode($search_query, JSON_UNESCAPED_UNICODE); ?>;
var PAGE_SIZE = 20;
var curPage = 0;

var X_SVG = '<svg viewBox="0 0 24 24" style="width:13px;height:13px;fill:currentColor;vertical-align:middle;"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>';
var LINK_ICON = '🔗';

function isVoiceProJob(v) {
    return (v.source === 'kuragevp') || (v.content_type === 'voice_pro_translation') || !!v.kuragevp_job_id;
}

function isEntertainmentJob(v) {
    return (v.source === 'entertainment') || (v.content_type === 'entertainment_short') || String(v.tweet_url || '').indexOf('/entertainment.php') !== -1;
}

function toolLabelForJob(v) {
    var source = String(v.source || '').toLowerCase();
    var contentType = String(v.content_type || '').toLowerCase();
    if (isVoiceProJob(v)) return 'Kurage Voice Pro';
    if (source === 'kmontage') return 'Kurage Montage';
    if (source === 'klofi') return 'Kurage Lo-Fi';
    if (source === 'blog' || contentType === 'blog') return 'Kurage Blog';
    if (source === 'horizon') return 'Horizon';
    if (isEntertainmentJob(v)) return 'Kurage Entertainment';
    if (v.tool_label) return String(v.tool_label);
    return 'Kurage';
}

function toolHomeUrlForJob(v) {
    var source = String(v.source || '').toLowerCase();
    var contentType = String(v.content_type || '').toLowerCase();
    if (isVoiceProJob(v)) return 'kuragevp.php';
    if (source === 'kmontage') return 'kmontage.php';
    if (source === 'klofi') return 'klofi.php';
    if (source === 'horizon') return 'horizon.php';
    if (isEntertainmentJob(v)) return 'entertainment.php';
    if (source === 'blog' || contentType === 'blog') return 'kurage.php';
    return '';
}

function isLocalSourcePath(url) {
    url = String(url || '').trim();
    return !url || url.indexOf('/home/') === 0 || url.indexOf('file:') === 0;
}

function sourceUrlForJob(v) {
    var keys = ['source_url', 'original_url', 'tweet_url'];
    for (var i = 0; i < keys.length; i++) {
        var url = String(v[keys[i]] || '').trim();
        if (url && !isLocalSourcePath(url)) return url;
    }
    return '';
}

function sourcePlatformForJob(v) {
    var platform = String(v.source_platform || '').toLowerCase();
    var url = sourceUrlForJob(v).toLowerCase();
    if (platform === 'youtube' || url.indexOf('youtube.com') !== -1 || url.indexOf('youtu.be') !== -1) return 'youtube';
    if (platform === 'x' || platform === 'twitter' || url.indexOf('x.com') !== -1 || url.indexOf('twitter.com') !== -1) return 'x';
    return platform || 'video';
}

function sourceButtonLabel(v) {
    if (isEntertainmentJob(v)) return '考察記事を開く';
    if (isVoiceProJob(v)) {
        var platform = sourcePlatformForJob(v);
        if (platform === 'youtube') return '元のYouTube動画';
        if (platform === 'x') return '元のX投稿';
        return '元動画を開く';
    }
    return '元の投稿を開く';
}

function displayTitleForJob(v) {
    var keys = ['display_title', 'summary_title', 'article_title', 'title', 'source_title'];
    for (var i = 0; i < keys.length; i++) {
        var title = String(v[keys[i]] || '').trim();
        if (title) return title;
    }
    return 'Kurage動画';
}

function voiceProLabelForJob(v) {
    var label = String(v.voice_pro_label || '').trim();
    if (label) return label;
    var title = displayTitleForJob(v);
    var m = title.match(/\[([^\]]*(?:Dub|Subtitles)[^\]]*)\]/i);
    if (m) return m[1].trim();
    m = title.match(/【([^】]*(?:字幕|吹替)[^】]*)】/);
    if (m) return m[1].trim();
    var lang = String(v.target_lang || '').toLowerCase();
    var mode = String(v.audio_mode || '');
    if (lang.indexOf('en') === 0) return mode === 'subtitle_only' ? 'English Subtitles' : 'English Dub/Subtitles';
    if (lang.indexOf('ja') === 0) return mode === 'subtitle_only' ? '日本語字幕' : '日本語吹替・日本語字幕';
    return mode === 'subtitle_only' ? '翻訳字幕' : '翻訳吹替・字幕';
}

function bodyTextForJob(v) {
    if (isVoiceProJob(v)) {
        var keys = ['display_summary', 'summary', 'tweet_text', 'source_title'];
        for (var i = 0; i < keys.length; i++) {
            var summary = String(v[keys[i]] || '').trim();
            if (summary) return summary;
        }
    }
    return String(v.tweet_text || '').trim();
}

function normalizeCopyCompareText(text) {
    return String(text || '').trim().replace(/\s+/g, '');
}

function copyDetailCandidate(title, text) {
    title = String(title || '').trim();
    text = String(text || '').replace(/\uFFFD/g, '').trim();
    if (text && text.endsWith('」') && text.indexOf('「') === -1) text = '「' + text;
    if (text && text.endsWith('』') && text.indexOf('『') === -1) text = '『' + text;
    if (!text) return '';
    var titleNorm = normalizeCopyCompareText(title);
    var textNorm = normalizeCopyCompareText(text);
    if (titleNorm && textNorm === titleNorm) return '';
    if (title && text.indexOf(title) === 0) {
        var trimmed = text.slice(title.length).replace(/^[\s:：\-ー|｜。.\n\r]+/, '').trim();
        if (trimmed) return trimmed;
    }
    return text;
}

function listTextExcerpt(text, limit) {
    limit = limit || 240;
    text = String(text || '').trim().replace(/\s+/g, ' ');
    return text.length > limit ? text.slice(0, limit) + '…' : text;
}

function copyDetailTextForJob(v) {
    var title = displayTitleForJob(v);
    var keys = isVoiceProJob(v)
        ? ['copy_summary', 'primary_description', 'translated_text', 'summary', 'tweet_text']
        : ['display_summary', 'summary', 'tweet_text', 'source_title', 'translated_text'];
    for (var i = 0; i < keys.length; i++) {
        var candidate = copyDetailCandidate(title, v[keys[i]]);
        if (candidate) return listTextExcerpt(candidate);
    }
    return '詳細は動画ページで確認できます。';
}

function shortenShareDetail(text, limit) {
    limit = limit || 90;
    text = String(text || '').trim().replace(/\s+/g, ' ');
    return text.length > limit ? text.slice(0, limit) + '…' : text;
}

function copyTextForJob(v, shareUrl) {
    var voiceProNote = isVoiceProJob(v) ? '\nKurage Voice Pro: ' + voiceProLabelForJob(v) : '';
    return 'タイトル:\n' + displayTitleForJob(v)
        + voiceProNote
        + '\n\n詳細:\n' + copyDetailTextForJob(v)
        + '\n\nURL:\n' + shareUrl;
}

function primeThumbVideos(root) {
    // Thumbnails are static images on the list page. Avoid loading video metadata for speed.
}

function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderCards(from, to) {
    var list = document.getElementById('post-list');
    if (!list) return;
    for (var i = from; i < to && i < kvVideos.length; i++) {
        var v      = kvVideos[i];
        var jid    = v.job_id     || '';
        var title  = displayTitleForJob(v);
        var author = v.tweet_author || '';
        var tweet  = bodyTextForJob(v);
        var tool   = toolLabelForJob(v);
        var turl   = sourceUrlForJob(v);
        var date   = v.created_at || '';
        var views  = Number.isFinite(Number(v.views)) ? Number(v.views) : 0;
        var av     = author ? author.replace(/^@/, '').charAt(0).toUpperCase() : '🪼';

        var shareUrl  = '<?php echo $BASE_URL . '/' . $THIS_FILE; ?>?id=' + encodeURIComponent(jid);
        var copyText  = copyTextForJob(v, shareUrl);
        var xText     = encodeURIComponent(copyText);

        var tweetHtml = tweet
            ? '<div class="tweet-block">' + esc(tweet) + '</div>'
            : '';

        var turlBtn = turl
            ? '<a class="kv-link" href="' + esc(turl) + '" target="_blank" rel="noopener">' + LINK_ICON + '&nbsp;' + esc(sourceButtonLabel(v)) + '</a>'
            : '';

        var thumbVer = encodeURIComponent(v.created_at || '1');
        var thumbSrc = 'kuragev.php?proxy=thumbnail&job_id=' + encodeURIComponent(jid) + '&v=' + thumbVer;
        var detailUrl = 'kuragev.php?id=' + encodeURIComponent(jid);
        var titleHtml = '<a class="post-title" href="' + detailUrl + '">' + esc(title) + '</a>';
        var html = '<div class="post-card" data-detail-url="' + detailUrl + '">'
            + '<div style="display:flex;gap:12px;align-items:flex-start;">'
            + '<div class="card-video-wrap" data-jid="' + esc(jid) + '" data-detail-url="' + detailUrl + '" title="詳細を見る">'
            + '<img class="thumb-img" src="' + thumbSrc + '" loading="lazy" decoding="async" alt="">'
            + '<div class="card-video-play">▶</div>'
            + '</div>'
            + '<div class="card-content">'
            + '<div class="tool-badge">' + esc(tool) + '</div>'
            + '<div class="post-meta" style="margin-bottom:6px;">'
            + titleHtml
            + '<div class="post-time">' + esc(date) + '<br><span class="views">表示' + esc(views) + '</span></div>'
            + '</div>'
            + tweetHtml
            + '<div class="card-links">'
            + '<a class="kv-link primary" href="' + detailUrl + '">📄 詳細</a>'
            + '<button class="kv-link reel-open-btn" data-idx="' + i + '">🎬 リール</button>'
            + '<button class="kv-link kv-copy-btn" data-text="' + esc(copyText) + '">📋 コピー</button>'
            + '<a class="kv-link" href="https://twitter.com/intent/tweet?text=' + xText + '" target="_blank" rel="noopener">' + X_SVG + '&nbsp;Xに投稿</a>'
            + turlBtn
            + (IS_ADMIN ? '<button class="kv-link danger kv-delete-btn" data-jid="' + esc(jid) + '">🗑</button>' : '')
            + '</div>'
            + '</div>'
            + '</div>'
            + '</div>';
        list.insertAdjacentHTML('beforeend', html);
    }
    curPage++;
    primeThumbVideos(list);
}

/* イベント委任（コピー・リール・詳細遷移） */
document.addEventListener('click', function(e) {
    var copyBtn = e.target.closest('.kv-copy-btn');
    if (copyBtn) {
        var text = copyBtn.dataset.text || '';
        kvCopyText(text, function() {
            copyBtn.textContent = '✓ コピー済';
            setTimeout(function() { copyBtn.textContent = '📋 コピー'; }, 2000);
        });
        return;
    }
    var detailCopyBtn = e.target.closest('#detail-copy-btn');
    if (detailCopyBtn) {
        kvCopyBtn(detailCopyBtn, detailCopyBtn.dataset.text || '');
        return;
    }
    var reelBtn = e.target.closest('.reel-open-btn');
    if (reelBtn) {
        openReel(parseInt(reelBtn.dataset.idx || '0', 10));
        return;
    }
    var delBtn = e.target.closest('.kv-delete-btn');
    if (delBtn && confirm('この動画を削除しますか？')) {
        var jid = delBtn.dataset.jid;
        fetch('kuragev.php', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'delete_job='+encodeURIComponent(jid)})
            .then(function(){ location.reload(); });
        return;
    }
    var detailTarget = e.target.closest('.card-video-wrap, .post-title, .tweet-block');
    if (detailTarget && !e.target.closest('a,button,form,input,textarea,select')) {
        var card = detailTarget.closest('.post-card');
        var detailUrl = (detailTarget.dataset && detailTarget.dataset.detailUrl) || (card && card.dataset.detailUrl);
        if (detailUrl) {
            window.location.href = detailUrl;
            return;
        }
    }
});

/* 無限スクロール */
var sentinel = document.getElementById('load-sentinel');
if (sentinel) {
    var obs = new IntersectionObserver(function(entries) {
        if (entries[0].isIntersecting) {
            var from = curPage * PAGE_SIZE;
            if (from < kvVideos.length) {
                renderCards(from, from + PAGE_SIZE);
            }
        }
    }, { rootMargin: '200px' });
    obs.observe(sentinel);
}

if (kvVideos.length === 0) {
    var pl = document.getElementById('post-list');
    if (pl) {
        pl.className = 'empty';
        pl.textContent = SEARCH_QUERY ? '検索条件に一致する動画がありません。' : 'まだ動画がありません。';
    }
} else {
    renderCards(0, PAGE_SIZE);
}

/* ── コピーヘルパー（詳細ページ用） ── */
function kvCopyText(text, onSuccess) {
    function done() {
        if (typeof onSuccess === 'function') onSuccess();
    }
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(done).catch(function() {
            kvFallbackCopy(text);
            done();
        });
        return;
    }
    kvFallbackCopy(text);
    done();
}
function kvFallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    ta.style.top = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
}
function kvCopyBtn(btn, text) {
    kvCopyText(text, function() {
        var orig = btn.textContent;
        btn.textContent = '✓ コピー済';
        btn.style.background = '#059669';
        setTimeout(function() { btn.textContent = orig; btn.style.background = '#007f96'; }, 2000);
    });
}

/* ──────────────────────────────────────────
   リールオーバーレイ
────────────────────────────────────────── */
var reelMuted   = true;
var reelCurrent = 0;
var reelSlides  = [];
var reelObs     = null;
var reelTimers  = new WeakMap();
var reelReady   = false;

function reelSlideHtml(v) {
    var jid = v.job_id || '';
    var title = displayTitleForJob(v);
    var shareUrl = '<?php echo $BASE_URL . '/' . $THIS_FILE; ?>?id=' + encodeURIComponent(jid);
    var copyText = copyTextForJob(v, shareUrl);
    var xText = encodeURIComponent(copyText);
    var videoSrc = 'kuragev.php?proxy=video&job_id=' + encodeURIComponent(jid);
    var thumbVer = encodeURIComponent(v.created_at || '1');
    var thumbSrc = 'kuragev.php?proxy=thumbnail&job_id=' + encodeURIComponent(jid) + '&v=' + thumbVer;
    var detailUrl = 'kuragev.php?id=' + encodeURIComponent(jid);
    return '<div class="reel-slide" data-job="' + esc(jid) + '">'
        + '<video src="' + videoSrc + '" poster="' + thumbSrc + '" playsinline muted loop preload="none"></video>'
        + '<div class="reel-grad"></div>'
        + '<div class="reel-info"><div class="reel-title">' + esc(title) + '</div></div>'
        + '<div class="reel-side">'
        + '<button class="reel-side-btn reel-mute-btn" onclick="reelMuteToggle()">🔇<span>音声</span></button>'
        + '<button class="reel-side-btn reel-copy-btn" data-text="' + esc(copyText) + '">📋<span>コピー</span></button>'
        + '<a class="reel-side-btn" style="text-decoration:none;" href="https://twitter.com/intent/tweet?text=' + xText + '" target="_blank" rel="noopener">𝕏<span>投稿</span></a>'
        + '<a class="reel-side-btn" style="text-decoration:none;" href="' + detailUrl + '">📄<span>詳細</span></a>'
        + '</div></div>';
}

function buildReelSlides() {
    var feed = document.getElementById('reel-feed');
    if (!feed || feed.dataset.built) return;
    feed.innerHTML = kvVideos.map(reelSlideHtml).join('');
    feed.dataset.built = '1';
}

function openReel(idx) {
    var overlay = document.getElementById('reel-overlay');
    if (!overlay) return;
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';

    if (!reelReady) {
        buildReelSlides();
        reelReady  = true;
        reelSlides = Array.from(overlay.querySelectorAll('.reel-slide'));

        /* コピーボタン */
        overlay.querySelectorAll('.reel-copy-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var text = btn.dataset.text || '';
                kvCopyText(text, function() {
                    btn.innerHTML = '✓<span>コピー済</span>';
                    setTimeout(function() { btn.innerHTML = '📋<span>コピー</span>'; }, 2000);
                });
            });
        });

        /* 自動再生 */
        var feed = document.getElementById('reel-feed');
        reelObs = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                var vid = entry.target.querySelector('video');
                if (!vid) return;
                if (entry.isIntersecting) {
                    reelCurrent = reelSlides.indexOf(entry.target);
                    var t = setTimeout(function() {
                        vid.muted = reelMuted;
                        vid.currentTime = 0;
                        vid.play().catch(function() { vid.muted = true; reelMuted = true; vid.play(); });
                    }, 500);
                    reelTimers.set(entry.target, t);
                } else {
                    var t2 = reelTimers.get(entry.target);
                    if (t2) { clearTimeout(t2); reelTimers.delete(entry.target); }
                    vid.pause();
                    vid.currentTime = 0;
                }
            });
        }, { root: feed, threshold: 0.75 });
        reelSlides.forEach(function(s) { reelObs.observe(s); });
    }

    if (idx >= 0 && idx < reelSlides.length) {
        setTimeout(function() {
            reelSlides[idx].scrollIntoView({ behavior: 'instant', block: 'start' });
        }, 50);
    }
}

function closeReel() {
    var overlay = document.getElementById('reel-overlay');
    if (!overlay) return;
    overlay.classList.remove('open');
    document.body.style.overflow = '';
    overlay.querySelectorAll('video').forEach(function(v) { v.pause(); v.currentTime = 0; });
}

function reelMuteToggle() {
    reelMuted = !reelMuted;
    var overlay = document.getElementById('reel-overlay');
    if (overlay) {
        overlay.querySelectorAll('video').forEach(function(v) { v.muted = reelMuted; });
        overlay.querySelectorAll('.reel-mute-btn').forEach(function(btn) {
            btn.innerHTML = (reelMuted ? '🔇' : '🔊') + '<span>音声</span>';
        });
    }
}

/* キーボード */
document.addEventListener('keydown', function(e) {
    var overlay = document.getElementById('reel-overlay');
    if (!overlay || !overlay.classList.contains('open')) return;
    if (e.key === 'Escape') { closeReel(); return; }
    if (e.key === 'ArrowDown' && reelSlides.length) {
        var next = reelSlides[reelCurrent + 1];
        if (next) next.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    if (e.key === 'ArrowUp' && reelSlides.length) {
        var prev = reelSlides[reelCurrent - 1];
        if (prev) prev.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
});

/* ?v= で直接リール表示 */
(function() {
    var params = new URLSearchParams(location.search);
    var v = params.get('v');
    if (!v) return;
    var overlay = document.getElementById('reel-overlay');
    if (!overlay) return;
    var idx = kvVideos.findIndex(function(job) { return String(job.job_id || '') === v; });
    if (idx >= 0) setTimeout(function() { openReel(idx); }, 200);
})();
</script>
<?php
$amazon_kw = trim((string)($detail_job['title'] ?? 'AI 動画生成 マイク 動画編集'));
$amazon_from = '/' . $THIS_FILE . ($detail_id ? ('?id=' . $detail_id) : '');
$amazon_cta_url = '/go.php?' . http_build_query(array(
    'to' => 'amazon',
    'kw' => $amazon_kw,
    'from' => $amazon_from,
));
?>
<footer class="affiliate-disclosure" style="max-width:1120px;margin:28px auto 18px;padding:16px;color:#647884;font-size:12px;line-height:1.7;text-align:center;">
  <div style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:center;border:1px solid #f2d39a;background:linear-gradient(135deg,#fffaf0,#fff);border-radius:999px;padding:9px 12px;box-shadow:0 10px 24px rgba(146,95,0,.08);">
    <span style="font-weight:900;color:#9a5b00;">Amazon Associate Partner</span>
    <span>この動画テーマに近い本・機材をAmazonで探せます。</span>
    <a href="<?php echo h($amazon_cta_url); ?>" target="_blank" rel="sponsored nofollow noopener" style="display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#ff9900;color:#1f2933;text-decoration:none;font-weight:900;padding:7px 12px;">Amazonで関連アイテムを見る</a>
  </div>
  <div style="margin-top:8px;">Amazonアソシエイトとして適格販売により収入を得ています。</div>
</footer>

</body>
</html>
