<?php
declare(strict_types=1);
namespace Nicode\Maintenance;

/** @package Nicode.joomla.webmonitor. One durable maintenance owner per installation.
 * All connector maintenance providers and the core CLI must use the same private directory.
 * An incomplete receipt stays reserved after a crash; there is no time-based takeover.
 */
final class MaintenanceGate
{
    public function __construct(private readonly string $storage, string $webRoot) {
        $root = realpath($webRoot); $dir = realpath($storage);
        if (!$root || !$dir || !is_dir($dir) || is_link($storage)) { throw new \RuntimeException('Private gate required'); }
        $a = strtolower(str_replace('\\', '/', $root)); $b = strtolower(str_replace('\\', '/', $dir));
        if ($a === $b || str_starts_with($a, $b.'/') || str_starts_with($b, $a.'/')) {
            throw new \RuntimeException('Gate must be outside site');
        }
    }

    public function currentOwner(): ?string {
        $file = $this->storage.'/owner.json';
        if (is_link($file)) { throw new \RuntimeException('Linked gate denied'); }
        if (!file_exists($file)) { return null; }
        if (!is_file($file) || filesize($file)>4096) { throw new \RuntimeException('Invalid gate'); }
        $owner = json_decode((string)file_get_contents($file), true, 8, JSON_THROW_ON_ERROR)['owner'] ?? null;
        if (!is_string($owner) || !preg_match('/^(core|extension|backup|backup_tree|backup_database|backup_site):[a-z0-9-]{8,64}$/D', $owner)) {
            throw new \RuntimeException('Invalid maintenance owner');
        }
        return $owner; // Advisory discovery only; every effect still obtains the shared lock.
    }

    public function run(string $owner, \Closure $action): array {
        if (!preg_match('/^(core|extension|backup|backup_tree|backup_database|backup_site):[a-z0-9-]{8,64}$/D', $owner)) {
            throw new \RuntimeException('Invalid maintenance owner');
        }
        $file = $this->storage.'/owner.json'; $lockPath = $this->storage.'/maintenance.lock';
        if (is_link($file) || is_link($lockPath)) { throw new \RuntimeException('Linked gate denied'); }
        $lock = fopen($lockPath, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) { fclose($lock); } throw new \RuntimeException('Maintenance busy');
        }
        try {
            if (file_exists($file)) {
                $prior = json_decode((string)file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
                if (($prior['owner'] ?? null) !== $owner) { throw new \RuntimeException('Maintenance reserved'); }
            } else {
                $f = fopen($file, 'x');
                if (!$f) { throw new \RuntimeException('Cannot reserve maintenance'); }
                try {
                    $bytes = json_encode(['owner'=>$owner], JSON_THROW_ON_ERROR);
                    if (fwrite($f, $bytes) !== strlen($bytes) || !fflush($f) || !fsync($f)) {
                        throw new \RuntimeException('Cannot persist maintenance');
                    }
                } finally { fclose($f); }
            }
            $result = $action();
            if (in_array($result['status'] ?? null, ['verified','updated','complete','captured'], true)) {
                if (!unlink($file)) { throw new \RuntimeException('Cannot release maintenance'); }
            }
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
}
