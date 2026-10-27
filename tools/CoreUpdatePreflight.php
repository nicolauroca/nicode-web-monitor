<?php
declare(strict_types=1);
namespace Nicode\Maintenance;

/** @package Nicode.joomla.webmonitor
 * Read-only checks for a locally obtained, independently trusted Joomla package.
 * A passing report is neither an execution permit nor proof of recoverability.
 */
final class CoreUpdatePreflight
{
    public static function package(string $path, string $expectedHash): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedHash) || !is_file($path)
            || is_link($path) || filesize($path) > 134217728
            || !hash_equals($expectedHash, hash_file('sha256', $path))) {
            throw new \RuntimeException('Package digest or local file invalid');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('Invalid ZIP');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 32768) {
                throw new \RuntimeException('Archive entry limit');
            }
            $seen = []; $files = []; $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i); $name = $entry['name'];
                $total += $entry['size'];
                $key = strtolower(rtrim($name, '/'));
                if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\')
                    || str_contains($name, '//') || preg_match('/[\x00-\x1f\x7f:]/', $name)
                    || in_array('.', explode('/', $name), true) || in_array('..', explode('/', $name), true)
                    || preg_match('#[. ](/|$)#', $name)
                    || preg_match('#(^|/)(con|prn|aux|nul|com[1-9]|lpt[1-9])([./]|$)#i', $name)
                    || isset($seen[$key]) || $total > 536870912 || $entry['size'] > 67108864
                    || ($entry['encryption_method'] ?? 0) !== 0) {
                    throw new \RuntimeException('Unsafe archive entry');
                }
                $seen[$key] = true;
                if (!str_ends_with($name, '/')) { $files[$key] = true; }
                if ($zip->getExternalAttributesIndex($i, $os, $attrs)
                    && in_array(($attrs >> 16) & 0170000, [0120000, 0060000, 0020000, 0010000, 0140000], true)) {
                    throw new \RuntimeException('Archive special file');
                }
                if ($key === 'configuration.php' || $key === '.env') {
                    throw new \RuntimeException('Site-specific configuration in package');
                }
                if ($key === 'installation' || str_starts_with($key, 'installation/')) {
                    throw new \RuntimeException('Full installation package is not an update package');
                }
            }
            foreach (array_keys($seen) as $name) {
                $parent = dirname($name);
                while ($parent !== '.') {
                    if (isset($files[$parent])) { throw new \RuntimeException('Archive file-directory collision'); }
                    $parent = dirname($parent);
                }
            }
            $manifestPath = 'administrator/manifests/files/joomla.xml';
            $xml = $zip->getFromName($manifestPath);
            if (!is_string($xml) || strlen($xml) > 65536 || stripos($xml, '<!DOCTYPE') !== false
                || stripos($xml, '<!ENTITY') !== false) { throw new \RuntimeException('Invalid core manifest'); }
            $previous = libxml_use_internal_errors(true);
            try { $manifest = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET); }
            finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
            if (!$manifest || $manifest->getName() !== 'extension' || (string)$manifest['type'] !== 'file'
                || (string)$manifest->name !== 'files_joomla' || count($manifest->version) !== 1
                || !preg_match('/^6\.\d+\.\d+$/D', (string)$manifest->version)
                || (string)$manifest->scriptfile !== 'administrator/components/com_admin/script.php') {
                throw new \RuntimeException('Unsupported core identity/version');
            }
            foreach (['libraries/src/Version.php', 'includes/framework.php', 'cli/joomla.php',
                'administrator/components/com_admin/script.php'] as $required) {
                if ($zip->locateName($required) === false) { throw new \RuntimeException('Incomplete core package'); }
            }
            // Parse constants as data. Never load executable PHP from the archive.
            $versionSource = $zip->getFromName('libraries/src/Version.php');
            $parts = [];
            foreach (['MAJOR_VERSION', 'MINOR_VERSION', 'PATCH_VERSION'] as $constant) {
                if (!preg_match('/\bconst\s+'. $constant .'\s*=\s*(\d+)\s*;/', $versionSource, $match)) {
                    throw new \RuntimeException('Core version constants missing');
                }
                $parts[] = $match[1];
            }
            if (implode('.', $parts) !== (string)$manifest->version) {
                throw new \RuntimeException('Core package versions disagree');
            }
            return ['version' => (string)$manifest->version, 'sha256' => $expectedHash,
                'entries' => $zip->numFiles, 'uncompressed_bytes' => $total];
        } finally { $zip->close(); }
    }

    public static function assess(array $installed, array $package): array
    {
        $blocked = [];
        $version = $installed['runtime_version'] ?? '';
        if (!is_string($version) || !preg_match('/^6\.\d+\.\d+$/D', $version)) { $blocked[] = 'unsupported_installed_version'; }
        if (($installed['manifest_version'] ?? null) !== $version || ($installed['database_version'] ?? null) !== $version) {
            $blocked[] = 'installed_versions_disagree';
        }
        if (!version_compare($package['version'], $version, '>')) { $blocked[] = 'target_not_newer'; }
        if (($installed['schema_errors'] ?? null) !== 0) { $blocked[] = 'database_schema_not_clean'; }
        if (($installed['offline'] ?? null) !== true) { $blocked[] = 'site_not_offline'; }
        return ['status' => $blocked ? 'blocked' : 'preflight_passed', 'blockers' => $blocked,
            'installed' => $installed, 'package' => $package, 'execution_authorized' => false,
            'remaining_requirements' => ['stop_all_other_writers', 'verify_recovery_on_separate_clone',
                'check_extension_and_platform_compatibility', 'explicit_operation_confirmation',
                'native_update_provider_not_implemented']];
    }
}
