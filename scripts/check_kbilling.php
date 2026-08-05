<?php
// kbilling の台帳・明細・税計算・入金記録・請求書PDFの動作確認。
// PayPalのAPIは呼ばない（ネットワークに出ないので、どこでも実行できる）。
//
// 実行: php scripts/check_kbilling.php

$tmp = sys_get_temp_dir() . '/kbilling-check-' . getmypid();
@mkdir($tmp, 0700, true);
define('KBIL_DATA_DIR', $tmp);
define('KBIL_CONFIG_FILE', '');          // 設定ファイルは読まない
define('KBIL_NAME', 'テスト商事株式会社');
define('KBIL_MAIL_FROM', 'test@example.test');

require_once __DIR__ . '/../public/kbilling_lib.php';
require_once __DIR__ . '/../public/kbilling_pdf.php';
require_once __DIR__ . '/../public/kbilling_paypal.php';

$failures = 0;
function check($label, $actual, $expected) {
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) { $failures++; }
    printf("%s %s (期待 %s / 実際 %s)\n", $ok ? 'ok  ' : 'NG  ', $label,
        var_export($expected, true), var_export($actual, true));
}

/* ============================================================
 * 1. 設定が空のとき、他人の口座が出ないこと
 *
 * この製品でいちばん大事な回帰テスト。母体の vibe-prototype.php は
 * 自社の口座をコードに直書きしていたので、そのまま配ると買った人の
 * 請求書に別人の口座が載った。ここが破れたら売ってはいけない。
 * （KBIL_BANK はこのブロックの後で define する）
 * ========================================================== */
check('設定前は振込先が空', kbil_issuer()['bank'], '');
check('設定前は口座名義が空', kbil_issuer()['holder'], '');
check('設定前は登録番号が空', kbil_issuer()['reg_no'], '');
check('設定前は住所が空', kbil_issuer()['addr'], '');

$probe = array(
    'no' => 'INV-20260805-0001', 'customer' => 'テスト', 'contact' => '', 'items' => array(
        array('name' => 'テスト品目', 'qty' => 1, 'unit' => 1000)),
    'tax_rate' => 10, 'net' => 1000, 'tax' => 100, 'total' => 1100,
    'issued_on' => '2026-08-05', 'due_on' => '2026-09-04', 'note' => '',
);
$pdf_bare = kbil_invoice_pdf($probe);
check('設定前のPDFに振込先の見出しが出ない',
    strpos($pdf_bare, kbil_pdf_hex('【お振込先】')) !== false, false);
check('設定前のPDFに口座名義が出ない',
    strpos($pdf_bare, kbil_pdf_hex('口座名義')) !== false, false);

// ソースそのものに口座らしきものが書かれていないこと。
// 母体の vibe-prototype.php は銀行名・支店・口座番号をコードに直書きしていた。
// コピー元から持ち込んでしまう事故は、動作テストでは気づけないのでここで見る。
// 設置した人が自分の値を書く kbilling_config.php は対象外（そこに書くのが正しい）。
$sources = '';
foreach (glob(__DIR__ . '/../public/*.php') as $f) {
    if (basename($f) === 'kbilling_config.php') { continue; }
    $sources .= file_get_contents($f);
}
// placeholder="例：…" は入力例なので対象外にする（これは書いてあって正しい）
$sources = preg_replace('/placeholder="[^"]*"/u', '', $sources);
check('ソースに銀行名を書いていない', preg_match('/銀行\s*[^\s"\']*支店/u', $sources), 0);
check('ソースに口座番号らしき数字がない', preg_match('/(普通|当座)\s*\d{6,}/u', $sources), 0);
check('ソースに固有の会社名がない', preg_match('/株式会社[ぁ-んァ-ヶ一-龠]/u', $sources), 0);

/* ここから発行元を設定する */
define('KBIL_BANK',   'サンプル銀行 本店営業部　普通 1234567');
define('KBIL_HOLDER', 'カ）サンプル');
define('KBIL_INVOICE_REG_NO', 'T0000000000000');
define('KBIL_ZIP',  '〒100-0001');
define('KBIL_ADDR', '東京都千代田区サンプル1-2-3');

check('設定すると振込先が出る', kbil_issuer()['bank'], 'サンプル銀行 本店営業部　普通 1234567');

/* ============================================================
 * 2. 明細の正規化
 * ========================================================== */
list($items, $err) = kbil_clean_items(array(
    array('name' => 'ホームページ制作費', 'qty' => '1', 'unit' => '300000'),
    array('name' => '', 'qty' => '', 'unit' => ''),                   // 空行は捨てる
    array('name' => '保守費', 'qty' => '12', 'unit' => '10000'),
));
check('空行を捨てる', count($items), 2);
check('エラーなし', $err, '');
check('数量が整数になる', $items[1]['qty'], 12);

check('品目が空ならエラー',
    kbil_clean_items(array(array('name' => '', 'qty' => '1', 'unit' => '100')))[1] !== '', true);
check('数量0はエラー',
    kbil_clean_items(array(array('name' => 'x', 'qty' => '0', 'unit' => '100')))[1] !== '', true);
check('数量が文字ならエラー',
    kbil_clean_items(array(array('name' => 'x', 'qty' => 'いち', 'unit' => '100')))[1] !== '', true);
check('単価が小数ならエラー',
    kbil_clean_items(array(array('name' => 'x', 'qty' => '1', 'unit' => '1.5')))[1] !== '', true);
check('1行も無ければエラー', kbil_clean_items(array())[1] !== '', true);
// 値引き行を書けるように、単価のマイナスは通す
check('単価のマイナスは通る',
    kbil_clean_items(array(array('name' => '値引き', 'qty' => '1', 'unit' => '-5000')))[1], '');

$many = array();
for ($i = 0; $i < KBIL_MAX_ITEMS + 1; $i++) { $many[] = array('name' => 'x', 'qty' => '1', 'unit' => '1'); }
check('明細の上限を超えるとエラー', kbil_clean_items($many)[1] !== '', true);

/* ============================================================
 * 3. 税計算
 * ========================================================== */
$t = kbil_totals(array(array('name' => 'a', 'qty' => 3, 'unit' => 1000)), 10);
check('小計', $t['net'], 3000);
check('消費税10%', $t['tax'], 300);
check('税込合計', $t['total'], 3300);

check('8%', kbil_totals(array(array('name' => 'a', 'qty' => 1, 'unit' => 1000)), 8)['tax'], 80);
check('0%は課税しない', kbil_totals(array(array('name' => 'a', 'qty' => 1, 'unit' => 1000)), 0)['tax'], 0);
check('端数は切り捨て', kbil_totals(array(array('name' => 'a', 'qty' => 1, 'unit' => 999)), 10)['tax'], 99);

// 税率ごとに1回だけ端数処理する（行ごとに計算して足すと1円ずれる）
$two = kbil_totals(array(
    array('name' => 'a', 'qty' => 1, 'unit' => 999),
    array('name' => 'b', 'qty' => 1, 'unit' => 999),
), 10);
check('小計をまとめてから課税する', $two['tax'], 199);   // 行ごとだと 99+99=198
check('合計は小計＋税', $two['total'], $two['net'] + $two['tax']);

// 値引き行が入っても破綻しない
$disc = kbil_totals(array(
    array('name' => '制作費', 'qty' => 1, 'unit' => 100000),
    array('name' => '値引き', 'qty' => 1, 'unit' => -10000),
), 10);
check('値引き後に課税', $disc['net'], 90000);
check('値引き後の税', $disc['tax'], 9000);

/* ============================================================
 * 4. 発行
 * ========================================================== */
check('最初は0件', count(kbil_all()), 0);

$res = kbil_create('株式会社アリス', '山田 太郎', 'Keiri@Alice.co.JP', $items, 10,
                   '2026-08-05', '2026-09-04', '8月分としてご請求申し上げます。');
check('発行できる', $res[0], true);
$a = $res[1];
check('請求書番号の連番', substr($a['no'], -4), '0001');
check('番号は発行日から作る', substr($a['no'], 0, 12), 'INV-20260805');
check('メールは小文字で持つ', $a['email'], 'keiri@alice.co.jp');
check('トークンは32桁', strlen($a['token']), 32);
check('初期は未入金', $a['status'], 'unpaid');
check('合計を保存している', $a['total'], 462000);   // 300000 + 120000 = 420000 → +10%

$res2 = kbil_create('株式会社ボブ', '', 'bob@example.test',
                    array(array('name' => '相談料', 'qty' => 1, 'unit' => 50000)), 10,
                    '2026-08-05', '2026-08-20', '');
$b = $res2[1];
check('連番が進む', substr($b['no'], -4), '0002');
check('トークンは重複しない', $a['token'] === $b['token'], false);

/* ============================================================
 * 5. トークンで引く（他人の請求書が開かないこと）
 * ========================================================== */
check('トークンで引ける', kbil_find_by_token($a['token'])['no'], $a['no']);
check('違うトークンでは引けない', kbil_find_by_token('0000000000000000') === null, true);
check('空のトークンでは引けない', kbil_find_by_token('') === null, true);
check('IDで引ける', kbil_find($a['id'])['customer'], '株式会社アリス');

/* ============================================================
 * 6. 入金
 * ========================================================== */
check('振込の入金を記録できる', kbil_mark_paid($a['id'], 'bank', '2026-08-10 入金確認')[0], true);
check('入金済みになる', kbil_find($a['id'])['status'], 'paid');
check('入金方法が残る', kbil_find($a['id'])['paid_via'], 'bank');
check('メモが残る', kbil_find($a['id'])['paid_note'], '2026-08-10 入金確認');
check('二重に押しても増えない', kbil_mark_paid($a['id'], 'bank', '')[0], false);

check('取り消せる', kbil_unmark_paid($a['id'])[0], true);
check('取り消すと未入金に戻る', kbil_find($a['id'])['status'], 'unpaid');
check('未入金は取り消せない', kbil_unmark_paid($a['id'])[0], false);

// PayPal決済済みは取り消させない（決済事業者の記録と食い違うため）
kbil_mark_paid($b['id'], 'paypal', 'PayPal決済', 'PP-ORDER-0001');
check('PayPal決済済みは取り消せない', kbil_unmark_paid($b['id'])[0], false);
check('PayPal決済済みのまま', kbil_find($b['id'])['status'], 'paid');
check('PayPal注文IDが残る', kbil_find($b['id'])['paypal_order_id'], 'PP-ORDER-0001');

// 同じPayPal注文IDの使い回しを見つける（二重計上の防止）
check('使用済みの決済IDを検出', kbil_paypal_order_used('PP-ORDER-0001'), true);
check('自分自身は除外できる', kbil_paypal_order_used('PP-ORDER-0001', $b['id']), false);
check('未使用の決済IDは通る', kbil_paypal_order_used('PP-ORDER-9999'), false);

/* ============================================================
 * 7. 支払期限
 * ========================================================== */
$old = kbil_create('株式会社期限切れ', '', 'x@example.test',
                   array(array('name' => 'a', 'qty' => 1, 'unit' => 1000)), 10,
                   '2026-01-01', '2026-01-31', '')[1];
check('期限を過ぎた未入金は期限超過', kbil_is_overdue(kbil_find($old['id'])), true);
kbil_mark_paid($old['id'], 'bank', '');
check('入金済みなら期限超過にしない', kbil_is_overdue(kbil_find($old['id'])), false);

/* ============================================================
 * 8. PayPalの有効判定
 *
 * client_id だけでは有効にしない。secret が無いとサーバー側で
 * 「本当に決済されたか」を確認できないため。
 * ========================================================== */
check('設定なしではPayPalを出さない', kbil_paypal_enabled(), false);
check('検証できない状態では決済を通さない',
    kbil_paypal_verify('SOMEORDERID', $a)[0], false);
check('決済IDの形式が変なら照会にも行かない',
    kbil_paypal_verify('bad id!!', $a)[1], '決済IDの形式が正しくありません。');

/* ============================================================
 * 8-2. PayPalの応答を突き合わせる判定
 *
 * この製品でいちばんきわどい所。母体の vibe-prototype.php は
 * ブラウザの申告をそのまま信じていたので、開発者ツールから同じ
 * リクエストを送るだけで、1円も払わずに入金済みにできた。
 * ここが破れたら売ってはいけない。
 * ========================================================== */
$inv_for_pp = kbil_find($a['id']);   // total = 462000
/** 正しい応答を作って、1か所だけ壊す */
function pp_order($override = array()) {
    global $inv_for_pp;
    $d = array(
        'status' => 'COMPLETED',
        'purchase_units' => array(array(
            'custom_id' => $inv_for_pp['id'],
            'amount' => array('currency_code' => 'JPY', 'value' => (string)$inv_for_pp['total']),
        )),
    );
    foreach ($override as $k => $v) {
        if ($k === 'status') { $d['status'] = $v; }
        if ($k === 'custom_id') { $d['purchase_units'][0]['custom_id'] = $v; }
        if ($k === 'currency') { $d['purchase_units'][0]['amount']['currency_code'] = $v; }
        if ($k === 'value')    { $d['purchase_units'][0]['amount']['value'] = $v; }
        if ($k === 'drop_pu')  { $d['purchase_units'] = array(); }
    }
    return $d;
}
check('正しい応答は通る', kbil_paypal_check_order(pp_order(), $inv_for_pp)[0], true);

// 決済が完了していないもの
check('APPROVEDだけでは通さない',  kbil_paypal_check_order(pp_order(array('status' => 'APPROVED')), $inv_for_pp)[0], false);
check('CREATEDは通さない',        kbil_paypal_check_order(pp_order(array('status' => 'CREATED')), $inv_for_pp)[0], false);
check('VOIDEDは通さない',         kbil_paypal_check_order(pp_order(array('status' => 'VOIDED')), $inv_for_pp)[0], false);

// 金額のごまかし
check('1円でも少なければ通さない', kbil_paypal_check_order(pp_order(array('value' => '461999')), $inv_for_pp)[0], false);
check('1円でも多ければ通さない',   kbil_paypal_check_order(pp_order(array('value' => '462001')), $inv_for_pp)[0], false);
check('1円決済は通さない',         kbil_paypal_check_order(pp_order(array('value' => '1')), $inv_for_pp)[0], false);
check('金額が空なら通さない',      kbil_paypal_check_order(pp_order(array('value' => '')), $inv_for_pp)[0], false);

// 通貨のすり替え（462000ドルではなく462000円のつもりで払わせない）
check('USDは通さない', kbil_paypal_check_order(pp_order(array('currency' => 'USD')), $inv_for_pp)[0], false);

// 他人の（本物の）決済の使い回し
check('別の請求書宛ての決済は通さない',
    kbil_paypal_check_order(pp_order(array('custom_id' => $b['id'])), $inv_for_pp)[0], false);
check('custom_idが空なら通さない',
    kbil_paypal_check_order(pp_order(array('custom_id' => '')), $inv_for_pp)[0], false);
check('custom_idが無い応答は通さない',
    kbil_paypal_check_order(array('status' => 'COMPLETED', 'purchase_units' => array(array(
        'amount' => array('currency_code' => 'JPY', 'value' => '462000')))), $inv_for_pp)[0], false);

// 壊れた応答
check('明細が無ければ通さない', kbil_paypal_check_order(pp_order(array('drop_pu' => 1)), $inv_for_pp)[0], false);
check('空の応答は通さない',     kbil_paypal_check_order(array(), $inv_for_pp)[0], false);
check('配列でなければ通さない', kbil_paypal_check_order(null, $inv_for_pp)[0], false);

/* ============================================================
 * 9. 支払いページの厳しさ
 * ========================================================== */
check('既定はメール確認を求めない', kbil_pay_requires_email(), false);

/* ============================================================
 * 10. 請求書PDF
 * ========================================================== */
$inv = kbil_find($a['id']);
$pdf = kbil_invoice_pdf($inv);
check('PDFのヘッダ', substr($pdf, 0, 8), '%PDF-1.4');
check('PDFの終端', substr($pdf, -5), '%%EOF');
check('中身がある', strlen($pdf) > 3000, true);
// ASCIIとCJKは別々に描画されるので、それぞれの断片で確認する
check('宛名が入る', strpos($pdf, kbil_pdf_hex('株式会社アリス')) !== false, true);
check('品目が入る', strpos($pdf, kbil_pdf_hex('ホームページ制作費')) !== false, true);
check('2行目の品目も入る', strpos($pdf, kbil_pdf_hex('保守費')) !== false, true);
check('合計が入る', strpos($pdf, '462,000') !== false, true);
check('設定した登録番号が入る', strpos($pdf, 'T0000000000000') !== false, true);

// PDFは ASCII と日本語を別々のフォントで描くので、混在した文字列は
// 連続した1つのhexにならない。照合はどちらか一方だけの断片で行う。
check('設定した振込先の銀行名が入る', strpos($pdf, kbil_pdf_hex('サンプル銀行')) !== false, true);
check('設定した振込先の口座番号が入る', strpos($pdf, '1234567') !== false, true);
check('設定した口座名義が入る', strpos($pdf, kbil_pdf_hex('カ）サンプル')) !== false, true);
check('備考が入る', strpos($pdf, kbil_pdf_hex('月分としてご請求申し上げます。')) !== false, true);

// 明細が上限まで入っても壊れない（用紙からはみ出さないこと）
$kanji = array('一', '二', '三', '四', '五', '六', '七', '八', '九', '十', '十一', '十二');
$max_items = array();
for ($i = 0; $i < KBIL_MAX_ITEMS; $i++) {
    $max_items[] = array('name' => '品目' . $kanji[$i], 'qty' => 1, 'unit' => 1000);
}
$full = kbil_create('株式会社フル', '', 'full@example.test', $max_items, 10, '2026-08-05', '2026-09-04', '')[1];
$pdf_full = kbil_invoice_pdf(kbil_find($full['id']));
check('上限の明細でもPDFになる', substr($pdf_full, -5), '%%EOF');
check('最終行が入っている', strpos($pdf_full, kbil_pdf_hex('品目十二')) !== false, true);
// 明細の区切り線は1行につき1本（線幅0.4）。行数がそのまま出ているか。
check('12行ぶん描かれている', substr_count($pdf_full, "\n0.4 w "), KBIL_MAX_ITEMS);
/** PDFの内容から、文字を描いた y 座標を全部拾う。 */
function drawn_y($pdf) {
    preg_match_all('/1 0 0 1 [\d.-]+ ([\d.-]+) Tm/', $pdf, $m);
    return array_map('floatval', $m[1]);
}
// 12行を描いても、脚注(y=56)より下へはみ出さないこと。
// はみ出すと、その行は紙の外に出るか脚注と重なって読めなくなる。
check('用紙からはみ出さない', min(drawn_y($pdf_full)) >= 56, true);

// 最悪の組み合わせ（明細12行＋備考の上限400字）でも収まること
$long_note = str_repeat('備考の行です。', 8) . "\n" . str_repeat('二行目です。', 8) . "\n"
           . str_repeat('三行目です。', 8) . "\n" . str_repeat('四行目です。', 8);
$worst = kbil_create('株式会社ワースト', '担当者', 'worst@example.test', $max_items, 10,
                     '2026-08-05', '2026-09-04', $long_note)[1];
$pdf_worst = kbil_invoice_pdf(kbil_find($worst['id']));
check('最悪ケースでも脚注より下に描かない', min(drawn_y($pdf_worst)) >= 56, true);
check('最悪ケースでもPDFになる', substr($pdf_worst, -5), '%%EOF');

/* ============================================================
 * 11. メール本文
 * ========================================================== */
$body = kbil_mail_body($inv, 'https://example.test/kbilling_pay.php?t=' . $inv['token']);
check('本文に請求書番号', strpos($body, $inv['no']) !== false, true);
check('本文に支払いページURL', strpos($body, $inv['token']) !== false, true);
check('本文に振込先', strpos($body, 'サンプル銀行') !== false, true);
check('本文に金額', strpos($body, number_format($inv['total'])) !== false, true);
// メール確認を求めない設定なので、その案内は入らない
check('不要な確認案内を書かない', strpos($body, 'メールアドレスの確認') !== false, false);
check('件名に請求書番号', strpos(kbil_mail_subject($inv), $inv['no']) !== false, true);

// 宛先が無ければ送らない（誤送信しない）
$noaddr = $inv; $noaddr['email'] = '';
check('宛先なしでは送信しない', kbil_send_mail($noaddr, 'https://example.test/'), false);
$bad = $inv; $bad['email'] = 'not-an-email';
check('不正な宛先では送信しない', kbil_send_mail($bad, 'https://example.test/'), false);

/* 後片付け */
foreach (array('invoices.json', 'login_fails.json') as $f) { @unlink($tmp . '/' . $f); }
@rmdir($tmp);

echo $failures === 0 ? "\nすべて期待どおり\n" : "\n{$failures} 件が期待と違う\n";
exit($failures === 0 ? 0 : 1);
