<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor. Local prepared compound execution or receipt lookup. */
if (PHP_SAPI!=='cli' || count($argv)!==7 || !in_array($argv[5],['execute','status'],true)
    || $argv[6]!=='--explicit-local-maintenance') {
    fwrite(STDERR,"Usage: php run_package_update.php JOOMLA_ROOT PRIVATE_PACKAGE_DIR SHARED_GATE_DIR OPERATION execute|status --explicit-local-maintenance\n"); exit(2);
}
define('_JEXEC',1); define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
$c=Joomla\CMS\Factory::getContainer(); $c->alias('session','session.cli')->alias(Joomla\Session\SessionInterface::class,'session.cli');
Joomla\CMS\Factory::$application=$c->get(Joomla\CMS\Application\ConsoleApplication::class);
Joomla\CMS\Factory::$application->createExtensionNamespaceMap();
require __DIR__.'/../extensions/plg_system_nicodewebmonitor/src/Maintenance/ExtensionIdentity.php';
foreach (['PackageState','PackagePreflight','PackageOperation','MaintenanceGate','NativePackageUpdate'] as $name) { require __DIR__.'/'.$name.'.php'; }
$provider=new Nicode\Maintenance\NativePackageUpdate($c->get(Joomla\Database\DatabaseInterface::class),$argv[2],JPATH_BASE,(bool)Joomla\CMS\Factory::getConfig()->get('offline'),$argv[3]);
echo json_encode($provider->handle($argv[4],$argv[5]==='execute'),JSON_THROW_ON_ERROR)."\n";
