<?php
/** Same-installation pairing and checks with DNS deliberately unavailable. */
if (($argv[2]??'')!=='--allow-disposable-writes') exit(2);
$_SERVER['HTTP_HOST']='localhost'; $_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['REQUEST_URI']='/index.php';
define('_JEXEC',1);define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php';require JPATH_BASE.'/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\SiteRegistry;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\PairingService;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\LocalInventory;
use Nicode\Component\NicodeConnector\Administrator\Service\Connection;
use Nicode\Component\NicodeConnector\Administrator\Service\CredentialVault as ConnectorVault;
$c=Factory::getContainer();$c->alias('session','session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
$app=$c->get(Joomla\CMS\Application\ConsoleApplication::class);Factory::$application=$app;
$app->createExtensionNamespaceMap();$app->bootComponent('com_nicodewebmonitor');$app->bootComponent('com_nicodeconnector');
$db=$c->get(DatabaseInterface::class);$checks=[];
function check($ok,$name){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
function rejects($fn,$code,$name){try{$fn();}catch(Throwable $e){check($e->getMessage()===$code,$name);return;}throw new RuntimeException($name);}
check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_sites')->loadResult()===0,'empty disposable registry');
$snapshot=$db->setQuery("SELECT extension_id,params,enabled,manifest_cache FROM #__extensions WHERE element IN ('com_nicodeconnector','nicodewebmonitor')")->loadAssocList();
$admin=new User((int)$db->setQuery('SELECT user_id FROM #__user_usergroup_map WHERE group_id=8')->loadResult());
$vault=new CredentialVault($app->get('secret'));$registry=new SiteRegistry($db,$vault,$admin);
$pairing=new PairingService($db,$vault);$dnsCalls=0;
$connection=new Connection($db,new ConnectorVault($app->get('secret')),function()use(&$dnsCalls){$dnsCalls++;throw new RuntimeException('DNS is unavailable');});
try {
    $code=$pairing->issue($admin,'Nicode Web Monitor self');
    $connection->connect('https://self.example.invalid',$code,'https://self.example.invalid');
    check($dnsCalls===0 && $connection->state()['connection_type']==='local','self pairing never resolves DNS or opens HTTPS');
    $row=$db->setQuery('SELECT * FROM #__nwm_sites')->loadAssoc();$id=(int)$row['id'];$token=$vault->open($row['site_id'],$row['credential']);
    check($row['connection_type']==='local' && $row['address']==='','local transport is explicit without a fake public IP');
    $result=$registry->inspect($id);
    check($result['ok'] && $result['history_saved'] && $result['inventory']['execution_context']==='local_cli','manual self inventory collects in process');
    check($result['inventory']['capabilities']['remote_inventory']===false,'local collection is not mislabeled as remote');
    check($registry->monitor(1)['checked']===1,'automatic monitoring uses local collection');
    $payload=['code'=>$code,'site_id'=>$row['site_id'],'credential'=>$token,'base_url'=>$row['base_url']];
    check($pairing->claimLocal($payload)['ok'],'same local claim recovers its receipt');
    rejects(fn()=>$pairing->claim([...$payload,'connection_type'=>'local']),'code_unavailable','HTTP payload cannot select local transport or replay local receipt');
    check((new LocalInventory($db))->fetch($row['site_id'],str_repeat('f',64))['error']==='access_denied','local inventory enforces credential');
    check((new LocalInventory($db))->fetch('c3c6d3e1-f65b-4000-8000-123456789abc',$token)['error']==='identity_mismatch','local inventory enforces identity');
    $db->setQuery("UPDATE #__extensions SET enabled=0 WHERE type='plugin' AND folder='system' AND element='nicodewebmonitor'")->execute();
    check($registry->inspect($id)['error']==='connector_unavailable','disabled engine revokes local checks');
    $db->setQuery("UPDATE #__extensions SET enabled=1 WHERE type='plugin' AND folder='system' AND element='nicodewebmonitor'")->execute();
    $connection->disconnect();check($registry->inspect($id)['error']==='connector_unavailable','disconnect revokes local access');
    $plugin=$db->setQuery("SELECT manifest_cache FROM #__extensions WHERE type='plugin' AND folder='system' AND element='nicodewebmonitor'")->loadResult();
    $cache=json_decode($plugin,true);$cache['version']='0.0.0-old';
    $db->setQuery('UPDATE #__extensions SET manifest_cache='.$db->quote(json_encode($cache))." WHERE type='plugin' AND folder='system' AND element='nicodewebmonitor'")->execute();
    rejects(fn()=>$connection->connect('https://self.example.invalid',$code,'https://self.example.invalid'),'package_incomplete','mixed installed versions have a specific diagnostic');
    echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT),"\n";
} finally {
    $db->setQuery('DELETE FROM #__nwm_pairing')->execute();$db->setQuery('DELETE FROM #__nwm_checks')->execute();$db->setQuery('DELETE FROM #__nwm_sites')->execute();
    foreach($snapshot as $r)$db->setQuery('UPDATE #__extensions SET params='.$db->quote($r['params']).',enabled='.(int)$r['enabled'].',manifest_cache='.$db->quote($r['manifest_cache']).' WHERE extension_id='.(int)$r['extension_id'])->execute();
}
