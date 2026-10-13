<?php
/** Disposable TLS batch harness. Ephemeral fixture credentials arrive on stdin. */
if (($argv[2] ?? '') !== '--allow-disposable-writes') { exit(2); }
$_SERVER['HTTP_HOST']='localhost'; $_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['REQUEST_URI']='/index.php';
define('_JEXEC',1); define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\SiteRegistry;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\InventoryClient;
$c=Factory::getContainer();$c->alias('session','session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
$app=$c->get(Joomla\CMS\Application\ConsoleApplication::class);Factory::$application=$app;
$app->createExtensionNamespaceMap();$app->bootComponent('com_nicodewebmonitor');
$db=$c->get(DatabaseInterface::class);$checks=[];
function check($ok,$name){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_sites')->loadResult()===0,'empty disposable registry');
$user=new User((int)$db->setQuery('SELECT user_id FROM #__user_usergroup_map WHERE group_id=8')->loadResult());
$input=json_decode(stream_get_contents(STDIN),true,64,JSON_THROW_ON_ERROR);
$vault=new CredentialVault($app->get('secret'));
$registry=new SiteRegistry($db,$vault,$user,fn($ms)=>new InventoryClient($input['ca'],['127.0.0.1'],$ms));
$ids=[];
try {
    foreach($input['sites'] as $fixture){
        $row=(object)['site_id'=>$fixture['id'],'label'=>'TLS batch fixture','base_url'=>$input['url'].'/'.$fixture['mode'].'/'.$fixture['id'],'address'=>'127.0.0.1','credential'=>$vault->seal($fixture['id'],$input['token'])];
        $db->insertObject('#__nwm_sites',$row,'id');$ids[]=(int)$row->id;
    }
    $production=new SiteRegistry($db,$vault,$user);
    check($production->monitor(3)['failed']===3,'default batch rejects loopback despite stored enrollment');
    $result=$registry->monitor(3);
    check($result['checked']===3 && $result['failed']===1 && $result['partial']===1,'TLS mixed batch counts success revocation and partial');
    $rows=$registry->all();
    check($rows[0]['check_state']==='recent_complete','complete TLS observation fresh');
    check($rows[1]['checks'][0]['detail']==='access_denied','revoked credential persists access denial');
    check($rows[1]['alerts']['active'] && $rows[2]['alerts']['active'],'failure and partial remain panel alerts');
    foreach($ids as $i=>$id)$db->setQuery('UPDATE #__nwm_sites SET base_url='.$db->quote($input['url'].'/complete/'.$input['sites'][$i]['id']).' WHERE id='.$id)->execute();
    $result=$registry->monitor(3);$rows=$registry->all();
    check($result['failed']===0 && $result['partial']===0,'TLS recovery batch succeeds');
    check(count(array_filter($rows,fn($r)=>$r['alerts']['active']))===0,'recovery clears active alerts');
    foreach($ids as $i=>$id)$db->setQuery('UPDATE #__nwm_sites SET base_url='.$db->quote($input['url'].'/slow/'.$input['sites'][$i]['id']).' WHERE id='.$id)->execute();
    $start=hrtime(true);$result=$registry->monitor(3,1);$elapsed=(hrtime(true)-$start)/1e9;
    check($result['checked']===1 && $result['failed']===1 && $result['status']==='budget_exhausted','slow TLS consumes budget without reading next sites');
    check($elapsed>=0.8 && $elapsed<2.5,'one second network budget measured within local tolerance');
    echo json_encode(['passed'=>count($checks),'checks'=>$checks,'slow_batch_seconds'=>$elapsed],JSON_PRETTY_PRINT),"\n";
} finally { foreach($ids as $id)$registry->remove($id); }
