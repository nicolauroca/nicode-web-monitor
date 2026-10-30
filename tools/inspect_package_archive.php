<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor. Read-only local ZIP inspection. */
if (PHP_SAPI !== 'cli' || count($argv)!==3) { fwrite(STDERR,"Usage: php inspect_package_archive.php TRUSTED_ZIP SHA256\n"); exit(2); }
require __DIR__.'/../extensions/plg_system_nicodewebmonitor/src/Maintenance/ExtensionIdentity.php';
require __DIR__.'/PackagePreflight.php';
try { echo json_encode(\Nicode\Maintenance\PackagePreflight::inspect($argv[1],$argv[2]),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n"; }
catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
