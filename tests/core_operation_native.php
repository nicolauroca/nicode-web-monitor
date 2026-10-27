<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor.
 * Native core upgrade experiment, NOT a remote or production update provider.
 * Requires a separately backed-up disposable installation with no web/cron writers.
 */
if (PHP_SAPI !== 'cli' || count($argv) !== 6 || $argv[5] !== '--allow-disposable-writes'
    || !is_file($argv[1].'/.nwm-disposable-core') || !is_dir($argv[4])) { exit(2); }
$sitePath = strtolower(str_replace('\\', '/', realpath($argv[1])));
$storePath = strtolower(str_replace('\\', '/', realpath($argv[4])));
if (is_link($argv[4]) || $sitePath === $storePath || str_starts_with($storePath, $sitePath.'/')
    || str_starts_with($sitePath, $storePath.'/')) { exit(2); }
require __DIR__.'/../tools/CoreUpdatePreflight.php';
require __DIR__.'/../tools/InstalledCore.php';
require __DIR__.'/../tools/DatabaseSnapshot.php';
use Nicode\Maintenance\CoreUpdatePreflight;
use Nicode\Maintenance\InstalledCore;
use Nicode\Maintenance\DatabaseSnapshot;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\Database\DatabaseInterface;
$package = CoreUpdatePreflight::package($argv[2], $argv[3]);
// CLI MVC services need a URI; never derive it from a Windows drive path.
$_SERVER['HTTP_HOST'] = 'joomla.invalid'; $_SERVER['REQUEST_URI'] = '/cli';
$_SERVER['HTTPS'] = 'on';
define('_JEXEC', 1); define('JPATH_BASE', realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
$c = Factory::getContainer();
$c->alias('session','session.cli')->alias(\Joomla\Session\SessionInterface::class,'session.cli');
Factory::$application = $c->get(\Joomla\CMS\Application\ConsoleApplication::class);
Factory::$application->createExtensionNamespaceMap();
$before = InstalledCore::inspect();
$report = CoreUpdatePreflight::assess($before, $package);
if ($report['status'] !== 'preflight_passed') { throw new RuntimeException('Preflight blocked'); }
require __DIR__.'/../tools/CoreOperation.php';
require __DIR__.'/../tools/NativeCoreUpdate.php';
$plan = ['extension_id'=>$before['extension_id'], 'from'=>$before['runtime_version'],
    'to'=>$package['version'], 'package_sha256'=>$package['sha256']];
$journal = new \Nicode\Maintenance\CoreOperation($argv[4]);
$result = $journal->execute('native-core-256', $plan, true, true, fn() => InstalledCore::inspect(),
    function ($hash) use ($argv) {
        \Nicode\Maintenance\NativeCoreUpdate::apply($argv[2], $hash, $argv[4]);
        exit(73); // Test a lost response AFTER native finalization, before success receipt.
    });
echo json_encode($result);
