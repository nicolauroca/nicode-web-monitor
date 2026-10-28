"""Package: Nicode.joomla.webmonitor. Real disposable Joomla core HTTP queue -> one-shot CLI -> fresh reconciliation.
Requires a recoverable, offline clone with writers stopped. Never target production.
"""
import http.cookiejar
import re
import urllib.parse
import argparse
import hashlib
import http.server
import json
import os
from pathlib import Path
import secrets
import ssl
import subprocess
import threading
import urllib.request
import urllib.error
import uuid

p=argparse.ArgumentParser(description=__doc__)
for name in ['central','site','php','certificate','private-key','storage','package']:
    p.add_argument('--'+name,type=Path,required=True)
p.add_argument('--sha256',required=True);p.add_argument('--allow-disposable-writes',action='store_true',required=True)
a=p.parse_args();repo=Path(__file__).resolve().parents[1];site=a.site.resolve();store=a.storage.resolve()
assert (site/'.nwm-disposable-core').is_file() and not store.exists()
store.mkdir();(store/'updates').mkdir();(store/'gate').mkdir();checks=[]
def check(ok,name):
    if not ok:raise AssertionError(name)
    checks.append(name)
def cli(script,*args,data=None,allowed=(0,)):
    r=subprocess.run([str(a.php),str(repo/script),*map(str,args)],input=json.dumps(data) if data is not None else None,
        capture_output=True,text=True,timeout=180,creationflags=subprocess.CREATE_NO_WINDOW)
    if r.returncode not in allowed:
        (store/'failure.txt').write_text(r.stdout+'\n'+r.stderr,encoding='utf-8')
        raise RuntimeError('Fixture CLI failed: '+script+' code '+str(r.returncode))
    return json.loads(r.stdout)
def fixture(action,**data):
    return cli('tests/update_fixture.php',site,'--allow-disposable-writes',data=dict(action=action,**data))
fixture('install',packages=[str(repo/'dist/plg_system_nicodewebmonitor-0.5.0-dev.zip')])
site_id=str(uuid.uuid4());token=secrets.token_hex(32);other=secrets.token_hex(32)
params={'site_id':site_id,'core_token_hash':hashlib.sha256(token.encode()).hexdigest(),
    'update_token_hash':hashlib.sha256(other.encode()).hexdigest(), 'update_storage':str(store/'updates'),'maintenance_storage':str(store/'gate')}
fixture('configure',params=params)
operation='native-core-panel-269'
prepared=cli('tools/prepare_core_update.php',site,a.package,a.sha256,operation,'--writers-stopped-and-recovery-verified')
check(not prepared['installed'] and not prepared['queued'],'local preparation has no dispatch or installation effects')
drop=False;post_effects=0
class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def do_GET(self):
        global drop,post_effects
        endpoint,_,query=self.path.partition('?')
        if endpoint!='/index.php':self.send_error(404);return
        env=os.environ.copy();env.update({'REDIRECT_STATUS':'200','GATEWAY_INTERFACE':'CGI/1.1','SCRIPT_FILENAME':str(site/'index.php'),
            'SCRIPT_NAME':'/index.php','DOCUMENT_ROOT':str(site),'QUERY_STRING':query,'REQUEST_URI':self.path,
            'REQUEST_METHOD':self.command,'SERVER_PROTOCOL':'HTTP/1.1','SERVER_NAME':'localhost',
            'SERVER_PORT':str(self.server.server_port),'REMOTE_ADDR':'127.0.0.1','HTTP_HOST':self.headers['Host'],'HTTPS':'on',
            'HTTP_AUTHORIZATION':self.headers.get('Authorization',''),'CONTENT_TYPE':self.headers.get('Content-Type',''),
            'CONTENT_LENGTH':self.headers.get('Content-Length','0')})
        body=self.rfile.read(int(env['CONTENT_LENGTH']))
        r=subprocess.run([str(a.php.with_name('php-cgi.exe'))],env=env,cwd=site,input=body,capture_output=True,timeout=25,creationflags=subprocess.CREATE_NO_WINDOW)
        raw,sep,body=r.stdout.partition(b'\r\n\r\n')
        if not sep:self.send_error(502);return
        headers=[x.decode('latin1').split(':',1) for x in raw.split(b'\r\n') if b':' in x]
        code=next((int(v.strip().split()[0]) for k,v in headers if k.lower()=='status'),200)
        if self.command=='POST':post_effects+=1
        if drop and self.command=='POST':drop=False;self.close_connection=True;return
        self.send_response(code)
        for k,v in headers:
            if k.lower() not in ['status','content-length','connection']:self.send_header(k,v.strip())
        self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
    do_POST=do_GET
server=http.server.HTTPServer(('127.0.0.1',0),Handler);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(a.certificate,a.private_key);server.socket=ctx.wrap_socket(server.socket,server_side=True)
thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
url='https://localhost:'+str(server.server_port)
def request(execute=False,bearer=token):
    return cli('tests/core_client.php',data={'ca':str(a.certificate.resolve()),'url':url,'site_id':site_id,'token':bearer,'operation':operation,'execute':execute})

central=a.central.resolve()
cli('tests/update_fixture.php',central,'--allow-disposable-writes',data={'action':'install','packages':[str(repo/'dist/com_nicodewebmonitor-0.8.0-dev.zip')]})
path=central/'administrator/components/com_nicodewebmonitor/src/Service/CoreUpdateCoordinator.php'
original=path.read_bytes()
assert original.replace(b'\r\n',b'\n')==(repo/'extensions/com_nicodewebmonitor/administrator/src/Service/CoreUpdateCoordinator.php').read_bytes().replace(b'\r\n',b'\n')
needle=b"(new UpdateClient(scope: 'core_update'))"
replacement="(new UpdateClient(caFile: "+json.dumps(str(a.certificate.resolve()).replace('\\','/'))+", allowedPrivateAddresses: ['127.0.0.1'], scope: 'core_update'))"
assert original.count(needle)==1
path.write_bytes(original.replace(needle,replacement.encode()))
roledata=None
def roles(action,**data):
    return cli('tests/backup_roles_fixture.php',central,'--allow-disposable-writes',data=dict(action=action,**data))
def session(role):
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    base='http://127.0.0.1:8767/administrator/index.php'
    def call(data=None,url=base+'?option=com_nicodewebmonitor'):
        try:r=opener.open(url,None if data is None else urllib.parse.urlencode(data).encode(),timeout=40)
        except urllib.error.HTTPError as e:r=e
        with r:return r.status,r.read().decode()
    code,html=call(url=base); csrf=re.search(r'name="([a-f0-9]{32})" value="1"',html).group(1)
    u=roledata['users'][role];call({'option':'com_login','task':'login','username':u['username'],'passwd':u['password'],csrf:1},base)
    code,html=call();check(code==200 and '<h1>Nicode Web Monitor</h1>' in html,role+' authenticated')
    csrf=re.search(r'name="([a-f0-9]{32})" value="1"',html).group(1)
    return call,csrf,html

try:
    initial=request()
    (store/'initial.json').write_text(json.dumps(initial))
    check(initial.get('error')=='operation_unknown','prepared operation not falsely reported queued')
    check(request(True,other)['error']=='access_denied' and not (store/'gate/owner.json').exists(),'extension credential denied for core with no gate effects')
    initial_posts=post_effects
    roledata=roles('setup',site_id=site_id,url=url,update_token=other,core_token=token)
    for role in ['reader','operator','backup_operator']:
        call,csrf,html=session(role)
        check('Prepared Joomla core updates' not in html,role+' cannot view core controls')
        for task in ['coreUpdateBegin','coreUpdateExecute','coreUpdateStatus','coreUpdateEnroll']:
            check(call({'task':task,'id':roledata['site'],'operation':operation,'confirmed':'1',csrf:1})[0]==403,role+' denied '+task)
    check(post_effects==initial_posts and not (store/'gate/owner.json').exists(),'ACL denial has no outbound effects')
    call,csrf,html=session('core_operator')
    check('Prepared Joomla core updates' in html and 'Prepared extension updates' not in html,'core-only operator sees only its controls')
    check(call({'task':'coreUpdateEnroll','id':roledata['site'],'core_update_credential':token,csrf:1})[0]==403,'core operator cannot enroll credentials')
    check(call({'task':'coreUpdateBegin','id':roledata['site'],'operation':operation,'confirmed':'1'})[0]==403,'core CSRF required')
    def panel(task,confirm='1'):
        return call({'task':task,'id':roledata['site'],'operation':operation,'confirmed':confirm,csrf:1})
    check('state: ready' in panel('coreUpdateBegin')[1],'panel registers prepared core operation')
    panel('coreUpdateExecute',confirm='0');check(post_effects==initial_posts,'missing confirmation has no remote dispatch')
    drop=True;code,html=panel('coreUpdateExecute')
    check('state: uncertain' in html,'lost queue response leaves panel uncertain')
    before=post_effects;panel('coreUpdateExecute');check(before==post_effects,'uncertain core panel cannot dispatch again')
    code,html=panel('coreUpdateStatus');check('state: queued' in html,'panel GET reconciles durable queue')
    before=post_effects;panel('coreUpdateExecute');check(before==post_effects,'queued panel cannot redispatch')
    journal=store/'updates/core'/operation/'journal'/('op-'+operation)
    check(not journal.exists(),'web request never loads native updater')
    first=cli('tools/core_update_execute.php',site,operation,'--execute-queued',allowed=(3,))
    check(first['status']=='uncertain' and (store/'gate/owner.json').exists(),'native CLI completes effects but leaves verification to a fresh process')
    check('state: uncertain' in panel('coreUpdateStatus')[1],'panel reflects native uncertainty without loading stale updater')
    receipt=(journal/'returned.json').read_bytes()
    replay=cli('tools/core_update_execute.php',site,operation,'--execute-queued',allowed=(3,))
    check(replay['status']=='uncertain' and receipt==(journal/'returned.json').read_bytes(),'explicit CLI replay does not rerun native installation')
    verified=cli('tools/core_update_execute.php',site,operation,'--reconcile')
    check(verified['status']=='verified' and verified['observed']['schema_errors']==0,'new CLI verifies native runtime manifest database and schema')
    check(not (store/'gate/owner.json').exists(),'verified native operation releases shared maintenance')
    code,html=panel('coreUpdateStatus');check('state: verified' in html,'panel observes fresh-process verified upgrade')
    before=post_effects;panel('coreUpdateExecute');check(post_effects==before,'verified core cannot reinstall')
    check(token not in html and str(store) not in html,'panel does not expose credential or private path')
    final=request();check(final['ok'] and final['receipt']['status']=='verified' and not final['reconcile_required'],'TLS client observes verified core receipt')
    (store/'result.json').write_text(json.dumps({'passed':len(checks),'checks':checks,'plan':prepared['plan'],'observed':verified['observed'],
        'central_panel_tested':True,'cli_dispatch_is_manual':True},indent=2))
    print(json.dumps({'passed':len(checks),'from':prepared['plan']['from'],'to':prepared['plan']['to']}))
finally:
    path.write_bytes(original)
    cleanup={'transport_restored':path.read_bytes()==original}
    if roledata:cleanup['roles']=roles('cleanup',**roledata)
    (store/'cleanup.json').write_text(json.dumps(cleanup))
    server.shutdown();server.server_close();thread.join(timeout=5)
