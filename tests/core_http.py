"""Package: Nicode.joomla.webmonitor. Real disposable Joomla core HTTP queue -> one-shot CLI -> fresh reconciliation.
Requires a recoverable, offline clone with writers stopped. Never target production.
"""
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
for name in ['site','php','certificate','private-key','storage','package']:
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
operation='native-core-http-259'
prepared=cli('tools/prepare_core_update.php',site,a.package,a.sha256,operation,'--writers-stopped-and-recovery-verified')
check(not prepared['installed'] and not prepared['queued'],'local preparation has no dispatch or installation effects')
drop=False
class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def do_GET(self):
        global drop
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
try:
    initial=request()
    (store/'initial.json').write_text(json.dumps(initial))
    check(initial.get('error')=='operation_unknown','prepared operation not falsely reported queued')
    check(request(True,other)['error']=='access_denied' and not (store/'gate/owner.json').exists(),'extension credential denied for core with no gate effects')
    drop=True;lost=request(True)
    check(not lost['ok'] and lost['reconcile_required'],'lost dispatch response requires read reconciliation')
    queued=request();check(queued['ok'] and queued['receipt']['status']=='queued','GET recovers durable queue after lost response')
    check(request(True)['receipt']['status']=='queued','duplicate HTTP dispatch is idempotent')
    journal=store/'updates/core'/operation/'journal'/('op-'+operation)
    check(not journal.exists(),'web request never loads native updater')
    first=cli('tools/core_update_execute.php',site,operation,'--execute-queued',allowed=(3,))
    check(first['status']=='uncertain' and (store/'gate/owner.json').exists(),'native CLI completes effects but leaves verification to a fresh process')
    check(request()['receipt']['status']=='uncertain','HTTP reflects native uncertainty without reconciling stale classes')
    receipt=(journal/'returned.json').read_bytes()
    replay=cli('tools/core_update_execute.php',site,operation,'--execute-queued',allowed=(3,))
    check(replay['status']=='uncertain' and receipt==(journal/'returned.json').read_bytes(),'explicit CLI replay does not rerun native installation')
    verified=cli('tools/core_update_execute.php',site,operation,'--reconcile')
    check(verified['status']=='verified' and verified['observed']['schema_errors']==0,'new CLI verifies native runtime manifest database and schema')
    check(not (store/'gate/owner.json').exists(),'verified native operation releases shared maintenance')
    final=request();check(final['ok'] and final['receipt']['status']=='verified' and not final['reconcile_required'],'TLS client observes verified core receipt')
    (store/'result.json').write_text(json.dumps({'passed':len(checks),'checks':checks,'plan':prepared['plan'],'observed':verified['observed'],
        'central_panel_tested':False,'cli_dispatch_is_manual':True},indent=2))
    print(json.dumps({'passed':len(checks),'from':prepared['plan']['from'],'to':prepared['plan']['to']}))
finally:
    server.shutdown();server.server_close();thread.join(timeout=5)
