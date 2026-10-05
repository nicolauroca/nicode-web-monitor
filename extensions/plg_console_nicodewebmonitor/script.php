<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;

class PlgConsoleNicodewebmonitorInstallerScript
{
    public function preflight(string $type, $parent): bool
    {
        return $type === 'uninstall' || version_compare(JVERSION, '6.0.0', '>=');
    }
}
