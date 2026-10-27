<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Dispatcher;
defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\SiteRegistry;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\BackupCoordinator;
final class Dispatcher extends \Joomla\CMS\Dispatcher\ComponentDispatcher
{
    public function dispatch()
    {
        if (!$this->app->isClient('administrator')) {
            throw new \RuntimeException('Access denied', 403);
        }
        $this->checkAccess();
        $user = $this->app->getIdentity();
        $registry = new SiteRegistry(Factory::getContainer()->get(DatabaseInterface::class), new CredentialVault($this->app->get('secret')), $user);
        $backups = new BackupCoordinator(Factory::getContainer()->get(DatabaseInterface::class), new CredentialVault($this->app->get('secret')), $user);
        $canBackup = !$user->guest && !$user->block && $user->authorise('nwm.backup', 'com_nicodewebmonitor');
        $updates = new \Nicode\Component\NicodeWebMonitor\Administrator\Service\UpdateCoordinator(Factory::getContainer()->get(DatabaseInterface::class), new CredentialVault($this->app->get('secret')), $user);
        $canUpdate = !$user->guest && !$user->block && $user->authorise('nwm.update', 'com_nicodewebmonitor');
        $updateOperations = $canUpdate ? $updates->recent() : [];
        $task = $this->input->getCmd('task', 'display');
        $inspection = null;
        $inspectedId = 0;
        if ($task !== 'display') {
            if ($this->input->getMethod() !== 'POST' || !Session::checkToken('post')) {
                throw new \RuntimeException('Invalid request', 403);
            }
            if (!in_array($task, ['add', 'remove', 'inspect', 'backupEnroll', 'backupBegin', 'backupAdvance', 'backupStatus', 'updateEnroll', 'updateBegin', 'updateExecute', 'updateStatus'], true)) {
                throw new \RuntimeException('Unknown task', 404);
            }
            if (str_starts_with($task, 'update')) {
                if (!$canUpdate || ($task === 'updateEnroll' && !$user->authorise('core.admin', 'com_nicodewebmonitor'))) {
                    throw new \RuntimeException('Access denied', 403);
                }
                $post = $this->input->post;
                try {
                    if ($task === 'updateEnroll') {
                        $updates->enroll($post->getInt('id'), $post->getString('update_credential'));
                    } elseif ($task === 'updateBegin') {
                        $updates->begin($post->getInt('id'), $post->getString('operation'), $post->getString('confirmed') === '1');
                    } else {
                        $result = $updates->run($post->getString('operation'), $task === 'updateExecute', $post->getString('confirmed') === '1');
                        if (!($result['ok'] ?? false)) { throw new \RuntimeException('Update request failed'); }
                    }
                    $this->app->enqueueMessage('Update journal updated. Review the operation state.');
                } catch (\Throwable) {
                    $this->app->enqueueMessage('Update not confirmed. Query status before any further action. No automatic retry was made.', 'error');
                }
                $this->app->redirect('index.php?option=com_nicodewebmonitor');
                return;
            }
            if (str_starts_with($task, 'backup')) {
                if (!$canBackup || ($task === 'backupEnroll' && !$user->authorise('core.admin', 'com_nicodewebmonitor'))) {
                    throw new \RuntimeException('Access denied', 403);
                }
                $post = $this->input->post;
                try {
                    if ($task === 'backupEnroll') {
                        $backups->enroll($post->getInt('id'), $post->getString('backup_credential'));
                    } elseif ($task === 'backupBegin') {
                        $backups->begin($post->getInt('id'), $post->getString('confirmed') === '1');
                    } else {
                        $result = $backups->run($post->getString('operation'), $task === 'backupAdvance', $post->getString('confirmed') === '1');
                        if (!($result['ok'] ?? false)) { throw new \RuntimeException('Backup request failed'); }
                    }
                    $this->app->enqueueMessage('Backup journal updated. Capture completion does not verify restoration.');
                } catch (\Throwable) {
                    $this->app->enqueueMessage('Backup action not confirmed. Review the journal and query status before advancing an uncertain operation. No automatic retry was made.', 'error');
                }
                $this->app->redirect('index.php?option=com_nicodewebmonitor');
                return;
            }
            if ($task === 'inspect') {
                $inspectedId = $this->input->post->getInt('id');
                $inspection = $registry->inspect($inspectedId);
                $sites = $registry->all();
                $backupOperations = $canBackup ? $backups->recent() : [];
                require JPATH_ADMINISTRATOR . '/components/com_nicodewebmonitor/tmpl/registry.php';
                return;
            }
            try {
                $post = $this->input->post;
                if ($task === 'add') {
                    $registry->add($post->getString('label'), $post->getString('base_url'), $post->getString('address'), $post->getString('site_id'), $post->getString('credential'));
                } else {
                    $registry->remove($post->getInt('id'));
                }
                $this->app->enqueueMessage('Site registry updated. Remote connector unchanged.');
            } catch (\Throwable $error) {
                // Never expose driver errors, query values or submitted credentials.
                $this->app->enqueueMessage('Site not changed. Check permissions, unique identity and enrollment fields.', 'error');
            }
            $this->app->redirect('index.php?option=com_nicodewebmonitor');
            return;
        }
        $sites = $registry->all();
        $backupOperations = $canBackup ? $backups->recent() : [];
        require JPATH_ADMINISTRATOR . '/components/com_nicodewebmonitor/tmpl/registry.php';
    }
}
