<?php
/** @package Nicode.joomla.webmonitor. One native installer request per process. */
if (($argv[2]??'')!=='--allow-disposable-writes')exit(2);
define('_JEXEC',1);define('JPATH_BASE',realpath($argv[1]));require JPATH_BASE.'/includes/defines.php';require JPATH_BASE.'/includes/framework.php';
$c=Joomla\CMS\Factory::getContainer();$c->alias('session','session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
Joomla\CMS\Factory::$application=$c->get(Joomla\CMS\Application\ConsoleApplication::class);Joomla\CMS\Factory::$application->createExtensionNamespaceMap();
$db=$c->get(Joomla\Database\DatabaseInterface::class);$d=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
$i=new Joomla\CMS\Installer\Installer();$i->setDatabase($db);
if($d['action']==='install'){
 $p=Joomla\CMS\Installer\InstallerHelper::unpack($d['zip'],true);if(!$p)throw new RuntimeException('Unpack failed');
 try{$ok=$i->install($p['extractdir']);}finally{Joomla\Filesystem\Folder::delete($p['extractdir']);}
}elseif($d['action']==='uninstall'){
 $row=$db->setQuery('SELECT element FROM #__extensions WHERE extension_id='.(int)$d['id'])->loadResult();
 if(!in_array($row,['nicodewebmonitor','com_nicodewebmonitor'],true))throw new RuntimeException('Not a product extension');
 $ok=$i->uninstall($d['type'],(int)$d['id']);
}else throw new RuntimeException('Unknown step');
if(!$ok)throw new RuntimeException('Native installer rejected step');echo '{"ok":true}';
