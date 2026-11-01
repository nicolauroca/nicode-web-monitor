<?php
/** @package Nicode.joomla.webmonitor. Disposable integration, injected local sender; no external email. */
if (($argv[2] ?? '') !== '--allow-disposable-writes') { exit(2); }
$_SERVER['HTTP_HOST']='localhost'; $_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['REQUEST_URI']='/index.php';
define('_JEXEC',1); define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
require __DIR__.'/../extensions/com_nicodewebmonitor/administrator/src/Service/Notifications.php';
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\Notifications;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\SiteRegistry;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CheckHistory;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault;
$c=Factory::getContainer(); $c->alias('session','session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
$app=$c->get(Joomla\CMS\Application\ConsoleApplication::class); Factory::$application=$app;
$app->createExtensionNamespaceMap(); $app->bootComponent('com_nicodewebmonitor');
$db=$c->get(DatabaseInterface::class); $checks=[];
function check($ok,$name){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
function rejects($fn,$name){try{$fn();}catch(Throwable){check(true,$name);return;}check(false,$name);}
check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_sites')->loadResult()===0,'empty disposable registry required');
$db->setQuery(file_get_contents(__DIR__.'/../extensions/com_nicodewebmonitor/administrator/sql/updates/mysql/0.10.0.sql'))->execute();
$admin=new User((int)$db->setQuery('SELECT user_id FROM #__user_usergroup_map WHERE group_id=8')->loadResult());
$registry=new SiteRegistry($db,new CredentialVault($app->get('secret')),$admin);
$sent=[];$mode='ok';$id=0;
$sender=function($to,$subject,$body)use(&$sent,&$mode,&$id,$db){
    check($db->setQuery('SELECT delivery FROM #__nwm_notifications WHERE site='.$id)->loadResult()==='uncertain','durable reservation before sending');
    $sent[]=[$to,$subject,$body];
    if($mode==='throw')throw new RuntimeException('simulated lost SMTP acknowledgement');
    return $mode==='ok';
};
$notices=new Notifications($db,$admin,$sender);
try {
    $registry->add('Notification fixture','https://example.invalid','93.184.216.34','c3c6d3e1-f65b-4000-8000-'.bin2hex(random_bytes(6)),bin2hex(random_bytes(32)));
    $id=(int)$db->insertid();
    check($notices->run(false,'')['status']==='disabled' && !$sent,'disabled sends nothing');
    $db->transactionStart();
    check($notices->run(true,'test@example.invalid')['status']==='autocommit_required' && !$sent,'enclosing transaction prevents external notification');
    $db->transactionRollback();
    rejects(fn()=>$notices->run(true,"test@example.invalid\r\nBcc: other@example.invalid"),'header injection rejected');
    rejects(fn()=>(new Notifications($db,new User(),$sender))->run(true,'test@example.invalid'),'guest denied');
    $history=new CheckHistory($db);
    $history->record($id,['ok'=>true,'inventory'=>['status'=>'complete','collected_at'=>gmdate('Y-m-d\TH:i:s\Z')]]);
    check($notices->run(true,'test@example.invalid')['accepted']===0 && !$sent,'initial healthy baseline is silent');
    $history->record($id,['ok'=>false,'error'=>'access_denied']);
    check($notices->run(true,'test@example.invalid')['accepted']===1 && count($sent)===1,'failure transition accepted');
    check(str_contains($sent[0][2],'check_failed') && !str_contains($sent[0][2],'credential'),'minimal diagnostic content');
    $notices->run(true,'test@example.invalid');
    check(count($sent)===1,'same state does not repeat');
    $history->record($id,['ok'=>true,'inventory'=>['status'=>'complete','collected_at'=>gmdate('Y-m-d\TH:i:s\Z')]]);
    $mode='throw';
    check($notices->run(true,'test@example.invalid')['uncertain']===1,'lost acknowledgement recorded uncertain');
    $notices->run(true,'test@example.invalid');
    check(count($sent)===2,'uncertain send never automatically retried');
    $notices->retry($id);$mode='ok';
    check($notices->run(true,'test@example.invalid')['accepted']===1 && count($sent)===3,'explicit retry authorizes one attempt');
    $notices->run(true,'test@example.invalid');check(count($sent)===3,'explicit retry remains one shot');
    $db->setQuery('UPDATE #__nwm_checks SET checked_at='.(time()-90000).',collected_at='.(time()-90000).' WHERE site='.$id)->execute();
    check($notices->run(true,'test@example.invalid')['accepted']===1 && str_contains($sent[3][2],'stale'),'staleness triggers without a new check');
    $row=$db->setQuery('SELECT * FROM #__nwm_notifications WHERE site='.$id)->loadAssoc();
    check(!str_contains(json_encode($row),'test@example.invalid'),'notification ledger stores recipient digest only');
    $registry->remove($id);
    check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_notifications WHERE site='.$id)->loadResult()===0,'removal clears notification state');$id=0;
}finally{if($id)$registry->remove($id);}
echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT),"\n";
