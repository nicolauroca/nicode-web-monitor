<?php
declare(strict_types=1);
namespace Nicode\Maintenance;

use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\Database\DatabaseInterface;

/** @package Nicode.joomla.webmonitor. Prepared compound updates; caller supplies the shared gate. */
final class NativePackageUpdate
{
    public function __construct(private DatabaseInterface $db, private string $storage,
        private string $webRoot, private bool $offline, private string $maintenanceStorage) {}

    public function handle(string $operation, bool $execute): array
    {
        if (!preg_match('/^[a-z0-9-]{8,64}$/D', $operation)) { throw new \RuntimeException('Invalid operation'); }
        foreach ([$this->storage, $this->storage.'/journal', $this->storage.'/prepared'] as $path) { $this->privateDirectory($path); }
        $core = new PackageOperation($this->storage.'/journal');
        $journal = $this->storage.'/journal/'.$operation;
        if (is_link($journal)) { throw new \RuntimeException('Linked journal denied'); }
        // A recorded attempt must never run the installer again, even after permit expiry.
        if (is_dir($journal)) { return $core->status($operation); }
        if (!$execute) { throw new \OutOfBoundsException('Unknown operation'); }
        $dir = $this->storage.'/prepared/'.$operation;
        $this->privateDirectory($dir);
        $validate = function () use ($dir, $operation): array {
            $file = $dir.'/permit.json';
            if (!$this->offline || !is_file($file) || is_link($file) || filesize($file)>65536) {
                throw new \RuntimeException('Maintenance required');
            }
            $permit = json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            if (($permit['kind']??null)!=='compound-package-v1' || ($permit['operation']??null)!==$operation
                || ($permit['writers_stopped']??null)!==true || ($permit['recovery_verified']??null)!==true
                || !is_int($permit['expires_at']??null) || $permit['expires_at']<time() || $permit['expires_at']>time()+900) {
                throw new \RuntimeException('Invalid permit');
            }
            $report = PackagePreflight::inspect($dir.'/package.zip', $permit['plan']['package_sha256']);
            $state = (new PackageState($this->db))->inspect($report['element'], array_column($report['targets'],'identity'));
            if ($report['element']!==$permit['element'] || $report['children']!==$permit['children']
                || PackagePreflight::plan($report, $state)!==$permit['plan']) { throw new \RuntimeException('Prepared baseline changed'); }
            return $permit;
        };
        $permit = $validate();
        return (new MaintenanceGate($this->maintenanceStorage, $this->webRoot))->run('extension:'.$operation,
            function () use ($validate, $permit, $operation, $core, $dir): array {
                if ($validate()!==$permit) { throw new \RuntimeException('Permit changed'); }
                $inspect = fn () => (new PackageState($this->db))->inspect($permit['element'], array_column($permit['plan']['targets'],'identity'));
                return $core->run($operation, $permit['plan'], true, true, $inspect,
                    function ($digest) use ($dir, $permit): bool {
                        $path = $dir.'/package.zip';
                        PackagePreflight::inspect($path, $digest);
                        $p = InstallerHelper::unpack($path, true);
                        if (!$p) { throw new \RuntimeException('Unpack failed'); }
                        try {
                            $i = new Installer(); $i->setDatabase($this->db); $i->setPath('source', $p['extractdir']);
                            if ($p['type']!=='package' || !$i->findManifest()
                                || 'pkg_'.(string)$i->manifest->packagename!==$permit['element']
                                || (string)$i->manifest->version!==$permit['plan']['to']) { throw new \RuntimeException('Installer manifest mismatch'); }
                            return $i->install($p['extractdir']);
                        } finally { \Joomla\Filesystem\Folder::delete($p['extractdir']); }
                    });
            });
    }

    private function privateDirectory(string $path): void
    {
        $root=realpath($this->webRoot); $dir=realpath($path);
        if (!$root || !$dir || !is_dir($dir) || is_link($path)) { throw new \RuntimeException('Private directory required'); }
        $a=strtolower(str_replace('\\','/',$root)); $b=strtolower(str_replace('\\','/',$dir));
        if ($a===$b || str_starts_with($a,$b.'/') || str_starts_with($b,$a.'/')) { throw new \RuntimeException('Private directory overlaps site'); }
    }
}
