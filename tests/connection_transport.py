"""HTTPS pairing diagnostics against a loopback-only deterministic fixture."""
import argparse,http.server,json,ssl,subprocess,threading
from pathlib import Path
p=argparse.ArgumentParser()
for name in ['php','certificate','private-key']:p.add_argument('--'+name,required=True)
a=p.parse_args();mode='ok'
class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self,*unused):pass
    def do_POST(self):
        self.rfile.read(int(self.headers.get('Content-Length',0)))
        statuses={'auth':401,'forbidden':403,'missing':404,'server':500,'redirect':302}
        body=b'not-json' if mode=='json' else json.dumps({'ok':True,'site_id':'fixture'}).encode()
        self.send_response(statuses.get(mode,200))
        self.send_header('Content-Type','text/html' if mode=='html' else 'application/json')
        self.send_header('Content-Length',str(len(body)))
        if mode=='redirect':self.send_header('Location','https://example.invalid')
        self.end_headers()
        self.wfile.write(body)
server=http.server.HTTPServer(('127.0.0.1',0),Handler)
ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(a.certificate,a.private_key)
server.socket=ctx.wrap_socket(server.socket,server_side=True);threading.Thread(target=server.serve_forever,daemon=True).start()
checks=[]
try:
    for mode,error in [('ok',None),('auth','central_auth_required'),('forbidden','central_access_denied'),('missing','central_component_missing'),('server','central_server_error'),('redirect','redirect_rejected'),('html','invalid_response'),('json','invalid_response')]:
        run=subprocess.run([a.php,str(Path(__file__).with_name('connection_fetch.php'))],input=json.dumps({'ca':str(Path(a.certificate).resolve()),'url':f'https://localhost:{server.server_port}'}),text=True,capture_output=True,timeout=25)
        assert run.returncode==0,(mode,run.stderr)
        result=json.loads(run.stdout);assert result['ok']==(error is None) and result.get('error')==error,(mode,result)
        checks.append(mode)
finally:server.shutdown();server.server_close()
print(json.dumps({'passed':len(checks),'checks':checks}))
