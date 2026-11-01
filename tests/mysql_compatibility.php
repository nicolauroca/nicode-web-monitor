<?php
/** @package Nicode.joomla.webmonitor. Explicitly empty disposable loopback MySQL only. */
if(($argv[2]??'')!=='--allow-disposable-writes')exit(2);
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if(!is_int($input['port']??null)||$input['port']<1024||$input['port']>65535)exit(2);
$root=new mysqli('127.0.0.1','root','',null,$input['port']);
$password=bin2hex(random_bytes(32));
$root->query("ALTER USER 'root'@'localhost' IDENTIFIED BY '".$password."'");
$root->query('CREATE DATABASE nwm_disposable CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$_SERVER['HTTP_HOST']='localhost';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['REQUEST_URI']='/index.php';
define('_JEXEC',1);define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php';require JPATH_BASE.'/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\SiteRegistry;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\Notifications;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CheckHistory;
$c=Factory::getContainer();$c->alias('session','session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
$app=$c->get(Joomla\CMS\Application\ConsoleApplication::class);Factory::$application=$app;
$app->createExtensionNamespaceMap();$app->bootComponent('com_nicodewebmonitor');
$db=(new Joomla\Database\DatabaseFactory())->getDriver('mysqli',['host'=>'127.0.0.1:'.$input['port'],'user'=>'root','password'=>$password,'database'=>'nwm_disposable','prefix'=>'qa_']);
$admin=new class extends User {public function authorise($a,$asset=null){return true;}};
$admin->id=123;$admin->guest=false;$admin->block=0;
$checks=[];function check($ok,$name){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
try{
 $schema=file_get_contents(__DIR__.'/../extensions/com_nicodewebmonitor/administrator/sql/install.mysql.utf8.sql');
 foreach(explode(';',$schema)as$sql)if(trim($sql))$db->setQuery($sql)->execute();
 check(count($db->getTableList())===10,'all ten component tables install on MySQL');
 foreach(glob(__DIR__.'/../extensions/com_nicodewebmonitor/administrator/sql/updates/mysql/*.sql')as$file){foreach(explode(';',file_get_contents($file))as$sql)if(trim($sql))$db->setQuery($sql)->execute();}
 check(true,'all existing incremental schema upgrades accepted');
 check(\Nicode\Component\NicodeWebMonitor\Administrator\Service\DurableDispatch::ready($db),'autocommit permits durable dispatch');
 $db->transactionStart();
 check(!\Nicode\Component\NicodeWebMonitor\Administrator\Service\DurableDispatch::ready($db),'even empty explicit transaction blocks dispatch');
 $db->transactionRollback();
 $db->setQuery('SET autocommit=0')->execute();
 check(!\Nicode\Component\NicodeWebMonitor\Administrator\Service\DurableDispatch::ready($db),'disabled autocommit blocks dispatch');
 $db->setQuery('SET autocommit=1')->execute();
 $vault=new CredentialVault(bin2hex(random_bytes(32)));
 $client=function($timeout){return new class{public function fetch(...$args){return ['ok'=>true,'inventory'=>['status'=>'complete','collected_at'=>gmdate('Y-m-d\TH:i:s\Z')]];}};};
 $registry=new SiteRegistry($db,$vault,$admin,$client);
 $paired=$registry->pair('MySQL fixture','https://example.invalid','93.184.216.34','c3c6d3e1-f65b-4000-8000-'.bin2hex(random_bytes(6)),bin2hex(random_bytes(32)));
 $site=$paired['id'];check($registry->all()[0]['check_state']==='recent_complete','atomic pair, encryption and observation projection');
 $sealed=$db->setQuery('SELECT credential FROM #__nwm_sites WHERE id='.$site)->loadResult();
 $db->setQuery("UPDATE #__nwm_sites SET credential='unreadable-fixture' WHERE id=".$site)->execute();
 $monitor=new SiteRegistry($db,$vault,$admin);
 check($monitor->monitor(1)['checked']===1,'GET_LOCK and grouped oldest-first monitoring query');
 $db->setQuery('UPDATE #__nwm_sites SET credential='.$db->quote($sealed).' WHERE id='.$site)->execute();
 (new CheckHistory($db))->record($site,['ok'=>true,'inventory'=>['status'=>'complete','collected_at'=>gmdate('Y-m-d\TH:i:s\Z')]]);
 $notices=new Notifications($db,$admin,static fn(...$args)=>true);
 check($notices->run(true,'test@example.invalid')['accepted']===0,'notification baseline and upsert');
 (new CheckHistory($db))->record($site,['ok'=>false,'error'=>'access_denied']);
 check($notices->run(true,'test@example.invalid')['accepted']===1,'notification transition reservation and update');
 foreach(['update'=>'UpdateCoordinator','core'=>'CoreUpdateCoordinator']as$kind=>$name){
  $class='Nicode\\Component\\NicodeWebMonitor\\Administrator\\Service\\'.$name;
  $service=new $class($db,$vault,$admin,static fn(...$args)=>['ok'=>true,'receipt'=>['status'=>$kind==='core'?'verified':'updated']]);
  $service->enroll($site,bin2hex(random_bytes(32)));$operation='mysql-'.$kind;
  $service->begin($site,$operation,true);$service->run($operation,true,true);
  check(count($service->recent()[0]['history'])===3,$kind.' nested transactions and maintenance history');
 }
 $db->transactionStart();$before=count($registry->all());$registry->remove($site);$db->transactionRollback();
 check(count($registry->all())===$before,'nested deletion rollback restores site and dependent rows');
 $registry->remove($site);check(!$registry->all(),'committed deletion clears registry');
 echo json_encode(['passed'=>count($checks),'checks'=>$checks,'database'=>$db->getVersion(),'php'=>PHP_VERSION,'scope'=>'real MySQL with Joomla database driver; simulated transport, no full Joomla MySQL install'],JSON_PRETTY_PRINT),"\n";
}finally{$db->disconnect();$root->query('SHUTDOWN');$root->close();}
