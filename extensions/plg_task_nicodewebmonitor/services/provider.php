<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Database\DatabaseInterface;
use Nicode\Plugin\Task\NicodeWebMonitor\Extension\NicodeWebMonitor;
return new class implements ServiceProviderInterface {
    public function register(Container $container) {
        $container->set(PluginInterface::class, function(Container $container) {
            $plugin = new NicodeWebMonitor((array) PluginHelper::getPlugin('task', 'nicodewebmonitor'), $container->get(DatabaseInterface::class));
            $plugin->setApplication(Factory::getApplication());
            return $plugin;
        });
    }
};
