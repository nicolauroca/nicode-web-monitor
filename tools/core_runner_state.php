<?php
declare(strict_types=1);
/** @package Nicode.joomla.webmonitor. Local discovery; never enqueues or installs. */
if (PHP_SAPI !== 'cli' || count($argv)!==2) { exit(2); }
try {
    require __DIR__.'/core_cli_bootstrap.php';
    $owner=$gate->currentOwner();
    if ($owner===null) { $result=['status'=>'idle']; }
    elseif (!str_starts_with($owner,'core:')) { $result=['status'=>'blocked']; }
    else {
        $id=substr($owner,5);
        $result=$dispatch->handle($id,false,false);
    }
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) { fwrite(STDERR,"Core discovery unavailable; inspect private receipts.\n");exit(2); }
