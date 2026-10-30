<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor. Disposable fixture setup/cleanup for preparation tests. */
if (($argv[3]??'')!=='--allow-disposable-writes')exit(2);
define('_JEXEC',1);define('JPATH_BASE',realpath($argv[1]));require JPATH_BASE.'/includes/defines.php';require JPATH_BASE.'/includes/framework.php';
$c=Joomla\CMS\Factory::getContainer();$c->alias('session','session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
Joomla\CMS\Factory::$application=$c->get(Joomla\CMS\Application\ConsoleApplication::class);
Joomla\CMS\Factory::$application->createExtensionNamespaceMap();
$db=$c->get(Joomla\Database\DatabaseInterface::class);
require __DIR__.'/../tools/PackageState.php';
$state=new Nicode\Maintenance\PackageState($db);
$identities=[['type'=>'library','element'=>'nwmpackageqa','folder'=>'','client_id'=>0],['type'=>'module','element'=>'mod_nwmpackageqa','folder'=>'','client_id'=>0]];
$inspect=fn()=>$state->inspect('pkg_nwmpackageqa',$identities);
if($argv[2]==='setup'){
    if($inspect()!==['parents'=>[],'children'=>[]])throw new RuntimeException('Fixture already exists');
    $p=Joomla\CMS\Installer\InstallerHelper::unpack($argv[4],true);if(!$p)throw new RuntimeException('Unpack failed');
    try{$i=new Joomla\CMS\Installer\Installer();$i->setDatabase($db);if(!$i->install($p['extractdir']))throw new RuntimeException('Fixture install failed');}
    finally{Joomla\Filesystem\Folder::delete($p['extractdir']);}
}elseif($argv[2]==='cleanup'){
    foreach([['package','pkg_nwmpackageqa'],['module','mod_nwmpackageqa'],['library','nwmpackageqa']] as [$type,$element]){
        $ids=$db->setQuery('SELECT extension_id FROM #__extensions WHERE type='.$db->quote($type).' AND element='.$db->quote($element))->loadColumn();
        foreach($ids as $id){$i=new Joomla\CMS\Installer\Installer();$i->setDatabase($db);if(!$i->uninstall($type,(int)$id))throw new RuntimeException('Cleanup failed');}
    }
}elseif($argv[2]!=='inspect'){throw new RuntimeException('Invalid action');}
echo json_encode($inspect(),JSON_THROW_ON_ERROR)."\n";
