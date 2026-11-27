"""Real Joomla reader/operator HTTP authorization; ephemeral users, no outbound requests.
Package: Nicode.joomla.webmonitor
"""
import argparse, http.cookiejar, json, re, subprocess, urllib.request, urllib.parse, urllib.error
from pathlib import Path
import ssl

p=argparse.ArgumentParser()
p.add_argument('--certificate',type=Path,help='Explicit local CA for disposable HTTPS')
p.add_argument('--php',required=True); p.add_argument('--joomla',required=True)
p.add_argument('--base-url',required=True)
p.add_argument('--allow-disposable-writes',required=True,action='store_true')
a=p.parse_args()
parts=urllib.parse.urlsplit(a.base_url)
if parts.scheme not in ['http','https'] or parts.hostname not in ('127.0.0.1','localhost') or parts.username or parts.password or parts.query or parts.fragment:
    raise SystemExit('Disposable loopback HTTP(S) only')
base=a.base_url.rstrip('/')+'/administrator/index.php'
url=base+'?option=com_nicodewebmonitor'; checks=[]
def fixture(action, **data):
    r=subprocess.run([a.php,str(Path(__file__).with_name('backup_roles_fixture.php')),a.joomla,'--allow-disposable-writes'],input=json.dumps(dict(data,action=action)),text=True,capture_output=True,timeout=30)
    if r.returncode: raise RuntimeError('Fixture failed: '+r.stderr[:1200])
    return json.loads(r.stdout)
def check(ok,name):
    if not ok: raise AssertionError(name)
    checks.append(name)
def csrf(html): return re.search(r'name="([a-f0-9]{32})" value="1"',html).group(1)
def client():
    handlers=[urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())]
    if parts.scheme=='https':
        if not a.certificate: raise SystemExit('Explicit local CA required for HTTPS')
        handlers.append(urllib.request.HTTPSHandler(context=ssl.create_default_context(cafile=str(a.certificate))))
    op=urllib.request.build_opener(*handlers)
    def request(target=None,data=None):
        target=target or url
        try:
            with op.open(target,None if data is None else urllib.parse.urlencode(data).encode(),timeout=15) as r: return r.status,r.read().decode()
        except urllib.error.HTTPError as e: return e.code,e.read().decode()
    return request
initial=fixture('snapshot'); f=fixture('setup')
url+='&site='+str(f['site'])+'&section=overview'
try:
    for role in ('reader','operator'):
        request=client(); _,html=request(base); u=f['users'][role]
        request(base,{'option':'com_login','task':'login','username':u['username'],'passwd':u['password'],csrf(html):1})
        status,html=request(); check(status==200 and 'aria-label="Nicode Web Monitor — Central"' in html,role+' authenticated component access')
        token=csrf(html); before=fixture('snapshot')
        status,html=request(url.replace('section=overview','section=maintenance&tool=backups'))
        check(('aria-label="Copias de seguridad"' in html)==(role=='operator'),role+' backup controls visibility')
        check('Historial de mantenimiento (últimas 50 observaciones)' not in html,role+' no invented history for missing operations')
        tasks=['backupEnroll'] if role=='operator' else ['backupEnroll','backupBegin','backupAdvance','backupContinue','backupStatus']
        for task in tasks:
            status,_=request(data={'task':task,'id':f['site'],'operation':'backup-'+'a'*32,'confirmed':'1','backup_credential':'b'*64,token:1})
            check(status==403,role+' denied '+task+' with valid CSRF')
        check(fixture('snapshot')==before,role+' forbidden actions leave database unchanged')
        if role=='reader': continue
        status,html=request(data={'task':'backupBegin','id':f['site'],token:1})
        check('Backup action not confirmed' in html and fixture('snapshot')==before,'operator begin requires confirmation without effects')
        status,html=request(data={'task':'backupBegin','id':f['site'],'confirmed':'1',token:1})
        cards=re.findall(r'<article class="nwm-backup".*?</article>',html,re.S)
        matching=[x for x in cards if 'Sitio #'+str(f['site'])+';' in x]
        check(bool(matching),'created operation remains on the selected site maintenance page')
        card=matching[0]
        operation=re.search(r'backup-[a-f0-9]{32}',card).group()
        check('estado: ready' in card,'operator creates ready operation')
        before=fixture('snapshot')
        for payload in [dict(expected_sequence='0'), dict(confirmed='1'), dict(confirmed='1',expected_sequence='1')]:
            status,body=request(data={'task':'backupContinue','operation':operation,token:1,**payload})
            check(status==200 and json.loads(body)=={'ok':False} and fixture('snapshot')==before,'runner rejects missing confirmation/sequence or stale sequence without effects')
        status,_=request(data={'task':'backupContinue','operation':operation,'confirmed':'1','expected_sequence':'0'})
        check(status==403 and fixture('snapshot')==before,'runner rejects missing CSRF without effects')
        status,html=request(data={'task':'backupAdvance','operation':operation,token:1})
        check('Backup action not confirmed' in html and fixture('snapshot')==before,'operator advance requires confirmation')
        status,html=request(data={'task':'backupAdvance','operation':operation,'confirmed':'1',token:1})
        check('estado: uncertain' in html,'rejected transport persists uncertain journal through real dispatcher')
        check('Historial de mantenimiento (últimas 50 observaciones)' in html and re.search(r'UTC.</p>\s*<ol>.*?uncertain.*?ready',html,re.S), 'HTTP history renders prior ready and uncertain observations')
        before=fixture('snapshot')
        request(data={'task':'backupAdvance','operation':operation,'confirmed':'1',token:1})
        check(fixture('snapshot')==before,'uncertain operation blocks blind advance')
        status,html=request(data={'task':'backupStatus','operation':operation,token:1})
        check(status==200 and 'Backup action not confirmed' in html and fixture('snapshot')==before,'operator status failure preserves uncertain journal')
finally:
    check(fixture('cleanup',**f)['cleaned'],'disposable accounts groups and rows removed')
check(fixture('snapshot')==initial,'original product database restored')
print(json.dumps({'passed':len(checks),'checks':checks,'real_joomla_accounts':True,'successful_remote_capture_tested':False},indent=2))
