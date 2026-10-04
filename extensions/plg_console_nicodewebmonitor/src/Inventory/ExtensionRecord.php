<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Plugin\Console\NicodeWebMonitor\Inventory;

final class ExtensionRecord
{
    /** Explicit allowlist: never forward params, update URLs, keys or manifest contents. */
    public static function fromRow(array $row): array
    {
        $manifest = json_decode((string) ($row['manifest_cache'] ?? ''), true);
        $version = is_array($manifest) ? ($manifest['version'] ?? null) : null;
        $version = is_string($version) && preg_match('/\A[0-9][a-zA-Z0-9.+_-]{0,79}\z/D', $version) ? $version : null;
        return [
            'id' => (int) $row['extension_id'],
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'element' => (string) $row['element'],
            'folder' => (string) $row['folder'],
            'client_id' => (int) $row['client_id'],
            'enabled' => (bool) $row['enabled'],
            'version' => ['status' => $version === null ? 'unknown' : 'available', 'value' => $version],
        ];
    }
}
