<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
class Com_NicodewebmonitorInstallerScript
{
    public function preflight(string $type, $parent): bool
    {
        if ($type === 'uninstall') return true;
        if (version_compare(JVERSION, '6.0.0', '<') || !extension_loaded('sodium') || !extension_loaded('curl')) return false;

        // Joomla may record the newest schema version on reinstall even when
        // CREATE TABLE IF NOT EXISTS kept an older, deliberately retained table.
        // Establish the current invariant BEFORE incremental SQL is considered.
        $db=\Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        // Establish the current latest-observation table from the single reference DDL.
        $schema=file_get_contents(__DIR__.'/administrator/sql/install.mysql.utf8.sql');
        if(!preg_match('/CREATE TABLE IF NOT EXISTS `#__nwm_inventory`[^;]+;/s',$schema,$match))return false;
        $db->setQuery($match[0])->execute();
        $table=$db->replacePrefix('#__nwm_sites');
        if (in_array($table,$db->getTableList(),true)
            && !isset($db->getTableColumns($table)['connection_type'])) {
            $db->setQuery('ALTER TABLE '.$db->quoteName($table)." ADD COLUMN ".$db->quoteName('connection_type')." varchar(8) NOT NULL DEFAULT 'https'")->execute();
        }
        return true;
    }

    public function uninstall($parent): void
    {
        $db=\Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
        $db->setQuery("UPDATE #__scheduler_tasks SET state=0 WHERE type='nicode.monitor'")->execute();
        $db->setQuery('DELETE FROM #__nwm_pairing')->execute();
    }
}
