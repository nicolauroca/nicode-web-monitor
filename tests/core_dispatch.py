"""Package: Nicode.joomla.webmonitor. Durable queue, cross-operation exclusion and crash tests."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile
import time

p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args()
fixture=Path(__file__).with_suffix('.php');checks=[]
def check(ok,name):
    if not ok: raise AssertionError(name)
    checks.append(name)
with tempfile.TemporaryDirectory(prefix='nwm-core-dispatch-') as temp:
    base=Path(temp)
    def setup(name):
        b=base/name;b.mkdir()
        for child in ['site','gate','updates/core/dispatch-test-259/journal','updates/core/dispatch-test-259/snapshot']:
            (b/child).mkdir(parents=True,exist_ok=True)
        plan={'extension_id':247,'from':'6.1.3','to':'6.1.4','package_sha256':'a'*64}
        permit={'operation':'dispatch-test-259','kind':'core','writers_stopped':True,'recovery_verified':True,'expires_at':int(time.time())+900,
            'root_sha256':hashlib.sha256((b/'site').resolve().as_posix().lower().encode()).hexdigest(),'plan':plan}
        (b/'updates/core/dispatch-test-259/permit.json').write_text(json.dumps(permit))
        (b/'state.json').write_text(json.dumps({'extension_id':247,'runtime_version':'6.1.3','manifest_version':'6.1.3','database_version':'6.1.3','schema_errors':0,'offline':True}))
        return b
    def run(b,mode):
        r=subprocess.run([a.php,str(fixture),str(b),mode],capture_output=True,text=True,timeout=15)
        return r.returncode,json.loads(r.stdout) if r.stdout else {}
    b=setup('main')
    check(run(b,'status')[0]==4,'unrequested operation has no status')
    check(run(b,'execute')[0]==4 and not (b/'gate/owner.json').exists(),'CLI cannot execute unqueued permit or reserve gate')
    check(run(b,'online')[0]==4 and not (b/'calls').exists(),'online dispatch refused without effects')
    check(run(b,'queue')[1]['status']=='queued' and not (b/'calls').exists(),'queue never invokes native installer')
    before=(b/'updates/core/dispatch-test-259/queued.json').read_bytes()
    check(run(b,'queue')[1]['status']=='queued' and before==(b/'updates/core/dispatch-test-259/queued.json').read_bytes(),'duplicate dispatch preserves receipt')
    check(run(b,'backup')[0]==4 and run(b,'extension')[0]==4,'queued core excludes both backup and extension')
    check(run(b,'crash')[0]==73 and (b/'calls').read_text()=='1','process crash after actual simulated effect')
    check(run(b,'execute')[1]['status']=='uncertain' and (b/'calls').read_text()=='1','fresh execute does not reinstall after crash')
    check(run(b,'backup')[0]==4,'crash retains maintenance exclusion')
    s=json.loads((b/'state.json').read_text());s['schema_errors']=1;(b/'state.json').write_text(json.dumps(s))
    check(run(b,'reconcile')[1]['status']=='uncertain' and run(b,'extension')[0]==4,'schema mismatch remains uncertain and exclusive')
    s['schema_errors']=0;(b/'state.json').write_text(json.dumps(s))
    check(run(b,'reconcile')[1]['status']=='verified' and not (b/'gate/owner.json').exists(),'fresh reconciliation verifies and releases gate')
    check(run(b,'execute')[1]['status']=='verified' and (b/'calls').read_text()=='1','verified replay never reinstalls')
    check(run(b,'extension')[1]['status']=='updated','extension allowed after verified core')
    b=setup('backup')
    check(run(b,'backup')[1]['status']=='files' and run(b,'queue')[0]==4,'partial backup excludes core between HTTP steps')
    check(run(b,'backup-complete')[1]['status']=='complete' and run(b,'queue')[1]['status']=='queued','completed backup releases core dispatch')
    b=setup('expired');path=b/'updates/core/dispatch-test-259/permit.json';d=json.loads(path.read_text());d['expires_at']=int(time.time())-1;path.write_text(json.dumps(d))
    check(run(b,'queue')[0]==4 and not (b/'gate/owner.json').exists(),'expired permit cannot reserve gate')
    b=setup('wrong-site');path=b/'updates/core/dispatch-test-259/permit.json';d=json.loads(path.read_text());d['root_sha256']='b'*64;path.write_text(json.dumps(d))
    check(run(b,'queue')[0]==4,'permit cannot move to a different installation')
    b=setup('corrupt-gate');(b/'gate/owner.json').write_text('')
    check(run(b,'queue')[0]==4 and not (b/'calls').exists(),'torn gate record fails closed')
    b=setup('concurrent')
    holder=subprocess.Popen([a.php,str(fixture),str(b),'hold'],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    try:
        deadline=time.monotonic()+10
        while not (b/'locked').exists() and time.monotonic()<deadline:time.sleep(.02)
        check((b/'locked').exists() and run(b,'backup-complete')[0]==4 and run(b,'queue')[0]==4,'OS lock excludes simultaneous calls including the same owner')
    finally:
        out,err=holder.communicate('\n',timeout=10)
    check(holder.returncode==0 and run(b,'queue')[1]['status']=='queued','concurrent owner completes and releases without takeover')
a.output.write_text(json.dumps({'passed':len(checks),'checks':checks},indent=2))
print(json.dumps({'passed':len(checks)}))
