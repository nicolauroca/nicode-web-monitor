<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
class Com_NicodewebmonitorInstallerScript
{
    public function preflight(string $type, $parent): bool
    {
        if ($type === 'uninstall') return true;
        if (!$this->requirements()) return false;

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
    private function requirements(): bool
    {
        $missing=[];$php=[];
        if(version_compare(JVERSION,'6.0.0','<'))$missing[]='Joomla 6.0+';
        foreach(['sodium'=>'Sodium (ext-sodium)','curl'=>'cURL (ext-curl)'] as $extension=>$label){
            if(!extension_loaded($extension)){$missing[]=$label;$php[]=$label;}
        }
        if(!$missing)return true;
        $app=\Joomla\CMS\Factory::getApplication();
        $spanish=str_starts_with($app->getLanguage()->getTag(),'es');
        $message=$spanish
            ? 'Nicode Web Monitor — Central: no se puede instalar. Requisitos incumplidos: '.implode(', ',$missing).'. Joomla detectado: '.JVERSION.'; PHP: '.PHP_VERSION.'.'
            : 'Nicode Web Monitor — Central: cannot install. Missing requirements: '.implode(', ',$missing).'. Detected Joomla: '.JVERSION.'; PHP: '.PHP_VERSION.'.';
        if($php)$message.=$spanish
            ? ' Activa las extensiones indicadas en el PHP que utiliza esta web desde el alojamiento y vuelve a instalar el paquete.'
            : ' Enable the listed extensions in the PHP used by this website through your hosting settings, then install the package again.';
        if(version_compare(JVERSION,'6.0.0','<'))$message.=$spanish?' Se necesita Joomla 6 o posterior.':' Joomla 6 or newer is required.';
        $app->enqueueMessage($message,'error');
        return false;
    }
}
