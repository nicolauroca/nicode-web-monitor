<?php
declare(strict_types=1);
namespace Nicode\Maintenance;

/** Local chunk store. A trusted caller supplies a frozen manifest and stops writers.
 * This primitive is deliberately not exposed by the synchronous HTTP backup route.
 */
final class ResumableFile
{
    public const BLOCK = 1048576;

    public function __construct(private readonly string $storage) {
        if (!is_dir($storage) || is_link($storage)) { throw new \RuntimeException('Private storage required'); }
    }

    /** One call copies or verifies at most one 1 MiB block. Paths are local, never HTTP input. */
    public function step(string $source, string $id, array $manifest, bool $quiesced): array {
        if (!$quiesced || !preg_match('/^[a-z0-9][a-z0-9-]{7,63}$/D', $id)
            || array_keys($manifest) !== ['bytes', 'chunks'] || !is_int($manifest['bytes'])
            || $manifest['bytes'] < 0 || $manifest['bytes'] > 1073741824
            || !is_array($manifest['chunks']) || !array_is_list($manifest['chunks'])
            || count($manifest['chunks']) !== (int) ceil($manifest['bytes'] / self::BLOCK)) {
            throw new \RuntimeException('Frozen manifest, stopped writers and valid identity required');
        }
        foreach ($manifest['chunks'] as $hash) {
            if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) { throw new \RuntimeException('Invalid digest'); }
        }
        clearstatcache();
        $sourcePath = realpath($source);
        if (!$sourcePath || is_link($source) || !is_file($source) || filesize($source) !== $manifest['bytes']) {
            throw new \RuntimeException('Source changed or unavailable');
        }
        $normal = static fn(string $p) => strtolower(str_replace('\\', '/', $p));
        if (str_starts_with($normal($sourcePath), rtrim($normal(realpath($this->storage)), '/') . '/')) {
            throw new \RuntimeException('Source must be outside operation storage');
        }
        $path = $this->storage . '/' . $id;
        if (!is_dir($path) && !@mkdir($path, 0700) && !is_dir($path)) { throw new \RuntimeException('Cannot reserve operation'); }
        if (is_link($path)) { throw new \RuntimeException('Invalid operation storage'); }
        // A private directory and a nonblocking OS lock serialize requests and release on death.
        $lock = fopen($path . '/lock', 'c+b');
        if (!$lock) { throw new \RuntimeException('Cannot lock operation'); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('Operation busy'); }
            $binding = hash('sha256', $sourcePath . "\0" . json_encode($manifest, JSON_THROW_ON_ERROR));
            $record = $path . '/state.json';
            if (is_file($record)) {
                if (is_link($record) || filesize($record) > 4096) { throw new \RuntimeException('Invalid checkpoint'); }
                $state = json_decode(file_get_contents($record), true, 16, JSON_THROW_ON_ERROR);
                if (($state['binding'] ?? null) !== $binding) { throw new \RuntimeException('Operation identity conflict'); }
                if (!in_array($state['phase'] ?? null, ['copy', 'verify', 'complete'], true)
                    || !is_int($state['cursor'] ?? null) || $state['cursor'] < 0
                    || $state['cursor'] > count($manifest['chunks'])) { throw new \RuntimeException('Invalid checkpoint'); }
            } else {
                // An orphan reservation cannot be rebound to a different input after a crash.
                if (file_exists($path . '/bound')) { throw new \RuntimeException('Initialization uncertain'); }
                $bound = fopen($path . '/bound', 'xb');
                if (!$bound) { throw new \RuntimeException('Cannot bind operation'); }
                fclose($bound);
                $state = ['binding' => $binding, 'phase' => 'copy', 'cursor' => 0];
                self::save($record, $state);
            }
            $i = $state['cursor']; $count = count($manifest['chunks']);
            if ($state['phase'] !== 'complete' && $i < $count) {
                $length = min(self::BLOCK, $manifest['bytes'] - $i * self::BLOCK);
                $input = fopen($sourcePath, 'rb');
                if (!$input) { throw new \RuntimeException('Unreadable source'); }
                try {
                    if (fseek($input, $i * self::BLOCK) !== 0) { throw new \RuntimeException('Cannot seek'); }
                    $bytes = stream_get_contents($input, $length);
                } finally { fclose($input); }
                if (!is_string($bytes) || strlen($bytes) !== $length
                    || !hash_equals($manifest['chunks'][$i], hash('sha256', $bytes))) {
                    throw new \RuntimeException('Source differs from frozen manifest');
                }
                $chunk = $path . '/chunk-' . $i;
                if ($state['phase'] === 'copy') {
                    // Interrupted uncommitted block is safely replaced, never appended.
                    self::atomic($chunk, $bytes);
                } elseif (!is_file($chunk) || is_link($chunk) || filesize($chunk) !== $length
                    || !hash_equals($manifest['chunks'][$i], hash_file('sha256', $chunk))) {
                    throw new \RuntimeException('Captured block corrupt');
                }
                $state['cursor']++;
            }
            if ($state['cursor'] === $count && $state['phase'] !== 'complete') {
                $state['phase'] = $state['phase'] === 'copy' ? 'verify' : 'complete';
                $state['cursor'] = 0;
            }
            self::save($record, $state);
            return ['operation' => $id, 'status' => $state['phase'], 'cursor' => $state['cursor'],
                'chunks' => $count, 'bytes' => $manifest['bytes'], 'format' => 'nwm-file-chunks-1',
                'manifest_sha256' => hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR)),
                'restoration_verified' => false];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function save(string $path, array $state): void {
        self::atomic($path, json_encode($state, JSON_THROW_ON_ERROR));
    }

    private static function atomic(string $path, string $bytes): void {
        $temp = $path . '.next';
        $file = fopen($temp, 'wb');
        if (!$file) { throw new \RuntimeException('Cannot prepare checkpoint'); }
        try {
            if (fwrite($file, $bytes) !== strlen($bytes) || !fflush($file) || !fsync($file)) {
                throw new \RuntimeException('Cannot persist checkpoint');
            }
        } finally { fclose($file); }
        if (!rename($temp, $path)) { throw new \RuntimeException('Cannot commit checkpoint'); }
    }
}
