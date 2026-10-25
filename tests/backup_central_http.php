<?php
/** Full central service + installed Joomla/MariaDB + real HTTPS transport to packaged connector. */
if (($argv[1] ?? '') !== '--allow-disposable-writes') { exit(2); }
$d=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$_SERVER['HTTP_HOST']='localhost'; $_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['REQUEST_URI']='/index.php';
define('_JEXEC',1); define('JPATH_BASE',realpath($d['joomla']));
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\BackupClient;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\BackupCoordinator;
$container=Factory::getContainer();
$container->alias('session','session.cli')->alias(\Joomla\Session\SessionInterface::class,'session.cli');
Factory::$application=$container->get(\Joomla\CMS\Application\ConsoleApplication::class);
Factory::$application->createExtensionNamespaceMap();
Factory::$application->bootComponent('com_nicodewebmonitor');
$db=$container->get(DatabaseInterface::class);
$adminId=(int)$db->setQuery('SELECT user_id FROM #__user_usergroup_map WHERE group_id=8')->loadResult();
$vault=new CredentialVault(Factory::$application->get('secret'));
$client=new BackupClient($d['ca'],['127.0.0.1'],2000,8192);
$service=new BackupCoordinator($db,$vault,new User($adminId),fn(...$args)=>$client->request(...$args));
$checks=[]; $id=null;
function check($ok,$name) { global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[]=$name; }
try {
    $row=(object)['site_id'=>$d['site_id'],'label'=>'Disposable central backup QA','base_url'=>$d['url'],
        'address'=>'127.0.0.1','credential'=>$vault->seal($d['site_id'],bin2hex(random_bytes(32)))];
    $db->insertObject('#__nwm_sites',$row,'id'); $id=(int)$row->id;
    $service->enroll($id,$d['token']);
    $op=$service->begin($id,true);
    check($service->run($op,true,true)['receipt']['status']==='progress','central first step over TLS');
    check($service->run($op)['receipt']['next_sequence']===1,'central readonly progress');
    $result=$service->run($op,true,true);
    check(!$result['ok'] && $result['reconcile_required'],'central lost response explicit');
    check($db->setQuery('SELECT status FROM #__nwm_backup_operations WHERE operation='.$db->quote($op))->loadResult()==='uncertain','durable journal uncertain after lost response');
    check($service->run($op,true,true)['error']==='reconciliation_required_or_complete','central blocks blind retry');
    $result=$service->run($op);
    check($result['receipt']['status']==='complete' && $result['receipt']['next_sequence']===2,'central reconciles completed operation');
    check($result['receipt']['restoration_verified']===false,'capture does not claim restoration');
    check($db->setQuery('SELECT status FROM #__nwm_backup_operations WHERE operation='.$db->quote($op))->loadResult()==='complete','completion persisted centrally');
    (new \Nicode\Component\NicodeWebMonitor\Administrator\Service\SiteRegistry($db,$vault,new User($adminId)))->remove($id);
    check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_backup_credentials WHERE site='.$id)->loadResult()===0,'site removal deletes backup credential');
    check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_backup_operations WHERE site='.$id)->loadResult()===0,'site removal deletes backup journal');
    $id=null;
    echo json_encode(['passed'=>count($checks),'checks'=>$checks,'real_mariadb_and_tls'=>true,'native_capture_provider'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
} finally {
    if ($id!==null) {
        foreach (['#__nwm_backup_operations','#__nwm_backup_credentials'] as $table) { $db->setQuery('DELETE FROM '.$table.' WHERE site='.$id)->execute(); }
        $db->setQuery('DELETE FROM #__nwm_sites WHERE id='.$id)->execute();
    }
}
