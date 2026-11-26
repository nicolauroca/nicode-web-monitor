"""Native HTTPS/browser onboarding on one DISPOSABLE Joomla with both packages.

The installed test copies receive a loopback destination and local CA override,
restored in finally. Packaged transports are never changed. Credentials on stdin.
"""
import argparse
import html
import http.server
import json
import mimetypes
import os
from pathlib import Path
import re
import ssl
import subprocess
import sys
import threading
import urllib.error
import urllib.request
from urllib.parse import urlsplit, unquote

p=argparse.ArgumentParser(description=__doc__)
for name in ['site','php','certificate','private-key','output']:
    p.add_argument('--'+name,type=Path,required=True)
p.add_argument('--browser',help='Chrome/Chromium executable path')
p.add_argument('--remote-transport',action='store_true',help='Exercise remote HTTPS with test-only loopback/CA overrides instead of the local shortcut')
p.add_argument('--design-states',action='store_true',help='Also inspect pending, disabled, dark and long-address presentations with disposable UI fixture states')
p.add_argument('--maintenance-tests',action='store_true',help='Also run native maintenance form/role suites against this TLS CGI server')
p.add_argument('--central-design',action='store_true',help='Exercise Central states, search, keyboard, mobile and dark theme with disposable observations')
p.add_argument('--without-js',action='store_true',help='Verify native connection/disconnection with browser JavaScript disabled')
p.add_argument('--allow-disposable-writes',action='store_true',required=True)
a=p.parse_args(); site=a.site.resolve(); a.output.mkdir(parents=True,exist_ok=True)
credentials=json.load(sys.stdin); checks=[]
flags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0
php=[str(a.php),'-d','extension=sodium','-d','extension=mysqli']
cgi=[str(a.php.with_name('php-cgi.exe' if os.name=='nt' else 'php-cgi'))]
ini=a.output.resolve()/'php.ini'
base_ini=a.php.parent/'php.ini'
ini.write_text((base_ini.read_text(encoding='utf-8') if base_ini.exists() else '')+
    '\nextension_dir="'+str(a.php.parent/'ext').replace('\\','/')+'"\nextension=sodium\nextension=mysqli\n',encoding='utf-8')
backups={}
snapshot=None

def fixture(payload):
    result=subprocess.run(php+[str(Path(__file__).with_name('onboarding_fixture.php')),str(site),'--allow-disposable-writes'],
        input=json.dumps(payload),text=True,capture_output=True,timeout=30,creationflags=flags)
    if result.returncode or not result.stdout.startswith('{'):
        (a.output/'fixture-error.txt').write_text(result.stdout+'\n'+result.stderr,encoding='utf-8')
        raise RuntimeError('Disposable fixture setup/cleanup failed; see private fixture-error.txt')
    return json.loads(result.stdout)

def replace(relative,old,new):
    path=site/relative
    if path not in backups: backups[path]=path.read_bytes()
    source=path.read_text(encoding='utf-8')
    assert old in source,relative
    path.write_text(source.replace(old,new),encoding='utf-8',newline='\n')

class Handler(http.server.BaseHTTPRequestHandler):
    def log_message(self,*unused): pass
    def do_GET(self):
        parts=urlsplit(self.path); path=unquote(parts.path)
        target=(site/path.lstrip('/')).resolve()
        if not target.is_relative_to(site): self.send_error(404); return
        if target.is_dir(): target=target/'index.php'; path=path.rstrip('/')+'/index.php'
        if not target.is_file(): self.send_error(404); return
        if target.suffix!='.php':
            body=target.read_bytes(); self.send_response(200)
            self.send_header('Content-Type',mimetypes.guess_type(target.name)[0] or 'application/octet-stream')
            self.send_header('Content-Length',str(len(body))); self.end_headers(); self.wfile.write(body); return
        env=os.environ.copy()
        env['PHPRC']=str(ini)
        env.update({'REDIRECT_STATUS':'200','GATEWAY_INTERFACE':'CGI/1.1','SCRIPT_FILENAME':str(target),
            'SCRIPT_NAME':path,'DOCUMENT_ROOT':str(site),'QUERY_STRING':parts.query,'REQUEST_URI':self.path,
            'REQUEST_METHOD':self.command,'SERVER_PROTOCOL':'HTTP/1.1','SERVER_NAME':'localhost',
            'SERVER_PORT':str(self.server.server_port),'REMOTE_ADDR':'127.0.0.1','HTTP_HOST':self.headers['Host'],
            'HTTPS':'on','CONTENT_TYPE':self.headers.get('Content-Type',''),'CONTENT_LENGTH':self.headers.get('Content-Length','0')})
        for header in ['Cookie','Authorization','User-Agent','X-Forwarded-Proto']:
            if header in self.headers: env['HTTP_'+header.upper().replace('-','_')]=self.headers[header]
        raw=self.rfile.read(min(int(self.headers.get('Content-Length',0)),16384))
        result=subprocess.run(cgi,env=env,cwd=site,input=raw,capture_output=True,timeout=40,creationflags=flags)
        headers,sep,body=result.stdout.partition(b'\r\n\r\n')
        if not sep: self.send_error(502); return
        parsed=[line.decode('latin1').split(':',1) for line in headers.split(b'\r\n') if b':' in line]
        status=next((int(v.strip().split()[0]) for k,v in parsed if k.lower()=='status'),200)
        self.send_response(status)
        for k,v in parsed:
            if k.lower() not in ['status','content-length','connection']: self.send_header(k,v.strip())
        self.send_header('Content-Length',str(len(body))); self.end_headers(); self.wfile.write(body)
    do_POST=do_GET

class TestServer(http.server.ThreadingHTTPServer):
    request_queue_size=128
    daemon_threads=True

server=TestServer(('127.0.0.1',0),Handler)
ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(a.certificate,a.private_key)
server.socket=ctx.wrap_socket(server.socket,server_side=True)
threading.Thread(target=server.serve_forever,daemon=True).start()
base=f'https://localhost:{server.server_port}'
client=ssl.create_default_context(cafile=str(a.certificate))

def request(path,body=None,headers=None):
    req=urllib.request.Request(base+path,data=body,headers=headers or {})
    try:
        with urllib.request.urlopen(req,context=client,timeout=30) as r: return r.status,r.read(),r.headers
    except urllib.error.HTTPError as e: return e.code,e.read(),e.headers

def check(ok,name):
    if not ok: raise AssertionError(name)
    checks.append(name)

try:
    snapshot=fixture({'action':'begin'})
    route='/index.php?option=com_nicodewebmonitor&task=connection.claim'
    check(request(route)[0]==405,'public pairing route rejects GET')
    status,body,headers=request(route,b'{}',{'Content-Type':'application/json'})
    check(status==400 and json.loads(body)['error']=='code_unavailable','public pairing rejects unauthenticated claim')
    check('no-store' in headers.get('Cache-Control',''),'public pairing responses cannot be cached')
    check(request(route,b'['+b' '*5000,{'Content-Type':'application/json'})[0]==400,'oversized body rejected')
    if not a.remote_transport:
        for name in ['com_nicodewebmonitor','com_nicodeconnector']:
            replace(f'administrator/components/{name}/src/Connection/PublicAddress.php',
                '$host = parse_url(self::url($url), PHP_URL_HOST);',"throw new \\RuntimeException('Test: self pairing must not use DNS');\n        $host = parse_url(self::url($url), PHP_URL_HOST);")
    else:
        replace('administrator/components/com_nicodeconnector/src/Service/Connection.php',
            '$local=$this->send===null', '$local=false && $this->send===null')
    for name in (['com_nicodewebmonitor','com_nicodeconnector'] if a.remote_transport else []):
        replace(f'administrator/components/{name}/src/Connection/PublicAddress.php',
            '$records = $this->lookup ?', "if ($host === 'localhost') return '93.184.216.34';\n        $records = $this->lookup ?")
    ca=str(a.certificate.resolve()).replace('\\','/')
    if a.remote_transport:
        replace('administrator/components/com_nicodewebmonitor/src/Service/InventoryClient.php',
        "CURLOPT_PROXY => '',",f"CURLOPT_CAINFO => '{ca}', CURLOPT_PROXY => '',")
        replace('administrator/components/com_nicodewebmonitor/src/Service/InventoryClient.php',
        '$resolved = str_contains($address,', "$address = '127.0.0.1';\n        $resolved = str_contains($address,")
        replace('administrator/components/com_nicodeconnector/src/Connection/JsonClient.php',
        "CURLOPT_MAXREDIRS=>0, CURLOPT_PROXY=>'',",f"CURLOPT_MAXREDIRS=>0, CURLOPT_CAINFO=>'{ca}', CURLOPT_PROXY=>'',")
        replace('administrator/components/com_nicodeconnector/src/Connection/JsonClient.php',
        "$address = str_contains($address,", "$address = '127.0.0.1';\n        $address = str_contains($address,")
    from playwright.sync_api import sync_playwright
    with sync_playwright() as pw:
        browser=pw.chromium.launch(executable_path=a.browser,channel=None if a.browser else 'chrome',headless=True)
        context=browser.new_context(ignore_https_errors=True,viewport={'width':1440,'height':1000},reduced_motion='reduce',java_script_enabled=not a.without_js)
        page=context.new_page(); errors=[]; page.on('pageerror',lambda e:errors.append(str(e)))
        network=[]
        page.on('requestfailed',lambda req:network.append({'url':req.url,'failure':req.failure}))
        page.on('response',lambda response:network.append({'url':response.url,'status':response.status}) if response.status>=400 else None)
        page.route('**/*',lambda route:route.continue_() if urlsplit(route.request.url).netloc==urlsplit(base).netloc else route.abort())
        page.goto(base+'/administrator/')
        (a.output/'login.html').write_text(page.content(),encoding='utf-8')
        page.locator('#mod-login-username').wait_for(timeout=5000)
        page.locator('#mod-login-username').fill(credentials['admin_user']); page.locator('#mod-login-password').fill(credentials['admin_password'])
        page.locator('button[type=submit]').click(); page.wait_for_load_state('networkidle')
        central=base+'/administrator/index.php?option=com_nicodewebmonitor'
        connector=base+'/administrator/index.php?option=com_nicodeconnector'
        page.goto(central); page.wait_for_load_state('networkidle')
        check(page.get_by_role('heading',name='Añadir sitio',exact=True).count()==1,'central presents simple onboarding')
        check(page.locator('input[name=site_id],input[name=credential],input[name=address]').count()==0,'central no longer asks for identity credential or IP')
        page.locator('#new-site-name').fill('Self monitored central')
        page.get_by_role('button',name='Generar código de vinculación',exact=True).click(); page.wait_for_load_state('networkidle')
        code=page.locator('#pairing-code').input_value()
        check(bool(re.fullmatch('[a-f0-9]{64}',code)),'native administrator issues temporary code')
        check(page.locator('#central-address').input_value()==base,'central URL detected automatically')
        check(page.locator('#pairing-code').get_attribute('type')=='password','central masks the temporary code')
        if not a.without_js:
            page.get_by_role('button',name='Mostrar',exact=True).click()
            check(page.locator('#pairing-code').get_attribute('type')=='text','central can reveal the code')
            page.get_by_role('button',name='Ocultar',exact=True).click()
            page.evaluate("Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async(value)=>{window.nwmTestClipboard=value}}})")
            page.get_by_role('button',name='Copiar código',exact=True).click()
            check(page.evaluate('window.nwmTestClipboard')==code,'central copies the exact code through a mocked clipboard')
            page.get_by_role('button',name='Copiar dirección',exact=True).click()
            check(page.evaluate('window.nwmTestClipboard')==base,'central copies the detected address')
        page.screenshot(path=str(a.output/'central-desktop.png'),full_page=True)
        page.goto(connector); page.wait_for_load_state('networkidle')
        page.screenshot(path=str(a.output/'connector-setup-desktop.png'),full_page=True)
        check(page.locator('#connection-code').get_attribute('type')=='password','pairing code is masked by default')
        if not a.without_js:
            page.get_by_role('button',name='Mostrar',exact=True).click()
            check(page.locator('#connection-code').get_attribute('type')=='text','code visibility can be toggled without submitting')
            page.get_by_role('button',name='Ocultar',exact=True).click()
            page.get_by_role('button',name='Usar este Joomla',exact=True).click()
            check(page.locator('#central-url').input_value()==base,'local URL shortcut fills the canonical address')
        page.set_viewport_size({'width':390,'height':1000})
        page.screenshot(path=str(a.output/'connector-setup-mobile.png'),full_page=True)
        check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'),'setup has no mobile horizontal overflow')
        page.set_viewport_size({'width':1440,'height':1000})
        if not a.without_js:
            page.locator('#connection-code').fill('short')
            page.get_by_role('button',name='Conectar',exact=True).click()
            check(page.locator('#connection-code').get_attribute('aria-invalid')=='true' and page.locator('#connection-code-error').is_visible(),'incomplete code has an accessible inline error before submission')
            check(page.get_by_role('button',name='Conectar',exact=True).is_enabled(),'invalid form does not lock the submit button')
        page.locator('#central-url').fill(base); page.locator('#connection-code').fill(code)
        page.get_by_role('button',name='Conectar',exact=True).click(); page.wait_for_load_state('networkidle')
        check(page.get_by_role('heading',name='Conectado',exact=True).count()==1,'browser connects same Joomla using '+('remote HTTPS' if a.remote_transport else 'local transport without DNS'))
        check(page.get_by_role('heading',name='Nicode Web Monitor — Conector',exact=True).count()==1,'connector shares the product brand')
        page.screenshot(path=str(a.output/'connector-desktop.png'),full_page=True)
        page.set_viewport_size({'width':1440,'height':1400})
        page.evaluate('window.scrollTo(0,0)')
        page.locator('.nwm-connector').screenshot(path=str(a.output/'connector-preview.png'))
        page.set_viewport_size({'width':1440,'height':1000})
        if a.design_states:
            ui=fixture({'action':'ui_snapshot'})
            try:
                long_url='https://'+'monitoring-team-'*3+'example.org/joomla-administration'
                for state,enabled,heading in [('pending',True,'Conexión pendiente de confirmar'),('pending',False,'Acceso desactivado'),('connected',False,'Acceso desactivado'),('connected',True,'Conectado')]:
                    fixture({'action':'ui_preview','state':state,'central_url':long_url,'enabled':enabled})
                    for width in [1440,390,320]:
                        page.set_viewport_size({'width':width,'height':1000});page.goto(connector);page.wait_for_load_state('networkidle')
                        check(page.get_by_role('heading',name=heading,exact=True).count()==1,f'{width}: {state}/{enabled} has an accurate visible status')
                        if state=='pending' and not enabled:check(page.locator('.nwm-connect-form').count()==0,f'{width}: disabled attempt guides cancellation instead of a futile retry')
                        check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'),f'{width}: {state}/{enabled} long address fits viewport')
                        if width in [1440,390]:page.locator('.nwm-connector').screenshot(path=str(a.output/f'connector-{state}-{enabled}-{width}.png'))
                page.set_viewport_size({'width':1440,'height':1000})
                page.evaluate("document.documentElement.dataset.bsTheme='dark'")
                check(page.locator('.nwm-panel').first.evaluate("e=>getComputedStyle(e).backgroundColor === 'rgb(27, 43, 55)'"),'connector adapts to native dark theme')
                page.locator('.nwm-connector').screenshot(path=str(a.output/'connector-dark.png'))
            finally:
                fixture({'action':'ui_restore',**ui})
                page.set_viewport_size({'width':1440,'height':1000});page.goto(connector);page.wait_for_load_state('networkidle')
        if not a.without_js:
            page.goto(base+'/administrator/index.php?option=com_plugins&task=plugin.edit&extension_id='+str(snapshot['system_id']))
            page.wait_for_load_state('networkidle')
            check(not page.locator('#jform_params_site_id').is_visible() and not page.locator('#jform_params_token_hash').is_visible(),'plugin keeps inventory identity fields out of the user interface')
            page.locator('#toolbar-apply button').click(); page.wait_for_load_state('networkidle')
            page.goto(central); page.get_by_role('button',name='Ver inventario de Self monitored central',exact=True).click(); page.wait_for_load_state('networkidle')
            check(page.get_by_role('heading',name='Software y entorno',exact=True).count()==1,'saving advanced plugin settings preserves inventory connection')
            page.locator('#nwm-extension-search').fill('nwm-no-such-extension')
            check(page.locator('[data-nwm-extension]:visible').count()==0 and page.locator('[data-nwm-no-extensions]').is_visible(),'extension search shows an explicit empty result')
            page.locator('#nwm-extension-search').fill('nicode')
            check(page.locator('[data-nwm-extension]:visible').count()>=4,'extension search finds installed product pieces')
            page.locator('#nwm-extension-search').fill('')
            for width in [1440,390,320]:
                page.set_viewport_size({'width':width,'height':1000})
                check(page.evaluate('document.documentElement.scrollWidth<=innerWidth'),f'{width}: inventory fits the viewport with an explicit scrollable table')
            page.set_viewport_size({'width':1440,'height':1000})
            page.locator('.nwm-inventory').screenshot(path=str(a.output/'inventory-desktop.png'))
        if a.central_design:
            from browser_dashboard import exercise_dashboard
            def dashboard_fixture(action):
                r=subprocess.run(php+[str(Path(__file__).with_name('browser_fixture.php')),str(site),'--allow-disposable-writes',action],capture_output=True,text=True,timeout=30,creationflags=flags)
                if r.returncode: raise RuntimeError('Dashboard fixture failed: '+r.stderr[:1000])
            try:
                dashboard_fixture('seed')
                exercise_dashboard(page,central,a.output,check,not a.without_js)
            finally:
                dashboard_fixture('clean')
        for width in [1440,390]:
            page.set_viewport_size({'width':width,'height':1000}); page.goto(central); page.wait_for_load_state('networkidle')
            check(page.get_by_role('heading',name='Self monitored central',exact=True).count()==1,f'{width}: enrolled site appears in dashboard')
            check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'),f'{width}: central no horizontal overflow')
            if width==390: page.screenshot(path=str(a.output/'central-mobile.png'),full_page=True)
            page.goto(connector); page.wait_for_load_state('networkidle')
            check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'),f'{width}: connector no horizontal overflow')
            check(page.evaluate("getComputedStyle(document.body).fontFamily.includes('Roboto')"),f'{width}: native template stylesheet loaded')
            if width==390:
                page.screenshot(path=str(a.output/'connector-mobile.png'),full_page=True)
                (a.output/'connector.html').write_text(page.content(),encoding='utf-8')
        page.locator('.nwm-management > summary').focus()
        page.locator('.nwm-management > summary').press('Enter')
        page.locator('input[name=confirmed]').check(); page.get_by_role('button',name='Desconectar',exact=True).click(); page.wait_for_load_state('networkidle')
        check(page.get_by_role('heading',name='Conectar este sitio',exact=True).count()==1,'browser disconnect revokes local access')
        page.goto(central); page.get_by_role('button',name='Ver inventario de Self monitored central',exact=True).click(); page.wait_for_load_state('networkidle')
        check(page.get_by_text('connector_unavailable',exact=False).count()>0,'central sees revocation on next inventory check')
        page.locator('.nwm-site-details > summary').click()
        page.locator('.nwm-remove > summary').click()
        page.locator('.nwm-remove input[name=confirmed]').check()
        page.get_by_role('button',name='Eliminar registro de Self monitored central',exact=True).click(); page.wait_for_load_state('networkidle')
        check(page.locator('[data-nwm-site]').count()==0,'browser removes central registration independently')
        (a.output/'network.json').write_text(json.dumps(network,indent=2),encoding='utf-8')
        failed_assets=[r for r in network if re.search(r'\.(css|js)(?:\?|$)',r['url'])]
        for resource in failed_assets:
            # The local CGI/TLS harness closes connections between requests.
            # Verify transient browser resource failures explicitly; never ignore 404s.
            retry=context.request.get(resource['url'],timeout=15000)
            check(retry.status==200,'failed fixture resource is available on explicit recheck')
        check(not [r for r in failed_assets if r.get('status',200)>=400],'native CSS and JavaScript resources exist')
        check(not errors,'no browser JavaScript errors: '+repr(errors))
        browser.close()
    if a.maintenance_tests:
        env=os.environ.copy();env['PHPRC']=str(ini)
        for name,extra in [('backup_roles_http',['--joomla',str(site)]),('backup_admin_http',['--site',str(site),'--browser-output',str(a.output/'maintenance')])]:
            result=subprocess.run([sys.executable,str(Path(__file__).with_name(name+'.py')),'--base-url',base,'--php',str(a.php),'--certificate',str(a.certificate),'--allow-disposable-writes',*extra],input=json.dumps(credentials),text=True,encoding='utf-8',errors='replace',capture_output=True,timeout=120,creationflags=flags,env=env)
            (a.output/(name+'.log')).write_text(result.stdout+'\n'+result.stderr,encoding='utf-8')
            if result.returncode: raise RuntimeError('Maintenance fixture failed: '+name+'; see private log')
            (a.output/(name+'.json')).write_text(json.dumps(json.loads(result.stdout),indent=2),encoding='utf-8')
    report={'passed':len(checks),'checks':checks,'transport':'https' if a.remote_transport else 'local','test_only_overrides':'loopback and local CA for remote mode; forbidden DNS for self mode; installed files restored'}
    (a.output/'report.json').write_text(json.dumps(report,indent=2),encoding='utf-8')
    print(json.dumps(report,indent=2))
finally:
    for path,data in backups.items(): path.write_bytes(data)
    if snapshot is not None: fixture({'action':'cleanup',**snapshot})
    server.shutdown(); server.server_close()
