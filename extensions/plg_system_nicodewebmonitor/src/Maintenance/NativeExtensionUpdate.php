<?php
declare(strict_types=1);
namespace Nicode\Plugin\System\NicodeWebMonitor\Maintenance;

use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\Database\DatabaseInterface;
use Nicode\Plugin\System\NicodeWebMonitor\Transport\BackupEndpoint;

/** @package Nicode.joomla.webmonitor
 * Locally vetted extension ZIPs. No downloads or remote paths.
 */
final class NativeExtensionUpdate
{
    public function __construct(private DatabaseInterface $db, private string $storage,
        private string $webRoot, private bool $offline, private string $maintenanceStorage = '') {}

    public function handle(string $operation, bool $execute): array
    {
        if (!preg_match('/^[a-z0-9-]{8,64}$/D', $operation)) { throw new \RuntimeException('Invalid operation'); }
        foreach ([$this->storage, $this->storage.'/journal', $this->storage.'/prepared'] as $dir) {
            BackupEndpoint::privateDirectory($dir, $this->webRoot);
        }
        $core = new ExtensionUpdate($this->storage.'/journal');
        // A receipt is authoritative even after the maintenance permit expires.
        if (is_dir($this->storage.'/journal/'.$operation)) { return $core->status($operation); }
        if (!$execute) { throw new \OutOfBoundsException('Unknown operation'); }
        $dir = $this->storage.'/prepared/'.$operation;
        BackupEndpoint::privateDirectory($dir, $this->webRoot);
        $file = $dir.'/permit.json';
        if (!$this->offline || !is_file($file) || is_link($file) || filesize($file)>4096) {
            throw new \RuntimeException('Maintenance required');
        }
        $permit = json_decode((string)file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
        if (($permit['operation'] ?? null) !== $operation || ($permit['writers_stopped'] ?? null) !== true
            || ($permit['recovery_verified'] ?? null) !== true
            || !is_int($permit['expires_at'] ?? null) || $permit['expires_at'] < time()
            || $permit['expires_at'] > time()+900) { throw new \RuntimeException('Invalid permit'); }
        $plan = $permit['plan']; $identity = $permit['identity'];
        if (array_keys($identity) !== ['type','element','folder','client_id']
            || !in_array($identity['type'], ['plugin','module','component','template','library'], true)
            || !in_array($identity['client_id'], [0,1], true)
            || !preg_match('/^[a-z0-9_]+$/D', $identity['element'])
            || !preg_match('/^[a-z0-9_]*$/D', $identity['folder'])) { throw new \RuntimeException('Unsupported identity'); }
        $inspect = function () use ($plan, $identity): array {
            // Libraries are uninstalled/reinstalled by Joomla: match the complete identity,
            // never a guessed replacement ID. Duplicates and surviving original rows fail closed.
            $where = $identity['type'] === 'library'
                ? 'type='.$this->db->quote($identity['type']).' AND element='.$this->db->quote($identity['element']).' AND folder='.$this->db->quote($identity['folder']).' AND client_id='.$identity['client_id']
                : 'extension_id='.(int)$plan['extension_id'];
            $rows = $this->db->setQuery('SELECT extension_id,type,element,folder,client_id,protected,package_id,manifest_cache FROM #__extensions WHERE '.$where)->loadAssocList();
            if (count($rows) !== 1) { throw new \RuntimeException('Ambiguous installed identity'); }
            $row = $rows[0];
            if ($identity['type'] === 'library' && (int)$row['package_id'] !== 0) { throw new \RuntimeException('Package-owned library unsupported'); }
            if ((int)$row['extension_id'] !== $plan['extension_id']
                && $this->db->setQuery('SELECT extension_id FROM #__extensions WHERE extension_id='.(int)$plan['extension_id'])->loadResult()) { throw new \RuntimeException('Original extension still present'); }
            if (!$row || (int)$row['protected'] !== 0 || $row['type'] !== $identity['type']
                || $row['element'] !== $identity['element'] || $row['folder'] !== $identity['folder']
                || (int)$row['client_id'] !== $identity['client_id']) { throw new \RuntimeException('Identity changed'); }
            $cache = json_decode($row['manifest_cache'], true, 16, JSON_THROW_ON_ERROR);
            return ['extension_id'=>(int)$row['extension_id'], 'version'=>$cache['version']];
        };
        $package = $dir.'/package.zip';
        if (!is_file($package) || is_link($package) || filesize($package)>67108864
            || !hash_equals($plan['package_sha256'], hash_file('sha256', $package))) {
            throw new \RuntimeException('Package changed');
        }
        // Check archive and manifest before invoking installer scripts or reserving effects.
        $zip = new \ZipArchive();
        if ($zip->open($package) !== true) { throw new \RuntimeException('Invalid archive'); }
        try {
            if ($zip->numFiles > 10000) { throw new \RuntimeException('Too many entries'); }
            $total = 0; $seen = [];
            for ($i=0; $i<$zip->numFiles; $i++) {
                $entry=$zip->statIndex($i); $name=$entry['name']; $total += $entry['size'];
                if (str_contains($name, '\\') || str_starts_with($name, '/') || str_contains($name, ':')
                    || in_array('..', explode('/', $name), true) || in_array('.', explode('/', $name), true)
                    || preg_match('#[. ](/|$)#', $name) || isset($seen[strtolower($name)]) || $total > 134217728) {
                    throw new \RuntimeException('Unsafe archive');
                }
                $seen[strtolower($name)] = true;
                if ($zip->getExternalAttributesIndex($i, $os, $attrs) && (($attrs >> 16) & 0170000) === 0120000) {
                    throw new \RuntimeException('Archive links unsupported');
                }
            }
            $filename = $identity['type'] === 'template' ? 'templateDetails.xml' : $identity['element'].'.xml';
            if ($identity['type'] === 'component' && $zip->locateName($filename) === false) {
                $filename = substr($identity['element'],4).'.xml';
            }
            $xml = $zip->getFromName($filename);
            if (!is_string($xml) || strlen($xml)>65536 || stripos($xml, '<!DOCTYPE')!==false
                || stripos($xml, '<!ENTITY')!==false) { throw new \RuntimeException('Invalid manifest'); }
            $manifest = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
            if (!$manifest || ExtensionIdentity::read($manifest, $filename) !== $identity
                || (string)$manifest->version!==$plan['to']) {
                throw new \RuntimeException('Package identity/version mismatch');
            }
        } finally { $zip->close(); }
        // Reject a stale prepared plan before reserving shared maintenance. The primitive
        // checks again under the lock so a changed baseline can never reach installation.
        if ($inspect() !== ['extension_id'=>$plan['extension_id'], 'version'=>$plan['from']]) {
            throw new \RuntimeException('Installed baseline changed');
        }
        return (new MaintenanceGate($this->maintenanceStorage, $this->webRoot))->run('extension:'.$operation,
            fn () => $core->run($operation, $plan, true, true, $inspect, function ($digest) use ($package, $manifest): bool {
            if (!hash_equals($digest, hash_file('sha256', $package))) { throw new \RuntimeException('Package changed'); }
            $p = InstallerHelper::unpack($package, true);
            if (!$p) { throw new \RuntimeException('Unpack failed'); }
            try {
                $installer = new Installer(); $installer->setDatabase($this->db);
                $installer->setPath('source', $p['extractdir']);
                if ($p['type'] !== (string)$manifest['type'] || !$installer->findManifest()
                    || $installer->manifest->asXML() !== $manifest->asXML()) {
                    throw new \RuntimeException('Installer selected a different manifest');
                }
                return $installer->install($p['extractdir']);
            } finally { \Joomla\Filesystem\Folder::delete($p['extractdir']); }
        }, $identity['type'] === 'library'));
    }
}
