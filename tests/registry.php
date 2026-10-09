<?php
/** Integration against an installed component in a disposable Joomla. Rolls back test rows. */
if (($argv[2] ?? '') !== '--allow-disposable-writes') { exit(2); }
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
$sealed = $vault->seal($uuid, $token);
check($vault->open($uuid, $sealed) === $token, 'credential roundtrip');
check($vault->seal($uuid, $token) !== $sealed, 'random nonce');
rejects(fn()=>$vault->open('different-site', $sealed), 'cross-site substitution rejected');
rejects(fn()=>(new CredentialVault(str_repeat('x',32)))->open($uuid,$sealed), 'wrong key rejected');
rejects(fn()=>$vault->open($uuid, 'v1:invalid'), 'malformed ciphertext rejected');
$db->transactionStart();
try {
    $registry->add('<script>alert(1)</script>', 'https://example.org', '93.184.216.34', $uuid, $token);
    $rows = $registry->all();
    $row = array_values(array_filter($rows, fn($r)=>$r['site_id']===$uuid))[0];
    check(!array_key_exists('credential',$row) && !str_contains(json_encode($rows),$token), 'list excludes credential');
    $stored = $db->setQuery('SELECT credential FROM #__nwm_sites WHERE id='.(int)$row['id'])->loadResult();
    check(!str_contains($stored,$token) && $vault->open($uuid,$stored)===$token, 'database stores authenticated ciphertext');
    rejects(fn()=>$registry->add('Duplicate','https://example.org','93.184.216.34',$uuid,$token), 'duplicate identity rejected');
    rejects(fn()=>$registry->add('Private','https://example.org','127.0.0.1',$uuid,$token), 'private IP rejected');
    rejects(fn()=>$registry->add('HTTP','http://example.org','93.184.216.34',$uuid,$token), 'HTTP rejected');
    rejects(fn()=>$registry->add('Query','https://example.org/?token=x','93.184.216.34',$uuid,$token), 'query URL rejected');
    rejects(fn()=>$registry->add('Token','https://example.org','93.184.216.34',$uuid,'bad'), 'invalid token rejected');
    $guest = new SiteRegistry($db,$vault,new User());
    rejects(fn()=>$guest->all(), 'guest read denied');
    rejects(fn()=>$guest->inspect((int)$row['id']), 'guest inventory denied');
    check($registry->inspect(-1)['error']==='site_not_found', 'missing site explicit');
    $db->setQuery('UPDATE #__nwm_sites SET credential='.$db->quote('broken').' WHERE id='.(int)$row['id'])->execute();
    check($registry->inspect((int)$row['id'])['error']==='credential_unavailable', 'corrupt credential does not connect');
    $db->setQuery('UPDATE #__nwm_sites SET credential='.$db->quote($stored).' WHERE id='.(int)$row['id'])->execute();
    rejects(fn()=>$guest->add('x','https://example.org','93.184.216.34',$uuid,$token), 'guest enrollment denied');
    rejects(fn()=>$guest->remove((int)$row['id']), 'guest delete denied');
    $reader = new class extends User {
        public function authorise($action, $assetname = null) { return $action === 'core.manage'; }
    };
    $readonly = new SiteRegistry($db,$vault,$reader);
    check(count($readonly->all()) === count($rows), 'read permission separated');
    rejects(fn()=>$readonly->add('x','https://example.org','93.184.216.34',$uuid,$token), 'reader cannot enroll');
    rejects(fn()=>$readonly->remove((int)$row['id']), 'reader cannot delete');
    // Actual template with malicious stored label, no form token needed for a reader.
    $sites = [$row]; $user = $reader;
    ob_start(); require JPATH_ADMINISTRATOR.'/components/com_nicodewebmonitor/tmpl/registry.php'; $html=ob_get_clean();
    check(!str_contains($html,'<script>') && str_contains($html,'&lt;script&gt;'), 'stored label escaped');
    check(!str_contains($html,$token) && !str_contains($html,$stored), 'template excludes secrets');
    check(!str_contains($html,'value="remove"') && !str_contains($html,'value="add"') && str_contains($html,'value="inspect"'), 'reader can inspect without mutation controls');
    $inspection=['ok'=>false,'error'=>'access_denied']; $inspectedId=(int)$row['id'];
    ob_start(); require JPATH_ADMINISTRATOR.'/components/com_nicodewebmonitor/tmpl/registry.php'; $html=ob_get_clean();
    check(str_contains($html,'access_denied') && str_contains($html,'does not establish'), 'failed read not presented as offline');
    $registry->remove((int)$row['id']);
    check(count($registry->all())===count($rows)-1, 'site removed');
} finally { $db->transactionRollback(); }
echo json_encode(['passed'=>count($checks),'checks'=>$checks], JSON_PRETTY_PRINT), "\n";
