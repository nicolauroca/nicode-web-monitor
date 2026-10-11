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
