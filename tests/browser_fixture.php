<?php
/** Seed/clean browser-only observations in an explicitly disposable Joomla. Never connects to the fixture URLs. */
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
require_once __DIR__.'/inventory_fixture.php';

$action = $argv[3] ?? '';
if (!in_array($action, ['seed', 'clean'], true)) { exit(2); }
$adminId = (int) $db->setQuery('SELECT user_id FROM #__user_usergroup_map WHERE group_id=8')->loadResult();
$registry = new SiteRegistry($db, new CredentialVault($app->get('secret')), new User($adminId));
$prefix = 'NWM browser fixture ';
// Only this fixture namespace is removed; unrelated enrolled sites are preserved.
foreach ($registry->all() as $row) {
    if (str_starts_with($row['label'], $prefix)) { $registry->remove((int)$row['id']); }
}
$ids = [];
if ($action === 'seed') {
    $history = new \Nicode\Component\NicodeWebMonitor\Administrator\Service\CheckHistory($db);
    $good = ['ok'=>true, 'inventory'=>['status'=>'complete', 'collected_at'=>gmdate('Y-m-d\TH:i:s\Z')]];
    $partial = $good; $partial['inventory']['status']='partial';
    foreach (['failed', 'partial', 'recovered', 'stale', 'never'] as $state) {
        $uuid = 'c3c6d3e1-f65b-4000-8000-' . bin2hex(random_bytes(6));
        $registry->add($prefix . $state, 'https://example.invalid/' . str_repeat('long-path-', 12), '93.184.216.34', $uuid, bin2hex(random_bytes(32)));
        $row = array_values(array_filter($registry->all(), fn($r)=>$r['site_id']===$uuid))[0];
        $id = (int)$row['id']; $ids[$state]=$id;
        if($state!=='never'){
            $inventory=nwmInventoryFixture();
            if($state==='stale')$inventory['collected_at']=gmdate('Y-m-d\TH:i:s\Z',time()-90000);
            $inventory['sections']['joomla']=['status'=>'available','data'=>['version'=>$state==='partial'?'6.1.4':'6.2.1']];
            $inventory['sections']['runtime']=['status'=>'available','data'=>['php_version'=>$state==='failed'?'8.1.34':($state==='partial'?'8.3.37':'8.5.13')]];
            $inventory['sections']['security']=['status'=>'available','data'=>['force_ssl'=>2,'debug'=>$state==='failed','debug_lang'=>false,'display_errors'=>false]];
            $inventory['sections']['storage']=['status'=>'available','data'=>['free_bytes'=>$state==='failed'?10485760:10737418240,'total_bytes'=>21474836480]];
            $inventory['sections']['configuration']=['status'=>'available','data'=>['offline'=>false]];
            if($state==='partial')$inventory['sections']['configuration']=['status'=>'denied','data'=>null];
            $inventory['sections']['scheduler']=['status'=>'available','data'=>['locked'=>0,'items'=>[]]];
            $updates=[];
            if($state==='partial')for($i=0;$i<56;$i++)$updates[]=['extension_id'=>100+$i%2,'type'=>'plugin','element'=>'fixture'.$i%2,'name'=>'Fixture','version'=>'2.0.0','current_version'=>'1.0.0'];
            $cacheTime=time()-($state==='stale'?90000:0);
            $inventory['sections']['updates']=['status'=>'available','data'=>['total'=>count($updates),'items'=>$updates,'oldest_checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$cacheTime),'last_checked_at'=>gmdate('Y-m-d\TH:i:s\Z',$cacheTime)]];
            (new \Nicode\Component\NicodeWebMonitor\Administrator\Service\InventoryStore($db))->save($id,$inventory,125);
            if($state==='stale')$db->setQuery('UPDATE #__nwm_inventory SET received_at=received_at-90000 WHERE site='.$id)->execute();
        }
        if ($state === 'failed') { $history->record($id, ['ok'=>false, 'error'=>'access_denied']); }
        if ($state === 'partial') { $history->record($id, $partial); }
        if ($state === 'recovered') {
            $history->record($id, ['ok'=>false, 'error'=>'connection_failed']);
            $history->record($id, $good);
        }
        if ($state === 'stale') {
            $history->record($id, $good);
            $db->setQuery('UPDATE #__nwm_checks SET checked_at=checked_at-90000, collected_at=collected_at-90000 WHERE site='.$id)->execute();
        }
    }
}
echo json_encode(['action'=>$action, 'fixture_ids'=>$ids]), "\n";
