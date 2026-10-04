<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Plugin\Console\NicodeWebMonitor\Inventory;

use Joomla\Database\DatabaseInterface;

final class Collector
{
    public function __construct(private DatabaseInterface $database, private string $joomlaVersion)
    {
    }

    public function collect(): array
    {
        if (version_compare($this->joomlaVersion, '6.0.0', '<')) {
            throw new \RuntimeException('Joomla 6 or newer is required.');
        }
        $sections = [
            'joomla' => self::available(['version' => $this->joomlaVersion]),
            'runtime' => self::available([
                'php_version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'os_family' => PHP_OS_FAMILY,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => (int) ini_get('max_execution_time'),
                'loaded_extensions' => get_loaded_extensions(),
            ]),
            'database' => $this->attempt(fn () => [
                'driver' => $this->database->getName(),
                'server_type' => $this->database->getServerType(),
                'version' => $this->database->getVersion(),
            ]),
            'extensions' => $this->attempt(function () {
                $fields = ['extension_id', 'name', 'type', 'element', 'folder', 'client_id', 'enabled', 'manifest_cache'];
                $query = $this->database->getQuery(true)
                    ->select($this->database->quoteName($fields))
                    ->from($this->database->quoteName('#__extensions'))
                    ->order($this->database->quoteName('extension_id') . ' ASC');
                $rows = $this->database->setQuery($query)->loadAssocList();
                return array_map(ExtensionRecord::fromRow(...), $rows);
            }),
            'hosting' => ['status' => 'unavailable', 'reason' => 'provider_not_integrated', 'data' => null],
        ];
        return [
            'schema_version' => 1,
            'collector_version' => '0.1.0-dev',
            'collected_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'execution_context' => 'local_cli',
            'status' => in_array('error', array_column($sections, 'status'), true) ? 'partial' : 'complete',
            'sections' => $sections,
            'capabilities' => ['remote_inventory' => false, 'remote_updates' => false, 'remote_backups' => false],
        ];
    }

    private static function available(mixed $data): array
    {
        return ['status' => 'available', 'data' => $data];
    }

    private function attempt(\Closure $read): array
    {
        try {
            return self::available($read());
        } catch (\Throwable) {
            // Exception text may contain hosts, credentials, paths or SQL. Never serialize it.
            return ['status' => 'error', 'reason' => 'collection_failed', 'data' => null];
        }
    }
}
