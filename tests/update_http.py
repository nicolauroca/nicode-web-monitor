"""Package: Nicode.joomla.webmonitor. Two disposable Joomla sites, native panel -> TLS -> installer.
Never point this test at production. A running disposable central site is required.
"""
import argparse
import hashlib
import http.cookiejar
import http.server
import json
import os
from pathlib import Path
import re
import secrets
import socket
import ssl
import subprocess
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
import zipfile

p=argparse.ArgumentParser(description=__doc__)
for name in ['site','central','php','certificate','private-key','storage']:
    p.add_argument('--'+name,type=Path,required=True)
p.add_argument('--allow-disposable-writes',action='store_true',required=True)
a=p.parse_args()
repo=Path(__file__).resolve().parents[1]
site=a.site.resolve(); central=a.central.resolve(); store=a.storage.resolve()
assert site!=central and not store.exists()
store.mkdir(); (store/'journal').mkdir(); (store/'prepared').mkdir()
(store/'maintenance').mkdir()
checks=[]
def check(ok,name):
    if not ok: raise AssertionError(name)
    checks.append(name)
def fixture(action,target=site,**data):
    proc=subprocess.run([str(a.php),str(repo/'tests/update_fixture.php'),str(target),'--allow-disposable-writes'],
        input=json.dumps(dict(data,action=action)),text=True,capture_output=True,timeout=60)
    if proc.returncode:
        # Private diagnostic can contain environment paths, but never input credentials.
        (store/'fixture-failure.txt').write_text(proc.stderr,encoding='utf-8')
        raise RuntimeError('Native fixture failed: '+action)
    return json.loads(proc.stdout)
def roles(action,**data):
    proc=subprocess.run([str(a.php),str(repo/'tests/backup_roles_fixture.php'),str(central),'--allow-disposable-writes'],
        input=json.dumps(dict(data,action=action)),text=True,capture_output=True,timeout=30)
    if proc.returncode: raise RuntimeError('Role fixture failed')
    return json.loads(proc.stdout)
def package(version):
    dest=store/('fixture-'+version+'.zip')
    with zipfile.ZipFile(dest,'x') as z:
        z.writestr('nwmupdateqa.xml','<?xml version="1.0"?><extension type="plugin" group="system" method="upgrade"><name>NWM Update QA</name><version>'+version+'</version><files><filename plugin="nwmupdateqa">nwmupdateqa.php</filename><filename>version.txt</filename></files></extension>')
        z.writestr('nwmupdateqa.php',"<?php defined('_JEXEC') or die;\n")
        z.writestr('version.txt',version)
    return dest
v1=package('1.0.0');v2=package('1.1.0');v3=package('1.2.0')
site_id=str(uuid.uuid4());token=secrets.token_hex(32);inventory=secrets.token_hex(32);backup=secrets.token_hex(32)
params={'site_id':site_id,'token_hash':hashlib.sha256(inventory.encode()).hexdigest(),
    'backup_token_hash':hashlib.sha256(backup.encode()).hexdigest(),
    'update_token_hash':hashlib.sha256(token.encode()).hexdigest(),'update_storage':str(store),
    'maintenance_storage':str(store/'maintenance')}
fixture('install',packages=[str(repo/'dist/plg_system_nicodewebmonitor-0.5.0-dev.zip')])
fixture('install',target=central,packages=[str(repo/'dist/com_nicodewebmonitor-0.7.0-dev.zip')])
remote=None;roledata=None
path=central/'administrator/components/com_nicodewebmonitor/src/Service/UpdateCoordinator.php'
original=path.read_bytes()
assert original.replace(b'\r\n',b'\n')==(repo/'extensions/com_nicodewebmonitor/administrator/src/Service/UpdateCoordinator.php').read_bytes().replace(b'\r\n',b'\n')
drop=False; post_effects=0
class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def do_GET(self):
        global drop,post_effects
        endpoint,_,query=self.path.partition('?')
        if endpoint!='/index.php':self.send_error(404);return
        env=os.environ.copy()
        env.update({'REDIRECT_STATUS':'200','GATEWAY_INTERFACE':'CGI/1.1','SCRIPT_FILENAME':str(site/'index.php'),
            'SCRIPT_NAME':'/index.php','DOCUMENT_ROOT':str(site),'QUERY_STRING':query,'REQUEST_URI':self.path,
            'REQUEST_METHOD':self.command,'SERVER_PROTOCOL':'HTTP/1.1','SERVER_NAME':'localhost',
            'SERVER_PORT':str(self.server.server_port),'REMOTE_ADDR':'127.0.0.1','HTTP_HOST':self.headers['Host'],
            'HTTPS':'on' if isinstance(self.connection,ssl.SSLSocket) else 'off',
            'HTTP_AUTHORIZATION':self.headers.get('Authorization',''),'CONTENT_TYPE':self.headers.get('Content-Type',''),
            'CONTENT_LENGTH':self.headers.get('Content-Length','0')})
        body=self.rfile.read(int(env['CONTENT_LENGTH']))
        result=subprocess.run([str(a.php.with_name('php-cgi.exe')),'-d','max_execution_time=20'],
            env=env,cwd=site,input=body,capture_output=True,timeout=25,creationflags=subprocess.CREATE_NO_WINDOW)
        raw,sep,body=result.stdout.partition(b'\r\n\r\n')
        if not sep:self.send_error(502);return
        headers=[x.decode('latin1').split(':',1) for x in raw.split(b'\r\n') if b':' in x]
        code=next((int(v.strip().split()[0]) for k,v in headers if k.lower()=='status'),200)
        if self.command=='POST':
            post_effects+=1
            if drop:
                drop=False;self.close_connection=True;return
        self.send_response(code)
        for k,v in headers:
            if k.lower() not in ['status','content-length','connection']:self.send_header(k,v.strip())
        self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
    do_POST=do_GET
secure=http.server.HTTPServer(('127.0.0.1',0),Handler)
ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(a.certificate,a.private_key)
secure.socket=ctx.wrap_socket(secure.socket,server_side=True)
plain=http.server.HTTPServer(('127.0.0.1',0),Handler)
threads=[threading.Thread(target=s.serve_forever,daemon=True) for s in [secure,plain]]
for t in threads:t.start()
resolve=socket.getaddrinfo
socket.getaddrinfo=lambda host,port,family=0,type=0,proto=0,flags=0:resolve(host,port,socket.AF_INET if host=='localhost' else family,type,proto,flags)
client=ssl.create_default_context(cafile=str(a.certificate))
def request(op='native-update-01',execute=False,credential=token,tls=True,changes=None,raw=None):
    data={'site_id':site_id,'operation':op,'action':'update' if execute else 'status'}
    if execute:data['confirmed']=True
    if changes:data.update(changes)
    url=f"{'https' if tls else 'http'}://localhost:{secure.server_port if tls else plain.server_port}/index.php?option=com_nicodewebmonitor&task=update_extension"
    if not execute:url+='&'+urllib.parse.urlencode(data)
    req=urllib.request.Request(url,data=(json.dumps(data).encode() if raw is None else raw) if execute else None,
        headers={'Authorization':'Bearer '+credential,'Content-Type':'application/json'})
    try:r=urllib.request.urlopen(req,context=client,timeout=30)
    except urllib.error.HTTPError as e:r=e
    with r:
        payload=r.read()
        try:decoded=json.loads(payload)
        except ValueError:decoded=None
        return r.status,decoded,payload
def prepare(op,package,from_version,to_version,**changes):
    d=store/'prepared'/op;d.mkdir()
    (d/'package.zip').write_bytes(package.read_bytes())
    permit={'operation':op,'writers_stopped':True,'recovery_verified':True,'expires_at':int(time.time())+600,
        'identity':{'type':'plugin','element':'nwmupdateqa','folder':'system','client_id':0},
        'plan':{'extension_id':remote['id'],'from':from_version,'to':to_version,'package_sha256':hashlib.sha256(package.read_bytes()).hexdigest()}}
    permit.update(changes);(d/'permit.json').write_text(json.dumps(permit),encoding='utf-8');return d
def session(role):
    opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    base='http://127.0.0.1:8767/administrator/index.php'
    def call(data=None,url=base+'?option=com_nicodewebmonitor'):
        try:r=opener.open(url,None if data is None else urllib.parse.urlencode(data).encode(),timeout=40)
        except urllib.error.HTTPError as e:r=e
        with r:return r.status,r.read().decode()
    code,html=call(url=base)
    csrf=re.search(r'name="([a-f0-9]{32})" value="1"',html).group(1)
    u=roledata['users'][role]
    call({'option':'com_login','task':'login','username':u['username'],'passwd':u['password'],csrf:1},base)
    code,html=call();check(code==200 and '<h1>Nicode Web Monitor</h1>' in html,role+' authenticated')
    csrf=re.search(r'name="([a-f0-9]{32})" value="1"',html).group(1)
    return call,csrf,html
try:
    remote=fixture('setup',package=str(v1),params=params)
    check(fixture('inspect')['version']=='1.0.0','native fixture installed')
    check(request(credential=inventory)[0]==401,'inventory credential denied')
    check(request(credential=backup)[0]==401,'backup credential denied')
    check(request(tls=False)[0]==403,'plain HTTP denied')
    check(request()[0]==404,'unknown status is read only')
    check(request(execute=True,raw=b'{')[0]==400,'malformed JSON denied')
    check(request(execute=True,raw=b'x'*4097)[0]==413,'oversize denied')
    check(request(execute=True,changes={'package':'https://example.invalid/package.zip'})[0]==400,'remote package injection denied')
    check(request(execute=True,changes={'confirmed':False})[0]==400,'confirmation required')
    shared=dict(params,update_token_hash=params['backup_token_hash']);fixture('configure',params=shared)
    check(request(credential=backup)[0]==503,'shared backup/update credential disabled')
    fixture('configure',params=params)
    prepare('update-expired',v2,'1.0.0','1.1.0',expires_at=int(time.time())-1)
    check(request('update-expired',True)[0]==409,'expired local permit denied')
    prepare('update-no-recovery',v2,'1.0.0','1.1.0',recovery_verified=False)
    check(request('update-no-recovery',True)[0]==409,'local recovery attestation required')
    prepare('update-writers',v2,'1.0.0','1.1.0',writers_stopped=False)
    check(request('update-writers',True)[0]==409,'stopped writers attestation required')
    check(request(execute=True,changes={'site_id':str(uuid.uuid4())})[0]==400,'wrong site identity denied')
    evil=store/'traversal.zip';evil.write_bytes(v2.read_bytes())
    with zipfile.ZipFile(evil,'a') as z:z.writestr('../escaped.txt','must not be extracted')
    prepare('update-traversal',evil,'1.0.0','1.1.0')
    check(request('update-traversal',True)[0]==409,'archive traversal denied before extraction')
    wrong=prepare('update-wrong-identity',v2,'1.0.0','1.1.0',identity={'type':'plugin','element':'otherplugin','folder':'system','client_id':0})
    check(request('update-wrong-identity',True)[0]==409,'different package identity denied')
    bad=prepare('update-tampered',v2,'1.0.0','1.1.0');(bad/'package.zip').write_bytes(b'changed')
    check(request('update-tampered',True)[0]==409,'tampered package denied')
    prepare('update-wrong-version',v2,'1.0.0','1.9.0')
    check(request('update-wrong-version',True)[0]==409,'manifest version mismatch denied')
    prepare('update-stale',v2,'0.9.0','1.1.0')
    check(request('update-stale',True)[0]==409,'stale installed baseline denied')
    check(not list((store/'journal').glob('*/started.json')) and not (store/'maintenance/owner.json').exists()
        and fixture('inspect')['version']=='1.0.0','rejected updates have no native effects or shared reservation')
    replacement='(new UpdateClient('+json.dumps(str(a.certificate.resolve()).replace('\\','/'))+", ['127.0.0.1']))"
    check(original.count(b'(new UpdateClient())')==1,'single local transport override')
    path.write_bytes(original.replace(b'(new UpdateClient())',replacement.encode()))
    roledata=roles('setup',site_id=site_id,url=f'https://localhost:{secure.server_port}',backup_token=backup,update_token=token)
    call,csrf,html=session('reader');check('Prepared extension updates' not in html,'reader cannot see update forms')
    check(call({'task':'updateBegin','id':roledata['site'],'operation':'native-update-01','confirmed':'1',csrf:1})[0]==403,'reader update denied')
    call,csrf,html=session('backup_operator');check('Prepared extension updates' not in html,'backup operator has no update permission')
    check(call({'task':'updateExecute','operation':'native-update-01','confirmed':'1',csrf:1})[0]==403,'backup permission cannot execute updates')
    call,csrf,html=session('operator');check('Prepared extension updates' in html,'update operator has forms')
    check(call({'task':'updateEnroll','id':roledata['site'],'update_credential':token,csrf:1})[0]==403,'operator cannot enroll credentials')
    check(call({'task':'updateBegin','id':roledata['site'],'operation':'native-update-01','confirmed':'1'})[0]==403,'CSRF required')
    def panel(task,op='native-update-01',confirm='1'):
        return call({'task':task,'id':roledata['site'],'operation':op,'confirmed':confirm,csrf:1})
    prepared=subprocess.run([str(a.php),str(repo/'tools/prepare_plugin_update.php'),str(site),str(store),str(v2),
        'native-update-01','--writers-stopped-and-recovery-verified'],text=True,capture_output=True,timeout=30)
    check(prepared.returncode==0 and json.loads(prepared.stdout)['installed'] is False,'documented local preparation CLI leaves installation unchanged')
    check('state: ready' in panel('updateBegin')[1],'operator registers prepared update')
    before=post_effects;panel('updateExecute',confirm='0')
    check(post_effects==before and fixture('inspect')['version']=='1.0.0','panel confirmation denial has no remote effects')
    code,html=panel('updateExecute')
    (store/'panel-result.html').write_text(html,encoding='utf-8')
    check(code==200 and 'state: updated' in html,'native update through panel and verified TLS')
    current=fixture('inspect');check(current=={'id':remote['id'],'version':'1.1.0','enabled':0,'file':'1.1.0'},'same native extension ID and new files/version')
    before=post_effects;panel('updateExecute');check(post_effects==before,'completed panel operation cannot reinstall')
    check(request()[1]['status']=='updated','GET returns persisted receipt')
    check(request(execute=True)[1]['status']=='updated','duplicate remote update returns receipt')
    check(str(store).encode() not in request()[2] and token.encode() not in request()[2],'receipt has no private path or credential')
    prepare('native-update-02',v3,'1.1.0','1.2.0');panel('updateBegin','native-update-02')
    drop=True;code,html=panel('updateExecute','native-update-02')
    check('state: uncertain' in html and fixture('inspect')['version']=='1.2.0','lost response after native install leaves central uncertain')
    before=post_effects;panel('updateExecute','native-update-02');check(post_effects==before,'uncertain operation cannot retry')
    code,html=panel('updateStatus','native-update-02');check('state: uncertain' not in html and html.count('state: updated')==2,'status read reconciles lost response')
    fixture('configure',params=dict(params,update_token_hash=''))
    check(request()[0]==503,'credential revocation disables status')
    result={'passed':len(checks),'checks':checks,'native_installer':True,'real_joomla_users':True,'central_panel':True,
        'tls_verified':True,'lost_response_reconciled':True,'core_update':False}
    (store/'result.json').write_text(json.dumps(result,indent=2),encoding='utf-8');print(json.dumps(result))
finally:
    path.write_bytes(original)
    cleanup={'transport_restored':path.read_bytes()==original}
    try:
        if roledata:cleanup['roles']=roles('cleanup',**roledata)
    finally:
        try:
            if remote:cleanup['remote']=fixture('cleanup',old=remote['old'])
        finally:
            for s in [secure,plain]:s.shutdown();s.server_close()
            for t in threads:t.join(timeout=5)
            (store/'cleanup.json').write_text(json.dumps(cleanup),encoding='utf-8')
