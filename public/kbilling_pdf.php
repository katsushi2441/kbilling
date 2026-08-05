<?php
/**
 * 請求書PDFの生成。外部ライブラリを使わない。
 *
 * heteml（共有サーバー）にはPDF拡張もComposerも無い。TCPDF等を持ち込むと
 * 数十MBのvendorを抱えることになるため、必要な機能だけを直接書いている。
 *
 * 日本語は Adobe-Japan1 の CID フォント（KozMinPro-Regular-Acro）を
 * UniJIS-UCS2-H 符号化で参照する。フォントファイルは埋め込まず、閲覧側の
 * 代替フォントで表示される。これが共有サーバーで日本語PDFを出す一番軽い方法。
 *
 * 発行元・振込先は kbilling_config.php から取る。ここに直接書かないこと
 * （書いたまま配ると、買った人の請求書に別人の口座が載る）。
 *
 * PHP 5.x でも動く構文だけを使う。
 */
require_once __DIR__ . '/kbilling_lib.php';

/** UTF-8 を UTF-16BE のhex文字列にする（UniJIS-UCS2-H が要求する形）。 */
function kbil_pdf_hex($text) {
    $utf16 = mb_convert_encoding((string)$text, 'UTF-16BE', 'UTF-8');
    return strtoupper(bin2hex($utf16));
}

/** Helvetica の字幅（AFM標準・1000分率）。ASCIIの送り幅を出すのに使う。 */
function kbil_pdf_helvetica_widths() {
    static $w = null;
    if ($w !== null) { return $w; }
    $widths = array(
        278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
        556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
        1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
        667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
        333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
        556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,
    );
    $w = array();
    foreach ($widths as $i => $width) { $w[32 + $i] = $width; }
    return $w;
}

/** 文字列を ASCII の並びと非ASCII（日本語）の並びに切り分ける。 */
function kbil_pdf_runs($text) {
    $runs = array();
    $len = mb_strlen($text, 'UTF-8');
    $buf = ''; $ascii = null;
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($text, $i, 1, 'UTF-8');
        $is_ascii = (strlen($ch) === 1 && ord($ch) >= 32 && ord($ch) <= 126);
        if ($ascii === null) { $ascii = $is_ascii; }
        if ($is_ascii !== $ascii) {
            $runs[] = array($ascii, $buf);
            $buf = ''; $ascii = $is_ascii;
        }
        $buf .= $ch;
    }
    if ($buf !== '') { $runs[] = array($ascii, $buf); }
    return $runs;
}

/** 描画したときの幅（pt）。右寄せに使う。 */
function kbil_pdf_width($size, $text) {
    $widths = kbil_pdf_helvetica_widths();
    $w = 0;
    foreach (kbil_pdf_runs((string)$text) as $run) {
        list($is_ascii, $chunk) = $run;
        if ($is_ascii) {
            for ($i = 0; $i < strlen($chunk); $i++) {
                $code = ord($chunk[$i]);
                $w += (isset($widths[$code]) ? $widths[$code] : 556) * $size / 1000;
            }
        } else {
            $w += mb_strlen($chunk, 'UTF-8') * $size;   // 全角は1em
        }
    }
    return $w;
}

/**
 * テキスト描画。$size はpt、$x/$y はページ左下原点。
 *
 * ASCIIは Helvetica(F2)、日本語は CIDフォント(F1) で描く。CIDフォントは
 * ASCIIのグリフを持たないため、全部F1で描くと英数字が豆腐になる（実測）。
 * 送り幅を自前で積んで、runをつなげて配置する。
 */
function kbil_pdf_text($x, $y, $size, $text) {
    $out = "BT\n";
    $cursor = $x;
    $widths = kbil_pdf_helvetica_widths();
    foreach (kbil_pdf_runs((string)$text) as $run) {
        list($is_ascii, $chunk) = $run;
        if ($chunk === '') { continue; }
        if ($is_ascii) {
            $out .= sprintf("/F2 %s Tf 1 0 0 1 %s %s Tm (%s) Tj\n",
                $size, round($cursor, 2), $y, kbil_pdf_escape($chunk));
            $advance = 0;
            for ($i = 0; $i < strlen($chunk); $i++) {
                $code = ord($chunk[$i]);
                $advance += isset($widths[$code]) ? $widths[$code] : 556;
            }
            $cursor += $advance * $size / 1000;
        } else {
            $out .= sprintf("/F1 %s Tf 1 0 0 1 %s %s Tm <%s> Tj\n",
                $size, round($cursor, 2), $y, kbil_pdf_hex($chunk));
            $cursor += mb_strlen($chunk, 'UTF-8') * $size;
        }
    }
    return $out . "ET\n";
}

/** 右端を $right に合わせて描く。金額の桁を揃えるために使う。 */
function kbil_pdf_text_right($right, $y, $size, $text) {
    return kbil_pdf_text($right - kbil_pdf_width($size, $text), $y, $size, $text);
}

/** Helvetica用。PDF文字列リテラルのエスケープ。 */
function kbil_pdf_escape($text) {
    return str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $text);
}

function kbil_pdf_line($x1, $y1, $x2, $y2, $width = 0.5) {
    return sprintf("%s w %s %s m %s %s l S\n", $width, $x1, $y1, $x2, $y2);
}

function kbil_pdf_rect_fill($x, $y, $w, $h, $gray = 0.94) {
    return sprintf("%s g %s %s %s %s re f 0 g\n", $gray, $x, $y, $w, $h);
}

/** 長い文字列を指定幅で切って末尾に … を付ける。明細が枠を突き抜けないように。 */
function kbil_pdf_ellipsis($size, $text, $max_width) {
    if (kbil_pdf_width($size, $text) <= $max_width) { return $text; }
    $len = mb_strlen($text, 'UTF-8');
    for ($i = $len - 1; $i > 0; $i--) {
        $cut = mb_substr($text, 0, $i, 'UTF-8') . '…';
        if (kbil_pdf_width($size, $cut) <= $max_width) { return $cut; }
    }
    return $text;
}

/**
 * 請求書PDFのバイト列を返す。
 *
 * @param array $r 請求書（no, customer, contact, items, tax_rate, net, tax,
 *                 total, issued_on, due_on, note）
 */
function kbil_invoice_pdf($r) {
    $issuer = kbil_issuer();

    $no      = isset($r['no']) ? $r['no'] : '';
    $to      = isset($r['customer']) ? $r['customer'] : '';
    $contact = isset($r['contact']) ? $r['contact'] : '';
    $items   = isset($r['items']) && is_array($r['items']) ? $r['items'] : array();
    $rate    = (int)(isset($r['tax_rate']) ? $r['tax_rate'] : 10);
    $net     = (int)(isset($r['net']) ? $r['net'] : 0);
    $tax     = (int)(isset($r['tax']) ? $r['tax'] : 0);
    $total   = (int)(isset($r['total']) ? $r['total'] : 0);
    $note    = isset($r['note']) ? (string)$r['note'] : '';
    $issued  = !empty($r['issued_on']) ? date('Y年n月j日', strtotime($r['issued_on'])) : date('Y年n月j日');
    $due     = !empty($r['due_on'])    ? date('Y年n月j日', strtotime($r['due_on']))    : '';

    // A4 = 595.28 x 841.89pt。左右マージン 57pt。
    $L = 57; $R = 538; $c = '';

    /* ---- 見出し ---- */
    $c .= kbil_pdf_text($L, 780, 22, '請求書');
    $c .= kbil_pdf_line($L, 772, $L + 62, 772, 1.2);
    $c .= kbil_pdf_text(400, 782, 9, '請求書番号: ' . $no);
    $c .= kbil_pdf_text(400, 768, 9, '発行日: ' . $issued);

    /* ---- 宛先 ---- */
    $c .= kbil_pdf_text($L, 724, 14, $to . ' 御中');
    $c .= kbil_pdf_line($L, 716, 330, 716, 0.8);
    if ($contact !== '') {
        $c .= kbil_pdf_text($L, 700, 9, 'ご担当: ' . $contact . ' 様');
    }

    /* ---- 発行元。登録番号・住所・電話は設定が空なら出さない ---- */
    $y = 724;
    $c .= kbil_pdf_text(360, $y, 11, $issuer['name']);
    $y -= 14;
    if ($issuer['reg_no'] !== '') {
        $c .= kbil_pdf_text(360, $y, 8, '登録番号 ' . $issuer['reg_no']);
        $y -= 12;
    }
    if ($issuer['zip'] !== '' || $issuer['addr'] !== '') {
        $c .= kbil_pdf_text(360, $y, 7.5, trim($issuer['zip'] . ' ' . $issuer['addr']));
        $y -= 11;
    }
    if ($issuer['tel'] !== '')  { $c .= kbil_pdf_text(360, $y, 7.5, $issuer['tel']);  $y -= 11; }
    if ($issuer['mail'] !== '') { $c .= kbil_pdf_text(360, $y, 7.5, $issuer['mail']); $y -= 11; }

    /* ---- ご請求金額 ---- */
    $c .= kbil_pdf_rect_fill($L, 626, 300, 34);
    $c .= kbil_pdf_text($L + 10, 638, 12, 'ご請求金額（税込）');
    $c .= kbil_pdf_text($L + 175, 636, 16, '￥' . number_format($total) . '-');
    if ($due !== '') {
        $c .= kbil_pdf_text($L, 606, 9.5, 'お支払期限: ' . $due);
    }

    /* ---- 明細 ---- */
    $col_qty   = $L + 320;   // 数量（右寄せ位置）
    $col_unit  = $L + 400;   // 単価（右寄せ位置）
    $col_amt   = $R - 4;     // 金額（右寄せ位置）
    $name_max  = 300;        // 品目に使える幅

    $ty = 578;
    $c .= kbil_pdf_rect_fill($L, $ty, $R - $L, 20, 0.90);
    $c .= kbil_pdf_text($L + 8, $ty + 6, 9, '品目');
    $c .= kbil_pdf_text_right($col_qty,  $ty + 6, 9, '数量');
    $c .= kbil_pdf_text_right($col_unit, $ty + 6, 9, '単価');
    $c .= kbil_pdf_text_right($col_amt,  $ty + 6, 9, '金額');

    $row_h = 20;
    $i = 0;
    foreach ($items as $it) {
        $top = $ty - ($i + 1) * $row_h;
        $name = kbil_pdf_ellipsis(9.5, (string)$it['name'], $name_max);
        $amt  = (int)$it['qty'] * (int)$it['unit'];
        $c .= kbil_pdf_text($L + 8, $top + 6, 9.5, $name);
        $c .= kbil_pdf_text_right($col_qty,  $top + 6, 9.5, number_format((int)$it['qty']));
        $c .= kbil_pdf_text_right($col_unit, $top + 6, 9.5, number_format((int)$it['unit']));
        $c .= kbil_pdf_text_right($col_amt,  $top + 6, 9.5, number_format($amt));
        $c .= kbil_pdf_line($L, $top, $R, $top, 0.4);
        $i++;
    }
    $table_bottom = $ty - $i * $row_h;

    /* ---- 小計・消費税・合計 ---- */
    $sy = $table_bottom - 24;
    $rows = array(
        array('小計', number_format($net)),
        array($rate > 0 ? ('消費税（' . $rate . '%）') : '消費税', number_format($tax)),
        array('合計（税込）', number_format($total)),
    );
    foreach ($rows as $n => $row) {
        $ry = $sy - ($n * 19);
        $c .= kbil_pdf_text_right($L + 400, $ry, 9.5, $row[0]);
        $c .= kbil_pdf_text_right($col_amt, $ry, 9.5, '￥' . $row[1]);
        if ($n === 1) { $c .= kbil_pdf_line($L + 300, $ry - 6, $R, $ry - 6, 0.5); }
    }

    /* ---- お支払いについて ---- */
    $py = $sy - 76;
    $c .= kbil_pdf_line($L, $py + 16, $R, $py + 16, 0.5);
    $c .= kbil_pdf_text($L, $py, 10, 'お支払いについて');
    $py -= 18;
    if ($due !== '') {
        $c .= kbil_pdf_text($L, $py, 9, 'お支払期限: ' . $due);
        $py -= 16;
    }
    if ($issuer['bank'] !== '') {
        $c .= kbil_pdf_text($L, $py, 9, '【お振込先】' . $issuer['bank']);
        $py -= 15;
        if ($issuer['holder'] !== '') {
            $c .= kbil_pdf_text($L, $py, 9, '　　　　　　口座名義: ' . $issuer['holder']);
            $py -= 15;
        }
        $c .= kbil_pdf_text($L, $py, 8, '・振込手数料はお客様のご負担でお願いいたします。');
        $py -= 14;
    }
    if (kbil_paypal_enabled()) {
        $c .= kbil_pdf_text($L, $py, 8, '・ご案内のURLからPayPal（クレジットカード可）でもお支払いいただけます。');
        $py -= 14;
    }

    /* ---- 備考 ---- */
    if ($note !== '') {
        $py -= 8;
        $c .= kbil_pdf_text($L, $py, 9, '備考');
        $py -= 15;
        // 1行に収まる長さで折り返す（長文は想定しない）
        foreach (explode("\n", $note) as $line) {
            if ($py < 120) { break; }
            $c .= kbil_pdf_text($L, $py, 8.5, kbil_pdf_ellipsis(8.5, $line, $R - $L));
            $py -= 13;
        }
    }

    /* ---- 脚注 ---- */
    $c .= kbil_pdf_line($L, 70, $R, 70, 0.5);
    $foot = $issuer['name'];
    if ($issuer['reg_no'] !== '') { $foot .= '　登録番号 ' . $issuer['reg_no']; }
    if ($issuer['zip'] !== '' || $issuer['addr'] !== '') {
        $foot .= '　' . trim($issuer['zip'] . ' ' . $issuer['addr']);
    }
    $c .= kbil_pdf_text($L, 56, 8, $foot);

    return kbil_pdf_document($c);
}

/** オブジェクトを組み立ててPDFのバイト列にする。 */
function kbil_pdf_document($content) {
    $objects = array();
    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] "
                . "/Resources << /Font << /F1 5 0 R /F2 8 0 R >> >> /Contents 4 0 R >>";
    $objects[4] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
    // Adobe-Japan1 の CID フォント。フォントファイルは埋め込まない。
    $objects[5] = "<< /Type /Font /Subtype /Type0 /BaseFont /KozMinPro-Regular-Acro "
                . "/Encoding /UniJIS-UCS2-H /DescendantFonts [6 0 R] >>";
    $objects[6] = "<< /Type /Font /Subtype /CIDFontType0 /BaseFont /KozMinPro-Regular-Acro "
                . "/CIDSystemInfo << /Registry (Adobe) /Ordering (Japan1) /Supplement 2 >> "
                . "/FontDescriptor 7 0 R /DW 1000 /W [1 [250] 231 632 500] >>";
    $objects[7] = "<< /Type /FontDescriptor /FontName /KozMinPro-Regular-Acro /Flags 6 "
                . "/FontBBox [-437 -340 1147 1317] /ItalicAngle 0 /Ascent 1317 /Descent -349 "
                . "/CapHeight 742 /StemV 80 >>";
    // 英数字用。PDFの標準14フォントなので埋め込み不要。
    $objects[8] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = array();
    foreach ($objects as $num => $body) {
        $offsets[$num] = strlen($pdf);
        $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $count = count($objects) + 1;
    $pdf .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
    for ($i = 1; $i < $count; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . $count . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
}
