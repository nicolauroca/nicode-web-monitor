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
check(status==200 and 'Añadir sitio' in html,'authenticated simple onboarding')
formtoken=csrf(html)
status,_=request(url,{'task':'issueCode','label':'Denied'})
check(status==403,'code issuance without CSRF rejected')
status,_=request(url+'&task=issueCode')
check(status==403,'GET code issuance rejected')
for task in ['add','pair']:
    status,_=request(url,{'task':task,formtoken:'1'})
    check(status==404,'obsolete manual task '+task+' removed')
status,_=request(url,{'task':'notificationRetry','id':-1,formtoken:'1'})
check(status==403,'notification retry requires explicit confirmation')
status,_=request(url+'&task=inspect&id=1')
check(status==403,'GET inventory rejected')
status,_=request(url,{'task':'inspect','id':'1'})
check(status==403,'inventory without CSRF rejected')
status,html=request(url,{'task':'inspect','id':'-1',formtoken:'1'})
check(status==200 and 'site_not_found' in html,'inventory result rendered through real dispatcher')
check('name="address"' not in html and 'name="credential"' not in html,'manual enrollment fields removed')
print(json.dumps({'passed':len(checks),'checks':checks},indent=2))
