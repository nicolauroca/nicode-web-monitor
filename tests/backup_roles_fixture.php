<?php
/** Disposable Joomla accounts for HTTP ACL tests. Never run against a production database. */
if (($argv[2] ?? '') !== '--allow-disposable-writes') { exit(2); }
$_SERVER['HTTP_HOST']='localhost'; $_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['REQUEST_URI']='/index.php';
define('_JEXEC', 1); define('JPATH_BASE', realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\CMS\Table\Table;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
$c=Factory::getContainer();
$c->alias('session','session.cli')->alias(\Joomla\Session\SessionInterface::class,'session.cli');
Factory::$application=$c->get(\Joomla\CMS\Application\ConsoleApplication::class);
Factory::$application->createExtensionNamespaceMap();
Factory::$application->bootComponent('com_nicodewebmonitor');
$db=$c->get(DatabaseInterface::class);
$d=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
$asset=(int)$db->setQuery("SELECT id FROM #__assets WHERE name='com_nicodewebmonitor'")->loadResult();
if ($d['action']==='snapshot') {
    $rows=[];
    foreach (['sites','backup_credentials','backup_operations'] as $table) {
        $rows[$table]=$db->setQuery('SELECT * FROM #__nwm_'.$table.' ORDER BY 1')->loadAssocList();
    }
    echo json_encode(['sha256'=>hash('sha256',json_encode($rows))]); exit;
}
$db->transactionStart();
try {
    if ($d['action']==='setup') {
        $result=['users'=>[], 'groups'=>[]];
        foreach (['reader','operator'] as $role) {
            $name='nwm_qa_'.bin2hex(random_bytes(6));
            $group=Table::getInstance('Usergroup');
            $group->parent_id=2; $group->title=$name;
            if (!$group->check() || !$group->store()) { throw new RuntimeException('Group creation failed'); }
            $gid=(int)$group->id; $result['groups'][]=$gid;
            foreach ([1,$asset] as $aid) {
                $rules=json_decode($db->setQuery('SELECT rules FROM #__assets WHERE id='.$aid)->loadResult(),true);
                $rules[$aid===1?'core.login.admin':'core.manage'][(string)$gid]=1;
                if ($aid===$asset && $role==='operator') { $rules['nwm.backup'][(string)$gid]=1; }
                $db->setQuery('UPDATE #__assets SET rules='.$db->quote(json_encode($rules)).' WHERE id='.$aid)->execute();
            }
            $password=bin2hex(random_bytes(24)); $user=new User();
            $fields=['name'=>$name,'username'=>$name,'email'=>$name.'@example.invalid','password'=>$password,'password2'=>$password,'groups'=>[$gid],'block'=>0];
            if (!$user->bind($fields) || !$user->save()) {
                throw new RuntimeException('User creation failed');
            }
            $result['users'][$role]=['id'=>$user->id,'username'=>$name,'password'=>$password];
        }
        $uuid='12345678-1234-4123-8123-'.bin2hex(random_bytes(6));
        $vault=new \Nicode\Component\NicodeWebMonitor\Administrator\Service\CredentialVault(Factory::$application->get('secret'));
        // Loopback address is intentionally rejected by the production transport, without outbound I/O.
        $row=(object)['site_id'=>$uuid,'label'=>'Disposable role QA','base_url'=>'https://example.invalid','address'=>'127.0.0.1','credential'=>$vault->seal($uuid,bin2hex(random_bytes(32)))];
        $db->insertObject('#__nwm_sites',$row,'id'); $result['site']=(int)$row->id;
        $row=(object)['site'=>$row->id,'credential'=>$vault->seal($uuid.':backup',bin2hex(random_bytes(32)))];
        $db->insertObject('#__nwm_backup_credentials',$row);
    } elseif ($d['action']==='cleanup') {
        foreach (['backup_operations','backup_credentials'] as $table) { $db->setQuery('DELETE FROM #__nwm_'.$table.' WHERE site='.(int)$d['site'])->execute(); }
        $db->setQuery('DELETE FROM #__nwm_sites WHERE id='.(int)$d['site'])->execute();
        foreach ($d['users'] as $u) { if (!(new User((int)$u['id']))->delete()) { throw new RuntimeException('User cleanup failed'); } }
        foreach ([1,$asset] as $aid) {
            $rules=json_decode($db->setQuery('SELECT rules FROM #__assets WHERE id='.$aid)->loadResult(),true);
            foreach ($rules as &$rule) { foreach ($d['groups'] as $gid) { unset($rule[(string)$gid]); } } unset($rule);
            $db->setQuery('UPDATE #__assets SET rules='.$db->quote(json_encode($rules)).' WHERE id='.$aid)->execute();
        }
        foreach ($d['groups'] as $gid) { $group=Table::getInstance('Usergroup'); if (!$group->delete((int)$gid)) { throw new RuntimeException('Group cleanup failed'); } }
        $result=['cleaned'=>true];
    } else { throw new RuntimeException('Unknown fixture action'); }
    $db->transactionCommit(); echo json_encode($result,JSON_THROW_ON_ERROR);
} catch (Throwable $e) { $db->transactionRollback(); fwrite(STDERR, get_class($e).': '.$e->getMessage()); exit(1); }
