<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Dispatcher;
defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\SiteRegistry;
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
        $task = $this->input->getCmd('task', 'display');
        if ($task !== 'display') {
            if ($this->input->getMethod() !== 'POST' || !Session::checkToken('post')) {
                throw new \RuntimeException('Invalid request', 403);
            }
            if (!in_array($task, ['add', 'remove'], true)) {
                throw new \RuntimeException('Unknown task', 404);
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
        require JPATH_ADMINISTRATOR . '/components/com_nicodewebmonitor/tmpl/registry.php';
    }
}
