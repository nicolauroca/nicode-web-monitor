"""Package: Nicode.joomla.webmonitor. One local TLS SMTP capture, no external email."""
import argparse, json, socketserver, ssl, subprocess, threading, time
from pathlib import Path
p=argparse.ArgumentParser()
for name in ['php','site','certificate','private-key']:p.add_argument('--'+name,required=True)
a=p.parse_args()
cert=ssl._ssl._test_decode_cert(a.certificate)
assert ssl.cert_time_to_seconds(cert['notBefore']) <= time.time() < ssl.cert_time_to_seconds(cert['notAfter'])
messages=[]
class Handler(socketserver.StreamRequestHandler):
    def handle(self):
        self.connection.settimeout(15)
        self.wfile.write(b'220 localhost ESMTP fixture\r\n')
        while line:=self.rfile.readline(4096):
            verb=line.split(b' ',1)[0].strip().upper()
            if verb in [b'EHLO',b'HELO']:reply=b'250 localhost\r\n'
            elif verb in [b'MAIL',b'RCPT',b'RSET']:reply=b'250 OK\r\n'
            elif verb==b'DATA':
                self.wfile.write(b'354 End with dot\r\n');body=[]
                while (line:=self.rfile.readline(8192)) not in [b'.\r\n',b'']:
                    body.append(line)
                    assert sum(map(len,body))<65536
                messages.append(b''.join(body));reply=b'250 Accepted locally\r\n'
            elif verb==b'QUIT':self.wfile.write(b'221 Bye\r\n');return
            else:reply=b'500 Unsupported\r\n'
            self.wfile.write(reply)
server=socketserver.TCPServer(('127.0.0.1',0),Handler)
context=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);context.load_cert_chain(a.certificate,a.private_key)
server.socket=context.wrap_socket(server.socket,server_side=True)
thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
try:
    result=subprocess.run([a.php,str(Path(__file__).with_suffix('.php')),a.site,'--allow-disposable-writes'],input=json.dumps({'port':server.server_address[1],'ca':str(Path(a.certificate).resolve())}),text=True,capture_output=True,timeout=30)
    assert result.returncode==0,result.stdout+result.stderr
    assert len(messages)==1 and b'never_checked' in messages[0]
    print(result.stdout.strip())
finally:server.shutdown();server.server_close();thread.join(timeout=2)
