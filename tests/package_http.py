"""Package: Nicode.joomla.webmonitor. Compound panel/TLS acceptance in disposable Joomla.
Uses the shared update HTTP harness setup and cleanup; requires --package-fixtures DIR.
"""
import sys
from pathlib import Path
index=sys.argv.index('--package-fixtures')
archives=Path(sys.argv[index+1]).resolve()
del sys.argv[index:index+2]
# Reuse setup helpers without running the single-extension scenario.
source=Path(__file__).with_name('update_http.py').read_text()
setup,scenario=source.split("try:\n    remote=fixture('setup'",1)
cleanup=scenario[scenario.rindex('\nfinally:\n')+len('\nfinally:\n'):]
exec(compile(setup,__file__,'exec'))

def compound(action,*args):
    result=subprocess.run([str(a.php),str(repo/'tests/package_prepare_native.php'),str(site),action,
        '--allow-disposable-writes',*map(str,args)],capture_output=True,text=True,timeout=60)
    if result.returncode: raise RuntimeError('Compound fixture failed: '+action)
    return json.loads(result.stdout)

def prepare_compound(op,version):
    result=subprocess.run([str(a.php),str(repo/'tools/prepare_package_update.php'),str(site),str(store),
        str(archives/('package-'+version+'.zip')),op,'--writers-stopped-and-recovery-verified'],capture_output=True,text=True,timeout=60)
    check(result.returncode==0,'compound permit prepared locally')

config=site/'configuration.php'; original_config=config.read_bytes(); mode=config.stat().st_mode
created=False
try:
    config.chmod(0o600)
    changed,n=re.subn(rb'public \$offline = (?:false|true|0|1);',b'public $offline = true;',original_config)
    check(n==1,'disposable remote placed offline');config.write_bytes(changed)
    remote=fixture('setup',package=str(v1),params=params)
    baseline=compound('setup',archives/'package-1.0.0.zip');created=True
    check(request(credential=inventory)[0]==401,'inventory credential denied')
    check(request(credential=backup)[0]==401,'backup credential denied')
    check(request(tls=False)[0]==403,'plain HTTP denied')
    check(request()[0]==404,'unknown status read only')
    replacement='(new UpdateClient('+json.dumps(str(a.certificate.resolve()).replace('\\','/'))+", ['127.0.0.1']))"
    path.write_bytes(original.replace(b'(new UpdateClient())',replacement.encode()))
    roledata=roles('setup',site_id=site_id,url=f'https://localhost:{secure.server_port}',backup_token=backup,update_token=token)
    for role in ['reader','backup_operator']:
        call,csrf,html=session(role)
        check('Prepared extension updates' not in html,'update forms denied to '+role)
        check(call({'task':'updateExecute','operation':'compound-http-01','confirmed':'1',csrf:1})[0]==403,'update ACL denies '+role)
    call,csrf,html=session('operator')
    def panel(task,op='compound-http-01',confirm='1'):
        return call({'task':task,'id':roledata['site'],'operation':op,'confirmed':confirm,csrf:1})
    prepare_compound('compound-http-01','1.1.0')
    check(call({'task':'updateBegin','id':roledata['site'],'operation':'compound-http-01','confirmed':'1'})[0]==403,'CSRF required')
    check('state: ready' in panel('updateBegin')[1],'prepared compound registered')
    count=post_effects;panel('updateExecute',confirm='0')
    check(post_effects==count and compound('inspect')==baseline,'missing confirmation has no effects')
    drop=True;code,html=panel('updateExecute')
    check('state: uncertain' in html,'lost response leaves central uncertain')
    current=compound('inspect')
    check(all(row['version']=='1.1.0' for row in current['parents']+current['children']),'native parent and children upgraded')
    count=post_effects;panel('updateExecute');check(post_effects==count,'uncertain panel cannot resend')
    code,html=panel('updateStatus')
    (store/'compound-panel.html').write_text(html,encoding='utf-8')
    check('state: updated' in html and 'Package and child observations' in html,'GET reconciles and panel displays compound detail')
    receipt=request('compound-http-01')[1]
    check(receipt['compound']['before']==baseline and receipt['compound']['observed']==current,'transport preserves complete observations')
    old=next(x['id'] for x in baseline['children'] if x['identity']['type']=='library')
    new=next(x['id'] for x in current['children'] if x['identity']['type']=='library')
    check(old!=new and f'ID {old};' in html and f'ID {new};' in html,'replacement IDs rendered honestly')
    check(not (store/'maintenance/owner.json').exists(),'successful compound releases common gate')
    prepare_compound('compound-http-02','1.2.0');panel('updateBegin','compound-http-02')
    code,html=panel('updateExecute','compound-http-02')
    partial=request('compound-http-02')[1]
    check(partial['status']=='uncertain' and partial['compound']['observed']==compound('inspect'),'partial child failure has exact observations')
    check((store/'maintenance/owner.json').exists(),'partial update retains common gate')
    count=post_effects;panel('updateExecute','compound-http-02');check(count==post_effects,'partial result cannot resend')
    check(request('compound-http-02',True)[1]==partial,'remote duplicate returns partial receipt without reinstall')
    check(compound('inspect')==partial['compound']['observed'],'duplicate has no effects')
    check('state: uncertain' in panel('updateStatus','compound-http-02')[1],'partial remains uncertain after status read')
    check(str(store) not in json.dumps(partial) and token not in json.dumps(partial),'no path or credential in receipt')
    (store/'receipts.json').write_text(json.dumps({'success':receipt,'partial':partial},indent=2))
    (store/'result.json').write_text(json.dumps({'passed':len(checks),'checks':checks},indent=2))
    print(json.dumps({'passed':len(checks)}))
finally:
    try:
        if created: compound('cleanup')
    finally:
        config.write_bytes(original_config);config.chmod(mode)
        exec(compile('\n'.join(line[4:] for line in cleanup.splitlines()),__file__,'exec'))
