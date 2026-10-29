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
import socket
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
parser.add_argument('--site-full', action='store_true', help='Full joint sequenced capture; disposable stopped-writer lab only')
parser.add_argument('--tree-smoke', action='store_true', help='Bounded native file-tree route only; does not capture a complete Joomla or database')
parser.add_argument('--database-guard-only', action='store_true')
parser.add_argument('--database-full', action='store_true', help='Capture entire disposable database in sequenced HTTPS requests')
parser.add_argument('--central-site', type=Path, help='Separate disposable Joomla central panel on loopback port 8767')
parser.add_argument('--central-steps', type=int, default=12)
parser.add_argument('--central-browser', action='store_true', help='Exercise explicit browser runner and pause with native connector')
parser.add_argument('--resume-panel', type=Path, help='Existing panel-progress.json; only before database capture has started')
args = parser.parse_args()
if args.central_browser and (not args.central_site or args.resume_panel):
    parser.error('--central-browser requires a separate central site and a fresh test capture')
# Fail before configuring a disposable site when a reused fixture has expired.
certificate = ssl._ssl._test_decode_cert(str(args.certificate.resolve()))
if not ssl.cert_time_to_seconds(certificate['notBefore']) <= time.time() < ssl.cert_time_to_seconds(certificate['notAfter']):
    parser.error('Local TLS certificate is not currently valid; replace the fixture, never disable verification')
site = args.site.resolve()
cgi = args.php.with_name('php-cgi.exe' if os.name == 'nt' else 'php-cgi')
flags = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0
checks = []
site_id = str(uuid.uuid4())
token = secrets.token_hex(32)
backup = secrets.token_hex(32)
storage = args.storage.resolve()
resume = None
if args.resume_panel:
    if not args.central_site or args.resume_panel.resolve().parent != storage:
        parser.error('Resume requires central site and the original capture storage')
    resume = json.loads(args.resume_panel.read_text())
    operation = resume['operation']
    if not __import__('re').fullmatch(r'backup-[a-f0-9]{32}', operation):
        parser.error('Invalid resume identity')
    state = json.loads((storage/'sites'/operation/'state.json').read_text())
    receipt = json.loads((storage/'site-operations'/operation/'state.json').read_text())
    if state['phase'] != 'files' or receipt['status'] != 'progress' or (storage/'site-databases'/operation).exists():
        parser.error('Resume enrollment only supported before database capture; do not change captured database')
    site_id = receipt['site_id']
    resume['receipt'] = receipt
for name in ['operations','snapshots','tree-operations','trees','database-operations','databases','site-operations','sites','site-trees','site-databases','site-verify']: (storage/name).mkdir(exist_ok=bool(resume))
(storage/'maintenance').mkdir(exist_ok=bool(resume))

def check(ok, name):
    if not ok:
        raise RuntimeError(name)
    checks.append(name)

def configure(credential, enabled=1, identity=None, backup_credential=backup):
    # Nonsecret digest goes through stdin, never shell interpolation.
    params = json.dumps({'backup_storage':str(storage), 'maintenance_storage':str(storage/'maintenance'), 'backup_token_hash':hashlib.sha256(backup_credential.encode()).hexdigest() if backup_credential else '', 'site_id': identity or site_id, 'token_hash': hashlib.sha256(credential.encode()).hexdigest() if credential else ''})
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
        p = subprocess.run([str(cgi), '-d', 'max_execution_time=5' if (args.tree_smoke or args.database_full or args.site_full) else 'max_execution_time=600'], env=env, cwd=site, input=payload, capture_output=True, timeout=8 if (args.tree_smoke or args.database_full or args.site_full) else 660, creationflags=flags)
        headers, sep, body = p.stdout.partition(b'\r\n\r\n')
        if not sep:
            self.send_error(502)
            return
        parsed = [line.decode('latin1').split(':',1) for line in headers.split(b'\r\n') if b':' in line]
        status = next((int(v.strip().split()[0]) for k,v in parsed if k.lower()=='status'),200)
        if args.central_site and self.command == 'POST' and payload and json.loads(payload).get('sequence') == 1 and not getattr(self.server, 'response_dropped', False):
            self.server.response_dropped = True
            self.close_connection = True
            return
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
# The fixture listens only on IPv4. Avoid Windows IPv6 localhost fallback delays
# while preserving TLS hostname validation for localhost.
resolve = socket.getaddrinfo
def fixture_resolve(host, port, family=0, type=0, proto=0, flags=0):
    return resolve(host, port, socket.AF_INET if host == 'localhost' else family, type, proto, flags)
socket.getaddrinfo = fixture_resolve
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
    if args.central_site:
        subprocess.run([str(args.php), str(Path(__file__).with_name('backup_database_baseline.php')),
                        str(site), str(storage/'database-baseline.json'), '--allow-disposable-writes'],
                       check=True, capture_output=True, timeout=30, creationflags=flags)
        if args.central_browser:
            from backup_runner_native import run_panel
        else:
            from backup_panel_native import run_panel
        print(json.dumps(run_panel(globals()), indent=2))
        sys.exit(0)
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
    if args.site_full:
        operation = 'native-joint'
        def joint(method='GET', seq=0, credential=backup, tls=True):
            payload = {'site_id':site_id,'operation':operation,'action':'step','sequence':seq,'confirmed':True}
            return request(credential, task='backup_site', method=method, tls=tls,
                suffix='&site_id='+site_id+'&operation='+operation+'&action=status' if method=='GET' else '',
                data=json.dumps(payload).encode() if method=='POST' else None,
                extra={'Content-Type':'application/json'})
        check(joint()[0]==404, 'joint_unknown')
        check(joint(credential=token)[0]==401, 'joint_inventory_denied')
        check(joint(tls=False)[0]==403, 'joint_tls_required')
        # This fixture exclusively owns the offline lab, with no other server/cron.
        # Renew locally under that same continuously stopped-writer condition;
        # production clients must never infer or renew this permission remotely.
        def arm():
            (storage/'armed.json').write_text(json.dumps({'operation':operation,'writers_stopped':True,'expires_at':int(time.time())+900}))
        arm(); renewed=time.monotonic(); renewals=0; durations=[]; phases={}; started=time.monotonic()
        for seq in range(20000):
            if time.monotonic()-renewed > 600:
                arm(); renewed=time.monotonic(); renewals+=1
            begin=time.monotonic(); result=joint('POST',seq); durations.append(time.monotonic()-begin)
            check(result[0]==200 and result[2]['next_sequence']==seq+1, 'joint_step_'+str(seq))
            phase=result[2]['phase']; phases[phase]=phases.get(phase,0)+1
            if seq==0 or phases[phase]==1:
                check(joint('POST',seq)[2]==result[2], 'joint_duplicate_'+phase)
                check(joint()[2]==result[2], 'joint_get_'+phase)
                check(joint('POST',seq+2)[0]==409 or phase=='complete', 'joint_sequence_'+phase)
            if seq % 100 == 0:
                (storage.parent/'progress.json').write_text(json.dumps({'sequence':seq,'phase':phase,'seconds':time.monotonic()-started}))
            if result[2]['status']=='complete': break
        check(result[2]['status']=='complete' and result[2]['scope']=='files_and_database', 'joint_complete')
        check(not result[2]['restoration_verified'], 'joint_no_false_restore_claim')
        check(str(storage).encode() not in result[3] and backup.encode() not in result[3], 'joint_private_response')
        check(joint('POST',seq+1)[2]==result[2], 'joint_completion_stable')
        # Keep completed capture evidence even if the independent restore test is interrupted.
        capture_result = {'passed':len(checks),'requests':len(durations),'phases':phases,
            'max_request_seconds':max(durations),'elapsed_seconds':time.monotonic()-started,
            'local_permit_renewals':renewals,'tls_certificate_verified':True,
            'restoration_verified':False,'application_verified':False}
        (storage/'capture-verification.json').write_text(json.dumps(capture_result,indent=2))
        verify=subprocess.run([str(args.php),str(Path(__file__).with_name('site_http_restore.php')),str(site),str(storage),'--allow-disposable-writes'],input=sys.stdin.read(),text=True,capture_output=True,creationflags=flags)
        if verify.returncode: raise RuntimeError('Joint restore comparison failed: '+verify.stderr)
        restored=json.loads(verify.stdout)
        (storage/'armed.json').unlink()
        configure(token,backup_credential='')
        check(joint()[0]==503, 'joint_revoked')
        check(max(durations)<5, 'joint_requests_under_five_seconds')
        print(json.dumps({'passed':len(checks),'requests':len(durations),'phases':phases,'max_request_seconds':max(durations),'elapsed_seconds':time.monotonic()-started,'local_permit_renewals':renewals,'tls_certificate_verified':True,'restore':restored,'application_verified':False},indent=2))
        sys.exit(0)
    if args.database_full or args.database_guard_only:
        def database(method='GET', seq=0, credential=backup, tls=True, operation='native-database'):
            payload = {'site_id':site_id,'operation':operation,'action':'step','sequence':seq,'confirmed':True}
            return request(credential, task='backup_database', method=method, tls=tls,
                suffix='&site_id='+site_id+'&operation='+operation+'&action=status' if method=='GET' else '',
                data=json.dumps(payload).encode() if method=='POST' else None,
                extra={'Content-Type':'application/json'})
        check(database()[0]==404, 'database_unknown')
        check(database(credential=token)[0]==401, 'database_inventory_denied')
        check(database(tls=False)[0]==403, 'database_tls_required')
        (storage/'armed.json').write_text(json.dumps({'operation':'native-database','writers_stopped':True,'expires_at':int(time.time())+900}))
        if args.database_guard_only:
            refused=database('POST')
            check(refused[0]==202 and refused[2]['status']=='uncertain', 'database_session_writer_refused')
            check(not (storage/'databases/native-database').exists(), 'database_session_writer_no_capture')
            check(database('POST')[2]==refused[2], 'database_session_writer_not_replayed')
            (storage/'armed.json').unlink()
            print(json.dumps({'passed':len(checks),'checks':checks,'database_writer_guard':True},indent=2))
            sys.exit(0)
        durations=[]
        for seq in range(3000):
            start=time.monotonic(); result=database('POST',seq); durations.append(time.monotonic()-start)
            check(result[0]==200 and result[2]['next_sequence']==seq+1, 'database_step_'+str(seq))
            check(str(storage).encode() not in result[3] and backup.encode() not in result[3], 'database_redacted_'+str(seq))
            if seq==0:
                check(database('POST',seq)[2]==result[2], 'database_lost_response_no_advance')
                check(database()[2]==result[2], 'database_get_reconciles')
                check(database('POST',seq+2)[0]==409, 'database_cannot_skip')
            if result[2]['status']=='complete': break
        check(result[2]['status']=='complete' and result[2]['scope']=='database_only', 'database_completed')
        check(database()[2]==result[2] and database('POST',seq+1)[2]==result[2], 'database_completion_stable')
        check(not result[2]['restoration_verified'], 'database_no_false_restore_claim')
        check('no-store' in result[1]['Cache-Control'], 'database_private_response')
        verify = subprocess.run([str(args.php), str(Path(__file__).with_name('database_http_restore.php')), str(site), str(storage/'databases'), '--allow-disposable-writes'], input=sys.stdin.read(), text=True, capture_output=True, creationflags=flags)
        if verify.returncode: raise RuntimeError('Database restore comparison failed: '+verify.stderr)
        restored=json.loads(verify.stdout)
        check(restored['tables']>50, 'https_capture_restored_and_compared')
        (storage/'armed.json').unlink()
        check(database('POST',operation='unarmed-database')[0]==202, 'database_unarmed_refused')
        check(not (storage/'databases/unarmed-database').exists(), 'database_unarmed_no_capture')
        configure(token, backup_credential='')
        check(database()[0]==503, 'database_revoked')
        check(max(durations)<5, 'database_requests_under_five_seconds')
        print(json.dumps({'passed':len(checks),'checks':checks,'requests':len(durations),'max_request_seconds':max(durations),'tls_certificate_verified':True,'restore':restored,'full_backup_verified':False},indent=2))
        sys.exit(0)
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
