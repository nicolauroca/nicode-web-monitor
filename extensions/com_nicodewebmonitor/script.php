<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
class Com_NicodewebmonitorInstallerScript
{
    public function preflight(string $type, $parent): bool
    {
        return $type === 'uninstall' || (version_compare(JVERSION, '6.0.0', '>=') && extension_loaded('sodium') && extension_loaded('curl'));
    }

    public function uninstall($parent): void
    {
        $db=\Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $db->setQuery("UPDATE #__scheduler_tasks SET state=0 WHERE type='nicode.monitor'")->execute();
        $db->setQuery('DELETE FROM #__nwm_pairing')->execute();
    }
}
