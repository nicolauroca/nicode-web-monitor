<?php
/** State isolation for onboarding_http.py. Never use on an existing registry. */
if (($argv[2]??'')!=='--allow-disposable-writes') exit(2);
$_SERVER['HTTP_HOST']='localhost'; $_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['REQUEST_URI']='/index.php';
define('_JEXEC',1); define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
$c=Joomla\CMS\Factory::getContainer(); $c->alias('session','session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
$app=$c->get(Joomla\CMS\Application\ConsoleApplication::class); Joomla\CMS\Factory::$application=$app;
$app->createExtensionNamespaceMap(); $app->bootComponent('com_nicodeconnector'); $app->bootComponent('com_nicodewebmonitor');
$db=$c->get(Joomla\Database\DatabaseInterface::class);
$input=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
if ($input['action']==='begin') {
    if ((int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_sites')->loadResult()
        || (int)$db->setQuery('SELECT COUNT(*) FROM #__nwm_pairing')->loadResult()
        || (int)$db->setQuery("SELECT COUNT(*) FROM #__scheduler_tasks WHERE type='nicode.monitor'")->loadResult()) throw new RuntimeException('Empty disposable fixture required');
    $rows=$db->setQuery("SELECT extension_id,params,enabled FROM #__extensions WHERE element IN ('com_nicodeconnector','com_scheduler','nicodewebmonitor','guidedtours','stats','debug')")->loadAssocList();
    $db->setQuery("UPDATE #__extensions SET enabled=0 WHERE type='plugin' AND element IN ('guidedtours','stats','debug')")->execute();
    $schedulerParams=new Joomla\Registry\Registry($db->setQuery("SELECT params FROM #__extensions WHERE type='component' AND element='com_scheduler'")->loadResult());
    $schedulerParams->set('lazy_scheduler.enabled',0);
    $db->setQuery('UPDATE #__extensions SET params='.$db->quote($schedulerParams->toString())." WHERE type='component' AND element='com_scheduler'")->execute();
    $systemId=(int)$db->setQuery("SELECT extension_id FROM #__extensions WHERE type='plugin' AND folder='system' AND element='nicodewebmonitor'")->loadResult();
    echo json_encode(['rows'=>$rows,'system_id'=>$systemId],JSON_THROW_ON_ERROR);
} elseif ($input['action']==='fixture_credential') {
    $rows=$db->setQuery('SELECT id,site_id,credential FROM #__nwm_sites')->loadAssocList();
    if(count($rows)!==1)throw new RuntimeException('Single disposable enrollment required');
    $row=$rows[0];$vault=new Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault($app->get('secret'));
    echo json_encode(['id'=>(int)$row['id'],'token'=>$vault->open($row['site_id'],$row['credential'])],JSON_THROW_ON_ERROR);
} elseif ($input['action']==='ui_snapshot') {
    echo json_encode(['rows'=>$db->setQuery("SELECT extension_id,params,enabled FROM #__extensions WHERE (type='component' AND element='com_nicodeconnector') OR (type='plugin' AND folder='system' AND element='nicodewebmonitor')")->loadAssocList()],JSON_THROW_ON_ERROR);
} elseif ($input['action']==='ui_restore') {
    foreach($input['rows'] as $row)$db->setQuery('UPDATE #__extensions SET params='.$db->quote($row['params']).',enabled='.(int)$row['enabled'].' WHERE extension_id='.(int)$row['extension_id'])->execute();
    echo '{"ok":true}';
} elseif ($input['action']==='ui_preview') {
    if(!in_array($input['state'],['connected','pending','disconnected'],true))throw new RuntimeException('Invalid preview state');
    $params=new Joomla\Registry\Registry($db->setQuery("SELECT params FROM #__extensions WHERE type='component' AND element='com_nicodeconnector'")->loadResult());
    $params->set('connection_state',$input['state']);$params->set('central_url',$input['central_url']);$params->set('connection_type','https');
    $db->setQuery('UPDATE #__extensions SET params='.$db->quote($params->toString())." WHERE type='component' AND element='com_nicodeconnector'")->execute();
    $db->setQuery('UPDATE #__extensions SET enabled='.(int)$input['enabled']." WHERE type='plugin' AND folder='system' AND element='nicodewebmonitor'")->execute();
    echo '{"ok":true}';
} elseif ($input['action']==='seed_inventory') {
    $admin=new Joomla\CMS\User\User((int)$db->setQuery('SELECT user_id FROM #__user_usergroup_map WHERE group_id=8')->loadResult());
    $registry=new Nicode\Component\NicodeWebMonitor\Administrator\Service\SiteRegistry($db,new Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault($app->get('secret')),$admin);
    $registry->add($input['label'],'https://example.org','93.184.216.34',$input['site_id'],$input['credential']);
    echo '{"ok":true}';
} elseif ($input['action']==='cleanup') {
    $db->setQuery('DELETE FROM #__nwm_inventory')->execute();$db->setQuery('DELETE FROM #__nwm_checks')->execute(); $db->setQuery('DELETE FROM #__nwm_sites')->execute(); $db->setQuery('DELETE FROM #__nwm_pairing')->execute();
    $scheduler=$app->bootComponent('com_scheduler');
    $ids=$db->setQuery("SELECT id FROM #__scheduler_tasks WHERE type='nicode.monitor'")->loadColumn();
    foreach ($ids as $id) { $table=$scheduler->getMVCFactory()->createTable('Task','Administrator'); if (!$table->delete((int)$id)) throw new RuntimeException('Task cleanup failed'); }
    foreach ($input['rows'] as $row) $db->setQuery('UPDATE #__extensions SET params='.$db->quote($row['params']).',enabled='.(int)$row['enabled'].' WHERE extension_id='.(int)$row['extension_id'])->execute();
    echo '{"ok":true}';
} else throw new RuntimeException('Unknown fixture action');
