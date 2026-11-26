<?php
/** Disposable connection-local table: no outbound I/O and no component settings retained. */
if(($argv[2]??'')!=='--allow-disposable-writes')exit(2);
$_SERVER['HTTP_HOST']='localhost';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['REQUEST_URI']='/index.php';
define('_JEXEC',1);define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php';require JPATH_BASE.'/includes/framework.php';
use Joomla\CMS\Factory;use Joomla\CMS\User\User;use Joomla\Database\DatabaseInterface;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\ReleaseCatalog;
$c=Factory::getContainer();$c->alias('session','session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
Factory::$application=$c->get(Joomla\CMS\Application\ConsoleApplication::class);Factory::$application->createExtensionNamespaceMap();Factory::$application->bootComponent('com_nicodewebmonitor');
$db=$c->get(DatabaseInterface::class);$adminId=(int)$db->setQuery('SELECT user_id FROM #__user_usergroup_map WHERE group_id=8')->loadResult();$admin=new User($adminId);$admin->authorise('nwm.monitor','com_nicodewebmonitor');
$checks=[];function check($ok,$name){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
function denied($fn,$name){try{$fn();}catch(RuntimeException $e){check($e->getCode()===403,$name);return;}throw new RuntimeException($name);}
$seed=ReleaseCatalog::seed();$now=max(array_column($seed,'checked_at'))+400;$calls=[];$failJoomla=false;
$php=json_encode([['branch'=>'8.5','latest'=>'8.5.13','state'=>'stable','initial_release'=>'2025-11-20T00:00:00+00:00','active_support_end'=>'2027-12-31T00:00:00+00:00','security_support_end'=>'2029-12-31T00:00:00+00:00']]);
$joomla='<table><tr><th>Current Release</th><td>6.2.1</td></tr><tr><th>Release Date of Series</th><td>14 October 2025</td></tr><tr><th>End of regular bugfix Support for 6.x</th><td>17 October 2028</td></tr><tr><th>End of security fix only Support for 6.x</th><td>16 October 2029</td></tr></table>';
$download=function($product)use(&$calls,&$failJoomla,$php,$joomla){$calls[]=$product;if($product==='joomla'&&$failJoomla)throw new RuntimeException('Fixture unavailable');return $product==='php'?$php:$joomla;};
$db->setQuery('CREATE TEMPORARY TABLE #__extensions (`type` varchar(32),element varchar(100),params mediumtext)')->execute();
try{
 $settings=(object)['type'=>'component','element'=>'com_nicodewebmonitor','params'=>json_encode(['notification_recipient'=>'fixture@example.invalid','custom'=>['preserved'=>true]])];$db->insertObject('#__extensions',$settings);
 $catalog=new ReleaseCatalog($db,$download);check($catalog->read()===$seed&&$calls===[],'rendering reads shipped facts without network');
 $reader=new class($adminId)extends User{public function authorise($action,$asset=null){return $action==='core.manage';}};
 denied(fn()=>$catalog->refresh(new User(),true,$now),'guest cannot refresh');
 denied(fn()=>$catalog->refresh($reader,true,$now),'reader cannot refresh without monitoring permission');
 $blocked=clone $admin;$blocked->block=1;denied(fn()=>$catalog->refresh($blocked,true,$now),'blocked administrator cannot refresh');
 check($calls===[],'forbidden refresh performs no network call');
 $result=$catalog->refresh($admin,true,$now);check($result['status']==='updated'&&$calls===['php','joomla'],'explicit refresh updates both independent sources');
 $cached=$catalog->read();check($cached['php']['checked_at']===$now&&$cached['joomla']['checked_at']===$now,'source dates are persisted');
 $params=json_decode($db->setQuery('SELECT params FROM #__extensions')->loadResult(),true);
 check($params['notification_recipient']==='fixture@example.invalid'&&$params['custom']['preserved'],'refresh preserves unrelated component options');
 $count=count($calls);check($catalog->refresh($admin,false,$now+30)['status']==='cached'&&count($calls)===$count,'scheduled retries are throttled');
 $failJoomla=true;$result=$catalog->refresh($admin,true,$now+86401);
 check($result['status']==='partial'&&$result['unavailable']===['joomla'],'independent source failures are explicit');
 $after=$catalog->read();check($after['joomla']===$cached['joomla']&&$after['php']['checked_at']===$now+86401,'failed source retains its old date instead of becoming fresh');
 $count=count($calls);$catalog->refresh($admin,false,$now+86402);check(count($calls)===$count,'unavailable provider does not get retried at every task visit');
}finally{$db->setQuery('DROP TEMPORARY TABLE #__extensions')->execute();}
echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT)."\n";
