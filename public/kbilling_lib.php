<?php
/**
 * Kurage Billing — 請求書の台帳・明細・税計算・メール送信。
 *
 * heteml にDBを置かない方針（kgeo/karchitect/kinvoice と同じ）。
 * JSONを flock で直列化する。更新は必ず kbil_update() を通すこと。
 *
 * PHP 5.x でも動く構文だけを使う。
 */

// 設定はここで最初に読む。defineは先勝ちなので、既定値より前に読まないと
// 設定ファイルの値が効かない（画面側で読むと手遅れになる）。
//
// KBIL_CONFIG_FILE を先に定義すれば、置き場所を変えられる。公開領域の外
// （例: dirname(__DIR__) . '/kbilling_config.php'）に置くとより安全。
if (!defined('KBIL_CONFIG_FILE')) { define('KBIL_CONFIG_FILE', __DIR__ . '/kbilling_config.php'); }
if (KBIL_CONFIG_FILE !== '' && file_exists(KBIL_CONFIG_FILE)) { require_once KBIL_CONFIG_FILE; }

if (!defined('KBIL_DATA_DIR')) { define('KBIL_DATA_DIR', __DIR__ . '/kbilling_data'); }
define('KBIL_LEDGER', KBIL_DATA_DIR . '/invoices.json');

// 請求書を発行できるXアカウント。未設定なら誰も管理者にしない（安全側に倒す）。
if (!defined('KBIL_ADMIN')) { define('KBIL_ADMIN', ''); }

// 明細の上限。1枚の請求書に入る行数（PDFの用紙に収まる数でもある）。
define('KBIL_MAX_ITEMS', 12);

// 支払いページのメール確認を何回間違えたら閉じるか（KBIL_PAY_REQUIRE_EMAIL 時のみ）。
define('KBIL_MAX_FAIL', 10);

/**
 * 発行元。値はすべて kbilling_config.php で設定する。
 * このファイルは公開リポジトリに入るので、自社の情報を直接書かないこと。
 *
 * 【この製品でいちばん重要な原則】
 * 振込先（bank / holder）をコードに書かない。書いたまま配ると、買った人が
 * 発行する請求書に別人の口座が載り、入金がその人に渡る。母体にした
 * vibe-prototype.php はこれを直書きしていたので、設定へ全部出した。
 *
 * メールは送信元(From)にも使う。共有サーバーから送る場合、そのサーバーが
 * SPFに含まれているドメインでなければ受信側に捨てられる。
 */
function kbil_issuer() {
    return array(
        'name'   => defined('KBIL_NAME') ? KBIL_NAME : '',
        'zip'    => defined('KBIL_ZIP')  ? KBIL_ZIP  : '',
        'addr'   => defined('KBIL_ADDR') ? KBIL_ADDR : '',
        'tel'    => defined('KBIL_TEL')  ? KBIL_TEL  : '',
        'mail'   => defined('KBIL_MAIL_FROM') ? KBIL_MAIL_FROM : '',
        // 適格請求書発行事業者の登録番号。空なら請求書に印字しない
        // （存在しない番号を書かないため）。
        'reg_no' => defined('KBIL_INVOICE_REG_NO') ? KBIL_INVOICE_REG_NO : '',
        // 振込先。空なら請求書にも支払いページにも出さない。
        'bank'   => defined('KBIL_BANK')   ? KBIL_BANK   : '',
        'holder' => defined('KBIL_HOLDER') ? KBIL_HOLDER : '',
    );
}

/** 消費税率の既定値。請求書ごとに変えられる。 */
function kbil_default_rate() {
    return defined('KBIL_TAX_RATE') ? (int)KBIL_TAX_RATE : 10;
}

/** 支払期限の既定（発行日から何日後か）。 */
function kbil_due_days() {
    return defined('KBIL_DUE_DAYS') ? max(0, (int)KBIL_DUE_DAYS) : 30;
}

/** 設定が足りているか。管理画面で未設定を知らせるために使う。 */
function kbil_config_missing() {
    $miss = array();
    if (!defined('KBIL_NAME') || KBIL_NAME === '') { $miss[] = 'KBIL_NAME（発行元の名称）'; }
    if (!defined('KBIL_MAIL_FROM') || KBIL_MAIL_FROM === '') { $miss[] = 'KBIL_MAIL_FROM（送信元メールアドレス）'; }
    return $miss;
}

/**
 * 設定が足りていないと「請求書として使えない」もの。
 * 発行はできるが、集金できないので画面で警告する。
 */
function kbil_payment_warnings() {
    $w = array();
    $issuer = kbil_issuer();
    if ($issuer['bank'] === '' && !kbil_paypal_enabled()) {
        $w[] = '振込先（KBIL_BANK）もPayPalも設定されていません。このままでは支払方法が案内できません。';
    }
    if (defined('KBIL_PAYPAL_CLIENT_ID') && KBIL_PAYPAL_CLIENT_ID !== ''
        && (!defined('KBIL_PAYPAL_SECRET') || KBIL_PAYPAL_SECRET === '')) {
        $w[] = 'KBIL_PAYPAL_SECRET が未設定のため、PayPal決済ボタンを表示しません。'
             . '入金をサーバー側で検証できない状態で決済させないためです。';
    }
    return $w;
}

/** 画面に出す製品名。買った人が自分の名前に変えられるようにする。 */
function kbil_app_title() {
    return defined('KBIL_APP_TITLE') && KBIL_APP_TITLE !== '' ? KBIL_APP_TITLE : '請求書の発行・集金';
}

/**
 * デモモード。触ってもらうための公開インスタンス用。
 *
 * メールを実際に送らないのが要点。誰でも任意のアドレス宛に送信できる
 * 状態にすると、そのドメインが迷惑メールの踏み台になり、送信評価が落ちて
 * 本物の請求書まで届かなくなる。
 */
function kbil_is_demo() { return defined('KBIL_DEMO') && KBIL_DEMO; }

/** デモの台帳が育ち続けないように、古いものと多すぎるものを捨てる。 */
function kbil_demo_cleanup($max_age_sec = 86400, $max_rows = 50) {
    if (!kbil_is_demo()) { return; }
    kbil_update(function (&$data) use ($max_age_sec, $max_rows) {
        $now = time();
        $kept = array();
        foreach ($data['invoices'] as $r) {
            if ($now - (int)$r['created_at'] < $max_age_sec) { $kept[] = $r; }
        }
        if (count($kept) > $max_rows) { $kept = array_slice($kept, -$max_rows); }
        $data['invoices'] = $kept;
        return array(true, '');
    });
}

/** ヘッダーのアイコン画像。設定が無ければ出さない（同梱していないため）。 */
function kbil_logo() { return defined('KBIL_LOGO') ? KBIL_LOGO : ''; }

function kbil_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** random_bytes は PHP 7 以降。5.x でも動くよう退避経路を持つ。 */
function kbil_random_hex($bytes) {
    if (function_exists('random_bytes')) { return bin2hex(random_bytes($bytes)); }
    if (function_exists('openssl_random_pseudo_bytes')) {
        return bin2hex(openssl_random_pseudo_bytes($bytes));
    }
    $out = '';
    for ($i = 0; $i < $bytes * 2; $i++) { $out .= dechex(mt_rand(0, 15)); }
    return $out;
}

/* ---------------- 台帳 ---------------- */

function kbil_update($callback) {
    if (!is_dir(KBIL_DATA_DIR) && !@mkdir(KBIL_DATA_DIR, 0705, true)) {
        return array(false, '台帳ディレクトリを作成できません');
    }
    $fp = @fopen(KBIL_LEDGER, 'c+');
    if (!$fp) { return array(false, '台帳を開けません'); }
    if (!flock($fp, LOCK_EX)) { fclose($fp); return array(false, '台帳をロックできません'); }
    rewind($fp);
    $data = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($data)) { $data = array(); }
    if (!isset($data['invoices']) || !is_array($data['invoices'])) { $data['invoices'] = array(); }
    if (!isset($data['seq'])) { $data['seq'] = 0; }
    $result = $callback($data);
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    @chmod(KBIL_LEDGER, 0600);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

function kbil_all() {
    if (!file_exists(KBIL_LEDGER)) { return array(); }
    $fp = @fopen(KBIL_LEDGER, 'r');
    if (!$fp) { return array(); }
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $d = json_decode((string)$raw, true);
    return (is_array($d) && isset($d['invoices']) && is_array($d['invoices'])) ? $d['invoices'] : array();
}

/** 新しい順。管理画面の一覧はこれを使う。 */
function kbil_recent() { return array_reverse(kbil_all()); }

/** 支払いページのトークンで引く。見つからなければ null。 */
function kbil_find_by_token($token) {
    $token = (string)$token;
    if ($token === '') { return null; }
    foreach (kbil_all() as $r) {
        // トークンは秘密なので、比較も定数時間で行う
        if (hash_equals((string)$r['token'], $token)) { return $r; }
    }
    return null;
}

function kbil_find($id) {
    foreach (kbil_all() as $r) { if ($r['id'] === $id) { return $r; } }
    return null;
}

function kbil_norm_email($email) { return strtolower(trim((string)$email)); }

/* ---------------- 明細と税 ---------------- */

/**
 * 入力された明細を正規化する。空行は捨てる。
 * 単価は税抜。請求書は税抜単価で明細を書くのが実務のため。
 *
 * @return array list($items, $error)
 */
function kbil_clean_items($raw) {
    $items = array();
    if (!is_array($raw)) { return array(array(), '明細を読み取れません。'); }
    foreach ($raw as $row) {
        $name = isset($row['name']) ? trim((string)$row['name']) : '';
        $qty  = isset($row['qty'])  ? trim((string)$row['qty'])  : '';
        $unit = isset($row['unit']) ? trim((string)$row['unit']) : '';
        // 3つとも空なら、入力されなかった行として黙って捨てる
        if ($name === '' && $qty === '' && $unit === '') { continue; }
        if ($name === '') { return array(array(), '品目が空の行があります。'); }
        if (mb_strlen($name, 'UTF-8') > 60) { return array(array(), '品目が長すぎます（60文字まで）。'); }
        if ($qty === '' || !preg_match('/^\d+$/', $qty) || (int)$qty < 1) {
            return array(array(), '数量は1以上の整数でご入力ください。');
        }
        if ($unit === '' || !preg_match('/^-?\d+$/', $unit)) {
            return array(array(), '単価は整数でご入力ください（税抜）。');
        }
        $qty = (int)$qty; $unit = (int)$unit;
        if ($qty > 99999)  { return array(array(), '数量が大きすぎます。'); }
        if (abs($unit) > 999999999) { return array(array(), '単価が大きすぎます。'); }
        $items[] = array('name' => $name, 'qty' => $qty, 'unit' => $unit);
        if (count($items) > KBIL_MAX_ITEMS) {
            return array(array(), '明細は ' . KBIL_MAX_ITEMS . ' 行までです。');
        }
    }
    if (!$items) { return array(array(), '明細を1行以上ご入力ください。'); }
    return array($items, '');
}

/**
 * 明細から金額を出す。
 *
 * 単価は税抜なので、小計＝Σ(数量×単価)、消費税＝小計×税率（端数切り捨て）。
 * 切り捨ては国税庁の端数処理（税率ごとに1回だけ端数処理する）に合わせている。
 * 行ごとに税を計算して足すと、合計が1円ずれる。
 */
function kbil_totals($items, $rate) {
    $rate = (int)$rate;
    $net = 0;
    foreach ($items as $it) { $net += (int)$it['qty'] * (int)$it['unit']; }
    $tax = $rate > 0 ? (int)floor($net * $rate / 100) : 0;
    return array('net' => $net, 'tax' => $tax, 'total' => $net + $tax, 'rate' => $rate);
}

/* ---------------- 発行 ---------------- */

/** 請求書を登録する。番号と支払いページのトークンはここで採番する。 */
function kbil_create($customer, $contact, $email, $items, $rate, $issued_on, $due_on, $note) {
    return kbil_update(function (&$data) use ($customer, $contact, $email, $items, $rate, $issued_on, $due_on, $note) {
        $data['seq'] = (int)$data['seq'] + 1;
        $t = kbil_totals($items, $rate);
        $r = array(
            'id'        => kbil_random_hex(8),
            // 推測されると他人の請求書が開くので、ここは16バイト(32桁)使う
            'token'     => kbil_random_hex(16),
            'no'        => 'INV-' . date('Ymd', strtotime($issued_on)) . '-' . sprintf('%04d', $data['seq']),
            'customer'  => $customer,
            'contact'   => $contact,
            'email'     => kbil_norm_email($email),
            'items'     => $items,
            'tax_rate'  => (int)$rate,
            'net'       => $t['net'],
            'tax'       => $t['tax'],
            'total'     => $t['total'],
            'issued_on' => $issued_on,
            'due_on'    => $due_on,
            'note'      => (string)$note,
            'status'    => 'unpaid',
            'created_at'=> time(),
            'sent_at'   => 0,
            'mail_ok'   => false,
            'paid_at'   => 0,
            'paid_via'  => '',      // paypal | bank
            'paid_note' => '',
            'paypal_order_id' => '',
            'views'     => array(),
            'fail'      => 0,
        );
        $data['invoices'][] = $r;
        return array(true, $r);
    });
}

function kbil_mark_sent($id, $ok) {
    return kbil_update(function (&$data) use ($id, $ok) {
        foreach ($data['invoices'] as $i => $r) {
            if ($r['id'] === $id) {
                $data['invoices'][$i]['sent_at'] = time();
                $data['invoices'][$i]['mail_ok'] = (bool)$ok;
                return array(true, '');
            }
        }
        return array(false, '請求書が見つかりません');
    });
}

function kbil_mark_view($id) {
    return kbil_update(function (&$data) use ($id) {
        foreach ($data['invoices'] as $i => $r) {
            if ($r['id'] === $id) {
                if (!isset($data['invoices'][$i]['views'])) { $data['invoices'][$i]['views'] = array(); }
                $data['invoices'][$i]['views'][] = time();
                $data['invoices'][$i]['fail'] = 0;
                return array(true, '');
            }
        }
        return array(false, '');
    });
}

function kbil_mark_fail($id) {
    $res = kbil_update(function (&$data) use ($id) {
        foreach ($data['invoices'] as $i => $r) {
            if ($r['id'] === $id) {
                $n = (int)(isset($r['fail']) ? $r['fail'] : 0) + 1;
                $data['invoices'][$i]['fail'] = $n;
                return array(true, $n);
            }
        }
        return array(false, 0);
    });
    return isset($res[1]) ? (int)$res[1] : 0;
}

/* ---------------- 入金 ---------------- */

/**
 * 入金を記録する。
 *
 * 【設計上の要】ここを呼べる経路は2つだけにしてある。
 *   1. kbilling_pay.php — PayPal の Orders API に問い合わせて COMPLETED を
 *      確認できたときだけ（kbil_paypal_verify）
 *   2. kbilling.php     — 管理者が振込を目視で確認したとき
 *
 * ブラウザから「払いました」と言われただけで paid にしてはいけない。
 * 母体の vibe-prototype.php はそれをしていたので、購入者が自分の注文を
 * 無料で入金済みにできた。
 */
function kbil_mark_paid($id, $via, $note = '', $paypal_order_id = '') {
    $via = in_array($via, array('paypal', 'bank'), true) ? $via : 'bank';
    return kbil_update(function (&$data) use ($id, $via, $note, $paypal_order_id) {
        foreach ($data['invoices'] as $i => $r) {
            if ($r['id'] !== $id) { continue; }
            if ($r['status'] === 'paid') { return array(false, 'すでに入金済みです'); }
            $data['invoices'][$i]['status']    = 'paid';
            $data['invoices'][$i]['paid_at']   = time();
            $data['invoices'][$i]['paid_via']  = $via;
            $data['invoices'][$i]['paid_note'] = (string)$note;
            if ($paypal_order_id !== '') {
                $data['invoices'][$i]['paypal_order_id'] = (string)$paypal_order_id;
            }
            return array(true, '入金を記録しました');
        }
        return array(false, '請求書が見つかりません');
    });
}

/**
 * 入金の記録を取り消す。振込の確認間違いを直すためのもの。
 * PayPal決済済みは取り消させない（決済事業者の記録と食い違うため）。
 */
function kbil_unmark_paid($id) {
    return kbil_update(function (&$data) use ($id) {
        foreach ($data['invoices'] as $i => $r) {
            if ($r['id'] !== $id) { continue; }
            if ($r['status'] !== 'paid') { return array(false, '入金済みではありません'); }
            if ($r['paid_via'] === 'paypal') {
                return array(false, 'PayPalで決済済みのため取り消せません');
            }
            $data['invoices'][$i]['status']    = 'unpaid';
            $data['invoices'][$i]['paid_at']   = 0;
            $data['invoices'][$i]['paid_via']  = '';
            $data['invoices'][$i]['paid_note'] = '';
            return array(true, '入金の記録を取り消しました');
        }
        return array(false, '請求書が見つかりません');
    });
}

/** 未入金かつ支払期限を過ぎているか。一覧で色を変えるために使う。 */
function kbil_is_overdue($r) {
    if ($r['status'] === 'paid') { return false; }
    if (empty($r['due_on'])) { return false; }
    return strtotime($r['due_on'] . ' 23:59:59') < time();
}

/* ---------------- 支払いページ ---------------- */

/**
 * 支払いページでメールアドレスの確認を求めるか。
 *
 * 既定は false。請求書のリンクは「払ってもらう」ためのもので、確認を挟むと
 * 支払いが止まる。PayPal や Stripe の請求書リンクも URL そのものを合鍵にしている。
 *
 * 情報漏洩の方を重く見るなら true にする。そのときは kinvoice と同じ
 * 「URL＋宛先メールアドレス」の2つが揃って初めて開く動きになる。
 */
function kbil_pay_requires_email() {
    return defined('KBIL_PAY_REQUIRE_EMAIL') && KBIL_PAY_REQUIRE_EMAIL;
}

/* ---------------- PayPal ---------------- */

/**
 * PayPal決済を出せるか。
 *
 * client_id だけでは有効にしない。secret が無いと Orders API に問い合わせられず、
 * 「本当に決済されたか」をサーバー側で確認できないため。確認できない決済は
 * 受け付けない、が この製品の方針。
 */
function kbil_paypal_enabled() {
    return defined('KBIL_PAYPAL_CLIENT_ID') && KBIL_PAYPAL_CLIENT_ID !== ''
        && defined('KBIL_PAYPAL_SECRET')    && KBIL_PAYPAL_SECRET !== '';
}

function kbil_paypal_client_id() {
    return defined('KBIL_PAYPAL_CLIENT_ID') ? KBIL_PAYPAL_CLIENT_ID : '';
}

/* ---------------- メール ---------------- */

function kbil_mail_subject($r) {
    return '【' . kbil_issuer()['name'] . '】請求書 ' . $r['no'] . ' のご案内';
}

/** 送るメールの本文。デモではこれを画面に出す。 */
function kbil_mail_body($r, $pay_url) {
    $issuer = kbil_issuer();
    $body = ($r['customer'] !== '' ? $r['customer'] . " 御中\n" : '')
          . ($r['contact'] !== '' ? $r['contact'] . " 様\n" : '')
          . "\nいつもお世話になっております。" . $issuer['name'] . "です。\n"
          . "請求書を発行いたしましたので、下記よりご確認ください。\n\n"
          . "──────────────────────────\n"
          . "  請求書番号 : " . $r['no'] . "\n"
          . "  発行日     : " . $r['issued_on'] . "\n"
          . "  お支払期限 : " . $r['due_on'] . "\n"
          . "  ご請求金額 : " . number_format((int)$r['total']) . " 円（税込）\n"
          . "──────────────────────────\n\n"
          . "▼ 請求書のご確認とお支払いはこちら\n"
          . $pay_url . "\n\n";
    if (kbil_pay_requires_email()) {
        $body .= "このページを開くと、メールアドレスの確認を求められます。\n"
               . "本メールの宛先（" . $r['email'] . "）をご入力ください。\n\n";
    }
    $body .= "上記ページから請求書PDFをダウンロードいただけます。\n";
    if (kbil_paypal_enabled()) {
        $body .= "PayPal（クレジットカード可）でのお支払いにも対応しております。\n";
    }
    if ($issuer['bank'] !== '') {
        $body .= "\n【お振込先】\n  " . $issuer['bank'] . "\n"
               . ($issuer['holder'] !== '' ? "  口座名義: " . $issuer['holder'] . "\n" : '')
               . "  ※振込手数料はお客様のご負担でお願いいたします。\n";
    }
    $body .= "\n※このURLはお客様専用です。第三者へ転送しないようお願いいたします。\n\n"
           . "──────────────────────────\n"
           . $issuer['name'] . "\n"
           . ($issuer['reg_no'] !== '' ? '登録番号 ' . $issuer['reg_no'] . "\n" : '')
           . trim($issuer['zip'] . ' ' . $issuer['addr']) . "\n"
           . $issuer['mail'] . "\n";
    return $body;
}

/**
 * 請求書の案内を送る。mail() + base64（kinvoice と同方式）。
 *
 * デモモードでは実際に送らない。公開デモに送信フォームを置くと、誰でも
 * 任意のアドレス宛に送れる踏み台になり、そのドメインの送信評価が落ちて
 * 本物の請求書まで届かなくなるため。
 */
function kbil_send_mail($r, $pay_url) {
    if (kbil_is_demo()) { return true; }
    if ($r['email'] === '' || !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) { return false; }

    $issuer = kbil_issuer();
    $from = defined('KBIL_MAIL_FROM') ? KBIL_MAIL_FROM : $issuer['mail'];
    $headers = implode("\r\n", array(
        'From: ' . $issuer['name'] . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: Kurage Billing',
    ));
    return @mail(
        $r['email'],
        '=?UTF-8?B?' . base64_encode(kbil_mail_subject($r)) . '?=',
        chunk_split(base64_encode(kbil_mail_body($r, $pay_url))),
        $headers
    );
}

/** 入金を確認したことを顧客へ知らせる。領収書ではない（kinvoice の担当）。 */
function kbil_send_paid_mail($r) {
    if (kbil_is_demo()) { return true; }
    if ($r['email'] === '' || !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) { return false; }

    $issuer = kbil_issuer();
    $from = defined('KBIL_MAIL_FROM') ? KBIL_MAIL_FROM : $issuer['mail'];
    $body = ($r['customer'] !== '' ? $r['customer'] . " 御中\n" : '')
          . ($r['contact'] !== '' ? $r['contact'] . " 様\n" : '')
          . "\nいつもお世話になっております。" . $issuer['name'] . "です。\n"
          . "下記のご入金を確認いたしました。ありがとうございました。\n\n"
          . "──────────────────────────\n"
          . "  請求書番号 : " . $r['no'] . "\n"
          . "  ご入金額   : " . number_format((int)$r['total']) . " 円（税込）\n"
          . "──────────────────────────\n\n"
          . "──────────────────────────\n"
          . $issuer['name'] . "\n"
          . ($issuer['reg_no'] !== '' ? '登録番号 ' . $issuer['reg_no'] . "\n" : '')
          . $issuer['mail'] . "\n";
    $headers = implode("\r\n", array(
        'From: ' . $issuer['name'] . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: Kurage Billing',
    ));
    return @mail(
        $r['email'],
        '=?UTF-8?B?' . base64_encode('【' . $issuer['name'] . '】ご入金を確認しました（' . $r['no'] . '）') . '?=',
        chunk_split(base64_encode($body)),
        $headers
    );
}
