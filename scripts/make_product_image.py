#!/usr/bin/env python3
"""kappstore 出品用の商品画像(1200x750 / 16:10)。kinvoice と同じ体裁。

実画面のスクリーンショット（outputs/screen-*.png）を使う。
実行: python3 scripts/make_product_image.py
"""
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

FONT = "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"
FONT_R = "/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc"

W, H = 1200, 750
FOAM, ABYSS, MUTED = "#f5fbfb", "#12202f", "#55697a"
TEAL, TEAL_DEEP = "#12a99f", "#0a726b"

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "outputs" / "kbilling-product.png"

img = Image.new("RGB", (W, H), FOAM)
dr = ImageDraw.Draw(img)
dr.rectangle([(0, 0), (W, 8)], fill=TEAL)

f_eyebrow = ImageFont.truetype(FONT, 22)
f_title = ImageFont.truetype(FONT, 52)
f_lead = ImageFont.truetype(FONT_R, 24)
f_pill = ImageFont.truetype(FONT, 21)

x = 56
dr.text((x, 44), "KURAGE APP STORE", font=f_eyebrow, fill=TEAL_DEEP)
dr.text((x, 84), "請求書を発行して送り、", font=f_title, fill=ABYSS)
dr.text((x, 148), "そのまま支払ってもらう", font=f_title, fill=TEAL_DEEP)
dr.text((x, 228), "明細12行・インボイス対応。PayPalの入金はサーバー側で裏取りします。",
        font=f_lead, fill=MUTED)

px = x
for label in ("インボイス対応", "PayPal/銀行振込", "改変自由"):
    tw = dr.textlength(label, font=f_pill)
    dr.rounded_rectangle([(px, 274), (px + tw + 38, 316)], radius=21, fill=TEAL)
    dr.text((px + 19, 281), label, font=f_pill, fill="#ffffff")
    px += tw + 52


def rounded(im, r=10):
    mask = Image.new("L", im.size, 0)
    ImageDraw.Draw(mask).rounded_rectangle([(0, 0), im.size], radius=r, fill=255)
    out = Image.new("RGB", im.size, FOAM)
    out.paste(im, (0, 0), mask)
    return out


admin = Image.open(ROOT / "outputs" / "screen-admin.png").convert("RGB")
admin = admin.crop((0, 0, admin.width, int(admin.height * 0.62)))
admin.thumbnail((760, 420), Image.LANCZOS)
img.paste(rounded(admin), (x, 356))
dr.rounded_rectangle([(x - 1, 355), (x + admin.width + 1, 355 + admin.height + 1)],
                     radius=10, outline="#cde5e2", width=2)

pay = Image.open(ROOT / "outputs" / "screen-pay.png").convert("RGB")
pay.thumbnail((330, 330), Image.LANCZOS)
px2 = x + admin.width + 26
img.paste(rounded(pay), (px2, 356))
dr.rounded_rectangle([(px2 - 1, 355), (px2 + pay.width + 1, 355 + pay.height + 1)],
                     radius=10, outline="#cde5e2", width=2)

f_cap = ImageFont.truetype(FONT_R, 17)
dr.text((x, 356 + admin.height + 10), "管理画面：明細を入れて発行するだけ", font=f_cap, fill=MUTED)
dr.text((px2, 356 + pay.height + 10), "お客様の支払いページ", font=f_cap, fill=MUTED)

OUT.parent.mkdir(parents=True, exist_ok=True)
img.save(OUT, "PNG", optimize=True)
print(f"wrote {OUT} ({OUT.stat().st_size:,} bytes) {img.size}")
