"""Integration test: central backup enrollment, CSRF, confirmation and journal over real Joomla HTTP.
Requires an installed central component and a disposable administrator account.
No inventory requests leave the machine. Do not log input credentials.
"""
import argparse, sys, json, re, secrets, uuid, urllib.request, urllib.parse, urllib.error, http.cookiejar, subprocess
from pathlib import Path
import ssl
parser=argparse.ArgumentParser(description='Disposable loopback Joomla admin form checks. Pass admin_user/admin_password JSON on stdin; never use a real site.')
parser.add_argument('--base-url',required=True)
parser.add_argument('--site',type=Path,required=True,help='Disposable Joomla root, used only to seed fixture inventory')
parser.add_argument('--php',required=True)
parser.add_argument('--certificate',type=Path,help='Local CA for a disposable HTTPS fixture')
parser.add_argument('--browser-output',type=Path)
parser.add_argument('--allow-disposable-writes',action='store_true',required=True)
args=parser.parse_args()
parts=urllib.parse.urlsplit(args.base_url)
if parts.scheme not in ['http','https'] or parts.hostname not in ['127.0.0.1','localhost','::1'] or parts.username or parts.password or parts.query or parts.fragment:
    raise SystemExit('Only a disposable loopback HTTP(S) URL is accepted')
base=args.base_url.rstrip('/')+'/administrator/index.php'
handlers=[urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar())]
if parts.scheme=='https':
    if not args.certificate: raise SystemExit('Explicit local CA required for HTTPS')
    handlers.append(urllib.request.HTTPSHandler(context=ssl.create_default_context(cafile=str(args.certificate))))
op=urllib.request.build_opener(*handlers)
checks=[]
def request(url=base, data=None):
    try:
        r=op.open(url, None if data is None else urllib.parse.urlencode(data).encode(), timeout=30)
        return r.status,r.read().decode()
    except urllib.error.HTTPError as e:return e.code,e.read().decode()
def check(ok,name):
    if not ok:raise RuntimeError(name)
    checks.append(name)
def csrf(html):
    return re.search(r'name="([a-f0-9]{32})" value="1"',html).group(1)
_,html=request()
c=json.load(sys.stdin)
status,html=request(data={'option':'com_login','task':'login','username':c['admin_user'],'passwd':c['admin_password'],csrf(html):'1'})
url=base+'?option=com_nicodewebmonitor'
status,html=request(url)
check(status==200 and 'Añadir sitio' in html,'authenticated component dispatch')
formtoken=csrf(html)
label='Disposable HTTP '+secrets.token_hex(6)
credential=secrets.token_hex(32)
data={'task':'add','label':label,'base_url':'https://example.org','address':'93.184.216.34','site_id':str(uuid.uuid4()),'credential':credential}
status,_=request(url,data)
check(status==403,'POST without CSRF rejected')
status,_=request(url+'&task=add')
check(status==403,'GET mutation rejected')
status,_=request(url+'&task=inspect&id=1')
check(status==403,'GET inventory rejected')
status,_=request(url,{'task':'inspect','id':'1'})
check(status==403,'inventory without CSRF rejected')
status,html=request(url,{'task':'inspect','id':'-1',formtoken:'1'})
check(status==200 and 'site_not_found' in html,'inventory result rendered through real dispatcher')
seed=subprocess.run([args.php,str(Path(__file__).with_name('onboarding_fixture.php')),str(args.site.resolve()),'--allow-disposable-writes'],
    input=json.dumps({'action':'seed_inventory','label':label,'site_id':data['site_id'],'credential':credential}),text=True,capture_output=True)
check(seed.returncode==0 and '"ok":true' in seed.stdout,'inventory fixture seeded through local service')
status,html=request(url)
check(status==200 and label in html,'fixture site visible')
check(credential not in html,'credential not echoed in HTML')
row=next(x for x in re.findall(r'<article class="nwm-site".*?</article>',html,re.S) if label in x)
rowid=re.search(r'name="id" value="(\d+)"',row).group(1)
backup=secrets.token_hex(32)
for task in ['backupEnroll','backupBegin','backupAdvance','backupStatus']:
    check(request(url,{'task':task,'id':rowid})[0]==403,task+' rejects missing CSRF')
    check(request(url+'&task='+task)[0]==403,task+' rejects GET')
status,html=request(url,{'task':'backupEnroll','id':rowid,'backup_credential':credential,formtoken:'1'})
check('Backup action not confirmed' in html,'inventory credential rejected')
status,html=request(url,{'task':'backupEnroll','id':rowid,'backup_credential':backup,formtoken:'1'})
check('Backup journal updated' in html and backup not in html,'backup credential saved without echo')
status,html=request(url,{'task':'backupBegin','id':rowid,formtoken:'1'})
check('Backup action not confirmed' in html,'begin requires server confirmation')
status,html=request(url,{'task':'backupBegin','id':rowid,'confirmed':'1',formtoken:'1'})
check('Backup journal updated' in html,'confirmed begin accepted')
ops=re.findall(r'<article class="nwm-backup".*?</article>',html,re.S)
entry=next(x for x in ops if 'Sitio #'+rowid+';' in x)
operation=re.search(r'backup-[a-f0-9]{32}',entry).group()
check('estado: ready' in entry and 'Avanzar un paso' in entry,'ready journal and next action rendered')
status,html=request(url,{'task':'backupAdvance','operation':operation,formtoken:'1'})
check('Backup action not confirmed' in html,'advance requires server confirmation')
entry=next(x for x in re.findall(r'<article class="nwm-backup".*?</article>',html,re.S) if operation in x)
check('estado: ready' in entry and 'siguiente paso: 0' in entry,'unconfirmed advance has no effects')
status,html=request(url,{'task':'backupStatus','operation':'invalid',formtoken:'1'})
check('Backup action not confirmed' in html,'invalid identity rejected without request')
if args.browser_output:
    from playwright.sync_api import sync_playwright
    args.browser_output.mkdir(parents=True,exist_ok=True)
    jar=next(h.cookiejar for h in op.handlers if hasattr(h,'cookiejar'))
    with sync_playwright() as pw:
        browser=pw.chromium.launch(channel='msedge',headless=True)
        context=browser.new_context(ignore_https_errors=parts.scheme=='https')
        context.add_cookies([{'name':x.name,'value':x.value,'domain':parts.hostname,'path':x.path,'secure':x.secure} for x in jar])
        page=context.new_page()
        page.route('**/*',lambda route: route.continue_() if urllib.parse.urlsplit(route.request.url).netloc==parts.netloc else route.abort())
        for width in [1440,390,320]:
            page.set_viewport_size({'width':width,'height':1000})
            page.goto(url);page.wait_for_load_state('networkidle')
            check(page.evaluate("getComputedStyle(document.body).fontFamily.includes('Roboto')"),str(width)+': native template CSS loaded')
            check(page.locator('.nwm-monitoring').evaluate("e=>getComputedStyle(e).backgroundColor==='rgb(255, 255, 255)'"),str(width)+': Central stylesheet loaded')
            page.locator('#nwm-maintenance > summary').click()
            section=page.get_by_role('region',name='Copias de seguridad')
            section.locator('summary').filter(has_text=label).click()
            card=page.locator('.nwm-backup').filter(has_text=operation)
            check(card.count()==1 and 'estado: ready' in card.inner_text(),str(width)+': browser journal visible')
            check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'),str(width)+': no page overflow')
            button=card.get_by_role('button',name='Avanzar un paso')
            button.click()
            check(card.locator('input[name=confirmed]').first.evaluate('(e)=>!e.checkValidity()'),str(width)+': browser requires confirmation')
            check('estado: ready' in card.inner_text(),str(width)+': unchecked action leaves operation ready')
            section.screenshot(path=str(args.browser_output / ('backups-'+str(width)+'.png')))
        browser.close()
status,html=request(url,{'task':'remove','id':rowid,csrf(html):'1'})
check(status==200 and label not in html,'real removal form accepted')
check(operation not in html,'site removal also removes operation journal')
print(json.dumps({'passed':len(checks),'checks':checks},indent=2))
