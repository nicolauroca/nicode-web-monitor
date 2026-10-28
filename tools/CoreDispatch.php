<?php
declare(strict_types=1);
namespace Nicode\Maintenance;

/** @package Nicode.joomla.webmonitor. HTTP only queues; native execution is an explicit fresh CLI.
 * No shell commands, remote paths, automatic retries or background process creation.
 */
final class CoreDispatch
{
    public function __construct(private string $storage, private MaintenanceGate $gate, private string $webRoot) {}

    private function directory(string $id): string {
        if (!preg_match('/^[a-z0-9-]{8,64}$/D', $id)) { throw new \RuntimeException('Invalid operation'); }
        foreach ([$this->storage, $this->storage.'/core', $this->storage.'/core/'.$id] as $dir) {
            $real = realpath($dir); $root = realpath($this->webRoot);
            if (!$real || !$root || !is_dir($dir) || is_link($dir)) { throw new \OutOfBoundsException('Unprepared operation'); }
            $a = strtolower(str_replace('\\','/',$real)); $b = strtolower(str_replace('\\','/',$root));
            if ($a === $b || str_starts_with($a,$b.'/') || str_starts_with($b,$a.'/')) { throw new \RuntimeException('Private storage required'); }
        }
        return $dir;
    }

    private function permit(string $dir, string $id): array {
        $file = $dir.'/permit.json';
        if (!is_file($file) || is_link($file) || filesize($file)>4096) { throw new \RuntimeException('Invalid permit'); }
        $p = json_decode((string)file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        if (($p['operation'] ?? null) !== $id || ($p['kind'] ?? null) !== 'core'
            || ($p['writers_stopped'] ?? null) !== true || ($p['recovery_verified'] ?? null) !== true
            || !is_int($p['expires_at'] ?? null) || $p['expires_at'] < time() || $p['expires_at'] > time()+900
            || ($p['root_sha256'] ?? null) !== hash('sha256', strtolower(str_replace('\\','/',realpath($this->webRoot))))) {
            throw new \RuntimeException('Expired or invalid core permit');
        }
        if (array_keys($p['plan'] ?? []) !== ['extension_id','from','to','package_sha256']) { throw new \RuntimeException('Invalid plan'); }
        return $p;
    }

    public function handle(string $id, bool $enqueue, bool $offline): array {
        $dir = $this->directory($id);
        if (is_dir($dir.'/journal/op-'.$id)) { return (new CoreOperation($dir.'/journal'))->status($id); }
        $queued = $dir.'/queued.json';
        if (is_link($queued)) { throw new \RuntimeException('Linked queue denied'); }
        if (is_file($queued)) {
            $q = json_decode((string)file_get_contents($queued), true, 16, JSON_THROW_ON_ERROR);
            if (($q['operation'] ?? null) !== $id || ($q['status'] ?? null) !== 'queued') { throw new \RuntimeException('Invalid queue receipt'); }
            return $q; // A repeated POST only reads the original request.
        }
        if (!$enqueue) { throw new \OutOfBoundsException('Not queued'); }
        if (!$offline) { throw new \RuntimeException('Offline required'); }
        $p = $this->permit($dir, $id);
        return $this->gate->run('core:'.$id, function () use ($id, $p, $queued) {
            // Recheck after obtaining the shared lock: another process may have queued it.
            if (file_exists($queued)) { return json_decode((string)file_get_contents($queued), true, 16, JSON_THROW_ON_ERROR); }
            $q = ['operation'=>$id,'status'=>'queued','plan'=>$p['plan']];
            $f = fopen($queued, 'x');
            if (!$f) { throw new \RuntimeException('Cannot queue'); }
            try {
                $bytes = json_encode($q, JSON_THROW_ON_ERROR);
                if (fwrite($f,$bytes)!==strlen($bytes) || !fflush($f) || !fsync($f)) { throw new \RuntimeException('Queue persistence failed'); }
            } finally { fclose($f); }
            return $q;
        });
    }

    public function execute(string $id, \Closure $inspect, \Closure $install): array {
        $dir = $this->directory($id);
        $this->handle($id, false, false); // Refuse unqueued operations before reserving the gate.
        return $this->gate->run('core:'.$id, function () use ($id,$dir,$inspect,$install) {
            $journal = new CoreOperation($dir.'/journal');
            if (is_dir($dir.'/journal/op-'.$id)) { return $journal->status($id); }
            $p = $this->permit($dir,$id);
            $queued = $this->handle($id, false, false);
            if ($queued['status'] !== 'queued' || $queued['plan'] !== $p['plan']) { throw new \RuntimeException('Queue conflict'); }
            return $journal->execute($id, $p['plan'], true, true, $inspect,
                fn ($hash) => $install($dir.'/package.zip', $hash, $dir.'/snapshot'));
        });
    }

    public function reconcile(string $id, \Closure $inspect): array {
        $dir = $this->directory($id);
        return $this->gate->run('core:'.$id, fn () => (new CoreOperation($dir.'/journal'))->reconcile($id,$inspect));
    }
}
