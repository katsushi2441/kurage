<?php
/** Kurage Montage usage credits: first generation free, then 500 JPY / 50,000 URLAI. */

define('KMO_PRICE_JPY', 500);
define('KMO_PRICE_URLAI', 50000);
define('KMO_DATA_DIR', __DIR__ . '/kmontage_data');
define('KMO_LEDGER', KMO_DATA_DIR . '/credits.json');
define('KMO_PAYPAL_CLIENT_ID', 'AbbwjyEYdGXqSqptChYFw7vxdOzBSZXiNslHASN1bHfxJZnV_borxUJdMzR1gs8njHQxqn69APqn5-MG');
define('KMO_PAYPAL_SECRET_FILE', __DIR__ . '/blog/paywall/data/paypal_secret.txt');
define('KMO_PAYPAL_API', 'https://api-m.paypal.com');
define('KMO_URLAI_CONTRACT', '0xdaecdda6ad112f0e1e4097fb735dd01d9c33cba3');
define('KMO_URLAI_RECEIVER', '0x444fadbd6e1fed0cfbf7613b6c9f91b9021eecbd');
define('KMO_BASE_RPC', 'https://mainnet.base.org');

function kmo_bill_defaults() {
    return array('users' => array(), 'used_orders' => array(), 'used_txs' => array());
}

function kmo_bill_ensure_dir() {
    if (!is_dir(KMO_DATA_DIR)) { @mkdir(KMO_DATA_DIR, 0705, true); }
    $deny = KMO_DATA_DIR . '/.htaccess';
    if (!file_exists($deny)) { @file_put_contents($deny, "Require all denied\nDeny from all\n"); }
}

function kmo_bill_read_locked($exclusive, $callback) {
    kmo_bill_ensure_dir();
    $fp = fopen(KMO_LEDGER, 'c+');
    if (!$fp) { return null; }
    flock($fp, $exclusive ? LOCK_EX : LOCK_SH);
    rewind($fp);
    $raw = stream_get_contents($fp);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) { $data = array(); }
    $data += kmo_bill_defaults();
    $result = call_user_func_array($callback, array(&$data));
    if ($exclusive) {
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fp);
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

function kmo_bill_state($user) {
    return kmo_bill_read_locked(false, function(&$d) use ($user) {
        $u = isset($d['users'][$user]) && is_array($d['users'][$user]) ? $d['users'][$user] : array();
        return array(
            'free_used' => !empty($u['free_used']),
            'credits' => isset($u['credits']) ? (int)$u['credits'] : 0,
            'generation_count' => isset($u['generation_count']) ? (int)$u['generation_count'] : 0,
        );
    });
}

function kmo_bill_gate($user) {
    $state = kmo_bill_state($user);
    if (!$state['free_used']) { return 'free'; }
    if ($state['credits'] > 0) { return 'credit'; }
    return 'need_payment';
}

function kmo_bill_reserve_generation($user) {
    return kmo_bill_read_locked(true, function(&$d) use ($user) {
        $u = isset($d['users'][$user]) && is_array($d['users'][$user]) ? $d['users'][$user] : array();
        $u += array('free_used' => false, 'credits' => 0, 'generation_count' => 0, 'generations' => array(), 'reservations' => array());
        foreach ($u['reservations'] as $id => $entry) {
            if (!is_array($entry) || (int)($entry['ts'] ?? 0) > time() - 900) { continue; }
            if (($entry['mode'] ?? '') === 'free') { $u['free_used'] = false; }
            if (($entry['mode'] ?? '') === 'credit') { $u['credits'] = (int)$u['credits'] + 1; }
            unset($u['reservations'][$id]);
        }
        if (!$u['free_used']) {
            $mode = 'free';
            $u['free_used'] = true;
        } elseif ((int)$u['credits'] > 0) {
            $mode = 'credit';
            $u['credits'] = (int)$u['credits'] - 1;
        } else {
            return array('ok' => false, 'mode' => 'need_payment');
        }
        $reservation = bin2hex(random_bytes(16));
        $u['reservations'][$reservation] = array('mode' => $mode, 'ts' => time());
        $d['users'][$user] = $u;
        return array('ok' => true, 'mode' => $mode, 'reservation' => $reservation);
    });
}

function kmo_bill_finish_reservation($user, $reservation, $job_id) {
    return (bool)kmo_bill_read_locked(true, function(&$d) use ($user, $reservation, $job_id) {
        $u = isset($d['users'][$user]) && is_array($d['users'][$user]) ? $d['users'][$user] : array();
        $entry = isset($u['reservations'][$reservation]) ? $u['reservations'][$reservation] : null;
        if (!is_array($entry)) { return false; }
        unset($u['reservations'][$reservation]);
        $u['generation_count'] = (int)($u['generation_count'] ?? 0) + 1;
        if (!isset($u['generations']) || !is_array($u['generations'])) { $u['generations'] = array(); }
        $u['generations'][] = array('job_id' => (string)$job_id, 'mode' => $entry['mode'], 'ts' => time());
        $d['users'][$user] = $u;
        return true;
    });
}

function kmo_bill_cancel_reservation($user, $reservation) {
    return (bool)kmo_bill_read_locked(true, function(&$d) use ($user, $reservation) {
        $u = isset($d['users'][$user]) && is_array($d['users'][$user]) ? $d['users'][$user] : array();
        $entry = isset($u['reservations'][$reservation]) ? $u['reservations'][$reservation] : null;
        if (!is_array($entry)) { return false; }
        unset($u['reservations'][$reservation]);
        if (($entry['mode'] ?? '') === 'free') {
            $u['free_used'] = false;
        } elseif (($entry['mode'] ?? '') === 'credit') {
            $u['credits'] = (int)($u['credits'] ?? 0) + 1;
        }
        $d['users'][$user] = $u;
        return true;
    });
}

function kmo_bill_commit_generation($user, $mode, $job_id) {
    return (bool)kmo_bill_read_locked(true, function(&$d) use ($user, $mode, $job_id) {
        $u = isset($d['users'][$user]) && is_array($d['users'][$user]) ? $d['users'][$user] : array();
        $u += array('free_used' => false, 'credits' => 0, 'generation_count' => 0, 'generations' => array());
        if ($mode === 'free') {
            if ($u['free_used']) { return false; }
            $u['free_used'] = true;
        } elseif ($mode === 'credit') {
            if ((int)$u['credits'] < 1) { return false; }
            $u['credits'] = (int)$u['credits'] - 1;
        } else {
            return false;
        }
        $u['generation_count'] = (int)$u['generation_count'] + 1;
        $u['generations'][] = array('job_id' => (string)$job_id, 'mode' => $mode, 'ts' => time());
        $d['users'][$user] = $u;
        return true;
    });
}

function kmo_bill_grant($user, $count, $method, $reference) {
    return (bool)kmo_bill_read_locked(true, function(&$d) use ($user, $count, $method, $reference) {
        $u = isset($d['users'][$user]) && is_array($d['users'][$user]) ? $d['users'][$user] : array();
        $u += array('credits' => 0, 'purchases' => array());
        $u['credits'] = (int)$u['credits'] + (int)$count;
        $u['purchases'][] = array('method' => $method, 'ref' => $reference, 'count' => (int)$count, 'ts' => time());
        $d['users'][$user] = $u;
        return true;
    });
}

function kmo_http_json($url, $headers, $post_body = null) {
    $ch = curl_init($url);
    $options = array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => $headers);
    if ($post_body !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $post_body;
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array($status, json_decode((string)$body, true));
}

function kmo_bill_grant_paypal($user, $order_id) {
    $order_id = trim((string)$order_id);
    if (!preg_match('/^[A-Z0-9]{8,32}$/i', $order_id)) { return array(false, '注文IDの形式が不正です'); }
    $already = kmo_bill_read_locked(false, function(&$d) use ($order_id) {
        return in_array($order_id, $d['used_orders'], true);
    });
    if ($already) { return array(false, 'この注文IDは既に使用されています'); }
    $secret = file_exists(KMO_PAYPAL_SECRET_FILE) ? trim((string)@file_get_contents(KMO_PAYPAL_SECRET_FILE)) : '';
    if ($secret === '') { return array(false, '決済設定が未完了です'); }
    list($status, $token) = kmo_http_json(
        KMO_PAYPAL_API . '/v1/oauth2/token',
        array('Authorization: Basic ' . base64_encode(KMO_PAYPAL_CLIENT_ID . ':' . $secret), 'Content-Type: application/x-www-form-urlencoded'),
        'grant_type=client_credentials'
    );
    if ($status !== 200 || empty($token['access_token'])) { return array(false, 'PayPal認証に失敗しました'); }
    list($status, $order) = kmo_http_json(
        KMO_PAYPAL_API . '/v2/checkout/orders/' . rawurlencode($order_id),
        array('Authorization: Bearer ' . $token['access_token'], 'Content-Type: application/json')
    );
    if ($status !== 200 || !is_array($order)) { return array(false, '注文が見つかりません'); }
    if (($order['status'] ?? '') !== 'COMPLETED') { return array(false, '決済が完了していません'); }
    $unit = $order['purchase_units'][0] ?? array();
    $amount = $unit['amount'] ?? ($unit['payments']['captures'][0]['amount'] ?? array());
    if (($amount['currency_code'] ?? '') !== 'JPY' || (float)($amount['value'] ?? 0) < KMO_PRICE_JPY) {
        return array(false, '決済金額が一致しません');
    }
    $accepted = kmo_bill_read_locked(true, function(&$d) use ($order_id) {
        if (in_array($order_id, $d['used_orders'], true)) { return false; }
        $d['used_orders'][] = $order_id;
        return true;
    });
    if (!$accepted) { return array(false, 'この注文IDは既に使用されています'); }
    kmo_bill_grant($user, 1, 'paypal', $order_id);
    return array(true, '動画生成クレジットを1追加しました');
}

function kmo_rpc($method, $params) {
    $ch = curl_init(KMO_BASE_RPC);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(array('jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params)),
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ));
    $body = curl_exec($ch);
    curl_close($ch);
    $json = json_decode((string)$body, true);
    return isset($json['result']) ? $json['result'] : null;
}

function kmo_topic_address($address) {
    return '0x' . str_pad(substr(strtolower((string)$address), 2), 64, '0', STR_PAD_LEFT);
}

function kmo_hex_to_tokens($hex) {
    $hex = ltrim(str_replace('0x', '', (string)$hex), '0');
    if ($hex === '') { return 0.0; }
    if (function_exists('bcadd')) {
        $decimal = '0';
        foreach (str_split($hex) as $char) { $decimal = bcadd(bcmul($decimal, '16'), (string)hexdec($char)); }
        return (float)bcdiv($decimal, bcpow('10', '18'), 6);
    }
    $value = 0.0;
    foreach (str_split($hex) as $char) { $value = $value * 16 + hexdec($char); }
    return $value / 1e18;
}

function kmo_bill_grant_urlai($user, $wallet) {
    $wallet = strtolower(trim((string)$wallet));
    if (!preg_match('/^0x[a-f0-9]{40}$/', $wallet)) { return array(false, 'ウォレットアドレスの形式が不正です'); }
    $latest_hex = kmo_rpc('eth_blockNumber', array());
    if (!$latest_hex) { return array(false, 'Baseチェーンへ接続できませんでした'); }
    $latest = hexdec($latest_hex);
    $topic0 = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    $used = kmo_bill_read_locked(false, function(&$d) { return $d['used_txs']; });
    $found = array();
    for ($index = 0; $index < 8; $index++) {
        $to = $latest - $index * 50000;
        $from = max(0, $to - 49999);
        $logs = kmo_rpc('eth_getLogs', array(array(
            'address' => KMO_URLAI_CONTRACT,
            'topics' => array($topic0, kmo_topic_address($wallet), kmo_topic_address(KMO_URLAI_RECEIVER)),
            'fromBlock' => '0x' . dechex($from),
            'toBlock' => '0x' . dechex($to),
        )));
        if (!is_array($logs)) { continue; }
        foreach ($logs as $log) {
            $key = strtolower(($log['transactionHash'] ?? '') . ':' . ($log['logIndex'] ?? ''));
            if ($key === ':' || in_array($key, $used, true)) { continue; }
            $found[$key] = kmo_hex_to_tokens($log['data'] ?? '0x0');
        }
    }
    $total = array_sum($found);
    $credits = (int)floor($total / KMO_PRICE_URLAI);
    if ($credits < 1) {
        return array(false, sprintf('未使用の受領を確認できませんでした（確認額: %s URLAI）', number_format($total)));
    }
    $accepted = kmo_bill_read_locked(true, function(&$d) use ($found) {
        $new = array();
        foreach (array_keys($found) as $key) {
            if (!in_array($key, $d['used_txs'], true)) { $d['used_txs'][] = $key; $new[] = $key; }
        }
        return $new;
    });
    if (!$accepted) { return array(false, 'この送金は既に使用されています'); }
    kmo_bill_grant($user, $credits, 'urlai', $wallet . ':' . implode(',', $accepted));
    return array(true, sprintf('%s URLAIを確認し、クレジットを%d追加しました', number_format($total), $credits));
}
