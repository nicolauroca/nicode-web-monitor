<?php
declare(strict_types=1);
namespace Nicode\Maintenance;

use Nicode\Plugin\System\NicodeWebMonitor\Maintenance\ExtensionIdentity;

/** @package Nicode.joomla.webmonitor. Trusted local ZIP inspection; never executes scripts. */
final class PackagePreflight
{
    public static function inspect(string $path, string $digest): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $digest) || !is_file($path) || is_link($path)
            || filesize($path) > 67108864 || !hash_equals($digest, hash_file('sha256', $path))) {
            throw new \RuntimeException('Invalid package digest or file');
        }
        $budget = 0;
        $archivePath = $path;
        $zip = self::open($path, $budget);
        try {
            [$filename, $m] = self::manifest($zip);
            $name = (string)$m->packagename;
            if ((string)$m['type'] !== 'package' || count($m->packagename) !== 1
                || !preg_match('/^[a-z0-9_]+$/D', $name) || $filename !== 'pkg_'.$name.'.xml'
                || count($m->files) !== 1 || (string)$m->files['folder'] !== '') {
                throw new \RuntimeException('Unsupported package manifest');
            }
            $version = self::version($m);
            $targets = []; $children = []; $seen = []; $paths = [];
            foreach ($m->files->children() as $entry) {
                $path = (string)$entry;
                if ($entry->getName() !== 'file' || count($targets) >= 32
                    || !preg_match('/^[a-zA-Z0-9_-]+\.zip$/D', $path) || isset($paths[strtolower($path)])) {
                    throw new \RuntimeException('Unsupported or duplicate child archive');
                }
                $paths[strtolower($path)] = true;
                $bytes = $zip->getFromName($path);
                if (!is_string($bytes) || strlen($bytes) > 67108864) { throw new \RuntimeException('Missing child archive'); }
                // Temporary child ZIP is data only; never extract its files or load its PHP.
                $tmp = tempnam(sys_get_temp_dir(), 'nwm-preflight-');
                if ($tmp === false) { throw new \RuntimeException('Cannot inspect child'); }
                try {
                    if (file_put_contents($tmp, $bytes) !== strlen($bytes)) { throw new \RuntimeException('Cannot inspect child'); }
                    $child = self::open($tmp, $budget);
                    try {
                        [$childName, $cm] = self::manifest($child);
                        $identity = ExtensionIdentity::read($cm, $childName);
                        $target = ['identity'=>$identity, 'version'=>self::version($cm)];
                        // Nested packages are deliberately rejected until nested ownership is supported.
                        $client = (string)$entry['client'];
                        $expectedClient = $identity['client_id'] === 1 ? 'administrator' : 'site';
                        if ((string)$entry['type'] !== $identity['type'] || (string)$entry['id'] !== $identity['element']
                            || (string)$entry['group'] !== $identity['folder']
                            || ($client !== '' && $client !== $expectedClient)
                            || ($client === '' && in_array($identity['type'], ['module','template'], true) && $identity['client_id'] !== 0)) {
                            throw new \RuntimeException('Child declaration identity mismatch');
                        }
                        $key = json_encode($identity, JSON_THROW_ON_ERROR);
                        if (isset($seen[$key])) { throw new \RuntimeException('Duplicate child identity'); }
                        $seen[$key] = true;
                        $targets[] = $target;
                        $children[] = ['archive'=>$path, 'sha256'=>hash('sha256', $bytes), 'target'=>$target];
                    } finally { $child->close(); }
                } finally { unlink($tmp); }
            }
            if (!$targets) { throw new \RuntimeException('Empty package'); }
            for ($i=0; $i<$zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if (str_ends_with(strtolower($entry), '.zip') && !isset($paths[strtolower($entry)])) {
                    throw new \RuntimeException('Undeclared child archive');
                }
            }
            if (!hash_equals($digest, hash_file('sha256', $archivePath))) { throw new \RuntimeException('Package changed during inspection'); }
            return ['element'=>'pkg_'.$name, 'to'=>$version, 'targets'=>$targets, 'children'=>$children, 'package_sha256'=>$digest];
        } finally { $zip->close(); }
    }

    public static function plan(array $report, array $before): array
    {
        $parents = $before['parents'] ?? [];
        if (count($parents) !== 1 || ($parents[0]['identity']['element'] ?? null) !== $report['element']
            || !is_string($parents[0]['version']) || !version_compare($report['to'], $parents[0]['version'], '>')) {
            throw new \RuntimeException('Package baseline mismatch');
        }
        $baseline = [];
        foreach ($report['targets'] as $target) {
            $rows = array_values(array_filter($before['children'] ?? [], fn($row)=>$row['identity'] === $target['identity']));
            if (count($rows) !== 1 || !is_string($rows[0]['version']) || version_compare($target['version'], $rows[0]['version'], '<')) {
                throw new \RuntimeException('Child baseline mismatch');
            }
            $baseline[] = ['identity'=>$target['identity'], 'version'=>$rows[0]['version']];
        }
        if (!PackageState::matches($before, $parents[0]['version'], $baseline)) { throw new \RuntimeException('Inconsistent package baseline'); }
        return ['before'=>$before, 'to'=>$report['to'], 'targets'=>$report['targets'], 'package_sha256'=>$report['package_sha256']];
    }

    private static function version(\SimpleXMLElement $m): string
    {
        $v = (string)$m->version;
        if (count($m->version) !== 1 || !preg_match('/^[0-9][a-zA-Z0-9.+_-]{0,79}$/D', $v)) { throw new \RuntimeException('Invalid version'); }
        return $v;
    }

    private static function manifest(\ZipArchive $zip): array
    {
        $found = [];
        for ($i=0; $i<$zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_contains($name, '/') || !str_ends_with(strtolower($name), '.xml')) { continue; }
            $xml = $zip->getFromIndex($i);
            if (!is_string($xml) || strlen($xml)>65536 || stripos($xml,'<!DOCTYPE')!==false || stripos($xml,'<!ENTITY')!==false) {
                throw new \RuntimeException('Invalid manifest XML');
            }
            $previous = libxml_use_internal_errors(true);
            try { $m = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET); }
            finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
            if (!$m) { throw new \RuntimeException('Invalid manifest XML'); }
            if ($m->getName() === 'extension') { $found[] = [$name, $m]; }
        }
        if (count($found)!==1) { throw new \RuntimeException('Exactly one root extension manifest required'); }
        return $found[0];
    }

    private static function open(string $path, int &$budget): \ZipArchive
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY)!==true) { throw new \RuntimeException('Invalid ZIP'); }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 10000) { throw new \RuntimeException('Archive entry limit'); }
            $seen = []; $files = [];
            for ($i=0; $i<$zip->numFiles; $i++) {
                $entry=$zip->statIndex($i); $name=$entry['name']; $key=strtolower(rtrim($name,'/')); $budget += $entry['size'];
                if ($name === '' || str_starts_with($name,'/') || str_contains($name,'\\') || str_contains($name,'//')
                    || preg_match('/[\x00-\x1f\x7f:]/',$name) || in_array('.',explode('/',$name),true) || in_array('..',explode('/',$name),true)
                    || preg_match('#[. ](/|$)#',$name) || preg_match('#(^|/)(con|prn|aux|nul|com[1-9]|lpt[1-9])([./]|$)#i',$name)
                    || isset($seen[$key]) || $budget>134217728 || $entry['size']>67108864 || ($entry['encryption_method']??0)!==0) {
                    throw new \RuntimeException('Unsafe archive entry');
                }
                $seen[$key]=true;
                if (!str_ends_with($name,'/')) { $files[$key]=true; }
                if ($zip->getExternalAttributesIndex($i,$os,$attrs) && in_array(($attrs>>16)&0170000,[0120000,0060000,0020000,0010000,0140000],true)) {
                    throw new \RuntimeException('Archive special file');
                }
            }
            foreach (array_keys($seen) as $name) {
                for ($parent=dirname($name); $parent!=='.'; $parent=dirname($parent)) {
                    if (isset($files[$parent])) { throw new \RuntimeException('Archive path collision'); }
                }
            }
            return $zip;
        } catch (\Throwable $e) { $zip->close(); throw $e; }
    }
}
