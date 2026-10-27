<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor. Disposable database only, transactional faults. */
if (count($argv) !== 3 || $argv[2] !== '--allow-disposable-writes') { exit(2); }
// CLI MVC services need a URI; never derive it from a Windows drive path.
$_SERVER['HTTP_HOST'] = 'joomla.invalid'; $_SERVER['REQUEST_URI'] = '/cli';
$_SERVER['HTTPS'] = 'on';
define('_JEXEC', 1); define('JPATH_BASE', realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
require __DIR__.'/../tools/CoreUpdatePreflight.php';
require __DIR__.'/../tools/InstalledCore.php';
use Nicode\Maintenance\CoreUpdatePreflight;
use Nicode\Maintenance\InstalledCore;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
$c = Factory::getContainer();
$c->alias('session','session.cli')->alias(\Joomla\Session\SessionInterface::class,'session.cli');
Factory::$application = $c->get(\Joomla\CMS\Application\ConsoleApplication::class);
Factory::$application->createExtensionNamespaceMap();
$db = $c->get(DatabaseInterface::class); $checks = [];
function check(bool $ok, string $name): void {
    global $checks; if (!$ok) { throw new RuntimeException($name); } $checks[] = $name;
}
// Refuse transactional fault injection if rollback is not supported by these tables.
$tables = $db->setQuery('SHOW TABLE STATUS')->loadAssocList('Name');
foreach (['extensions','schemas'] as $table) {
    check(strtoupper($tables[$db->getPrefix().$table]['Engine']) === 'INNODB', 'transactional '.$table);
}
$baseline = InstalledCore::inspect();
check($baseline['schema_errors'] === 0, 'native baseline schema clean');
$id = $baseline['extension_id'];
$before = $db->setQuery('SELECT manifest_cache FROM #__extensions WHERE extension_id='.$id)->loadResult();
$schemaBefore = $db->setQuery('SELECT version_id FROM #__schemas WHERE extension_id='.$id)->loadResult();
$package = ['version'=>'6.1.5']; // Synthetic target for assessment only; never installed.
$db->transactionStart();
try {
    $cache = json_decode($before, true, 16, JSON_THROW_ON_ERROR); $cache['version'] = '6.1.3';
    $db->setQuery('UPDATE #__extensions SET manifest_cache='.$db->quote(json_encode($cache)).' WHERE extension_id='.$id)->execute();
    check(in_array('installed_versions_disagree', CoreUpdatePreflight::assess(InstalledCore::inspect(), $package)['blockers'], true), 'real database version drift denied');
    $db->setQuery('UPDATE #__extensions SET manifest_cache='.$db->quote($before).' WHERE extension_id='.$id)->execute();
    $db->setQuery("UPDATE #__schemas SET version_id='0.0.0' WHERE extension_id=".$id)->execute();
    $drift = InstalledCore::inspect();
    check($drift['schema_errors'] > 0, 'native schema checker detects stale migration marker');
    check(in_array('database_schema_not_clean', CoreUpdatePreflight::assess($drift, $package)['blockers'], true), 'real schema drift blocks preflight');
} finally { $db->transactionRollback(); }
check($db->setQuery('SELECT manifest_cache FROM #__extensions WHERE extension_id='.$id)->loadResult() === $before, 'manifest restored exactly');
check($db->setQuery('SELECT version_id FROM #__schemas WHERE extension_id='.$id)->loadResult() === $schemaBefore, 'schema marker restored exactly');
check(InstalledCore::inspect() === $baseline, 'native baseline restored');
echo json_encode(['passed'=>count($checks), 'checks'=>$checks, 'core_updated'=>false, 'rollback_verified'=>true], JSON_PRETTY_PRINT)."\n";
