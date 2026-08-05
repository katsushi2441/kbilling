<?php
/**
 * 発行者（管理者）の認証。2つのモードを持つ。
 *
 *   password（既定） — 設定に入れたパスワードハッシュで入る。
 *                      どのサーバーでも動く。導入したら普通はこちら。
 *   x               — X(Twitter)ログイン。auth_common.php が同じ場所に
 *                      必要で、OAuth を受ける側の準備も要る。自社運用向け。
 *
 * 【この製品でログインするのは発行者だけ】
 * 請求先（お客様）にログインを求めない。母体にした vibe-prototype.php は
 * 発注者にXログインを求めていたが、請求書を受け取る取引先にSNSアカウントを
 * 要求する運用は成り立たない。お客様は、メールで届いたURLだけで開く。
 *
 * 設定を置かない状態では誰も入れない（KBIL_ADMIN_PASSWORD_HASH も
 * KBIL_ADMIN も空なら、常に false を返す）。
 */
require_once __DIR__ . '/kbilling_lib.php';

function kbil_auth_mode() {
    $m = defined('KBIL_AUTH') ? strtolower(KBIL_AUTH) : 'password';
    return $m === 'x' ? 'x' : 'password';
}

/** 設置先のURL。設定が無ければリクエストから組み立てる（サブディレクトリ可）。 */
function kbil_base_url() {
    if (defined('KBIL_BASE_URL') && KBIL_BASE_URL !== '') { return rtrim(KBIL_BASE_URL, '/'); }
    $scheme = 'http';
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) {
        $scheme = 'https';
    }
    $host = isset($_SERVER['HTTP_HOST'])
        ? preg_replace('/[^A-Za-z0-9.:\-]/', '', $_SERVER['HTTP_HOST']) : 'localhost';
    $dir = isset($_SERVER['SCRIPT_NAME'])
        ? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') : '';
    if ($dir === '/' || $dir === '.') { $dir = ''; }
    return $scheme . '://' . $host . $dir;
}

/** お客様に送る支払いページのURL。 */
function kbil_pay_url($r) {
    return kbil_base_url() . '/kbilling_pay.php?t=' . rawurlencode($r['token']);
}

/* ---------------- パスワードモード ---------------- */

define('KBIL_LOGIN_FAILS', KBIL_DATA_DIR . '/login_fails.json');
define('KBIL_LOGIN_MAX_FAIL', 8);
define('KBIL_LOGIN_LOCK_SEC', 900);  // 15分

function kbil_client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

/** 総当たり対策。IPごとに失敗を数え、一定回数で一定時間止める。 */
function kbil_login_locked() {
    if (!file_exists(KBIL_LOGIN_FAILS)) { return false; }
    $d = json_decode((string)@file_get_contents(KBIL_LOGIN_FAILS), true);
    $ip = kbil_client_ip();
    if (!is_array($d) || !isset($d[$ip])) { return false; }
    $e = $d[$ip];
    return (int)$e['n'] >= KBIL_LOGIN_MAX_FAIL && (time() - (int)$e['at']) < KBIL_LOGIN_LOCK_SEC;
}

function kbil_login_record_fail() {
    if (!is_dir(KBIL_DATA_DIR)) { @mkdir(KBIL_DATA_DIR, 0705, true); }
    $fp = @fopen(KBIL_LOGIN_FAILS, 'c+');
    if (!$fp) { return; }
    flock($fp, LOCK_EX);
    rewind($fp);
    $d = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($d)) { $d = array(); }
    $ip = kbil_client_ip();
    $now = time();
    // 古い記録は捨てる（ファイルが際限なく育たないように）
    foreach ($d as $k => $v) {
        if ($now - (int)$v['at'] > KBIL_LOGIN_LOCK_SEC * 4) { unset($d[$k]); }
    }
    $n = (isset($d[$ip]) && $now - (int)$d[$ip]['at'] < KBIL_LOGIN_LOCK_SEC) ? (int)$d[$ip]['n'] + 1 : 1;
    $d[$ip] = array('n' => $n, 'at' => $now);
    rewind($fp); ftruncate($fp, 0);
    fwrite($fp, json_encode($d));
    fflush($fp);
    @chmod(KBIL_LOGIN_FAILS, 0600);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function kbil_login_clear_fail() {
    if (!file_exists(KBIL_LOGIN_FAILS)) { return; }
    $d = json_decode((string)@file_get_contents(KBIL_LOGIN_FAILS), true);
    if (!is_array($d)) { return; }
    unset($d[kbil_client_ip()]);
    @file_put_contents(KBIL_LOGIN_FAILS, json_encode($d), LOCK_EX);
}

function kbil_password_hash_set() {
    return defined('KBIL_ADMIN_PASSWORD_HASH') && KBIL_ADMIN_PASSWORD_HASH !== '';
}

/** パスワードを照合してログインする。 */
function kbil_password_login($password) {
    if (!kbil_password_hash_set()) { return false; }
    if (kbil_login_locked()) { return false; }
    if (!password_verify((string)$password, KBIL_ADMIN_PASSWORD_HASH)) {
        kbil_login_record_fail();
        usleep(400000);   // 総当たりの速度を落とす
        return false;
    }
    kbil_login_clear_fail();
    session_regenerate_id(true);   // ログイン後にIDを変える（固定化対策）
    $_SESSION['kbil_admin'] = true;
    return true;
}

/* ---------------- 共通 ---------------- */

/** セッションを開始する。モードによって担当が違う。 */
function kbil_auth_start() {
    if (kbil_auth_mode() === 'x') {
        // auth_common.php 側がセッションを持つ
        if (!function_exists('url2ai_auth_bootstrap')) {
            if (file_exists(__DIR__ . '/config.php'))      { require_once __DIR__ . '/config.php'; }
            if (file_exists(__DIR__ . '/auth_common.php')) { require_once __DIR__ . '/auth_common.php'; }
        }
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('KBILSESSID');
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        @session_set_cookie_params(0, '/', '', $secure, true);
        @session_start();
    }
}

/** いま管理者としてログインしているか。 */
function kbil_is_admin() {
    if (kbil_auth_mode() === 'x') {
        if (!function_exists('url2ai_auth_bootstrap')) { return false; }
        if (KBIL_ADMIN === '') { return false; }
        $auth = url2ai_auth_bootstrap();
        if (empty($auth['logged_in'])) { return false; }
        $user = strtolower(ltrim(trim((string)$auth['session_user']), '@'));
        return $user !== '' && hash_equals(strtolower(KBIL_ADMIN), $user);
    }
    return !empty($_SESSION['kbil_admin']) && kbil_password_hash_set();
}

function kbil_auth_logout() {
    if (kbil_auth_mode() === 'x') { return; }  // 画面側がリダイレクトを組む
    unset($_SESSION['kbil_admin']);
    @session_destroy();
}

/** CSRFトークン。フォームごとに埋めて照合する。 */
function kbil_csrf() {
    if (empty($_SESSION['kbil_csrf'])) { $_SESSION['kbil_csrf'] = kbil_random_hex(24); }
    return (string)$_SESSION['kbil_csrf'];
}

function kbil_csrf_ok($sent) {
    return !empty($_SESSION['kbil_csrf']) && is_string($sent) && $sent !== ''
        && hash_equals((string)$_SESSION['kbil_csrf'], $sent);
}

/** 導入が終わっていない項目を返す。管理画面で案内するために使う。 */
function kbil_setup_missing() {
    $miss = kbil_config_missing();
    if (kbil_auth_mode() === 'x') {
        if (KBIL_ADMIN === '') { $miss[] = 'KBIL_ADMIN（Xアカウント）'; }
        if (!file_exists(__DIR__ . '/auth_common.php')) { $miss[] = 'auth_common.php（Xログイン用。同じ場所に必要）'; }
    } elseif (!kbil_password_hash_set()) {
        $miss[] = 'KBIL_ADMIN_PASSWORD_HASH（管理パスワード。scripts/make_password_hash.php で作成）';
    }
    return array_values(array_unique($miss));
}
