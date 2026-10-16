<?php
declare(strict_types=1);
namespace Nicode\Maintenance;

/** Experimental transport core. Not registered as a Joomla HTTP route. */
final class BackupOperations
{
    public function __construct(private readonly string $storage, private readonly string $siteId,
        private readonly string $backupTokenHash) {
        if (!is_dir($storage) || is_link($storage)) { throw new \RuntimeException('Private operation storage required'); }
    }

    /** Provider is locally bound; callers cannot supply filesystem paths or executable commands. */
    public function handle(array $server, array $request, \Closure $capture): array {
        if (!in_array(strtolower((string) ($server['HTTPS'] ?? '')), ['on', '1'], true)) { return $this->error(403, 'https_required'); }
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $this->siteId)
            || !preg_match('/^[a-f0-9]{64}$/D', $this->backupTokenHash)) { return $this->error(503, 'backup_disabled'); }
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/Di', (string) ($server['HTTP_AUTHORIZATION'] ?? ''), $match)
            || !hash_equals($this->backupTokenHash, hash('sha256', $match[1]))) { return $this->error(401, 'unauthorized'); }
        if (($request['site_id'] ?? null) !== $this->siteId) { return $this->error(409, 'site_mismatch'); }
        $id = $request['operation'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9-]{7,63}$/D', $id)) { return $this->error(400, 'invalid_operation'); }
        $action = $request['action'] ?? null;
        if (!in_array($action, ['capture', 'status'], true)) { return $this->error(400, 'invalid_action'); }
        if (array_diff(array_keys($request), ['site_id', 'operation', 'action', 'confirmed'])) { return $this->error(400, 'unknown_fields'); }
        if (($server['REQUEST_METHOD'] ?? '') !== ($action === 'capture' ? 'POST' : 'GET')) { return $this->error(405, 'method_not_allowed'); }
        if ($action === 'capture' && ($request['confirmed'] ?? null) !== true) { return $this->error(400, 'confirmation_required'); }
        $path = $this->storage . '/' . $id;
        try {
            if ($action === 'status') { return $this->read($path, $id); }
            // Atomic directory reservation survives process death. Never remove or reuse it.
            if (!@mkdir($path, 0700)) { return $this->read($path, $id); }
            $record = ['site_id' => $this->siteId, 'operation' => $id, 'status' => 'uncertain'];
            $this->write($path . '/started.json', $record);
            // The provider must enforce quiescence and its own size/storage preconditions.
            $receipt = $capture($id);
            if (!is_array($receipt) || ($receipt['status'] ?? null) !== 'captured'
                || ($receipt['operation'] ?? null) !== $id
                || !is_string($receipt['manifest_sha256'] ?? null)
                || !preg_match('/^[a-f0-9]{64}$/D', $receipt['manifest_sha256'])) {
                throw new \RuntimeException('Invalid provider receipt');
            }
            // Allowlist result: never persist provider paths, diagnostics or secrets.
            $record['status'] = 'captured';
            $record['manifest_sha256'] = $receipt['manifest_sha256'];
            $record['restoration_verified'] = false;
            $this->write($path . '/completed.json', $record);
            return [200, $record];
        } catch (\Throwable) {
            // A provider may have made changes before failing. No automatic retry.
            return [202, ['site_id' => $this->siteId, 'operation' => $id, 'status' => 'uncertain']];
        }
    }

    private function read(string $path, string $id): array {
        if (!file_exists($path)) { return $this->error(404, 'operation_unknown'); }
        if (is_link($path) || !is_dir($path)) { return $this->error(503, 'operation_unreadable'); }
        $uncertain = [202, ['site_id' => $this->siteId, 'operation' => $id, 'status' => 'uncertain']];
        $started = $path . '/started.json';
        if (!is_file($started)) { return $uncertain; }
        $record = $this->load($started);
        if (($record['site_id'] ?? null) !== $this->siteId || ($record['operation'] ?? null) !== $id) {
            return $this->error(409, 'operation_identity_conflict');
        }
        $completed = $path . '/completed.json';
        if (!is_file($completed)) { return $uncertain; }
        $record = $this->load($completed);
        if (($record['site_id'] ?? null) !== $this->siteId || ($record['operation'] ?? null) !== $id
            || ($record['status'] ?? null) !== 'captured' || !is_string($record['manifest_sha256'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $record['manifest_sha256'])) { return $uncertain; }
        return [200, ['site_id' => $this->siteId, 'operation' => $id, 'status' => 'captured',
            'manifest_sha256' => $record['manifest_sha256'], 'restoration_verified' => false]];
    }

    private function load(string $path): array {
        if (is_link($path) || filesize($path) > 4096) { throw new \RuntimeException('Invalid record'); }
        $record = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($record)) { throw new \RuntimeException('Invalid record'); }
        return $record;
    }

    private function write(string $path, array $record): void {
        $data = json_encode($record, JSON_THROW_ON_ERROR);
        $file = @fopen($path, 'xb');
        if (!$file) { throw new \RuntimeException('Cannot reserve record'); }
        try {
            if (fwrite($file, $data) !== strlen($data) || !fflush($file) || !fsync($file)) {
                throw new \RuntimeException('Cannot persist record');
            }
        } finally { fclose($file); }
    }

    private function error(int $status, string $code): array { return [$status, ['error' => $code]]; }
}
