<?php
declare(strict_types=1);
namespace Nicode\Maintenance;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/** @package Nicode.joomla.webmonitor. Requires the site's native Joomla CLI bootstrap. */
final class InstalledCore
{
    public static function inspect(): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $rows = $db->setQuery("SELECT extension_id,manifest_cache FROM #__extensions WHERE type='file' AND element='joomla'")->loadAssocList();
        if (count($rows) !== 1) { throw new \RuntimeException('Core identity missing or ambiguous'); }
        $cache = json_decode($rows[0]['manifest_cache'], true, 16, JSON_THROW_ON_ERROR);
        $manifest = simplexml_load_file(JPATH_MANIFESTS.'/files/joomla.xml', \SimpleXMLElement::class, LIBXML_NONET);
        $model = Factory::$application->bootComponent('com_installer')->getMVCFactory()->createModel('Database', 'Administrator', ['ignore_request' => true]);
        $model->setState('filter.extension_id', (int)$rows[0]['extension_id']);
        $items = $model->getItems();
        if (count($items) !== 1) { throw new \RuntimeException('Native schema check unavailable'); }
        $item = reset($items);
        if (!isset($item['errorsCount']) || !is_numeric($item['errorsCount'])) {
            throw new \RuntimeException('Native schema result unavailable');
        }
        return ['extension_id' => (int)$rows[0]['extension_id'], 'runtime_version' => JVERSION,
            'manifest_version' => (string)$manifest->version, 'database_version' => $cache['version'],
            'schema_errors' => (int)$item['errorsCount'], 'offline' => (bool)Factory::getConfig()->get('offline')];
    }
}
