<?php
/** Current package lifecycle; no historical-release compatibility contract. */
if (($argv[3]??'')!=='--allow-disposable-writes') exit(2);
$_SERVER['HTTP_HOST']='localhost'; $_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['REQUEST_URI']='/index.php';
define('_JEXEC',1); define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
$c=Factory::getContainer();$c->alias('session','session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
$app=$c->get(Joomla\CMS\Application\ConsoleApplication::class);Factory::$application=$app;$app->createExtensionNamespaceMap();
$db=$c->get(DatabaseInterface::class);$output=realpath($argv[2]);
if (!$output || (int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_sites')->loadResult()
    || (int)$db->setQuery("SELECT COUNT(*) FROM #__scheduler_tasks WHERE type='nicode.monitor'")->loadResult()) throw new RuntimeException('Empty disposable installation/output required');
$checks=[];
function check($ok,$name){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
function step(array $request):void{
    $p=proc_open([PHP_BINARY,__DIR__.'/lifecycle_step.php',JPATH_BASE,'--allow-disposable-writes'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode($request,JSON_THROW_ON_ERROR));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($p)!==0 || !str_contains($out,'"ok":true'))throw new RuntimeException('Native lifecycle step failed: '.substr($out.$err,0,300));
}
function installPackage(string $name):void { step(['action'=>'install','zip'=>realpath(__DIR__.'/../dist/pkg_'.$name.'-1.1.1-dev.8.zip')]); }
function removePackage(string $name):void{
    global $db;$id=(int)$db->setQuery("SELECT extension_id FROM #__extensions WHERE type='package' AND element=".$db->quote('pkg_'.$name))->loadResult();
    if(!$id)throw new RuntimeException('Missing package');step(['action'=>'uninstall','type'=>'package','id'=>$id]);
}
installPackage('nicodemonitor');installPackage('nicodeconnector');
$owners=$db->setQuery("SELECT element,package_id FROM #__extensions WHERE type='component' AND element IN ('com_nicodewebmonitor','com_nicodeconnector')")->loadAssocList('element');
check(count($owners)===2 && $owners['com_nicodewebmonitor']['package_id']!==$owners['com_nicodeconnector']['package_id'],'two components have independent package owners');
check((int)$db->setQuery("SELECT COUNT(*) FROM #__extensions WHERE type='component' AND element LIKE 'com_nicode%'")->loadResult()===2,'display names do not create a third component identity');
$pluginId=(int)$db->setQuery("SELECT extension_id FROM #__extensions WHERE type='plugin' AND folder='system' AND element='nicodewebmonitor'")->loadResult();
$hash=hash('sha256',bin2hex(random_bytes(32)));
$db->setQuery('UPDATE #__extensions SET enabled=1,params='.$db->quote(json_encode(['site_id'=>'c3c6d3e1-f65b-4000-8000-123456789abc','token_hash'=>$hash])).' WHERE extension_id='.$pluginId)->execute();
installPackage('nicodeconnector');
check(str_contains($db->setQuery('SELECT params FROM #__extensions WHERE extension_id='.$pluginId)->loadResult(),$hash),'current-package reinstall preserves connector configuration');
$site=(object)['site_id'=>'c3c6d3e1-f65b-4000-8000-123456789abc','label'=>'Lifecycle retained','base_url'=>'https://example.org','address'=>'93.184.216.34','credential'=>'fixture-unusable'];$db->insertObject('#__nwm_sites',$site,'id');
$task=(object)['title'=>'Disposable lifecycle','type'=>'nicode.monitor','state'=>1,'created'=>gmdate('Y-m-d H:i:s'),'created_by'=>0,'params'=>'{}','execution_rules'=>'{}','cron_rules'=>'{}'];$db->insertObject('#__scheduler_tasks',$task,'id');
removePackage('nicodemonitor');
check((int)$db->setQuery('SELECT state FROM #__scheduler_tasks WHERE id='.(int)$task->id)->loadResult()===0,'central uninstall pauses monitoring tasks');
check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_sites WHERE id='.(int)$site->id)->loadResult()===1,'central uninstall retains registry data');
check((int)$db->setQuery("SELECT COUNT(*) FROM #__extensions WHERE type='component' AND element='com_nicodeconnector'")->loadResult()===1,'central uninstall leaves connector installed');
check(str_contains($db->setQuery('SELECT params FROM #__extensions WHERE extension_id='.$pluginId)->loadResult(),$hash),'central uninstall preserves connector access for another central');
installPackage('nicodemonitor');
check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_sites WHERE id='.(int)$site->id)->loadResult()===1,'central reinstall recovers retained registry');
removePackage('nicodeconnector');
check((int)$db->setQuery("SELECT COUNT(*) FROM #__extensions WHERE element='com_nicodewebmonitor' AND type='component'")->loadResult()===1,'connector uninstall leaves central installed');
check((int)$db->setQuery("SELECT COUNT(*) FROM #__extensions WHERE type='plugin' AND folder='system' AND element='nicodewebmonitor'")->loadResult()===0,'connector uninstall removes its endpoint engine');
installPackage('nicodeconnector');
$db->setQuery('DELETE FROM #__nwm_sites WHERE id='.(int)$site->id)->execute();
$db->setQuery('DELETE FROM #__scheduler_tasks WHERE id='.(int)$task->id)->execute();
file_put_contents($output.'/lifecycle.json',json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT));
echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT),"\n";
