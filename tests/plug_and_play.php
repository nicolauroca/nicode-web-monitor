<?php
/** Native database and scheduler integration. Use only a disposable Joomla with both packages. */
if (($argv[2]??'')!=='--allow-disposable-writes') exit(2);
$_SERVER['HTTP_HOST']='localhost'; $_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['REQUEST_URI']='/index.php';
define('_JEXEC',1); define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\PairingService;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\MonitoringSetup;
use Nicode\Component\NicodeConnector\Administrator\Service\Connection;
use Nicode\Component\NicodeConnector\Administrator\Service\CredentialVault as ConnectorVault;

$c=Factory::getContainer(); $c->alias('session','session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
$app=$c->get(Joomla\CMS\Application\ConsoleApplication::class); Factory::$application=$app;
$app->createExtensionNamespaceMap(); $app->bootComponent('com_nicodewebmonitor'); $app->bootComponent('com_nicodeconnector');
$db=$c->get(DatabaseInterface::class); $checks=[];
function check($ok,$name){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
function rejects($fn,$name){try{$fn();}catch(Throwable){check(true,$name);return;}throw new RuntimeException($name);}
$admin=new User((int)$db->setQuery('SELECT user_id FROM #__user_usergroup_map WHERE group_id=8')->loadResult());
check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_sites')->loadResult()===0,'empty disposable registry');
check((int)$db->setQuery("SELECT COUNT(*) FROM #__scheduler_tasks WHERE type='nicode.monitor'")->loadResult()===0,'empty disposable monitoring tasks');
$extensions=$db->setQuery("SELECT extension_id,params,enabled FROM #__extensions WHERE element IN ('com_nicodeconnector','nicodewebmonitor')")->loadObjectList();
$clientOK=true; $calls=0;
$factory=function($timeout)use(&$clientOK,&$calls){return new class($clientOK,$calls){
    public function __construct(private bool &$ok,private int &$calls){}
    public function fetch(...$args):array{$this->calls++;return $this->ok ? ['ok'=>true,'inventory'=>['status'=>'complete','collected_at'=>gmdate('Y-m-d\TH:i:s\Z')]] : ['ok'=>false,'error'=>'identity_mismatch'];}
};};
$pairing=new PairingService($db,new CredentialVault($app->get('secret')),fn($url)=>'93.184.216.34',$factory);
$payload=null;
$connection=new Connection($db,new ConnectorVault($app->get('secret')),fn($url)=>'93.184.216.34',function($url,$ip,$request)use($pairing,&$payload){$payload=$request;return $pairing->claim($request);});
try {
    $setup=new MonitoringSetup($db); rejects(fn()=>$setup->enable(new User()),'guest cannot create task');
    check($setup->status()['state']==='pending','monitoring initially pending');
    $setup->enable($admin); $setup->enable($admin);
    check((int)$db->setQuery("SELECT COUNT(*) FROM #__scheduler_tasks WHERE type='nicode.monitor'")->loadResult()===1,'setup idempotently creates one native task');
    check($setup->status()['state']==='waiting','prepared task is not reported as running');
    $db->setQuery("UPDATE #__scheduler_tasks SET state=0 WHERE type='nicode.monitor'")->execute(); $setup->enable($admin);
    check($setup->status()['state']==='paused','setup preserves deliberate task pause');
    $db->setQuery("UPDATE #__scheduler_tasks SET state=1 WHERE type='nicode.monitor'")->execute();
    rejects(fn()=>$pairing->issue(new User(),'Denied'),'guest cannot issue code');
    $first=$pairing->issue($admin,'This central'); $code=$pairing->issue($admin,'This central');
    check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_pairing')->loadResult()===1,'new code replaces previous code');
    $raw=$db->setQuery('SELECT * FROM #__nwm_pairing')->loadAssoc();
    check(!str_contains(json_encode($raw),$code) && (int)$raw['expires_at']<=time()+600,'only expiring code hash stored');
    $clientOK=false;
    rejects(fn()=>$connection->connect('https://central.example',$code,'https://central.example'),'failed verification leaves pending connection');
    check($connection->state()['state']==='pending','pending state survives failed request');
    check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_sites')->loadResult()===0,'failed verification creates no site');
    $clientOK=true;
    rejects(fn()=>$pairing->claim([...$payload,'code'=>$first]),'superseded code rejected');
    $db->setQuery('UPDATE #__nwm_pairing SET expires_at='.(time()-1))->execute();
    rejects(fn()=>$pairing->claim($payload),'expired code rejected');
    $db->setQuery('UPDATE #__nwm_pairing SET expires_at='.(time()+600))->execute();
    $uuid=$payload['site_id']; $token=$payload['credential'];
    $connection->connect('https://central.example',$code,'https://central.example');
    check($connection->state()['state']==='connected','central successfully monitors itself with separate components');
    check($payload['site_id']===$uuid && $payload['credential']===$token,'retry preserves pending identity and credential');
    $row=$db->setQuery('SELECT * FROM #__nwm_sites')->loadAssoc();
    check((new CredentialVault($app->get('secret')))->open($uuid,$row['credential'])===$token,'central encrypts inventory credential');
    $plugin=$db->setQuery("SELECT params FROM #__extensions WHERE type='plugin' AND folder='system' AND element='nicodewebmonitor'")->loadResult();
    check(!str_contains($plugin,$token) && str_contains($plugin,hash('sha256',$token)),'connector stores inventory hash only');
    $params=$db->setQuery("SELECT params FROM #__extensions WHERE element='com_nicodeconnector'")->loadResult();
    check(!str_contains($params,'pending_token') && !str_contains($params,$code),'successful connector clears pending secret');
    $before=$calls; $pairing->claim($payload);
    check($calls===$before && (int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_sites')->loadResult()===1,'same claim returns receipt without reenrollment');
    rejects(fn()=>$pairing->claim([...$payload,'credential'=>bin2hex(random_bytes(32))]),'used code cannot bind a different credential');
    rejects(fn()=>$pairing->claim([...$payload,'base_url'=>'https://other.example']),'used code cannot bind another site');
    check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_checks')->loadResult()===1,'first inventory retained once');
    $connection->disconnect(); check($connection->state()['state']==='disconnected' && !$connection->state()['enabled'],'disconnect revokes inventory locally');
    $code=$pairing->issue($admin,'Lost response');
    $lost=true;
    $uncertain=new Connection($db,new ConnectorVault($app->get('secret')),fn($url)=>'93.184.216.34',function($url,$ip,$request)use($pairing,&$lost){
        $result=$pairing->claim($request); if($lost){$lost=false;throw new RuntimeException('connection_uncertain');} return $result;
    });
    rejects(fn()=>$uncertain->connect('https://central.example',$code,'https://second.example'),'lost acknowledgment persists pending');
    $uncertain->connect('https://central.example',$code,'https://second.example');
    check($uncertain->state()['state']==='connected' && (int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_sites')->loadResult()===2,'lost acknowledgment reconciles without duplicate site');
    $uncertain->disconnect();
    echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT),"\n";
} finally {
    $db->setQuery('DELETE FROM #__nwm_pairing')->execute();
    $db->setQuery('DELETE FROM #__nwm_checks')->execute(); $db->setQuery('DELETE FROM #__nwm_sites')->execute();
    $ids=$db->setQuery("SELECT id FROM #__scheduler_tasks WHERE type='nicode.monitor'")->loadColumn();
    foreach($ids as $id){$db->setQuery('DELETE FROM #__assets WHERE name='.$db->quote('com_scheduler.task.'.$id))->execute();}
    $db->setQuery("DELETE FROM #__scheduler_tasks WHERE type='nicode.monitor'")->execute();
    foreach($extensions as $row){$db->setQuery('UPDATE #__extensions SET params='.$db->quote($row->params).',enabled='.(int)$row->enabled.' WHERE extension_id='.(int)$row->extension_id)->execute();}
}
