"""Package: Nicode.joomla.webmonitor. Guided form in disposable loopback Joomla; credentials on stdin."""
import argparse, hashlib, json, re, sys
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
p=argparse.ArgumentParser();p.add_argument('--base-url',required=True);p.add_argument('--allow-disposable-writes',action='store_true',required=True)
a=p.parse_args();parts=urlsplit(a.base_url)
assert parts.scheme=='http' and parts.hostname in ['127.0.0.1','localhost'] and not parts.username and not parts.query
c=json.load(sys.stdin);checks=[]
with sync_playwright() as pw:
    browser=pw.chromium.launch(channel='msedge',headless=True)
    page=browser.new_page(viewport={'width':1440,'height':1000});errors=[]
    page.on('pageerror',lambda error:errors.append(str(error)))
    page.route('**/*',lambda route:route.continue_() if urlsplit(route.request.url).netloc==parts.netloc else route.abort())
    base=a.base_url.rstrip('/')+'/administrator/'
    page.goto(base);page.locator('#mod-login-username').fill(c['admin_user']);page.locator('#mod-login-password').fill(c['admin_password'])
    page.locator('button[type=submit]').click();page.wait_for_load_state('networkidle')
    if page.get_by_role('button',name='Hide Forever',exact=True).count():page.get_by_role('button',name='Hide Forever',exact=True).click()
    for width in [1440,390]:
        page.set_viewport_size({'width':width,'height':1000})
        page.goto(base+'index.php?option=com_nicodewebmonitor');page.wait_for_load_state('networkidle')
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth'),width
        page.locator('#nwm-generate').click()
        page.wait_for_function("document.getElementById('nwm-digest').value.length === 64")
        token=page.locator('#nwm-credential').input_value();uuid=page.locator('#nwm-site_id').input_value()
        assert re.fullmatch('[a-f0-9]{64}',token)
        assert re.fullmatch('[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}',uuid)
        assert page.locator('#nwm-digest').input_value()==hashlib.sha256(token.encode()).hexdigest()
        assert page.locator('#nwm-credential').get_attribute('type')=='password'
        assert page.locator('#nwm-pairing input[name=task]').input_value()=='pair'
        page.locator('#nwm-generate').click();assert page.locator('#nwm-credential').input_value()==token
        checks.append(f'{width}: visible form, no overflow, secure generation/digest, masked credential, pair action, no overwrite')
    assert not errors,errors
    browser.close()
print(json.dumps({'passed':len(checks),'checks':checks,'browser_errors':0}))
