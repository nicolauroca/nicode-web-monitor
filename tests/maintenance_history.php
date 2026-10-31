<?php
/** Real Joomla user/vault + MariaDB temporary tables; deterministic transport failure injection. */
if (($argv[2] ?? '') !== '--allow-disposable-writes') { exit(2); }
$_SERVER['HTTP_HOST']='localhost'; $_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['REQUEST_URI']='/index.php';
define('_JEXEC', 1); define('JPATH_BASE', realpath($argv[1]));
require JPATH_BASE . '/includes/defines.php'; require JPATH_BASE . '/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\BackupCoordinator;
$services = dirname(__DIR__) . '/extensions/com_nicodewebmonitor/administrator/src/Service/';
require $services . 'CredentialVault.php'; require $services . 'BackupCoordinator.php';
$container = Factory::getContainer();
$container->alias('session','session.cli')->alias(\Joomla\Session\SessionInterface::class,'session.cli');
Factory::$application = $container->get(\Joomla\CMS\Application\ConsoleApplication::class);
$db = $container->get(DatabaseInterface::class);
$adminId = (int)$db->setQuery('SELECT user_id FROM #__user_usergroup_map WHERE group_id=8')->loadResult();
$admin = new User($adminId);
$vault = new CredentialVault(bin2hex(random_bytes(32)));
require $services . 'UpdateCoordinator.php'; require $services . 'CoreUpdateCoordinator.php';

$checks=[];
function check($ok,$name) { global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[]=$name; }
$schema=file_get_contents(dirname($services,2).'/sql/install.mysql.utf8.sql');$tables=[];
try {
 foreach(explode(';',$schema) as $sql) {
  if(!trim($sql))continue;
  preg_match('/`(#__[a-z_]+)`/',$sql,$m);$tables[]=$m[1];
  $db->setQuery(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE',$sql))->execute();
 }
 $uuid='12345678-1234-4123-8123-123456789abc';
 $record=(object)['site_id'=>$uuid,'label'=>'History fixture','base_url'=>'https://example.invalid','address'=>'192.0.2.1','credential'=>$vault->seal($uuid,bin2hex(random_bytes(32)))];
 $db->insertObject('#__nwm_sites',$record,'id');$site=(int)$record->id;
 foreach(['update'=>'UpdateCoordinator','core'=>'CoreUpdateCoordinator'] as $kind=>$name) {
  $class='Nicode\\Component\\NicodeWebMonitor\\Administrator\\Service\\'.$name;
  $calls=0;$lost=true;
  $transport=function(...$args)use(&$calls,&$lost,$kind,$db){
   $calls++;
   if($lost) {
    $last=$db->setQuery('SELECT status FROM #__nwm_maintenance_events ORDER BY id DESC')->loadResult();
    check($last==='uncertain','durable history before '.$kind.' dispatch');
    return ['ok'=>false,'error'=>'transport_failed'];
   }
   return ['ok'=>true,'receipt'=>['status'=>$kind==='core'?'verified':'updated']];
  };
  $service=new $class($db,$vault,$admin,$transport);
  $service->enroll($site,bin2hex(random_bytes(32)));
  $id='history-'.$kind.'-314';$service->begin($site,$id,true);
  check($service->recent()[0]['history'][0]['status']==='ready',$kind.' begin recorded');
  $service->run($id,true,true);$lost=false;$service->run($id);
  $history=$service->recent()[0]['history'];
  check(array_column($history,'status')===[$kind==='core'?'verified':'updated','uncertain','ready'],$kind.' lost response and reconciliation retained');
  check(count(array_unique(array_column($history,'actor_id')))===1 && (int)$history[0]['actor_id']===$adminId,$kind.' actor attributed');
  $reader=new class($adminId) extends User { public function authorise($a,$asset=null){return $a==='core.manage';} };
  try {(new $class($db,$vault,$reader))->recent();throw new LogicException('ACL bypass');}
  catch(RuntimeException $e){check($e->getCode()===403,$kind.' history ACL');}
  for($i=0;$i<52;$i++){$service->run($id);}
  check(count($service->recent()[0]['history'])===50,$kind.' display bounded to 50');
  check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_maintenance_events WHERE kind='.$db->quote($kind))->loadResult()===55,$kind.' display limit does not delete older events');
  $table=$kind==='core'?'#__nwm_core_update_operations':'#__nwm_update_operations';
  $db->setQuery('UPDATE '.$table.' SET site_id='.$db->quote('99999999-1234-4123-8123-123456789abc'))->execute();
  check($service->recent()[0]['history']===[],$kind.' reused operation cannot expose another identity history');
 }
 echo json_encode(['passed'=>count($checks),'checks'=>$checks,'real_mariadb'=>true],JSON_PRETTY_PRINT);
}finally{foreach(array_reverse($tables) as $table){$db->setQuery('DROP TEMPORARY TABLE IF EXISTS '.$db->quoteName($table))->execute();}}
