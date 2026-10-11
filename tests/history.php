<?php
/** Integration against an installed component in a disposable Joomla. Rolls back test rows. */
if (($argv[2] ?? '') !== '--allow-disposable-writes') { exit(2); }
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/index.php';
define('_JEXEC', 1);
define('JPATH_BASE', realpath($argv[1]));
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\SiteRegistry;
$container = Factory::getContainer();
$container->alias('session', 'session.cli')->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
$app = $container->get(\Joomla\CMS\Application\ConsoleApplication::class);
Factory::$application = $app;
$app->createExtensionNamespaceMap();
$app->bootComponent('com_nicodewebmonitor');
$db = $container->get(DatabaseInterface::class);
$checks = [];
function check($ok, $name) { global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[] = $name; }
function rejects($fn, $name) { try { $fn(); } catch (Throwable $e) { check(true, $name); return; } check(false, $name); }
$adminId = (int) $db->setQuery('SELECT user_id FROM #__user_usergroup_map WHERE group_id=8')->loadResult();
$admin = new User($adminId);
$vault = new CredentialVault($app->get('secret'));
$registry = new SiteRegistry($db, $vault, $admin);
$token = bin2hex(random_bytes(32));
$uuid = 'c3c6d3e1-f65b-4000-8000-' . bin2hex(random_bytes(6));
$history = new \Nicode\Component\NicodeWebMonitor\Administrator\Service\CheckHistory($db);
$class = \Nicode\Component\NicodeWebMonitor\Administrator\Service\CheckHistory::class;
$id = null;
try {
    $registry->add('History fixture', 'https://example.org', '93.184.216.34', $uuid, $token);
    $row = array_values(array_filter($registry->all(), fn($r)=>$r['site_id']===$uuid))[0];
    $id=(int)$row['id'];
    check($row['check_state']==='never_checked' && $row['checks']===[], 'never checked');
    $good=['ok'=>true,'inventory'=>['status'=>'complete','collected_at'=>gmdate('Y-m-d\TH:i:s\Z')]];
    $history->record($id,$good);
    $reloaded = new SiteRegistry($db,$vault,$admin);
    $row = array_values(array_filter($reloaded->all(), fn($r)=>(int)$r['id']===$id))[0];
    check($row['check_state']==='recent_complete' && count($row['checks'])===1,'committed observation survives registry reload');
    $last=$row['checks'][0]; $now=(int)$last['checked_at'];
    check($class::state($last,$now+86400)==='recent_complete','freshness inclusive boundary');
    check($class::state($last,$now+86401)==='stale','freshness expiration');
    $last['collected_at']=$now-86401;
    check($class::state($last,$now)==='stale','old remote observation not fresh');
    $last['collected_at']=$now+301;
    check($class::state($last,$now)==='clock_uncertain','future remote clock');
    $last['collected_at']=null;
    check($class::state($last,$now)==='clock_uncertain','unknown remote clock');
    $last['collected_at']=$now; $last['checked_at']=$now+301;
    check($class::state($last,$now)==='clock_uncertain','future central clock');
    $partial=$good; $partial['inventory']['status']='partial';
    $history->record($id,$partial);
    check($class::state($history->recent($id)[0])==='recent_partial','partial separate from complete');
    $db->setQuery('UPDATE #__nwm_sites SET credential='.$db->quote('broken').' WHERE id='.$id)->execute();
    $result=$registry->inspect($id);
    check(!$result['ok'] && $result['history_saved'],'credential failure recorded without connection');
    $last=$history->recent($id)[0];
    check($last['detail']==='credential_unavailable' && $class::state($last)==='check_failed','latest failure supersedes old success');
    check($class::state($last,(int)$last['checked_at']+86401)==='stale','old failure becomes stale');
    $history->record($id,['ok'=>false,'error'=>$token]);
    check($history->recent($id)[0]['detail']==='transport_failed','arbitrary error excluded');
    for ($i=0;$i<105;$i++) { $history->record($id,$good); }
    $all=$history->recent($id,1000);
    check(count($all)===100,'retention bounded to 100');
    check((int)$all[0]['id']>(int)$all[99]['id'],'newest first');
    check(!str_contains(json_encode($all),$token) && !array_key_exists('inventory',$all[0]),'no credential or inventory payload retained');
    $db->transactionStart();
    $history->record($id,['ok'=>false,'error'=>'timeout']);
    $db->transactionRollback();
    check($history->recent($id)[0]['outcome']==='received','nested history transaction preserves caller rollback');
    $sites=$registry->all(); $user=new class extends User { public function authorise($a,$asset=null) { return $a==='core.manage'; } };
    ob_start();require JPATH_ADMINISTRATOR.'/components/com_nicodewebmonitor/tmpl/registry.php';$html=ob_get_clean();
    check(str_contains($html,'Recent complete inventory') && str_contains($html,'Recent checks'),'dashboard renders persisted status and history');
    $sites=[];$inspection=['ok'=>false,'error'=>'timeout','history_saved'=>false];$inspectedId=$id;
    ob_start();require JPATH_ADMINISTRATOR.'/components/com_nicodewebmonitor/tmpl/registry.php';$html=ob_get_clean();
    check(str_contains($html,'could not be saved'),'persistence failure visible');
    $registry->remove($id);
    check($history->recent($id)===[],'site deletion removes history');
    $history->record($id,$good);
    check($history->recent($id)===[],'late result cannot recreate deleted history');
} finally { if ($id !== null) { $registry->remove($id); } }
echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT),"\n";
