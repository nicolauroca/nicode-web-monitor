<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor. Explicit one-shot CLI, not a daemon or web child process. */
if (PHP_SAPI!=='cli' || count($argv)!==4 || !in_array($argv[3],['--execute-queued','--reconcile'],true)) {
    fwrite(STDERR,"Usage: php core_update_execute.php JOOMLA_ROOT OPERATION --execute-queued|--reconcile\n");exit(2);
}
try {
    require __DIR__.'/core_cli_bootstrap.php';
    $inspect=fn()=>\Nicode\Maintenance\InstalledCore::inspect();
    $result=$argv[3]==='--reconcile' ? $dispatch->reconcile($argv[2],$inspect)
        : $dispatch->execute($argv[2],$inspect,fn($archive,$hash,$snapshot)=>\Nicode\Maintenance\NativeCoreUpdate::apply($archive,$hash,$snapshot));
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
    exit($result['status']==='verified'?0:3);
} catch (Throwable) {
    fwrite(STDERR,"Core operation unavailable or uncertain. Inspect private receipts; do not retry installation blindly.\n");exit(2);
}
