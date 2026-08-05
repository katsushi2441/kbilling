<?php
/**
 * お客様が開く支払いページ。
 *
 *   https://example.com/kbilling_pay.php?t=<32桁のトークン>
 *
 * ここでできること
 *   - 請求内容の確認
 *   - 請求書PDFのダウンロード
 *   - PayPal（クレジットカード可）での支払い
 *   - 振込先の確認
 *
 * 【ログインを求めない】
 * 請求書を受け取る取引先に、こちらのアカウントを作らせない。URLそのものが
 * 合鍵になる。推測されないよう、トークンは16バイト（32桁）使っている。
 * 情報漏洩の方を重く見る場合は KBIL_PAY_REQUIRE_EMAIL=true にすると、
 * 宛先メールアドレスの確認も要求する。
 */
require_once __DIR__ . '/kbilling_lib.php';
require_once __DIR__ . '/kbilling_paypal.php';
require_once __DIR__ . '/kbilling_ui.php';
date_default_timezone_set('Asia/Tokyo');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('KBILPAYSESSID');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    @session_set_cookie_params(0, '/', '', $secure, true);
    @session_start();
}
if (empty($_SESSION['kbil_pay_csrf'])) { $_SESSION['kbil_pay_csrf'] = kbil_random_hex(24); }
$csrf = (string)$_SESSION['kbil_pay_csrf'];

$token = isset($_GET['t']) ? (string)$_GET['t'] : '';
$r = kbil_find_by_token($token);

/* 見つからないものは、理由を明かさず同じ応答にする（総当たりに情報を与えない）。 */
if (!$r) {
    http_response_code(404);
    kbil_head('お探しのページはありません');
    kbil_header('');
    echo '<main class="wrap narrow"><section><h1>お探しのページはありません</h1>'
       . '<p class="lead">URLが正しいかご確認ください。'
       . 'お心当たりのない場合は、お手数ですが送信元へお問い合わせください。</p></section></main>';
    kbil_footer();
    exit;
}

/* ---- メールアドレスの確認（KBIL_PAY_REQUIRE_EMAIL のときだけ） ---- */

$authed = !kbil_pay_requires_email();
$locked = kbil_pay_requires_email() && (int)$r['fail'] >= KBIL_MAX_FAIL;
$auth_err = '';

if (kbil_pay_requires_email()) {
    if (!empty($_SESSION['kbil_pay_ok'][$r['id']])) { $authed = true; }
    if (!$authed && !$locked && isset($_POST['confirm_email'])) {
        if (!hash_equals($csrf, isset($_POST['csrf']) ? (string)$_POST['csrf'] : '')) {
            $auth_err = '送信を確認できませんでした。もう一度お試しください。';
        } else {
            $input = kbil_norm_email(isset($_POST['email']) ? $_POST['email'] : '');
            if ($input !== '' && hash_equals((string)$r['email'], $input)) {
                $_SESSION['kbil_pay_ok'][$r['id']] = true;
                $authed = true;
                kbil_mark_view($r['id']);
                $r = kbil_find($r['id']);
            } else {
                $n = kbil_mark_fail($r['id']);
                usleep(400000);
                $locked = $n >= KBIL_MAX_FAIL;
                $auth_err = $locked
                    ? '確認の失敗が続いたため、このページを閉じました。送信元へお問い合わせください。'
                    : 'メールアドレスが一致しません。請求書の案内メールが届いた宛先をご入力ください。';
            }
        }
    }
} else {
    kbil_mark_view($r['id']);
}

/* ---- 請求書PDF ---- */

if (isset($_GET['pdf'])) {
    if (!$authed) { http_response_code(403); exit('確認が必要です'); }
    require_once __DIR__ . '/kbilling_pdf.php';
    $pdf = kbil_invoice_pdf($r);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $r['no'] . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store, max-age=0');
    echo $pdf;
    exit;
}

/* ---- PayPal決済の記録 ----
 *
 * ブラウザからの申告だけでは入金にしない。PayPal の Orders API に照会して
 * 状態・通貨・金額・請求書IDが全部合ったときだけ記録する。
 */
if (isset($_GET['paid'])) {
    header('Content-Type: application/json; charset=utf-8');
    $reply = function ($ok, $message) {
        echo json_encode(array('ok' => $ok, 'message' => $message), JSON_UNESCAPED_UNICODE);
        exit;
    };
    if (!hash_equals($csrf, isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? (string)$_SERVER['HTTP_X_CSRF_TOKEN'] : '')) {
        http_response_code(403);
        $reply(false, '送信を確認できませんでした。画面を再読み込みしてお試しください。');
    }
    if (!$authed) { http_response_code(403); $reply(false, '確認が必要です。'); }
    if ($r['status'] === 'paid') { $reply(true, 'すでにお支払い済みです。'); }

    $input = json_decode((string)file_get_contents('php://input'), true);
    $pp_id = isset($input['paypal_order_id']) ? (string)$input['paypal_order_id'] : '';

    if (kbil_paypal_order_used($pp_id, $r['id'])) {
        $reply(false, 'この決済はすでに別の請求書で使われています。');
    }
    list($ok, $msg) = kbil_paypal_verify($pp_id, $r);
    if (!$ok) {
        // 検証に落ちたものは入金にしない。お客様には決済事業者側の記録が残るので、
        // ここで黙って通すより、連絡してもらった方が早く解決する。
        $reply(false, $msg . ' お手数ですが送信元へご連絡ください。');
    }
    $res = kbil_mark_paid($r['id'], 'paypal', 'PayPal決済（サーバー側で照合済み）', $pp_id);
    if (empty($res[0])) { $reply(false, isset($res[1]) ? $res[1] : '記録できませんでした。'); }
    kbil_send_paid_mail(kbil_find($r['id']));
    $reply(true, 'お支払いを確認しました。');
}

/* ---- デモ専用：入金後の画面を見てもらうための疑似記録 ----
 *
 * デモでは PayPal の本番決済を通せないので、入金済みの表示を確認できるように
 * ボタンを置いている。デモモード以外では絶対に動かない。
 */
if (kbil_is_demo() && isset($_POST['demo_paid']) && $authed) {
    if (hash_equals($csrf, isset($_POST['csrf']) ? (string)$_POST['csrf'] : '')) {
        kbil_mark_paid($r['id'], 'bank', 'デモ：入金を疑似記録');
        header('Location: ?t=' . rawurlencode($token));
        exit;
    }
}

$issuer = kbil_issuer();
$overdue = kbil_is_overdue($r);

kbil_head('請求書 ' . $r['no'] . ' | ' . $issuer['name']);
kbil_header('お支払いのご案内');
?>
<main class="wrap narrow">
<section>

<?php if (!$authed): /* ---- 確認前は、誰宛のいくらかを一切出さない ---- */ ?>

  <h1>ご確認</h1>
  <p class="lead">請求書をご確認いただくため、案内メールが届いた<b>メールアドレス</b>をご入力ください。</p>
  <?php if ($auth_err !== ''): ?><p class="err"><?php echo kbil_h($auth_err); ?></p><?php endif; ?>
  <?php if (!$locked): ?>
  <form method="post" class="card">
    <input type="hidden" name="csrf" value="<?php echo kbil_h($csrf); ?>">
    <label for="email">メールアドレス</label>
    <input type="email" id="email" name="email" required autocomplete="email" placeholder="you@example.co.jp">
    <p class="hint">このページのURLをご存じでも、宛先が一致しないと開きません。</p>
    <button type="submit" name="confirm_email" value="1" class="btn" style="margin-top:16px">確認する</button>
  </form>
  <?php endif; ?>

<?php else: ?>

  <h1>請求書 <?php echo kbil_h($r['no']); ?></h1>
  <p class="lead"><?php echo kbil_h($issuer['name']); ?> より、下記のとおりご請求申し上げます。</p>

  <?php if ($r['status'] === 'paid'): ?>
    <p class="ok"><b>お支払いを確認しております。</b>ありがとうございました。</p>
  <?php elseif ($overdue): ?>
    <p class="err">お支払期限（<?php echo kbil_h($r['due_on']); ?>）を過ぎております。行き違いの際はご容赦ください。</p>
  <?php endif; ?>

  <div class="gate">
    <p class="price">￥<?php echo number_format((int)$r['total']); ?><small>（税込）</small></p>
    <?php if (!empty($r['due_on'])): ?>
    <p style="font-size:13.5px;margin-top:4px">お支払期限：<b><?php echo kbil_h($r['due_on']); ?></b></p>
    <?php endif; ?>
    <p style="margin-top:14px">
      <a class="btn" href="?t=<?php echo kbil_h($token); ?>&amp;pdf=1">請求書PDFをダウンロード</a></p>
  </div>

  <div class="card">
    <h2>ご請求の内訳</h2>
    <div class="scroll">
    <table class="items">
      <thead><tr>
        <th>品目</th><th class="num">数量</th><th class="num">単価</th><th class="num">金額</th>
      </tr></thead>
      <tbody>
      <?php foreach ($r['items'] as $it): ?>
        <tr>
          <td><?php echo kbil_h($it['name']); ?></td>
          <td class="num"><?php echo number_format((int)$it['qty']); ?></td>
          <td class="num"><?php echo number_format((int)$it['unit']); ?></td>
          <td class="num"><?php echo number_format((int)$it['qty'] * (int)$it['unit']); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="3" class="num">小計</td>
            <td class="num">￥<?php echo number_format((int)$r['net']); ?></td></tr>
        <tr><td colspan="3" class="num">消費税<?php echo (int)$r['tax_rate'] > 0 ? '（' . (int)$r['tax_rate'] . '%）' : ''; ?></td>
            <td class="num">￥<?php echo number_format((int)$r['tax']); ?></td></tr>
        <tr><td colspan="3" class="num"><b>合計（税込）</b></td>
            <td class="num"><b>￥<?php echo number_format((int)$r['total']); ?></b></td></tr>
      </tfoot>
    </table>
    </div>
    <?php if (!empty($r['note'])): ?>
      <p class="hint" style="margin-top:12px;white-space:pre-wrap"><?php echo kbil_h($r['note']); ?></p>
    <?php endif; ?>
  </div>

  <?php if ($r['status'] !== 'paid'): ?>

    <?php if ($issuer['bank'] !== ''): ?>
    <div class="card">
      <h2>銀行振込でお支払い</h2>
      <table class="kv">
        <tr><th>振込先</th><td><?php echo kbil_h($issuer['bank']); ?></td></tr>
        <?php if ($issuer['holder'] !== ''): ?>
        <tr><th>口座名義</th><td><?php echo kbil_h($issuer['holder']); ?></td></tr>
        <?php endif; ?>
        <tr><th>金額</th><td>￥<?php echo number_format((int)$r['total']); ?>（税込）</td></tr>
      </table>
      <p class="hint">振込手数料はお客様のご負担でお願いいたします。
        お振込みの際は、請求書番号（<?php echo kbil_h($r['no']); ?>）をご記入いただけますと確認が早くなります。</p>
    </div>
    <?php endif; ?>

    <?php if (kbil_paypal_enabled()): ?>
    <div class="card">
      <h2>PayPalでお支払い</h2>
      <div id="paypalButtons"></div>
      <p class="hint" id="payMsg">クレジットカードもPayPal経由でご利用いただけます。</p>
    </div>
    <?php elseif (kbil_is_demo()): ?>
    <div class="card">
      <h2>PayPalでお支払い</h2>
      <p class="hint">デモのため、PayPalの決済ボタンは表示していません。
        実際に設置すると、ここに決済ボタンが出ます。</p>
      <form method="post" style="margin-top:12px">
        <input type="hidden" name="csrf" value="<?php echo kbil_h($csrf); ?>">
        <button type="submit" name="demo_paid" value="1" class="btn ghost small">
          【デモ専用】入金後の画面を見る</button>
      </form>
    </div>
    <?php endif; ?>

  <?php endif; ?>

  <div class="card plain">
    <table class="kv">
      <tr><th>請求書番号</th><td><?php echo kbil_h($r['no']); ?></td></tr>
      <tr><th>発行日</th><td><?php echo kbil_h($r['issued_on']); ?></td></tr>
      <tr><th>請求先</th><td><?php echo kbil_h($r['customer']); ?> 御中
        <?php if (!empty($r['contact'])): ?><br><?php echo kbil_h($r['contact']); ?> 様<?php endif; ?></td></tr>
      <tr><th>発行元</th><td><?php echo kbil_h($issuer['name']); ?>
        <?php if ($issuer['reg_no'] !== ''): ?><br><span class="muted">登録番号 <?php echo kbil_h($issuer['reg_no']); ?></span><?php endif; ?>
        <?php if ($issuer['mail'] !== ''): ?><br><span class="muted"><?php echo kbil_h($issuer['mail']); ?></span><?php endif; ?>
      </td></tr>
    </table>
  </div>

<?php endif; ?>
</section>
</main>

<?php if ($authed && $r['status'] !== 'paid' && kbil_paypal_enabled()): ?>
<script src="https://www.paypal.com/sdk/js?client-id=<?php echo rawurlencode(kbil_paypal_client_id()); ?>&currency=JPY"></script>
<script>
(function () {
  var box = document.getElementById('paypalButtons');
  if (!box || !window.paypal) { return; }
  var msg = document.getElementById('payMsg');
  paypal.Buttons({
    style: { layout: 'vertical', height: 42 },
    createOrder: function (data, actions) {
      return actions.order.create({
        purchase_units: [{
          description: <?php echo json_encode('請求書 ' . $r['no'], JSON_UNESCAPED_UNICODE); ?>,
          // サーバー側の照合に使う。どの請求書への支払いかをPayPal側にも持たせる。
          custom_id: <?php echo json_encode($r['id']); ?>,
          amount: { currency_code: 'JPY', value: '<?php echo (int)$r['total']; ?>' }
        }]
      });
    },
    onApprove: function (data, actions) {
      return actions.order.capture().then(function (details) {
        msg.textContent = 'お支払いを確認しています…';
        return fetch('?t=' + encodeURIComponent(<?php echo json_encode($token); ?>) + '&paid=1', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': <?php echo json_encode($csrf); ?> },
          body: JSON.stringify({ paypal_order_id: details.id })
        }).then(function (res) { return res.json(); }).then(function (out) {
          msg.textContent = out.message;
          if (out.ok) { setTimeout(function () { location.reload(); }, 1200); }
        });
      });
    },
    onError: function () {
      msg.textContent = 'PayPalの決済でエラーが発生しました。時間をおいてお試しください。';
    }
  }).render('#paypalButtons');
})();
</script>
<?php endif; ?>
<?php kbil_footer(); ?>
