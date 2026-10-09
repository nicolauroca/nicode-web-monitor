<?php
/** Disposable integration harness. TLS fixture configuration arrives on stdin. */
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
use Nicode\Component\NicodeWebMonitor\Administrator\Service\InventoryClient;
$c = Factory::getContainer();
$c->alias('session', 'session.cli')->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
$app = $c->get(\Joomla\CMS\Application\ConsoleApplication::class);
Factory::$application = $app;
$app->createExtensionNamespaceMap();
$app->bootComponent('com_nicodewebmonitor');
$db = $c->get(DatabaseInterface::class);
$user = new User((int)$db->setQuery('SELECT user_id FROM #__user_usergroup_map WHERE group_id=8')->loadResult());
$input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$vault = new CredentialVault($app->get('secret'));
$registry = new SiteRegistry($db, $vault, $user);
$db->transactionStart();
try {
    // Only this disposable harness bypasses production's public-IP enrollment rule.
    $row = (object)['site_id'=>$input['site_id'], 'label'=>'TLS fixture', 'base_url'=>$input['url'], 'address'=>'127.0.0.1', 'credential'=>$vault->seal($input['site_id'], $input['token'])];
    $db->insertObject('#__nwm_sites', $row, 'id');
    $result = $registry->inspect((int)$row->id, new InventoryClient($input['ca'], ['127.0.0.1'], 2000));
    $sites=[]; $inspection=$result; $inspectedId=(int)$row->id;
    ob_start(); require JPATH_ADMINISTRATOR.'/components/com_nicodewebmonitor/tmpl/registry.php'; $html=ob_get_clean();
    if (str_contains($html, $input['token']) || str_contains($html, $row->credential) || str_contains($html, '<script>')) { throw new RuntimeException('Unsafe output'); }
    echo json_encode(['ok'=>$result['ok'], 'error'=>$result['error']??null, 'status'=>$result['inventory']['status']??null, 'rendered'=>str_contains($html, $result['ok'] ? 'Inventory section availability' : 'Inventory unavailable')]);
} finally { $db->transactionRollback(); }
