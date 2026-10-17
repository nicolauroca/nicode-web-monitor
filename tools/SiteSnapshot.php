<?php
declare(strict_types=1);
namespace Nicode\Maintenance;

/** Local provider: caller keeps every file/DB writer stopped throughout capture. */
final class SiteSnapshot
{
    public function __construct(private readonly string $storage,
        private readonly int $fileLimit = 1073741824, private readonly int $entryLimit = 100000,
        private readonly int $databaseLimit = 134217728) {
        if (!is_dir($storage) || is_link($storage) || min($fileLimit, $entryLimit, $databaseLimit) < 1) {
            throw new \RuntimeException('Private storage and positive limits required');
        }
    }

    private static function write(string $path, array $data): void {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $file = @fopen($path, 'xb');
        if (!$file) { throw new \RuntimeException('Cannot create site record'); }
        try {
            if (fwrite($file, $json) !== strlen($json) || !fflush($file) || !fsync($file)) {
                throw new \RuntimeException('Cannot persist site record');
            }
        } finally { fclose($file); }
    }

    public function capture(\mysqli $database, string $source, string $id, bool $quiesced): array {
        if (!$quiesced || !preg_match('/^[a-z0-9][a-z0-9-]{7,63}$/D', $id)) {
            throw new \RuntimeException('Stopped writers and valid operation required');
        }
        $normalize = static fn($p) => strtolower(str_replace('\\', '/', rtrim($p, '/\\')));
        $sourcePath = realpath($source);
        $storePath = realpath($this->storage);
        if (!$sourcePath || !$storePath || !is_dir($source) || is_link($source)) {
            throw new \RuntimeException('Regular source required');
        }
        $a = $normalize($sourcePath); $b = $normalize($storePath);
        if ($a === $b || str_starts_with($a, $b . '/') || str_starts_with($b, $a . '/')) {
            throw new \RuntimeException('Storage and source must not overlap');
        }
        $path = $this->storage . '/' . $id;
        if (!@mkdir($path, 0700)) { throw new \RuntimeException('Operation reserved; inspect, never replay'); }
        self::write($path . '/started.json', ['operation' => $id, 'status' => 'uncertain']);
        foreach (['files', 'database'] as $part) {
            if (!mkdir($path . '/' . $part, 0700)) { throw new \RuntimeException('Cannot prepare capture'); }
        }
        // Distinct namespaces permit the same identity for both artifacts.
        // Neither partial completion is a completed site backup.
        $files = (new FileSnapshot($path . '/files', $this->fileLimit, $this->entryLimit))
            ->capture($source, $id, true);
        $db = (new DatabaseSnapshot($path . '/database', $this->databaseLimit))
            ->capture($database, $id, true);
        $manifest = ['format' => 'nwm-site-1', 'operation' => $id,
            'files' => ['format' => $files['format'], 'manifest_sha256' => $files['manifest_sha256'],
                'entries' => $files['entries'], 'bytes' => $files['bytes']],
            'database' => ['format' => $db['format'], 'sha256' => $db['sha256'],
                'tables' => $db['tables'], 'rows' => $db['rows'], 'bytes' => $db['bytes']],
            'writer_quiescence' => 'caller_acknowledged', 'restoration_verified' => false];
        self::write($path . '/manifest.json', $manifest);
        $receipt = ['status' => 'captured', 'operation' => $id,
            'manifest_sha256' => hash_file('sha256', $path . '/manifest.json'), 'restoration_verified' => false];
        self::write($path . '/completed.json', $receipt);
        return $receipt;
    }
}
