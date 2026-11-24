<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
class Com_NicodeconnectorInstallerScript
{
    public function preflight(string $type, $parent): bool
    {
        return $type==='uninstall' || (version_compare(JVERSION,'6.0.0','>=') && extension_loaded('sodium') && extension_loaded('curl'));
    }
    public function uninstall($parent): void
    {
        // Removing the UI must also revoke its inventory credential.
        $db=\Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $raw=$db->setQuery("SELECT params FROM #__extensions WHERE type='plugin' AND folder='system' AND element='nicodewebmonitor'")->loadResult();
        if ($raw!==null) {
            $params=new \Joomla\Registry\Registry($raw); $params->set('token_hash','');
            $db->setQuery('UPDATE #__extensions SET params='.$db->quote($params->toString())." WHERE type='plugin' AND folder='system' AND element='nicodewebmonitor'")->execute();
        }
    }
}
