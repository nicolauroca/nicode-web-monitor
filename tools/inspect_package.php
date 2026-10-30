<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor. Read-only local package inspection, no credentials in output. */
if (PHP_SAPI !== 'cli' || count($argv)<3 || count($argv)>4) {
    fwrite(STDERR,"Usage: php inspect_package.php JOOMLA_ROOT pkg_element [EXPECTED_IDENTITIES_JSON]\n");exit(2);
}
define('_JEXEC',1);define('JPATH_BASE',realpath($argv[1]));
require JPATH_BASE.'/includes/defines.php';require JPATH_BASE.'/includes/framework.php';
require __DIR__.'/PackageState.php';
$identities=[];
if (isset($argv[3])) {
    if (!is_file($argv[3]) || filesize($argv[3])>65536) throw new RuntimeException('Invalid identity file');
    $identities=json_decode((string)file_get_contents($argv[3]),true,16,JSON_THROW_ON_ERROR);
    if (!is_array($identities) || !array_is_list($identities) || count($identities)>100) throw new RuntimeException('Invalid identities');
}
$state=(new \Nicode\Maintenance\PackageState(\Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class)))->inspect($argv[2],$identities);
echo json_encode(['state'=>$state,'expected_identities_supplied'=>count($identities),
    'limit'=>'Registry/version observation only; no file integrity, recovery or complete installation guarantee. Supply expected identities to find orphaned replacement children.'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
