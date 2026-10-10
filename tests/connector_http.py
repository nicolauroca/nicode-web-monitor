"""HTTPS/CGI integration against a DISPOSABLE Joomla with the connector installed.

Requires --allow-disposable-writes: overwrites the connector configuration, then
revokes its test credential and disables it. Never run against a real site.
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

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--site', type=Path, required=True)
parser.add_argument('--php', type=Path, required=True)
parser.add_argument('--certificate', type=Path, required=True)
parser.add_argument('--private-key', type=Path, required=True)
parser.add_argument('--allow-disposable-writes', action='store_true', required=True)
parser.add_argument('--client-checks', action='store_true', help='Also exercise the central PHP inventory client')
parser.add_argument('--registry-site', type=Path, help='Disposable principal Joomla with central component; also test registry and rendered inventory')
args = parser.parse_args()
site = args.site.resolve()
cgi = args.php.with_name('php-cgi.exe' if os.name == 'nt' else 'php-cgi')
flags = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0
checks = []
site_id = str(uuid.uuid4())
token = secrets.token_hex(32)

def check(ok, name):
    if not ok:
        raise RuntimeError(name)
    checks.append(name)

def configure(credential, enabled=1, identity=None):
    # Nonsecret digest goes through stdin, never shell interpolation.
    params = json.dumps({'site_id': identity or site_id, 'token_hash': hashlib.sha256(credential.encode()).hexdigest() if credential else ''})
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
        p = subprocess.run([str(cgi)], env=env, cwd=site, input=b'', capture_output=True, timeout=30, creationflags=flags)
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

def request(credential=None, task='inventory', method='GET', tls=True, extra=None, path='/index.php', suffix=''):
    server = secure if tls else plain
    url = f'{"https" if tls else "http"}://localhost:{server.server_port}{path}?option=com_nicodewebmonitor&task={task}{suffix}'
    headers = extra or {}
    if credential is not None:
        headers['Authorization']='Bearer '+credential
    req = urllib.request.Request(url,headers=headers,method=method)
    try:
        response = urllib.request.urlopen(req,context=client,timeout=35)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        raw = response.read()
        try:
            body = json.loads(raw)
        except (ValueError,UnicodeError):
            body = None
        return response.status, response.headers, body, raw

try:
    configure(token)
    status,headers,body,raw=request(token)
    check(status==200 and body['site_id']==site_id,'https_authenticated_identity')
    inventory=body['inventory']
    check(inventory['status']=='complete' and len(inventory['sections']['extensions']['data'])>0,'real_joomla_inventory')
    check(inventory['execution_context']=='remote_https' and inventory['sections']['runtime']['data']['sapi']=='cgi-fcgi','web_runtime_not_cli')
    check(inventory['capabilities']=={'remote_inventory':True,'remote_updates':False,'remote_backups':False},'read_only_capabilities')
    check('no-store' in headers['Cache-Control'] and 'application/json' in headers['Content-Type'],'no_cache_json_headers')
    check(token.encode() not in raw and b'token_hash' not in raw and b'password' not in raw,'no_credentials_in_inventory')
    if args.client_checks or args.registry_site:
        def central(credential, identity=site_id):
            payload = {'url':f'https://localhost:{secure.server_port}', 'address':'127.0.0.1',
                       'site_id':identity, 'token':credential, 'ca':str(args.certificate),
                       'allow_private':['127.0.0.1']}
            command=[str(args.php), str(Path(__file__).with_name('client_fetch.php'))]
            if args.registry_site:
                command=[str(args.php), str(Path(__file__).with_name('registry_fetch.php')), str(args.registry_site), '--allow-disposable-writes']
            p = subprocess.run(command,
                               input=json.dumps(payload), text=True, capture_output=True,
                               timeout=25, creationflags=flags)
            check(p.returncode == 0, 'central_client_execution')
            return json.loads(p.stdout)
        observed = central(token)
        check(observed['ok'] and observed['joomla'] == inventory['sections']['joomla']['data']['version']
              and observed['extensions'] == len(inventory['sections']['extensions']['data']), 'central_real_inventory')
        if args.registry_site:
            check(observed['rendered'] and observed['details_rendered'], 'registry_real_inventory_details_rendered')
        check(central(token, str(uuid.uuid4()))['error']=='identity_mismatch', 'central_wrong_identity')
        check(central(secrets.token_hex(32))['error']=='access_denied', 'central_wrong_credential')
    check(request()[0]==401,'anonymous_rejected')
    check(request(secrets.token_hex(32))[0]==401,'wrong_token_rejected')
    check(request(suffix='&token='+token)[0]==401,'query_token_ignored')
    check(request(token,method='POST')[0]==405,'post_rejected')
    check(request(token,task='update')[0]==404,'update_unavailable')
    check(request(token,task='backup')[0]==404,'backup_unavailable')
    check(request(token,path='/administrator/index.php')[2] is None,'administrator_not_connector')
    check(request(token,tls=False)[0]==403,'plain_http_rejected')
    check(request(token,tls=False,extra={'X-Forwarded-Proto':'https'})[0]==403,'spoofed_proxy_rejected')
    configure('')
    check(request(token)[0]==503,'revocation_immediate')
    if args.client_checks or args.registry_site:
        check(central(token)['error']=='connector_unavailable', 'central_revocation')
    replacement=secrets.token_hex(32)
    configure(replacement)
    check(request(token)[0]==401,'rotated_old_token_rejected')
    new=request(replacement)
    check(new[0]==200 and new[2]['site_id']==site_id,'rotated_new_token_same_identity')
    configure(replacement,identity='invalid')
    check(request(replacement)[0]==503,'invalid_identity_rejected')
    configure(replacement,enabled=0)
    check(request(replacement)[2] is None,'disabled_plugin_no_inventory')
    print(json.dumps({'passed':len(checks),'checks':checks,'joomla':inventory['sections']['joomla']['data']['version'], 'tls_certificate_verified':True},indent=2))
finally:
    configure('',enabled=0)
    for server in [secure,plain]:
        server.shutdown()
        server.server_close()
    for thread in threads:
        thread.join(timeout=5)
