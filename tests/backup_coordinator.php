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
$checks=[]; $calls=0; $mode='normal';
function check($ok,$name) { global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[]=$name; }
function denied($fn,$name) { try { $fn(); } catch (Throwable $e) { check($e->getCode() === 403,$name); return; } throw new RuntimeException($name); }
function invalid($fn,$name) { try { $fn(); } catch (InvalidArgumentException) { check(true,$name); return; } throw new RuntimeException($name); }
$schema = file_get_contents(dirname($services,2) . '/sql/install.mysql.utf8.sql');
$tables=[];
try {
    foreach (explode(';', $schema) as $sql) {
        if (!trim($sql)) { continue; }
        preg_match('/`(#__[a-z_]+)`/', $sql, $match); $tables[]=$match[1];
        $db->setQuery(str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE', $sql))->execute();
    }
    $uuid='12345678-1234-4123-8123-123456789abc';
    $inventory=bin2hex(random_bytes(32)); $backup=bin2hex(random_bytes(32));
    $row=(object)['site_id'=>$uuid,'label'=>'Disposable','base_url'=>'https://example.invalid','address'=>'192.0.2.1','credential'=>$vault->seal($uuid,$inventory)];
    $db->insertObject('#__nwm_sites',$row,'id'); $site=(int)$row->id;
    $transport = function($url,$address,$siteId,$token,$operation,$sequence,$confirmed) use (&$calls,&$mode,$backup,$db) {
        $calls++; check(hash_equals($backup,$token),'transport receives separate backup credential');
        if ($sequence !== null) {
            $status=$db->setQuery('SELECT status FROM #__nwm_backup_operations WHERE operation='.$db->quote($operation))->loadResult();
            check($status==='uncertain','uncertainty persisted before dispatch');
        }
        if ($mode==='lost') { return ['ok'=>false,'error'=>'connection_failed','reconcile_required'=>true]; }
        if ($mode==='throw') { throw new RuntimeException('Private transport detail'); }
        $receipt=['site_id'=>$siteId,'operation'=>$operation,'scope'=>'files_and_database','status'=>$mode==='complete'?'complete':'progress',
            'next_sequence'=>$sequence===null?1:$sequence+1,'restoration_verified'=>false];
        if ($mode==='stale') { $receipt['next_sequence']=0; }
        if ($mode==='jump') { $receipt['next_sequence']=999; }
        return ['ok'=>true,'receipt'=>$receipt,'reconcile_required'=>false];
    };
    $service=new BackupCoordinator($db,$vault,$admin,$transport);
    $reader=new class($adminId) extends User { public function authorise($action,$assetname=null) { return $action==='core.manage'; } };
    $operator=new class($adminId) extends User { public function authorise($action,$assetname=null) { return in_array($action,['core.manage','nwm.backup'],true); } };
    $readOnly=new BackupCoordinator($db,$vault,$reader,$transport);
    denied(fn()=>$readOnly->enroll($site,$backup),'reader cannot enroll backup');
    denied(fn()=>$readOnly->begin($site,true),'reader cannot begin backup');
    denied(fn()=>$readOnly->run('backup-'.str_repeat('a',32)),'reader cannot query backup');
    denied(fn()=>$readOnly->recent(),'reader cannot list operations');
    denied(fn()=>(new BackupCoordinator($db,$vault,new User(),$transport))->begin($site,true),'guest denied');
    denied(fn()=>(new BackupCoordinator($db,$vault,$operator,$transport))->enroll($site,$backup),'backup operator cannot change credentials');
    invalid(fn()=>$service->enroll($site,$inventory),'inventory credential cannot authorize backups');
    $service->enroll($site,$backup);
    $sealed=$db->setQuery('SELECT credential FROM #__nwm_backup_credentials WHERE site='.$site)->loadResult();
    check(!str_contains($sealed,$backup) && $vault->open($uuid.':backup',$sealed)===$backup,'encrypted purpose-bound credential');
    try { $vault->open($uuid,$sealed); throw new LogicException('purpose accepted'); }
    catch (RuntimeException) { check(true,'inventory purpose cannot decrypt backup credential'); }
    invalid(fn()=>$service->begin($site,false),'begin requires confirmation');
    $id=$service->begin($site,true); check($calls===0,'begin has no remote effects');
    $projection=$service->recent();
    check(count($projection)===1 && $projection[0]['operation']===$id && !isset($projection[0]['receipt']) && !isset($projection[0]['credential']), 'journal projection excludes secrets and receipts');
    invalid(fn()=>$service->run($id,true,false),'step requires confirmation');
    check($service->run($id,true,true)['receipt']['next_sequence']===1,'first sequenced step persisted');
    $mode='stale'; check($service->run($id)['error']==='journal_conflict','receipt cannot regress sequence');
    $mode='jump'; check($service->run($id)['error']==='journal_conflict','receipt cannot skip sequences');
    $mode='lost'; check(!$service->run($id,true,true)['ok'],'lost response recorded');
    $before=$calls;
    check($service->run($id,true,true)['error']==='reconciliation_required_or_complete' && $calls===$before,'no blind retry after lost response');
    $mode='normal'; check($service->run($id)['ok'],'read-only reconciliation permits continuation');
    $mode='throw'; check($service->run($id,true,true)['error']==='transport_failed','exception sanitized');
    $mode='complete'; check($service->run($id)['receipt']['status']==='complete','reconcile completed operation');
    $before=$calls; check(!$service->run($id,true,true)['ok'] && $calls===$before,'completed operation cannot advance');
    $id2=$service->begin($site,true);
    $db->transactionStart();
    check($service->run($id2,true,true)['error']==='autocommit_required' && $calls===$before,'transaction prevents non-durable dispatch');
    $db->transactionRollback();
    $db->setQuery('UPDATE #__nwm_backup_credentials SET credential='.$db->quote('broken').' WHERE site='.$site)->execute();
    check($service->run($id2,true,true)['error']==='credential_unavailable' && $calls===$before,'corrupt secret prevents dispatch');
    check($db->setQuery('SELECT status FROM #__nwm_backup_operations WHERE operation='.$db->quote($id2))->loadResult()==='ready','pre-dispatch failure leaves ready journal');
    echo json_encode(['passed'=>count($checks),'checks'=>$checks,'real_mariadb'=>true,'native_admin_http_tested'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
} finally {
    foreach (array_reverse($tables) as $table) { $db->setQuery('DROP TEMPORARY TABLE IF EXISTS '.$db->quoteName($table))->execute(); }
}
