"""Integration test: enrolls and removes a temporary site via the real Joomla UI.
Requires an installed central component and a disposable administrator account.
No inventory requests leave the machine. Do not log input credentials.
"""
import argparse, sys, json, re, secrets, uuid, urllib.request, urllib.parse, urllib.error, http.cookiejar
from pathlib import Path
parser=argparse.ArgumentParser(description='Disposable loopback Joomla admin form checks. Pass admin_user/admin_password JSON on stdin; never use a real site.')
parser.add_argument('--base-url',required=True)
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
status,html=request(url,{**data,formtoken:'1'})
check(status==200 and label in html,'real enrollment form accepted')
check(credential not in html,'credential not echoed in HTML')
row=re.search(r'<tr><td>'+re.escape(label)+r'</td>.*?</tr>',html,re.S).group(0)
rowid=re.search(r'name="id" value="(\d+)"',row).group(1)
status,html=request(url,{'task':'remove','id':rowid,csrf(html):'1'})
check(status==200 and label not in html,'real removal form accepted')
print(json.dumps({'passed':len(checks),'checks':checks},indent=2))
