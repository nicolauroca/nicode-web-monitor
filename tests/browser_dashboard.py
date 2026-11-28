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
    states = {'failed':'Comprobación fallida','partial':'Inventario parcial','recovered':'Inventario reciente','stale':'Datos antiguos','never':'Sin comprobar'}
    for width in [1440,390,320]:
        page.set_viewport_size({'width':width,'height':1000});page.goto(central);page.wait_for_load_state('networkidle')
        check(page.get_by_role('heading',name='Nicode Web Monitor — Central',exact=True).count()==1,f'{width}: Central brand')
        check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),f'{width}: directory fits viewport')
        check(page.locator('.nwm-inventory,.nwm-site-tabs,#nwm-add-site,#nwm-monitoring,#nwm-maintenance').count()==0,f'{width}: directory does not embed detail or other workflows')
        table=page.locator('.nwm-site-table')
        for state,label in states.items():
            name='NWM browser fixture '+state
            row=page.locator('[data-nwm-site]').filter(has=page.get_by_role('link',name=name,exact=True))
            check(row.count()==1 and label in row.inner_text(),f'{width}: {state} compact status')
            if width==1440:
                check(abs(row.bounding_box()['width']-table.bounding_box()['width'])<=2 and row.bounding_box()['height']<=105,'desktop: '+state+' occupies one compact full-width row')
        recovered=page.locator('[data-nwm-site]').filter(has=page.get_by_role('link',name='NWM browser fixture recovered',exact=True))
        failed=page.locator('[data-nwm-site]').filter(has=page.get_by_role('link',name='NWM browser fixture failed',exact=True))
        partial=page.locator('[data-nwm-site]').filter(has=page.get_by_role('link',name='NWM browser fixture partial',exact=True))
        check(recovered.locator('.nwm-version--success').count()==2 and failed.locator('.nwm-version--danger').count()==1,f'{width}: supported versions and EOL have explicit visual states')
        check('2 extensiones' in partial.inner_text(),f'{width}: 56 cached rows become two distinct pending extensions')
        check('125 ms' in recovered.inner_text(),f'{width}: query duration is labeled as inventory')
        if javascript:
            page.locator('[data-nwm-filter=review]').click()
            check(page.locator('[data-nwm-site]:visible').count()==3,f'{width}: review filter')
            page.locator('#nwm-site-search').fill('PARTIAL')
            check(page.locator('[data-nwm-site]:visible').count()==1,f'{width}: combined case-insensitive search')
            page.locator('#nwm-site-search').fill('no-such-site')
            check(page.locator('[data-nwm-no-results]').is_visible(),f'{width}: empty search')
            page.locator('[data-nwm-clear-filters]').click()
            check(page.locator('[data-nwm-site]:visible').count()==6,f'{width}: clear filters')
            page.locator('[data-nwm-health-filter=critical]').click()
            check(failed.is_visible() and not recovered.is_visible(),f'{width}: priority filter focuses the affected site')
            page.locator('[data-nwm-clear-filters]').click()
        page.evaluate('scrollTo(0,0)');page.screenshot(path=str(output/f'central-directory-{width}.png'),full_page=True)
        page.get_by_role('link',name='Entrar en NWM browser fixture recovered',exact=True).click();page.wait_for_load_state('networkidle')
        check(page.locator('[data-nwm-site],#nwm-add-site,#nwm-monitoring').count()==0,f'{width}: entering a site replaces the directory')
        check(page.locator('.nwm-brand-symbol').evaluate("e=>e.getBoundingClientRect().width>=24&&e.parentElement.getBoundingClientRect().width>=e.getBoundingClientRect().width*1.5"),f'{width}: compact site header preserves original symbol clear space')
        check(page.get_by_role('heading',name='NWM browser fixture recovered',exact=True).is_visible(),f'{width}: selected site identified')
        check(page.get_by_text('NWM browser fixture failed',exact=True).count()==0,f'{width}: other site identities absent')
        page.screenshot(path=str(output/f'site-overview-{width}.png'),full_page=True)
        page.locator('.nwm-site-tabs').get_by_role('link',name='Diagnóstico',exact=True).click();page.wait_for_load_state('networkidle')
        check(page.locator('[data-health-category]').count()==7 and page.get_by_text('Respuesta de la consulta',exact=True).is_visible(),f'{width}: diagnosis explains each area and separates timing')
        check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),f'{width}: diagnosis fits viewport')
        page.screenshot(path=str(output/f'site-health-{width}.png'),full_page=True)
        page.locator('.nwm-site-tabs').get_by_role('link',name='Mantenimiento',exact=True).click();page.wait_for_load_state('networkidle')
        hub=page.url
        check(page.locator('[data-nwm-maint-tool]').count()==3 and page.locator('input[value=backupBegin],input[value=updateBegin],input[value=coreUpdateBegin]').count()==0,f'{width}: maintenance starts with a chooser instead of every form')
        page.screenshot(path=str(output/f'maintenance-hub-{width}.png'),full_page=True)
        for tool,region,task in [('backups','Copias de seguridad','backupAdvance'),('extensions','Actualizaciones de extensiones','updateExecute'),('joomla','Actualizaciones de Joomla','coreUpdateExecute')]:
            page.locator('[data-nwm-maint-tool='+tool+']').click();page.wait_for_load_state('networkidle')
            check(page.locator('#nwm-maintenance section[aria-label]').count()==1 and page.get_by_role('region',name=region,exact=True).count()==1,f'{width}: {tool} is an isolated tool page')
            check(page.locator('.nwm-inline-help[open]').count()==0,f'{width}: {tool} extended help starts closed')
            help=page.locator('.nwm-inline-help > summary').first;help.focus();help.press('Enter')
            check(page.locator('.nwm-inline-help[open]').count()==1,f'{width}: {tool} information help works by keyboard')
            help.press('Enter')
            form=page.locator('form').filter(has=page.locator('input[value='+task+']'))
            operation_card=form.locator('xpath=ancestor::article')
            operation_details=operation_card.locator('.nwm-maint-operation')
            if operation_details.count() and operation_details.get_attribute('open') is None:operation_details.locator(':scope > summary').click()
            check(form.count()==1 and form.locator('input[name=confirmed]').get_attribute('required') is not None and not form.locator('input[name=confirmed]').is_checked(),f'{width}: {tool} execution requires a new explicit confirmation')
            check(form.is_visible() and operation_card.locator('.nwm-action-warning').is_visible(),f'{width}: {tool} critical warning is visible with its action while optional help is closed')
            check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),f'{width}: {tool} fits viewport')
            if tool=='joomla':
                uncertain=page.locator('.nwm-update').filter(has_text='-uncertain')
                check(uncertain.locator('input[value=coreUpdateExecute]').count()==0 and uncertain.locator('.nwm-action-warning').is_visible(),f'{width}: uncertain operation offers status without a blind retry')
            page.screenshot(path=str(output/f'maintenance-{tool}-{width}.png'),full_page=True)
            page.locator('.nwm-breadcrumb').get_by_role('link',name='Mantenimiento',exact=True).click();page.wait_for_load_state('networkidle')
        page.goto(hub+'&tool=not-a-tool');page.wait_for_load_state('networkidle')
        check(page.get_by_role('heading',name='Herramienta no disponible').is_visible() and page.locator('input[value=backupBegin],input[value=updateBegin],input[value=coreUpdateBegin]').count()==0,f'{width}: invalid maintenance tool has no action forms')
        page.locator('.nwm-site-tabs').get_by_role('link',name='Historial',exact=True).click();page.wait_for_load_state('networkidle')
        check(page.get_by_text('Sin alertas activas de inventario.',exact=True).is_visible(),f'{width}: own history and alert only')
        summary=page.get_by_text('Cambios de estado',exact=True);summary.focus();summary.press('Enter')
        page.get_by_text('Recuperación',exact=True).wait_for(state='visible')
        check(page.get_by_text('Recuperación',exact=True).is_visible(),f'{width}: recovery history by keyboard')
        check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),f'{width}: selected history fits viewport')
        page.screenshot(path=str(output/f'site-history-{width}.png'),full_page=True)
        page.locator('.nwm-breadcrumb').get_by_role('link',name='Sitios',exact=True).click();page.wait_for_load_state('networkidle')
        check(page.locator('[data-nwm-site]').count()==6,f'{width}: breadcrumb returns to all sites')
    if javascript:
        page.set_viewport_size({'width':1440,'height':1000});page.evaluate("document.documentElement.dataset.bsTheme='dark'")
        check(page.locator('.nwm-site-table').evaluate("e=>getComputedStyle(e).backgroundColor==='rgb(36, 24, 47)'"),'directory follows native dark theme')
        page.screenshot(path=str(output/'central-dark.png'),full_page=True);page.evaluate("delete document.documentElement.dataset.bsTheme")
    page.goto(central);page.wait_for_load_state('networkidle')
    page.get_by_role('link',name='Entrar en Self monitored central',exact=True).click();page.wait_for_load_state('networkidle')
    page.locator('.nwm-site-tabs').get_by_role('link',name='Mantenimiento',exact=True).click();page.wait_for_load_state('networkidle')
    check(page.locator('.nwm-local-maintenance').is_visible(),'self monitoring clearly identifies local maintenance scope')
    page.locator('[data-nwm-maint-tool=backups]').click();page.wait_for_load_state('networkidle')
    check(page.locator('input[value=backupBegin],input[value=backupAdvance],input[value=backupEnroll]').count()==0,'local inventory does not offer unprepared remote write forms')
    check(page.get_by_role('link',name='Abrir el actualizador de este Joomla',exact=False).count()==1,'authorized self-monitoring site links to its native updater')
    page.goto(central);page.wait_for_load_state('networkidle')
    page.get_by_role('link',name='Añadir sitio',exact=True).click();page.wait_for_load_state('networkidle')
    check(page.locator('#new-site-name').is_visible() and page.locator('[data-nwm-site],#nwm-monitoring').count()==0,'add-site has its own page')
    page.locator('.nwm-nav').get_by_role('link',name='Comprobaciones',exact=True).click();page.wait_for_load_state('networkidle')
    check(page.locator('#nwm-monitoring').is_visible() and page.locator('[data-nwm-site],#nwm-add-site').count()==0,'scheduler has its own page')
    page.goto(central+'&site=2147483647&section=inventory');page.wait_for_load_state('networkidle')
    check(page.get_by_role('heading',name='Sitio o sección no disponible').is_visible() and page.locator('[data-nwm-site],.nwm-inventory-category').count()==0,'deleted site does not fall back to another site or full directory')
    page.goto(central+'&health=critical');page.wait_for_load_state('networkidle')
    check(page.locator('[data-nwm-site]:visible').count()==1,'priority filtering also runs on the server without JavaScript')


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
