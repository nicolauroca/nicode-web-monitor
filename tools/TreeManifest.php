<?php
declare(strict_types=1);
namespace Nicode\Maintenance;

/** Local-only manifest builder. Caller must stop ALL writers for the entire operation. */
final class TreeManifest
{
    public const BLOCK = 1048576;
    public const MAX_ENTRIES = 32768;
    public const MAX_CHILDREN = 2048;
    public const MAX_CHECKPOINT = 16777216;
    public const MAX_BYTES = 1073741824;

    public function __construct(private readonly string $storage) {
        if (!is_dir($storage) || is_link($storage)) { throw new \RuntimeException('Private storage required'); }
    }

    private static function normal(string $path): string {
        return strtolower(str_replace('\\', '/', $path));
    }

    private static function resolve(string $root, string $relative): string {
        $path = $root . ($relative === '' ? '' : '/' . $relative);
        $real = realpath($path);
        if (!$real || is_link($path) || self::normal($real) !== self::normal($path)) {
            throw new \RuntimeException('Source missing, linked or redirected');
        }
        return $real;
    }

    /** A directory is limited to 2048 children; no unbounded recursive scan. */
    private static function children(string $path): array {
        $handle = opendir($path);
        if (!$handle) { throw new \RuntimeException('Unreadable directory'); }
        $names = []; $seen = [];
        try {
            while (($name = readdir($handle)) !== false) {
                if ($name === '.' || $name === '..') { continue; }
                if (count($names) >= self::MAX_CHILDREN
                    || preg_match('/[\x00-\x1f<>:"|?*\\\\]/', $name)
                    || preg_match('/[. ]$/', $name)
                    || preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(\.|$)/i', $name)
                    || isset($seen[strtolower($name)])) {
                    throw new \RuntimeException('Directory limit or nonportable name');
                }
                $seen[strtolower($name)] = true;
                $names[] = $name;
            }
        } finally { closedir($handle); }
        sort($names, SORT_STRING);
        return $names;
    }

    private static function save(string $path, array $state): void {
        $bytes = json_encode($state, JSON_THROW_ON_ERROR);
        if (strlen($bytes) > self::MAX_CHECKPOINT) { throw new \RuntimeException('Checkpoint size limit'); }
        $f = fopen($path . '.next', 'wb');
        if (!$f) { throw new \RuntimeException('Cannot write checkpoint'); }
        try {
            if (fwrite($f, $bytes) !== strlen($bytes) || !fflush($f) || !fsync($f)) {
                throw new \RuntimeException('Cannot persist checkpoint');
            }
        } finally { fclose($f); }
        if (!rename($path . '.next', $path)) { throw new \RuntimeException('Cannot commit checkpoint'); }
    }

    /** Each unit discovers one entry/directory OR hashes one block; a batch checkpoints once.
     * A second pass checks every directory listing and file block before completion.
     */
    public function step(string $source, string $id, bool $quiesced, int $units = 1): array {
        if ($units < 1 || $units > 64) { throw new \InvalidArgumentException('Invalid work budget'); }
        clearstatcache();
        if (!$quiesced || !preg_match('/^[a-z0-9][a-z0-9-]{7,63}$/D', $id)
            || is_link($source) || !is_dir($source)) { throw new \RuntimeException('Invalid source, identity or quiescence'); }
        $root = realpath($source); $storage = realpath($this->storage);
        $a = self::normal($root); $b = self::normal($storage);
        if ($a === $b || str_starts_with($b, $a . '/') || str_starts_with($a, $b . '/')) {
            throw new \RuntimeException('Storage must be outside source');
        }
        $dir = $storage . '/' . $id;
        if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) { throw new \RuntimeException('Cannot reserve operation'); }
        if (is_link($dir)) { throw new \RuntimeException('Invalid storage'); }
        $lock = fopen($dir . '/lock', 'c+b');
        if (!$lock) { throw new \RuntimeException('Cannot lock'); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('Operation busy'); }
            $record = $dir . '/state.json';
            if (is_file($record)) {
                if (is_link($record) || filesize($record) > self::MAX_CHECKPOINT) { throw new \RuntimeException('Invalid checkpoint'); }
                $s = json_decode(file_get_contents($record), true, 32, JSON_THROW_ON_ERROR);
                if (($s['root'] ?? null) !== $root || !in_array($s['phase'] ?? null, ['discover', 'verify', 'complete'], true)
                    || !is_array($s['entries'] ?? null) || !is_array($s['pending'] ?? null)
                    || !is_int($s['cursor'] ?? null) || !is_int($s['block'] ?? null)) {
                    throw new \RuntimeException('Checkpoint or identity conflict');
                }
            } else {
                if (file_exists($dir . '/bound')) { throw new \RuntimeException('Initialization uncertain'); }
                $bound = fopen($dir . '/bound', 'xb');
                if (!$bound) { throw new \RuntimeException('Cannot bind'); }
                fclose($bound);
                $s = ['root' => $root, 'phase' => 'discover', 'pending' => [''], 'entries' => [],
                    'active' => null, 'cursor' => 0, 'block' => 0, 'bytes' => 0];
                self::save($record, $s);
            }
            $deadline = microtime(true) + 0.2;
            for ($unit = 0; $unit < $units && $s['phase'] !== 'complete'; $unit++) {
                if ($s['phase'] === 'discover') {
                    if ($s['active'] !== null) {
                        $key = $s['active']; $entry = &$s['entries'][$key];
                        $entry['chunks'][] = self::digest($root, $key, $entry['bytes'], count($entry['chunks']));
                        if (count($entry['chunks']) === (int) ceil($entry['bytes'] / self::BLOCK)) { $s['active'] = null; }
                        unset($entry);
                    } elseif ($s['pending']) {
                        $key = array_shift($s['pending']); $path = self::resolve($root, $key);
                        if (is_dir($path)) {
                            $names = self::children($path);
                            if (count($s['entries']) + count($s['pending']) + count($names) + 1 > self::MAX_ENTRIES) {
                                throw new \RuntimeException('Tree entry limit');
                            }
                            $s['entries'][$key] = ['type' => 'directory', 'children' => $names];
                            foreach ($names as $name) { $s['pending'][] = $key === '' ? $name : $key . '/' . $name; }
                        } elseif (is_file($path)) {
                            $size = filesize($path);
                            if ($size === false || $size + $s['bytes'] > self::MAX_BYTES) { throw new \RuntimeException('Tree byte limit'); }
                            $s['bytes'] += $size;
                            $s['entries'][$key] = ['type' => 'file', 'bytes' => $size, 'chunks' => []];
                            if ($size > 0) { $s['active'] = $key; }
                        } else { throw new \RuntimeException('Special file unsupported'); }
                    }
                    if (!$s['pending'] && $s['active'] === null) { $s['phase'] = 'verify'; }
                } elseif ($s['phase'] === 'verify') {
                    $keys = array_keys($s['entries']); $key = (string) $keys[$s['cursor']];
                    $entry = $s['entries'][$key]; $path = self::resolve($root, $key);
                    if ($entry['type'] === 'directory') {
                        if (!is_dir($path) || self::children($path) !== $entry['children']) { throw new \RuntimeException('Directory changed'); }
                        $s['cursor']++;
                    } else {
                        if (!is_file($path) || filesize($path) !== $entry['bytes']) { throw new \RuntimeException('File changed'); }
                        if ($entry['bytes'] > 0 && self::digest($root, $key, $entry['bytes'], $s['block']) !== $entry['chunks'][$s['block']]) {
                            throw new \RuntimeException('File content changed');
                        }
                        $s['block']++;
                        if ($s['block'] >= count($entry['chunks'])) { $s['block'] = 0; $s['cursor']++; }
                    }
                    if ($s['cursor'] === count($keys)) { $s['phase'] = 'complete'; }
                }
                if (microtime(true) >= $deadline) { break; }
            }
            self::save($record, $s);
            return ['operation' => $id, 'status' => $s['phase'], 'entries' => count($s['entries']),
                'bytes' => $s['bytes'], 'manifest_sha256' => $s['phase'] === 'complete'
                    ? hash('sha256', json_encode($s['entries'], JSON_THROW_ON_ERROR)) : null];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function digest(string $root, string $key, int $size, int $block): string {
        $path = self::resolve($root, $key);
        if (!is_file($path) || filesize($path) !== $size) { throw new \RuntimeException('File changed'); }
        $f = fopen($path, 'rb');
        if (!$f) { throw new \RuntimeException('Unreadable file'); }
        try {
            if (fseek($f, $block * self::BLOCK) !== 0) { throw new \RuntimeException('Cannot seek'); }
            $length = min(self::BLOCK, $size - $block * self::BLOCK);
            $bytes = stream_get_contents($f, $length);
            if ($bytes === false || strlen($bytes) !== $length) { throw new \RuntimeException('File changed'); }
            return hash('sha256', $bytes);
        } finally { fclose($f); }
    }
}
