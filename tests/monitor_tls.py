"""Real Joomla batches to simulated loopback TLS endpoints; no public network."""
import argparse, datetime, http.server, json, secrets, ssl, subprocess, threading, time, uuid
from pathlib import Path
p=argparse.ArgumentParser(description=__doc__)
for name in ['php','site','certificate','private-key']: p.add_argument('--'+name,required=True)
a=p.parse_args()
# Validate before starting Joomla writes; certificate verification stays enabled.
decoded=ssl._ssl._test_decode_cert(a.certificate)
assert ssl.cert_time_to_seconds(decoded['notBefore'])<=time.time()<ssl.cert_time_to_seconds(decoded['notAfter']), 'Renew expired local certificate first'
token=secrets.token_hex(32)
sites=[{'id':str(uuid.uuid4()),'mode':mode} for mode in ['complete','revoked','partial']]
class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def do_GET(self):
        from urllib.parse import urlsplit
        url=urlsplit(self.path); mode=url.path.strip('/').split('/')[0]
        identity=url.path.strip('/').split('/')[1]
        if mode=='slow': time.sleep(2)
        inv={'schema_version':1,'collected_at':datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),'status':'partial' if mode=='partial' else 'complete','execution_context':'remote_https','capabilities':{'remote_inventory':True,'remote_updates':False,'remote_backups':False},'sections':{key:{'status':'available','data':{}} for key in ['joomla','runtime','database','extensions','hosting']}}
        if mode=='partial': inv['sections']['database']={'status':'error','data':None}
        body=json.dumps({'protocol_version':1,'site_id':identity,'inventory':inv}).encode()
        try:
            self.send_response(401 if mode=='revoked' or self.headers.get('Authorization')!='Bearer '+token else 200)
            self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
        except (ConnectionError,ssl.SSLError): pass
server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler)
ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(a.certificate,a.private_key)
server.socket=ctx.wrap_socket(server.socket,server_side=True)
threading.Thread(target=server.serve_forever,daemon=True).start()
try:
    payload={'sites':sites,'token':token,'ca':a.certificate,'url':f'https://localhost:{server.server_port}'}
    r=subprocess.run([a.php,str(Path(__file__).with_suffix('.php')),a.site,'--allow-disposable-writes'],input=json.dumps(payload),capture_output=True,text=True,timeout=30)
    assert token not in r.stdout+r.stderr, 'Unexpected fixture credential output'
    assert r.returncode==0,r.stdout+r.stderr
    result=json.loads(r.stdout);result['fixture']='real Joomla batch with simulated HTTPS connectors';result['tls_verified']=True
    print(json.dumps(result,indent=2))
finally: server.shutdown();server.server_close()
