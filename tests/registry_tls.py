"""Real Joomla registry to simulated HTTPS connector; not a two-Joomla test."""
import argparse, http.server, json, secrets, ssl, subprocess, threading, uuid
from pathlib import Path
p=argparse.ArgumentParser(description=__doc__)
for name in ['php','site','certificate','private-key']: p.add_argument('--'+name,required=True)
a=p.parse_args()
identity, token=str(uuid.uuid4()), secrets.token_hex(32)
mode='complete'
class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def do_GET(self):
        authorized=self.headers.get('Authorization')=='Bearer '+token
        inventory={'schema_version':1,'collected_at':'2026-10-09T14:00:00Z','status':mode if mode in ['complete','partial'] else 'complete','execution_context':'remote_https','capabilities':{'remote_inventory':True,'remote_updates':False,'remote_backups':False},'sections':{key:{'status':'available','data':{}} for key in ['joomla','runtime','database','extensions','hosting']}}
        if mode=='partial': inventory['sections']['database']={'status':'error','data':None}
        body=json.dumps({'protocol_version':1,'site_id':str(uuid.uuid4()) if mode=='wrong_identity' else identity,'inventory':inventory}).encode()
        self.send_response(401 if mode=='revoked' or not authorized else 200)
        self.send_header('Content-Type','application/json'); self.send_header('Content-Length',str(len(body))); self.end_headers(); self.wfile.write(body)
server=http.server.HTTPServer(('127.0.0.1',0),Handler)
ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(a.certificate,a.private_key)
server.socket=ctx.wrap_socket(server.socket,server_side=True)
threading.Thread(target=server.serve_forever,daemon=True).start()
checks=[]
try:
    for mode,error in [('complete',None),('partial',None),('revoked','access_denied'),('wrong_identity','identity_mismatch')]:
        payload={'site_id':identity,'token':token,'ca':a.certificate,'url':f'https://localhost:{server.server_port}'}
        r=subprocess.run([a.php,str(Path(__file__).with_name('registry_fetch.php')),a.site,'--allow-disposable-writes'],input=json.dumps(payload),capture_output=True,text=True,timeout=30)
        assert r.returncode==0 and token not in r.stdout+r.stderr, 'harness failed'
        result=json.loads(r.stdout)
        assert result['ok']==(error is None) and result['error']==error and result['rendered'], (mode,result)
        if error is None: assert result['status']==mode
        checks.append(mode)
finally: server.shutdown(); server.server_close()
print(json.dumps({'passed':len(checks),'checks':checks,'fixture':'one real Joomla registry with simulated TLS connector'},indent=2))
