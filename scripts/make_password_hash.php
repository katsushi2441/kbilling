<?php
// 管理パスワードのハッシュを作る。出てきた1行を kbilling_config.php に貼る。
//
//   php scripts/make_password_hash.php 'あなたのパスワード'
//
// パスワードそのものは保存しない。ハッシュだけを設定に置く。

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$pw = isset($argv[1]) ? (string)$argv[1] : '';
if ($pw === '') {
    fwrite(STDERR, "使い方: php scripts/make_password_hash.php 'あなたのパスワード'\n");
    exit(1);
}
if (strlen($pw) < 8) {
    fwrite(STDERR, "8文字以上にしてください。\n");
    exit(1);
}

echo "kbilling_config.php にこの1行を貼ってください:\n\n";
echo "define('KBIL_ADMIN_PASSWORD_HASH', '" . password_hash($pw, PASSWORD_DEFAULT) . "');\n";
