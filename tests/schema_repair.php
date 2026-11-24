<?php
/** Reproduce missing connection_type in a DISPOSABLE installation, then use real package installation. */
if (($argv[2]??'')!=='--allow-disposable-writes') exit(2);
$_SERVER['HTTP_HOST']='localhost';$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['REQUEST_URI']='/index.php';
define('_JEXEC',1);define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php';require JPATH_BASE.'/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\SiteRegistry;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CheckHistory;
$c=Factory::getContainer();$c->alias('session','session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
$app=$c->get(Joomla\CMS\Application\ConsoleApplication::class);Factory::$application=$app;
$app->createExtensionNamespaceMap();$app->bootComponent('com_nicodewebmonitor');
$db=$c->get(DatabaseInterface::class);$checks=[];
function check($ok,$name){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
function installCurrent():void {
    $p=proc_open([PHP_BINARY,__DIR__.'/lifecycle_step.php',JPATH_BASE,'--allow-disposable-writes'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fwrite($pipes[0],json_encode(['action'=>'install','zip'=>realpath(__DIR__.'/../dist/pkg_nicodemonitor-1.1.1-dev.2.zip')],JSON_THROW_ON_ERROR));fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($p)!==0 || !str_contains($out,'"ok":true'))throw new RuntimeException('Native installer failed: '.substr($out.$err,0,300));
}
check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_sites')->loadResult()===0,'empty disposable registry');
$eid=(int)$db->setQuery("SELECT extension_id FROM #__extensions WHERE type='component' AND element='com_nicodewebmonitor'")->loadResult();
$admin=new User((int)$db->setQuery('SELECT user_id FROM #__user_usergroup_map WHERE group_id=8')->loadResult());
$vault=new CredentialVault($app->get('secret'));$registry=new SiteRegistry($db,$vault,$admin);
$uuid='c3c6d3e1-f65b-4000-8000-'.bin2hex(random_bytes(6));$token=bin2hex(random_bytes(32));$id=0;
try {
    $registry->add('Retained schema fixture','https://example.org','93.184.216.34',$uuid,$token);
    $row=$db->setQuery('SELECT * FROM #__nwm_sites WHERE site_id='.$db->quote($uuid))->loadAssoc();$id=(int)$row['id'];
    (new CheckHistory($db))->record($id,['ok'=>false,'error'=>'timeout']);
    foreach (['1.1.1','1.1.0',null] as $marker) {
        $db->setQuery('ALTER TABLE #__nwm_sites DROP COLUMN connection_type')->execute();
        $db->setQuery('DELETE FROM #__schemas WHERE extension_id='.$eid)->execute();
        if($marker!==null){$schema=(object)['extension_id'=>$eid,'version_id'=>$marker];$db->insertObject('#__schemas',$schema);}
        $reproduced=false;try{$registry->all();}catch(Throwable $e){$reproduced=str_contains($e->getMessage(),'connection_type');}
        check($reproduced,'reproduced missing-column dashboard failure with schema '.($marker??'absent'));
        installCurrent();
        $sites=$registry->all();
        check(count($sites)===1 && $sites[0]['connection_type']==='https','native package repairs actual column with schema '.($marker??'absent'));
        check($db->setQuery('SELECT credential FROM #__nwm_sites WHERE id='.$id)->loadResult()===$row['credential'],'repair preserves encrypted credential');
        check((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_checks WHERE site='.$id)->loadResult()===1,'repair preserves existing check history');
    }
    $db->setQuery("UPDATE #__nwm_sites SET connection_type='local' WHERE id=".$id)->execute();
    installCurrent();installCurrent();
    check($registry->all()[0]['connection_type']==='local','reinstallation does not duplicate column or overwrite existing mode');
    echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT),"\n";
} finally {
    if(!isset($db->getTableColumns('#__nwm_sites')['connection_type']))$db->setQuery("ALTER TABLE #__nwm_sites ADD COLUMN connection_type varchar(8) NOT NULL DEFAULT 'https'")->execute();
    if($id){$db->setQuery('DELETE FROM #__nwm_checks WHERE site='.$id)->execute();$db->setQuery('DELETE FROM #__nwm_sites WHERE id='.$id)->execute();}
}
