"""Package: Nicode.joomla.webmonitor. Runner subprocess crash/recovery and exclusion contract."""
import argparse
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import time

p=argparse.ArgumentParser();p.add_argument('--php',required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args()
repo=Path(__file__).resolve().parents[1];checks=[]
def check(ok,name):
    if not ok: raise AssertionError(name)
    checks.append(name)
with tempfile.TemporaryDirectory(prefix='nwm runner ') as tmp:
    base=Path(tmp)
    shutil.copy2(repo/'tools/core_runner.php',base/'core_runner.php')
    fixture=json.dumps((repo/'tests/core_dispatch.php').as_posix())
    (base/'core_runner_state.php').write_text('<?php $argv[2]="discovery"; require '+fixture+';')
    (base/'core_update_execute.php').write_text('''<?php
        $argv[2]=$argv[3]==='--reconcile'?'reconcile':(is_file($argv[1].'/crash')?'crash':'execute');
        require '''+fixture+';')
    def setup(name):
        b=base/name;b.mkdir()
        for sub in ['site','gate','updates/core/dispatch-test-259/journal','updates/core/dispatch-test-259/snapshot']:
            (b/sub).mkdir(parents=True,exist_ok=True)
        plan={'extension_id':247,'from':'6.1.3','to':'6.1.4','package_sha256':'a'*64}
        (b/'updates/core/dispatch-test-259/permit.json').write_text(json.dumps({'operation':'dispatch-test-259','kind':'core',
            'writers_stopped':True,'recovery_verified':True,'expires_at':int(time.time())+900,
            'root_sha256':hashlib.sha256((b/'site').resolve().as_posix().lower().encode()).hexdigest(),'plan':plan}))
        (b/'state.json').write_text(json.dumps({'extension_id':247,'runtime_version':'6.1.3','manifest_version':'6.1.3',
            'database_version':'6.1.3','schema_errors':0,'offline':True}))
        return b
    def run(b,mode=None):
        args=[a.php,str(base/'core_runner.php'),str(b),'--run-queued'] if mode is None else [a.php,str(repo/'tests/core_dispatch.php'),str(b),mode]
        r=subprocess.run(args,capture_output=True,text=True,timeout=30)
        return r.returncode,json.loads(r.stdout) if r.stdout else {}
    b=setup('normal')
    check(run(b)[1]['status']=='idle' and not (b/'calls').exists(),'prepared but unqueued operation is never installed')
    run(b,'queue');check(run(b)[1]['status']=='verified' and (b/'calls').read_text()=='1','one host invocation executes and freshly verifies')
    check(run(b)[1]['status']=='idle' and (b/'calls').read_text()=='1','completed operation never runs again')
    b=setup('crashed');(b/'crash').touch();run(b,'queue')
    check(run(b)[1]['status']=='verified' and (b/'calls').read_text()=='1','abnormal installer child exit reconciles effects without rerun')
    b=setup('uncertain');run(b,'queue');run(b,'crash')
    state=json.loads((b/'state.json').read_text());state['schema_errors']=1;(b/'state.json').write_text(json.dumps(state))
    check(run(b)[1]['status']=='uncertain' and (b/'gate/owner.json').exists() and (b/'calls').read_text()=='1','schema drift retains ownership without installation retry')
    state['schema_errors']=0;(b/'state.json').write_text(json.dumps(state))
    check(run(b)[1]['status']=='verified' and (b/'calls').read_text()=='1','later host invocation reconciles existing effects only')
    b=setup('backup');run(b,'backup')
    check(run(b)[1]['status']=='blocked' and not (b/'calls').exists(),'partial backup is not stolen by core runner')
    b=setup('expired');run(b,'queue');f=b/'updates/core/dispatch-test-259/permit.json';d=json.loads(f.read_text());d['expires_at']=0;f.write_text(json.dumps(d))
    check(run(b)[0]!=0 and (b/'gate/owner.json').exists() and not (b/'calls').exists(),'expired queued permit preserves gate without effects')
    b=setup('torn');(b/'gate/owner.json').write_text('{')
    check(run(b)[0]!=0 and not (b/'calls').exists(),'malformed ownership fails closed')
a.output.write_text(json.dumps({'passed':len(checks),'checks':checks},indent=2));print(json.dumps({'passed':len(checks)}))
