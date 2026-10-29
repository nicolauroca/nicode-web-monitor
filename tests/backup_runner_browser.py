"""Browser runner boundary cases with synthetic server replies; no remote writes.
Package: Nicode.joomla.webmonitor
"""
import json
from pathlib import Path
from urllib.parse import parse_qs
from playwright.sync_api import sync_playwright

checks=[]
def check(ok,name):
    if not ok: raise AssertionError(name)
    checks.append(name)
html='''<article class="nwm-backup" data-operation="backup-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" data-sequence="0">
<h4>Operation</h4><p>Ready</p>
<form><input name="task" value="backupStatus"><button>Query backup status</button></form>
<form class="nwm-backup-runner" action="http://127.0.0.1/step" method="post" hidden>
<input name="task" value="backupContinue"><input name="operation" value="backup-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa">
<input name="expected_sequence" value="0"><input name="confirmed" type="checkbox" value="1" required>
<button type="submit">Run backup steps</button><button type="button" class="nwm-backup-pause" disabled>Pause after current step</button>
<p class="nwm-backup-progress" role="status"></p></form></article>'''
with sync_playwright() as pw:
    browser=pw.chromium.launch(channel='msedge',headless=True)
    for mode in ['complete','limit','pause','lost','wrong-operation','wrong-sequence','bad-status','html-login','rejected']:
        page=browser.new_page(); calls=[]
        def serve(route):
            if route.request.method=='GET':
                route.fulfill(status=200,content_type='text/html',body=html); return
            data=parse_qs(route.request.post_data);calls.append(data)
            seq=int(data['expected_sequence'][0]); status='complete' if mode in ('complete','pause') and seq==2 else 'progress'
            receipt={'ok':True,'operation':data['operation'][0],'status':status,'next_sequence':seq+1}
            if mode=='lost':route.abort();return
            if mode=='html-login':route.fulfill(status=200,content_type='text/html',body='Login');return
            if mode=='wrong-operation':receipt['operation']='other'
            if mode=='wrong-sequence':receipt['next_sequence']=seq+2
            if mode=='bad-status':receipt['status']='uncertain'
            if mode=='rejected':receipt={'ok':False}
            if mode=='pause' and len(calls)==1:
                page.get_by_role('button',name='Pause after current step').click()
            route.fulfill(status=200,content_type='application/json',body=json.dumps(receipt))
        page.route('**/*',serve)
        page.goto('http://127.0.0.1/')
        page.add_script_tag(path=str(Path(__file__).resolve().parents[1]/'extensions/com_nicodewebmonitor/administrator/tmpl/backup-runner.js'))
        page.locator('input[name=confirmed]').check()
        page.get_by_role('button',name='Run backup steps').click()
        page.wait_for_function("/^(Stopped:|Capture complete|Paused after)/.test(document.querySelector('.nwm-backup-progress').textContent)")
        if mode=='complete':
            check(len(calls)==3 and 'Capture complete' in page.locator('[role=status]').inner_text(),'terminal completion stops at last receipt')
            check(page.get_by_role('button',name='Run backup steps').is_disabled(),'completed capture cannot resume')
        elif mode=='limit':
            check(len(calls)==100 and page.locator('input[name=expected_sequence]').input_value()=='100','batch budget stops after 100 sequential steps')
            check(not page.locator('input[name=confirmed]').is_checked(),'budget stop requires new confirmation')
        elif mode=='pause':
            check(len(calls)==1,'pause waits for in-flight request without sending next step')
            page.locator('input[name=confirmed]').check()
            page.get_by_role('button',name='Run backup steps').click()
            page.wait_for_function("document.querySelector('.nwm-backup-progress').textContent.startsWith('Capture complete')")
            check([int(x['expected_sequence'][0]) for x in calls]==[0,1,2],'explicit resume preserves operation and next sequence')
        else:
            check(len(calls)==1 and page.get_by_role('button',name='Run backup steps').is_disabled(),mode+' stops and locks advance without retry')
            check(page.get_by_role('button',name='Query backup status').is_enabled(),mode+' preserves explicit reconciliation')
        check([int(x['expected_sequence'][0]) for x in calls]==list(range(len(calls))),mode+' no duplicate/skipped sequence requests')
        page.close()
    browser.close()
print(json.dumps({'passed':len(checks),'checks':checks,'synthetic_replies':True},indent=2))
