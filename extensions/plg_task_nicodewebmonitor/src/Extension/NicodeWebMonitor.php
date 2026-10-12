<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Plugin\Task\NicodeWebMonitor\Extension;
defined('_JEXEC') or die;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\User;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault;
use Nicode\Component\NicodeWebMonitor\Administrator\Service\SiteRegistry;
final class NicodeWebMonitor extends CMSPlugin implements SubscriberInterface {
    use TaskPluginTrait;
    protected $autoloadLanguage = true;
    protected const TASKS_MAP = ['nicode.monitor'=>['langConstPrefix'=>'PLG_TASK_NWM','form'=>'monitor','method'=>'monitor']];
    public function __construct(array $config, private DatabaseInterface $db) { parent::__construct($config); }
    public static function getSubscribedEvents(): array {
        return ['onTaskOptionsList'=>'advertiseRoutines','onExecuteTask'=>'standardRoutineHandler','onContentPrepareForm'=>'enhanceTaskItemForm'];
    }
    protected function monitor(ExecuteTaskEvent $event): int {
        try {
            $this->getApplication()->bootComponent('com_nicodewebmonitor');
            $params = $event->getArgument('params');
            $owner = new User((int)$event->getArgument('subject')->get('created_by'));
            if (!$owner->id || $owner->block || !$owner->authorise('core.admin', 'com_nicodewebmonitor')) {
                throw new \RuntimeException('Task owner cannot delegate monitoring');
            }
            $user = new User((int)($params->user_id ?? 0));
            if (!$user->id || $user->guest || $user->block) { throw new \RuntimeException('Unavailable user'); }
            $registry = new SiteRegistry($this->db, new CredentialVault($this->getApplication()->get('secret')), $user);
            $result = $registry->monitor((int)($params->limit ?? 5), (int)($params->budget ?? 20));
            $this->snapshot['output'] = json_encode($result, JSON_THROW_ON_ERROR);
            return $result['status']==='complete' && $result['failed']===0 ? Status::OK : Status::KNOCKOUT;
        } catch (\Throwable) {
            $this->snapshot['output'] = 'Monitoring unavailable: check component installation, user permissions and task limits.';
            return Status::KNOCKOUT;
        }
    }
}
