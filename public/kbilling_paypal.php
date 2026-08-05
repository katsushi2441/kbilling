<?php
/**
 * PayPal の入金をサーバー側で確かめる。
 *
 * 【なぜこれが要るのか】
 * PayPalボタンはブラウザの中で動くので、決済が終わると JavaScript が
 * 「終わりました」とサーバーへ知らせにくる。この知らせを信じて入金済みに
 * すると、ブラウザの開発者ツールから同じリクエストを手で送るだけで、
 * 1円も払わずに入金済みにできる。
 *
 * 母体にした vibe-prototype.php はそれをしていた。ここでは PayPal の
 * Orders API v2 に「その注文は本当に決済されたか」を問い合わせ、
 * 金額・通貨・請求書IDまで突き合わせてから入金として記録する。
 *
 * PHP 5.x でも動く構文だけを使う。
 */
require_once __DIR__ . '/kbilling_lib.php';

/** 本番かサンドボックスか。設定で切り替える（既定は本番）。 */
function kbil_paypal_base() {
    $env = defined('KBIL_PAYPAL_ENV') ? strtolower(KBIL_PAYPAL_ENV) : 'live';
    return $env === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
}

/**
 * HTTPで取ってくる。curl が無い共有サーバーでも動くよう2経路持つ。
 *
 * @return array list($status, $body)
 */
function kbil_http($method, $url, $headers, $body = null, $userpwd = null) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        // 証明書の検証は絶対に切らない。切ると中間者に決済結果を偽装される。
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if ($userpwd !== null) { curl_setopt($ch, CURLOPT_USERPWD, $userpwd); }
        if ($body !== null)    { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $res = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return array($status, $res === false ? '' : (string)$res);
    }

    if ($userpwd !== null) { $headers[] = 'Authorization: Basic ' . base64_encode($userpwd); }
    $ctx = stream_context_create(array(
        'http' => array(
            'method'        => $method,
            'header'        => implode("\r\n", $headers),
            'content'       => $body === null ? '' : $body,
            'timeout'       => 20,
            'ignore_errors' => true,
        ),
        'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
    ));
    $res = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) &&
        preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    return array($status, $res === false ? '' : (string)$res);
}

/** アクセストークンを取る。失敗したら空文字。 */
function kbil_paypal_token() {
    if (!kbil_paypal_enabled()) { return ''; }
    list($status, $body) = kbil_http(
        'POST',
        kbil_paypal_base() . '/v1/oauth2/token',
        array('Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'),
        'grant_type=client_credentials',
        KBIL_PAYPAL_CLIENT_ID . ':' . KBIL_PAYPAL_SECRET
    );
    if ($status !== 200) { return ''; }
    $d = json_decode($body, true);
    return (is_array($d) && !empty($d['access_token'])) ? (string)$d['access_token'] : '';
}

/**
 * PayPalから返ってきた注文の内容が、この請求書の支払いとして正しいか判定する。
 *
 * 次のすべてが合ったときだけ true を返す。
 *   - 注文の状態が COMPLETED（決済が完了して確定している）
 *   - 通貨が JPY
 *   - 金額が請求書の税込合計と一致する
 *   - custom_id が この請求書のID と一致する
 *
 * custom_id の照合が要点。これが無いと、別の（本物の）PayPal注文IDを
 * 持ってくるだけで、支払っていない請求書を入金済みにできてしまう。
 *
 * 通信と切り離してあるのは、この判定だけをテストできるようにするため。
 * ここが this 製品の一番きわどい所なので、ネットワーク無しで何度でも
 * 確かめられる形にしている（scripts/check_kbilling.php）。
 *
 * @param array $d       PayPal の Orders API v2 が返した注文（連想配列）
 * @param array $invoice こちらの請求書
 * @return array list($ok, $message)
 */
function kbil_paypal_check_order($d, $invoice) {
    if (!is_array($d)) { return array(false, 'PayPalの応答を読み取れませんでした。'); }

    if (!isset($d['status']) || $d['status'] !== 'COMPLETED') {
        $s = isset($d['status']) ? (string)$d['status'] : '不明';
        return array(false, '決済が完了していません（状態: ' . $s . '）。');
    }

    if (empty($d['purchase_units'][0])) {
        return array(false, '決済の明細を確認できませんでした。');
    }
    $pu = $d['purchase_units'][0];

    // 金額。JPY は小数を持たないが、PayPal は文字列で返すので整数に寄せて比べる。
    $cur = isset($pu['amount']['currency_code']) ? (string)$pu['amount']['currency_code'] : '';
    $val = isset($pu['amount']['value']) ? (string)$pu['amount']['value'] : '';
    if ($cur !== 'JPY') {
        return array(false, '通貨が違います（' . $cur . '）。');
    }
    if ($val === '' || (int)round((float)$val) !== (int)$invoice['total']) {
        return array(false, '決済金額が請求額と一致しません。');
    }

    // この請求書に対する支払いか。他の注文IDの使い回しを防ぐ。
    $custom = isset($pu['custom_id']) ? (string)$pu['custom_id'] : '';
    if ($custom === '' || !hash_equals((string)$invoice['id'], $custom)) {
        return array(false, 'この請求書に対する決済ではありません。');
    }

    return array(true, '決済を確認しました。');
}

/**
 * PayPalの注文を照会して、この請求書の支払いとして正しいか確かめる。
 * 通信を行い、判定は kbil_paypal_check_order() に任せる。
 *
 * @return array list($ok, $message)
 */
function kbil_paypal_verify($paypal_order_id, $invoice) {
    $paypal_order_id = trim((string)$paypal_order_id);
    if ($paypal_order_id === '' || !preg_match('/^[A-Za-z0-9_-]{5,64}$/', $paypal_order_id)) {
        return array(false, '決済IDの形式が正しくありません。');
    }
    if (!kbil_paypal_enabled()) {
        return array(false, 'PayPalの設定がされていません。');
    }

    $token = kbil_paypal_token();
    if ($token === '') {
        return array(false, 'PayPalに接続できませんでした。時間をおいてお試しください。');
    }

    list($status, $body) = kbil_http(
        'GET',
        kbil_paypal_base() . '/v2/checkout/orders/' . rawurlencode($paypal_order_id),
        array('Accept: application/json', 'Authorization: Bearer ' . $token)
    );
    if ($status === 404) { return array(false, 'その決済が見つかりませんでした。'); }
    if ($status !== 200)  { return array(false, 'PayPalへの照会に失敗しました。'); }

    return kbil_paypal_check_order(json_decode($body, true), $invoice);
}

/**
 * 同じ PayPal 注文IDが、すでに別の請求書で使われていないか。
 * 二重計上を防ぐ。
 */
function kbil_paypal_order_used($paypal_order_id, $except_invoice_id = '') {
    $paypal_order_id = trim((string)$paypal_order_id);
    if ($paypal_order_id === '') { return false; }
    foreach (kbil_all() as $r) {
        if ($r['id'] === $except_invoice_id) { continue; }
        if (!empty($r['paypal_order_id']) && hash_equals((string)$r['paypal_order_id'], $paypal_order_id)) {
            return true;
        }
    }
    return false;
}
