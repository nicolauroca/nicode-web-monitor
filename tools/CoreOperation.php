<?php
declare(strict_types=1);
namespace Nicode\Maintenance;

/** @package Nicode.joomla.webmonitor. Durable local core-operation journal.
 * Storage must be private, outside Joomla, and exclusive to this installation.
 * Callbacks are trusted local adapters, never request-supplied code.
 */
final class CoreOperation
{
    public function __construct(private readonly string $storage) {
        if (!is_dir($storage) || is_link($storage)) { throw new \RuntimeException('Private storage required'); }
    }

    public function execute(string $id, array $plan, bool $authorized, bool $confirmed,
        \Closure $inspect, \Closure $install): array {
        if (!$authorized || !$confirmed) { throw new \RuntimeException('Authorization and confirmation required'); }
        if (array_keys($plan) !== ['extension_id', 'from', 'to', 'package_sha256']
            || !is_int($plan['extension_id']) || $plan['extension_id'] < 1
            || !is_string($plan['from']) || !is_string($plan['to'])
            || !preg_match('/^6\.\d+\.\d+$/D', $plan['from'])
            || !preg_match('/^6\.\d+\.\d+$/D', $plan['to'])
            || !version_compare($plan['to'], $plan['from'], '>')
            || !is_string($plan['package_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/D', $plan['package_sha256'])) {
            throw new \RuntimeException('Invalid core plan');
        }
        return $this->locked(function () use ($id, $plan, $inspect, $install) {
            $path = $this->path($id);
            if (is_dir($path)) {
                $record = $this->status($id);
                if ($record['plan'] !== $plan) { throw new \RuntimeException('Operation conflict'); }
                return $record; // Never invoke installation twice, even after success.
            }
            // Block a NEW id too while a previous reservation remains unresolved.
            foreach (glob($this->storage.'/op-*', GLOB_ONLYDIR) as $prior) {
                if (!is_file($prior.'/verified.json')) { throw new \RuntimeException('Unresolved core operation'); }
            }
            $before = $inspect();
            if (!$this->matches($before, $plan, $plan['from']) || ($before['offline'] ?? null) !== true) {
                throw new \RuntimeException('Core baseline changed or online');
            }
            if (!mkdir($path, 0700)) { throw new \RuntimeException('Cannot reserve operation'); }
            $record = ['operation'=>$id, 'plan'=>$plan, 'status'=>'uncertain', 'writer_pid'=>getmypid()];
            $this->write($path.'/started.json', $record);
            // A crash, exception or failed receipt leaves the reservation blocked.
            if ($install($plan['package_sha256']) === true) {
                $this->write($path.'/returned.json', ['native_returned'=>true]);
            }
            return $record; // Loaded Joomla classes cannot verify their own replacement.
        });
    }

    public function reconcile(string $id, \Closure $inspect): array {
        return $this->locked(function () use ($id, $inspect) {
            $record = $this->status($id);
            if ($record['status'] === 'verified') { return $record; }
            if ($record['writer_pid'] === getmypid()) { throw new \RuntimeException('Fresh CLI process required'); }
            $observed = $inspect();
            if ($this->matches($observed, $record['plan'], $record['plan']['to'])) {
                $record['status'] = 'verified';
                $record['observed'] = $observed;
                $this->write($this->path($id).'/verified.json', $record);
            }
            return $record; // Mismatch is uncertainty, never permission to reinstall.
        });
    }

    public function status(string $id): array {
        $path = $this->path($id);
        $file = $path.(is_file($path.'/verified.json') ? '/verified.json' : '/started.json');
        if (!is_file($file) || !is_readable($file)) { throw new \RuntimeException('Incomplete core reservation'); }
        $record = json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        if (($record['operation'] ?? null) !== $id || !isset($record['plan'], $record['writer_pid'])) {
            throw new \RuntimeException('Invalid core receipt');
        }
        return $record;
    }
    private function matches(array $state, array $plan, string $version): bool {
        return ($state['extension_id'] ?? null) === $plan['extension_id']
            && ($state['runtime_version'] ?? null) === $version
            && ($state['manifest_version'] ?? null) === $version
            && ($state['database_version'] ?? null) === $version
            && ($state['schema_errors'] ?? null) === 0;
    }
    private function path(string $id): string {
        if (!preg_match('/^[a-z0-9-]{8,64}$/D', $id)) { throw new \RuntimeException('Invalid operation'); }
        return $this->storage.'/op-'.$id;
    }
    private function locked(\Closure $action): array {
        $lock = fopen($this->storage.'/core.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) { fclose($lock); }
            throw new \RuntimeException('Core operation busy');
        }
        try { return $action(); } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    private function write(string $path, array $data): void {
        $bytes = json_encode($data, JSON_THROW_ON_ERROR);
        $f = fopen($path, 'x');
        if (!$f) { throw new \RuntimeException('Receipt unavailable'); }
        try {
            if (fwrite($f, $bytes) !== strlen($bytes) || !fflush($f) || !fsync($f)) {
                throw new \RuntimeException('Receipt persistence failed');
            }
        } finally { fclose($f); }
    }
}
