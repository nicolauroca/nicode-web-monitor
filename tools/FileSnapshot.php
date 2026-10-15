<?php
declare(strict_types=1);
namespace Nicode\Maintenance;

/** Trusted, private, quiesced local trees only. No HTTP entry point. */
final class FileSnapshot
{
    public function __construct(private readonly string $storage, private readonly int $maxBytes = 1073741824,
        private readonly int $maxFiles = 100000) {
        if (!is_dir($storage) || is_link($storage) || $maxBytes < 1 || $maxFiles < 1) {
            throw new \RuntimeException('Private storage required');
        }
    }

    private function path(string $id): string {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{7,63}$/D', $id)) { throw new \RuntimeException('Invalid operation'); }
        return $this->storage . '/' . $id;
    }

    private function reserve(string $id): string {
        $path = $this->path($id);
        if (!@mkdir($path, 0700)) { throw new \RuntimeException('Operation exists or cannot be reserved'); }
        self::write($path . '/started.json', ['status' => 'uncertain', 'operation' => $id]);
        return $path;
    }

    private static function write(string $path, array $data): void {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $f = fopen($path, 'xb');
        if (!$f) { throw new \RuntimeException('Cannot create record'); }
        try { if (fwrite($f, $json) !== strlen($json)) { throw new \RuntimeException('Incomplete record'); } }
        finally { fclose($f); }
    }

    private static function nested(string $child, string $parent): bool {
        $normalize = static fn($p) => strtolower(str_replace('\\', '/', rtrim($p, '/\\')));
        return $normalize($child) === $normalize($parent) || str_starts_with($normalize($child), $normalize($parent) . '/');
    }

    /** Reject links, junctions/resolved escapes, special files and ambiguous portable names. */
    private function scan(string $root): array {
        $root = realpath($root) ?: throw new \RuntimeException('Missing tree');
        $entries = []; $bytes = 0; $seen = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $resolved = $file->getRealPath();
            $expected = str_replace('\\', '/', $root) . '/' . $relative;
            if ($file->isLink() || !$resolved || strcasecmp(str_replace('\\', '/', $resolved), $expected) !== 0
                || (!$file->isDir() && !$file->isFile())) { throw new \RuntimeException('Links and special files unsupported'); }
            foreach (explode('/', $relative) as $part) {
                if (preg_match('/[\x00-\x1f<>:"|?*\\\\]/', $part) || preg_match('/[. ]$/', $part)
                    || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\.|$)/i', $part)) {
                    throw new \RuntimeException('Nonportable file name');
                }
            }
            $key = strtolower($relative);
            if (isset($seen[$key])) { throw new \RuntimeException('Case collision'); }
            $seen[$key] = true;
            $size = $file->isDir() ? 0 : $file->getSize(); $bytes += $size;
            if ($bytes > $this->maxBytes || count($entries) >= $this->maxFiles) { throw new \RuntimeException('Snapshot limit'); }
            $hash = $file->isDir() ? null : hash_file('sha256', $file->getPathname());
            if ($hash === false) { throw new \RuntimeException('Unreadable file'); }
            $entries[$relative] = ['type' => $file->isDir() ? 'directory' : 'file', 'bytes' => $size, 'sha256' => $hash];
        }
        ksort($entries, SORT_STRING);
        return $entries;
    }

    private static function copyTree(string $source, string $target, array $entries): void {
        if (!@mkdir($target, 0700)) { throw new \RuntimeException('Target must not exist'); }
        foreach ($entries as $relative => $entry) {
            $dest = $target . '/' . $relative;
            if ($entry['type'] === 'directory') {
                if (!mkdir($dest, 0700)) { throw new \RuntimeException('Cannot create directory'); }
            } else {
                $in = fopen($source . '/' . $relative, 'rb'); $out = fopen($dest, 'xb');
                if (!$in || !$out) { throw new \RuntimeException('Cannot copy file'); }
                try {
                    if (stream_copy_to_stream($in, $out) !== $entry['bytes']) { throw new \RuntimeException('File changed or copy failed'); }
                } finally { fclose($in); fclose($out); }
                chmod($dest, 0600);
            }
        }
    }

    public function capture(string $source, string $id, bool $quiesced): array {
        if (!$quiesced || is_link($source) || !is_dir($source)) { throw new \RuntimeException('Stopped writers and regular source required'); }
        if (self::nested(realpath($this->storage), realpath($source)) || self::nested(realpath($source), realpath($this->storage))) {
            throw new \RuntimeException('Storage must be outside source');
        }
        $entries = $this->scan($source);
        $path = $this->reserve($id);
        self::copyTree($source, $path . '/files', $entries);
        if ($this->scan($path . '/files') !== $entries || $this->scan($source) !== $entries) { throw new \RuntimeException('Source changed or copy mismatch'); }
        self::write($path . '/manifest.json', $entries);
        $record = ['status' => 'captured', 'format' => 'nwm-files-1', 'operation' => $id,
            'entries' => count($entries), 'bytes' => array_sum(array_column($entries, 'bytes')),
            'manifest_sha256' => hash_file('sha256', $path . '/manifest.json'), 'restoration_verified' => false];
        self::write($path . '/completed.json', $record);
        return $record;
    }

    public function restore(string $snapshotId, string $id, string $target, bool $confirmed): array {
        if (!$confirmed || file_exists($target) || is_link($target) || !is_dir(dirname($target))) { throw new \RuntimeException('Confirmed nonexistent isolated target required'); }
        $source = $this->path($snapshotId);
        $state = json_decode(file_get_contents($source . '/completed.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($state['status'] ?? '') !== 'captured' || ($state['format'] ?? '') !== 'nwm-files-1'
            || !hash_equals($state['manifest_sha256'], hash_file('sha256', $source . '/manifest.json'))) { throw new \RuntimeException('Incomplete or corrupt snapshot'); }
        // Never use manifest paths to write: compare with a fresh, validated physical scan first.
        $entries = $this->scan($source . '/files');
        if ($entries !== json_decode(file_get_contents($source . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR)) { throw new \RuntimeException('Corrupt snapshot'); }
        if (self::nested(realpath(dirname($target)), realpath($source))) { throw new \RuntimeException('Target overlaps snapshot'); }
        $path = $this->reserve($id);
        self::copyTree($source . '/files', $target, $entries);
        if ($this->scan($target) !== $entries) { throw new \RuntimeException('Restored content mismatch'); }
        $record = ['status' => 'restored', 'operation' => $id, 'source' => $snapshotId,
            'entries' => count($entries), 'bytes' => $state['bytes'], 'application_verified' => false];
        self::write($path . '/completed.json', $record);
        return $record;
    }
}
