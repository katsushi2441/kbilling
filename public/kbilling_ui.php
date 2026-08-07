<?php
/**
 * 画面の共通部分（見出し・配色・ヘッダー・フッター）。
 *
 * 管理画面とお客様の支払いページで同じ見た目にするために切り出している。
 * ここを直せば両方に効く。
 */
require_once __DIR__ . '/kbilling_lib.php';

function kbil_head($title, $description = '', $noindex = true) {
    $logo = kbil_logo();
    ?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo kbil_h($title); ?></title>
<?php if ($description !== ''): ?>
<meta name="description" content="<?php echo kbil_h($description); ?>">
<?php endif; ?>
<?php if ($noindex): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<style>
:root{
  --ink:#12202f; --ink-soft:#55697a; --bg:#f5fbfb; --panel:#e7f3f2; --line:#cde5e2;
  --teal:#12a99f; --teal-deep:#0a726b; --gold:#c98a1e; --gold-bg:#fbf2db; --gold-line:#ecd9a8;
  --shadow:0 14px 40px rgba(10,40,45,.10);
}
@media(prefers-color-scheme:dark){:root{
  --ink:#eaf3f3; --ink-soft:#9fb3ba; --bg:#0c1720; --panel:#12242a; --line:#1f3a3f;
  --teal:#2bd4c6; --teal-deep:#1c9e93; --gold:#f2c766; --gold-bg:#241b08; --gold-line:#4c3c17;
  --shadow:0 14px 40px rgba(0,0,0,.38);
}}
*{box-sizing:border-box;margin:0;padding:0}
body{color:var(--ink);background:var(--bg);line-height:1.85;
  font-family:"Hiragino Sans","Yu Gothic",Meiryo,system-ui,sans-serif;font-size:15px}
a{color:var(--teal-deep)}
.wrap{max-width:940px;margin:0 auto;padding:0 20px}
.wrap.narrow{max-width:720px}
header.site{border-bottom:1px solid var(--line);background:var(--panel)}
header.site .wrap{display:flex;align-items:center;gap:12px;padding:12px 20px;flex-wrap:wrap}
.brand{display:flex;gap:10px;align-items:center;color:inherit;text-decoration:none}
.brand .ico{width:36px;height:36px;border-radius:50%;overflow:hidden;border:2px solid var(--teal);flex:none}
.brand .ico img{width:100%;height:100%;object-fit:cover;display:block}
.brand strong{font-size:14.5px;font-weight:800;display:block;line-height:1.3}
.brand span{font-size:11px;color:var(--ink-soft)}
.hnav{margin-left:auto;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.chip{font-size:12px;font-weight:700;color:var(--ink-soft);border:1px solid var(--line);
  border-radius:999px;padding:4px 12px;background:var(--bg);text-decoration:none}
.btn{border:0;border-radius:999px;padding:11px 22px;font-weight:800;font-size:13.5px;cursor:pointer;
  display:inline-flex;align-items:center;gap:7px;text-decoration:none;
  background:linear-gradient(135deg,var(--teal),var(--teal-deep));color:#fff;
  box-shadow:0 10px 24px rgba(18,169,159,.26);font-family:inherit}
.btn:hover{color:#fff}
.btn.ghost{background:transparent;color:var(--ink-soft);border:1.5px solid var(--line);box-shadow:none}
.btn.small{padding:7px 15px;font-size:12.5px;box-shadow:none}
.btn:disabled{opacity:.5;cursor:not-allowed;box-shadow:none}
section{padding:26px 0}
h1{font-size:clamp(21px,3.4vw,28px);font-weight:800;line-height:1.35;margin-bottom:10px}
h2{font-size:18px;font-weight:800;margin-bottom:10px}
h3{font-size:14.5px;font-weight:800;margin-bottom:6px}
.lead{font-size:14px;color:var(--ink-soft);margin-bottom:18px}
.card{background:var(--panel);border:1.5px solid var(--line);border-radius:16px;
  padding:22px;box-shadow:var(--shadow);margin-bottom:16px}
.card.plain{background:var(--bg);box-shadow:none}
.gate{background:var(--gold-bg);border:1.5px solid var(--gold-line);border-radius:16px;
  padding:20px;margin-bottom:16px}
.price{font-size:clamp(25px,4.2vw,34px);font-weight:800}
.price small{font-size:13px;font-weight:700;color:var(--ink-soft);margin-left:8px}
label{display:block;font-size:13px;font-weight:700;margin:14px 0 5px}
input[type=text],input[type=email],input[type=password],input[type=date],input[type=number],select,textarea{
  width:100%;font:inherit;font-size:15px;color:inherit;background:var(--bg);
  border:1.5px solid var(--line);border-radius:9px;padding:10px 12px}
input:focus,select:focus,textarea:focus{outline:2px solid var(--teal);border-color:var(--teal)}
textarea{min-height:70px;resize:vertical}
.hint{font-size:12px;color:var(--ink-soft);margin-top:5px}
.req{color:#c0392b}
.err{background:#fdecea;border:1.5px solid #f5c6c2;color:#a3261b;border-radius:10px;
  padding:12px 14px;font-size:13.5px;margin-bottom:14px}
.ok{background:var(--gold-bg);border:1.5px solid var(--gold-line);border-radius:10px;
  padding:12px 14px;font-size:13.5px;margin-bottom:14px}
@media(prefers-color-scheme:dark){.err{background:#2a1512;border-color:#5c2a24;color:#ff9d92}}
.tag{font-size:11px;font-weight:800;border-radius:999px;padding:2px 10px;
  border:1.5px solid var(--line);color:var(--ink-soft);white-space:nowrap}
.tag.paid{border-color:var(--teal);color:var(--teal-deep)}
.tag.over{border-color:#d9534f;color:#c9302c}
table.kv{width:100%;border-collapse:collapse;font-size:13.5px}
table.kv th,table.kv td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:top}
table.kv th{width:34%;color:var(--ink-soft);font-size:12px;white-space:nowrap;font-weight:700}
.scroll{overflow-x:auto;-webkit-overflow-scrolling:touch}
table.items{width:100%;border-collapse:collapse;font-size:13.5px;min-width:520px}
table.items th,table.items td{padding:8px 10px;border-bottom:1px solid var(--line)}
table.items th{font-size:12px;color:var(--ink-soft);text-align:left;font-weight:700}
table.items td.num,table.items th.num{text-align:right;white-space:nowrap}
.row{display:flex;gap:12px;align-items:center;flex-wrap:wrap;
  border-top:1px solid var(--line);padding:12px 2px}
.row:first-child{border-top:0}
.row .grow{flex:1;min-width:190px}
.muted{color:var(--ink-soft);font-size:12.5px}
.demo-bar{background:var(--gold-bg);border-bottom:1px solid var(--gold-line);
  text-align:center;font-size:12.5px;padding:7px 16px;color:var(--gold)}
footer.site{text-align:center;color:var(--ink-soft);font-size:12.5px;
  padding:30px 20px 42px;border-top:1px solid var(--line);margin-top:18px}
</style>
<?php if (defined('KBIL_HEAD_EXTRA')) { echo KBIL_HEAD_EXTRA; } ?>
</head>
<body>
<?php if (kbil_is_demo()): ?>
<div class="demo-bar">デモサイトです。メールは実際には送信されません。入力内容は24時間で消えます。</div>
<?php endif;
}

function kbil_header($subtitle, $nav = '') {
    $logo = kbil_logo();
    ?>
<header class="site"><div class="wrap">
  <?php // 左上のタイトルは自分自身へのリンク。押せば初期表示に戻る ?>
  <a class="brand" href="<?php echo kbil_h(basename($_SERVER['SCRIPT_NAME'])); ?>">
    <?php if ($logo !== ''): ?>
    <span class="ico"><img src="<?php echo kbil_h($logo); ?>" alt=""></span>
    <?php endif; ?>
    <span><strong><?php echo kbil_h(kbil_app_title()); ?></strong>
      <span><?php echo kbil_h($subtitle); ?></span></span>
  </a>
  <?php if ($nav !== ''): ?><nav class="hnav"><?php echo $nav; ?></nav><?php endif; ?>
</div></header>
<?php
}

function kbil_footer() {
    $issuer = kbil_issuer();
    ?>
<footer class="site"><div class="wrap">
  <?php echo kbil_h($issuer['name']); ?>
  <?php if ($issuer['reg_no'] !== ''): ?>　登録番号 <?php echo kbil_h($issuer['reg_no']); ?><?php endif; ?>
</div></footer>
</body>
</html>
<?php
}
