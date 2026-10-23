"""Backup HTTPS/CGI integration against a DISPOSABLE Joomla with the connector installed.

Requires --allow-disposable-writes: overwrites the connector configuration, captures files/database, then
revokes its test credentials and disables it. Never run against a real site.
The TLS fixture is loopback-only; request credentials are generated in memory.
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
import urllib.error
import urllib.request
import uuid
import sys
import time

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--site', type=Path, required=True)
parser.add_argument('--php', type=Path, required=True)
parser.add_argument('--certificate', type=Path, required=True)
parser.add_argument('--private-key', type=Path, required=True)
parser.add_argument('--allow-disposable-writes', action='store_true', required=True)
parser.add_argument('--storage', type=Path, required=True)
parser.add_argument('--tree-smoke', action='store_true', help='Bounded native file-tree route only; does not capture a complete Joomla or database')
args = parser.parse_args()
site = args.site.resolve()
cgi = args.php.with_name('php-cgi.exe' if os.name == 'nt' else 'php-cgi')
flags = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0
checks = []
site_id = str(uuid.uuid4())
token = secrets.token_hex(32)
backup = secrets.token_hex(32)
storage = args.storage.resolve()
for name in ['operations','snapshots','tree-operations','trees']: (storage/name).mkdir()

def check(ok, name):
    if not ok:
        raise RuntimeError(name)
    checks.append(name)

def configure(credential, enabled=1, identity=None, backup_credential=backup):
    # Nonsecret digest goes through stdin, never shell interpolation.
    params = json.dumps({'backup_storage':str(storage), 'backup_token_hash':hashlib.sha256(backup_credential.encode()).hexdigest() if backup_credential else '', 'site_id': identity or site_id, 'token_hash': hashlib.sha256(credential.encode()).hexdigest() if credential else ''})
    code = r'''define('_JEXEC',1); define('JPATH_BASE',getcwd());
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$db=Joomla\CMS\Factory::getContainer()->get(Joomla\Database\DatabaseInterface::class);
$db->setQuery("UPDATE #__extensions SET enabled=".(int)$d['enabled'].", params=".$db->quote($d['params'])." WHERE type='plugin' AND folder='system' AND element='nicodewebmonitor'")->execute();'''
    p = subprocess.run([str(args.php), '-r', code], cwd=site, input=json.dumps({'enabled':enabled,'params':params}), text=True, capture_output=True, creationflags=flags)
    if p.returncode:
        raise RuntimeError('Disposable connector configuration failed')

class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *unused):
        pass

    def do_GET(self):
        path, _, query = self.path.partition('?')
        if path not in ['/index.php', '/administrator/index.php']:
            self.send_error(404)
            return
        env = os.environ.copy()
        env.update({'REDIRECT_STATUS':'200', 'GATEWAY_INTERFACE':'CGI/1.1',
                    'SCRIPT_FILENAME':str(site / path.lstrip('/')), 'SCRIPT_NAME':path,
                    'DOCUMENT_ROOT':str(site), 'QUERY_STRING':query, 'REQUEST_URI':self.path,
                    'REQUEST_METHOD':self.command, 'SERVER_PROTOCOL':'HTTP/1.1',
                    'SERVER_NAME':'localhost', 'SERVER_PORT':str(self.server.server_port),
                    'REMOTE_ADDR':'127.0.0.1', 'HTTP_HOST':self.headers['Host'],
                    'HTTPS':'on' if isinstance(self.connection, ssl.SSLSocket) else 'off'})
        for header in ['Authorization','X-Forwarded-Proto']:
            if header in self.headers:
                env['HTTP_'+header.upper().replace('-','_')] = self.headers[header]
        env['CONTENT_TYPE'] = self.headers.get('Content-Type','')
        env['CONTENT_LENGTH'] = self.headers.get('Content-Length','0')
        payload = self.rfile.read(int(env['CONTENT_LENGTH']))
        p = subprocess.run([str(cgi), '-d', 'max_execution_time=5' if args.tree_smoke else 'max_execution_time=600'], env=env, cwd=site, input=payload, capture_output=True, timeout=8 if args.tree_smoke else 660, creationflags=flags)
        headers, sep, body = p.stdout.partition(b'\r\n\r\n')
        if not sep:
            self.send_error(502)
            return
        parsed = [line.decode('latin1').split(':',1) for line in headers.split(b'\r\n') if b':' in line]
        status = next((int(v.strip().split()[0]) for k,v in parsed if k.lower()=='status'),200)
        self.send_response(status)
        for k,v in parsed:
            if k.lower() not in ['status','content-length','connection']:
                self.send_header(k,v.strip())
        self.send_header('Content-Length',str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    do_POST = do_GET

secure = http.server.HTTPServer(('127.0.0.1',0),Handler)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(args.certificate,args.private_key)
secure.socket = ctx.wrap_socket(secure.socket,server_side=True)
plain = http.server.HTTPServer(('127.0.0.1',0),Handler)
threads = [threading.Thread(target=s.serve_forever,daemon=True) for s in [secure,plain]]
for thread in threads:
    thread.start()
client = ssl.create_default_context(cafile=str(args.certificate))

def request(credential=None, task='inventory', method='GET', tls=True, extra=None, path='/index.php', suffix='', data=None):
    server = secure if tls else plain
    url = f'{"https" if tls else "http"}://localhost:{server.server_port}{path}?option=com_nicodewebmonitor&task={task}{suffix}'
    headers = extra or {}
    if credential is not None:
        headers['Authorization']='Bearer '+credential
    req = urllib.request.Request(url,headers=headers,method=method,data=data)
    try:
        response = urllib.request.urlopen(req,context=client,timeout=700)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        raw = response.read()
        try:
            body = json.loads(raw)
        except (ValueError,UnicodeError):
            body = None
        return response.status, response.headers, body, raw

def backup_request(operation='http-capture', method='GET', credential=backup, data=None, **kwargs):
    suffix = '&site_id='+site_id+'&operation='+operation+'&action=status' if method=='GET' else ''
    if method == 'POST' and data is None:
        data = json.dumps({'site_id':site_id,'operation':operation,'action':'capture','confirmed':True}).encode()
    return request(credential, task='backup', method=method, suffix=suffix, data=data,
                   extra={'Content-Type':'application/json'}, **kwargs)

try:
    configure(token)
    check(request(token)[0]==200, 'inventory_still_works')
    check(backup_request(credential=token)[0]==401, 'inventory_cannot_read_backup_status')
    check(backup_request(tls=False)[0]==403, 'backup_requires_tls')
    check(backup_request()[0]==404, 'unknown_status')
    check(backup_request(method='POST',data=b'{')[0]==400, 'malformed_json_rejected')
    check(backup_request(method='POST',data=b'x'*4097)[0]==413, 'oversize_rejected')
    configure(token, backup_credential=token)
    check(backup_request(credential=token)[0]==503, 'shared_credential_disabled')
    configure(token)
    result=backup_request(operation='unarmed-capture',method='POST')
    check(result[0]==202 and result[2]['status']=='uncertain', 'unarmed_capture_fails_closed')
    check(not (storage/'snapshots/unarmed-capture').exists(), 'unarmed_has_no_snapshot')
    check(backup_request(operation='unarmed-capture')[0]==202, 'unarmed_result_persisted')
    if args.tree_smoke:
        def tree(method='GET', seq=0, credential=backup, tls=True):
            payload = {'site_id':site_id,'operation':'native-tree','action':'step','sequence':seq,'confirmed':True}
            return request(credential, task='backup_tree', method=method, tls=tls,
                suffix='&site_id='+site_id+'&operation=native-tree&action=status' if method=='GET' else '',
                data=json.dumps(payload).encode() if method=='POST' else None,
                extra={'Content-Type':'application/json'})
        check(tree()[0]==404, 'native_tree_unknown')
        check(tree(credential=token)[0]==401, 'native_tree_inventory_denied')
        check(tree(tls=False)[0]==403, 'native_tree_tls_required')
        (storage/'armed.json').write_text(json.dumps({'operation':'native-tree','writers_stopped':True,'expires_at':int(time.time())+900}))
        durations=[]
        first=None
        for seq in range(4):
            start=time.monotonic(); result=tree('POST',seq); durations.append(time.monotonic()-start)
            check(result[0]==200 and result[2]['next_sequence']==seq+1, 'native_tree_step_'+str(seq))
            if seq==0: first=result[2]
        check(tree('POST',3)[2]==result[2], 'native_duplicate_no_advance')
        check(tree()[2]==result[2], 'native_get_reconciles')
        check(result[2]['scope']=='files_only' and result[2]['status']=='progress', 'native_not_complete_backup')
        check('no-store' in result[1]['Cache-Control'], 'native_private_response')
        (storage/'armed.json').unlink()
        check(tree('POST',4)[0]==202, 'native_expired_arm_stops_resume')
        check(tree()[2]['status']=='uncertain', 'native_failed_resume_persisted')
        configure(token, backup_credential='')
        check(tree()[0]==503, 'native_revoked')
        check(max(durations)<5, 'native_requests_under_five_seconds')
        print(json.dumps({'passed':len(checks),'checks':checks,'max_request_seconds':max(durations),'tls_certificate_verified':True,'full_backup_verified':False},indent=2))
        sys.exit(0)
    # The runner keeps the disposable site offline, with no web/cron writers.
    (storage/'armed.json').write_text(json.dumps({'operation':'http-capture','writers_stopped':True,'expires_at':int(__import__('time').time())+900}))
    result=backup_request(method='POST')
    check(result[0]==200 and result[2]['status']=='captured', 'real_joomla_https_capture')
    manifest=storage/'snapshots/http-capture/manifest.json'
    check(result[2]['manifest_sha256']==hashlib.sha256(manifest.read_bytes()).hexdigest(), 'receipt_matches_manifest')
    details=json.loads(manifest.read_text())
    check(details['database']['tables']>50 and details['files']['entries']>1000, 'whole_joomla_and_database_captured')
    check(backup_request(method='POST')[2]==result[2], 'duplicate_returns_same_receipt')
    check(backup_request()[2]==result[2], 'get_reconciles_capture')
    check(result[2]['restoration_verified'] is False, 'no_restore_claim')
    check('no-store' in result[1]['Cache-Control'], 'private_response')
    check(str(storage).encode() not in result[3] and backup.encode() not in result[3], 'no_path_or_credential_in_response')
    configure(token, backup_credential='')
    check(backup_request()[0]==503, 'revocation_blocks_status')
    print(json.dumps({'passed':len(checks),'checks':checks,'tls_certificate_verified':True},indent=2))
finally:
    configure('',enabled=0,backup_credential='')
    for server in [secure,plain]:
        server.shutdown(); server.server_close()
    for thread in threads: thread.join(timeout=5)
