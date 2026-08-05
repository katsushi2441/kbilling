#!/usr/bin/env bash
# ダウンロード販売用のパッケージを作る。
#
#   出力: outputs/kbilling-vwork-<日付>.zip
#
# GitHub には動くコードだけを置いている（.gitignore）。このzipには、それに加えて
# 教材（docs/）・AI向け手順書（AGENTS.md / CLAUDE.md / INSTALL.md）・VWork一式を入れる。
# **この差分が価格の根拠**なので、中身を減らさないこと。
#
# 顧客データと実行時設定は入れない（public/kbilling_data/ と kbilling_config.php）。
set -euo pipefail
cd "$(dirname "$0")/.."

STAMP="$(date +%Y%m%d)"
NAME="kbilling-vwork-${STAMP}"
OUT="outputs/${NAME}"

# 中身が壊れていたら詰めない
php scripts/check_kbilling.php > /dev/null || { echo "テストが通りません。中止します。" >&2; exit 1; }

rm -rf "$OUT" "outputs/${NAME}.zip"
mkdir -p "$OUT/public/kbilling_data" "$OUT/scripts"

# --- 動くシステム（設定と台帳は除く） ---
for f in kbilling.php kbilling_pay.php kbilling_auth.php kbilling_lib.php \
         kbilling_pdf.php kbilling_paypal.php kbilling_ui.php kbilling_config.php.example; do
  cp "public/$f" "$OUT/public/$f"
done
cp public/kbilling_data/.htaccess "$OUT/public/kbilling_data/.htaccess"

# --- 有償パッケージにだけ入るもの ---
cp AGENTS.md CLAUDE.md INSTALL.md SUPPORT.md README.md LICENSE "$OUT/"
cp -r docs  "$OUT/docs"
cp -r vwork "$OUT/vwork"

# --- 道具 ---
cp scripts/make_password_hash.php scripts/check_kbilling.php "$OUT/scripts/"

# --- 入っていてはいけないものが混ざっていないか確かめる ---
ng=0
for bad in "kbilling_config.php" "invoices.json" "login_fails.json" ".env" "cookies.txt"; do
  if find "$OUT" -name "$bad" | grep -q .; then
    echo "NG: $bad が混ざっています" >&2; ng=1
  fi
done
# --- 検査2: コードに口座らしきものを書いていないか ---
#
# 走査するのは**コードだけ**。docs/ と INSTALL.md には「◯◯銀行 ◯◯支店　普通 1234567」
# のような記載例が必要で、そこまで落とすと手順書が書けなくなる。
# 実際に事故るのは、コピー元の値がコードに残ることの方。
# check_kbilling.php は除く。あそこの「サンプル銀行…普通 1234567」は、
# 「設定すれば出る／設定しなければ出ない」を確かめるための作り物で、これが無いと
# 肝心の回帰テストが書けない。
code_files=$(find "$OUT/public" "$OUT/scripts" -type f \
  ! -name '*.example' ! -name 'check_kbilling.php' 2>/dev/null)
if [[ -n "$code_files" ]]; then
  for pat in '(普通|当座)[[:space:]]*[0-9]{6,}' '銀行[^[:space:]"]*支店' 'T[0-9]{13}'; do
    if echo "$code_files" | xargs grep -lIE "$pat" 2>/dev/null | grep -q .; then
      echo "NG: コードに口座らしき記述があります（$pat）" >&2
      echo "$code_files" | xargs grep -nIE "$pat" 2>/dev/null | head -3 >&2
      ng=1
    fi
  done
fi

# --- 検査3: 実際の認証情報が混ざっていないか ---
#
# 照合する値は .env から読む。ここに値を書き写すと、この検査ファイル自体が
# 漏洩元になる（docs/02-failures.md の3番目と同じ失敗）。
if [[ -f /home/kojima/work/aixec/.env ]]; then
  # FTP_USER は対象にしない。この環境では公開ドメイン名と同じ文字列で、
  # README のURLに出るのが正常。秘密なのはパスワードと鍵の方。
  while IFS='=' read -r key val; do
    [[ "$key" =~ ^(FTP_PASS|.*SECRET|.*TOKEN|.*PASSWORD|.*_KEY)$ ]] || continue
    val="${val%\"}"; val="${val#\"}"
    [[ ${#val} -ge 8 ]] || continue
    if grep -rlIF -- "$val" "$OUT" >/dev/null 2>&1; then
      echo "NG: 認証情報（$key の値）が混ざっています" >&2; ng=1
    fi
  done < /home/kojima/work/aixec/.env
fi
[[ $ng -eq 0 ]] || { rm -rf "$OUT"; echo "中止しました" >&2; exit 1; }

( cd outputs && zip -qr "${NAME}.zip" "$NAME" )
rm -rf "$OUT"

echo "できました: outputs/${NAME}.zip"
unzip -l "outputs/${NAME}.zip" | tail -n +4 | head -40
