<?php
/** @license GPL-2.0-or-later */
defined('_JEXEC') or die;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Extension\Component;
use Joomla\CMS\Extension\Service\Provider\ComponentDispatcherFactory;
use Joomla\CMS\Extension\Service\Provider\MVCFactory;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->registerServiceProvider(new MVCFactory('\\Nicode\\Component\\NicodeWebMonitor'));
        $container->registerServiceProvider(new ComponentDispatcherFactory('\\Nicode\\Component\\NicodeWebMonitor'));
        $container->set(ComponentInterface::class, fn(Container $c) => new Component($c->get(ComponentDispatcherFactoryInterface::class)));
    }
};
