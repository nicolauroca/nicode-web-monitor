<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Plugin\System\NicodeWebMonitor\Extension;

use Joomla\CMS\Event\Application\AfterInitialiseEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;
use Nicode\Plugin\System\NicodeWebMonitor\Inventory\Collector;
use Nicode\Plugin\System\NicodeWebMonitor\Transport\Endpoint;

defined('_JEXEC') or die;

final class NicodeWebMonitor extends CMSPlugin implements SubscriberInterface
{
    public function __construct(array $config, private DatabaseInterface $database)
    {
        parent::__construct($config);
    }

    public static function getSubscribedEvents(): array
    {
        return ['onAfterInitialise' => 'serve'];
    }

    public function serve(AfterInitialiseEvent $event): void
    {
        $app = $event->getApplication();
        if (!$app->isClient('site') || $app->getInput()->get->getString('option') !== 'com_nicodewebmonitor') {
            return;
        }
        $endpoint = new Endpoint((string) $this->params->get('site_id', ''), (string) $this->params->get('token_hash', ''));
        [$status, $body] = $endpoint->handle(
            $_SERVER,
            $app->getInput()->get->getString('task', ''),
            fn () => (new Collector($this->database, (new Version())->getShortVersion()))->collect()
        );
        // Send directly and close before Joomla page rendering or page caching.
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        if ($status === 401) { header('WWW-Authenticate: Bearer realm="Nicode Web Monitor"'); }
        if ($status === 405) { header('Allow: GET'); }
        echo json_encode($body, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $app->close();
    }
}
