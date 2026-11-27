"""Native connector package on a DISPOSABLE Joomla with no Central or Nicode extensions.

Tests real missing PHP extensions, not mocked extension_loaded calls. Does not alter php.ini.
"""
import argparse,json,os,re,subprocess
from pathlib import Path

p=argparse.ArgumentParser(description=__doc__)
for name in ['site','php','ini','package','output']:p.add_argument('--'+name,type=Path,required=True)
p.add_argument('--allow-disposable-writes',action='store_true',required=True)
a=p.parse_args();a.output.mkdir(parents=True,exist_ok=True);checks=[]
def check(ok,name):
    if not ok:raise AssertionError(name)
    checks.append(name)
base=a.ini.read_text(encoding='utf-8');profiles={'available':a.ini.resolve()}
for mode,disabled in [('no-sodium',['sodium']),('no-curl',['curl']),('no-both',['sodium','curl'])]:
    text=base
    for extension in disabled:
        text=re.sub(r'(?mi)^(\s*)extension\s*=\s*["\']?(?:php_)?'+extension+r'(?:\.dll)?["\']?\s*$',r'\1; disabled by disposable fixture',text)
    target=a.output/(mode+'.ini');target.write_text(text,encoding='utf-8');profiles[mode]=target.resolve()

def step(action='inspect',mode='available',language='en-GB'):
    env=os.environ.copy();env['PHPRC']=str(profiles[mode])
    r=subprocess.run([str(a.php),str(Path(__file__).with_name('connector_install_step.php')),str(a.site.resolve()),'--allow-disposable-writes'],
        input=json.dumps({'action':action,'zip':str(a.package.resolve()),'language':language}),capture_output=True,text=True,encoding='utf-8',errors='replace',timeout=60,env=env)
    if r.returncode or '\nNWM_RESULT ' not in r.stdout:
        (a.output/'failure.log').write_text(r.stdout+'\n'+r.stderr,encoding='utf-8');raise RuntimeError('Native installer probe failed; see private failure.log')
    return json.loads(r.stdout.rsplit('\nNWM_RESULT ',1)[1])

initial=step();check(not initial['central_installed'] and initial['extensions']==[],'start with no Central, Connector or endpoint engine')
check(initial['sodium'] and initial['curl'],'complete runtime has Sodium and cURL')
for mode,extensions in [('no-sodium',['Sodium']),('no-curl',['cURL']),('no-both',['Sodium','cURL'])]:
    state=step('install',mode,'es-ES');messages=json.dumps(state['messages'],ensure_ascii=False)
    check(not state['ok'] and all(extension in messages for extension in extensions),mode+': exact missing requirements reported')
    check('no se puede instalar' in messages and state['joomla'] in messages and state['php'] in messages,mode+': Spanish diagnostic includes actual runtime versions')
    check(state['extensions']==[] and not state['central_installed'],mode+': rejected package installs no children')
    check(state['sodium']==(mode=='no-curl') and state['curl']==(mode=='no-sodium'),mode+': missing extension is real in the installer process')

installed=step('install');check(installed['ok'] and len(installed['extensions'])==3,'complete package installs standalone on clean Joomla')
check(not installed['central_installed'],'connector never needs Central installed')
owner=next(row['extension_id'] for row in installed['extensions'] if row['type']=='package')
check(all(int(row['package_id'])==int(owner) for row in installed['extensions'] if row['type']!='package'),'both children belong to their connector package')
step('seed');saved=step()['extensions'];reinstalled=step('install')
check(reinstalled['ok'] and reinstalled['extensions']==saved,'reinstall preserves component policy, engine settings and package identity')
for mode in ['no-sodium','no-curl']:
    rejected=step('install',mode)
    check(not rejected['ok'] and rejected['extensions']==saved,mode+': rejected reinstall preserves every installed child')
removed=step('uninstall','no-both');check(removed['ok'] and removed['extensions']==[],'uninstall remains possible without either PHP extension')
for relative in ['administrator/components/com_nicodeconnector','plugins/system/nicodewebmonitor']:
    check(not (a.site/relative).exists(),'native uninstall removes '+relative)
installed=step('install');check(installed['ok'] and len(installed['extensions'])==3,'clean reinstall after removal also succeeds')
report={'passed':len(checks),'checks':checks,'joomla':installed['joomla'],'php':installed['php'],'central_required':False}
(a.output/'report.json').write_text(json.dumps(report,indent=2),encoding='utf-8');print(json.dumps(report,indent=2))
