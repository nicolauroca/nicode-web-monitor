"""Browser QA for a disposable loopback Joomla with browser_fixture.php seed data.

Requires Playwright and Microsoft Edge. Pass admin_user/admin_password JSON on
stdin, never as arguments. Screenshots contain only the disposable fixture data.
Seed and clean with browser_fixture.php; this test never requests remote inventory.
"""
import argparse
import json
import sys
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--base-url', required=True)
parser.add_argument('--output', type=Path, required=True)
parser.add_argument('--allow-disposable-writes', action='store_true', required=True)
args = parser.parse_args()
parts = urlsplit(args.base_url)
if parts.scheme != 'http' or parts.hostname not in ['127.0.0.1', 'localhost', '::1'] or parts.username or parts.password or parts.query or parts.fragment:
    raise SystemExit('Only a disposable loopback HTTP URL is accepted')
credentials = json.load(sys.stdin)
base = args.base_url.rstrip('/') + '/administrator/'
args.output.mkdir(parents=True, exist_ok=True)
checks = []

def check(ok, label):
    if not ok:
        raise AssertionError(label)
    checks.append(label)

with sync_playwright() as playwright:
    browser = playwright.chromium.launch(channel='msedge', headless=True)
    page = browser.new_page(viewport={'width': 1440, 'height': 1000})
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    # Browser assets and actions are local. Block any accidental third-party request.
    page.route('**/*', lambda route: route.continue_() if urlsplit(route.request.url).netloc == parts.netloc else route.abort())
    page.goto(base)
    page.locator('#mod-login-username').fill(credentials['admin_user'])
    page.locator('#mod-login-password').fill(credentials['admin_password'])
    page.locator('button[type=submit]').click()
    page.wait_for_load_state('networkidle')
    # Joomla's first-login tour otherwise redirects every page back to its first step.
    if page.get_by_role('button', name='Hide Forever', exact=True).count():
        page.get_by_role('button', name='Hide Forever', exact=True).click()
        page.wait_for_load_state('networkidle')
    if page.locator('.js-pstats-btn-allow-never').count():
        page.locator('.js-pstats-btn-allow-never').click()
        page.wait_for_load_state('networkidle')
    states = {
        'failed': ('Latest check failed', 'Active inventory alert'),
        'partial': ('Recent partial inventory', 'Active inventory alert'),
        'recovered': ('Recent complete inventory', 'No active inventory alert'),
        'stale': ('Observation older than 24 hours', 'Active inventory alert'),
        'never': ('Never checked', 'Awaiting first check'),
    }
    for width in [1440, 390, 320]:
        page.set_viewport_size({'width': width, 'height': 1000})
        page.goto(base + 'index.php?option=com_nicodewebmonitor')
        page.wait_for_load_state('networkidle')
        check('option=com_nicodewebmonitor' in page.url, f'{width}: component remains open')
        check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'), f'{width}: no page overflow')
        table = page.locator('.nwm-sites')
        check(table.evaluate('(e)=>e.scrollWidth <= e.clientWidth + 1'), f'{width}: no concealed site columns')
        for state, labels in states.items():
            row = table.locator('tbody tr').filter(has=page.get_by_text('NWM browser fixture ' + state, exact=True))
            check(row.count() == 1 and all(label in row.inner_text() for label in labels), f'{width}: {state} state and alert')
            action = row.get_by_role('button', name='Check NWM browser fixture ' + state, exact=True)
            action.scroll_into_view_if_needed()
            bounds = action.bounding_box()
            check(bounds is not None and bounds['x'] >= 0 and bounds['x'] + bounds['width'] <= width + 1, f'{width}: {state} action on screen')
            if state == 'recovered':
                row.get_by_text('Alert transitions (retained history)', exact=True).click()
                page.wait_for_function("()=>[...document.querySelectorAll('.nwm-sites details[open]')].some(e=>e.innerText.includes('recovered'))")
                check('recovered' in row.locator('details[open]').inner_text(), f'{width}: recovery history expands')
        page.evaluate('scrollTo(0, 0)')
        page.screenshot(path=str(args.output / f'dashboard-{width}.png'), full_page=True)
        if width == 390:
            row = table.locator('tbody tr').first
            row.screenshot(path=str(args.output / 'mobile-site.png'))
    check(not errors, 'no browser JavaScript errors')
    browser.close()
result = {'passed': len(checks), 'checks': checks, 'widths': [1440, 390, 320], 'browser': 'Microsoft Edge / Chromium', 'scope': 'Disposable seeded observations; not a live connector integration'}
(args.output / 'browser-tests.json').write_text(json.dumps(result, indent=2), encoding='utf-8')
print(json.dumps(result, indent=2))
