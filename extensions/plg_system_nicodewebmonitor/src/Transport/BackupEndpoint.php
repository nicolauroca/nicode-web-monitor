<?php
declare(strict_types=1);
namespace Nicode\Plugin\System\NicodeWebMonitor\Transport;

use Nicode\Plugin\System\NicodeWebMonitor\Maintenance\BackupOperations;

/** Bounded HTTP adapter. Private paths and provider are supplied locally only. */
final class BackupEndpoint
{
    public function __construct(private string $siteId, private string $tokenHash,
        private string $inventoryHash, private string $storage, private string $webRoot, private bool $tree = false) {}

    public function handle(array $server, array $query, string $body, \Closure $capture): array
    {
        if (!in_array(strtolower((string) ($server['HTTPS'] ?? '')), ['on', '1'], true)) {
            return [403, ['error' => 'https_required']];
        }
        // A separate permission requires a genuinely separate credential.
        if (!preg_match('/^[a-f0-9]{64}$/D', $this->tokenHash) || $this->tokenHash === $this->inventoryHash) {
            return [503, ['error' => 'backup_disabled']];
        }
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/Di', (string) ($server['HTTP_AUTHORIZATION'] ?? ''), $match)
            || !hash_equals($this->tokenHash, hash('sha256', $match[1]))) {
            return [401, ['error' => 'unauthorized']];
        }
        $method = $server['REQUEST_METHOD'] ?? '';
        if (!in_array($method, ['GET', 'POST'], true)) { return [405, ['error' => 'method_not_allowed']]; }
        if (strlen($body) > 4096) { return [413, ['error' => 'request_too_large']]; }
        unset($query['option'], $query['task']);
        if ($method === 'POST') {
            if ($query) { return [400, ['error' => 'query_not_allowed']]; }
            if (strtolower(trim(explode(';', $server['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
                return [415, ['error' => 'json_required']];
            }
            try { $request = json_decode($body, true, 8, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { return [400, ['error' => 'invalid_json']]; }
            if (!is_array($request) || array_is_list($request)) { return [400, ['error' => 'invalid_json']]; }
        } else {
            if ($body !== '') { return [400, ['error' => 'body_not_allowed']]; }
            $request = $query;
        }
        try {
            self::privateDirectory($this->storage, $this->webRoot);
            if ($this->tree) {
                self::privateDirectory($this->storage . '/tree-operations', $this->webRoot);
                return (new \Nicode\Plugin\System\NicodeWebMonitor\Maintenance\TreeOperations($this->storage . '/tree-operations', $this->siteId))
                    ->handle($server, $request, $capture);
            }
            self::privateDirectory($this->storage . '/operations', $this->webRoot);
            return (new BackupOperations($this->storage . '/operations', $this->siteId, $this->tokenHash))
                ->handle($server, $request, $capture);
        } catch (\Throwable) { return [503, ['error' => 'backup_unavailable']]; }
    }

    public static function privateDirectory(string $path, string $webRoot): void
    {
        $actual = realpath($path); $web = realpath($webRoot);
        if (!$actual || !$web || !is_dir($path) || is_link($path)) { throw new \RuntimeException('Private directory required'); }
        $normalize = static fn($p) => strtolower(str_replace('\\', '/', rtrim($p, '/\\')));
        $a = $normalize($actual); $b = $normalize($web);
        if ($a === $b || str_starts_with($a, $b . '/') || str_starts_with($b, $a . '/')) {
            throw new \RuntimeException('Private directory must not overlap web root');
        }
    }
}
