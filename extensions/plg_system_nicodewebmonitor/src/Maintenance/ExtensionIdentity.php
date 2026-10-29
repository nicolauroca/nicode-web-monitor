<?php
declare(strict_types=1);
namespace Nicode\Plugin\System\NicodeWebMonitor\Maintenance;

/** @package Nicode.joomla.webmonitor. Strict supported subset of native manifests. */
final class ExtensionIdentity
{
    public static function read(\SimpleXMLElement $m, string $filename): array
    {
        if ($m->getName() !== 'extension') { throw new \RuntimeException('Invalid manifest'); }
        $type = (string)$m['type']; $folder = ''; $client = 0;
        if ($type === 'plugin' || $type === 'module') {
            $nodes = $m->xpath('./files/*[@'.$type.']');
            $explicit = (string)$m->element;
            if (count($nodes) > 1 || (count($nodes) === 0 && $explicit === '')) {
                throw new \RuntimeException('Unambiguous extension element required');
            }
            $element = count($nodes) ? (string)$nodes[0][$type] : $explicit;
            if ($explicit !== '' && $explicit !== $element) { throw new \RuntimeException('Conflicting element'); }
            if ($type === 'plugin') {
                $folder = (string)$m['group'];
                if (!preg_match('/^[a-z0-9_]+$/D', $folder)) { throw new \RuntimeException('Invalid group'); }
            } else {
                $name = (string)$m['client'];
                if (!in_array($name, ['', 'site', 'administrator'], true)) { throw new \RuntimeException('Invalid client'); }
                $client = $name === 'administrator' ? 1 : 0;
                if (!str_starts_with($element, 'mod_')) { throw new \RuntimeException('Invalid module element'); }
            }
            $names = [$element.'.xml'];
        } elseif ($type === 'component') {
            // Require canonical names instead of guessing Joomla's sanitized name.
            $element = (string)$m->name; $client = 1;
            if (!str_starts_with($element, 'com_') || ((string)$m->element !== '' && (string)$m->element !== $element)) {
                throw new \RuntimeException('Canonical component name required');
            }
            $names = [$element.'.xml', substr($element, 4).'.xml'];
        } elseif ($type === 'template') {
            $element = (string)$m->element ?: (string)$m->name;
            $name = (string)$m['client'];
            if (!in_array($name, ['', 'site', 'administrator'], true)) { throw new \RuntimeException('Invalid client'); }
            $client = $name === 'administrator' ? 1 : 0;
            $names = ['templateDetails.xml'];
        } else { throw new \RuntimeException('Unsupported extension type'); }
        if (!preg_match('/^[a-z0-9_]+$/D', $element) || !in_array($filename, $names, true)) {
            throw new \RuntimeException('Invalid manifest identity');
        }
        return ['type'=>$type, 'element'=>$element, 'folder'=>$folder, 'client_id'=>$client];
    }
}
