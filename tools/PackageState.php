<?php
declare(strict_types=1);
namespace Nicode\Maintenance;

use Joomla\Database\DatabaseInterface;

/** @package Nicode.joomla.webmonitor. Read-only package/child observations. */
final class PackageState
{
    public function __construct(private DatabaseInterface $db) {}

    public function inspect(string $element, array $expectedIdentities): array
    {
        if (!preg_match('/^pkg_[a-z0-9_]+$/D', $element)) { throw new \RuntimeException('Invalid package'); }
        $parents = $this->read("type='package' AND element=".$this->db->quote($element));
        $ids = array_column($parents, 'id');
        $children = $ids ? $this->read('package_id IN ('.implode(',', $ids).')') : [];
        // Also look by identity: replacement libraries can become orphaned after a failure.
        foreach ($expectedIdentities as $identity) {
            if (array_keys($identity) !== ['type','element','folder','client_id']
                || !in_array($identity['type'], ['plugin','module','component','template','library'], true)
                || !preg_match('/^[a-z0-9_]+$/D', $identity['element'])
                || !preg_match('/^[a-z0-9_]*$/D', $identity['folder'])
                || !in_array($identity['client_id'], [0,1], true)) { throw new \RuntimeException('Invalid child identity'); }
            foreach ($this->read('type='.$this->db->quote($identity['type']).' AND element='.$this->db->quote($identity['element']).' AND folder='.$this->db->quote($identity['folder']).' AND client_id='.$identity['client_id']) as $row) {
                $children[] = $row;
            }
        }
        $unique = [];
        foreach ($children as $row) { $unique[$row['id']] = $row; }
        ksort($unique, SORT_NUMERIC);
        return ['parents'=>$parents, 'children'=>array_values($unique)];
    }

    /** Exact child set, ownership and version; never certify only the parent. */
    public static function matches(array $state, string $version, array $targets): bool
    {
        if (count($state['parents']) !== 1 || $state['parents'][0]['version'] !== $version
            || count($state['children']) !== count($targets)) { return false; }
        $parent = $state['parents'][0]; $seen = [];
        if ($parent['protected'] || $parent['package_id'] !== 0) { return false; }
        foreach ($targets as $target) {
            $matches = array_values(array_filter($state['children'], fn ($row) => $row['identity'] === $target['identity']));
            if (count($matches) !== 1) { return false; }
            $row = $matches[0];
            if (isset($seen[$row['id']]) || $row['protected'] || $row['package_id'] !== $parent['id']
                || $row['version'] !== $target['version']) { return false; }
            $seen[$row['id']] = true;
        }
        return true;
    }

    private function read(string $where): array
    {
        $rows = $this->db->setQuery('SELECT extension_id,type,element,folder,client_id,package_id,protected,manifest_cache FROM #__extensions WHERE '.$where.' ORDER BY extension_id')->loadAssocList();
        return array_map(function ($row) {
            $cache = json_decode($row['manifest_cache'], true);
            $v = is_array($cache) ? ($cache['version'] ?? null) : null;
            return ['id'=>(int)$row['extension_id'],
                'identity'=>['type'=>$row['type'],'element'=>$row['element'],'folder'=>$row['folder'],'client_id'=>(int)$row['client_id']],
                'package_id'=>(int)$row['package_id'], 'protected'=>(bool)$row['protected'],
                'version'=>is_string($v) && preg_match('/^[0-9][a-zA-Z0-9.+_-]{0,79}$/D',$v) ? $v : null];
        }, $rows);
    }
}
