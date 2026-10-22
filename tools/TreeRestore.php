<?php
declare(strict_types=1);
namespace Nicode\Maintenance;
require_once __DIR__ . '/TreeManifest.php';

/** Offline restoration of trusted local chunk captures, never a remote endpoint.
 * @package Nicode.joomla.webmonitor
 */
final class TreeRestore
{
    private static function regular(string $path, bool $directory = false): void {
        $real = realpath($path);
        $normal = static fn($p) => strtolower(str_replace('\\', '/', $p));
        if (!$real || is_link($path) || $normal($real) !== $normal($path)
            || !($directory ? is_dir($path) : is_file($path))) {
            throw new \RuntimeException('Missing, linked or redirected path');
        }
    }

    private static function read(string $path, int $limit): array {
        self::regular($path);
        if (filesize($path) > $limit) { throw new \RuntimeException('Record size limit'); }
        return json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    }

    public function restore(string $capture, string $target, bool $confirmed): array {
        clearstatcache();
        if (!$confirmed) { throw new \RuntimeException('Isolated empty target confirmation required'); }
        // Require canonical absolute paths and exclusive access to both trees.
        self::regular($capture, true); self::regular($target, true);
        $a = strtolower(str_replace('\\', '/', $capture)); $b = strtolower(str_replace('\\', '/', $target));
        if ($a === $b || str_starts_with($a, $b . '/') || str_starts_with($b, $a . '/')) {
            throw new \RuntimeException('Capture and target overlap');
        }
        if (scandir($target) !== ['.', '..']) { throw new \RuntimeException('Target must be empty'); }
        $s = self::read($capture . '/state.json', 4096);
        $m = self::read($capture . '/manifests/initial-tree/state.json', 2097152);
        $entries = $m['entries'] ?? null;
        if (($s['phase'] ?? '') !== 'complete' || ($m['phase'] ?? '') !== 'complete'
            || !is_array($entries) || count($entries) > TreeManifest::MAX_ENTRIES
            || ($entries['']['type'] ?? '') !== 'directory'
            || ($s['manifest_sha256'] ?? '') !== hash('sha256', json_encode($entries, JSON_THROW_ON_ERROR))) {
            throw new \RuntimeException('Incomplete or invalid capture');
        }
        $seen = []; $bytes = 0; $files = 0;
        foreach ($entries as $key => $entry) {
            $key = (string) $key;
            if ($key !== '') {
                foreach (explode('/', $key) as $part) {
                    if ($part === '' || preg_match('/[\x00-\x1f<>:"|?*\\\\]/', $part)
                        || preg_match('/[. ]$/', $part)
                        || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\.|$)/i', $part)) {
                        throw new \RuntimeException('Nonportable entry path');
                    }
                }
                $parent = str_contains($key, '/') ? substr($key, 0, strrpos($key, '/')) : '';
                if (($seen[strtolower($parent)] ?? '') !== 'directory') { throw new \RuntimeException('Missing parent'); }
            }
            $fold = strtolower($key);
            if (isset($seen[$fold]) || !in_array($entry['type'] ?? '', ['directory', 'file'], true)) {
                throw new \RuntimeException('Duplicate or invalid entry');
            }
            $seen[$fold] = $entry['type'];
            if ($entry['type'] === 'file') {
                if (!is_int($entry['bytes'] ?? null) || $entry['bytes'] < 0
                    || !is_array($entry['chunks'] ?? null) || !array_is_list($entry['chunks'])
                    || count($entry['chunks']) !== (int) ceil($entry['bytes'] / TreeManifest::BLOCK)) {
                    throw new \RuntimeException('Invalid file manifest');
                }
                $bytes += $entry['bytes']; $files++;
                if ($bytes > TreeManifest::MAX_BYTES) { throw new \RuntimeException('Capture size limit'); }
            }
        }
        // Verify ALL chunks before creating any target content, then recheck each read.
        foreach ([false, true] as $write) {
            $index = 0;
            foreach ($entries as $key => $entry) {
                $path = $target . ($key === '' ? '' : '/' . $key);
                $chunks = $capture . '/files/' . sprintf('file-%04d', $index++);
                if ($entry['type'] === 'directory') {
                    if ($write && $key !== '' && !mkdir($path, 0700)) { throw new \RuntimeException('Cannot create directory'); }
                    continue;
                }
                $out = $write ? fopen($path, 'xb') : null;
                if ($write && !$out) { throw new \RuntimeException('Cannot create file; target may be partial'); }
                try {
                    foreach ($entry['chunks'] as $j => $hash) {
                        $chunk = $chunks . '/chunk-' . $j; self::regular($chunk);
                        $length = min(TreeManifest::BLOCK, $entry['bytes'] - $j * TreeManifest::BLOCK);
                        if (filesize($chunk) !== $length) { throw new \RuntimeException('Chunk size mismatch'); }
                        $data = file_get_contents($chunk);
                        if (!is_string($hash) || hash('sha256', $data) !== $hash) { throw new \RuntimeException('Chunk integrity failed'); }
                        if ($write && fwrite($out, $data) !== $length) { throw new \RuntimeException('Incomplete restore'); }
                    }
                    if ($write && (!fflush($out) || !fsync($out))) { throw new \RuntimeException('Cannot persist restore'); }
                } finally { if (is_resource($out)) { fclose($out); } }
            }
        }
        return ['status' => 'files_restored', 'files' => $files, 'bytes' => $bytes,
            'manifest_sha256' => $s['manifest_sha256'], 'application_verified' => false];
    }
}
