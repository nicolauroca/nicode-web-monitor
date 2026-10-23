<?php
declare(strict_types=1);
namespace Nicode\Maintenance;

/** Sequenced HTTP journal for file-only captures. Authentication belongs to the adapter.
 * @package Nicode.joomla.webmonitor
 */
final class TreeOperations
{
    public function __construct(private readonly string $storage, private readonly string $siteId) {}

    public function handle(array $server, array $request, \Closure $step): array {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $this->siteId)) {
            return [503, ['error' => 'backup_disabled']];
        }
        if (($request['site_id'] ?? null) !== $this->siteId) { return [409, ['error' => 'site_mismatch']]; }
        $id = $request['operation'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-z0-9][a-z0-9-]{7,63}$/D', $id)) { return [400, ['error' => 'invalid_operation']]; }
        $action = $request['action'] ?? null;
        if (!in_array($action, ['step', 'status'], true)) { return [400, ['error' => 'invalid_action']]; }
        $write = $action === 'step';
        if (array_diff(array_keys($request), $write ? ['site_id','operation','action','confirmed','sequence'] : ['site_id','operation','action'])) {
            return [400, ['error' => 'unknown_fields']];
        }
        if (($server['REQUEST_METHOD'] ?? '') !== ($write ? 'POST' : 'GET')) { return [405, ['error' => 'method_not_allowed']]; }
        if ($write && (($request['confirmed'] ?? null) !== true || !is_int($request['sequence'] ?? null)
            || $request['sequence'] < 0 || $request['sequence'] > 1000000)) { return [400, ['error' => 'invalid_step']]; }
        $dir = $this->storage . '/' . $id;
        if (!file_exists($dir)) {
            if (!$write) { return [404, ['error' => 'operation_unknown']]; }
            if ($request['sequence'] !== 0) { return [409, ['error' => 'sequence_conflict']]; }
            if (!@mkdir($dir, 0700) && !is_dir($dir)) { return [503, ['error' => 'storage_unavailable']]; }
        }
        if (is_link($dir) || !is_dir($dir) || is_link($dir . '/lock')) { return [503, ['error' => 'storage_unavailable']]; }
        $lock = fopen($dir . '/lock', 'c+b');
        if (!$lock) { return [503, ['error' => 'storage_unavailable']]; }
        $unknown = ['site_id'=>$this->siteId, 'operation'=>$id, 'status'=>'uncertain', 'scope'=>'files_only'];
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { return [409, ['error'=>'operation_busy']]; }
            $path = $dir . '/state.json';
            if (is_file($path)) {
                if (is_link($path) || filesize($path) > 4096) { throw new \RuntimeException('Invalid journal'); }
                $s = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
                if (($s['site_id'] ?? null) !== $this->siteId || ($s['operation'] ?? null) !== $id) {
                    return [409, ['error'=>'operation_identity_conflict']];
                }
                if (!is_int($s['next_sequence'] ?? null) || !in_array($s['status'] ?? null, ['ready','progress','complete','uncertain'], true)) {
                    throw new \RuntimeException('Invalid journal');
                }
            } else {
                if (file_exists($dir . '/bound') || !$write) { return [202, $unknown]; }
                $bound = fopen($dir . '/bound', 'xb');
                if (!$bound) { throw new \RuntimeException('Cannot bind'); } fclose($bound);
                $s = $unknown + ['next_sequence'=>0, 'restoration_verified'=>false];
                $s['status'] = 'ready'; self::save($path, $s);
            }
            if ($s['status'] === 'uncertain') { return [202, $unknown]; }
            if (!$write || $s['status'] === 'complete' || $request['sequence'] === $s['next_sequence'] - 1) { return [200, $s]; }
            if ($request['sequence'] !== $s['next_sequence']) { return [409, ['error'=>'sequence_conflict']]; }
            // A process death after this point is uncertain, even if the provider checkpointed.
            // Do not advance again automatically after losing the journal receipt.
            $s['status'] = 'uncertain'; self::save($path, $s);
            $r = $step($id);
            if (($r['operation'] ?? null) !== $id || !in_array($r['status'] ?? null, ['manifest','capture','final','complete'], true)
                || !is_int($r['cursor'] ?? null) || $r['cursor'] < 0
                || (($r['manifest_sha256'] ?? null) !== null && (!is_string($r['manifest_sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $r['manifest_sha256'])))
                || ($r['status'] === 'complete' && !is_string($r['manifest_sha256'] ?? null))) { throw new \RuntimeException('Invalid receipt'); }
            $s['status'] = $r['status'] === 'complete' ? 'complete' : 'progress';
            $s['phase'] = $r['status']; $s['cursor'] = $r['cursor'];
            $s['manifest_sha256'] = $r['manifest_sha256'] ?? null;
            $s['next_sequence']++; self::save($path, $s);
            return [200, $s];
        } catch (\Throwable) { return [202, $unknown]; }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function save(string $path, array $record): void {
        $data = json_encode($record, JSON_THROW_ON_ERROR); $file = fopen($path . '.next', 'wb');
        if (!$file) { throw new \RuntimeException('Cannot write'); }
        try {
            if (fwrite($file, $data) !== strlen($data) || !fflush($file) || !fsync($file)) { throw new \RuntimeException('Cannot persist'); }
        } finally { fclose($file); }
        if (!rename($path . '.next', $path)) { throw new \RuntimeException('Cannot commit'); }
    }
}
