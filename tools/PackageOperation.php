<?php
declare(strict_types=1);
namespace Nicode\Maintenance;

/** @package Nicode.joomla.webmonitor. Local compound-install primitive, not an HTTP provider.
 * The caller must bind a vetted archive installer and hold the shared MaintenanceGate.
 */
final class PackageOperation
{
    public function __construct(private string $storage)
    {
        if (!is_dir($storage) || is_link($storage)) { throw new \RuntimeException('Private journal required'); }
    }

    public function run(string $id, array $plan, bool $authorized, bool $confirmed, \Closure $inspect, \Closure $install): array
    {
        if (!$authorized || !$confirmed) { throw new \RuntimeException('Permission and confirmation required'); }
        if (!preg_match('/^[a-z0-9-]{8,64}$/D', $id)
            || array_keys($plan) !== ['before','to','targets','package_sha256']
            || !is_string($plan['to']) || !is_array($plan['targets']) || !$plan['targets']
            || !is_string($plan['package_sha256']) || !preg_match('/^[a-f0-9]{64}$/D',$plan['package_sha256'])) {
            throw new \RuntimeException('Invalid compound plan');
        }
        $fingerprint = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
        $dir = $this->storage.'/'.$id;
        if (is_dir($dir)) {
            $receipt = $this->status($id);
            if ($receipt['fingerprint'] !== $fingerprint) { throw new \RuntimeException('Operation conflict'); }
            return $receipt;
        }
        if (count($plan['before']['parents'] ?? []) !== 1
            || !version_compare($plan['to'], $plan['before']['parents'][0]['version'] ?? '', '>')
            || $inspect() !== $plan['before']) { throw new \RuntimeException('Installed baseline changed'); }
        $baselineTargets = [];
        foreach ($plan['targets'] as $target) {
            if (!is_array($target) || array_keys($target) !== ['identity','version']
                || !is_string($target['version']) || !preg_match('/^[0-9][a-zA-Z0-9.+_-]{0,79}$/D',$target['version'])) {
                throw new \RuntimeException('Invalid child target');
            }
            $rows = array_values(array_filter($plan['before']['children'] ?? [],fn($r)=>$r['identity']===$target['identity']));
            if (count($rows)!==1 || !is_string($rows[0]['version'])
                || version_compare($target['version'],$rows[0]['version'],'<')) { throw new \RuntimeException('Child baseline mismatch'); }
            $baselineTargets[] = ['identity'=>$target['identity'],'version'=>$rows[0]['version']];
        }
        if (!PackageState::matches($plan['before'],$plan['before']['parents'][0]['version'],$baselineTargets)) {
            throw new \RuntimeException('Inconsistent package baseline');
        }
        if (!mkdir($dir,0700)) { throw new \RuntimeException('Cannot reserve operation'); }
        $record = ['operation'=>$id,'fingerprint'=>$fingerprint,'status'=>'uncertain','plan'=>$plan];
        $this->write($dir.'/started.json', $record);
        try { $installed = $install($plan['package_sha256']) === true; }
        catch (\Throwable) { $installed = false; }
        // Observation failures never expose SQL/paths and never permit another installation.
        try { $after = $inspect(); } catch (\Throwable) { $after = null; }
        $record['installer_returned_success'] = $installed;
        $record['observed'] = $after;
        if ($installed && $after !== null && PackageState::matches($after, $plan['to'], $plan['targets'])) {
            $record['status'] = 'updated';
        }
        $this->write($dir.'/observed.json', $record);
        return $record;
    }

    public function status(string $id): array
    {
        if (!preg_match('/^[a-z0-9-]{8,64}$/D', $id)) { throw new \RuntimeException('Invalid operation'); }
        $dir = $this->storage.'/'.$id;
        $r = json_decode((string)file_get_contents($dir.(is_file($dir.'/observed.json')?'/observed.json':'/started.json')),true,32,JSON_THROW_ON_ERROR);
        if (($r['operation'] ?? null) !== $id) { throw new \RuntimeException('Invalid receipt'); }
        return $r;
    }

    private function write(string $file, array $data): void
    {
        $bytes=json_encode($data,JSON_THROW_ON_ERROR); $f=fopen($file,'x');
        if (!$f) { throw new \RuntimeException('Cannot persist receipt'); }
        try {
            if (fwrite($f,$bytes)!==strlen($bytes) || !fflush($f) || !fsync($f)) { throw new \RuntimeException('Cannot persist receipt'); }
        } finally { fclose($f); }
    }
}
