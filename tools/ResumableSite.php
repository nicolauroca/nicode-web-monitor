<?php
declare(strict_types=1);
namespace Nicode\Maintenance;

/** Bounded joint capture, called only inside an uncertainty-preserving operation journal.
 * All writers remain stopped across files, database and final file verification.
 * @package Nicode.joomla.webmonitor
 */
final class ResumableSite
{
    public function __construct(private readonly string $storage) {}

    public function step(string $id, \Closure $files, \Closure $database, \Closure $verify): array {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{7,63}$/D', $id)
            || !is_dir($this->storage) || is_link($this->storage)) { throw new \RuntimeException('Invalid storage or identity'); }
        $dir = $this->storage . '/' . $id;
        if (!is_dir($dir) && !@mkdir($dir, 0700) && !is_dir($dir)) { throw new \RuntimeException('Cannot reserve'); }
        if (is_link($dir) || is_link($dir . '/lock')) { throw new \RuntimeException('Invalid storage'); }
        $lock = fopen($dir . '/lock', 'c+b');
        if (!$lock) { throw new \RuntimeException('Cannot lock'); }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { throw new \RuntimeException('Operation busy'); }
            $path = $dir . '/state.json';
            if (is_file($path)) {
                if (is_link($path) || filesize($path) > 4096) { throw new \RuntimeException('Invalid checkpoint'); }
                $s = json_decode(file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
                if (($s['operation'] ?? null) !== $id || !in_array($s['phase'] ?? null, ['files','database','verify','complete'], true)
                    || !is_int($s['cursor'] ?? null) || $s['cursor'] < 0) { throw new \RuntimeException('Invalid checkpoint'); }
                if (isset($s['deferred_final']) && !is_bool($s['deferred_final'])) { throw new \RuntimeException('Invalid verification mode'); }
                foreach (['files_sha256','database_sha256'] as $key) {
                    if (($s[$key] ?? null) !== null && (!is_string($s[$key]) || !preg_match('/^[a-f0-9]{64}$/D', $s[$key]))) {
                        throw new \RuntimeException('Invalid digest');
                    }
                }
                if ($s['phase'] !== 'files' && !isset($s['files_sha256'])) { throw new \RuntimeException('Missing file receipt'); }
                if (in_array($s['phase'], ['verify','complete'], true) && !isset($s['database_sha256'])) { throw new \RuntimeException('Missing database receipt'); }
            } else {
                if (file_exists($dir . '/bound')) { throw new \RuntimeException('Initialization uncertain'); }
                $bound = fopen($dir . '/bound', 'xb');
                if (!$bound) { throw new \RuntimeException('Cannot bind'); } fclose($bound);
                $s = ['operation'=>$id, 'phase'=>'files', 'cursor'=>0, 'files_sha256'=>null, 'database_sha256'=>null];
                self::save($path, $s);
            }
            $phase = $s['phase'];
            if ($phase !== 'complete') {
                $r = match ($phase) { 'files'=>$files($id), 'database'=>$database($id), 'verify'=>$verify($id, $s['deferred_final'] ?? false) };
                $allowed = match ($phase) {
                    'files'=>['manifest','capture','final','complete'],
                    'database'=>['schema','capture','verify','complete'],
                    'verify'=>['discover','verify','final','complete']
                };
                if (($r['operation'] ?? null) !== $id || !in_array($r['status'] ?? null, $allowed, true)) { throw new \RuntimeException('Invalid provider receipt'); }
                if ($r['status'] === 'complete' || ($phase === 'files' && $r['status'] === 'final')) {
                    $hash = $r['manifest_sha256'] ?? null;
                    if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) { throw new \RuntimeException('Missing manifest digest'); }
                    if ($phase === 'files') { $s['deferred_final'] = $r['status'] === 'final'; $s['files_sha256'] = $hash; $s['phase'] = 'database'; }
                    elseif ($phase === 'database') { $s['database_sha256'] = $hash; $s['phase'] = 'verify'; }
                    else {
                        if (!hash_equals($s['files_sha256'], $hash)) { throw new \RuntimeException('Files changed during database capture'); }
                        $s['phase'] = 'complete';
                    }
                }
                $s['cursor']++;
                self::save($path, $s);
            }
            return ['operation'=>$id, 'status'=>$s['phase'], 'cursor'=>$s['cursor'],
                'manifest_sha256'=>$s['phase'] === 'complete' ? hash('sha256', json_encode([
                    'format'=>'nwm-site-chunks-1', 'files'=>$s['files_sha256'], 'database'=>$s['database_sha256']
                ], JSON_THROW_ON_ERROR)) : null, 'restoration_verified'=>false];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function save(string $path, array $state): void {
        $data = json_encode($state, JSON_THROW_ON_ERROR); $file = fopen($path . '.next', 'wb');
        if (!$file) { throw new \RuntimeException('Cannot write'); }
        try {
            if (fwrite($file, $data) !== strlen($data) || !fflush($file) || !fsync($file)) { throw new \RuntimeException('Cannot persist'); }
        } finally { fclose($file); }
        if (!rename($path . '.next', $path)) { throw new \RuntimeException('Cannot commit'); }
    }
}
