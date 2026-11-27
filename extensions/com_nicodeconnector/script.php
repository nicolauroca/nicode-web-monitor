<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
class Com_NicodeconnectorInstallerScript
{
    public function preflight(string $type, $parent): bool
    {
        return $type==='uninstall' || $this->requirements();
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
            ? 'Nicode Web Monitor — Conector: no se puede instalar. Requisitos incumplidos: '.implode(', ',$missing).'. Joomla detectado: '.JVERSION.'; PHP: '.PHP_VERSION.'.'
            : 'Nicode Web Monitor — Conector: cannot install. Missing requirements: '.implode(', ',$missing).'. Detected Joomla: '.JVERSION.'; PHP: '.PHP_VERSION.'.';
        if($php)$message.=$spanish
            ? ' Activa las extensiones indicadas en el PHP que utiliza esta web desde el alojamiento y vuelve a instalar el paquete.'
            : ' Enable the listed extensions in the PHP used by this website through your hosting settings, then install the package again.';
        if(version_compare(JVERSION,'6.0.0','<'))$message.=$spanish?' Se necesita Joomla 6 o posterior.':' Joomla 6 or newer is required.';
        $app->enqueueMessage($message,'error');
        return false;
    }
}
