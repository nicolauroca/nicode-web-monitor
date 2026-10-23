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
use Nicode\Plugin\System\NicodeWebMonitor\Transport\BackupEndpoint;
use Nicode\Plugin\System\NicodeWebMonitor\Maintenance\SiteSnapshot;

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
        $task = $app->getInput()->get->getString('task', '');
        if (in_array($task, ['backup', 'backup_tree'], true)) {
            $storage = (string) $this->params->get('backup_storage', '');
            $endpoint = new BackupEndpoint((string) $this->params->get('site_id', ''),
                (string) $this->params->get('backup_token_hash', ''),
                (string) $this->params->get('token_hash', ''), $storage, JPATH_ROOT, $task === 'backup_tree');
            // Bound the read even when Content-Length is missing or false.
            $body = file_get_contents('php://input', false, null, 0, 4097);
            [$status, $body] = $endpoint->handle($_SERVER, $_GET, $body === false ? '' : $body,
                function (string $id) use ($app, $storage, $task): array {
                    // Offline alone does not stop cron/admin/external writers. A local operator
                    // must stop them and arm this single operation for a short maintenance window.
                    $arm = $storage . '/armed.json';
                    if (!$app->get('offline') || !is_file($arm) || is_link($arm) || filesize($arm) > 1024) {
                        throw new \RuntimeException('Maintenance preconditions required');
                    }
                    $permit = json_decode(file_get_contents($arm), true, 8, JSON_THROW_ON_ERROR);
                    if (($permit['operation'] ?? null) !== $id || ($permit['writers_stopped'] ?? null) !== true
                        || !is_int($permit['expires_at'] ?? null) || $permit['expires_at'] < time()
                        || $permit['expires_at'] > time() + 900) {
                        throw new \RuntimeException('Operation not armed');
                    }
                    if ($task === 'backup_tree') {
                        BackupEndpoint::privateDirectory($storage . '/trees', JPATH_ROOT);
                        return (new \Nicode\Plugin\System\NicodeWebMonitor\Maintenance\ResumableTree($storage . '/trees'))->step(JPATH_ROOT, $id, true);
                    }
                    BackupEndpoint::privateDirectory($storage . '/snapshots', JPATH_ROOT);
                    $this->database->connect();
                    $connection = $this->database->getConnection();
                    if (!$connection instanceof \mysqli) { throw new \RuntimeException('Unsupported database driver'); }
                    return (new SiteSnapshot($storage . '/snapshots'))->capture($connection, JPATH_ROOT, $id, true);
                });
        } else {
            $endpoint = new Endpoint((string) $this->params->get('site_id', ''), (string) $this->params->get('token_hash', ''));
            [$status, $body] = $endpoint->handle(
                $_SERVER,
                $app->getInput()->get->getString('task', ''),
                fn () => (new Collector($this->database, (new Version())->getShortVersion()))->collect()
            );
        }
        // Send directly and close before Joomla page rendering or page caching.
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        if ($status === 401) { header('WWW-Authenticate: Bearer realm="Nicode Web Monitor"'); }
        if ($status === 405) { header('Allow: ' . (in_array($task, ['backup', 'backup_tree'], true) ? 'GET, POST' : 'GET')); }
        echo json_encode($body, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $app->close();
    }
}
