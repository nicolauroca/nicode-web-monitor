<?php
declare(strict_types=1);
namespace Nicode\Maintenance;
require_once __DIR__ . '/TreeManifest.php';
require_once __DIR__ . '/ResumableFile.php';

/** Local-only capture coordinator. All writers must remain stopped.
 * @package Nicode.joomla.webmonitor
 */
final class ResumableTree
{
    public function __construct(private readonly string $storage) {}

    public function step(string $source, string $id, bool $quiesced, int $units = 1): array {
        if ($units < 1 || $units > 64) { throw new \InvalidArgumentException('Invalid work budget'); }
        clearstatcache();
        $root = realpath($source); $base = realpath($this->storage);
        if (!$quiesced || !$root || !$base || !is_dir($root) || is_link($source)
            || is_link($this->storage) || !preg_match('/^[a-z0-9][a-z0-9-]{7,63}$/D', $id)) {
            throw new \RuntimeException('Invalid source, storage, identity or quiescence');
        }
        $normal = static fn(string $p) => strtolower(str_replace('\\', '/', $p));
        $a = $normal($root); $b = $normal($base);
        if ($a === $b || str_starts_with($a, $b . '/') || str_starts_with($b, $a . '/')) {
            throw new \RuntimeException('Storage must be outside source');
        }
        $dir = $base . '/' . $id;
        if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) { throw new \RuntimeException('Cannot reserve'); }
        if (is_link($dir)) { throw new \RuntimeException('Invalid storage'); }
        $lock = fopen($dir . '/lock', 'c+b');
        if (!$lock) { throw new \RuntimeException('Cannot lock'); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('Operation busy'); }
            $record = $dir . '/state.json';
            if (is_file($record)) {
                if (is_link($record) || filesize($record) > 4096) { throw new \RuntimeException('Invalid checkpoint'); }
                $s = json_decode(file_get_contents($record), true, 16, JSON_THROW_ON_ERROR);
                if (($s['root'] ?? null) !== $root || !in_array($s['phase'] ?? null, ['manifest', 'capture', 'final', 'complete'], true)
                    || !is_int($s['cursor'] ?? null) || $s['cursor'] < 0 || $s['cursor'] > TreeManifest::MAX_ENTRIES) {
                    throw new \RuntimeException('Checkpoint or identity conflict');
                }
            } else {
                if (file_exists($dir . '/bound')) { throw new \RuntimeException('Initialization uncertain'); }
                $f = fopen($dir . '/bound', 'xb');
                if (!$f) { throw new \RuntimeException('Cannot bind'); } fclose($f);
                foreach (['manifests', 'files'] as $child) {
                    if (!mkdir($dir . '/' . $child, 0700)) { throw new \RuntimeException('Cannot initialize'); }
                }
                $s = ['root' => $root, 'phase' => 'manifest', 'cursor' => 0, 'manifest_sha256' => null];
                self::save($record, $s);
            }
            $builder = new TreeManifest($dir . '/manifests');
            if ($s['phase'] === 'manifest') {
                $r = $builder->step($root, 'initial-tree', true, $units);
                if ($r['status'] === 'complete') {
                    $s['manifest_sha256'] = $r['manifest_sha256']; $s['phase'] = 'capture';
                }
            } elseif ($s['phase'] === 'capture') {
                $manifestPath = $dir . '/manifests/initial-tree/state.json';
                if (is_link($manifestPath) || filesize($manifestPath) > TreeManifest::MAX_CHECKPOINT) { throw new \RuntimeException('Invalid manifest'); }
                $m = json_decode(file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
                $entries = $m['entries'];
                if (!hash_equals($s['manifest_sha256'], hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR)))) {
                    throw new \RuntimeException('Frozen manifest changed');
                }
                $keys = array_keys($entries);
                $deadline = microtime(true) + 0.2;
                for ($unit = 0; $unit < $units; $unit++) {
                    if ($s['cursor'] >= count($keys)) { throw new \RuntimeException('Invalid capture cursor'); }
                    $key = (string) $keys[$s['cursor']]; $entry = $entries[$key];
                    if ($entry['type'] === 'directory') { $s['cursor']++; }
                    else {
                        $r = (new ResumableFile($dir . '/files'))->step($root . '/' . $key,
                            sprintf('file-%04d', $s['cursor']), ['bytes' => $entry['bytes'], 'chunks' => $entry['chunks']], true);
                        if ($r['status'] === 'complete') { $s['cursor']++; }
                    }
                    if ($s['cursor'] === count($keys)) { $s['phase'] = 'final'; break; }
                    if (microtime(true) >= $deadline) { break; }
                }
            } elseif ($s['phase'] === 'final') {
                $r = $builder->step($root, 'final-tree', true, $units);
                if ($r['status'] === 'complete') {
                    if (!hash_equals($s['manifest_sha256'], $r['manifest_sha256'])) { throw new \RuntimeException('Source tree changed'); }
                    $s['phase'] = 'complete';
                }
            }
            self::save($record, $s);
            return ['operation' => $id, 'status' => $s['phase'], 'cursor' => $s['cursor'],
                'manifest_sha256' => $s['manifest_sha256'], 'format' => 'nwm-tree-chunks-1', 'restoration_verified' => false];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function save(string $path, array $state): void {
        $bytes = json_encode($state, JSON_THROW_ON_ERROR);
        $f = fopen($path . '.next', 'wb');
        if (!$f) { throw new \RuntimeException('Cannot write checkpoint'); }
        try {
            if (fwrite($f, $bytes) !== strlen($bytes) || !fflush($f) || !fsync($f)) { throw new \RuntimeException('Cannot persist'); }
        } finally { fclose($f); }
        if (!rename($path . '.next', $path)) { throw new \RuntimeException('Cannot commit'); }
    }
}
