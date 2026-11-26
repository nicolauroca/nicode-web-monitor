<?php
/** Full disclosure contract against an explicitly disposable Joomla. No outbound I/O. */
if(($argv[2]??'')!=='--allow-disposable-writes')exit(2);
$_SERVER['HTTP_HOST']='localhost';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['REQUEST_URI']='/index.php';
define('_JEXEC',1);define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php';require JPATH_BASE.'/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Nicode\Component\NicodeConnector\Administrator\Service\Connection;
use Nicode\Component\NicodeConnector\Administrator\Service\CredentialVault as ConnectorVault;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\SiteRegistry;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\PairingService;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\InventorySchema;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\InventoryStore;
use Nicode\Plugin\System\NicodeWebMonitor\Inventory\Collector;
$c=Factory::getContainer();$c->alias('session','session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
$app=$c->get(Joomla\CMS\Application\ConsoleApplication::class);Factory::$application=$app;$app->createExtensionNamespaceMap();
$app->bootComponent('com_nicodeconnector');$app->bootComponent('com_nicodewebmonitor');$app->bootPlugin('nicodewebmonitor','system');
$db=$c->get(DatabaseInterface::class);$checks=[];$site=null;
function check($ok,$name){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
function rejects($fn,$name){try{$fn();}catch(Throwable){check(true,$name);return;}throw new RuntimeException($name);}
check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_sites')->loadResult()===0,'empty disposable registry');
$snapshot=$db->setQuery("SELECT extension_id,params,enabled FROM #__extensions WHERE element IN ('com_nicodeconnector','nicodewebmonitor')")->loadAssocList();
$admin=new User((int)$db->setQuery('SELECT user_id FROM #__user_usergroup_map WHERE group_id=8')->loadResult());
$connection=new Connection($db,new ConnectorVault($app->get('secret')));
$registry=new SiteRegistry($db,new CredentialVault($app->get('secret')),$admin);
$all=array_fill_keys(array_keys(InventorySchema::categories()),true);
try{
    check(count($all)===13 && count(array_filter(InventorySchema::policy()))===4,'only four essential categories enabled initially');
    $before=$db->setQuery("SELECT params FROM #__extensions WHERE element='com_nicodeconnector'")->loadResult();
    $reader=new class extends User{public function authorise($action,$asset=null){return $action==='core.manage';}};
    $reader->guest=false;$reader->block=0;$reader->id=$admin->id;
    rejects(fn()=>$connection->saveSharing(new User(),$all),'guest cannot change disclosure');
    rejects(fn()=>$connection->saveSharing($reader,$all),'reader cannot change disclosure');
    $blocked=clone $admin;$blocked->block=1;
    rejects(fn()=>$connection->saveSharing($blocked,$all),'blocked administrator cannot change disclosure');
    rejects(fn()=>$connection->saveSharing($admin,['users'=>['bad']]),'nested policy value rejected');
    rejects(fn()=>$connection->saveSharing($admin,['invented'=>1]),'unknown category rejected');
    check($before===$db->setQuery("SELECT params FROM #__extensions WHERE element='com_nicodeconnector'")->loadResult(),'rejected disclosure saves have no effects');
    $invalid=json_decode($before,true)?:[];$invalid['inventory_sharing']=null;
    $db->setQuery('UPDATE #__extensions SET params='.$db->quote(json_encode($invalid))." WHERE type='component' AND element='com_nicodeconnector'")->execute();
    check(count(array_filter($connection->sharing()))===0,'explicit null policy is not interpreted as default permission');
    check(count(array_filter(Collector::forSite($db,JVERSION)->collect()['sections'],fn($s)=>$s['status']==='denied'))===13,'invalid saved policy fails closed in the engine');
    $connection->saveSharing($admin,[]);
    $denied=Collector::forSite($db,JVERSION)->collect();
    check($denied['status']==='complete'&&count(array_filter($denied['sections'],fn($s)=>$s['status']==='denied'&&$s['data']===null))===13,'deny-all returns only metadata without treating privacy as failure');
    $pairing=new PairingService($db,new CredentialVault($app->get('secret')));
    $connection->connect('https://privacy.example.invalid',$pairing->issue($admin,'Disclosure self fixture'),'https://privacy.example.invalid');
    $row=$db->setQuery('SELECT * FROM #__nwm_sites')->loadAssoc();$site=(int)$row['id'];
    check($connection->state()['connection_type']==='local' && $registry->latest($site)['ok'],'deny-all still permits authenticated self pairing and bounded storage');
    $pluginBefore=$db->setQuery("SELECT params FROM #__extensions WHERE type='plugin' AND folder='system' AND element='nicodewebmonitor'")->loadResult();
    $connection->saveSharing($admin,$all);
    $result=$registry->inspect($site);
    check($result['ok']&&$result['snapshot_saved']&&$result['history_saved'],'complete collection saved through real registry');
    check($result['inventory']['status']==='complete'&&count(array_filter($result['inventory']['sections'],fn($s)=>$s['status']==='available'))===13,'all thirteen real readers succeed');
    check($pluginBefore===$db->setQuery("SELECT params FROM #__extensions WHERE type='plugin' AND folder='system' AND element='nicodewebmonitor'")->loadResult(),'disclosure does not change inventory or maintenance credentials');
    $payload=json_encode($result['inventory']);
    $identity=$db->setQuery('SELECT username,email,password FROM #__users WHERE id='.(int)$admin->id)->loadAssoc();
    foreach($identity as $key=>$value)check($value===''||!str_contains($payload,$value),'no user '.$key.' in full inventory');
    foreach(['password','secret','host','user','dbprefix','log_path','tmp_path','smtpuser','smtppass'] as $forbidden)
        check(!array_key_exists($forbidden,$result['inventory']['sections']['configuration']['data']),'configuration excludes '.$forbidden);
    check(!str_contains($payload,str_replace('\\','/',JPATH_ROOT))&&!str_contains($payload,JPATH_ROOT),'no filesystem root disclosed');
    check($registry->latest($site)['from_cache']===true,'stored detail explicitly marked as an observation');
    rejects(fn()=>(new SiteRegistry($db,new CredentialVault($app->get('secret')),new User()))->latest($site),'cached detail requires component read permission');
    foreach(array_keys($all) as $category){
        $selected=$all;$selected[$category]=false;$connection->saveSharing($admin,$selected);
        $result=$registry->inspect($site);$stored=$registry->latest($site);
        check($result['ok']&&$result['snapshot_saved']&&$result['inventory']['sections'][$category]['status']==='denied'&&$result['inventory']['sections'][$category]['data']===null,$category.' denial enforced in self-monitoring');
        check($stored['inventory']['sections'][$category]['data']===null,$category.' old data replaced in stored detail');
    }
    $connection->saveSharing($admin,[]);$registry->inspect($site);
    $summary=$registry->all()[0]['inventory_summary'];
    check($summary['joomla']['version']===null&&$summary['runtime']['php_version']===null&&$summary['updates']['total']===null,'deny-all removes old dashboard values');
    $process=proc_open([PHP_BINARY,__DIR__.'/lifecycle_step.php',JPATH_BASE,'--allow-disposable-writes'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode(['action'=>'install','zip'=>realpath(__DIR__.'/../dist/pkg_nicodeconnector-1.1.1-dev.5.zip')]));fclose($pipes[0]);
    $installed=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    check(proc_close($process)===0&&str_contains($installed,'"ok":true'),'native connector package reinstalls successfully');
    check(count(array_filter($connection->sharing()))===0&&$connection->state()['state']==='connected','package reinstall preserves deny-all and the existing connection');
    // A broken disposable shadow table must not turn a storage failure into scheduler success.
    $db->setQuery('CREATE TEMPORARY TABLE #__nwm_inventory (site int PRIMARY KEY,received_at bigint,summary text,inventory mediumtext,CHECK (site<0))')->execute();
    try{
        $failed=$registry->inspect($site);
        check($failed['history_saved']&&$failed['snapshot_saved']===false,'snapshot failure is explicit while check history remains recorded');
        $batch=$registry->monitor(1);
        check($batch['failed']===1&&$batch['status']==='history_failed','scheduler reports a failed snapshot write honestly');
    }finally{$db->setQuery('DROP TEMPORARY TABLE #__nwm_inventory')->execute();}
    $bad=$denied;$bad['sections']['users']['data']=['total'=>123];
    rejects(fn()=>InventorySchema::normalise($bad,['local_cli']),'denied payload cannot smuggle retained data');
    $bad=$denied;$bad['schema_version']=1;
    rejects(fn()=>InventorySchema::normalise($bad,['local_cli']),'older development inventory schema rejected');
    $extra=$result['inventory'];$extra['sections']['runtime']=['status'=>'available','data'=>['php_version'=>PHP_VERSION,'password'=>'DO_NOT_RETAIN']];$extra['secret']='DO_NOT_RETAIN';
    check(!str_contains(json_encode(InventorySchema::normalise($extra,['local_cli'])),'DO_NOT_RETAIN'),'unknown envelope and category fields stripped');
    $extra['sections']['updates']=['status'=>'available','data'=>['items'=>[['version'=>'https://private.invalid/DO_NOT_RETAIN','current_version'=>'https://private.invalid/DO_NOT_RETAIN']],'total'=>1]];
    check(!str_contains(json_encode(InventorySchema::normalise($extra,['local_cli'])),'DO_NOT_RETAIN'),'malformed update version fields cannot forward private URLs');
    $connection->disconnect();
    check(count(array_filter($connection->sharing()))===0,'disconnect retains restrictive disclosure');
    $registry->remove($site);
    check(!(int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_inventory WHERE site='.$site)->loadResult(),'site removal deletes stored inventory');
    (new InventoryStore($db))->save($site,$denied);
    check(!(int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_inventory WHERE site='.$site)->loadResult(),'late observation cannot recreate a deleted site cache');
    echo json_encode(['passed'=>count($checks),'checks'=>$checks,'real_joomla'=>true,'self_monitoring'=>true],JSON_PRETTY_PRINT),PHP_EOL;
}finally{
    if($site)$registry->remove($site);
    $db->setQuery('DELETE FROM #__nwm_pairing')->execute();
    foreach($snapshot as $r)$db->setQuery('UPDATE #__extensions SET params='.$db->quote($r['params']).',enabled='.(int)$r['enabled'].' WHERE extension_id='.(int)$r['extension_id'])->execute();
}
