"""Real browser + central Joomla/DB + native backup over verified loopback TLS.
Package: Nicode.joomla.webmonitor
"""
import json
import subprocess
import time
from urllib.parse import parse_qs
from pathlib import Path
from playwright.sync_api import sync_playwright


def run_panel(context):
    a = context['args']
    central = a.central_site.resolve()
    if central == context['site']:
        raise ValueError('Separate disposable sites required')
    checks = []
    def check(ok, name):
        if not ok:
            raise AssertionError(name)
        checks.append(name)
    def fixture(action, **data):
        p = subprocess.run([str(a.php), str(Path(__file__).with_name('backup_roles_fixture.php')),
                            str(central), '--allow-disposable-writes'],
                           input=json.dumps(dict(data, action=action)), text=True,
                           capture_output=True, timeout=30)
        if p.returncode:
            raise RuntimeError('Disposable fixture failed')
        return json.loads(p.stdout)
    path = central/'administrator/components/com_nicodewebmonitor/src/Service/BackupCoordinator.php'
    original = path.read_bytes()
    source = Path(__file__).resolve().parents[1]/'extensions/com_nicodewebmonitor/administrator/src/Service/BackupCoordinator.php'
    check(original.replace(b'\r\n', b'\n') == source.read_bytes().replace(b'\r\n', b'\n'), 'installed coordinator matches source')
    replacement = '(new BackupClient('+json.dumps(str(a.certificate.resolve()).replace('\\','/'))+", ['127.0.0.1']))"
    check(original.count(b'(new BackupClient())') == 1, 'single fixture-only transport override')
    initial = fixture('snapshot')
    f = None
    storage = context['storage']
    calls = []
    responses = []
    try:
        diagnostic = "file_put_contents("+json.dumps((storage/'transport-result.json').as_posix())+", json_encode(['ok'=>$result['ok'] ?? false,'error'=>$result['error'] ?? null])); return $result;"
        path.write_bytes(original.replace(b'(new BackupClient())', replacement.encode()).replace(b'return $result;', diagnostic.encode()))
        f = fixture('setup', site_id=context['site_id'], backup_token=context['backup'],
                    url=f"https://localhost:{context['secure'].server_port}")
        with sync_playwright() as pw:
            browser = pw.chromium.launch(channel='msedge', headless=True)
            page = browser.new_page(viewport={'width':390,'height':1000})
            page.route('**/*', lambda route: route.continue_() if route.request.url.startswith('http://127.0.0.1:8767/') else route.abort())
            page.on('request', lambda req: calls.append(req.post_data) if req.post_data and 'task=backupContinue' in req.post_data else None)
            def response_received(response):
                if response.request.post_data and 'task=backupContinue' in response.request.post_data:
                    item={'status':response.status,'type':response.headers.get('content-type'),'cache':response.headers.get('cache-control')}
                    try:
                        data=response.json()
                        item['projection']={k:v for k,v in data.items() if k in ['ok','status','next_sequence']}
                    except Exception:
                        item['json']=False
                    responses.append(item)
            page.on('response',response_received)
            page.on('requestfailed',lambda req: responses.append({'network_failure':req.failure}))
            base='http://127.0.0.1:8767/administrator/index.php'
            url=base+'?option=com_nicodewebmonitor'
            page.goto(base)
            page.locator('input[name=username]').fill(f['users']['operator']['username'])
            page.locator('input[name=passwd]').fill(f['users']['operator']['password'])
            page.locator('button[type=submit]').click()
            page.goto(url)
            check(page.locator('h1').inner_text()=='Nicode Web Monitor','real operator browser login')
            section=page.get_by_role('region',name='Remote backups')
            section.locator('summary').filter(has_text='Disposable role QA').click()
            begin=section.locator('form').filter(has=page.locator('input[value=backupBegin]')).last
            begin.locator('input[name=confirmed]').check()
            begin.locator('button').click()
            card=page.locator('.nwm-backup').filter(has_text='Site #'+str(f['site'])+';')
            operation=card.get_attribute('data-operation')
            card=page.locator('.nwm-backup').filter(has_text=operation)
            (storage/'armed.json').write_text(json.dumps({'operation':operation,'writers_stopped':True,'expires_at':int(time.time())+900}))
            runner=card.locator('.nwm-backup-runner')
            runner.wait_for(state='visible')
            check(runner.is_visible(),'packaged runner JavaScript loaded')
            runner.get_by_role('button',name='Run backup steps').click()
            check(len(calls)==0,'unchecked start has no network effects')
            runner.locator('input[name=confirmed]').check()
            runner.get_by_role('button',name='Run backup steps').click()
            page.wait_for_function("document.querySelector('.nwm-backup-progress').textContent.startsWith('Stopped:')")
            check(len(calls)==2,'loss at native second step stops without third dispatch; observed '+str(len(calls)))
            check(runner.get_by_role('button',name='Run backup steps').is_disabled(),'uncertain operation cannot restart in stale tab')
            page.wait_for_timeout(500)
            check(len(calls)==2,'no delayed automatic retry')
            card.get_by_role('button',name='Query backup status').click()
            card=page.locator('.nwm-backup').filter(has_text=operation)
            check(card.get_attribute('data-sequence')=='2','explicit query reconciles native effect to sequence 2')
            runner=card.locator('.nwm-backup-runner')
            for attempt in range(2):
                before=len(calls)
                runner.locator('input[name=confirmed]').check()
                with page.expect_request(lambda req: req.post_data and 'task=backupContinue' in req.post_data):
                    runner.get_by_role('button',name='Run backup steps').click()
                runner.get_by_role('button',name='Pause after current step').click()
                page.wait_for_function("document.querySelector('.nwm-backup-progress').textContent.startsWith('Paused after')")
                after=len(calls)
                check(after>before,'explicit resume advances native capture '+str(attempt))
                page.wait_for_timeout(400)
                check(after==len(calls),'pause sends no further requests '+str(attempt))
                check(not runner.locator('input[name=confirmed]').is_checked(),'resume requires fresh confirmation '+str(attempt))
            check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'),'mobile runner has no page overflow')
            page.screenshot(path=str(storage/'browser-paused.png'),full_page=True)
            for width in [1440,320]:
                page.set_viewport_size({'width':width,'height':1000})
                check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'),'paused runner has no page overflow at '+str(width))
                card.screenshot(path=str(storage/('runner-'+str(width)+'.png')))
            check(all(r.get('type','').startswith('application/json') and 'no-store' in r.get('cache','') for r in responses),'continuation replies are JSON and never cached')
            receipt=context['request'](context['backup'],task='backup_site',suffix='&site_id='+context['site_id']+'&operation='+operation+'&action=status')[2]
            check(receipt['next_sequence']==int(card.get_attribute('data-sequence')),'browser progress matches native durable receipt')
            check(not receipt['restoration_verified'],'no false restoration claim')
            browser.close()
    finally:
        (storage/'browser-responses.json').write_text(json.dumps(responses,indent=2))
        (storage/'browser-fields.json').write_text(json.dumps([{k:v for k,v in parse_qs(body).items() if k in ['task','confirmed','expected_sequence']} for body in calls],indent=2))
        path.write_bytes(original)
        (storage/'armed.json').unlink(missing_ok=True)
        if f:
            fixture('cleanup', **f)
        check(path.read_bytes()==original,'installed transport restored')
        check(fixture('snapshot')==initial,'temporary users and product rows cleaned')
    return {'passed':len(checks),'checks':checks,'browser_dispatches':len(calls),
            'native_next_sequence':receipt['next_sequence'],'complete_capture':False,
            'real_browser_operator':True,'native_tls_connector':True}
