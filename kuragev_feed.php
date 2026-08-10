<?php
/**
 * kuragev の「新着動画」フィード（CORS付きJSON）。
 * kclauncher など外部クライアント向けに、公開済み動画の最小情報だけを返す読み取り専用エンドポイント。
 * 実データは kuragev.php と同じ動画ジョブAPI(:18303/jobs)から取得する（追加のみ・既存は変更しない）。
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=300');

$KURAGE_API = 'http://exbridge.ddns.net:18303';
$BASE = 'https://kurage.exbridge.jp';
$limit = isset($_GET['limit']) ? max(1, min(30, (int)$_GET['limit'])) : 12;

$ctx = stream_context_create(array('http' => array('timeout' => 10, 'header' => "Accept: application/json\r\n")));
$body = @file_get_contents($KURAGE_API . '/jobs?limit=0', false, $ctx);
$data = json_decode((string)$body, true);
$jobs = is_array($data) ? (isset($data['jobs']) ? $data['jobs'] : $data) : array();

function feed_title($j) {
    foreach (array('display_title', 'summary_title', 'title', 'source_title') as $k) {
        $t = trim((string)($j[$k] ?? ''));
        if ($t !== '') { return $t; }
    }
    return 'Kurage動画';
}
function feed_visible($j) {
    if (strtolower(trim((string)($j['status'] ?? ''))) !== 'done') { return false; }
    // listing_public が無い旧ジョブは表示、あるものは真のみ
    if (array_key_exists('listing_public', $j) && empty($j['listing_public'])) { return false; }
    return true;
}

$out = array();
if (is_array($jobs)) {
    foreach ($jobs as $j) {
        if (!feed_visible($j)) { continue; }
        $id = preg_replace('/[^a-zA-Z0-9]/', '', (string)($j['job_id'] ?? $j['id'] ?? ''));
        if ($id === '') { continue; }
        $ver = rawurlencode((string)($j['created_at'] ?? '1'));
        $out[] = array(
            'id'        => $id,
            'title'     => feed_title($j),
            'page_url'  => $BASE . '/kuragev.php?id=' . rawurlencode($id),
            'thumb_url' => $BASE . '/kuragev.php?proxy=thumbnail&job_id=' . rawurlencode($id) . '&v=' . $ver,
            'created_at'=> (string)($j['created_at'] ?? ''),
        );
        if (count($out) >= $limit) { break; }
    }
}

echo json_encode(array('name' => 'kuragev', 'count' => count($out), 'videos' => $out),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
