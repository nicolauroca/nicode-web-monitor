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
        if ($task === 'update_core') {
            $endpoint = new \Nicode\Plugin\System\NicodeWebMonitor\Transport\UpdateEndpoint(
                (string)$this->params->get('site_id',''), (string)$this->params->get('core_token_hash',''),
                [(string)$this->params->get('token_hash',''), (string)$this->params->get('backup_token_hash',''),
                    (string)$this->params->get('update_token_hash','')], 'core_update');
            $body = file_get_contents('php://input', false, null, 0, 4097);
            [$status,$body] = $endpoint->handle($_SERVER,$_GET,$body === false ? '' : $body,
                fn (string $id, bool $execute) => (new \Nicode\Plugin\System\NicodeWebMonitor\Maintenance\CoreDispatch(
                    (string)$this->params->get('update_storage',''),
                    new \Nicode\Plugin\System\NicodeWebMonitor\Maintenance\MaintenanceGate(
                        (string)$this->params->get('maintenance_storage',''), JPATH_ROOT), JPATH_ROOT))->handle($id,$execute,(bool)$app->get('offline')));
        } elseif ($task === 'update_extension') {
            $storage = (string)$this->params->get('update_storage', '');
            $endpoint = new \Nicode\Plugin\System\NicodeWebMonitor\Transport\UpdateEndpoint(
                (string)$this->params->get('site_id', ''), (string)$this->params->get('update_token_hash', ''),
                [(string)$this->params->get('token_hash', ''), (string)$this->params->get('backup_token_hash', ''), (string)$this->params->get('core_token_hash','')]);
            $body = file_get_contents('php://input', false, null, 0, 4097);
            [$status, $body] = $endpoint->handle($_SERVER, $_GET, $body === false ? '' : $body,
                fn (string $id, bool $execute) => (new \Nicode\Plugin\System\NicodeWebMonitor\Maintenance\NativeExtensionUpdate(
                    $this->database, $storage, JPATH_ROOT, (bool)$app->get('offline'),
                    (string)$this->params->get('maintenance_storage','')))->handle($id, $execute));
        } elseif (in_array($task, ['backup', 'backup_tree', 'backup_database', 'backup_site'], true)) {
            $storage = (string) $this->params->get('backup_storage', '');
            $endpoint = new BackupEndpoint((string) $this->params->get('site_id', ''),
                (string) $this->params->get('backup_token_hash', ''),
                (string) $this->params->get('token_hash', ''), $storage, JPATH_ROOT, $task === 'backup_tree', $task === 'backup_database', $task === 'backup_site');
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
                    return (new \Nicode\Plugin\System\NicodeWebMonitor\Maintenance\MaintenanceGate(
                        (string)$this->params->get('maintenance_storage',''),JPATH_ROOT))->run($task.':'.$id,
                        function () use ($app,$storage,$task,$id): array {
                    if ($task === 'backup_tree') {
                        BackupEndpoint::privateDirectory($storage . '/trees', JPATH_ROOT);
                        return (new \Nicode\Plugin\System\NicodeWebMonitor\Maintenance\ResumableTree($storage . '/trees'))->step(JPATH_ROOT, $id, true, 64);
                    }

                    $this->database->connect();
                    $connection = $this->database->getConnection();
                    if (!$connection instanceof \mysqli) { throw new \RuntimeException('Unsupported database driver'); }
                    if (in_array($task, ['backup_database', 'backup_site'], true)) {
                        // Joomla starts its session before onAfterInitialise: the backup
                        // requests themselves must not mutate the database being captured.
                        if ($app->get('session_handler', 'filesystem') !== 'filesystem'
                            || (bool) $app->get('session_metadata', true)) {
                            throw new \RuntimeException('Database-writing sessions must be disabled locally');
                        }
                        if ($task === 'backup_site') {
                            foreach (['sites','site-trees','site-databases','site-verify'] as $child) {
                                BackupEndpoint::privateDirectory($storage . '/' . $child, JPATH_ROOT);
                            }
                            return (new \Nicode\Plugin\System\NicodeWebMonitor\Maintenance\ResumableSite($storage . '/sites'))->step($id,
                                fn ($operation) => (new \Nicode\Plugin\System\NicodeWebMonitor\Maintenance\ResumableTree($storage . '/site-trees'))->step(JPATH_ROOT, $operation, true, 64, true),
                                fn ($operation) => (new \Nicode\Plugin\System\NicodeWebMonitor\Maintenance\ResumableDatabase($storage . '/site-databases'))->step($connection, $operation, true),
                                fn ($operation, $deferred) => $deferred
                                    ? (new \Nicode\Plugin\System\NicodeWebMonitor\Maintenance\ResumableTree($storage . '/site-trees'))->step(JPATH_ROOT, $operation, true, 64)
                                    : (new \Nicode\Plugin\System\NicodeWebMonitor\Maintenance\TreeManifest($storage . '/site-verify'))->step(JPATH_ROOT, $operation, true, 64));
                        }
                        BackupEndpoint::privateDirectory($storage . '/databases', JPATH_ROOT);
                        return (new \Nicode\Plugin\System\NicodeWebMonitor\Maintenance\ResumableDatabase($storage . '/databases'))->step($connection, $id, true);
                    }
                    BackupEndpoint::privateDirectory($storage . '/snapshots', JPATH_ROOT);
                    return (new SiteSnapshot($storage . '/snapshots'))->capture($connection, JPATH_ROOT, $id, true);
                        });
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
        if ($status === 405) { header('Allow: ' . (in_array($task, ['backup', 'backup_tree', 'backup_database', 'backup_site', 'update_extension','update_core'], true) ? 'GET, POST' : 'GET')); }
        echo json_encode($body, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $app->close();
    }
}
