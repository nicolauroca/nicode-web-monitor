<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Plugin\Console\NicodeWebMonitor\Extension;

use Joomla\Application\ApplicationEvents;
use Joomla\Application\Event\ApplicationEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Version;
use Joomla\Console\Application;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;
use Nicode\Plugin\Console\NicodeWebMonitor\Command\InventoryCommand;
use Nicode\Plugin\Console\NicodeWebMonitor\Inventory\Collector;

defined('_JEXEC') or die;

final class NicodeWebMonitor extends CMSPlugin implements SubscriberInterface
{
    public function __construct(array $config, private DatabaseInterface $database)
    {
        parent::__construct($config);
    }

    public static function getSubscribedEvents(): array
    {
        return [ApplicationEvents::BEFORE_EXECUTE => 'registerCommand'];
    }

    public function registerCommand(ApplicationEvent $event): void
    {
        $app = $event->getApplication();
        if ($app instanceof Application) {
            $app->addCommand(new InventoryCommand(new Collector($this->database, (new Version())->getShortVersion())));
        }
    }
}
