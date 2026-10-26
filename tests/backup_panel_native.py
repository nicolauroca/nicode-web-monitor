"""Native sequenced connector exercised by real Joomla operator forms.

Called by backup_http.py. Uses a separate remote Joomla/database with writers stopped.
The installed central transport gets a temporary loopback/CA fixture override; the
repository and distributable are never weakened. Original bytes are restored on exit.
Package: Nicode.joomla.webmonitor
"""
import http.cookiejar
import json
import re
import subprocess
import time
import urllib.request
import urllib.parse
from pathlib import Path


def run_panel(context):
    a = context['args']
    central = a.central_site.resolve()
    remote = context['site']
    if central == remote or not 1 <= a.central_steps <= 20000:
        raise ValueError('Separate disposable central and remote sites required')
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
    check(original.count(b'(new BackupClient())') == 1, 'single test transport override')
    initial = fixture('snapshot')
    f = None
    durations = []
    started = time.monotonic()
    operation = None
    try:
        path.write_bytes(original.replace(b'(new BackupClient())', replacement.encode()))
        resume=context['resume']
        extra={'resume_receipt':resume['receipt']} if resume else {}
        f = fixture('setup', site_id=context['site_id'], backup_token=context['backup'],
                    url=f"https://localhost:{context['secure'].server_port}", **extra)
        probe = subprocess.run([str(a.php), str(Path(__file__).with_name('backup_client.php'))],
            input=json.dumps({'url':f"https://localhost:{context['secure'].server_port}", 'address':'127.0.0.1',
                'site_id':context['site_id'], 'token':context['backup'], 'ca':str(a.certificate.resolve()), 'operation':'probe-unknown'}),
            text=True, capture_output=True, timeout=30)
        diagnostic=json.loads(probe.stdout)
        check(diagnostic.get('error')=='operation_unknown', 'native readonly connectivity: '+str(diagnostic.get('error')))
        op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        base = 'http://127.0.0.1:8767/administrator/index.php'
        url = base+'?option=com_nicodewebmonitor'
        def request(target=url, data=None):
            with op.open(target, None if data is None else urllib.parse.urlencode(data).encode(), timeout=35) as r:
                return r.read().decode()
        def csrf(html):
            return re.search(r'name="([a-f0-9]{32})" value="1"', html).group(1)
        html = request(base)
        u = f['users']['operator']
        request(base, {'option':'com_login','task':'login','username':u['username'],'passwd':u['password'],csrf(html):1})
        html = request()
        check('<h1>Nicode Web Monitor</h1>' in html, 'real operator authenticated')
        token = csrf(html)
        if not resume:
            html = request(data={'task':'backupBegin','id':f['site'],'confirmed':'1',token:1})
        card = next(x for x in re.findall(r'<article class="nwm-backup".*?</article>', html, re.S) if 'Site #'+str(f['site'])+';' in x)
        operation = re.search(r'backup-[a-f0-9]{32}', card).group()
        check(('state: progress' if resume else 'state: ready') in card, 'fixture resumed from native receipt' if resume else 'operation created through panel')
        first_sequence=resume['receipt']['next_sequence'] if resume else 0
        storage = context['storage']
        (storage/'armed.json').write_text(json.dumps({'operation':operation,'writers_stopped':True,'expires_at':int(time.time())+900}))
        for seq in range(first_sequence,first_sequence+a.central_steps):
            if seq % 100 == 0:
                (storage/'armed.json').write_text(json.dumps({'operation':operation,'writers_stopped':True,'expires_at':int(time.time())+900}))
            begin = time.monotonic()
            html = request(data={'task':'backupAdvance','operation':operation,'confirmed':'1',token:1})
            durations.append(time.monotonic()-begin)
            if seq == 1:
                check('state: uncertain' in html, 'lost native response leaves uncertain panel journal')
                before = fixture('snapshot')
                request(data={'task':'backupAdvance','operation':operation,'confirmed':'1',token:1})
                check(fixture('snapshot') == before, 'uncertain native step cannot be blindly repeated')
                html = request(data={'task':'backupStatus','operation':operation,token:1})
                check('Backup journal updated' in html, 'lost native response reconciled by panel status')
            card = next(x for x in re.findall(r'<article class="nwm-backup".*?</article>', html, re.S) if operation in x)
            (storage/'last-card.html').write_text(card, encoding='utf-8')
            check('next sequence: '+str(seq+1)+';' in card and ('state: progress' in card or 'state: complete' in card), 'native panel advance '+str(seq))
            if seq in (first_sequence, first_sequence+a.central_steps-1):
                html = request(data={'task':'backupStatus','operation':operation,token:1})
                check('Backup journal updated' in html, 'successful panel status '+str(seq))
                # updated_at can change; compare native receipt instead of mutable timestamps.
                receipt = context['request'](context['backup'], task='backup_site', suffix='&site_id='+context['site_id']+'&operation='+operation+'&action=status')[2]
                check(receipt['next_sequence']==seq+1, 'status does not advance native capture '+str(seq))
                check(not receipt['restoration_verified'], 'no false restoration claim '+str(seq))
            progress = {'operation':operation,'steps':seq+1,'complete':'state: complete' in card,
                        'elapsed_seconds':time.monotonic()-started,'restoration_verified':False}
            (storage/'panel-progress.json').write_text(json.dumps(progress,indent=2))
            if progress['complete']:
                break
        result = dict(progress, checks=checks, resumed=bool(resume), initial_sequence=first_sequence,
                    real_operator_forms=True, native_connector=True,
                    tls_verified=True, fixture_transport_override=True,
                    max_panel_request_seconds=max(durations))
    finally:
        path.write_bytes(original)
        if f:
            fixture('cleanup', **f)
        if operation:
            (context['storage']/'armed.json').unlink(missing_ok=True)
        check(path.read_bytes()==original, 'original installed transport restored')
        check(fixture('snapshot')==initial, 'central product rows restored')
    result['passed'] = len(checks)
    return result
