"""Real Joomla reader/operator HTTP authorization; ephemeral users, no outbound requests.
Package: Nicode.joomla.webmonitor
"""
import argparse, http.cookiejar, json, re, subprocess, urllib.request, urllib.parse, urllib.error
from pathlib import Path

p=argparse.ArgumentParser()
p.add_argument('--php',required=True); p.add_argument('--joomla',required=True)
p.add_argument('--base-url',required=True)
p.add_argument('--allow-disposable-writes',required=True,action='store_true')
a=p.parse_args()
parts=urllib.parse.urlsplit(a.base_url)
if parts.scheme!='http' or parts.hostname not in ('127.0.0.1','localhost') or parts.username or parts.password or parts.query or parts.fragment:
    raise SystemExit('Disposable loopback HTTP only')
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
    op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def request(target=url,data=None):
        try:
            with op.open(target,None if data is None else urllib.parse.urlencode(data).encode(),timeout=15) as r: return r.status,r.read().decode()
        except urllib.error.HTTPError as e: return e.code,e.read().decode()
    return request
initial=fixture('snapshot'); f=fixture('setup')
try:
    for role in ('reader','operator'):
        request=client(); _,html=request(base); u=f['users'][role]
        request(base,{'option':'com_login','task':'login','username':u['username'],'passwd':u['password'],csrf(html):1})
        status,html=request(); check(status==200 and '<h1>Nicode Web Monitor</h1>' in html,role+' authenticated component access')
        token=csrf(html); before=fixture('snapshot')
        check(('aria-label="Remote backups"' in html)==(role=='operator'),role+' backup controls visibility')
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
        card=next(x for x in cards if 'Site #'+str(f['site'])+';' in x)
        operation=re.search(r'backup-[a-f0-9]{32}',card).group()
        check('state: ready' in card,'operator creates ready operation')
        before=fixture('snapshot')
        for payload in [dict(expected_sequence='0'), dict(confirmed='1'), dict(confirmed='1',expected_sequence='1')]:
            status,body=request(data={'task':'backupContinue','operation':operation,token:1,**payload})
            check(status==200 and json.loads(body)=={'ok':False} and fixture('snapshot')==before,'runner rejects missing confirmation/sequence or stale sequence without effects')
        status,_=request(data={'task':'backupContinue','operation':operation,'confirmed':'1','expected_sequence':'0'})
        check(status==403 and fixture('snapshot')==before,'runner rejects missing CSRF without effects')
        status,html=request(data={'task':'backupAdvance','operation':operation,token:1})
        check('Backup action not confirmed' in html and fixture('snapshot')==before,'operator advance requires confirmation')
        status,html=request(data={'task':'backupAdvance','operation':operation,'confirmed':'1',token:1})
        check('state: uncertain' in html,'rejected transport persists uncertain journal through real dispatcher')
        before=fixture('snapshot')
        request(data={'task':'backupAdvance','operation':operation,'confirmed':'1',token:1})
        check(fixture('snapshot')==before,'uncertain operation blocks blind advance')
        status,html=request(data={'task':'backupStatus','operation':operation,token:1})
        check(status==200 and 'Backup action not confirmed' in html and fixture('snapshot')==before,'operator status failure preserves uncertain journal')
finally:
    check(fixture('cleanup',**f)['cleaned'],'disposable accounts groups and rows removed')
check(fixture('snapshot')==initial,'original product database restored')
print(json.dumps({'passed':len(checks),'checks':checks,'real_joomla_accounts':True,'successful_remote_capture_tested':False},indent=2))
