<?php
/** Real due-queue execution in a disposable Joomla. No OS task is created. */
if (($argv[2] ?? '') !== '--allow-disposable-writes') { exit(2); }
$_SERVER['HTTP_HOST']='localhost'; $_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['REQUEST_URI']='/index.php';
define('_JEXEC',1); define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
$c=Factory::getContainer();$c->alias('session','session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
$app=$c->get(Joomla\CMS\Application\ConsoleApplication::class);Factory::$application=$app;
$app->createExtensionNamespaceMap();$app->bootComponent('com_nicodewebmonitor');$app->bootComponent('com_scheduler');
$db=$c->get(DatabaseInterface::class);$checks=[];
function check($ok,$name){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_sites')->loadResult()===0,'empty disposable registry');
$enabledTasks=$db->setQuery('SELECT id FROM #__scheduler_tasks WHERE state=1')->loadColumn();
check((int)$db->setQuery('SELECT COUNT(*) FROM #__scheduler_tasks WHERE locked IS NOT NULL')->loadResult()===0,'no running tasks required');
$uid=(int)$db->setQuery('SELECT user_id FROM #__user_usergroup_map WHERE group_id=8')->loadResult();
$enabled=(int)$db->setQuery("SELECT enabled FROM #__extensions WHERE type='plugin' AND folder='task' AND element='nicodewebmonitor'")->loadResult();
$task=null;$site=null;
try {
    if($enabledTasks)$db->setQuery('UPDATE #__scheduler_tasks SET state=0 WHERE id IN ('.implode(',',array_map('intval',$enabledTasks)).')')->execute();
    $db->setQuery("UPDATE #__extensions SET enabled=1 WHERE type='plugin' AND folder='task' AND element='nicodewebmonitor'")->execute();
    Joomla\CMS\Plugin\PluginHelper::getPlugin('task',null,true);
    $task=(object)['title'=>'Disposable NWM due queue','type'=>'nicode.monitor','state'=>1,'created_by'=>$uid,'created'=>gmdate('Y-m-d H:i:s'),'next_execution'=>gmdate('Y-m-d H:i:s',time()+3),'params'=>json_encode(['user_id'=>$uid,'limit'=>1,'budget'=>1]),'execution_rules'=>json_encode(['rule-type'=>'interval-minutes','interval-minutes'=>1]),'cron_rules'=>json_encode(['type'=>'interval-minutes']),'priority'=>0];
    $db->insertObject('#__scheduler_tasks',$task,'id');
    // Each trigger is a fresh CLI process, as in production (Factory caches dates).
    $trigger=static function(){
        $p=proc_open([PHP_BINARY,JPATH_BASE.'/cli/joomla.php','scheduler:run'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($p))throw new RuntimeException('CLI unavailable');
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        return proc_close($p);
    };
    check($trigger()===125,'future task not executed');
    sleep(4);
    check($trigger()===0,'native CLI due queue executes monitoring without forced ID');
    $saved=$db->setQuery('SELECT * FROM #__scheduler_tasks WHERE id='.$task->id)->loadAssoc();
    check((int)$saved['times_executed']===1 && $saved['locked']===null,'native execution count and lock persisted');
    check(strtotime($saved['next_execution'].' UTC')>time(),'native interval advances next execution');
    check($trigger()===125,'immediate trigger does not repeat task');
    $site=(object)['site_id'=>'c3c6d3e1-f65b-4000-8000-'.bin2hex(random_bytes(6)),'label'=>'Scheduler failure fixture','base_url'=>'https://example.invalid','address'=>'93.184.216.34','credential'=>'broken'];
    $db->insertObject('#__nwm_sites',$site,'id');
    $wait=max(1,strtotime($saved['next_execution'].' UTC')-time()+1);
    if($wait>62)throw new RuntimeException('Unexpected recurrence interval');
    sleep($wait);
    check($trigger()===5,'next natural recurrence reports failed inventory');
    $saved=$db->setQuery('SELECT * FROM #__scheduler_tasks WHERE id='.$task->id)->loadAssoc();
    check((int)$saved['times_executed']===2 && (int)$saved['times_failed']===1 && $saved['locked']===null,'recurring failure counters and unlock persisted');
    check($db->setQuery('SELECT detail FROM #__nwm_checks WHERE site='.(int)$site->id)->loadResult()==='credential_unavailable','recurring failure reaches panel history');
    echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT),"\n";
} finally {
    if(isset($site->id)) {
        $db->setQuery('DELETE FROM #__nwm_checks WHERE site='.(int)$site->id)->execute();
        $db->setQuery('DELETE FROM #__nwm_sites WHERE id='.(int)$site->id)->execute();
    }
    if(isset($task->id)) {
        $db->setQuery('DELETE FROM #__scheduler_tasks WHERE id='.$task->id)->execute();
    }
    $db->setQuery("UPDATE #__extensions SET enabled=".$enabled." WHERE type='plugin' AND folder='task' AND element='nicodewebmonitor'")->execute();
    if($enabledTasks)$db->setQuery('UPDATE #__scheduler_tasks SET state=1 WHERE id IN ('.implode(',',array_map('intval',$enabledTasks)).')')->execute();
}
