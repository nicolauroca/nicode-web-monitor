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
        $this->app->setHeader('Cache-Control','no-store, private',true);
        $this->app->setHeader('Referrer-Policy','no-referrer',true);
        $user = $this->app->getIdentity();
        $selectedSiteId=$this->input->get->getInt('site',0);
        $viewPage=$this->input->get->getCmd('screen','sites');
        $viewSection=$this->input->get->getCmd('section','overview');
        $viewCategory=$this->input->get->getCmd('category','');
        $viewTool=$this->input->get->getCmd('tool','');
        $priorityFilter=$this->input->get->getCmd('health','all');
        $registry = new SiteRegistry(Factory::getContainer()->get(DatabaseInterface::class), new CredentialVault($this->app->get('secret')), $user);
        $backups = new BackupCoordinator(Factory::getContainer()->get(DatabaseInterface::class), new CredentialVault($this->app->get('secret')), $user);
        $canBackup = !$user->guest && !$user->block && $user->authorise('nwm.backup', 'com_nicodewebmonitor');
        $updates = new \Nicode\Component\NicodeWebMonitor\Administrator\Service\UpdateCoordinator(Factory::getContainer()->get(DatabaseInterface::class), new CredentialVault($this->app->get('secret')), $user);
        $canUpdate = !$user->guest && !$user->block && $user->authorise('nwm.update', 'com_nicodewebmonitor');
        $updateOperations = $selectedSiteId>0 && $viewSection==='maintenance' && $viewTool==='extensions' && $canUpdate ? $updates->recent($selectedSiteId) : [];
        $coreUpdates = new \Nicode\Component\NicodeWebMonitor\Administrator\Service\CoreUpdateCoordinator(Factory::getContainer()->get(DatabaseInterface::class), new CredentialVault($this->app->get('secret')), $user);
        $canCoreUpdate = !$user->guest && !$user->block && $user->authorise('nwm.coreupdate', 'com_nicodewebmonitor');
        $coreUpdateOperations = $selectedSiteId>0 && $viewSection==='maintenance' && $viewTool==='joomla' && $canCoreUpdate ? $coreUpdates->recent($selectedSiteId) : [];
        $maintenanceCredentialStored=false;
        $credentialTables=['backups'=>[$canBackup,'#__nwm_backup_credentials'],'extensions'=>[$canUpdate,'#__nwm_update_credentials'],'joomla'=>[$canCoreUpdate,'#__nwm_core_update_credentials']];
        if($selectedSiteId>0&&$viewSection==='maintenance'&&isset($credentialTables[$viewTool])&&$credentialTables[$viewTool][0]){
            $maintenanceCredentialStored=(bool)Factory::getContainer()->get(DatabaseInterface::class)->setQuery('SELECT site FROM '.$credentialTables[$viewTool][1].' WHERE site='.$selectedSiteId)->loadResult();
        }
        $setup = new \Nicode\Component\NicodeWebMonitor\Administrator\Service\MonitoringSetup(Factory::getContainer()->get(DatabaseInterface::class));
        $monitoring = $setup->status();
        $pairingCode = null;
        $task = $this->input->getCmd('task', 'display');
        $inspection = null;
        $inspectedId = 0;
        if($task==='display'&&$selectedSiteId>0&&$viewSection==='inventory'){
            $inspectedId=$selectedSiteId;$inspection=$registry->latest($inspectedId);
        }
        $siteRows=function()use($registry,&$selectedSiteId):array{
            if($selectedSiteId!==0){$row=$registry->find($selectedSiteId);return $row?[$row]:[];}
            return $registry->all();
        };
        $maintenanceReturn=function(string $kind):string{
            $post=$this->input->post;$id=$post->getInt('id');
            if($post->getString('operation','')!==''){
                $db=Factory::getContainer()->get(DatabaseInterface::class);
                $table=['backup'=>'#__nwm_backup_operations','update'=>'#__nwm_update_operations','core'=>'#__nwm_core_update_operations'][$kind];
                $id=(int)$db->setQuery('SELECT site FROM '.$db->quoteName($table).' WHERE operation='.$db->quote($post->getString('operation')))->loadResult();
            }
            $tool=['backup'=>'backups','update'=>'extensions','core'=>'joomla'][$kind];
            return 'index.php?option=com_nicodewebmonitor'.($id>0?'&site='.$id.'&section=maintenance&tool='.$tool:'&screen=maintenance');
        };
        if ($task !== 'display') {
            if ($this->input->getMethod() !== 'POST' || !Session::checkToken('post')) {
                throw new \RuntimeException('Invalid request', 403);
            }
            if (!in_array($task, ['refreshReferences', 'issueCode', 'setupMonitoring', 'notificationRetry', 'remove', 'inspect', 'backupEnroll', 'backupBegin', 'backupAdvance', 'backupContinue', 'backupStatus', 'updateEnroll', 'updateBegin', 'updateExecute', 'updateStatus', 'coreUpdateEnroll', 'coreUpdateBegin', 'coreUpdateExecute', 'coreUpdateStatus'], true)) {
                throw new \RuntimeException('Unknown task', 404);
            }
            if($task==='refreshReferences'){
                $catalogService=new \Nicode\Component\NicodeWebMonitor\Administrator\Service\ReleaseCatalog(Factory::getContainer()->get(DatabaseInterface::class));
                $result=$catalogService->refresh($user,true);
                $this->app->enqueueMessage($result['status']==='updated'?'Referencias oficiales actualizadas.':'No se han podido actualizar todas las referencias. Se conservan las fechas de las últimas consultas.',$result['status']==='updated'?'message':'warning');
                $this->app->redirect('index.php?option=com_nicodewebmonitor'.($selectedSiteId>0?'&site='.$selectedSiteId.'&section=health':''),303);
                return;
            }
            if ($task === 'issueCode' || $task === 'setupMonitoring') {
                $selectedSiteId=0;$viewPage=$task==='issueCode'?'add':'monitoring';
                try {
                    if ($user->authorise('core.admin','com_nicodewebmonitor')) $setup->enable($user);
                    elseif ($task === 'setupMonitoring') throw new \RuntimeException('Access denied');
                    if ($task === 'issueCode') {
                        if (!in_array(strtolower((string)($_SERVER['HTTPS']??'')),['on','1'],true)) throw new \RuntimeException('HTTPS required');
                        $pairingCode = (new \Nicode\Component\NicodeWebMonitor\Administrator\Service\PairingService(Factory::getContainer()->get(DatabaseInterface::class),new CredentialVault($this->app->get('secret'))))->issue($user,$this->input->post->getString('label'));
                    }
                    $monitoring = $setup->status();
                } catch (\Throwable) {
                    $this->app->enqueueMessage('No se pudo preparar la conexión. Comprueba el nombre del sitio, HTTPS, tus permisos y el paquete central.','error');
                }
                $sites = $siteRows();
                $backupOperations = $selectedSiteId>0 && $viewSection==='maintenance' && $viewTool==='backups' && $canBackup ? $backups->recent($selectedSiteId) : [];
                $this->app->setHeader('Cache-Control','no-store, private',true);
                $this->app->setHeader('Referrer-Policy','no-referrer',true);
                require JPATH_ADMINISTRATOR . '/components/com_nicodewebmonitor/tmpl/registry.php';
                return;
            }
            if ($task === 'notificationRetry') {
                if ($this->input->post->getString('confirmed') !== '1') { throw new \RuntimeException('Confirmation required',403); }
                (new \Nicode\Component\NicodeWebMonitor\Administrator\Service\Notifications(Factory::getContainer()->get(DatabaseInterface::class), $user))->retry($this->input->post->getInt('id'));
                $this->app->enqueueMessage('One notification attempt authorized for the next monitoring task. No email was sent by this action.');
                $this->app->redirect('index.php?option=com_nicodewebmonitor&site='.$this->input->post->getInt('id').'&section=history');
                return;
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
                $this->app->redirect($maintenanceReturn('update'));
                return;
            }
            if (str_starts_with($task, 'coreUpdate')) {
                if (!$canCoreUpdate || ($task === 'coreUpdateEnroll' && !$user->authorise('core.admin', 'com_nicodewebmonitor'))) {
                    throw new \RuntimeException('Access denied', 403);
                }
                $post = $this->input->post;
                try {
                    if ($task === 'coreUpdateEnroll') {
                        $coreUpdates->enroll($post->getInt('id'), $post->getString('core_update_credential'));
                    } elseif ($task === 'coreUpdateBegin') {
                        $coreUpdates->begin($post->getInt('id'), $post->getString('operation'), $post->getString('confirmed') === '1');
                    } else {
                        $result = $coreUpdates->run($post->getString('operation'), $task === 'coreUpdateExecute', $post->getString('confirmed') === '1');
                        if (!($result['ok'] ?? false)) { throw new \RuntimeException('Update request failed'); }
                    }
                    $this->app->enqueueMessage('Update journal updated. Review the operation state.');
                } catch (\Throwable) {
                    $this->app->enqueueMessage('Update not confirmed. Query status before any further action. No automatic retry was made.', 'error');
                }
                $this->app->redirect($maintenanceReturn('core'));
                return;
            }
            if (str_starts_with($task, 'backup')) {
                if (!$canBackup || ($task === 'backupEnroll' && !$user->authorise('core.admin', 'com_nicodewebmonitor'))) {
                    throw new \RuntimeException('Access denied', 403);
                }
                $post = $this->input->post;
                if ($task === 'backupContinue') {
                    $response = ['ok'=>false];
                    try {
                        $sequence = $post->getString('expected_sequence');
                        if (!preg_match('/^(0|[1-9][0-9]{0,9})$/D', $sequence)) {
                            throw new \InvalidArgumentException('Sequence required');
                        }
                        $result = $backups->run($post->getString('operation'), true, $post->getString('confirmed') === '1', (int)$sequence);
                        if ($result['ok'] ?? false) {
                            $receipt = $result['receipt'];
                            $response = ['ok'=>true, 'operation'=>$post->getString('operation'),
                                'status'=>$receipt['status'], 'next_sequence'=>(int)$receipt['next_sequence']];
                        }
                    } catch (\Throwable) { /* No receipt, token or driver detail in browser errors. */ }
                    $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
                    $this->app->setHeader('Cache-Control', 'no-store', true);
                    $this->app->sendHeaders();
                    echo json_encode($response, JSON_THROW_ON_ERROR);
                    $this->app->close();
                    return;
                }
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
                $this->app->redirect($maintenanceReturn('backup'));
                return;
            }
            if ($task === 'inspect') {
                $inspectedId = $this->input->post->getInt('id');
                $selectedSiteId=$inspectedId;$viewSection='inventory';
                $inspection = $registry->inspect($inspectedId);
                if(($inspection['ok']??false)&&($inspection['snapshot_saved']??false)&&($inspection['history_saved']??false)){
                    $this->app->enqueueMessage('Inventario actualizado. Puedes explorar sus categorías.');
                    $this->app->redirect('index.php?option=com_nicodewebmonitor&site='.$inspectedId.'&section=inventory'.($viewCategory!==''?'&category='.rawurlencode($viewCategory):''),303);
                    return;
                }
                $sites = $siteRows();
                $backupOperations = $selectedSiteId>0 && $viewSection==='maintenance' && $viewTool==='backups' && $canBackup ? $backups->recent($selectedSiteId) : [];
                require JPATH_ADMINISTRATOR . '/components/com_nicodewebmonitor/tmpl/registry.php';
                return;
            }
            try {
                $post = $this->input->post;
                $registry->remove($post->getInt('id'));
                $this->app->enqueueMessage('Site registry updated. Remote connector unchanged.');
            } catch (\Throwable $error) {
                // Never expose driver errors, query values or submitted credentials.
                $this->app->enqueueMessage('No se ha podido eliminar el registro. Comprueba tus permisos.', 'error');
            }
            $this->app->redirect('index.php?option=com_nicodewebmonitor');
            return;
        }
        $sites = $siteRows();
        $backupOperations = $selectedSiteId>0 && $viewSection==='maintenance' && $viewTool==='backups' && $canBackup ? $backups->recent($selectedSiteId) : [];
        require JPATH_ADMINISTRATOR . '/components/com_nicodewebmonitor/tmpl/registry.php';
    }
}
