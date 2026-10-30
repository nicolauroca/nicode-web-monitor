<?php
declare(strict_types=1);
namespace Nicode\Maintenance;

/** Package: Nicode.joomla.webmonitor. Local trusted-package update primitive; no HTTP route. */
final class ExtensionUpdate
{
    public function __construct(private readonly string $storage) {
        if (!is_dir($storage) || is_link($storage)) { throw new \RuntimeException('Private journal required'); }
    }

    /** Callbacks are locally bound, never supplied by a remote caller. */
    public function run(string $operation, array $plan, bool $authorized, bool $confirmed,
        \Closure $inspect, \Closure $install, bool $allowReplacement = false): array {
        if (!$authorized || !$confirmed) { throw new \RuntimeException('Permission and confirmation required'); }
        if (!preg_match('/^[a-z0-9-]{8,64}$/D', $operation)
            || array_keys($plan) !== ['extension_id', 'from', 'to', 'package_sha256']
            || !is_int($plan['extension_id']) || $plan['extension_id'] < 1
            || !is_string($plan['from']) || !is_string($plan['to'])
            || strlen($plan['from']) > 64 || strlen($plan['to']) > 64
            || !version_compare($plan['to'], $plan['from'], '>')
            || !is_string($plan['package_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/D', $plan['package_sha256'])) {
            throw new \RuntimeException('Invalid update plan');
        }
        $lock = fopen($this->storage . '/update.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) { fclose($lock); }
            throw new \RuntimeException('Another update is running');
        }
        try {
            $path = $this->storage . '/' . $operation;
            $fingerprint = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
            if (is_dir($path)) {
                $started = json_decode((string) file_get_contents($path . '/started.json'), true, 32, JSON_THROW_ON_ERROR);
                if (($started['fingerprint'] ?? '') !== $fingerprint) { throw new \RuntimeException('Operation conflict'); }
                return $this->status($operation);
            }
            if ($inspect() !== ['extension_id' => $plan['extension_id'], 'version' => $plan['from']]) {
                throw new \RuntimeException('Installed baseline changed');
            }
            if (!mkdir($path, 0700)) { throw new \RuntimeException('Cannot reserve operation'); }
            $record = ['operation' => $operation, 'fingerprint' => $fingerprint, 'plan' => $plan, 'status' => 'uncertain'];
            $this->write($path . '/started.json', $record);
            // Any exception, false result or process death leaves a non-retryable reservation.
            if ($install($plan['package_sha256']) !== true) { return $record; }
            $observed = $inspect();
            if (array_keys($observed) !== ['extension_id', 'version']
                || !is_int($observed['extension_id']) || $observed['extension_id'] < 1
                || (!$allowReplacement && $observed['extension_id'] !== $plan['extension_id'])
                || $observed['version'] !== $plan['to']) { return $record; }
            if ($allowReplacement) { $record['installed_extension_id'] = $observed['extension_id']; }
            $record['status'] = 'updated';
            $this->write($path . '/completed.json', $record);
            return $record;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function status(string $operation): array {
        if (!preg_match('/^[a-z0-9-]{8,64}$/D', $operation)) { throw new \RuntimeException('Invalid operation'); }
        $path = $this->storage . '/' . $operation;
        $file = is_file($path . '/completed.json') ? 'completed.json' : 'started.json';
        $record = json_decode((string) file_get_contents($path . '/' . $file), true, 32, JSON_THROW_ON_ERROR);
        if (($record['operation'] ?? '') !== $operation) { throw new \RuntimeException('Invalid receipt'); }
        return $record;
    }

    private function write(string $path, array $data): void {
        $bytes = json_encode($data, JSON_THROW_ON_ERROR);
        $f = fopen($path, 'x');
        if (!$f) { throw new \RuntimeException('Cannot persist receipt'); }
        try {
            if (fwrite($f, $bytes) !== strlen($bytes) || !fflush($f) || !fsync($f)) {
                throw new \RuntimeException('Cannot persist receipt');
            }
        } finally { fclose($f); }
    }
}
