<?php
/** @package Nicode.joomla.webmonitor. Native install/upgrade/removal on a clean disposable Joomla. */
if (($argv[4]??'')!=='--allow-disposable-writes')exit(2);
define('_JEXEC',1);define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php';require JPATH_BASE.'/includes/framework.php';
use Joomla\CMS\Factory;use Joomla\CMS\Installer\Installer;use Joomla\CMS\Installer\InstallerHelper;use Joomla\Database\DatabaseInterface;
$c=Factory::getContainer();$c->alias('session','session.cli')->alias(\Joomla\Session\SessionInterface::class,'session.cli');
Factory::$application=$c->get(\Joomla\CMS\Application\ConsoleApplication::class);Factory::$application->createExtensionNamespaceMap();
$db=$c->get(DatabaseInterface::class);$output=realpath($argv[2]);$old=realpath($argv[3]);$checks=[];
if(!$output||!$old)throw new RuntimeException('Output and baseline ZIP required');
function check($ok,$name){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
function step($data){
    $p=proc_open([PHP_BINARY,__DIR__.'/lifecycle_step.php',JPATH_BASE,'--allow-disposable-writes'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode($data));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($p)!==0)throw new RuntimeException('Native step failed: '.$err.' '.$out);
    check(json_decode($out,true)['ok']===true,'native '.$data['action']);
}
function install($zip){step(['action'=>'install','zip'=>$zip]);}
function ids(){global $db;return $db->setQuery("SELECT extension_id,type,element,folder FROM #__extensions WHERE element IN ('com_nicodewebmonitor','nicodewebmonitor') ORDER BY extension_id")->loadAssocList();}
function tables(){global $db;return array_values(array_filter($db->getTableList(),fn($t)=>str_starts_with($t,$db->getPrefix().'nwm_')));}
function uninstallAll(){global $db;foreach(array_reverse(ids()) as $r){step(['action'=>'uninstall','type'=>$r['type'],'id'=>(int)$r['extension_id']]);}check(ids()===[],'all extension registrations removed');foreach(['administrator/components/com_nicodewebmonitor','plugins/system/nicodewebmonitor','plugins/console/nicodewebmonitor','plugins/task/nicodewebmonitor'] as $p)check(!is_dir(JPATH_BASE.'/'.$p),'files removed '.$p);}
check(ids()===[] && tables()===[],'clean product baseline');
$dist=__DIR__.'/../dist';$component=$dist.'/com_nicodewebmonitor-0.8.0-dev.zip';
foreach(glob($dist.'/*.zip') as $zip)install($zip);
check(count(ids())===4,'all four extensions registered');check(count(tables())===8,'fresh install creates all eight tables');
$freshTables=tables();uninstallAll();check(tables()===$freshTables,'uninstall retains data tables as documented');
// Only the eight empty tables created by this test, in an explicitly disposable target.
foreach($freshTables as $t){check((int)$db->setQuery('SELECT COUNT(*) FROM '.$db->quoteName($t))->loadResult()===0,'fresh table empty');$db->setQuery('DROP TABLE '.$db->quoteName($t))->execute();}
install($old);$prior=ids();check(count($prior)===1,'old component baseline installed');
$db->setQuery("INSERT INTO #__nwm_sites (site_id,label,base_url,address,credential) VALUES ('12345678-1234-4123-8123-123456789012','Lifecycle QA','https://example.invalid','192.0.2.1','synthetic-not-a-secret')")->execute();
$row=$db->setQuery('SELECT * FROM #__nwm_sites')->loadAssocList();
install($component);check(ids()===$prior,'component identity survives upgrade');
check($db->setQuery('SELECT * FROM #__nwm_sites')->loadAssocList()===$row,'upgrade preserves registration bytes');
check(count(tables())===8,'upgrade creates new maintenance tables');
check($db->setQuery('SELECT version_id FROM #__schemas WHERE extension_id='.(int)$prior[0]['extension_id'])->loadResult()==='0.8.0','native schema version advanced');
install($component);check($db->setQuery('SELECT * FROM #__nwm_sites')->loadAssocList()===$row,'same version reinstall preserves data');
uninstallAll();check($db->setQuery('SELECT * FROM #__nwm_sites')->loadAssocList()===$row,'uninstall preserves registration');
install($component);check($db->setQuery('SELECT * FROM #__nwm_sites')->loadAssocList()===$row,'reinstall reconnects retained data');
check(is_file(JPATH_BASE.'/administrator/components/com_nicodewebmonitor/tmpl/backup-runner.js'),'new browser asset installed');
uninstallAll();
file_put_contents($output.'/lifecycle.json',json_encode(['passed'=>count($checks),'checks'=>$checks,'retained_tables'=>count(tables()),'production'=>false],JSON_PRETTY_PRINT));
echo json_encode(['passed'=>count($checks),'fresh_install'=>true,'upgrade'=>true,'uninstall'=>true,'retention'=>true])."\n";
