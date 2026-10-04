<?php
/** Integration checks against a disposable, installed Joomla 6 site. No writes. */
declare(strict_types=1);
define('_JEXEC', 1);
define('JPATH_BASE', realpath($argv[1] ?? '') ?: throw new RuntimeException('Pass the Joomla root path.'));
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';
require __DIR__ . '/../extensions/plg_console_nicodewebmonitor/src/Inventory/ExtensionRecord.php';
require __DIR__ . '/../extensions/plg_console_nicodewebmonitor/src/Inventory/Collector.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;
use Nicode\Plugin\Console\NicodeWebMonitor\Inventory\Collector;
use Nicode\Plugin\Console\NicodeWebMonitor\Inventory\ExtensionRecord;

$checks = [];
function check(bool $ok, string $name): void {
    global $checks;
    if (!$ok) { throw new RuntimeException($name); }
    $checks[] = $name;
}
$db = Factory::getContainer()->get(DatabaseInterface::class);
$version = (new Version())->getShortVersion();
$result = (new Collector($db, $version))->collect();
check($result['status'] === 'complete', 'live_collection_complete');
check($result['sections']['joomla']['data']['version'] === $version, 'real_joomla_version');
check($result['sections']['runtime']['data']['php_version'] === PHP_VERSION, 'real_php_version');
check($result['sections']['database']['data']['version'] === $db->getVersion(), 'real_database_version');
$expected = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__extensions'))->loadResult();
check(count($result['sections']['extensions']['data']) === $expected, 'all_installed_extensions_including_disabled');
check($result['sections']['hosting']['status'] === 'unavailable', 'hosting_not_fabricated');
check($result['capabilities'] === ['remote_inventory' => false, 'remote_updates' => false, 'remote_backups' => false], 'no_remote_capabilities_claimed');
check((bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['collected_at']), 'utc_timestamp');
$row = ['extension_id'=>1, 'name'=>'Example', 'type'=>'plugin', 'element'=>'example', 'folder'=>'system', 'client_id'=>0, 'enabled'=>0,
    'params'=>'DO_NOT_EXPORT_PARAMS', 'manifest_cache'=>json_encode(['version'=>'1.2.3-rc1', 'secret'=>'DO_NOT_EXPORT_MANIFEST']), 'download_key'=>'DO_NOT_EXPORT_KEY'];
$record = ExtensionRecord::fromRow($row);
check($record['version']['value'] === '1.2.3-rc1' && $record['enabled'] === false, 'version_and_disabled_state');
check(!str_contains(json_encode($record), 'DO_NOT_EXPORT'), 'allowlist_removes_extra_fields');
foreach (['{broken', '{}', 'null', '{"version":[]}', '{"version":"https://example.invalid/private"}', '{"version":""}'] as $manifest) {
    $row['manifest_cache'] = $manifest;
    $record = ExtensionRecord::fromRow($row);
    check($record['version'] === ['status'=>'unknown', 'value'=>null], 'unknown_version_' . count($checks));
}
$config = Factory::getConfig();
$missingTableDb = (new Joomla\Database\DatabaseFactory())->getDriver($config->get('dbtype'), [
    'host' => $config->get('host'), 'user' => $config->get('user'),
    'password' => $config->get('password'), 'database' => $config->get('db'),
    'prefix' => 'nwm_test_table_does_not_exist_',
]);
$partial = (new Collector($missingTableDb, $version))->collect();
check($partial['status'] === 'partial', 'missing_table_is_partial');
check($partial['sections']['extensions'] === ['status'=>'error', 'reason'=>'collection_failed', 'data'=>null], 'query_failure_not_empty_inventory');
check($partial['sections']['database']['status'] === 'available', 'independent_sections_survive_failure');
check(!str_contains(json_encode($partial), 'nwm_test_table'), 'sql_exception_details_not_exposed');
$rejected = false;
try { (new Collector($db, '5.4.0'))->collect(); } catch (RuntimeException) { $rejected = true; }
check($rejected, 'joomla5_rejected');
echo json_encode(['passed'=>count($checks), 'checks'=>$checks, 'joomla'=>$version, 'php'=>PHP_VERSION, 'extensions'=>$expected], JSON_PRETTY_PRINT) . PHP_EOL;
