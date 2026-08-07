<?php
// デモ用の設定。proto.exbridge.jp/kbilling/ に置く。
// 本番とは別インスタンス・別台帳。

// メールを実際に送らない。公開デモに送信フォームを置くと、誰でも任意の
// アドレス宛に送れる踏み台になり、ドメインの送信評価が落ちる。
define('KBIL_DEMO', true);

define('KBIL_APP_TITLE', '請求書の発行・集金（デモ）');
define('KBIL_NAME', 'デモ商事株式会社');
define('KBIL_MAIL_FROM', 'demo@example.co.jp');
define('KBIL_INVOICE_REG_NO', 'T0000000000000');
define('KBIL_ZIP',  '〒100-0001');
define('KBIL_ADDR', '東京都千代田区サンプル1-2-3');

// 振込先。デモなので実在しない口座を入れている。
define('KBIL_BANK',   'サンプル銀行 本店営業部　普通 1234567');
define('KBIL_HOLDER', 'カ）デモシヨウジ');

// PayPalは設定しない。デモで本番の決済を通すわけにはいかないため、
// 支払いページでは「実際に設置すると決済ボタンが出ます」と案内する。

// デモのパスワードは商品ページに公開する（ログイン画面も商品の一部なので隠さない）
define('KBIL_ADMIN_PASSWORD_HASH', '$2y$10$HKgG8xMF/pL3FhpcbE96cO7zmgtFPDVVcbOw9OJkW2s7RKFQiu2Ze');

/* ------------------------------------------------------------------
 * デモサイトのアクセス解析
 *
 * これは **デモ用の設定ファイル** にだけ書く。配布物の
 * kbilling_config.php.example には値を入れない。
 * 入れてしまうと、購入されたお客様のサイトの訪問者が当社の解析へ
 * 送られてしまう。定数が未定義なら画面は何も出力しない。
 * ---------------------------------------------------------------- */
define('KBIL_HEAD_EXTRA', <<<'HTML'
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','G-BP0650KDFR');</script>
<script>(function(){var s=document.createElement('script');s.src='https://kurage.exbridge.jp/simpletrack.php?url='+encodeURIComponent(location.href)+'&ref='+encodeURIComponent(document.referrer);document.head.appendChild(s)})();</script>
HTML
);
