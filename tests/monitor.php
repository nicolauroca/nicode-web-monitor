<?php
/** Disposable integration only. No network: fixtures have deliberately unusable ciphertext. */
if (($argv[2] ?? '') !== '--allow-disposable-writes') { exit(2); }
$_SERVER['HTTP_HOST']='localhost'; $_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['REQUEST_URI']='/index.php';
define('_JEXEC',1); define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\SiteRegistry;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault;
$c=Factory::getContainer();$c->alias('session','session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
$app=$c->get(Joomla\CMS\Application\ConsoleApplication::class);Factory::$application=$app;
$app->createExtensionNamespaceMap();$app->bootComponent('com_nicodewebmonitor');$app->bootComponent('com_scheduler');
$db=$c->get(DatabaseInterface::class);$checks=[];
function check($ok,$name){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
function rejects($fn,$name){try{$fn();}catch(Throwable){check(true,$name);return;}check(false,$name);}
check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_sites')->loadResult()===0,'empty disposable registry required');
$adminId=(int)$db->setQuery('SELECT user_id FROM #__user_usergroup_map WHERE group_id=8')->loadResult();
$admin=new User($adminId);$vault=new CredentialVault($app->get('secret'));$registry=new SiteRegistry($db,$vault,$admin);
$ids=[];
try {
    check($registry->monitor()['checked']===0,'empty batch');
    rejects(fn()=>$registry->monitor(11),'site cap validated');
    rejects(fn()=>$registry->monitor(1,31),'time cap validated');
    $reader=new class extends User { public function authorise($action,$asset=null){return $action==='core.manage';} };
    rejects(fn()=>(new SiteRegistry($db,$vault,$reader))->monitor(),'read permission cannot monitor');
    $blocked=clone $admin;$blocked->block=1;
    rejects(fn()=>(new SiteRegistry($db,$vault,$blocked))->monitor(),'blocked user denied');
    foreach(range(1,3) as $n){
        $row=(object)['site_id'=>'c3c6d3e1-f65b-4000-8000-'.bin2hex(random_bytes(6)),'label'=>'Monitor fixture','base_url'=>'https://example.invalid','address'=>'93.184.216.34','credential'=>'broken'];
        $db->insertObject('#__nwm_sites',$row,'id');$ids[]=(int)$row->id;
    }
    $result=$registry->monitor(2);
    check($result['checked']===2 && $result['failed']===2,'bounded batch records failed checks');
    $count=(int)$db->setQuery('SELECT COUNT(DISTINCT site) FROM #__nwm_checks')->loadResult();
    check($count===2,'only requested sites inspected');
    $registry->monitor(1);
    check((int)$db->setQuery('SELECT COUNT(DISTINCT site) FROM #__nwm_checks')->loadResult()===3,'next batch progresses to unchecked site');
    $rows=$registry->all();check(count(array_filter($rows,fn($r)=>$r['alerts']['active']))===3,'automatic failures visible in panel');
    $pluginState=(int)$db->setQuery("SELECT enabled FROM #__extensions WHERE type='plugin' AND folder='task' AND element='nicodewebmonitor'")->loadResult();
    $db->setQuery("UPDATE #__extensions SET enabled=1 WHERE type='plugin' AND folder='task' AND element='nicodewebmonitor'")->execute();
    Joomla\CMS\Plugin\PluginHelper::getPlugin('task',null,true);
    $plugin=$app->bootPlugin('nicodewebmonitor','task');
    $form=Joomla\CMS\Form\Form::getInstance('com_scheduler.task','<form><field name="type" type="text" /></form>');
    check($plugin->enhanceTaskItemForm($form,(object)['type'=>'nicode.monitor']) && $form->getField('limit','params')!==false,'native task parameters load');
    $invoke=function($uid,$limit=1,$owner=null)use($plugin,$adminId){
        $params=(object)['user_id'=>$uid,'limit'=>$limit,'budget'=>1];
        $task=new Joomla\Component\Scheduler\Administrator\Task\Task((object)['id'=>0,'created_by'=>$owner??$adminId,'type'=>'nicode.monitor','taskOption'=>(object)['id'=>'nicode.monitor'],'params'=>json_encode($params)]);
        $event=new Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent('onExecuteTask',['subject'=>$task,'params'=>$params]);
        $plugin->standardRoutineHandler($event);return $event->getResultSnapshot();
    };
    $snapshot=$invoke($adminId);
    check(json_decode($snapshot['output'],true)['checked']===1,'native Joomla task event executes batch');
    check($snapshot['status']===Joomla\Component\Scheduler\Administrator\Task\Status::KNOCKOUT,'failed checks not reported as task success');
    $before=(int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_checks')->loadResult();
    $snapshot=$invoke(0);
    check(str_contains($snapshot['output'],'Monitoring unavailable'),'unconfigured task fails safely');
    check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_checks')->loadResult()===$before,'invalid user has no effects');
    check(str_contains($invoke($adminId,1,0)['output'],'Monitoring unavailable'),'task owner cannot impersonate monitoring user without admin permission');
    $other=(new Joomla\Database\DatabaseFactory())->getDriver($app->get('dbtype'),['host'=>$app->get('host'),'user'=>$app->get('user'),'password'=>$app->get('password'),'database'=>$app->get('db'),'prefix'=>$app->get('dbprefix')]);
    $lock=$db->quote('nwm:'.substr(hash('sha256',$db->getPrefix().JPATH_ROOT),0,48));
    try {
        check((int)$other->setQuery('SELECT GET_LOCK('.$lock.',0)')->loadResult()===1,'independent connection obtains shared lock');
        check($registry->monitor()['status']==='busy','concurrent batch refused');
        check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_checks')->loadResult()===$before,'busy batch has no effects');
    }finally{$other->setQuery('SELECT RELEASE_LOCK('.$lock.')')->loadResult();$other->disconnect();}
    check($registry->monitor(1)['checked']===1,'batch resumes after competing lock released');
    foreach($ids as $id)$registry->remove($id);$ids=[];
    $snapshot=$invoke($adminId);
    check($snapshot['status']===Joomla\Component\Scheduler\Administrator\Task\Status::OK,'native empty batch succeeds');
    echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT),"\n";
}finally{
    foreach($ids as $id)$registry->remove($id);
    if(isset($pluginState))$db->setQuery("UPDATE #__extensions SET enabled=".$pluginState." WHERE type='plugin' AND folder='task' AND element='nicodewebmonitor'")->execute();
}
