"""Bounded HTTPS client tests; loopback only, ephemeral credentials, no Joomla writes."""
import argparse
import copy
import http.server
import json
import os
from pathlib import Path
import secrets
import ssl
import subprocess
import threading
import time
import uuid

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--php', type=Path, required=True)
parser.add_argument('--certificate', type=Path, required=True)
parser.add_argument('--private-key', type=Path, required=True)
args = parser.parse_args()
identity, token = str(uuid.uuid4()), secrets.token_hex(32)
inventory = {'schema_version':1, 'collected_at':'2026-10-06T14:00:00Z', 'status':'complete',
             'execution_context':'remote_https',
             'capabilities':{'remote_inventory':True,'remote_updates':False,'remote_backups':False},
             'sections':{key:{'status':'available','data':{'version':'6.1.4'} if key=='joomla' else []}
                         for key in ['joomla','runtime','database','extensions','hosting']}}
inventory['sections']['hosting']={'status':'unavailable','data':None}
valid = {'protocol_version':1,'site_id':identity,'inventory':inventory}
mode, calls, trap_calls = 'valid', 0, 0

class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self, *unused):
        pass

    def do_GET(self):
        global calls, trap_calls
        calls += 1
        if self.path.startswith('/trap'):
            trap_calls += 1
        selected = mode
        body = copy.deepcopy(valid)
        code, kind = 200, 'application/json; charset=utf-8'
        if selected == 'partial':
            body['inventory']['status']='partial'
            body['inventory']['sections']['database']={'status':'error','data':None}
        elif selected == 'wrong_identity': body['site_id']=str(uuid.uuid4())
        elif selected == 'wrong_protocol': body['protocol_version']=2
        elif selected == 'wrong_schema': body['inventory']['schema_version']=2
        elif selected == 'bad_date': body['inventory']['collected_at']='2026-02-31T00:00:00Z'
        elif selected == 'missing_section': del body['inventory']['sections']['extensions']
        elif selected == 'bad_capability': body['inventory']['capabilities']['remote_inventory']='true'
        elif selected == 'hidden_partial': body['inventory']['sections']['database']={'status':'error','data':None}
        elif selected == 'denied': code=401
        elif selected == 'unavailable': code=503
        elif selected == 'http_error': code=500
        elif selected == 'redirect': code=302
        elif selected == 'html': kind='text/html'
        elif selected == 'slow': time.sleep(0.5)
        raw = json.dumps(body).encode()
        if selected == 'malformed': raw=b'{'
        if selected == 'oversize': raw=b' ' * 8192
        self.send_response(code)
        self.send_header('Content-Type',kind)
        if selected == 'large_headers': self.send_header('X-Padding','x'*40000)
        if selected == 'redirect': self.send_header('Location',f'https://localhost:{self.server.server_port}/trap')
        self.send_header('Content-Length',str(len(raw)))
        self.end_headers()
        try: self.wfile.write(raw)
        except (ConnectionError, ssl.SSLError): pass

server = http.server.HTTPServer(('127.0.0.1',0),Handler)
ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
ctx.load_cert_chain(args.certificate,args.private_key)
server.socket = ctx.wrap_socket(server.socket,server_side=True)
thread = threading.Thread(target=server.serve_forever,daemon=True)
thread.start()
checks=[]
defaults={'url':f'https://localhost:{server.server_port}', 'address':'127.0.0.1', 'site_id':identity,
          'token':token, 'ca':str(args.certificate), 'allow_private':['127.0.0.1'], 'timeout':2000}

def run(name, expected=None, **overrides):
    payload=defaults | overrides
    started=time.monotonic()
    p=subprocess.run([str(args.php),str(Path(__file__).with_name('client_fetch.php'))],
                     input=json.dumps(payload),capture_output=True,text=True,timeout=10,
                     creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
    assert p.returncode==0 and token not in p.stdout+p.stderr, name
    result=json.loads(p.stdout)
    assert result.get('error')==expected and result['ok']==(expected is None), (name,result)
    checks.append(name)
    return result,time.monotonic()-started

try:
    run('valid_tls_identity')
    mode='partial'
    assert run('partial_preserved')[0]['status']=='partial'
    for mode,error in [('wrong_identity','identity_mismatch'),('wrong_protocol','unsupported_protocol'),
                       ('wrong_schema','invalid_inventory'),('bad_date','invalid_inventory'),
                       ('missing_section','invalid_inventory'),('bad_capability','invalid_inventory'),
                       ('hidden_partial','invalid_inventory'),('denied','access_denied'),
                       ('unavailable','connector_unavailable'),('http_error','http_error'),
                       ('redirect','redirect_rejected'),('html','invalid_content_type'),
                       ('malformed','invalid_json'),('large_headers','response_too_large')]:
        run(mode,error)
    assert trap_calls==0
    checks.append('redirect_never_followed')
    mode='oversize'
    run('bounded_body','response_too_large',max_bytes=1024)
    mode='slow'
    assert run('bounded_time','timeout',timeout=100)[1]<2
    time.sleep(0.5)
    mode='valid'
    run('untrusted_certificate','tls_verification_failed',ca=None)
    run('hostname_mismatch','tls_verification_failed',url=f'https://wrong.invalid:{server.server_port}')
    before=calls
    for label,overrides in [
        ('private_default',{'allow_private':[]}),
        ('private_not_allowed',{'address':'10.0.0.1'}),
        ('metadata_address',{'address':'169.254.169.254'}),
        ('shared_address',{'address':'100.64.0.1'}),
        ('invalid_address',{'address':'127.1'}),
        ('http',{'url':'http://localhost'}),
        ('userinfo',{'url':'https://user@localhost'}),
        ('query',{'url':'https://localhost?token=example'}),
        ('fragment',{'url':'https://localhost/#fragment'}),
        ('backslash',{'url':'https://localhost\\@wrong.invalid'}),
        ('path_traversal',{'url':'https://localhost/../admin'}),
        ('encoded_path',{'url':'https://localhost/%2fadmin'}),
        ('numeric_host',{'url':'https://127.0.0.1'}),
        ('bad_identity',{'site_id':'not-uuid'}),
        ('header_injection',{'token':token+'\r\nX-Injected: yes'}),
    ]:
        run(label,'invalid_enrollment',**overrides)
    assert calls==before
    checks.append('invalid_inputs_do_not_connect')
    run('subdirectory_installation',url=defaults['url']+'/joomla')
    server.shutdown()
    server.server_close()
    run('connection_failure','connection_failed')
    print(json.dumps({'passed':len(checks),'checks':checks,'fixture':'loopback TLS, simulated protocol responses'},indent=2))
finally:
    server.shutdown()
    server.server_close()
    thread.join(timeout=5)
