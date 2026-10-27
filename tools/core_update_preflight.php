<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor. Read-only CLI; never installs or repairs. */
if (PHP_SAPI !== 'cli' || count($argv) !== 4) {
    fwrite(STDERR, "Usage: php core_update_preflight.php JOOMLA_ROOT TRUSTED_CORE_ZIP EXPECTED_SHA256\n"); exit(2);
}
require __DIR__.'/CoreUpdatePreflight.php';
require __DIR__.'/InstalledCore.php';
use Nicode\Maintenance\CoreUpdatePreflight;
use Nicode\Maintenance\InstalledCore;
use Joomla\CMS\Factory;
try {
    $package = CoreUpdatePreflight::package($argv[2], $argv[3]);
    $root = realpath($argv[1]);
    if (!$root || !is_file($root.'/includes/framework.php')) { throw new RuntimeException('Invalid Joomla root'); }
    if (!is_file($root.'/configuration.php') || is_file($root.'/installation/index.php')) {
        throw new RuntimeException('Installed site required; installation directory must be removed');
    }
    // CLI MVC services need a URI; never derive it from a Windows drive path.
    $_SERVER['HTTP_HOST'] = 'joomla.invalid'; $_SERVER['REQUEST_URI'] = '/cli';
    $_SERVER['HTTPS'] = 'on';
    define('_JEXEC', 1); define('JPATH_BASE', $root);
    require JPATH_BASE.'/includes/defines.php'; require JPATH_BASE.'/includes/framework.php';
    $container = Factory::getContainer();
    $container->alias('session', 'session.cli')->alias(\Joomla\Session\SessionInterface::class, 'session.cli');
    Factory::$application = $container->get(\Joomla\CMS\Application\ConsoleApplication::class);
    Factory::$application->createExtensionNamespaceMap();
    $report = CoreUpdatePreflight::assess(InstalledCore::inspect(), $package);
    echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
    exit($report['status'] === 'preflight_passed' ? 0 : 3);
} catch (Throwable $error) {
    // Do not print exceptions that may contain database connection details or local paths.
    fwrite(STDERR, "Core preflight failed; no update was attempted. Check package and local Joomla prerequisites.\n"); exit(2);
}
