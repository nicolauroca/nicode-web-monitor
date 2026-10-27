<?php
declare(strict_types=1);
namespace Nicode\Maintenance;
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerHelper;
/** @package Nicode.joomla.webmonitor. Local native adapter, called under CoreOperation reservation.
 * Caller must pin/inspect package, verify recovery, stop other writers and enforce maintenance authorization.
 * A new CLI process must reconcile afterwards. No network download or automatic rollback.
 */
final class NativeCoreUpdate
{
    public static function apply(string $archive, string $hash, string $storage): bool
    {
        $site = strtolower(str_replace('\\', '/', realpath(JPATH_BASE)));
        $store = realpath($storage);
        if (!$store || is_link($storage)) { throw new \RuntimeException('Private snapshot directory required'); }
        $store = strtolower(str_replace('\\', '/', $store));
        if ($site === $store || str_starts_with($store, $site.'/') || str_starts_with($site, $store.'/')) {
            throw new \RuntimeException('Snapshot storage must be separate');
        }
        $report = CoreUpdatePreflight::assess(InstalledCore::inspect(), CoreUpdatePreflight::package($archive, $hash));
        if ($report['status'] !== 'preflight_passed') { throw new \RuntimeException('Core preflight blocked'); }
        $config = Factory::getConfig(); $host = explode(':', $config->get('host'));
        $mysqli = new \mysqli($host[0], $config->get('user'), $config->get('password'), $config->get('db'), (int)($host[1] ?? 3306));
        $mysqli->set_charset('utf8mb4');
        $snapshot = (new DatabaseSnapshot(realpath($storage)))->capture($mysqli, 'core-before', true);
        $mysqli->close();
        // Follow the native CLI sequence: unpack, copy, finaliseUpgrade, cleanup, purge.
        // Deliberately no download/latest-version selection: the test package is pinned.
        $staged = $storage.'/package.zip';
        if (file_exists($staged) || !copy($archive, $staged) || !hash_equals($hash, hash_file('sha256', $staged))) {
            throw new \RuntimeException('Cannot stage private package');
        }
        $unpacked = InstallerHelper::unpack($staged, true);
        if (!$unpacked || !is_dir($unpacked['extractdir'])) { throw new \RuntimeException('Unpack failed'); }
        $model = Factory::$application->bootComponent('com_joomlaupdate')->getMVCFactory()->createModel('Update', 'Administrator');
        try {
            \Joomla\Filesystem\Folder::copy($unpacked['extractdir'], JPATH_BASE, '', true);
            $finished = $model->finaliseUpgrade();
            if (!$finished || $model->getErrors()) { throw new \RuntimeException('Native finalization failed; inspect effects'); }
            $model->purge();
        } finally { InstallerHelper::cleanupInstall(null, $unpacked['extractdir']); }
        return true;
    }
}
