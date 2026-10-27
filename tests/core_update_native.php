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
$config = Factory::getConfig(); $host = explode(':', $config->get('host'));
$mysqli = new mysqli($host[0], $config->get('user'), $config->get('password'), $config->get('db'), (int)($host[1] ?? 3306));
$mysqli->set_charset('utf8mb4');
$snapshot = (new DatabaseSnapshot(realpath($argv[4])))->capture($mysqli, 'core-before', true);
$mysqli->close();
// A permanent reservation makes interrupted experiments non-retryable.
$reservation = fopen($argv[4].'/core-started.json', 'x');
if (!$reservation) { throw new RuntimeException('Already attempted; inspect effects'); }
$bytes = json_encode(['status'=>'uncertain', 'from'=>$before, 'package'=>$package], JSON_THROW_ON_ERROR);
if (fwrite($reservation, $bytes) !== strlen($bytes) || !fflush($reservation) || !fsync($reservation)) {
    throw new RuntimeException('Cannot persist reservation');
}
fclose($reservation);
$db = $c->get(DatabaseInterface::class);
// Follow the native CLI sequence: unpack, copy, finaliseUpgrade, cleanup, purge.
// Deliberately no download/latest-version selection: the test package is pinned.
$staged = $argv[4].'/package.zip';
if (file_exists($staged) || !copy($argv[2], $staged) || !hash_equals($argv[3], hash_file('sha256', $staged))) {
    throw new RuntimeException('Cannot stage private package');
}
$unpacked = InstallerHelper::unpack($staged, true);
if (!$unpacked || !is_dir($unpacked['extractdir'])) { throw new RuntimeException('Unpack failed'); }
$model = Factory::$application->bootComponent('com_joomlaupdate')->getMVCFactory()->createModel('Update', 'Administrator');
try {
    \Joomla\Filesystem\Folder::copy($unpacked['extractdir'], JPATH_BASE, '', true);
    $finished = $model->finaliseUpgrade();
    if (!$finished || $model->getErrors()) { throw new RuntimeException('Native finalization failed; inspect effects'); }
    $model->purge();
} finally { InstallerHelper::cleanupInstall(null, $unpacked['extractdir']); }
$receipt = ['native_finalization_returned'=>true, 'fresh_process_verification_required'=>true,
    'from'=>$before['runtime_version'], 'to'=>$package['version'], 'database_snapshot'=>$snapshot,
    'remote_core_provider'=>false];
file_put_contents($argv[4].'/native-returned.json', json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo json_encode(['native_finalization_returned'=>true, 'fresh_process_verification_required'=>true])."\n";
