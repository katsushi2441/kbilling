<?php
/**
 * Kurage Billing — 管理画面。請求書を発行して、お客様へ案内メールを送る。
 *
 * ここに入れるのは発行者だけ。お客様はメールで届いたURL（kbilling_pay.php）を
 * 開くだけで、ログインは要らない。
 */
require_once __DIR__ . '/kbilling_lib.php';
require_once __DIR__ . '/kbilling_auth.php';
require_once __DIR__ . '/kbilling_ui.php';
date_default_timezone_set('Asia/Tokyo');

kbil_auth_start();

/* ---- Xログインモードの出入り口 ---- */
if (kbil_auth_mode() === 'x') {
    if (isset($_GET['login']) && function_exists('url2ai_auth_login_url')) {
        header('Location: ' . url2ai_auth_login_url('/kbilling.php')); exit;
    }
    if (isset($_GET['logout']) && function_exists('url2ai_auth_logout_url')) {
        header('Location: ' . url2ai_auth_logout_url('/kbilling.php')); exit;
    }
} elseif (isset($_GET['logout'])) {
    kbil_auth_logout();
    header('Location: kbilling.php'); exit;
}

$is_admin = kbil_is_admin();
$missing  = kbil_setup_missing();
$notice   = '';
$error    = '';
$login_error = '';

/* ---- パスワードでログイン ---- */
if (!$is_admin && kbil_auth_mode() === 'password' && isset($_POST['login'])) {
    if (kbil_login_locked()) {
        $login_error = '試行回数の上限に達しました。15分ほどおいてからお試しください。';
    } elseif (kbil_password_login(isset($_POST['password']) ? $_POST['password'] : '')) {
        header('Location: kbilling.php'); exit;
    } else {
        $login_error = 'パスワードが違います。';
    }
}

$csrf = $is_admin ? kbil_csrf() : '';

/* ---- 請求書の発行 ---- */
$created = null;
if ($is_admin && isset($_POST['issue'])) {
    if (!kbil_csrf_ok(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        $error = '送信を確認できませんでした。もう一度お試しください。';
    } elseif ($missing) {
        $error = '設定が終わっていないため発行できません。';
    } else {
        $customer = trim((string)(isset($_POST['customer']) ? $_POST['customer'] : ''));
        $contact  = trim((string)(isset($_POST['contact'])  ? $_POST['contact']  : ''));
        $email    = trim((string)(isset($_POST['email'])    ? $_POST['email']    : ''));
        $rate     = (int)(isset($_POST['tax_rate']) ? $_POST['tax_rate'] : kbil_default_rate());
        $issued   = trim((string)(isset($_POST['issued_on']) ? $_POST['issued_on'] : ''));
        $due      = trim((string)(isset($_POST['due_on'])    ? $_POST['due_on']    : ''));
        $note     = trim((string)(isset($_POST['note'])      ? $_POST['note']      : ''));
        list($items, $item_err) = kbil_clean_items(isset($_POST['items']) ? $_POST['items'] : array());

        if ($customer === '') {
            $error = '請求先名をご入力ください。';
        } elseif (mb_strlen($customer, 'UTF-8') > 100 || mb_strlen($contact, 'UTF-8') > 60) {
            $error = '入力が長すぎます。';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '送信先のメールアドレスをご入力ください。';
        } elseif ($item_err !== '') {
            $error = $item_err;
        } elseif (!in_array($rate, array(0, 8, 10), true)) {
            $error = '消費税率は 0 / 8 / 10 のいずれかです。';
        } elseif ($issued === '' || !strtotime($issued)) {
            $error = '発行日をご入力ください。';
        } elseif ($due !== '' && !strtotime($due)) {
            $error = 'お支払期限の日付が正しくありません。';
        } elseif (mb_strlen($note, 'UTF-8') > 400) {
            $error = '備考が長すぎます（400文字まで）。';
        } else {
            if ($due === '') { $due = date('Y-m-d', strtotime($issued . ' +' . kbil_due_days() . ' days')); }
            $res = kbil_create($customer, $contact, $email, $items, $rate,
                               date('Y-m-d', strtotime($issued)), date('Y-m-d', strtotime($due)), $note);
            if (empty($res[0])) {
                $error = isset($res[1]) ? $res[1] : '発行できませんでした。';
            } else {
                $r = $res[1];
                $ok = kbil_send_mail($r, kbil_pay_url($r));
                kbil_mark_sent($r['id'], $ok);
                // POSTの再送で二重発行されないよう、GETへ逃がす
                header('Location: kbilling.php?created=' . rawurlencode($r['id'])); exit;
            }
        }
    }
}

/* ---- 入金の確認・取り消し・再送 ---- */
if ($is_admin && isset($_POST['action'])) {
    if (!kbil_csrf_ok(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        $error = '送信を確認できませんでした。';
    } else {
        $id = (string)(isset($_POST['id']) ? $_POST['id'] : '');
        $target = kbil_find($id);
        if (!$target) {
            $error = '請求書が見つかりません。';
        } elseif ($_POST['action'] === 'paid') {
            $note = trim((string)(isset($_POST['paid_note']) ? $_POST['paid_note'] : ''));
            $res = kbil_mark_paid($id, 'bank', $note !== '' ? $note : date('Y-m-d') . ' 入金確認');
            if (empty($res[0])) { $error = $res[1]; }
            else {
                $notice = $target['no'] . ' の入金を記録しました。';
                if (kbil_send_paid_mail(kbil_find($id))) { $notice .= 'お礼のメールを送信しました。'; }
            }
        } elseif ($_POST['action'] === 'unpaid') {
            $res = kbil_unmark_paid($id);
            if (empty($res[0])) { $error = $res[1]; } else { $notice = $target['no'] . ' の入金記録を取り消しました。'; }
        } elseif ($_POST['action'] === 'resend') {
            $ok = kbil_send_mail($target, kbil_pay_url($target));
            kbil_mark_sent($id, $ok);
            $notice = $ok
                ? $target['no'] . ' の案内メールを再送しました。'
                : $target['no'] . ' のメール送信に失敗しました。サーバーの mail() 設定をご確認ください。';
        }
    }
}

if ($is_admin && kbil_is_demo()) { kbil_demo_cleanup(); }

if ($is_admin && isset($_GET['created'])) { $created = kbil_find((string)$_GET['created']); }
$list = $is_admin ? kbil_recent() : array();
$warnings = $is_admin ? kbil_payment_warnings() : array();

/* ================= 画面 ================= */

$nav = '';
if ($is_admin) {
    $nav = kbil_auth_mode() === 'x'
        ? '<a class="chip" href="?logout=1">ログアウト</a>'
        : '<a class="chip" href="?logout=1">ログアウト</a>';
}
kbil_head(kbil_app_title(), '', true);
kbil_header('管理画面', $nav);
?>
<main class="wrap">

<?php if (!$is_admin): /* ================= ログイン ================= */ ?>
<section class="wrap narrow" style="padding-left:0;padding-right:0">
  <h1>ログイン</h1>
  <p class="lead">請求書を発行できるのは管理者だけです。</p>

  <?php if ($missing): ?>
  <div class="err">
    <b>設置が終わっていません。</b>次の設定が必要です。
    <ul style="margin:8px 0 0 18px">
      <?php foreach ($missing as $m): ?><li><?php echo kbil_h($m); ?></li><?php endforeach; ?>
    </ul>
    <p style="margin-top:8px;font-size:12.5px">
      <code>kbilling_config.php.example</code> をコピーして <code>kbilling_config.php</code> を作り、値を入れてください。</p>
  </div>
  <?php endif; ?>

  <?php if ($login_error !== ''): ?><p class="err"><?php echo kbil_h($login_error); ?></p><?php endif; ?>

  <?php if (kbil_auth_mode() === 'x'): ?>
    <div class="card"><p style="font-size:14px">𝕏 アカウントでログインしてください。</p>
      <p style="margin-top:14px"><a class="btn" href="?login=1">𝕏 でログイン</a></p></div>
  <?php else: ?>
    <form method="post" class="card">
      <label for="password">管理パスワード</label>
      <input type="password" id="password" name="password" required autocomplete="current-password" autofocus>
      <button type="submit" name="login" value="1" class="btn" style="margin-top:16px">ログイン</button>
    </form>
  <?php endif; ?>
</section>

<?php else: /* ================= 管理 ================= */ ?>

<section>
  <?php if ($notice !== ''): ?><p class="ok"><?php echo kbil_h($notice); ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="err"><?php echo kbil_h($error); ?></p><?php endif; ?>

  <?php foreach ($warnings as $w): ?>
    <p class="err"><?php echo kbil_h($w); ?></p>
  <?php endforeach; ?>

  <?php if ($created): ?>
    <div class="card">
      <h2>請求書 <?php echo kbil_h($created['no']); ?> を発行しました</h2>
      <p style="font-size:14px">
        <?php echo kbil_h($created['customer']); ?> 御中 ／
        ￥<?php echo number_format((int)$created['total']); ?>（税込）</p>
      <p style="font-size:13.5px;margin-top:6px">
        <?php if (kbil_is_demo()): ?>
          <b>デモのため、メールは送信していません。</b>
        <?php elseif (!empty($created['mail_ok'])): ?>
          <b><?php echo kbil_h($created['email']); ?></b> へ案内メールを送信しました。
        <?php else: ?>
          <b style="color:#c0392b">メールの送信に失敗しました。</b>
          下記のURLをお客様へお伝えください。サーバーの <code>mail()</code> 設定もご確認ください。
        <?php endif; ?>
      </p>
      <label for="payurl">お客様がお支払いに使うURL</label>
      <input type="text" id="payurl" readonly onclick="this.select()"
             value="<?php echo kbil_h(kbil_pay_url($created)); ?>">
      <p class="hint">このURLをご存じの方は請求内容を見られます。取り扱いにご注意ください。</p>

      <?php if (kbil_is_demo()): ?>
      <details style="margin-top:14px">
        <summary style="cursor:pointer;font-size:13.5px;font-weight:700">送信されるメールの内容を見る</summary>
        <pre style="white-space:pre-wrap;font-size:12.5px;background:var(--bg);border:1px solid var(--line);
                    border-radius:9px;padding:14px;margin-top:10px;overflow-x:auto"><?php
          echo kbil_h(kbil_mail_subject($created) . "\n\n" . kbil_mail_body($created, kbil_pay_url($created)));
        ?></pre>
      </details>
      <?php endif; ?>
      <p style="margin-top:14px">
        <a class="btn ghost small" href="<?php echo kbil_h(kbil_pay_url($created)); ?>" target="_blank" rel="noopener">
          お客様の画面を開く</a></p>
    </div>
  <?php endif; ?>

  <h1>請求書の発行</h1>

  <form method="post" class="card">
    <input type="hidden" name="csrf" value="<?php echo kbil_h($csrf); ?>">

    <label for="customer">請求先名（会社名・屋号・氏名）<span class="req">*</span></label>
    <input type="text" id="customer" name="customer" maxlength="100" required placeholder="例：株式会社サンプル">
    <p class="hint">請求書の宛名になります。「御中」は自動で付きます。</p>

    <label for="contact">ご担当者名（任意）</label>
    <input type="text" id="contact" name="contact" maxlength="60" placeholder="例：山田 太郎">

    <label for="email">送信先メールアドレス<span class="req">*</span></label>
    <input type="email" id="email" name="email" maxlength="200" required placeholder="keiri@example.co.jp">
    <p class="hint">ここへ支払いページのURLを送ります。</p>

    <label>明細<span class="req">*</span>（単価は<b>税抜</b>）</label>
    <div class="scroll">
    <table class="items" id="itemTable">
      <thead><tr><th>品目</th><th class="num" style="width:90px">数量</th>
        <th class="num" style="width:130px">単価（税抜）</th></tr></thead>
      <tbody>
      <?php for ($i = 0; $i < 3; $i++): ?>
        <tr>
          <td><input type="text" name="items[<?php echo $i; ?>][name]" maxlength="60"
                     placeholder="<?php echo $i === 0 ? '例：ホームページ制作費' : ''; ?>"></td>
          <td><input type="text" name="items[<?php echo $i; ?>][qty]" inputmode="numeric"
                     value="<?php echo $i === 0 ? '1' : ''; ?>"></td>
          <td><input type="text" name="items[<?php echo $i; ?>][unit]" inputmode="numeric"></td>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
    </div>
    <p class="hint">空の行は無視されます。<button type="button" class="btn ghost small" id="addRow"
       style="margin-left:8px">行を追加</button></p>

    <label for="tax_rate">消費税率</label>
    <select id="tax_rate" name="tax_rate">
      <option value="10"<?php echo kbil_default_rate() === 10 ? ' selected' : ''; ?>>10%</option>
      <option value="8"<?php echo kbil_default_rate() === 8 ? ' selected' : ''; ?>>8%（軽減税率）</option>
      <option value="0"<?php echo kbil_default_rate() === 0 ? ' selected' : ''; ?>>0%（対象外）</option>
    </select>

    <label for="issued_on">発行日<span class="req">*</span></label>
    <input type="date" id="issued_on" name="issued_on" required value="<?php echo date('Y-m-d'); ?>">

    <label for="due_on">お支払期限</label>
    <input type="date" id="due_on" name="due_on"
           value="<?php echo date('Y-m-d', strtotime('+' . kbil_due_days() . ' days')); ?>">
    <p class="hint">空欄なら発行日から <?php echo kbil_due_days(); ?> 日後になります。</p>

    <label for="note">備考（任意）</label>
    <textarea id="note" name="note" maxlength="400" placeholder="例：3月分としてご請求申し上げます。"></textarea>

    <button type="submit" name="issue" value="1" class="btn" style="margin-top:18px">
      請求書を発行してメールを送る</button>
  </form>

  <h2 style="margin-top:30px">発行した請求書</h2>
  <?php if (!$list): ?>
    <p class="muted">まだ1件もありません。</p>
  <?php else: ?>
    <div class="card">
    <?php foreach ($list as $r): $over = kbil_is_overdue($r); ?>
      <div class="row">
        <div class="grow">
          <b><?php echo kbil_h($r['no']); ?></b>　<?php echo kbil_h($r['customer']); ?> 御中<br>
          <span class="muted">
            ￥<?php echo number_format((int)$r['total']); ?>（税込）
            ・発行 <?php echo kbil_h($r['issued_on']); ?>
            ・期限 <?php echo kbil_h($r['due_on']); ?>
            <?php if ($r['status'] === 'paid' && !empty($r['paid_at'])): ?>
              ・入金 <?php echo date('Y/n/j', (int)$r['paid_at']); ?>
              （<?php echo $r['paid_via'] === 'paypal' ? 'PayPal' : '振込'; ?>）
            <?php elseif (empty($r['mail_ok']) && !kbil_is_demo()): ?>
              ・<span style="color:#c0392b">メール未送信</span>
            <?php endif; ?>
          </span>
        </div>
        <span class="tag <?php echo $r['status'] === 'paid' ? 'paid' : ($over ? 'over' : ''); ?>">
          <?php echo $r['status'] === 'paid' ? '入金済' : ($over ? '期限超過' : '未入金'); ?></span>
        <a class="chip" href="<?php echo kbil_h(kbil_pay_url($r)); ?>" target="_blank" rel="noopener">支払いページ</a>

        <?php if ($r['status'] !== 'paid'): ?>
          <form method="post" style="margin:0;display:flex;gap:6px;align-items:center">
            <input type="hidden" name="csrf" value="<?php echo kbil_h($csrf); ?>">
            <input type="hidden" name="id" value="<?php echo kbil_h($r['id']); ?>">
            <input type="text" name="paid_note" placeholder="メモ（任意）" style="width:130px;padding:6px 9px;font-size:12.5px">
            <button type="submit" name="action" value="paid" class="btn small">入金確認</button>
          </form>
          <form method="post" style="margin:0">
            <input type="hidden" name="csrf" value="<?php echo kbil_h($csrf); ?>">
            <input type="hidden" name="id" value="<?php echo kbil_h($r['id']); ?>">
            <button type="submit" name="action" value="resend" class="btn ghost small">再送</button>
          </form>
        <?php elseif ($r['paid_via'] !== 'paypal'): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="csrf" value="<?php echo kbil_h($csrf); ?>">
            <input type="hidden" name="id" value="<?php echo kbil_h($r['id']); ?>">
            <button type="submit" name="action" value="unpaid" class="btn ghost small">入金を取消</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<script>
// 明細の行を足す。JavaScriptが無くても3行は使えるようにしてある。
(function () {
  var btn = document.getElementById('addRow');
  var body = document.querySelector('#itemTable tbody');
  if (!btn || !body) { return; }
  btn.addEventListener('click', function () {
    var n = body.rows.length;
    if (n >= <?php echo KBIL_MAX_ITEMS; ?>) { btn.disabled = true; return; }
    var tr = body.insertRow();
    ['name', 'qty', 'unit'].forEach(function (key) {
      var td = tr.insertCell();
      var input = document.createElement('input');
      input.type = 'text';
      input.name = 'items[' + n + '][' + key + ']';
      if (key !== 'name') { input.inputMode = 'numeric'; }
      td.appendChild(input);
    });
    if (body.rows.length >= <?php echo KBIL_MAX_ITEMS; ?>) { btn.disabled = true; }
  });
})();
</script>

<?php endif; ?>
</main>
<?php kbil_footer(); ?>
