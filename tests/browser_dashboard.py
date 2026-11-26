"""Central UI QA using disposable observations; never requests remote inventory.

Imported by onboarding_http.py --central-design for native HTTPS integration.
Standalone mode requires browser_fixture.php seed/clean and credentials on stdin.
"""
import argparse
import json
import sys
from pathlib import Path
from urllib.parse import urlsplit


def exercise_dashboard(page, central, output, check, javascript=True):
    states = {
        'failed': ('Comprobación fallida', 'Hay una observación que requiere revisión.'),
        'partial': ('Inventario parcial', 'Hay una observación que requiere revisión.'),
        'recovered': ('Inventario reciente', 'Sin alertas activas de inventario.'),
        'stale': ('Datos antiguos', 'Hay una observación que requiere revisión.'),
        'never': ('Sin comprobar', 'A la espera de la primera comprobación.'),
    }
    for width in [1440, 390, 320]:
        page.set_viewport_size({'width': width, 'height': 1000})
        page.goto(central); page.wait_for_load_state('networkidle')
        check(page.get_by_role('heading', name='Nicode Web Monitor — Central', exact=True).count() == 1, f'{width}: shared Central brand')
        check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'), f'{width}: no page overflow')
        for state, labels in states.items():
            card = page.locator('[data-nwm-site]').filter(has=page.get_by_role('heading', name='NWM browser fixture ' + state, exact=True))
            check(card.count() == 1 and labels[0] in card.inner_text(), f'{width}: {state} visible state')
            card.locator('.nwm-site-details > summary').focus()
            card.locator('.nwm-site-details > summary').press('Enter')
            card.get_by_text(labels[1],exact=True).wait_for(state='visible')
            check(card.locator('.nwm-site-details').get_attribute('open') is not None and labels[1] in card.inner_text(), f'{width}: {state} keyboard history and alert')
            action = card.get_by_role('button', name='Actualizar inventario de NWM browser fixture ' + state, exact=True)
            action.scroll_into_view_if_needed(); bounds = action.bounding_box()
            check(bounds is not None and bounds['x'] >= 0 and bounds['x'] + bounds['width'] <= width + 1, f'{width}: {state} action remains on screen')
            if state == 'recovered':
                card.get_by_text('Cambios de estado', exact=True).click()
                card.get_by_text('Recuperación',exact=True).wait_for(state='visible')
                check('Recuperación' in card.inner_text(), f'{width}: honest recovery history')
            card.locator('.nwm-site-details > summary').click()
        if javascript:
            page.locator('[data-nwm-filter=review]').click()
            check(page.locator('[data-nwm-site]:visible').count() == 3, f'{width}: review metric filters partial failed and stale observations')
            page.locator('#nwm-site-search').fill('PARTIAL')
            check(page.locator('[data-nwm-site]:visible').count() == 1, f'{width}: case-insensitive combined search')
            page.locator('#nwm-site-search').fill('no-such-site')
            check(page.locator('[data-nwm-no-results]').is_visible(), f'{width}: search empty state')
            page.locator('[data-nwm-clear-filters]').click()
            check(page.locator('[data-nwm-site]:visible').count() == len(states) + 1, f'{width}: clear filters restores all fixture and connected sites')
            page.locator('#nwm-site-state').select_option('never')
            check(page.locator('[data-nwm-site]:visible').count() == 1, f'{width}: never-checked filter')
            page.locator('[data-nwm-clear-filters]').click()
        page.evaluate('scrollTo(0,0)')
        page.screenshot(path=str(output / f'central-sites-{width}.png'), full_page=True)
        if width == 1440:
            page.screenshot(path=str(output/'central-overview.png'))
            page.locator('.nwm-central').screenshot(path=str(output / 'central-preview.png'))
        if width == 390:
            page.locator('[data-nwm-site]').first.screenshot(path=str(output / 'central-site-mobile.png'))
    page.set_viewport_size({'width': 1440, 'height': 1000})
    if javascript:
        page.evaluate("document.documentElement.dataset.bsTheme='dark'")
        check(page.locator('.nwm-monitoring').evaluate("e=>getComputedStyle(e).backgroundColor === 'rgb(27, 43, 55)'"), 'Central follows the native dark theme')
        page.locator('.nwm-central').screenshot(path=str(output / 'central-dark.png'))
        page.evaluate("delete document.documentElement.dataset.bsTheme")
        page.get_by_role('link', name='Añadir sitio', exact=True).click()
        page.wait_for_function("document.activeElement===document.getElementById('new-site-name')")
        check(page.locator('#nwm-add-site').get_attribute('open') is not None and page.locator('#new-site-name').evaluate('e=>e===document.activeElement'), 'add-site link opens and focuses the native form')
        page.locator('#nwm-maintenance > summary').click()
        check(page.locator('#nwm-maintenance').get_attribute('open') is not None, 'maintenance remains a separate explicit section')


if __name__ == '__main__':
    from playwright.sync_api import sync_playwright
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base-url', required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--browser')
    parser.add_argument('--allow-disposable-writes', action='store_true', required=True)
    args = parser.parse_args(); parts = urlsplit(args.base_url)
    if parts.scheme not in ['http', 'https'] or parts.hostname not in ['127.0.0.1', 'localhost', '::1'] or parts.username or parts.password or parts.query or parts.fragment:
        raise SystemExit('Only a disposable loopback URL is accepted')
    credentials = json.load(sys.stdin); args.output.mkdir(parents=True, exist_ok=True); checks = []
    def check(ok, label):
        if not ok: raise AssertionError(label)
        checks.append(label)
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(executable_path=args.browser, headless=True)
        page = browser.new_page(ignore_https_errors=True, viewport={'width': 1440, 'height': 1000})
        errors = []; page.on('pageerror', lambda error: errors.append(str(error)))
        page.route('**/*', lambda route: route.continue_() if urlsplit(route.request.url).netloc == parts.netloc else route.abort())
        base = args.base_url.rstrip('/') + '/administrator/'
        page.goto(base)
        page.locator('#mod-login-username').fill(credentials['admin_user'])
        page.locator('#mod-login-password').fill(credentials['admin_password'])
        page.locator('button[type=submit]').click(); page.wait_for_load_state('networkidle')
        exercise_dashboard(page, base + 'index.php?option=com_nicodewebmonitor', args.output, check)
        check(not errors, 'no browser JavaScript errors'); browser.close()
    report = {'passed': len(checks), 'checks': checks, 'scope': 'Disposable observations; no remote requests'}
    (args.output / 'central-design-report.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
    print(json.dumps(report, indent=2))
