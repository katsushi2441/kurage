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

function normalize_search_text($text) {
    $text = (string)$text;
    $text = preg_replace('/https?:\/\/\S+/u', ' ', $text);
    $text = preg_replace('/[　\s]+/u', ' ', $text);
    $text = trim($text);
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function search_terms($text) {
    $text = normalize_search_text($text);
    preg_match_all('/[a-z0-9][a-z0-9+#._-]{1,}|[一-龥ぁ-んァ-ンー]{2,}/u', $text, $matches);
    $stop = array('これ','それ','動画','教えて','おすすめ','ください','について','したい','できる','知りたい','kurage','さん','方法','わかる');
    $terms = array();
    foreach (($matches[0] ?? array()) as $term) {
        $parts = preg_split('/(?:について|できる|したい|教えて|おすすめ|ください|なら|から|まで|より|では|には|で|を|が|は|に|の|と|へ|も|や)/u', $term);
        $parts[] = $term;
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || in_array($part, $stop, true)) { continue; }
            if (function_exists('mb_strlen') && mb_strlen($part, 'UTF-8') < 2) { continue; }
            $terms[$part] = true;
        }
    }
    $synonyms = array(
        '稼ぐ' => array('収益化','マネタイズ','副業','月商','売上'),
        '収益化' => array('稼ぐ','マネタイズ','副業','月商','売上'),
        'バイブコーディング' => array('vibe coding','claude code','codex','ai開発'),
        'youtube' => array('ショート','shorts','チャンネル'),
    );
    foreach (array_keys($terms) as $term) {
        foreach (($synonyms[$term] ?? array()) as $synonym) {
            $terms[$synonym] = true;
        }
    }
    return array_keys($terms);
}

function clip_text($text, $limit = 130) {
    $text = trim(preg_replace('/[　\s]+/u', ' ', (string)$text));
    if ($text === '') { return ''; }
    if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $limit) {
        return mb_substr($text, 0, $limit, 'UTF-8') . '...';
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

function score_text_for_query($text, $query, $terms) {
    $haystack = normalize_search_text($text);
    $score = 0;
    if ($query !== '' && strpos($haystack, $query) !== false) { $score += 18; }
    foreach ($terms as $term) {
        if ($term === '') { continue; }
        if (strpos($haystack, $term) !== false) {
            $score += function_exists('mb_strlen') ? max(2, min(10, mb_strlen($term, 'UTF-8'))) : max(2, min(10, strlen($term)));
        }
    }
    return $score;
}

function load_knowledge_topics($topics) {
    $loaded = array();
    foreach ((array)$topics as $topic) {
        $slug = preg_replace('/[^a-z0-9_-]/i', '', (string)($topic['slug'] ?? ''));
        $detail = $slug !== '' ? read_json_file(__DIR__ . '/storage/knowledge/topics/' . $slug . '.json') : null;
        $loaded[] = is_array($detail) ? $detail : $topic;
    }
    return $loaded;
}

function kurage_answer_for_question($topics, $question) {
    $question = trim((string)$question);
    $query = normalize_search_text($question);
    $terms = search_terms($question);
    $loaded_topics = load_knowledge_topics($topics);
    $videos = array();
    $matched_topics = array();

    foreach ($loaded_topics as $topic) {
        $topic_text = implode(' ', array(
            $topic['title'] ?? '',
            $topic['lead'] ?? '',
            $topic['editor_summary'] ?? '',
            implode(' ', (array)($topic['keywords'] ?? array())),
        ));
        $topic_score = score_text_for_query($topic_text, $query, $terms);
        if ($topic_score > 0) {
            $matched_topics[] = array(
                'slug' => $topic['slug'] ?? '',
                'title' => $topic['title'] ?? '',
                'score' => $topic_score,
            );
        }
        $topic_videos = array_merge((array)($topic['featured_videos'] ?? array()), (array)($topic['videos'] ?? array()));
        foreach ($topic_videos as $video) {
            $direct_text = implode(' ', array(
                $video['title'] ?? '',
                $video['excerpt'] ?? '',
                $video['source'] ?? '',
                $video['content_type'] ?? '',
            ));
            $direct_score = score_text_for_query($direct_text, $query, $terms);
            $score = ($direct_score * 3) + (int)round($topic_score * 0.35);
            $score += min(8, (int)floor(((int)($video['views'] ?? 0)) / 25));
            if ($query === '' || $direct_score > 0 || $topic_score > 0) {
                $jid = preg_replace('/[^a-zA-Z0-9]/', '', (string)($video['job_id'] ?? ''));
                if ($jid === '') { continue; }
                $video['score'] = $score;
                $video['topic_title'] = $topic['title'] ?? '';
                $video['topic_slug'] = $topic['slug'] ?? ($video['topic_slug'] ?? '');
                $videos[$jid] = isset($videos[$jid]) && ($videos[$jid]['score'] ?? 0) >= $score ? $videos[$jid] : $video;
            }
        }
    }

    usort($videos, function($a, $b) {
        $score_cmp = ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        if ($score_cmp !== 0) { return $score_cmp; }
        return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
    });
    usort($matched_topics, function($a, $b) { return ($b['score'] ?? 0) <=> ($a['score'] ?? 0); });

    $videos = array_slice(array_values($videos), 0, 12);
    if (!$videos && $loaded_topics) {
        foreach ($loaded_topics as $topic) {
            foreach (array_slice((array)($topic['featured_videos'] ?? array()), 0, 2) as $video) {
                $video['topic_title'] = $topic['title'] ?? '';
                $videos[] = $video;
            }
        }
        $videos = array_slice($videos, 0, 8);
    }

    $first_title = $videos[0]['title'] ?? '';
    $topic_name = $matched_topics[0]['title'] ?? ($videos[0]['topic_title'] ?? 'Kurage動画');
    $answer = $videos
        ? 'Kurageです。質問に近いテーマは「' . $topic_name . '」です。まずは「' . $first_title . '」から見ると流れをつかみやすいです。関連する動画を下に並べました。'
        : 'Kurageです。まだ質問にぴったり合う動画は少ないようです。近いテーマから増やしていきます。';

    foreach ($videos as &$video) {
        $jid = preg_replace('/[^a-zA-Z0-9]/', '', (string)($video['job_id'] ?? ''));
        $video['title'] = $video['title'] ?? 'Kurage動画';
        $video['excerpt'] = clip_text($video['excerpt'] ?? $video['summary'] ?? '', 160);
        $video['thumbnail_url'] = $video['thumbnail_url'] ?? ($jid ? 'thumbs/' . $jid . '.jpg' : '');
        $video['page_url'] = $video['page_url'] ?? ($jid ? 'kuragev.php?id=' . rawurlencode($jid) : 'kuragev.php');
        $video['views'] = (int)($video['views'] ?? 0);
        $video['created_at'] = $video['created_at'] ?? '';
        $video['source'] = $video['source'] ?? 'kurage';
    }
    unset($video);

    return array(
        'ok' => true,
        'question' => $question,
        'answer' => $answer,
        'videos' => $videos,
        'matched_topics' => array_slice($matched_topics, 0, 5),
    );
}

$data = read_json_file($DATA_FILE);
$topics = is_array($data['topics'] ?? null) ? $data['topics'] : [];
if (($_GET['api'] ?? '') === 'ask') {
    $payload = json_decode((string)file_get_contents('php://input'), true);
    $question = is_array($payload) ? (string)($payload['question'] ?? '') : (string)($_POST['question'] ?? '');
    header('Content-Type: application/json; charset=utf-8');
    $json_flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
    if (!$data || !$topics) {
        echo json_encode(array('ok' => false, 'error' => 'knowledge data is not ready'), $json_flags);
        exit;
    }
    echo json_encode(kurage_answer_for_question($topics, $question), $json_flags);
    exit;
}
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
.ask-card{max-width:1120px;margin:0 auto 18px;padding:0 24px}
.ask-inner{background:rgba(255,255,255,.94);border:1.5px solid var(--line);border-radius:28px;box-shadow:0 18px 54px rgba(42,168,199,.13);padding:20px;display:grid;grid-template-columns:130px minmax(0,1fr);gap:18px;align-items:center}
.ask-avatar{display:flex;align-items:center;justify-content:center;min-height:118px;background:linear-gradient(180deg,#f3fcff,#fff);border:1px solid #d8f1f6;border-radius:22px}
.ask-avatar .kurage-avatar-stage{width:92px;height:92px}
.ask-label{display:inline-flex;align-items:center;gap:7px;border:1px solid #bce9f1;background:#ecfbff;color:var(--accent2);border-radius:999px;padding:5px 11px;font-size:12px;font-weight:900;margin-bottom:8px}
.ask-title{font-size:24px;font-weight:900;margin-bottom:6px;letter-spacing:-.02em}
.ask-copy{font-size:13.5px;color:#45677c;line-height:1.75;margin-bottom:12px}
.ask-form{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:start}
.ask-input{width:100%;min-height:58px;resize:vertical;border:1.5px solid #c9eaf1;border-radius:18px;background:#fff;color:var(--ink);padding:13px 15px;font-size:14px;line-height:1.6;font-family:inherit;outline:none}
.ask-input:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(42,168,199,.12)}
.ask-submit{border:0;border-radius:18px;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#fff;font-size:14px;font-weight:900;padding:14px 20px;cursor:pointer;box-shadow:0 12px 24px rgba(42,168,199,.22);font-family:inherit;white-space:nowrap}
.ask-submit:disabled{opacity:.62;cursor:wait}
.ask-response{display:none;margin-top:14px;background:#f1fbfe;border:1px solid #c7edf4;border-radius:18px;padding:13px 15px;color:#2d596d;font-size:14px;line-height:1.8}
.ask-response.show{display:block}
.ask-reset{display:none;margin-top:10px;border:1px solid #c9eaf1;background:#fff;color:var(--accent2);border-radius:999px;padding:7px 13px;font-size:12px;font-weight:900;cursor:pointer;font-family:inherit}
.ask-reset.show{display:inline-flex}
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
.video-results{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.video-card{background:#fff;border:1.5px solid var(--line);border-radius:22px;padding:14px;box-shadow:0 12px 32px rgba(19,50,61,.06);display:grid;grid-template-columns:92px minmax(0,1fr);gap:13px;transition:transform .16s,box-shadow .16s}
.video-card:hover{transform:translateY(-2px);box-shadow:0 18px 42px rgba(42,168,199,.14)}
.video-thumb{position:relative;display:block;width:92px;aspect-ratio:9/16;border-radius:14px;overflow:hidden;background:#dff5fa;border:1px solid #c7edf4}
.video-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.video-play{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(180deg,rgba(0,0,0,.08),rgba(0,0,0,.34));color:#fff;font-size:22px;text-shadow:0 2px 8px rgba(0,0,0,.4)}
.video-meta{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:6px}
.video-badge{display:inline-flex;border:1px solid #b2dde8;background:#e8f8fb;color:#007f96;border-radius:999px;padding:3px 8px;font-size:11px;font-weight:900}
.video-title{display:block;color:#14283a;font-size:15px;line-height:1.45;font-weight:900;margin-bottom:7px}
.video-title:hover{text-decoration:underline;text-underline-offset:3px}
.video-excerpt{font-size:12.5px;line-height:1.68;color:#526f83;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:9px}
.video-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.video-link{display:inline-flex;align-items:center;border:1px solid #d6e9ef;background:#f8fdff;color:#45677c;border-radius:9px;padding:5px 10px;font-size:12px;font-weight:900}
.video-link.primary{background:#e0f5f8;border-color:#b2dde8;color:#007f96}
.video-date{font-size:11px;color:#8aa0ae;font-weight:800;margin-left:auto}
.empty{background:#fff;border:1.5px solid var(--line);border-radius:24px;padding:34px;line-height:1.8;color:#3f627a}
footer{border-top:1px solid var(--line);background:rgba(255,255,255,.72);padding:24px;color:var(--muted);font-size:13px;text-align:center}
@media(max-width:860px){header{padding:10px 16px}.hero{grid-template-columns:1fr;padding:34px 18px 24px}.ask-card{padding:0 18px}.ask-inner{grid-template-columns:1fr}.ask-avatar{min-height:96px}.ask-form{grid-template-columns:1fr}.topics,.video-results{grid-template-columns:1fr}main{padding:10px 18px 56px}.featured{grid-template-columns:repeat(3,1fr)}.section-head{align-items:flex-start;flex-direction:column}.btn-ghost{display:none}.video-card{grid-template-columns:82px minmax(0,1fr)}.video-thumb{width:82px}}
</style>
<link rel="stylesheet" href="assets/kurage-avatar.css?v=20260704a">
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
    <span class="kurage-avatar-stage kurage-avatar-editor" role="img" aria-label="Kurage editor"><span class="kurage-avatar-motion"><span class="kurage-avatar-breath"><img class="kurage-avatar-frame kurage-avatar-frame-0" src="avatar/lipsync/kurage_mouth_0.png" alt=""><img class="kurage-avatar-frame kurage-avatar-frame-1" src="avatar/lipsync/kurage_mouth_1.png" alt=""><img class="kurage-avatar-frame kurage-avatar-frame-2" src="avatar/lipsync/kurage_mouth_2.png" alt=""><img class="kurage-avatar-frame kurage-avatar-frame-3" src="avatar/lipsync/kurage_mouth_3.png" alt=""><img class="kurage-avatar-frame kurage-avatar-frame-4" src="avatar/lipsync/kurage_mouth_4.png" alt=""></span></span></span>
    <h2>Kurageが編集します</h2>
    <p>新しい動画が増えるたびに、テーマ分類と要約を更新。動画単体では見えにくい知識のつながりを案内します。</p>
  </div>
</section>

<section class="ask-card" aria-labelledby="ask-title">
  <div class="ask-inner">
    <div class="ask-avatar" aria-hidden="true">
      <span class="kurage-avatar-stage" role="img" aria-label="Kurage"><span class="kurage-avatar-motion"><span class="kurage-avatar-breath"><img class="kurage-avatar-frame kurage-avatar-frame-0" src="avatar/lipsync/kurage_mouth_0.png" alt=""><img class="kurage-avatar-frame kurage-avatar-frame-1" src="avatar/lipsync/kurage_mouth_1.png" alt=""><img class="kurage-avatar-frame kurage-avatar-frame-2" src="avatar/lipsync/kurage_mouth_2.png" alt=""><img class="kurage-avatar-frame kurage-avatar-frame-3" src="avatar/lipsync/kurage_mouth_3.png" alt=""><img class="kurage-avatar-frame kurage-avatar-frame-4" src="avatar/lipsync/kurage_mouth_4.png" alt=""></span></span></span>
    </div>
    <div>
      <div class="ask-label">教えて！Kurageさん</div>
      <h2 class="ask-title" id="ask-title">質問すると、Kurageが動画を選びます</h2>
      <p class="ask-copy">知りたいテーマを入力してください。Kurageがナレッジライブラリから近い動画を探して、下の一覧をおすすめ動画に切り替えます。</p>
      <form class="ask-form" id="kurageAskForm">
        <textarea class="ask-input" id="kurageQuestion" name="question" placeholder="例: バイブコーディングで稼ぐ方法がわかる動画を教えて"></textarea>
        <button class="ask-submit" id="kurageAskSubmit" type="submit">Kurageに聞く</button>
      </form>
      <div class="ask-response" id="kurageAnswer" aria-live="polite"></div>
      <button class="ask-reset" id="kurageReset" type="button">テーマ一覧に戻す</button>
    </div>
  </div>
</section>

<main id="topics">
  <div class="section-head">
    <div>
      <div class="section-eyebrow">Topics</div>
      <h2 id="knowledgeSectionTitle">育っているテーマ</h2>
    </div>
    <div class="updated" id="knowledgeSectionMeta">更新: <?php echo h($data['updated_at'] ?? '未生成'); ?></div>
  </div>

  <?php if (!$data || !$topics): ?>
    <div class="empty">
      まだナレッジデータが生成されていません。<br>
      `scripts/build-kurage-knowledge.py` を実行すると、動画からテーマ別ページが生成されます。
    </div>
  <?php else: ?>
    <div class="topics" id="knowledgeResults">
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
<script>
(function(){
  var form = document.getElementById('kurageAskForm');
  var input = document.getElementById('kurageQuestion');
  var submit = document.getElementById('kurageAskSubmit');
  var answer = document.getElementById('kurageAnswer');
  var reset = document.getElementById('kurageReset');
  var results = document.getElementById('knowledgeResults');
  var title = document.getElementById('knowledgeSectionTitle');
  var meta = document.getElementById('knowledgeSectionMeta');
  if (!form || !input || !results) { return; }
  var defaultHtml = results.innerHTML;
  var defaultClass = results.className;
  var defaultTitle = title ? title.textContent : '';
  var defaultMeta = meta ? meta.textContent : '';

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function(c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function toolLabel(v) {
    var source = String(v.source || '').toLowerCase();
    if (source === 'kmontage') { return 'Kurage Montage'; }
    if (source === 'kuragevp') { return 'Kurage Voice Pro'; }
    if (source === 'entertainment') { return 'Kurage Entertainment'; }
    if (source === 'blog') { return 'Kurage Blog'; }
    if (source === 'horizon') { return 'Horizon'; }
    return 'Kurage';
  }

  function renderVideos(videos) {
    if (!videos || !videos.length) {
      results.className = 'empty';
      results.innerHTML = '近い動画が見つかりませんでした。別の言葉で質問してみてください。';
      return;
    }
    results.className = 'video-results';
    results.innerHTML = videos.map(function(v) {
      var jid = v.job_id || '';
      var detail = v.page_url || ('kuragev.php?id=' + encodeURIComponent(jid));
      var thumb = v.thumbnail_url || (jid ? 'thumbs/' + encodeURIComponent(jid) + '.jpg' : '');
      return '<article class="video-card">'
        + '<a class="video-thumb" href="' + esc(detail) + '" title="動画を見る">'
        + '<img src="' + esc(thumb) + '" loading="lazy" decoding="async" alt="">'
        + '<span class="video-play">▶</span>'
        + '</a>'
        + '<div>'
        + '<div class="video-meta"><span class="video-badge">' + esc(toolLabel(v)) + '</span>'
        + (v.topic_title ? '<span class="video-badge">' + esc(v.topic_title) + '</span>' : '')
        + '</div>'
        + '<a class="video-title" href="' + esc(detail) + '">' + esc(v.title || 'Kurage動画') + '</a>'
        + '<p class="video-excerpt">' + esc(v.excerpt || '') + '</p>'
        + '<div class="video-actions">'
        + '<a class="video-link primary" href="' + esc(detail) + '">詳細を見る</a>'
        + '<a class="video-link" href="kuragev.php?id=' + encodeURIComponent(jid) + '">kuragev.php</a>'
        + '<span class="video-date">' + esc(v.created_at || '') + '</span>'
        + '</div>'
        + '</div>'
        + '</article>';
    }).join('');
  }

  form.addEventListener('submit', function(e) {
    e.preventDefault();
    var question = input.value.trim();
    if (!question) {
      input.focus();
      return;
    }
    submit.disabled = true;
    submit.textContent = '検索中...';
    answer.className = 'ask-response show';
    answer.textContent = 'Kurageがナレッジ動画を探しています。';
    fetch('kurage_knowledge.php?api=ask', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({question: question})
    }).then(function(res) {
      return res.json();
    }).then(function(data) {
      if (!data || !data.ok) { throw new Error((data && data.error) || 'search failed'); }
      answer.textContent = data.answer || 'Kurageが関連動画を選びました。';
      if (title) { title.textContent = 'Kurageさんのおすすめ動画'; }
      if (meta) { meta.textContent = '質問: ' + question; }
      renderVideos(data.videos || []);
      reset.className = 'ask-reset show';
      document.getElementById('topics').scrollIntoView({behavior:'smooth', block:'start'});
    }).catch(function(err) {
      answer.textContent = '検索でエラーが出ました。少し時間を置いてもう一度試してください。';
      console.error(err);
    }).finally(function() {
      submit.disabled = false;
      submit.textContent = 'Kurageに聞く';
    });
  });

  reset.addEventListener('click', function() {
    results.className = defaultClass;
    results.innerHTML = defaultHtml;
    if (title) { title.textContent = defaultTitle; }
    if (meta) { meta.textContent = defaultMeta; }
    answer.className = 'ask-response';
    answer.textContent = '';
    reset.className = 'ask-reset';
  });
})();
</script>
</body>
</html>
