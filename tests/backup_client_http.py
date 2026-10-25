"""Real TLS + packaged backup adapter/journal; deterministic capture provider, no Joomla database.

All credentials are ephemeral. No outside network, retries or production writes.
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
import tempfile
import threading
import time
import urllib.parse
import uuid
import zipfile

p = argparse.ArgumentParser(description=__doc__)
for option in ['php', 'certificate', 'private-key', 'package']:
    p.add_argument('--' + option, type=Path, required=True)
p.add_argument('--joomla', type=Path, help='Disposable installed component; enables central DB + TLS test')
p.add_argument('--allow-disposable-writes', action='store_true')
a = p.parse_args()
if a.joomla and not a.allow_disposable_writes:
    p.error('--joomla requires --allow-disposable-writes')
flags = subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0
identity, token = str(uuid.uuid4()), secrets.token_hex(32)
mode, calls, traps = 'normal', 0, 0
checks = []
here = Path(__file__).resolve().parent

def php(script, data):
    command = [str(a.php), str(here / script)]
    if script == 'backup_central_http.php': command.append('--allow-disposable-writes')
    r = subprocess.run(command, input=json.dumps(data),
                       capture_output=True, text=True, timeout=20, creationflags=flags)
    assert r.returncode == 0 and token not in r.stdout + r.stderr, 'PHP failed or disclosed credential'
    return json.loads(r.stdout)

with tempfile.TemporaryDirectory(prefix='nwm-backup-client-') as temp:
    root = Path(temp)
    with zipfile.ZipFile(a.package) as z:
        z.extractall(root / 'package')
    for name in ['web', 'store', 'store/site-operations']:
        (root / name).mkdir()

    class Handler(http.server.BaseHTTPRequestHandler):
        def handle(self):
            try: super().handle()
            except (ConnectionError, ssl.SSLError): pass

        def log_message(self, *unused):
            pass

        def do_GET(self):
            global calls, traps
            calls += 1
            if self.path.startswith('/trap'):
                traps += 1
            query = dict(urllib.parse.parse_qsl(urllib.parse.urlsplit(self.path).query))
            assert query.get('task') == 'backup_site'
            body = self.rfile.read(int(self.headers.get('Content-Length', '0'))).decode()
            selected = mode
            if selected == 'central' and body and json.loads(body).get('sequence') == 1:
                selected = 'lost'
            code, result = php('backup_client_endpoint.php', {
                'package': str(root / 'package'), 'site_id': identity,
                'digest': hashlib.sha256(token.encode()).hexdigest(),
                'storage': str(root / 'store'), 'web': str(root / 'web'),
                'server': {'HTTPS': 'on', 'REQUEST_METHOD': self.command,
                           'CONTENT_TYPE': self.headers.get('Content-Type', ''),
                           'HTTP_AUTHORIZATION': self.headers.get('Authorization', '')},
                'query': query, 'body': body})
            if selected == 'lost':
                self.close_connection = True
                return
            if selected == 'slow':
                time.sleep(.5)
            if selected == 'wrong_identity': result['site_id'] = str(uuid.uuid4())
            if selected == 'wrong_operation': result['operation'] = 'different-operation'
            if selected == 'wrong_scope': result['scope'] = 'files_only'
            if selected == 'wrong_sequence': result['next_sequence'] = -1
            if selected == 'invalid_hash': result['manifest_sha256'] = 'invalid'
            if selected == 'false_restoration': result['restoration_verified'] = True
            if selected == 'extra': result['private_path'] = '/must/not/escape'
            if selected == 'uncertain':
                code, result = 202, {k: result[k] for k in ['site_id', 'operation', 'scope']}
                result['status'] = 'uncertain'
            if selected == 'redirect': code = 302
            raw = json.dumps(result).encode()
            if selected == 'bad_json': raw = b'{'
            if selected == 'oversize': raw = b' ' * 9000
            self.send_response(code)
            self.send_header('Content-Type', 'text/html' if selected == 'html' else 'application/json')
            self.send_header('Content-Length', str(len(raw)))
            if selected == 'large_headers': self.send_header('X-Padding', 'x' * 40000)
            if selected == 'redirect': self.send_header('Location', '/trap')
            self.end_headers()
            try: self.wfile.write(raw)
            except (ConnectionError, ssl.SSLError): pass

        do_POST = do_GET

    server = http.server.HTTPServer(('127.0.0.1', 0), Handler)
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.load_cert_chain(a.certificate, a.private_key)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    worker = threading.Thread(target=server.serve_forever, daemon=True)
    worker.start()
    defaults = {'url': f'https://localhost:{server.server_port}', 'address': '127.0.0.1',
                'site_id': identity, 'token': token, 'ca': str(a.certificate),
                'operation': 'central-test'}

    def run(name, error=None, **overrides):
        result = php('backup_client.php', defaults | overrides)
        assert result['ok'] == (error is None) and result.get('error') == error, (name, result)
        checks.append(name)
        return result

    try:
        for overrides in [{'confirmed': False, 'sequence': 0}, {'sequence': -1, 'confirmed': True},
                          {'operation': '../bad'}, {'sequence': 1000001, 'confirmed': True}]:
            assert not run('reject_invalid_request', 'invalid_operation', **overrides)['reconcile_required']
        for overrides in [{'url': 'http://localhost'}, {'allow_private': []}, {'token': 'invalid'},
                          {'url': 'https://localhost/?x=y'}, {'site_id': 'invalid'}]:
            run('reject_invalid_enrollment', 'invalid_enrollment', **overrides)
        assert calls == 0
        run('unknown_operation_is_readonly', 'operation_unknown')
        assert not (root / 'store/provider-count').exists()
        receipt = run('first_step', sequence=0, confirmed=True)['receipt']
        assert receipt['next_sequence'] == 1 and receipt['status'] == 'progress'
        run('duplicate_sequence_has_no_effect', sequence=0, confirmed=True)
        assert (root / 'store/provider-count').read_text() == '1'
        run('sequence_gap_rejected', 'operation_conflict', sequence=3, confirmed=True)
        mode = 'lost'
        result = run('lost_response_requires_reconciliation', 'connection_failed', sequence=1, confirmed=True)
        assert result['reconcile_required'] and (root / 'store/provider-count').read_text() == '2'
        mode = 'normal'
        result = run('status_reconciles_completed_write')
        assert result['receipt']['status'] == 'complete' and result['receipt']['restoration_verified'] is False
        assert (root / 'store/provider-count').read_text() == '2'
        run('wrong_credential', 'access_denied', token=secrets.token_hex(32))
        for mode, error in [('wrong_identity','invalid_receipt'), ('wrong_operation','invalid_receipt'),
                            ('wrong_scope','invalid_receipt'), ('wrong_sequence','invalid_receipt'),
                            ('invalid_hash','invalid_receipt'), ('false_restoration','invalid_receipt'),
                            ('bad_json','invalid_json'), ('html','invalid_content_type'),
                            ('oversize','response_too_large'), ('large_headers','response_too_large'),
                            ('redirect','redirect_rejected')]:
            run(mode, error)
        assert traps == 0
        mode = 'uncertain'
        assert run('remote_uncertainty_preserved')['reconcile_required']
        mode = 'extra'
        assert 'private_path' not in run('only_whitelisted_receipt_fields')['receipt']
        mode = 'slow'
        run('bounded_timeout', 'timeout', timeout=100)
        time.sleep(.5)
        mode = 'normal'
        run('untrusted_certificate', 'tls_verification_failed', ca=None)
        assert (root / 'store/provider-count').read_text() == '2'
        checks.append('all_reads_and_failures_leave_provider_count_unchanged')
        if a.joomla:
            (root / 'store/provider-count').unlink()
            mode = 'central'
            integrated = php('backup_central_http.php', defaults | {'joomla': str(a.joomla)})
            assert integrated['passed'] == 10 and (root / 'store/provider-count').read_text() == '2'
            checks.extend(integrated['checks'])
        print(json.dumps({'passed': len(checks), 'checks': checks, 'http_requests': calls,
                          'provider_effects_per_scenario': 2, 'real_central_joomla_tested': bool(a.joomla),
                          'native_capture_provider': False, 'admin_browser_tested': False}, indent=2))
    finally:
        server.shutdown()
        server.server_close()
        worker.join(timeout=2)
