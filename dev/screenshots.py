"""Screenshots of the test site (dev/start.sh running, data built), desktop and phone, light
and dark. Needs: pip install playwright && playwright install chromium.
Usage: python3 dev/screenshots.py [catchment] [output folder]"""
import os
import sys
from playwright.sync_api import sync_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
TILE = open(os.path.join(HERE, 'fixtures', 'tile.png'), 'rb').read()  # Stands in for map tiles.
catchment = sys.argv[1] if len(sys.argv) > 1 else 'brisbane'
out = sys.argv[2] if len(sys.argv) > 2 else '/tmp/floodwatcher-shots'
os.makedirs(out, exist_ok=True)
errors = []
with sync_playwright() as p:
    browser = p.chromium.launch()
    for name, w, h, phone, scheme in [('desktop', 1400, 900, False, 'light'), ('desktop-dark', 1400, 900, False, 'dark'),
                                      ('phone', 390, 844, True, 'light')]:
        ctx = browser.new_context(viewport={'width': w, 'height': h}, timezone_id='Australia/Brisbane',
                                  is_mobile=phone, has_touch=phone, device_scale_factor=2 if phone else 1,
                                  color_scheme=scheme)
        page = ctx.new_page()
        page.route('https://tile.openstreetmap.org/**', lambda r, q: r.fulfill(status=200, body=TILE, content_type='image/png'))
        page.on('pageerror', lambda e: errors.append(str(e)))
        page.goto(f'http://127.0.0.1:8102/?c={catchment}')
        page.wait_for_timeout(2500)
        page.screenshot(path=f'{out}/{catchment}-{name}.png')
        page.click('#panel-tab')
        page.wait_for_timeout(500)
        page.screenshot(path=f'{out}/{catchment}-{name}-panel.png')
        ctx.close()
    browser.close()
print('Saved in', out, '| page errors:', errors or 'none')
