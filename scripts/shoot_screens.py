#!/usr/bin/env python3
"""ローカルで動かした kbilling の画面を撮る。

商品画像とドキュメントに使う。実画面をそのまま撮ることで、
「動いていない画面を載せる」事故を防ぐ。

前提: public/ を http://127.0.0.1:18308 で配信していること
  php -S 127.0.0.1:18308 -t public

実行: python3 scripts/shoot_screens.py <支払いページのトークン>
"""
import sys
from pathlib import Path

from playwright.sync_api import sync_playwright

BASE = "http://127.0.0.1:18308"
OUT = Path(__file__).resolve().parent.parent / "outputs"
PASSWORD = "demo2026"


def main(token: str) -> int:
    OUT.mkdir(exist_ok=True)
    with sync_playwright() as p:
        browser = p.chromium.launch()

        # --- 管理画面（発行者） ---
        page = browser.new_page(viewport={"width": 1280, "height": 900})
        page.goto(f"{BASE}/kbilling.php", wait_until="networkidle")
        page.screenshot(path=OUT / "screen-login.png")

        page.fill("#password", PASSWORD)
        page.click("button[name=login]")
        page.wait_for_load_state("networkidle")
        page.screenshot(path=OUT / "screen-admin.png", full_page=True)

        # --- お客様の支払いページ ---
        pay = browser.new_page(viewport={"width": 1280, "height": 900})
        pay.goto(f"{BASE}/kbilling_pay.php?t={token}", wait_until="networkidle")
        pay.screenshot(path=OUT / "screen-pay.png", full_page=True)

        # --- スマートフォン幅（請求書は外で見られることが多い） ---
        sp = browser.new_page(viewport={"width": 390, "height": 844})
        sp.goto(f"{BASE}/kbilling_pay.php?t={token}", wait_until="networkidle")
        sp.screenshot(path=OUT / "screen-pay-sp.png", full_page=True)

        browser.close()

    for f in sorted(OUT.glob("screen-*.png")):
        print(f"{f.name}: {f.stat().st_size:,} bytes")
    return 0


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(1)
    sys.exit(main(sys.argv[1]))
