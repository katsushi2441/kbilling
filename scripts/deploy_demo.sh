#!/usr/bin/env bash
# デモサイトを公開する。
#   https://proto.exbridge.jp/kbilling/
#
# デモ設定は KBIL_DEMO=true なので、メールは実際には送信されない。
# PayPalも設定していないので、本番の決済は一切通らない。
#
# heteml 上の素のPHPで動く。常駐プロセスもDBもポートも使わない。
set -euo pipefail
cd "$(dirname "$0")/.."
set -a
. /home/kojima/work/aixec/.env
set +a

remote="/web/proto_exbridge_jp/kbilling"

upload() {  # upload <local> <remote-path>
  curl --fail --silent --show-error --ftp-create-dirs -T "$1" \
    "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${remote}/${2}"
  echo "deployed: ${2}"
}

for f in kbilling.php kbilling_pay.php kbilling_auth.php kbilling_lib.php \
         kbilling_pdf.php kbilling_paypal.php kbilling_ui.php; do
  upload "public/$f" "$f"
done

upload demo/kbilling_config.php kbilling_config.php
upload demo/index.php           index.php
upload demo/.htaccess           .htaccess
# 台帳(請求先・メール・金額が入る)をWebから直接読ませない
upload public/kbilling_data/.htaccess kbilling_data/.htaccess

echo
echo "published: https://proto.exbridge.jp/kbilling/"
