"""Actual loopback TLS/CGI requests against extracted package and a small file tree.
No Joomla database is used here; native Joomla route acceptance is a separate check.
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
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid

p = argparse.ArgumentParser(description=__doc__)
for name in ['php', 'package', 'root', 'certificate', 'private-key']:
    p.add_argument('--' + name, type=Path, required=True)
a = p.parse_args()
root = a.root.resolve(); package = a.package.resolve()
for name in ['source', 'source/empty', 'store', 'store/trees', 'store/tree-operations']:
    (root / name).mkdir()
(root / 'source/data.bin').write_bytes(b'A' * 1048576 + b'\x00tail')
(root / 'armed').touch()
token = secrets.token_hex(32); inventory = secrets.token_hex(32); site = str(uuid.uuid4())
digest = hashlib.sha256(token.encode()).hexdigest()
checks = []; times = []; flags = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0

def check(ok, name):
    if not ok:
        raise RuntimeError(name)
    checks.append(name)

class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *unused):
        pass

    def do_GET(self):
        env = os.environ.copy()
        env.update({'REDIRECT_STATUS': '200', 'GATEWAY_INTERFACE': 'CGI/1.1',
                    'SCRIPT_FILENAME': str(Path(__file__).with_name('tree_endpoint.php').resolve()),
                    'SCRIPT_NAME': '/index.php', 'REQUEST_METHOD': self.command,
                    'QUERY_STRING': urllib.parse.urlsplit(self.path).query,
                    'SERVER_PROTOCOL': 'HTTP/1.1', 'SERVER_NAME': 'localhost',
                    'SERVER_PORT': str(self.server.server_port), 'REMOTE_ADDR': '127.0.0.1',
                    'HTTPS': 'on' if isinstance(self.connection, ssl.SSLSocket) else 'off',
                    'HTTP_AUTHORIZATION': self.headers.get('Authorization', ''),
                    'CONTENT_TYPE': self.headers.get('Content-Type', ''),
                    'CONTENT_LENGTH': self.headers.get('Content-Length', '0'),
                    'NWM_TEST_ROOT': str(root), 'NWM_TEST_PACKAGE': str(package),
                    'NWM_TEST_DIGEST': digest, 'NWM_TEST_SITE': site,
                    'NWM_TEST_INVENTORY': hashlib.sha256(inventory.encode()).hexdigest()})
        body = self.rfile.read(int(env['CONTENT_LENGTH']))
        result = subprocess.run([str(a.php.with_name('php-cgi.exe' if os.name == 'nt' else 'php-cgi')),
                                 '-d', 'max_execution_time=5'], input=body, capture_output=True,
                                env=env, timeout=8, creationflags=flags)
        headers, sep, raw = result.stdout.partition(b'\r\n\r\n')
        if not sep:
            self.send_error(502); return
        parsed = [x.decode('latin1').split(':', 1) for x in headers.split(b'\r\n') if b':' in x]
        self.send_response(next((int(v.strip().split()[0]) for k, v in parsed if k.lower() == 'status'), 200))
        self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(raw)))
        self.end_headers(); self.wfile.write(raw)

    do_POST = do_GET

secure = http.server.HTTPServer(('127.0.0.1', 0), Handler)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(a.certificate, a.private_key)
secure.socket = ctx.wrap_socket(secure.socket, server_side=True)
plain = http.server.HTTPServer(('127.0.0.1', 0), Handler)
threads = [threading.Thread(target=s.serve_forever, daemon=True) for s in [secure, plain]]
for t in threads: t.start()
client = ssl.create_default_context(cafile=str(a.certificate))

def request(method='GET', op='http-tree', seq=0, credential=None, tls=True, extra=None, raw=None):
    body = {'site_id': site, 'operation': op, 'action': 'step' if method == 'POST' else 'status'}
    if method == 'POST': body.update(confirmed=True, sequence=seq)
    body.update(extra or {})
    url = f'{"https" if tls else "http"}://localhost:{(secure if tls else plain).server_port}/index.php'
    payload = None
    if method == 'POST': payload = raw if raw is not None else json.dumps(body).encode()
    else: url += '?' + urllib.parse.urlencode(body)
    req = urllib.request.Request(url, method=method, data=payload,
        headers={'Authorization': 'Bearer ' + (token if credential is None else credential), 'Content-Type': 'application/json'})
    start = time.monotonic()
    try: response = urllib.request.urlopen(req, context=client, timeout=10)
    except urllib.error.HTTPError as e: response = e
    with response:
        raw = response.read(); status = response.status; result = json.loads(raw)
    times.append(time.monotonic() - start)
    check(str(root).encode() not in raw and token.encode() not in raw, 'response_redacted')
    return status, result

try:
    check(request()[0] == 404, 'unknown_status')
    check(request(credential=inventory)[0] == 401, 'inventory_denied')
    check(request(tls=False)[0] == 403, 'plaintext_denied')
    check(request('POST', extra={'path': '/arbitrary'})[0] == 400, 'paths_denied')
    check(request('POST', raw=b'x' * 4097)[0] == 413, 'body_limit')
    check(request('POST', seq=3)[0] == 409, 'cannot_skip_first_step')
    first = request('POST'); check(first[0] == 200, 'first_step')
    check(request('POST') == first, 'lost_response_duplicate_no_advance')
    check(request() == first, 'get_reconciles_without_advance')
    check(request('POST', seq=8)[0] == 409, 'future_sequence_denied')
    current = first
    for _ in range(100):
        if current[1]['status'] == 'complete': break
        current = request('POST', seq=current[1]['next_sequence'])
        check(current[0] == 200, 'bounded_step')
    check(current[1]['status'] == 'complete' and current[1]['scope'] == 'files_only', 'file_capture_completed')
    check(request() == current and request('POST', seq=current[1]['next_sequence']) == current, 'completion_stable')
    check(current[1]['restoration_verified'] is False, 'no_restore_claim')
    restore_code = r"require $argv[1]; (new Nicode\Maintenance\TreeRestore)->restore($argv[2], $argv[3], true);"
    restored = root / 'restored'; restored.mkdir()
    restored_run = subprocess.run([str(a.php), '-r', restore_code,
        str(Path(__file__).resolve().parents[1] / 'tools/TreeRestore.php'),
        str(root / 'store/trees/http-tree'), str(restored)], capture_output=True, creationflags=flags)
    check(restored_run.returncode == 0 and (restored / 'data.bin').read_bytes() == (root / 'source/data.bin').read_bytes()
        and (restored / 'empty').is_dir(), 'http_capture_restored_exactly')
    # Failure AFTER the real provider checkpoint must not run the next step on retry.
    failed = request('POST', op='crash-after-step')
    path = root / 'store/trees/crash-after-step/state.json'
    before = path.read_bytes()
    check(failed[0] == 202 and failed[1]['status'] == 'uncertain', 'crash_uncertain')
    check(request('POST', op='crash-after-step') == failed and path.read_bytes() == before, 'uncertain_not_replayed')
    check(request(op='crash-after-step') == failed, 'uncertain_status')
    (root / 'armed').unlink()
    check(request('POST', op='not-armed')[0] == 202 and not (root / 'store/trees/not-armed').exists(), 'unarmed_no_capture')
    digest = ''
    check(request()[0] == 503, 'revocation_blocks_status')
    check(max(times) < 5, 'each_tls_cgi_request_under_five_seconds')
    print(json.dumps({'passed': len(checks), 'checks': checks, 'requests': len(times),
        'max_request_seconds': max(times), 'tls_certificate_verified': True}, indent=2))
finally:
    for s in [secure, plain]: s.shutdown(); s.server_close()
    for t in threads: t.join(timeout=5)
