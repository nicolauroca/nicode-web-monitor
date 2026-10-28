<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor. Internal bootstrap for the one-shot core commands. */
if (PHP_SAPI !== 'cli') { exit(2); }
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
$root = realpath($argv[1]);
if (!$root || !is_file($root.'/configuration.php') || !is_file($root.'/includes/framework.php')
    || is_file($root.'/installation/index.php')) { throw new RuntimeException('Installed Joomla required'); }
$_SERVER['HTTP_HOST']='joomla.invalid'; $_SERVER['REQUEST_URI']='/cli'; $_SERVER['HTTPS']='on';
define('_JEXEC',1); define('JPATH_BASE',$root);
require $root.'/includes/defines.php'; require $root.'/includes/framework.php';
$container=Factory::getContainer();
$container->alias('session','session.cli')->alias(\Joomla\Session\SessionInterface::class,'session.cli');
Factory::$application=$container->get(\Joomla\CMS\Application\ConsoleApplication::class);
Factory::$application->createExtensionNamespaceMap();
$db=$container->get(DatabaseInterface::class);
$params=$db->setQuery("SELECT params FROM #__extensions WHERE type='plugin' AND folder='system' AND element='nicodewebmonitor' AND enabled=1")->loadResult();
$params=json_decode((string)$params,true,16,JSON_THROW_ON_ERROR);
foreach (['CoreDispatch','CoreOperation','MaintenanceGate','InstalledCore','NativeCoreUpdate','CoreUpdatePreflight','DatabaseSnapshot'] as $class) {
    require_once __DIR__.'/'.$class.'.php';
}
$storage=$params['update_storage'] ?? '';
$gate=new \Nicode\Maintenance\MaintenanceGate($params['maintenance_storage'] ?? '',$root);
$dispatch=new \Nicode\Maintenance\CoreDispatch($storage,$gate,$root);
