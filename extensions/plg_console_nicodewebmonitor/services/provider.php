<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Nicode\Plugin\Console\NicodeWebMonitor\Extension\NicodeWebMonitor;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, function (Container $container) {
            return new NicodeWebMonitor(
                (array) PluginHelper::getPlugin('console', 'nicodewebmonitor'),
                $container->get(DatabaseInterface::class)
            );
        });
    }
};
