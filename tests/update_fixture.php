<?php
/** @package Nicode.joomla.webmonitor. Explicit disposable fixture; JSON stdin, no secret logs. */
if (($argv[2] ?? '')!=='--allow-disposable-writes') { exit(2); }
define('_JEXEC',1); define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerHelper;
use Joomla\Database\DatabaseInterface;
$c=Factory::getContainer();
$c->alias('session','session.cli')->alias(\Joomla\Session\SessionInterface::class,'session.cli');
Factory::$application=$c->get(\Joomla\CMS\Application\ConsoleApplication::class);
Factory::$application->createExtensionNamespaceMap();
$db=$c->get(DatabaseInterface::class);
$d=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
function installPackage($file) {
    global $db;
    $p=InstallerHelper::unpack($file,true);
    if (!$p || !$p['type']) { throw new RuntimeException('Invalid fixture package'); }
    try {$i=new Installer();$i->setDatabase($db);if(!$i->install($p['extractdir']))throw new RuntimeException('Install failed');}
    finally {\Joomla\Filesystem\Folder::delete($p['extractdir']);}
}
$where="type='plugin' AND folder='system' AND element='nicodewebmonitor'";
$kind=$d['kind']??'plugin';
if(!in_array($kind,['plugin','module-site','module-admin','component','template-site','template-admin','library'],true))throw new RuntimeException('Unknown fixture kind');
$type=explode('-',$kind)[0];
$client=in_array($kind,['module-admin','component','template-admin'],true)?1:0;
$element=($type==='module'?'mod_':($type==='component'?'com_':'')).'nwmupdateqa';
$folder=$type==='plugin'?'system':'';
$fixture='type='.$db->quote($type).' AND element='.$db->quote($element).' AND folder='.$db->quote($folder).' AND client_id='.$client;
$fixturePath=JPATH_BASE.($client?'/administrator':'').(['plugin'=>'/plugins/system/','module'=>'/modules/','component'=>'/components/','template'=>'/templates/','library'=>'/libraries/'][$type]).$element;

switch($d['action']) {
case 'install':
    foreach($d['packages'] as $file) {installPackage($file);} echo json_encode(['installed'=>true]);break;
case 'setup':
    if($db->setQuery('SELECT extension_id FROM #__extensions WHERE '.$fixture)->loadResult())throw new RuntimeException('Fixture already exists');
    $old=$db->setQuery('SELECT enabled,params FROM #__extensions WHERE '.$where)->loadAssoc();
    if(!$old)throw new RuntimeException('Connector absent');
    installPackage($d['package']);
    $id=(int)$db->setQuery('SELECT extension_id FROM #__extensions WHERE '.$fixture)->loadResult();
    $db->setQuery('UPDATE #__extensions SET enabled=0 WHERE extension_id='.$id)->execute();
    $db->setQuery('UPDATE #__extensions SET enabled=1,params='.$db->quote(json_encode($d['params'])).' WHERE '.$where)->execute();
    echo json_encode(['id'=>$id,'old'=>$old]);break;
case 'configure':
    $db->setQuery('UPDATE #__extensions SET enabled=1,params='.$db->quote(json_encode($d['params'])).' WHERE '.$where)->execute();
    echo json_encode(['configured'=>true]);break;
case 'inspect':
    $row=$db->setQuery('SELECT extension_id,manifest_cache,enabled FROM #__extensions WHERE '.$fixture)->loadAssoc();
    echo json_encode($row?['id'=>(int)$row['extension_id'],'version'=>json_decode($row['manifest_cache'],true)['version'],
        'enabled'=>(int)$row['enabled'],'file'=>trim(file_get_contents($fixturePath.'/version.txt'))]:['absent'=>true]);break;
case 'cleanup':
    $id=$db->setQuery('SELECT extension_id FROM #__extensions WHERE '.$fixture)->loadResult();
    if($id){$i=new Installer();$i->setDatabase($db);if(!$i->uninstall($type,(int)$id))throw new RuntimeException('Cleanup failed');}
    $db->setQuery('UPDATE #__extensions SET enabled='.(int)$d['old']['enabled'].',params='.$db->quote($d['old']['params']).' WHERE '.$where)->execute();
    if(is_dir($fixturePath))throw new RuntimeException('Fixture remains');
    echo json_encode(['removed'=>true]);break;
default:throw new RuntimeException('Unknown action');
}
