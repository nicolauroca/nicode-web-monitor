"""Integration test: central backup enrollment, CSRF, confirmation and journal over real Joomla HTTP.
Requires an installed central component and a disposable administrator account.
No inventory requests leave the machine. Do not log input credentials.
"""
import argparse, sys, json, re, secrets, uuid, urllib.request, urllib.parse, urllib.error, http.cookiejar
from pathlib import Path
parser=argparse.ArgumentParser(description='Disposable loopback Joomla admin form checks. Pass admin_user/admin_password JSON on stdin; never use a real site.')
parser.add_argument('--base-url',required=True)
parser.add_argument('--browser-output',type=Path)
parser.add_argument('--allow-disposable-writes',action='store_true',required=True)
args=parser.parse_args()
parts=urllib.parse.urlsplit(args.base_url)
if parts.scheme != 'http' or parts.hostname not in ['127.0.0.1','localhost','::1'] or parts.username or parts.password or parts.query or parts.fragment:
    raise SystemExit('Only a disposable loopback HTTP URL is accepted')
base=args.base_url.rstrip('/')+'/administrator/index.php'
op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
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
check(status==200 and 'Enroll a site' in html,'authenticated component dispatch')
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
status,html=request(url,{**data,formtoken:'1'})
check(status==200 and label in html,'real enrollment form accepted')
check(credential not in html,'credential not echoed in HTML')
row=re.search(r'<tr><td\b[^>]*>'+re.escape(label)+r'</td>.*?</tr>',html,re.S).group(0)
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
entry=next(x for x in ops if 'Site #'+rowid+';' in x)
operation=re.search(r'backup-[a-f0-9]{32}',entry).group()
check('state: ready' in entry and 'Advance backup step' in entry,'ready journal and next action rendered')
status,html=request(url,{'task':'backupAdvance','operation':operation,formtoken:'1'})
check('Backup action not confirmed' in html,'advance requires server confirmation')
entry=next(x for x in re.findall(r'<article class="nwm-backup".*?</article>',html,re.S) if operation in x)
check('state: ready' in entry and 'next sequence: 0' in entry,'unconfirmed advance has no effects')
status,html=request(url,{'task':'backupStatus','operation':'invalid',formtoken:'1'})
check('Backup action not confirmed' in html,'invalid identity rejected without request')
if args.browser_output:
    from playwright.sync_api import sync_playwright
    args.browser_output.mkdir(parents=True,exist_ok=True)
    jar=next(h.cookiejar for h in op.handlers if hasattr(h,'cookiejar'))
    with sync_playwright() as pw:
        browser=pw.chromium.launch(channel='msedge',headless=True)
        context=browser.new_context()
        context.add_cookies([{'name':x.name,'value':x.value,'domain':x.domain,'path':x.path} for x in jar])
        page=context.new_page()
        page.route('**/*',lambda route: route.continue_() if urllib.parse.urlsplit(route.request.url).netloc==parts.netloc else route.abort())
        for width in [1440,390,320]:
            page.set_viewport_size({'width':width,'height':1000})
            page.goto(url);page.wait_for_load_state('networkidle')
            section=page.get_by_role('region',name='Remote backups')
            section.locator('summary').filter(has_text=label).click()
            card=page.locator('.nwm-backup').filter(has_text=operation)
            check(card.count()==1 and 'state: ready' in card.inner_text(),str(width)+': browser journal visible')
            check(page.evaluate('document.documentElement.scrollWidth <= innerWidth'),str(width)+': no page overflow')
            button=card.get_by_role('button',name='Advance backup step')
            button.click()
            check(card.locator('input[name=confirmed]').evaluate('(e)=>!e.checkValidity()'),str(width)+': browser requires confirmation')
            check('state: ready' in card.inner_text(),str(width)+': unchecked action leaves operation ready')
            section.screenshot(path=str(args.browser_output / ('backups-'+str(width)+'.png')))
        browser.close()
status,html=request(url,{'task':'remove','id':rowid,csrf(html):'1'})
check(status==200 and label not in html,'real removal form accepted')
check(operation not in html,'site removal also removes operation journal')
print(json.dumps({'passed':len(checks),'checks':checks},indent=2))
