<?php
/** @license GPL-2.0-or-later */
namespace Nicode\Component\NicodeWebMonitor\Administrator\Service;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

final class MonitoringSetup
{
    public function __construct(private DatabaseInterface $db) {}

    public function enable(User $user): void
    {
        if (!$user->id || $user->guest || $user->block || !$user->authorise('core.admin','com_nicodewebmonitor')
            || !$user->authorise('nwm.monitor','com_nicodewebmonitor')) throw new \RuntimeException('Access denied');
        $lock=$this->db->quote('nwm-setup:'.substr(hash('sha256',$this->db->getPrefix().JPATH_ROOT),0,40));
        if (!(int)$this->db->setQuery('SELECT GET_LOCK('.$lock.',0)')->loadResult()) throw new \RuntimeException('Setup busy');
        try {
            $plugin=(int)$this->db->setQuery("SELECT extension_id FROM #__extensions WHERE type='plugin' AND folder='task' AND element='nicodewebmonitor'")->loadResult();
            if (!$plugin) throw new \RuntimeException('Install the central package first');
            $existing=$this->db->setQuery("SELECT id FROM #__scheduler_tasks WHERE type='nicode.monitor' ORDER BY id")->loadColumn();
            if ($existing) return; // Preserve an operator's cadence, owner and deliberate pause.
            $this->db->setQuery('UPDATE #__extensions SET enabled=1 WHERE extension_id='.$plugin)->execute();
            $component=Factory::getApplication()->bootComponent('com_scheduler');
            $table=$component->getMVCFactory()->createTable('Task','Administrator');
            $table->setCurrentUser($user);
            if (!$table->bind(['title'=>'Nicode: comprobar sitios','type'=>'nicode.monitor','state'=>1,
                'created_by'=>$user->id,'next_execution'=>gmdate('Y-m-d H:i:s'),
                'params'=>['user_id'=>$user->id,'limit'=>5,'budget'=>20],
                'execution_rules'=>['rule-type'=>'interval-minutes','interval-minutes'=>5],
                'cron_rules'=>['type'=>'interval-minutes'],'priority'=>0]) || !$table->check() || !$table->store()) {
                throw new \RuntimeException('Task creation failed');
            }
        } finally { $this->db->setQuery('SELECT RELEASE_LOCK('.$lock.')')->loadResult(); }
    }

    public function status(): array
    {
        $task=$this->db->setQuery("SELECT * FROM #__scheduler_tasks WHERE type='nicode.monitor' ORDER BY id",0,1)->loadAssoc();
        $enabled=(int)$this->db->setQuery("SELECT enabled FROM #__extensions WHERE type='plugin' AND folder='task' AND element='nicodewebmonitor'")->loadResult();
        $last=$task['last_execution']??null;
        $at=$last ? strtotime($last.' UTC') : false;
        $owner=$task ? new User((int)$task['created_by']) : null;
        $params=json_decode($task['params']??'{}',true)?:[];
        $runner=$task ? new User((int)($params['user_id']??0)) : null;
        $authorised=$owner && $owner->id && !$owner->block && $owner->authorise('core.admin','com_nicodewebmonitor')
            && $runner && $runner->id && !$runner->block && $runner->authorise('core.manage','com_nicodewebmonitor') && $runner->authorise('nwm.monitor','com_nicodewebmonitor');
        $state=!$task ? 'pending' : ((!$enabled || (int)$task['state']!==1) ? 'paused' : (!$authorised ? 'permissions' : (!$at ? 'waiting' : ($at>time()+60 || $at<time()-900 ? 'late' : ((int)($task['last_exit_code']??-1)===0 ? 'running' : 'failed')))));
        return ['state'=>$state,'last'=>$last,'next'=>$task['next_execution']??null,'tasks'=> (int)$this->db->setQuery("SELECT COUNT(*) FROM #__scheduler_tasks WHERE type='nicode.monitor'")->loadResult()];
    }
}
